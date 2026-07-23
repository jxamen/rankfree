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
    /** 이하 월 조회수는 수집 가치 없음 — 후보 리스트에서 삭제(사용자 확정 2026-07-23, 플레이스 has_volume 거부와 짝). */
    private const MIN_VOLUME = 10;

    /**
     * 수집 대기열 — 아직 수집 안 했거나 오래된 쇼핑 키워드를 검색량 큰 순으로 준다.
     * 확장이 이 목록을 받아 한 건씩 연속 수집한다(수만 개를 사람이 클릭할 수 없으므로).
     */
    public function queue(Request $request)
    {
        // 쇼핑 상품 대량 수집은 슈퍼관리자(관리자 콘솔)만.
        if (! $request->user()?->isSuperAdmin()) {
            return response()->json(['ok' => false, 'message' => '권한이 없습니다.'], 403);
        }

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
            ->where(fn ($q) => $q->whereNull('monthly_total')->orWhere('monthly_total', '>', self::MIN_VOLUME))
            ->select('keyword')
            ->selectRaw('MAX(monthly_total) as vol')
            ->groupBy('keyword')
            ->orderByRaw('MAX(monthly_total) is null')     // 검색량 있는 것 우선
            ->orderByRaw('MAX(monthly_total) desc')
            ->limit($limit)
            ->pluck('keyword')
            ->values();

        return response()->json(['data' => [
            'keywords' => $this->volumeGate($keywords),
            'remaining' => \App\Models\KeywordCandidate::whereIn('category_id', $shopCatIds)
                ->whereNotIn('keyword', $fresh)
                ->where(fn ($q) => $q->whereNull('monthly_total')->orWhere('monthly_total', '>', self::MIN_VOLUME))
                ->distinct()->count('keyword'),
        ]]);
    }

    /**
     * 카테고리 순회 대기열 — **1개마다 다른 1차 분류**(라운드 로빈, 사용자 확정 2026-07-22).
     * 배치 안에서도 패션의류→패션잡화→화장품… 순으로 한 키워드씩 섞어 내준다(대분류 하나만 파고드는 것 방지).
     * 각 분류 안에서는 미수집 우선(전 분류 소진 후 재수집)·검색량 큰 순. 시작 분류는 커서로 이어간다.
     */
    private function queueByCategory(int $limit, int $days)
    {
        // 방금 내준 키워드는 잠시 제외한다 — 수집(수십 초) 중에 다시 요청이 오면 같은 걸 또 주게 되고,
        // 확장이 그걸 걸러내면 큐가 비어 재요청 → 같은 키워드만 반복되는 루프가 된다(실측: '제시뉴욕원피스' 3회).
        $lease = \Illuminate\Support\Facades\Cache::get('shop_queue_lease', []);
        $lease = array_filter($lease, fn ($exp) => $exp > time());

        // 1차 분류 + 각 분류의 후손 카테고리 id 묶음(정적이라 캐시)
        $roots = \Illuminate\Support\Facades\Cache::remember('shop_root_subtrees', 3600, function () {
            $cats = \App\Models\KeywordCategory::where('type', 'shopping')
                ->orderBy('sort')->orderBy('id')->get(['id', 'parent_id', 'name']);
            $byParent = $cats->groupBy('parent_id');
            $out = [];
            foreach ($cats->whereNull('parent_id') as $root) {
                $ids = [$root->id];
                $frontier = [$root->id];
                while ($frontier) {
                    $children = collect($frontier)->flatMap(fn ($pid) => ($byParent[$pid] ?? collect())->pluck('id'))->all();
                    if (! $children) {
                        break;
                    }
                    $ids = array_merge($ids, $children);
                    $frontier = $children;
                }

                $out[] = ['name' => $root->name, 'ids' => array_values(array_unique($ids))];
            }

            return $out;
        });
        if ($roots === []) {
            return response()->json(['data' => ['keywords' => [], 'category' => null, 'category_index' => 0, 'category_total' => 0, 'remaining' => 0]]);
        }

        $never = fn ($q) => $q->whereNotExists(fn ($s) => $s->selectRaw('1')
            ->from('keyword_shop_serps')->whereColumn('keyword_shop_serps.keyword', 'keyword_candidates.keyword'));
        $freshKw = KeywordShopSerp::where('collected_at', '>=', now()->subDays($days))->pluck('keyword');
        $stale = fn ($q) => $q->whereNotIn('keyword', $freshKw);

        $n = count($roots);
        $cursor = (int) \Illuminate\Support\Facades\Cache::get('shop_queue_root_cursor', 0) % $n;
        $perRootLimit = (int) ceil($limit / $n) + 2;

        foreach ([['new', $never], ['refresh', $stale]] as [$phase, $filter]) {
            // 분류별 후보를 한 번에 받아 라운드 로빈으로 인터리브 — 1개마다 분류가 바뀐다
            $perRoot = [];
            foreach ($roots as $i => $root) {
                $q = \App\Models\KeywordCandidate::whereIn('category_id', $root['ids']);
                $filter($q);
                $perRoot[$i] = $q
                    ->when($lease !== [], fn ($x) => $x->whereNotIn('keyword', array_keys($lease)))
                    ->where(fn ($x) => $x->whereNull('monthly_total')->orWhere('monthly_total', '>', self::MIN_VOLUME))
                    ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')
                    ->distinct()->limit($perRootLimit)->pluck('keyword')->values();
            }

            $picked = [];
            $seen = [];
            for ($round = 0; $round < $perRootLimit && count($picked) < $limit; $round++) {
                for ($i = 0; $i < $n && count($picked) < $limit; $i++) {
                    $idx = ($cursor + $i) % $n;
                    $kw = $perRoot[$idx][$round] ?? null;
                    if ($kw === null || isset($seen[$kw])) {
                        continue;   // 같은 키워드가 여러 분류에 중복 존재 — 한 번만
                    }
                    $seen[$kw] = true;
                    $picked[] = $kw;
                }
            }

            if ($picked !== []) {
                // 조회수 게이트 — 검색량 미상 키워드는 여기서 조회해 10 이하를 삭제·제외한 뒤 내준다
                $picked = $this->volumeGate(collect($picked))->all();
            }
            if ($picked !== []) {
                foreach ($picked as $k) {
                    $lease[$k] = time() + 300;   // 5분간 재발급 금지
                }
                \Illuminate\Support\Facades\Cache::put('shop_queue_lease', $lease, 600);
                \Illuminate\Support\Facades\Cache::put('shop_queue_root_cursor', ($cursor + 1) % $n, 3600);

                // 남은 수는 표시용 — 무거우니 짧게 캐시(정확도보다 응답 속도)
                $allIds = collect($roots)->flatMap(fn ($r) => $r['ids']);
                $remaining = \Illuminate\Support\Facades\Cache::remember("shop_queue_remaining:{$phase}", 180,
                    function () use ($allIds, $filter) {
                        $q = \App\Models\KeywordCandidate::whereIn('category_id', $allIds)
                            ->where(fn ($x) => $x->whereNull('monthly_total')->orWhere('monthly_total', '>', self::MIN_VOLUME));
                        $filter($q);

                        return $q->distinct()->count('keyword');
                    });

                return response()->json(['data' => [
                    'keywords' => collect($picked),
                    'category' => '전 분류 순회('.$roots[$cursor]['name'].'부터 1개씩)',
                    'category_index' => $cursor + 1,
                    'category_total' => $n,
                    'phase' => $phase === 'new' ? '미수집 우선' : '재수집',
                    'remaining' => $remaining,
                ]]);
            }
        }

        return response()->json(['data' => [
            'keywords' => [], 'category' => null,
            'category_index' => $n, 'category_total' => $n, 'remaining' => 0,
        ]]);
    }

    /**
     * 조회수 게이트(사용자 확정 2026-07-23) — 수집 전에 검색량부터 확인해 월 조회수 10 이하는
     * **후보 리스트에서 삭제**하고 수집하지 않는다(플레이스 발행의 has_volume 거부와 짝).
     * 검색량 미상은 keywordstool(5개 배치)로 조회해 후보에 저장하고, 응답에서 빠진 키워드는
     * 무데이터(=조회수 없음)로 보고 삭제한다. 청크가 통째로 빈 응답이면 API 실패 가능성이 있어
     * 삭제하지 않고 이번 배치는 그대로 수집한다(다음 라운드에 재시도).
     */
    private function volumeGate(\Illuminate\Support\Collection $keywords): \Illuminate\Support\Collection
    {
        if ($keywords->isEmpty()) {
            return $keywords;
        }
        $shopCatIds = \App\Models\KeywordCategory::where('type', 'shopping')->pluck('id');

        // 이미 검색량을 아는 키워드(후보 최대값 기준) — 10 이하는 즉시 탈락
        $known = \App\Models\KeywordCandidate::whereIn('category_id', $shopCatIds)
            ->whereIn('keyword', $keywords)->whereNotNull('monthly_total')
            ->groupBy('keyword')->selectRaw('keyword, MAX(monthly_total) as vol')->pluck('vol', 'keyword');
        $drop = $known->filter(fn ($v) => (int) $v <= self::MIN_VOLUME)->keys()->all();

        $svc = app(\App\Domain\Keyword\NaverKeywordService::class);
        foreach ($keywords->reject(fn ($k) => $known->has($k))->values()->chunk(5) as $chunk) {
            $got = $svc->volumes($chunk->values()->all());
            if ($got === []) {
                continue;   // 청크 통실패(계정 장애 등) 가능성 — 삭제 없이 통과
            }
            foreach ($chunk as $kw) {
                if (isset($got[$kw])) {
                    \App\Models\KeywordCandidate::whereIn('category_id', $shopCatIds)->where('keyword', $kw)->update([
                        'monthly_total' => (int) $got[$kw]['monthly_total'],
                        'comp_idx' => $got[$kw]['comp_idx'] ?? null,
                        'volume_checked_at' => now(),
                    ]);
                    if ((int) $got[$kw]['monthly_total'] <= self::MIN_VOLUME) {
                        $drop[] = $kw;
                    }
                } else {
                    $drop[] = $kw;   // keywordstool 은 무데이터 키워드를 응답에서 생략한다
                }
            }
        }

        if ($drop !== []) {
            \App\Models\KeywordCandidate::whereIn('category_id', $shopCatIds)->whereIn('keyword', $drop)->delete();
            \Illuminate\Support\Facades\Cache::forget('shop_queue_remaining:new');
            \Illuminate\Support\Facades\Cache::forget('shop_queue_remaining:refresh');
        }

        return $keywords->reject(fn ($k) => in_array($k, $drop, true))->values();
    }

    public function store(Request $request)
    {
        // 쇼핑 상품 대량 수집 저장은 슈퍼관리자(관리자 콘솔)만.
        if (! $request->user()?->isSuperAdmin()) {
            return response()->json(['ok' => false, 'message' => '권한이 없습니다.'], 403);
        }

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
            'products.*.storeId' => 'nullable|string|max:100', // 스토어 핸들 — 스마트스토어 판별용
            'products.*.channelUid' => 'nullable|string|max:120',
            'products.*.channelId' => 'nullable|string|max:120',
            'products.*.channelNo' => 'nullable|integer|min:0',
            'products.*.reviewCount' => 'nullable|integer|min:0',   // 가격비교 판매처는 리뷰 있는 것만 받는다
            'products.*.isCatalog' => 'nullable|boolean',           // 가격비교 자체는 저장하지 않는다
            // 시장분석 산출용(확장 v0.3.7+) — 6개월 구매건수·카탈로그 보강 매출·몰 등급·카테고리
            'products.*.purchase6m' => 'nullable|integer|min:0',
            'products.*.revenue6m' => 'nullable|integer|min:0',
            'products.*.mallCount' => 'nullable|integer|min:0',
            'products.*.sellerCount' => 'nullable|integer|min:0',
            'products.*.mallGrade' => 'nullable|string|max:40',
            'products.*.category' => 'nullable|string|max:191',
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

        // 구매건수가 실린 수집(확장 v0.3.7+)이면 시장분석도 함께 생성 — "쇼핑 시장 분석은 확장 플로만"의
        // 대량 경로. 수집 유저 소유 문서로 저장돼 허브 발행이 이를 복제한다. purchase6m 필드 자체가 없으면
        // 구버전 확장 수집이므로 만들지 않는다(전부 0인 껍데기 방지).
        $market = null;
        $hasPurchaseField = collect($items)->contains(fn ($p) => array_key_exists('purchase6m', $p));
        if ($hasPurchaseField) {
            $market = app(\App\Domain\Shopping\MarketAnalysisFromSerp::class)->save(
                $request->user(),
                $keyword,
                array_map(fn ($p) => $p + ['purchase6m' => 0, 'revenue6m' => null], $items),
                (int) ($data['total'] ?? 0),
                (array) ($data['related_tags'] ?? []),
            );
        }

        return response()->json(['data' => [
            'saved' => $row->item_count,
            'collected_at' => $row->collected_at->toDateTimeString(),
            'market_id' => $market?->id,
            'market_sales_6m' => $market?->sales_6m,
        ]]);
    }
}
