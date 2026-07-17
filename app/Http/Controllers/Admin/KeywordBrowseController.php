<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use Illuminate\Http\Request;

/**
 * 키워드 탐색(관리자) — 수집된 키워드를 플레이스/쇼핑별로 "보기만" 하는 조회 화면.
 * 드릴다운:
 *   쇼핑    1차 → 2차 → 3차 (데이터랩 카테고리 트리 그대로, parent_id)
 *   플레이스 업종 → 지역유형(핫플/구/시/동/여행지) → 지역 (지역은 2,900곳이라 유형으로 먼저 좁힌다)
 * 승인·발행 등 파이프라인 관리는 키워드 콘텐츠 허브(admin.keyword-hub)에서 한다.
 */
class KeywordBrowseController extends Controller
{
    /** 지역 유형 라벨 — place_keyword_matrix 의 region_types */
    private const REGION_TYPES = ['hotplace' => '핫플레이스', 'district' => '구', 'city' => '시군구', 'dong' => '읍면동', 'travel' => '여행지'];

    public function index(Request $request)
    {
        $type = in_array($request->query('type'), ['place', 'shopping'], true) ? $request->query('type') : 'place';
        $q = trim((string) $request->query('q', ''));
        $catId = (int) $request->query('category', 0);
        $region = trim((string) $request->query('region', ''));
        $regionType = (string) $request->query('region_type', '');

        $cat = $catId ? KeywordCategory::where('type', $type)->find($catId) : null;
        if (! $cat) {
            $catId = 0;
        }

        // 선택 카테고리 + 하위(손자까지) 스코프 — 미선택이면 타입 전체
        $scopeIds = $cat
            ? $this->descendantIds($cat)
            : KeywordCategory::where('type', $type)->pluck('id');

        // 드릴다운 목록: 선택했으면 그 자식들, 아니면 최상위(쇼핑 1차 / 플레이스 업종)
        // 카운트는 하위(손자)까지 합산 — 1차 분류는 직계 후보가 없고 하위에만 있어 0으로 보이면 안 된다
        $subCats = ($cat ? $cat->children() : KeywordCategory::where('type', $type)->whereNull('parent_id'))
            ->orderBy('sort')->orderBy('id')->get()
            ->each(fn ($sc) => $sc->total_count = KeywordCandidate::whereIn('category_id', $this->descendantIds($sc))->count());

        $base = fn () => KeywordCandidate::whereIn('category_id', $scopeIds)
            ->when($regionType !== '', fn ($x) => $x->where('region_type', $regionType))
            ->when($region !== '', fn ($x) => $x->where('region', $region))
            ->when($q !== '', fn ($x) => $x->where('keyword', 'like', '%'.addcslashes($q, '\\%_').'%'));

        // 지역 유형별 후보 수(플레이스) — 지역을 유형으로 먼저 좁힌다
        $regionTypeCounts = $type === 'place'
            ? KeywordCandidate::whereIn('category_id', $scopeIds)->whereNotNull('region_type')
                ->when($q !== '', fn ($x) => $x->where('keyword', 'like', '%'.addcslashes($q, '\\%_').'%'))
                ->selectRaw('region_type, count(*) as c')->groupBy('region_type')->pluck('c', 'region_type')
            : collect();

        // 지역 목록 — 유형을 고른 뒤에만(전체 나열 방지). 지역 검색어로 추가로 좁힐 수 있다.
        $regionQ = trim((string) $request->query('rq', ''));
        $regions = ($type === 'place' && $regionType !== '')
            ? KeywordCandidate::whereIn('category_id', $scopeIds)->where('region_type', $regionType)
                ->when($regionQ !== '', fn ($x) => $x->where('region', 'like', '%'.addcslashes($regionQ, '\\%_').'%'))
                ->whereNotNull('region')
                ->selectRaw('region, count(*) as c')->groupBy('region')->orderBy('region')->get()->pluck('c', 'region')
            : collect();

        return view('admin.keyword-browse', [
            'type' => $type,
            'q' => $q,
            'catId' => $catId,
            'cat' => $cat?->loadMissing('parent.parent'),
            'subCats' => $subCats,
            'region' => $region,
            'regionType' => $regionType,
            'regionQ' => $regionQ,
            'regionTypes' => self::REGION_TYPES,
            'regionTypeCounts' => $regionTypeCounts,
            'regions' => $regions,
            'items' => $base()->with('category')
                ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')->orderBy('keyword')
                ->paginate(100)->withQueryString(),
            'total' => $base()->count(),
            'statusCounts' => $base()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    /** 카테고리 자신 + 자식 + 손자 id(데이터랩 3계층). */
    private function descendantIds(KeywordCategory $cat)
    {
        $childIds = $cat->children()->pluck('id');

        return collect([$cat->id])->merge($childIds)
            ->merge(KeywordCategory::whereIn('parent_id', $childIds)->pluck('id'))
            ->unique()->values();
    }
}
