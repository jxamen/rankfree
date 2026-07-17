<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Keyword\HubAutoRun;
use App\Domain\Keyword\KeywordHubCollector;
use App\Domain\Keyword\KeywordHubPublisher;
use App\Domain\Seo\SearchEnginePing;
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
    /** 허브 첫 화면 — 발행 전용(현황 요약·발행 실행·최근 발행). 후보 큐·카테고리 시드·수집은 candidates 로 분리. */
    public function index()
    {
        return view('admin.keyword-hub.index', [
            'counts' => KeywordCandidate::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'hubDocs' => KeywordSearch::where('origin', 'hub')->latest('id')->limit(10)->get(),
            'hubDocCount' => KeywordSearch::where('origin', 'hub')->count(),
            'auto' => $this->autoPayload(),   // 자동 발행 초기 상태(새로고침·재방문 복원용)
        ]);
    }

    /** 자동 발행 상태(폴링용 JSON). */
    public function autoStatus()
    {
        return response()->json(['data' => $this->autoPayload()]);
    }

    /** 자동 발행 on/off 토글 — {on: bool, type: shopping|place}. 서버 크론(hub:auto-publish)이 실제 발행을 이어간다. */
    public function autoToggle(Request $request)
    {
        $state = $request->boolean('on')
            ? HubAutoRun::start($request->input('type'))
            : HubAutoRun::stop();

        return response()->json(['data' => $this->autoPayload($state)]);
    }

    /** 자동 발행 상태 → 화면/폴링 페이로드. */
    private function autoPayload(?array $s = null): array
    {
        $s = $s ?? HubAutoRun::state();
        $last = $s['last_at'] ?? null;
        $ago = $last ? max(0, now()->timestamp - (int) $last) : null;

        return [
            'running' => (bool) ($s['running'] ?? false),
            'type' => $s['type'] ?? null,
            'done' => (int) ($s['done'] ?? 0),
            'held' => (int) ($s['held'] ?? 0),
            'remaining' => (int) ($s['remaining'] ?? 0),
            'updated_ago' => $ago,
            'stale' => $ago !== null && $ago > 180,   // 3분 넘게 갱신 없으면 크론 미동작 의심
        ];
    }

    /** 후보·수집 관리 — 후보 큐(필터·일괄 처리)·카테고리 시드·수동 수집. 발행은 index 에서. */
    public function candidates(Request $request)
    {
        $status = in_array($request->query('status'), KeywordCandidate::STATUSES, true) ? $request->query('status') : 'pending';
        $catId = (int) $request->query('category', 0);
        $source = (string) $request->query('source', ''); // 출처 필터(combo=지역조합/seed/related/autocomplete/gsc/datalab)
        $kw = trim((string) $request->query('q', ''));    // 키워드 검색(대량 후보 탐색용)
        $region = trim((string) $request->query('region', '')); // 지역 필터(플레이스 — 강남역·망원동…)

        return view('admin.keyword-hub.candidates', [
            // 후보 필터 select 용 전체 목록(카운트 불필요 — 2천여 데이터랩 분류 포함)
            'categories' => KeywordCategory::with('parent')->orderBy('type')->orderBy('sort')->orderBy('id')->get(),
            // 시드 관리 대상 = 수동 카테고리만. 데이터랩 트리(naver_cid 보유, 쇼핑 1~3분류 2천여 개)는
            // hub:shopping-collect 가 자동 관리하므로 시드 카드 목록에 펼치지 않는다(화면 폭주 방지).
            'seedCategories' => KeywordCategory::with('parent')->whereNull('naver_cid')->withCount([
                'candidates as pending_count' => fn ($q) => $q->where('status', 'pending'),
                'candidates as approved_count' => fn ($q) => $q->where('status', 'approved'),
                'candidates as published_count' => fn ($q) => $q->where('status', 'published'),
            ])->orderBy('type')->orderBy('sort')->orderBy('id')->get(),
            'candidates' => $this->filteredCandidates($status, $catId, $source, $kw, $region)
                ->with('category')
                ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')->orderByDesc('id')
                ->paginate(50)->withQueryString(),
            'status' => $status,
            'catId' => $catId,
            'source' => $source,
            'q' => $kw,
            'region' => $region,
            'counts' => KeywordCandidate::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            // 출처별 후보 수(현 status 기준) — 시딩(지역조합) 결과를 화면에서 바로 확인
            'sourceCounts' => KeywordCandidate::where('status', $status)
                ->when($catId, fn ($q) => $q->where('category_id', $catId))
                ->selectRaw('source, count(*) as c')->groupBy('source')->pluck('c', 'source'),
            // 지역별 후보 수(현 status·카테고리·출처 기준) — 플레이스를 지역으로 훑기
            'regionCounts' => KeywordCandidate::where('status', $status)
                ->when($catId, fn ($q) => $q->where('category_id', $catId))
                ->when($source !== '', fn ($q) => $q->where('source', $source))
                ->whereNotNull('region')
                ->selectRaw('region, count(*) as c')->groupBy('region')->orderByDesc('c')->orderBy('region')
                ->limit(200)->pluck('c', 'region'),
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

    /** 필터 전체 일괄 처리 — 현재 필터(상태·카테고리·출처·검색어)에 걸린 모든 후보를 승인/거부/삭제(대량 시딩 운영용). */
    public function bulkAllCandidates(Request $request)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject,pending,delete',
            'status' => 'required|in:'.implode(',', KeywordCandidate::STATUSES),
            'category' => 'nullable|integer',
            'source' => 'nullable|string|max:20',
            'q' => 'nullable|string|max:120',
            'region' => 'nullable|string|max:60',
        ]);

        $query = $this->filteredCandidates($data['status'], (int) ($data['category'] ?? 0), (string) ($data['source'] ?? ''), trim((string) ($data['q'] ?? '')), trim((string) ($data['region'] ?? '')));

        if ($data['action'] === 'delete') {
            $n = $query->delete();

            return back()->with('status', "필터 전체 {$n}건을 삭제했습니다.");
        }

        $status = ['approve' => 'approved', 'reject' => 'rejected', 'pending' => 'pending'][$data['action']];
        $n = $query->where('status', '!=', 'published')->update(['status' => $status]);

        return back()->with('status', "필터 전체 {$n}건을 '{$status}' 상태로 변경했습니다.");
    }

    /** 승인 큐 공통 필터(목록·전체 일괄 처리가 동일 조건을 쓰도록 단일화). */
    private function filteredCandidates(string $status, int $catId, string $source, string $kw, string $region = '')
    {
        return KeywordCandidate::query()
            ->where('status', $status)
            ->when($catId, fn ($q) => $q->where('category_id', $catId))
            ->when($source !== '', fn ($q) => $q->where('source', $source))
            ->when($region !== '', fn ($q) => $q->where('region', $region))
            ->when($kw !== '', fn ($q) => $q->where('keyword', 'like', '%'.addcslashes($kw, '\\%_').'%'));
    }

    /** 지금 수집 — 선택 카테고리(없으면 로테이션 순서대로 1개)를 동기 수집. */
    public function collect(Request $request, KeywordHubCollector $collector)
    {
        $catId = (int) $request->input('category_id', 0);
        $cat = $catId
            ? KeywordCategory::findOrFail($catId)
            // 자동 로테이션은 수동(시드) 카테고리만 — 데이터랩 분류는 시드가 없어 헛돈다(hub:shopping-collect 담당)
            : KeywordCategory::where('is_active', true)->whereNull('naver_cid')
                ->orderByRaw('collected_at is null desc')->orderBy('collected_at')->first();

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
    public function publish(Request $request, KeywordHubPublisher $publisher, SearchEnginePing $ping)
    {
        $limit = min(max((int) $request->input('limit', 3), 1), 10);
        $cands = KeywordCandidate::where('status', 'approved')
            ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')->orderBy('id')
            ->limit($limit)->get();

        if ($cands->isEmpty()) {
            return back()->with('status', '발행할 승인 후보가 없습니다. 후보를 먼저 승인하세요.');
        }

        $ok = $hold = 0;
        $published = collect();
        foreach ($cands as $c) {
            if ($doc = $publisher->publish($c)) {
                $ok++;
                $published->push($doc);
            } else {
                $hold++;
            }
        }

        $msg = "발행 완료 — 발행 {$ok}건 · 데이터 부족 보류 {$hold}건.";
        if ($note = $ping->afterHubPublish($published)) {
            $msg .= ' '.$note;
        }

        return back()->with('status', $msg);
    }

    /**
     * 연속 분석·발행 배치(JSON) — 허브 화면의 [시작]이 반복 호출한다.
     * 승인 절차 없이 쌓인 후보(pending·approved)를 유형(쇼핑/플레이스)별로 검색량 큰 순 1건씩 분석·발행한다.
     * 요청당 1건만 처리해 웹 타임아웃을 피하고(분석은 외부 API 여러 번), 남은 수를 돌려줘
     * 클라이언트 루프가 0이 되거나 [중단]을 누를 때까지 이어간다.
     */
    public function publishBatch(Request $request, KeywordHubPublisher $publisher, SearchEnginePing $ping)
    {
        // 유형 선택(쇼핑/플레이스)은 카테고리 type 으로 거른다. 미지정이면 전체.
        $type = in_array($request->input('type'), ['shopping', 'place'], true) ? $request->input('type') : null;

        // 쌓인 키워드 = 아직 발행/보류되지 않은 후보(pending·approved). 매번 새로 조회(처리분은 status 가 바뀌어 빠진다).
        $pending = fn () => KeywordCandidate::whereIn('status', ['pending', 'approved'])
            ->when($type, fn ($q) => $q->whereHas('category', fn ($c) => $c->where('type', $type)));

        $c = $pending()
            ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')->orderBy('id')
            ->first();

        if (! $c) {
            return response()->json(['data' => [
                'published' => 0, 'held' => 0, 'remaining' => 0, 'items' => [], 'ping' => '',
            ]]);
        }

        $doc = $publisher->publish($c);   // 실시간 분석(검색량) → 데이터 있으면 발행+published, 없으면 보류(rejected)
        $note = $doc ? $ping->afterHubPublish(collect([$doc])) : '';

        return response()->json(['data' => [
            'published' => $doc ? 1 : 0,
            'held' => $doc ? 0 : 1,
            'remaining' => $pending()->count(),
            'items' => [[
                'keyword' => $c->keyword,
                'ok' => (bool) $doc,
                'url' => $doc?->shareUrl(),
            ]],
            'ping' => $note,
        ]]);
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
