<?php

namespace App\Http\Controllers;

use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use Illuminate\Http\Request;

/**
 * 키워드 인사이트 허브(공개, 색인 대상) — 22_KEYWORD_CONTENT_HUB Phase 2.
 *   /keywords          카테고리 인덱스(대분류>소분류 + 인기 문서)
 *   /keywords/{slug}   카테고리 허브(집계 + 발행 문서 목록 + 하위/형제 카테고리)
 * 문서 자체는 기존 /keyword/{slug} (KeywordSearch origin=hub).
 */
class KeywordInsightController extends Controller
{
    public function index()
    {
        $categories = KeywordCategory::where('is_active', true)
            ->withCount(['searches as docs_count' => fn ($q) => $q->where('origin', 'hub')])
            ->orderBy('sort')->orderBy('id')->get();

        return view('keywords.index', [
            'groups' => $categories->whereNull('parent_id')->values(),
            'byParent' => $categories->whereNotNull('parent_id')->groupBy('parent_id'),
            'topDocs' => KeywordSearch::where('origin', 'hub')->orderByDesc('monthly_total')->limit(12)->get(),
            'docCount' => KeywordSearch::where('origin', 'hub')->count(),
        ]);
    }

    public function category(string $slug, Request $request)
    {
        $cat = KeywordCategory::where('is_active', true)->where('slug', $slug)->first();
        abort_if(! $cat, 404);

        $region = trim((string) $request->query('region', '')); // 플레이스 지역 필터(강남역 등)
        $q = trim((string) $request->query('q', ''));            // 키워드 검색

        // 대분류 페이지는 하위 카테고리 문서까지 합산해 보여준다
        $ids = $cat->children()->where('is_active', true)->pluck('id')->push($cat->id);
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
            'docTotal' => $docs()->count(),
            'volumeSum' => (int) $docs()->sum('monthly_total'),
            'children' => $cat->children()->where('is_active', true)
                ->withCount(['searches as docs_count' => fn ($q) => $q->where('origin', 'hub')])->get(),
            'siblings' => $cat->parent_id
                ? KeywordCategory::where('is_active', true)->where('parent_id', $cat->parent_id)
                    ->where('id', '!=', $cat->id)->orderBy('sort')->orderBy('id')->get()
                : collect(),
            'region' => $region,
            'q' => $q,
            'regions' => $regions,
        ]);
    }
}
