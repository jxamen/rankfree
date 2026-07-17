<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use Illuminate\Http\Request;

/**
 * 키워드 탐색(관리자) — 수집된 키워드를 플레이스/쇼핑별로 "보기만" 하는 단순 조회 화면.
 * 카테고리·지역(플레이스)·키워드 검색으로 좁혀서 전체를 훑는 용도.
 * 승인·발행 등 파이프라인 관리는 키워드 콘텐츠 허브(admin.keyword-hub)에서 한다.
 */
class KeywordBrowseController extends Controller
{
    public function index(Request $request)
    {
        $type = in_array($request->query('type'), ['place', 'shopping'], true) ? $request->query('type') : 'place';
        $q = trim((string) $request->query('q', ''));
        $catId = (int) $request->query('category', 0);
        $region = trim((string) $request->query('region', ''));

        $cats = KeywordCategory::where('type', $type)->orderBy('sort')->orderBy('id')->get();
        $catIds = $cats->pluck('id');

        // 목록·집계 공통 조건
        $base = fn () => KeywordCandidate::whereIn('category_id', $catIds)
            ->when($catId, fn ($x) => $x->where('category_id', $catId))
            ->when($region !== '', fn ($x) => $x->where('region', $region))
            ->when($q !== '', fn ($x) => $x->where('keyword', 'like', '%'.addcslashes($q, '\\%_').'%'));

        return view('admin.keyword-browse', [
            'type' => $type,
            'q' => $q,
            'catId' => $catId,
            'region' => $region,
            'cats' => $cats->loadCount('candidates'),
            'items' => $base()->with('category')
                ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')->orderBy('keyword')
                ->paginate(100)->withQueryString(),
            'total' => $base()->count(),
            'statusCounts' => $base()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            // 지역 목록은 지역 필터를 빼고 집계(선택해도 다른 지역으로 이동 가능)
            'regions' => $type === 'place'
                ? KeywordCandidate::whereIn('category_id', $catIds)
                    ->when($catId, fn ($x) => $x->where('category_id', $catId))
                    ->when($q !== '', fn ($x) => $x->where('keyword', 'like', '%'.addcslashes($q, '\\%_').'%'))
                    ->whereNotNull('region')
                    ->selectRaw('region, count(*) as c')->groupBy('region')->orderByDesc('c')->orderBy('region')
                    ->limit(300)->pluck('c', 'region')
                : collect(),
        ]);
    }
}
