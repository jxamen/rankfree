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

        $base = fn () => KeywordCandidate::whereIn('category_id', $scopeIds)
            ->when($type === 'place' && $rg !== '', fn ($x) => $x->where('region', $rg))
            ->when($inRegions !== [], fn ($x) => $x->whereIn('region', $inRegions))
            ->when($q !== '', fn ($x) => $x->where('keyword', 'like', '%'.addcslashes($q, '\\%_').'%'));

        // 화면에 뜬 키워드의 검색량 자동 갱신 — 주 1회(TTL) 대상만, 화면당 최대 50건(5개씩 묶어 조회)
        $items = $base()->with('category')
            ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')->orderBy('keyword')
            ->paginate(100)->withQueryString();
        $refreshed = $refresher->refresh(collect($items->items()));

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
            'total' => $base()->count(),
            'statusCounts' => $base()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    /**
     * 키워드 상세 — 그 키워드로 실제 노출되는 업체를 수집해 보여준다.
     *   플레이스: pcmap SERP 최대 300개(순위·리뷰·저장수·place+·새로오픈·톡톡) — 6페이지 × 3초라 결과를 캐시한다.
     *   쇼핑: 서버는 search.shopping 이 418 로 막혀 확장 수집이 필요 — 여기서는 안내만 한다.
     */
    public function detail(Request $request, \App\Domain\Place\PlaceRankChecker $checker)
    {
        $keyword = trim((string) $request->query('keyword', ''));
        abort_if($keyword === '', 404);

        $candidate = KeywordCandidate::with('category')
            ->where('keyword', $keyword)->orderByDesc('id')->first();
        $type = $candidate?->category?->type ?? 'place';
        $cat = $this->placeCatKey($candidate?->category?->name);
        $top = min(300, max(10, (int) $request->query('top', 300)));

        // 순위·리뷰는 변동이 크지 않아 매번 재수집하지 않는다 — DB 스냅샷을 재사용하고 '다시 수집' 때만 갱신.
        $data = ['blocked' => false, 'total' => 0, 'items' => [], 'collected_at' => null];
        if ($type === 'place') {
            $saved = \App\Models\KeywordPlaceSerp::where('keyword', $keyword)->where('cat', $cat)->first();

            if ($saved && ! $request->boolean('refresh')) {
                $data = [
                    'blocked' => false,
                    'total' => $saved->total,
                    'items' => $saved->items ?? [],
                    'collected_at' => $saved->collected_at,
                ];
            } else {
                $r = $checker->serpFetch($keyword, $cat, null, $top);
                $items = (array) ($r['items'] ?? []);
                $blocked = (bool) ($r['blocked'] ?? false);

                if (! $blocked && $items) {
                    $saved = \App\Models\KeywordPlaceSerp::updateOrCreate(
                        ['keyword' => $keyword, 'cat' => $cat],
                        ['total' => (int) ($r['total'] ?? 0), 'item_count' => count($items), 'items' => $items, 'collected_at' => now()],
                    );
                }
                $data = [
                    'blocked' => $blocked,
                    'total' => (int) ($r['total'] ?? 0),
                    'items' => $items,
                    'collected_at' => $saved?->collected_at ?? now(),
                ];
            }
        }

        return view('admin.keyword-detail', [
            'keyword' => $keyword,
            'type' => $type,
            'cat' => $cat,
            'candidate' => $candidate,
            'top' => $top,
            'serp' => $data,
        ]);
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
