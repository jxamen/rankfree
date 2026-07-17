<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Keyword\PlaceRegionTree;
use App\Http\Controllers\Controller;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 키워드 탐색(관리자) — 수집된 키워드 조회 전용.
 * 셀렉트는 항상 고정 표기하고, 상위를 고르면 하위 옵션이 채워진다(선택 없으면 전체 키워드).
 *   쇼핑    1차 · 2차 · 3차 (데이터랩 카테고리 트리)
 *   플레이스 업종 · **시/도 · 시/군/구 · 지역** (공개 /keywords/place 와 동일한 3단계 지역 계층 — PlaceRegionTree)
 * 승인·발행 등 파이프라인 관리는 키워드 콘텐츠 허브(admin.keyword-hub)에서 한다.
 */
class KeywordBrowseController extends Controller
{
    public function index(Request $request, PlaceRegionTree $tree, \App\Domain\Keyword\KeywordVolumeRefresher $refresher)
    {
        $type = in_array($request->query('type'), ['place', 'shopping'], true) ? $request->query('type') : 'shopping';
        $q = trim((string) $request->query('q', ''));

        // 카테고리 3단(쇼핑 1·2·3차 / 플레이스는 c1=업종만 사용)
        $c1 = (int) $request->query('c1', 0);
        $c2 = (int) $request->query('c2', 0);
        $c3 = (int) $request->query('c3', 0);
        $sido = trim((string) $request->query('sido', ''));  // 플레이스 지역 1단계(시/도)
        $sgg = trim((string) $request->query('sgg', ''));    // 2단계(시/군/구)
        $rg = trim((string) $request->query('rg', ''));      // 3단계(동·상권)

        // 1차 = 쇼핑은 최상위 분류, 플레이스는 업종(플랫)
        $lv1 = $type === 'shopping'
            ? KeywordCategory::where('type', 'shopping')->whereNull('parent_id')->orderBy('sort')->orderBy('id')->get()
            : KeywordCategory::where('type', 'place')->orderBy('sort')->orderBy('id')->get();

        // 상위 선택이 유효할 때만 하위 옵션을 채운다(잘못된 조합은 하위 선택 해제)
        $c1 = $lv1->contains('id', $c1) ? $c1 : 0;
        $lv2 = ($type === 'shopping' && $c1) ? KeywordCategory::where('parent_id', $c1)->orderBy('sort')->orderBy('id')->get() : collect();
        $c2 = $lv2->contains('id', $c2) ? $c2 : 0;
        $lv3 = ($type === 'shopping' && $c2) ? KeywordCategory::where('parent_id', $c2)->orderBy('sort')->orderBy('id')->get() : collect();
        $c3 = $lv3->contains('id', $c3) ? $c3 : 0;

        // 선택된 가장 하위 분류 기준 스코프(없으면 타입 전체)
        $selectedId = $c3 ?: ($c2 ?: $c1);
        $scopeIds = $selectedId
            ? $this->descendantIds(KeywordCategory::find($selectedId))
            : KeywordCategory::where('type', $type)->pluck('id');

        // 플레이스 지역 — 공개 페이지와 같은 3단계(시/도 > 시/군/구 > 동·상권). 지역명 2,900곳을 계층으로 접는다.
        $grouped = ['sido' => [], 'sgg' => [], 'leaf' => []];
        if ($type === 'place') {
            // 지역 목록은 시딩 때만 바뀐다 — 67만 행 group by 라 운영에서 1.5초(실측). 스코프별로 캐시한다.
            $grouped = Cache::remember('kb:regions:'.md5($scopeIds->implode(',')), 1800, fn () => $tree->group(
                KeywordCandidate::whereIn('category_id', $scopeIds)->whereNotNull('region')
                    ->selectRaw('region, count(*) as c')->groupBy('region')->pluck('c', 'region')
            ));
            // 상위 선택이 유효할 때만 하위를 살린다(잘못된 조합은 해제)
            $sido = isset($grouped['sido'][$sido]) ? $sido : '';
            $sgg = ($sido !== '' && isset($grouped['sgg'][$sido][$sgg])) ? $sgg : '';
            $rg = ($sgg !== '' && isset($grouped['leaf'][$sido][$sgg][$rg])) ? $rg : '';
        }
        // 선택 깊이만큼 지역을 좁힌다 — 지역(rg) > 시군구 > 시도 순
        $inRegions = $type === 'place' && $rg === '' && $sido !== ''
            ? $tree->regionsIn($grouped, $sido, $sgg ?: null)
            : [];

        // 수집 상태 필터 — 분류당 수천 개라 '미수집만' 보고 골라 수집할 수 있어야 한다
        $collected = (string) $request->query('collected', '');   // ''=전체 · 'y'=수집됨 · 'n'=미수집
        $serpTable = $type === 'place' ? 'keyword_place_ranks' : 'keyword_shop_ranks';

        // ★ 키워드 마스터(keywords) 기반 — candidates(99만 행)를 키워드로 합치면 풀스캔 18.5초.
        //   마스터는 고유 키워드(26.8만)만 담고 (type, monthly_total)·(type, serp_collected_at) 인덱스로 정렬한다.
        //   분류를 고른 경우에만 매핑(candidates)으로 좁힌다.
        $narrow = $selectedId || ($type === 'place' && ($rg !== '' || $inRegions !== []));
        $base = fn () => \App\Models\Keyword::where('type', $type)
            ->when($selectedId, fn ($x) => $x->whereIn('keyword', fn ($s) => $s->select('keyword')->from('keyword_candidates')->whereIn('category_id', $scopeIds)))
            ->when($type === 'place' && $rg !== '', fn ($x) => $x->where('region', $rg))
            ->when($inRegions !== [], fn ($x) => $x->whereIn('region', $inRegions))
            ->when($q !== '', fn ($x) => $x->where('keyword', 'like', '%'.addcslashes($q, '\\%_').'%'))
            ->when($collected === 'y', fn ($x) => $x->whereNotNull('serp_collected_at'))
            ->when($collected === 'n', fn ($x) => $x->whereNull('serp_collected_at'));

        // 정렬 — 기본 검색량순. 수집일은 마스터에 반영해 둔 값(스냅샷 저장 시 touchSerp)을 쓴다.
        $sort = in_array($request->query('sort'), ['vol', 'collected', 'collected_old', 'keyword'], true)
            ? $request->query('sort') : 'vol';

        // 마스터 컬럼으로 정렬 — 인덱스(kw_type_vol·kw_type_serp)를 그대로 탄다.
        // ★ 'x is null' 을 정렬식에 넣으면 표현식이라 인덱스를 못 쓰고 filesort 가 된다(운영 실측 0.92초 → 0.00초).
        //   MySQL 은 NULL 을 가장 작은 값으로 보므로 DESC 만 쓰면 미상·미수집이 알아서 뒤로 간다.
        $items = $base()->with('category')
            ->when($sort === 'vol', fn ($x) => $x->orderByDesc('monthly_total')->orderByDesc('id'))
            ->when($sort === 'keyword', fn ($x) => $x->orderBy('keyword'))
            ->when($sort === 'collected', fn ($x) => $x->orderByDesc('serp_collected_at')->orderByDesc('id'))
            // 오래된 수집분부터 — 미수집(NULL)은 볼 이유가 없으니 제외해야 인덱스 범위로 좁혀진다
            ->when($sort === 'collected_old', fn ($x) => $x->whereNotNull('serp_collected_at')->orderBy('serp_collected_at')->orderBy('id'))
            ->paginate(100)->withQueryString();
        // 검색어를 넣은 조회는 즉답이 중요하다 — 갱신은 목록을 훑을 때만(검색 중 3초 지연 방지, 실측)
        $refreshed = $q === '' ? $refresher->refresh(collect($items->items())) : 0;

        return view('admin.keyword-browse', [
            'type' => $type, 'q' => $q,
            'refreshed' => $refreshed,
            'volumeTtlDays' => \App\Domain\Keyword\KeywordVolumeRefresher::TTL_DAYS,
            'c1' => $c1, 'c2' => $c2, 'c3' => $c3,
            'lv1' => $lv1, 'lv2' => $lv2, 'lv3' => $lv3,
            'sido' => $sido, 'sgg' => $sgg, 'rg' => $rg,
            'sidos' => $grouped['sido'],
            'sggs' => $sido !== '' ? ($grouped['sgg'][$sido] ?? []) : [],
            'regions' => ($sido !== '' && $sgg !== '') ? ($grouped['leaf'][$sido][$sgg] ?? []) : [],
            'items' => $items,       // 수집일·분류수·상태는 마스터 컬럼(serp_collected_at·serp_count·cat_cnt·status)
            'collected' => $collected,
            'sort' => $sort,
            // 마스터는 (keyword,type) 유니크라 distinct 가 필요 없다 — distinct count 는 운영에서 4.0초(실측).
            // 그래도 66만 행 count 는 1초 안팎이라 필터 조합별로 5분 캐시한다(총계는 실시간일 필요가 없다).
            'total' => \Illuminate\Support\Facades\Cache::remember(
                'kb:total:'.md5(implode('|', [$type, $c1, $c2, $c3, $sido, $sgg, $rg, $q, $collected])), 300,
                fn () => $base()->count()
            ),
            'statusCounts' => \Illuminate\Support\Facades\Cache::remember(
                'kb:st:'.md5(implode('|', [$type, $c1, $c2, $c3, $sido, $sgg, $rg, $q, $collected])), 300,
                fn () => $base()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')
            ),
        ]);
    }

    /**
     * 키워드 상세 — 그 키워드로 실제 노출되는 업체를 수집해 보여준다.
     *   플레이스: pcmap SERP 최대 300개(순위·리뷰·저장수·place+·새로오픈·톡톡) — 6페이지 × 3초라 결과를 캐시한다.
     *   쇼핑: 서버는 search.shopping 이 418 로 막혀 확장 수집이 필요 — 여기서는 안내만 한다.
     */
    public function detail(Request $request, \App\Domain\Place\PlaceRankChecker $checker, \App\Domain\Place\PlaceSerpStore $store)
    {
        $keyword = trim((string) $request->query('keyword', ''));
        abort_if($keyword === '', 404);

        $candidate = KeywordCandidate::with('category')
            ->where('keyword', $keyword)->orderByDesc('id')->first();
        $type = $candidate?->category?->type ?? 'place';
        $cat = $this->placeCatKey($candidate?->category?->name);
        $top = min(300, max(10, (int) $request->query('top', 300)));

        // 업체는 마스터(place_businesses)에 1회만, 키워드↔업체는 월별 파티션 매핑(keyword_place_ranks).
        // 저장분을 재사용하고 '다시 수집' 때만 갱신한다(순위는 매일 바뀌지만 매번 17초를 쓸 이유는 없다).
        $data = ['blocked' => false, 'total' => 0, 'items' => collect(), 'collected_at' => null];
        $months = [];
        $month = (int) $request->query('month', 0) ?: null;   // 특정 월 수집분 보기

        if ($type === 'place') {
            $months = $store->months($keyword, $cat);
            $has = $months !== [];

            if ($has && ! $request->boolean('refresh')) {
                $items = $store->items($keyword, $cat, $month);
                $data = [
                    'blocked' => false,
                    'total' => \App\Models\KeywordPlaceSerp::where('keyword', $keyword)->where('cat', $cat)->value('total') ?: 0,
                    'items' => $items,
                    'collected_at' => $items->first()->collected_at ?? null,
                ];
            } else {
                $r = $checker->serpFetch($keyword, $cat, null, $top);
                $items = (array) ($r['items'] ?? []);
                $blocked = (bool) ($r['blocked'] ?? false);

                if (! $blocked && $items) {
                    $store->save($keyword, $cat, $items);
                    // 전체 노출 수는 별도 보관(매핑 행에 넣기엔 중복)
                    \App\Models\KeywordPlaceSerp::updateOrCreate(
                        ['keyword' => $keyword, 'cat' => $cat],
                        ['total' => (int) ($r['total'] ?? 0), 'item_count' => count($items), 'items' => [], 'collected_at' => now()],
                    );
                    $months = $store->months($keyword, $cat);
                }
                $data = [
                    'blocked' => $blocked,
                    'total' => (int) ($r['total'] ?? 0),
                    'items' => $blocked ? collect() : $store->items($keyword, $cat),
                    'collected_at' => now(),
                ];
            }
        }

        // 쇼핑 — 확장이 수집해 저장한 상품(마스터+월별 매핑). 메타(전체 노출·연관태그)는 KeywordShopSerp.
        $shop = null;
        $shopItems = collect();
        if ($type === 'shopping') {
            $shop = \App\Models\KeywordShopSerp::where('keyword', $keyword)->first();
            $store = app(\App\Domain\Shopping\ShopSerpStore::class);
            $months = $store->months($keyword);
            $shopItems = $store->items($keyword, $month);
        }

        return view('admin.keyword-detail', [
            'keyword' => $keyword,
            'type' => $type,
            'cat' => $cat,
            'candidate' => $candidate,
            'top' => $top,
            'serp' => $data,
            'shop' => $shop,
            'shopItems' => $shopItems,   // 쇼핑 상품(마스터 조인)
            'months' => $months,       // 수집된 월 목록(최신순)
            'month' => $month,         // 지금 보고 있는 월(null = 최신)
        ]);
    }

    /** 특정 월 수집분 삭제 — 매핑만 지우고 업체 마스터는 남긴다(다른 키워드가 참조). */
    public function deleteMonth(Request $request, \App\Domain\Place\PlaceSerpStore $store)
    {
        $data = $request->validate([
            'keyword' => 'required|string|max:120',
            'cat' => 'required|string|max:20',
            'month' => 'required|integer|min:202001|max:209912',
        ]);

        $n = $store->deleteMonth($data['keyword'], $data['cat'], (int) $data['month']);

        return back()->with('status', "{$data['month']} 수집분 {$n}건을 삭제했습니다.");
    }

    /** 허브 업종 카테고리명 → pcmap 업종 키(payload 선택). */
    private function placeCatKey(?string $name): string
    {
        return match ($name) {
            '맛집·음식점' => 'restaurant',
            '병원·의원' => 'hospital',
            '헤어샵' => 'hairshop',
            '네일·뷰티' => 'nailshop',
            '숙박·여행' => 'accommodation',
            default => 'place',
        };
    }

    /** 카테고리 자신 + 자식 + 손자 id(데이터랩 3계층). */
    private function descendantIds(?KeywordCategory $cat)
    {
        if (! $cat) {
            return collect();
        }
        $childIds = KeywordCategory::where('parent_id', $cat->id)->pluck('id');

        return collect([$cat->id])->merge($childIds)
            ->merge(KeywordCategory::whereIn('parent_id', $childIds)->pluck('id'))
            ->unique()->values();
    }
}
