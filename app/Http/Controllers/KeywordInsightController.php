<?php

namespace App\Http\Controllers;

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

    /** 타입 홈 = 카테고리 메뉴. 플레이스(업종 플랫 + 지역 축)와 쇼핑(대>소분류 인덱스)은 나열 방식이 다르다. */
    public function typeHome(string $type)
    {
        $cats = KeywordCategory::where('is_active', true)->where('type', $type)
            ->withCount(['searches as docs_count' => fn ($q) => $q->where('origin', 'hub')])
            ->orderBy('sort')->orderBy('id')->get();
        $ids = $cats->pluck('id');

        $docs = fn () => KeywordSearch::where('origin', 'hub')->whereIn('category_id', $ids);

        return view('keywords.type', [
            'type' => $type,
            'typeLabel' => self::TYPES[$type],
            'groups' => $cats->whereNull('parent_id')->values(),
            'byParent' => $cats->whereNotNull('parent_id')->groupBy('parent_id'),
            'typeDocCount' => $docs()->count(),
            'topDocs' => $docs()->orderByDesc('monthly_total')->limit(12)->get(),
            // 플레이스의 실질 2번째 축 = 지역. 카테고리 카드의 지역 칩 + 전역 지역 진입에 쓴다.
            'regions' => $type === 'place'
                ? $docs()->whereNotNull('region')->selectRaw('region, count(*) as c')
                    ->groupBy('region')->orderByDesc('c')->orderBy('region')->limit(24)->pluck('c', 'region')
                : collect(),
            'regionsByCat' => $type === 'place'
                ? KeywordSearch::where('origin', 'hub')->whereIn('category_id', $ids)->whereNotNull('region')
                    ->selectRaw('category_id, region, count(*) as c')->groupBy('category_id', 'region')
                    ->orderByDesc('c')->get()->groupBy('category_id')
                : collect(),
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
            'fallbackDocs' => $docs->total() === 0
                ? KeywordSearch::where('origin', 'hub')->orderByDesc('monthly_total')->limit(6)->get()
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
        // 지역·검색 필터가 걸린 문서 쿼리(집계·목록 공통)
        $docs = fn () => KeywordSearch::where('origin', 'hub')->whereIn('category_id', $ids)
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
