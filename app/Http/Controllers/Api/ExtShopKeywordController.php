<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Http\Controllers\Controller;
use App\Jobs\ShopKeywordCheckJob;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopProductInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 확장 — 쇼핑 유입키워드 상품정보 자동 수집(2026-07-29).
 *
 * 서버는 상품페이지를 직접 못 읽는다(네이버 429 + 스토어 SPA). 관리자 화면에서는 사람이 분석 화면을
 * 열면 확장이 collectProductPage 로 채워주는데, 외부 API 로 만든 분석은 그 화면을 여는 사람이 없어
 * 제목을 못 구하면 조합이 0개가 된다.
 *
 * → 요청자도 확장을 켜 두면, 확장이 이 큐를 폴링해 **자기 분석의** 상품정보를 채우고
 *   조합 재생성·순위확인까지 이어진다. 화면을 열 필요가 없다는 점만 다르고 규칙은 관리자와 동일하다.
 *
 * 권한: shop_keyword API 권한이 허용된 회원만(관리자가 부여). 대상은 항상 본인 분석뿐이다.
 */
class ExtShopKeywordController extends Controller
{
    public function __construct(private ShopKeywordExposureAnalyzer $analyzer) {}

    /** shop_keyword 권한 없으면 403 — 이 자동 수집은 허용된 회원 전용. */
    private function authorizeScope(Request $request): void
    {
        abort_unless($request->user()?->canUseApiScope('shop_keyword'), 403, '쇼핑 유입키워드 권한이 없습니다.');
    }

    /**
     * 상품정보(제목)가 비어 조합을 못 만든 내 분석 목록 — 확장이 순서대로 수집한다.
     * 확장은 product_url 을 백그라운드 탭으로 열어 수집한 뒤 productInfo 로 돌려준다.
     */
    public function productQueue(Request $request): JsonResponse
    {
        $this->authorizeScope($request);

        $limit = min(20, max(1, (int) $request->query('limit', 5)));

        $base = ShopKeywordAnalysis::where('user_id', $request->user()->id)
            ->whereNotNull('product_id')->where('product_id', '!=', '')
            ->whereNotNull('product_url')->where('product_url', '!=', '')
            ->where(fn ($q) => $q->whereNull('product_title')->orWhere('product_title', ''));

        $rows = (clone $base)->latest('id')->limit($limit)
            ->get(['id', 'core_keyword', 'product_id', 'product_url']);

        return response()->json(['data' => [
            'items' => $rows->map(fn ($a) => [
                'analysis_id' => (int) $a->id,
                'core_keyword' => (string) $a->core_keyword,
                'product_id' => (string) $a->product_id,
                'product_url' => (string) $a->product_url,
            ])->all(),
            'remaining' => (clone $base)->count(),
        ]]);
    }

    /**
     * 확장이 수집한 상품정보 반영 — 저장 → 조합 재생성 → 순위확인 자동 시작.
     * 관리자 화면의 refreshProductInfo 와 같은 규칙(소유자 확인 · 상품 일치 확인).
     */
    public function productInfo(Request $request, ShopKeywordAnalysis $analysis): JsonResponse
    {
        $this->authorizeScope($request);
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'info' => 'required|array',
            'info.channel_product_id' => 'nullable|string|max:40',
            'info.title' => 'nullable|string|max:300',
            'info.brand' => 'nullable|string|max:120',
            'info.mall_name' => 'nullable|string|max:150',
            'info.price' => 'nullable|integer|min:0|max:2000000000',
            'info.seller_tags' => 'nullable|array|max:60',
            'info.seller_tags.*' => 'nullable|string|max:80',
            'info.category' => 'nullable|string|max:191',
            'info.thumbnail_url' => 'nullable|string|max:500',
        ]);

        $info = (array) $data['info'];
        // 이 분석의 상품과 일치할 때만 반영(다른 상품 payload 오염 방지) — 관리자 경로와 동일
        if ((string) ($info['channel_product_id'] ?? '') !== (string) $analysis->product_id
            || trim((string) ($info['title'] ?? '')) === '') {
            return response()->json(['ok' => false, 'message' => '이 분석의 상품 정보가 아닙니다.'], 422);
        }

        ShopProductInfo::updateOrCreate(
            ['user_id' => $analysis->user_id, 'channel_product_id' => (string) $analysis->product_id],
            [
                'title' => $info['title'],
                'brand' => $info['brand'] ?? null,
                'mall_name' => $info['mall_name'] ?? null,
                'price' => $info['price'] ?? null,
                'seller_tags' => array_values(array_unique(array_filter(array_map(
                    fn ($s) => trim((string) $s), (array) ($info['seller_tags'] ?? [])
                )))),
                'category' => $info['category'] ?? null,
                'thumbnail_url' => $info['thumbnail_url'] ?? null,
                'collected_at' => now(),
            ],
        );

        $r = $this->analyzer->refreshProductInfo($analysis);
        if (($r['added'] ?? 0) > 0) {
            $this->analyzer->regenerate($analysis);
        }

        // 조합이 생겼으면 순위 확인을 자동으로 시작한다. status 는 regenerate 가 이미 checking 으로
        // 바꿔 두므로 status 로 판단하면 안 되고, **미확인 조합이 남았는지**로 판단해 잡을 띄운다
        // (관리자 화면은 화면 JS 가 확인을 몰지만, API 경로는 이 잡이 유일한 구동원이다).
        $fresh = $analysis->fresh();
        $started = false;
        if ($fresh->combos()->whereNull('rank')->exists()) {
            if ($fresh->status !== 'checking') {
                $fresh->update(['status' => 'checking']);
                $fresh = $fresh->fresh();
            }
            ShopKeywordCheckJob::dispatch($fresh->id);
            $started = true;
        }

        return response()->json(['ok' => true, 'data' => [
            'analysis_id' => (int) $fresh->id,
            'title' => (string) $fresh->product_title,
            'combo_count' => (int) $fresh->combo_count,
            'status' => (string) $fresh->status,
            'check_started' => $started,
        ]]);
    }
}
