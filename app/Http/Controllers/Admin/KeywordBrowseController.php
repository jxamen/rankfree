<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use Illuminate\Http\Request;

/**
 * 키워드 탐색(관리자) — 수집된 키워드 조회 전용.
 * 셀렉트는 항상 고정 표기하고, 상위를 고르면 하위 옵션이 채워진다(선택 없으면 전체 키워드).
 *   쇼핑    1차 · 2차 · 3차 (데이터랩 카테고리 트리)
 *   플레이스 업종 · 지역유형 · 지역 (지역은 2,900곳이라 유형으로 먼저 좁힌다)
 * 승인·발행 등 파이프라인 관리는 키워드 콘텐츠 허브(admin.keyword-hub)에서 한다.
 */
class KeywordBrowseController extends Controller
{
    private const REGION_TYPES = ['hotplace' => '핫플레이스', 'district' => '구', 'city' => '시군구', 'dong' => '읍면동', 'travel' => '여행지'];

    public function index(Request $request)
    {
        $type = in_array($request->query('type'), ['place', 'shopping'], true) ? $request->query('type') : 'shopping';
        $q = trim((string) $request->query('q', ''));

        // 카테고리 3단(쇼핑 1·2·3차 / 플레이스는 c1=업종만 사용)
        $c1 = (int) $request->query('c1', 0);
        $c2 = (int) $request->query('c2', 0);
        $c3 = (int) $request->query('c3', 0);
        $rt = (string) $request->query('rt', '');   // 플레이스 지역유형
        $rg = trim((string) $request->query('rg', '')); // 플레이스 지역

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

        $base = fn () => KeywordCandidate::whereIn('category_id', $scopeIds)
            ->when($type === 'place' && $rt !== '', fn ($x) => $x->where('region_type', $rt))
            ->when($type === 'place' && $rg !== '', fn ($x) => $x->where('region', $rg))
            ->when($q !== '', fn ($x) => $x->where('keyword', 'like', '%'.addcslashes($q, '\\%_').'%'));

        // 플레이스 지역 옵션 — 유형을 골라야 채운다(2,900곳 전체 나열 방지)
        $regions = ($type === 'place' && $rt !== '')
            ? KeywordCandidate::whereIn('category_id', $scopeIds)->where('region_type', $rt)->whereNotNull('region')
                ->selectRaw('region, count(*) as c')->groupBy('region')->orderBy('region')->get()->pluck('c', 'region')
            : collect();
        $regionTypeCounts = $type === 'place'
            ? KeywordCandidate::whereIn('category_id', $scopeIds)->whereNotNull('region_type')
                ->selectRaw('region_type, count(*) as c')->groupBy('region_type')->pluck('c', 'region_type')
            : collect();

        return view('admin.keyword-browse', [
            'type' => $type, 'q' => $q,
            'c1' => $c1, 'c2' => $c2, 'c3' => $c3,
            'lv1' => $lv1, 'lv2' => $lv2, 'lv3' => $lv3,
            'rt' => $rt, 'rg' => $rg,
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
