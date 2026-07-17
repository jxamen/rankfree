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
            $grouped = $tree->group(
                KeywordCandidate::whereIn('category_id', $scopeIds)->whereNotNull('region')
                    ->selectRaw('region, count(*) as c')->groupBy('region')->pluck('c', 'region')
            );
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

        $base = fn () => KeywordCandidate::whereIn('category_id', $scopeIds)
            ->when($type === 'place' && $rg !== '', fn ($x) => $x->where('region', $rg))
            ->when($inRegions !== [], fn ($x) => $x->whereIn('region', $inRegions))
            ->when($q !== '', fn ($x) => $x->where('keyword', 'like', '%'.addcslashes($q, '\\%_').'%'))
            ->when($collected === 'y', fn ($x) => $x->whereIn('keyword', fn ($s) => $s->select('keyword')->from($serpTable)))
            ->when($collected === 'n', fn ($x) => $x->whereNotIn('keyword', fn ($s) => $s->select('keyword')->from($serpTable)));

        // 같은 키워드가 여러 분류에 중복 존재한다(실측 825건·6%, '탑텐'은 7개 분류) — 목록은 키워드 단위로 합친다.
        // ★ groupBy(keyword) 로 합치면 운영 99만 행에서 풀스캔+filesort 로 18.5초가 걸린다(실측).
        //   → 인덱스(kc_keyword_vol)를 타도록 정렬은 그대로 두고, 중복은 '같은 키워드 중 가장 작은 id' 만 남겨 거른다.
        $items = $base()->with('category')
            ->whereRaw('id = (select min(c2.id) from keyword_candidates c2 where c2.keyword = keyword_candidates.keyword)')
            ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')->orderBy('keyword')
            ->paginate(100)->withQueryString();

        // 분류 중복 수(+N 표시) — 현재 페이지의 키워드만 세면 되므로 가볍다
        $catCnt = \Illuminate\Support\Facades\DB::table('keyword_candidates')
            ->whereIn('keyword', collect($items->items())->pluck('keyword'))
            ->selectRaw('keyword, count(*) c')->groupBy('keyword')->pluck('c', 'keyword');
        // 검색어를 넣은 조회는 즉답이 중요하다 — 갱신은 목록을 훑을 때만(검색 중 3초 지연 방지, 실측)
        $refreshed = $q === '' ? $refresher->refresh(collect($items->items())) : 0;
        // 분류별 키워드가 수천 개라(실측: 패션잡화 9,588) 미수집만 보기·수집 상태 필터가 필요하다

        // 업체·상품 수집일(키워드별 스냅샷) — 목록에서 어느 키워드를 수집했는지 바로 보이게
        $shown = collect($items->items())->pluck('keyword')->all();
        $serpTbl = $type === 'place' ? 'keyword_place_ranks' : 'keyword_shop_ranks';
        $serpAt = \Illuminate\Support\Facades\DB::table($serpTbl)   // 월별 파티션 매핑의 최신 수집일
            ->whereIn('keyword', $shown)
            ->selectRaw('keyword, MAX(collected_at) as collected_at')
            ->groupBy('keyword')->pluck('collected_at', 'keyword');
        // 수집 상품·업체 수 — 최신 월 기준 몇 개를 수집했는지 목록에서 바로 보이게
        $serpCnt = \Illuminate\Support\Facades\DB::table($serpTbl)
            ->whereIn('keyword', $shown)
            ->selectRaw('keyword, count(*) as c')
            ->groupBy('keyword')->pluck('c', 'keyword');

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
            'items' => $items,
            'serpAt' => $serpAt,     // 키워드 => 수집일시
            'serpCnt' => $serpCnt,   // 키워드 => 수집한 상품·업체 수
            'catCnt' => $catCnt,     // 키워드 => 속한 분류 수
            'collected' => $collected,
            // distinct count 가 운영에서 1.58초라 캐시(필터 조합별 5분) — 총계는 실시간일 필요가 없다
            'total' => \Illuminate\Support\Facades\Cache::remember(
                'kb:total:'.md5(implode('|', [$type, $c1, $c2, $c3, $sido, $sgg, $rg, $q, $collected])), 300,
                fn () => $base()->distinct()->count('keyword')
            ),
            'statusCounts' => $base()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
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
