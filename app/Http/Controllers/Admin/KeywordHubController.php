<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Keyword\KeywordHubCollector;
use App\Domain\Keyword\KeywordHubPublisher;
use App\Http\Controllers\Controller;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use Illuminate\Http\Request;

/**
 * 키워드 콘텐츠 허브 관리(22) — 카테고리·시드 관리, 후보 승인 큐(대량), 수집/발행 수동 실행.
 * 파이프라인: hub:collect(수집) → 승인 → hub:publish(발행 /keyword/슬러그) → hub:refresh(갱신).
 */
class KeywordHubController extends Controller
{
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), KeywordCandidate::STATUSES, true) ? $request->query('status') : 'pending';
        $catId = (int) $request->query('category', 0);
        $source = (string) $request->query('source', ''); // 출처 필터(combo=지역조합/seed/related/autocomplete/gsc/datalab)

        return view('admin.keyword-hub.index', [
            'categories' => KeywordCategory::with('parent')->withCount([
                'candidates as pending_count' => fn ($q) => $q->where('status', 'pending'),
                'candidates as approved_count' => fn ($q) => $q->where('status', 'approved'),
                'candidates as published_count' => fn ($q) => $q->where('status', 'published'),
            ])->orderBy('type')->orderBy('sort')->orderBy('id')->get(),
            'candidates' => KeywordCandidate::with('category')
                ->where('status', $status)
                ->when($catId, fn ($q) => $q->where('category_id', $catId))
                ->when($source !== '', fn ($q) => $q->where('source', $source))
                ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')->orderByDesc('id')
                ->paginate(50)->withQueryString(),
            'status' => $status,
            'catId' => $catId,
            'source' => $source,
            'counts' => KeywordCandidate::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            // 출처별 후보 수(현 status 기준) — 시딩(지역조합) 결과를 화면에서 바로 확인
            'sourceCounts' => KeywordCandidate::where('status', $status)
                ->when($catId, fn ($q) => $q->where('category_id', $catId))
                ->selectRaw('source, count(*) as c')->groupBy('source')->pluck('c', 'source'),
            'hubDocs' => KeywordSearch::where('origin', 'hub')->latest('id')->limit(10)->get(),
            'hubDocCount' => KeywordSearch::where('origin', 'hub')->count(),
        ]);
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:place,shopping',
            'parent_id' => 'nullable|exists:keyword_categories,id',
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:300',
            'seed_keywords' => 'nullable|string|max:3000',
        ]);

        KeywordCategory::create([
            'type' => $data['type'],
            'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name'],
            'slug' => KeywordCategory::makeSlug($data['name']),
            'description' => $data['description'] ?? null,
            'seed_keywords' => $this->parseSeeds($data['seed_keywords'] ?? ''),
            'sort' => (int) (KeywordCategory::max('sort') + 1),
            'is_active' => true,
        ]);

        return back()->with('status', '카테고리를 추가했습니다.');
    }

    public function updateCategory(Request $request, KeywordCategory $category)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:300',
            'seed_keywords' => 'nullable|string|max:3000',
            'sort' => 'nullable|integer|min:0|max:9999',
        ]);

        $category->update([
            'name' => $data['name'],
            'slug' => $category->name === $data['name'] ? $category->slug : KeywordCategory::makeSlug($data['name'], $category->id),
            'description' => $data['description'] ?? null,
            'seed_keywords' => $this->parseSeeds($data['seed_keywords'] ?? ''),
            'sort' => $data['sort'] ?? $category->sort,
        ]);

        return back()->with('status', '카테고리를 수정했습니다.');
    }

    public function toggleCategory(KeywordCategory $category)
    {
        $category->update(['is_active' => ! $category->is_active]);

        return back()->with('status', '카테고리 사용 상태를 변경했습니다.');
    }

    public function destroyCategory(KeywordCategory $category)
    {
        // 후보는 cascade 삭제, 발행 문서(keyword_searches.category_id)는 nullOnDelete — 문서는 남는다
        $category->delete();

        return back()->with('status', "'{$category->name}' 카테고리를 삭제했습니다(발행 문서는 유지).");
    }

    /** 후보 대량 처리 — 승인/거부/보류(재검토)/삭제. published 후보의 상태는 되돌리지 않는다. */
    public function bulkCandidates(Request $request)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject,pending,delete',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $q = KeywordCandidate::whereIn('id', $data['ids']);
        if ($data['action'] === 'delete') {
            $n = $q->delete();

            return back()->with('status', "후보 {$n}건을 삭제했습니다.");
        }

        $status = ['approve' => 'approved', 'reject' => 'rejected', 'pending' => 'pending'][$data['action']];
        $n = $q->where('status', '!=', 'published')->update(['status' => $status]);

        return back()->with('status', "후보 {$n}건을 '{$status}' 상태로 변경했습니다.");
    }

    /** 지금 수집 — 선택 카테고리(없으면 로테이션 순서대로 1개)를 동기 수집. */
    public function collect(Request $request, KeywordHubCollector $collector)
    {
        $catId = (int) $request->input('category_id', 0);
        $cat = $catId
            ? KeywordCategory::findOrFail($catId)
            : KeywordCategory::where('is_active', true)->orderByRaw('collected_at is null desc')->orderBy('collected_at')->first();

        if (! $cat) {
            return back()->with('status', '수집할 카테고리가 없습니다. 먼저 카테고리와 시드 키워드를 추가하세요.');
        }
        if (! $cat->seedList()) {
            return back()->with('status', "'{$cat->name}' 카테고리에 시드 키워드가 없습니다.");
        }

        $s = $collector->collect($cat);

        return back()->with('status', "'{$cat->name}' 수집 완료 — 시드 {$s['seeds']} · 신규 {$s['created']} · 갱신 {$s['updated']} · 필터 {$s['filtered']}. (신규 후보가 없으면 검색광고 API 자격증명 여부를 확인하세요)");
    }

    /** 지금 발행 — 승인 후보를 검색량 큰 순으로 소량 동기 발행(요청 타임아웃 보호). */
    public function publish(Request $request, KeywordHubPublisher $publisher)
    {
        $limit = min(max((int) $request->input('limit', 3), 1), 10);
        $cands = KeywordCandidate::where('status', 'approved')
            ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')->orderBy('id')
            ->limit($limit)->get();

        if ($cands->isEmpty()) {
            return back()->with('status', '발행할 승인 후보가 없습니다. 후보를 먼저 승인하세요.');
        }

        $ok = $hold = 0;
        foreach ($cands as $c) {
            $publisher->publish($c) ? $ok++ : $hold++;
        }

        return back()->with('status', "발행 완료 — 발행 {$ok}건 · 데이터 부족 보류 {$hold}건.");
    }

    /** 시드 textarea(줄바꿈/콤마 구분) → 배열. */
    private function parseSeeds(string $raw): array
    {
        return collect(preg_split('/[\r\n,]+/u', $raw) ?: [])
            ->map(fn ($k) => trim(preg_replace('/\s+/u', ' ', (string) $k)))
            ->filter(fn ($k) => $k !== '')
            ->unique()->values()->all();
    }
}
