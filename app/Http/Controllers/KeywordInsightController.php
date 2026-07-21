<?php

namespace App\Http\Controllers;

use App\Domain\Keyword\PlaceRegionTree;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use Illuminate\Http\Request;

/**
 * 키워드 인사이트 허브(공개) — 22_KEYWORD_CONTENT_HUB Phase 2·4(타입 우선 IA).
 *   /keywords                 검색 진입점(구글식) — 카테고리 나열 없음
 *   /keywords/place|shopping  타입별 카테고리 메뉴(플레이스=업종 카드 / 쇼핑=분류 인덱스)
 *   /keywords/search?q=&type= 통합 검색 결과(noindex, follow)
 *   /keywords/{slug}          카테고리 상세(발행 문서 목록·지역 필터)
 * 문서 자체는 /keyword/{slug} (KeywordSearch origin=hub).
 *
 * ⚠️ 공개 화면의 모든 문서 쿼리는 origin='hub' 로 제한한다 — 사용자 검색 내역(origin=user) 노출 금지(21).
 */
class KeywordInsightController extends Controller
{
    private const TYPES = ['place' => '플레이스', 'shopping' => '쇼핑'];

    /** 허브 — 큰 검색창 + 타입 2분기 + 인기 리포트. */
    public function index()
    {
        $counts = KeywordSearch::where('origin', 'hub')
            ->join('keyword_categories', 'keyword_categories.id', '=', 'keyword_searches.category_id')
            ->selectRaw('keyword_categories.type as t, count(*) as c')
            ->groupBy('keyword_categories.type')->pluck('c', 't');

        return view('keywords.index', [
            'topDocs' => KeywordSearch::where('origin', 'hub')->with('category:id,type,name')
                ->orderByDesc('monthly_total')->limit(12)->get(),
            'docCount' => KeywordSearch::where('origin', 'hub')->count(),
            'typeStats' => collect(self::TYPES)->map(fn ($label, $type) => [
                'label' => $label,
                'docs' => (int) ($counts[$type] ?? 0),
                'cats' => KeywordCategory::where('is_active', true)->where('type', $type)->whereNull('parent_id')->count(),
            ]),
        ]);
    }

    /**
     * 타입 홈 = 카테고리 메뉴. 나열 방식이 타입별로 다르다.
     *   플레이스: 업종 칩 + **지역 3단계 드릴다운**(시/도 → 시/군/구 → 동·상권) → 고른 지역의 키워드 목록
     *   쇼핑: 대분류 섹션 + 소분류 텍스트 인덱스
     */
    public function typeHome(string $type, Request $request, PlaceRegionTree $tree)
    {
        $cats = KeywordCategory::where('is_active', true)->where('type', $type)
            ->withCount(['searches as docs_count' => fn ($q) => $q->where('origin', 'hub')])
            ->orderBy('sort')->orderBy('id')->get();
        $ids = $cats->pluck('id');
        // category eager load — publicUrl() 이 타입을 보고 쇼핑이면 시장분석(/market)으로 연결한다
        $docs = fn () => KeywordSearch::where('origin', 'hub')->whereIn('category_id', $ids)->with('category:id,type,name');

        $data = [
            'type' => $type,
            'typeLabel' => self::TYPES[$type],
            'groups' => $cats->whereNull('parent_id')->values(),
            'byParent' => $cats->whereNotNull('parent_id')->groupBy('parent_id'),
            'typeDocCount' => $docs()->count(),
        ];

        if ($type !== 'place') {
            return view('keywords.type', $data + [
                'topDocs' => $docs()->orderByDesc('monthly_total')->limit(12)->get(),
            ]);
        }

        // ── 플레이스: 업종(cat) 칩 + 지역 드릴다운(sido → sgg → region) ──
        $catSlug = trim((string) $request->query('cat', ''));
        $activeCat = $catSlug !== '' ? $cats->firstWhere('slug', $catSlug) : null;
        $scoped = fn () => KeywordSearch::where('origin', 'hub')
            ->whereIn('category_id', $activeCat ? [$activeCat->id] : $ids)->with('category:id,type,name');

        // 업종 필터가 걸린 상태의 지역 분포 → 3단계로 접는다
        $grouped = $tree->group(
            $scoped()->whereNotNull('region')->selectRaw('region, count(*) as c')
                ->groupBy('region')->pluck('c', 'region')
        );

        $sido = trim((string) $request->query('sido', '')) ?: null;
        $sgg = trim((string) $request->query('sgg', '')) ?: null;
        if ($sido !== null && ! isset($grouped['sido'][$sido])) {
            $sido = $sgg = null;   // 없는 지역 → 1단계로
        }
        if ($sgg !== null && ! isset($grouped['sgg'][$sido][$sgg])) {
            $sgg = null;
        }
        $region = trim((string) $request->query('region', '')) ?: null;
        $q = trim((string) $request->query('q', ''));   // 셀렉트 줄 우측 검색 — 선택 범위 안에서 좁힌다

        // 선택 깊이만큼 좁힌 지역들의 키워드 문서 — "지역 고르면 그 지역 키워드가 쭉".
        // 아무것도 고르지 않은 첫 진입은 최근 발행 문서를 보여준다(빈 화면 방지).
        $picked = $region !== null ? [$region] : $tree->regionsIn($grouped, $sido, $sgg);
        $list = $scoped()
            ->when($sido !== null, fn ($x) => $x->whereIn('region', $picked))
            ->when($q !== '', fn ($x) => $x->where('keyword', 'like', '%'.addcslashes($q, '\\%_').'%'))
            ->when($sido === null && $q === '', fn ($x) => $x->latest('id')->limit(30))       // 첫 진입 = 최근 30개
            ->when($sido !== null || $q !== '', fn ($x) => $x->orderByDesc('monthly_total'));
        $list = ($sido === null && $q === '') ? $list->get() : $list->paginate(24)->withQueryString();
        $isRecent = $sido === null && $q === '';

        return view('keywords.type', $data + [
            'cats' => $cats->whereNull('parent_id')->values(),
            'activeCat' => $activeCat,
            'grouped' => $grouped,
            'sido' => $sido,
            'sgg' => $sgg,
            'region' => $region,
            'q' => $q,
            'docs' => $list,
            'isRecent' => $isRecent,   // 첫 진입(최근 문서 30개) 여부 — 뷰에서 제목·페이지네이션 분기
            'topDocs' => collect(),
        ]);
    }

    /** 통합 검색 결과 — noindex, follow(무한 쿼리 조합 색인 방지). 매칭 카테고리로 색인 자산에 되돌린다. */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $type = in_array($request->query('type'), array_keys(self::TYPES), true) ? $request->query('type') : null;
        $like = '%'.addcslashes($q, '\\%_').'%';

        $docs = KeywordSearch::where('origin', 'hub')   // ★ 사용자 검색 내역 노출 금지
            ->when($q !== '', fn ($x) => $x->where('keyword', 'like', $like))
            ->when($type, fn ($x) => $x->whereHas('category', fn ($c) => $c->where('type', $type)))
            ->with('category:id,type,name')
            ->orderByDesc('monthly_total')->paginate(24)->withQueryString();

        return view('keywords.search', [
            'q' => $q,
            'type' => $type,
            'typeLabel' => $type ? self::TYPES[$type] : null,
            'docs' => $docs,
            'matchedCats' => $q === '' ? collect() : KeywordCategory::where('is_active', true)
                ->when($type, fn ($x) => $x->where('type', $type))
                ->where('name', 'like', $like)
                ->withCount(['searches as docs_count' => fn ($x) => $x->where('origin', 'hub')])
                ->limit(3)->get(),
            // 결과 0건 폴백 — 타입 컨텍스트를 유지한다(플레이스 검색에 쇼핑 리포트를 들이밀지 않는다)
            'fallbackDocs' => $docs->total() === 0
                ? KeywordSearch::where('origin', 'hub')
                    ->when($type, fn ($x) => $x->whereHas('category', fn ($c) => $c->where('type', $type)))
                    ->with('category:id,type,name')
                    ->orderByDesc('monthly_total')->limit(6)->get()
                : collect(),
        ]);
    }

    public function category(string $slug, Request $request)
    {
        $cat = KeywordCategory::where('is_active', true)->where('slug', $slug)->first();
        abort_if(! $cat, 404);

        $region = trim((string) $request->query('region', '')); // 플레이스 지역 필터(강남역 등)
        $q = trim((string) $request->query('q', ''));            // 키워드 검색(레거시 — 폼은 /keywords/search 로 제출)

        // 대분류 페이지는 하위 카테고리 문서를 합산 — 쇼핑은 데이터랩 3계층이므로 손자까지 포함
        $childIds = $cat->children()->where('is_active', true)->pluck('id');
        $ids = collect([$cat->id])->merge($childIds)
            ->merge(KeywordCategory::whereIn('parent_id', $childIds)->where('is_active', true)->pluck('id'))
            ->unique()->values();
        // 지역·검색 필터가 걸린 문서 쿼리(집계·목록 공통) — category 는 publicUrl() 분기용
        $docs = fn () => KeywordSearch::where('origin', 'hub')->whereIn('category_id', $ids)->with('category:id,type,name')
            ->when($region !== '', fn ($x) => $x->where('region', $region))
            ->when($q !== '', fn ($x) => $x->where('keyword', 'like', '%'.addcslashes($q, '\\%_').'%'));

        // 플레이스만 지역 목록(발행 문서가 있는 지역, 문서 많은 순) — 지역 필터 UI용
        $regions = $cat->type === 'place'
            ? KeywordSearch::where('origin', 'hub')->whereIn('category_id', $ids)->whereNotNull('region')
                ->selectRaw('region, count(*) as c')->groupBy('region')->orderByDesc('c')->orderBy('region')->limit(80)->pluck('c', 'region')
            : collect();

        return view('keywords.category', [
            'cat' => $cat->loadMissing('parent'),
            'docs' => $docs()->orderByDesc('monthly_total')->paginate(24)->withQueryString(),
            'docTotal' => $docs()->count(),   // 화면 비표시 — 구조화 데이터(ItemList numberOfItems)·메타 설명용
            'children' => $cat->children()->where('is_active', true)
                ->withCount(['searches as docs_count' => fn ($q) => $q->where('origin', 'hub')])->get(),
            'siblings' => $cat->parent_id
                ? KeywordCategory::where('is_active', true)->where('parent_id', $cat->parent_id)
                    ->where('id', '!=', $cat->id)->orderBy('sort')->orderBy('id')->get()
                : collect(),
            'region' => $region,
            'q' => $q,
            'regions' => $regions,
            'typeLabel' => self::TYPES[$cat->type] ?? '',
        ]);
    }
}
