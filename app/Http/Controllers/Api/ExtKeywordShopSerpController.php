<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KeywordShopSerp;
use Illuminate\Http\Request;

/**
 * 확장이 수집한 쇼핑 노출 상품(상위 80개)을 저장 — 서버는 search.shopping 이 418 이라 직접 수집할 수 없다.
 * 관리자 키워드 상세(admin.keyword-browse.detail)가 이 스냅샷을 읽어 보여준다.
 */
class ExtKeywordShopSerpController extends Controller
{
    /**
     * 수집 대기열 — 아직 수집 안 했거나 오래된 쇼핑 키워드를 검색량 큰 순으로 준다.
     * 확장이 이 목록을 받아 한 건씩 연속 수집한다(수만 개를 사람이 클릭할 수 없으므로).
     */
    public function queue(Request $request)
    {
        $limit = min(50, max(1, (int) $request->query('limit', 20)));
        // 최근 이 기간 안에 수집한 키워드는 다시 주지 않는다(같은 키워드가 반복 수집되는 것 방지).
        // 기본 1일 — 순위는 매일 바뀔 수 있으니 하루 지난 것부터 재수집 대상.
        $days = max(1, (int) $request->query('days', 1));

        // 카테고리 순회 모드 — 데이터랩 트리 순서(1차 → 그 2차 → 그 3차 …)로 한 분류씩 비운다.
        // 검색량 순으로 전체를 훑으면 어느 분류가 끝났는지 알 수 없어, 분류 단위로 끝내고 넘어간다.
        if ($request->query('mode') === 'category') {
            return $this->queueByCategory($limit, $days);
        }

        // 쇼핑 카테고리에 속한 후보 중, 스냅샷이 없거나 오래된 것
        $shopCatIds = \App\Models\KeywordCategory::where('type', 'shopping')->pluck('id');
        $fresh = KeywordShopSerp::where('collected_at', '>=', now()->subDays($days))->pluck('keyword');

        // ★ distinct — 같은 키워드가 여러 분류에 중복 존재한다(실측 825건·6%, '탑텐'은 7개 분류).
        //   수집은 키워드 단위라 중복을 제거하지 않으면 같은 키워드를 7번 수집하게 된다.
        $keywords = \App\Models\KeywordCandidate::whereIn('category_id', $shopCatIds)
            ->whereNotIn('keyword', $fresh)
            ->select('keyword')
            ->selectRaw('MAX(monthly_total) as vol')
            ->groupBy('keyword')
            ->orderByRaw('MAX(monthly_total) is null')     // 검색량 있는 것 우선
            ->orderByRaw('MAX(monthly_total) desc')
            ->limit($limit)
            ->pluck('keyword')
            ->values();

        return response()->json(['data' => [
            'keywords' => $keywords,
            'remaining' => \App\Models\KeywordCandidate::whereIn('category_id', $shopCatIds)
                ->whereNotIn('keyword', $fresh)->distinct()->count('keyword'),
        ]]);
    }

    /**
     * 카테고리 순회 대기열 — 데이터랩 트리 순서(1차 → 그 하위 2차 → 그 하위 3차 …)로
     * "아직 다 못 비운 첫 분류"의 미수집 키워드를 준다. 분류 하나를 싹 끝내고 다음으로 넘어간다.
     * 진행 위치를 따로 저장하지 않아도 '미수집이 남은 첫 분류'가 곧 이어할 지점이라, 멈췄다 다시 시작해도 그대로 이어진다.
     */
    private function queueByCategory(int $limit, int $days)
    {
        $fresh = KeywordShopSerp::where('collected_at', '>=', now()->subDays($days))->pluck('keyword');

        // 방금 내준 키워드는 잠시 제외한다 — 수집(수십 초) 중에 다시 요청이 오면 같은 걸 또 주게 되고,
        // 확장이 그걸 걸러내면 큐가 비어 재요청 → 같은 키워드만 반복되는 루프가 된다(실측: '제시뉴욕원피스' 3회).
        $lease = \Illuminate\Support\Facades\Cache::get('shop_queue_lease', []);
        $lease = array_filter($lease, fn ($exp) => $exp > time());

        // 트리 DFS 순서(1차 sort → 그 자식 → 그 손자) — 카테고리는 정적이라 캐시
        $order = \Illuminate\Support\Facades\Cache::remember('shop_cat_dfs', 3600, function () {
            $cats = \App\Models\KeywordCategory::where('type', 'shopping')
                ->orderBy('sort')->orderBy('id')->get(['id', 'parent_id', 'name']);
            $out = [];
            $walk = function ($pid, string $path) use (&$walk, $cats, &$out) {
                foreach ($cats->where('parent_id', $pid) as $c) {
                    $full = $path === '' ? $c->name : $path.' > '.$c->name;
                    $out[] = ['id' => $c->id, 'path' => $full];
                    $walk($c->id, $full);
                }
            };
            $walk(null, '');

            return $out;
        });

        $doneCats = 0;
        foreach ($order as $c) {
            $kws = \App\Models\KeywordCandidate::where('category_id', $c['id'])
                ->whereNotIn('keyword', $fresh)
                ->when($lease !== [], fn ($x) => $x->whereNotIn('keyword', array_keys($lease)))   // 내준 것 제외
                ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')
                ->distinct()->limit($limit)->pluck('keyword')->values();

            if ($kws->isNotEmpty()) {
                // 5분간 재발급 금지 — 수집이 끝나 스냅샷이 생기면 어차피 $fresh 로 걸러진다
                foreach ($kws as $k) {
                    $lease[$k] = time() + 300;
                }
                \Illuminate\Support\Facades\Cache::put('shop_queue_lease', $lease, 600);

                return response()->json(['data' => [
                    'keywords' => $kws,
                    'category' => $c['path'],                       // 지금 수집 중인 분류(화면 표시)
                    'category_index' => $doneCats + 1,
                    'category_total' => count($order),
                    'remaining' => \App\Models\KeywordCandidate::whereIn('category_id', collect($order)->pluck('id'))
                        ->whereNotIn('keyword', $fresh)->distinct()->count('keyword'),
                ]]);
            }
            $doneCats++;
        }

        return response()->json(['data' => [
            'keywords' => [], 'category' => null,
            'category_index' => count($order), 'category_total' => count($order), 'remaining' => 0,
        ]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'keyword' => 'required|string|max:120',
            'total' => 'nullable|integer|min:0',
            'products' => 'required|array|min:1|max:200',
            'products.*.title' => 'required|string|max:300',
            'products.*.rank' => 'nullable|integer|min:0',
            'products.*.price' => 'nullable|integer|min:0',
            'products.*.mallName' => 'nullable|string|max:120',
            // 링크 길이는 제한하지 않는다 — 네이버 광고 링크(cr.shopping.naver.com/adcr?x=…)가 2,000자를 넘는다.
            // 길이 제한을 걸면 광고 상품이 섞인 키워드는 전부 저장 실패한다(실측).
            'products.*.link' => 'nullable|string',
            'products.*.isAd' => 'nullable|boolean',
            'products.*.talkId' => 'nullable|string|max:60',   // 톡톡 코드(talk.naver.com/ct/{code})
            'products.*.storeId' => 'nullable|string|max:80',  // 스토어 핸들 — 스마트스토어 판별용
            'related_tags' => 'nullable|array|max:50',
        ]);

        // 저장은 상위 80개까지 — 화면 목적상 그 이상은 불필요
        $items = array_slice(array_values($data['products']), 0, 80);
        // 지나치게 긴 광고 링크는 잘라 저장(표시·이동엔 지장 없는 선). JSON 비대화 방지.
        foreach ($items as &$it) {
            if (! empty($it['link']) && mb_strlen($it['link']) > 2000) {
                $it['link'] = mb_substr($it['link'], 0, 2000);
            }
        }
        unset($it);

        $keyword = trim($data['keyword']);

        // 상품 마스터 + 월별 파티션 매핑(중복 상품 제거·용량 최적화)
        app(\App\Domain\Shopping\ShopSerpStore::class)->save($keyword, $items);

        // 전체 노출 수·연관 태그는 키워드 단위 메타로 보관(items 는 매핑으로 대체돼 비운다)
        $row = KeywordShopSerp::updateOrCreate(
            ['keyword' => $keyword],
            [
                'total' => (int) ($data['total'] ?? 0),
                'item_count' => count($items),
                'items' => [],
                'related_tags' => $data['related_tags'] ?? null,
                'collected_at' => now(),
            ],
        );

        return response()->json(['data' => ['saved' => $row->item_count, 'collected_at' => $row->collected_at->toDateTimeString()]]);
    }
}
