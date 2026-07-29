<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Domain\Shopping\ShopKeywordShortLinkService;
use App\Http\Controllers\Controller;
use App\Jobs\ShopKeywordCheckJob;
use App\Models\ShopKeywordAnalysis;
use DomainException;
use Illuminate\Http\Request;

/**
 * 쇼핑 유입키워드 분석 외부 API v1 (scope: shop_keyword) — 2026-07-26.
 *
 * 분석 생성(키워드 추출·조합) → 순위 확인 → 노출 키워드로 Short URL 그룹 생성까지 외부에서 자동화한다.
 * 규칙은 화면과 동일한 곳을 쓴다: 추출·조합·확인 [ShopKeywordExposureAnalyzer], Short URL [ShopKeywordShortLinkService].
 *
 * 확장(브라우저) 의존:
 *  - check_method='api'(기본) 는 openapi shop.json 을 서버가 직접 호출 → **확장 없이 완결**된다(상위 40위·광고 판별 없음).
 *    생성 즉시 ShopKeywordCheckJob 이 남은 조합이 0 이 될 때까지 자동으로 확인한다.
 *  - check_method='search' 는 실화면(m.search) 기준이라 서버 IP 가 막히면 status=blocked 로 멈춘다.
 *    이때는 확장을 켠 브라우저로 관리자 분석 화면을 열어 두면 이어서 처리된다.
 *  - 조합 재료(제목·SEO태그)는 원래 확장 수집분을 쓰므로, 외부에서는 `product_info` 로 직접 넘길 수 있다.
 */
class ShopKeywordApiController extends Controller
{
    public function __construct(
        private ShopKeywordExposureAnalyzer $analyzer,
        private ShopKeywordShortLinkService $shortLinks,
    ) {}

    /** 분석 생성 + 순위 확인 자동 시작. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'core_keyword' => 'required|string|max:120',
            'product' => 'required|string|max:500',
            'threshold' => 'nullable|integer|min:1|max:40',
            'check_method' => 'nullable|in:api,search',
            // 조합 재료 — 확장 수집분이 없을 때 외부에서 상품정보를 직접 전달(제목이 없으면 조합 품질이 떨어진다)
            'product_info' => 'nullable|array',
            'product_info.title' => 'nullable|string|max:300',
            'product_info.brand' => 'nullable|string|max:120',
            'product_info.mall' => 'nullable|string|max:150',
            'product_info.price' => 'nullable|integer|min:0|max:2000000000',
            'product_info.seller_tags' => 'nullable|array|max:60',
            'product_info.seller_tags.*' => 'nullable|string|max:80',
        ]);

        $opts = [
            'threshold' => $data['threshold'] ?? null,
            'check_method' => $data['check_method'] ?? 'api',
        ];
        if (! empty($data['product_info']['title'])) {
            $opts['product_info'] = [
                'title' => (string) $data['product_info']['title'],
                'brand' => (string) ($data['product_info']['brand'] ?? ''),
                'mall' => (string) ($data['product_info']['mall'] ?? ''),
                'price' => (int) ($data['product_info']['price'] ?? 0),
                'seller_tags' => array_values(array_filter(array_map(
                    fn ($t) => trim((string) $t), (array) ($data['product_info']['seller_tags'] ?? [])
                ))),
            ];
        }

        $analysis = $this->analyzer->prepare($request->user(), trim($data['core_keyword']), trim($data['product']), null, $opts);

        // 확인 자동 완주 — 조합이 있으면 큐가 남은 조합 0 까지 배치를 이어서 돌린다.
        if ($analysis->status === 'checking') {
            ShopKeywordCheckJob::dispatch($analysis->id);
        } elseif ((int) $analysis->combo_count === 0 && trim((string) $analysis->product_title) === '') {
            // 상품 제목을 아직 못 구해 조합이 0개 — '완료'가 아니라 **상품정보 수집 대기**다(2026-07-29).
            // 요청자의 확장이 /api/ext/shop-keyword/product-queue 를 폴링해 채우면 조합·순위확인이 이어진다.
            $analysis->update(['status' => 'pending']);
        }

        return response()->json(['analysis' => $this->payload($analysis)], 201);
    }

    /** 내 분석 목록. */
    public function index(Request $request)
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $rows = ShopKeywordAnalysis::where('user_id', $request->user()->id)
            ->latest('id')->paginate($perPage);

        return response()->json([
            'analyses' => collect($rows->items())->map(fn ($a) => $this->payload($a))->all(),
            'page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
        ]);
    }

    /** 분석 상세 — 진행 상태 · 노출 키워드 · 생성된 Short URL. */
    public function show(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return response()->json([
            'analysis' => $this->payload($analysis),
            'exposed_keywords' => $this->shortLinks->exposedKeywords($analysis),
            'short_links' => $this->linkPayload($analysis),
        ]);
    }

    /** Short URL 생성 — 노출 키워드를 group_count 개 그룹으로 나눈다(화면과 동일 규칙). */
    public function storeShortLinks(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $data = $request->validate(['group_count' => 'required|integer|min:1|max:100']);

        try {
            $links = $this->shortLinks->generate($analysis, (int) $data['group_count']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => 'group_count'], 422);
        }

        return response()->json(['short_links' => $this->linkPayload($analysis)], 201);
    }

    /** Short URL 목록만 조회 — 발주 시스템이 링크·배정 키워드를 그대로 가져다 쓴다. */
    public function shortLinks(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return response()->json(['short_links' => $this->linkPayload($analysis)]);
    }

    /**
     * Short URL 재배정 — 이미 배포한 URL 은 그대로 두고 키워드만 다시 나눈다.
     * 순위 확인이 더 진행돼 노출 키워드가 늘었을 때 사용(생성은 호출된 링크가 있으면 막힌다).
     */
    public function reassignShortLinks(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        try {
            $this->shortLinks->reassign($analysis);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => 'short_links'], 422);
        }

        return response()->json(['short_links' => $this->linkPayload($analysis)]);
    }

    /** Short URL 표현 — 그룹 번호·주소·배정 키워드·호출 수. */
    private function linkPayload(ShopKeywordAnalysis $analysis): array
    {
        return $analysis->shortLinks()->orderBy('group_no')->get()
            ->map(fn ($l) => [
                'group_no' => (int) $l->group_no,
                'url' => $l->url(),
                'keywords' => (array) $l->keywords,
                'hit_count' => (int) $l->hit_count,
            ])->all();
    }

    /** 공통 분석 페이로드 — 진행 상태(progress)를 함께 준다. */
    private function payload(ShopKeywordAnalysis $analysis): array
    {
        $p = $this->analyzer->progress($analysis);

        return [
            'id' => (int) $analysis->id,
            'core_keyword' => (string) $analysis->core_keyword,
            'product_url' => (string) $analysis->product_url,
            'product_id' => (string) $analysis->product_id,
            'mall_name' => (string) $analysis->mall_name,
            'threshold' => (int) $analysis->threshold,
            'check_method' => (string) $analysis->check_method,
            'status' => (string) $analysis->status,
            'progress' => [
                'total' => (int) $p['total'],
                'checked' => (int) $p['checked'],
                'remaining' => (int) $p['remaining'],
                'exposed' => (int) $p['exposed'],
                'blocked' => (bool) $p['blocked'],
            ],
            'created_at' => optional($analysis->created_at)->toIso8601String(),
        ];
    }
}
