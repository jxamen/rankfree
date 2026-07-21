<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Keyword\HubAutoRun;
use App\Domain\Keyword\KeywordHubCollectionControl;
use App\Domain\Keyword\KeywordHubCollector;
use App\Domain\Keyword\KeywordHubPublisher;
use App\Domain\Seo\SearchEnginePing;
use App\Http\Controllers\Controller;
use App\Jobs\KeywordHubCollectCategoryJob;
use App\Jobs\KeywordHubCollectShoppingRootJob;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordHubRun;
use App\Models\KeywordHubRunItem;
use App\Models\KeywordSearch;
use App\Models\MarketAnalysis;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * 키워드 자동 분석 관리 — 후보 생성과 분석 발행을 운영한다.
 * 플레이스 후보는 키워드 분석, 쇼핑 후보는 쇼핑 시장 분석으로 발행한다.
 */
class KeywordHubController extends Controller
{
    /** 첫 화면 — 현황 요약·병렬 자동 분석·최근 발행. 후보 큐·수집은 candidates 로 분리. */
    public function index()
    {
        return view('admin.keyword-hub.index', [
            'counts' => KeywordCandidate::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'candidateTypeCounts' => $this->candidateTypeCounts(),
            'hubDocsByType' => [
                'place' => $this->recentHubDocs('place'),
                'shopping' => $this->recentHubDocs('shopping'),
            ],
            'publishedCounts' => $this->publishedCounts(),
            'categoryBreakdown' => $this->publishedCategoryBreakdown(),
            'collectTargets' => $this->collectTargetSummary(),
            'collectionRuns' => KeywordHubRun::with('items')->latest('id')->limit(5)->get(),
            'collectionControl' => KeywordHubCollectionControl::state(),
            'auto' => $this->autoPayload(),   // 자동 분석 초기 상태(새로고침·재방문 복원용)
        ]);
    }

    /** 자동 발행 상태(폴링용 JSON). 브라우저·프록시 캐시 금지 — 폴링이 최신 진행을 봐야 한다. */
    public function autoStatus()
    {
        return response()->json(['data' => $this->autoPayload()])
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    public function collectionStatus()
    {
        $runs = KeywordHubRun::with('items')->latest('id')->limit(5)->get()
            ->map(fn (KeywordHubRun $run) => $this->collectionRunPayload($run))
            ->values();

        return response()->json(['data' => $runs])
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    public function collectionControl(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $state = KeywordHubCollectionControl::set(
            (bool) $data['enabled'],
            $request->user()?->email,
        );

        return response()->json(['data' => $this->collectionControlPayload($state)])
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    public function startCollection(Request $request)
    {
        $data = $request->validate([
            'collect_place' => 'nullable|boolean',
            'collect_shopping' => 'nullable|boolean',
            'place_limit' => 'nullable|integer|min:1|max:500',
            'shopping_pages' => 'nullable|integer|min:1|max:25',
            'shopping_depth' => 'nullable|integer|min:2|max:3',
            'shopping_delay_ms' => 'nullable|integer|min:0|max:5000',
        ]);

        $collectPlace = $request->boolean('collect_place');
        $collectShopping = $request->boolean('collect_shopping');
        if (! $collectPlace && ! $collectShopping) {
            return back()->withErrors(['collect' => '수집 대상을 하나 이상 선택해 주세요.']);
        }

        $type = $collectPlace && $collectShopping ? 'both' : ($collectPlace ? 'place' : 'shopping');
        $options = [
            'place_limit' => min(max((int) ($data['place_limit'] ?? 50), 1), 500),
            'shopping_pages' => min(max((int) ($data['shopping_pages'] ?? config('rankfree.hub.datalab_pages', 25)), 1), 25),
            'shopping_depth' => min(max((int) ($data['shopping_depth'] ?? 3), 2), 3),
            'shopping_delay_ms' => min(max((int) ($data['shopping_delay_ms'] ?? 300), 0), 5000),
        ];

        $run = KeywordHubRun::create([
            'type' => $type,
            'status' => 'queued',
            'options' => $options,
            'note' => '관리자 수동 시작',
        ]);

        $items = [];
        if ($collectPlace) {
            foreach ($this->placeCollectCategories($options['place_limit']) as $category) {
                $items[] = $run->items()->create([
                    'type' => 'place',
                    'target_type' => 'category',
                    'target_id' => (string) $category->id,
                    'label' => $category->parent ? $category->parent->name.' > '.$category->name : $category->name,
                ]);
            }
        }

        if ($collectShopping) {
            $shoppingRoots = $this->shoppingRootCategories();
            if ($shoppingRoots->isEmpty()) {
                $items[] = $run->items()->create([
                    'type' => 'shopping',
                    'target_type' => 'shopping_all',
                    'target_id' => null,
                    'label' => '쇼핑 전체',
                    'note' => '데이터랩 분류가 아직 없어 전체 동기화로 시작',
                ]);
            } else {
                foreach ($shoppingRoots as $root) {
                    $items[] = $run->items()->create([
                        'type' => 'shopping',
                        'target_type' => 'shopping_root',
                        'target_id' => (string) $root->naver_cid,
                        'label' => $root->name,
                    ]);
                }
            }
        }

        $run->forceFill([
            'total_jobs' => count($items),
            'status' => count($items) ? 'queued' : 'completed',
            'finished_at' => count($items) ? null : now(),
            'note' => count($items) ? '큐에 등록됨' : '수집 대상 없음',
        ])->save();

        foreach ($items as $item) {
            if ($item->type === 'shopping') {
                KeywordHubCollectShoppingRootJob::dispatch($item->id);
            } else {
                KeywordHubCollectCategoryJob::dispatch($item->id);
            }
        }

        return back()->with('status', '백그라운드 수집 작업 '.$run->total_jobs.'개를 큐에 등록했습니다.');
    }

    /** 자동 분석 on/off 토글 — type 미지정이면 쇼핑+플레이스 동시 처리. 서버 크론이 큐를 채운다. */
    public function autoToggle(Request $request)
    {
        $state = $request->boolean('on')
            ? HubAutoRun::start($request->input('type'))
            : HubAutoRun::stop();

        return response()->json(['data' => $this->autoPayload($state)])
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    /** 자동 분석 상태 → 화면/폴링 페이로드. */
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

    private function collectionControlPayload(?array $state = null): array
    {
        $state = $state ?? KeywordHubCollectionControl::state();
        $updatedAt = $state['updated_at'] ?? null;

        return [
            'enabled' => (bool) ($state['enabled'] ?? true),
            'updated_at' => $updatedAt ? date('Y-m-d H:i:s', (int) $updatedAt) : null,
            'updated_by' => $state['updated_by'] ?? null,
        ];
    }

    /** 후보·수집 관리 — 후보 큐(필터·일괄 처리)·수동 수집. 발행은 index 에서. */
    public function candidates(Request $request)
    {
        $status = in_array($request->query('status'), KeywordCandidate::STATUSES, true) ? $request->query('status') : 'pending';
        $type = in_array($request->query('type'), ['place', 'shopping'], true) ? $request->query('type') : 'place';
        $catId = (int) $request->query('category', 0);
        $source = (string) $request->query('source', ''); // 출처 필터(combo=지역조합/seed/related/autocomplete/gsc/datalab)
        $kw = trim((string) $request->query('q', ''));    // 키워드 검색(대량 후보 탐색용)
        $region = trim((string) $request->query('region', '')); // 지역 필터(플레이스 — 강남역·망원동…)
        if ($type !== 'place') {
            $region = '';
        }
        if ($catId && ! KeywordCategory::whereKey($catId)->where('type', $type)->exists()) {
            $catId = 0;
        }

        return view('admin.keyword-hub.candidates', [
            // 선택한 유형만 표시한다. 지역 후보와 쇼핑 데이터랩 카테고리가 한 목록에 섞이지 않게 한다.
            'categories' => KeywordCategory::with('parent.parent')
                ->where('type', $type)
                ->orderByRaw('parent_id is null desc')
                ->orderBy('sort')
                ->orderBy('id')
                ->get(),
            // 지금 수집 대상도 현재 유형의 수동 카테고리만 표시한다.
            'seedCategories' => KeywordCategory::with('parent')
                ->where('type', $type)
                ->whereNull('naver_cid')
                ->withCount([
                'candidates as pending_count' => fn ($q) => $q->where('status', 'pending'),
                'candidates as approved_count' => fn ($q) => $q->where('status', 'approved'),
                'candidates as published_count' => fn ($q) => $q->where('status', 'published'),
            ])->orderBy('type')->orderBy('sort')->orderBy('id')->get(),
            'candidates' => $this->filteredCandidates($status, $type, $catId, $source, $kw, $region)
                ->with('category.parent.parent')
                ->orderByRaw('monthly_total is null')->orderByDesc('monthly_total')->orderByDesc('id')
                ->paginate(50)->withQueryString(),
            'status' => $status,
            'type' => $type,
            'catId' => $catId,
            'source' => $source,
            'q' => $kw,
            'region' => $region,
            'counts' => KeywordCandidate::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'typeCounts' => $this->candidateTypeCounts(),
            // 출처별 후보 수(현 status 기준) — 시딩(지역조합) 결과를 화면에서 바로 확인
            'sourceCounts' => KeywordCandidate::where('status', $status)
                ->whereHas('category', fn ($q) => $q->where('type', $type))
                ->when($catId, fn ($q) => $q->where('category_id', $catId))
                ->selectRaw('source, count(*) as c')->groupBy('source')->pluck('c', 'source'),
            // 지역별 후보 수(현 status·카테고리·출처 기준) — 플레이스를 지역으로 훑기
            'regionCounts' => $type === 'place'
                ? KeywordCandidate::where('status', $status)
                    ->whereHas('category', fn ($q) => $q->where('type', 'place'))
                    ->when($catId, fn ($q) => $q->where('category_id', $catId))
                    ->when($source !== '', fn ($q) => $q->where('source', $source))
                    ->whereNotNull('region')
                    ->selectRaw('region, count(*) as c')->groupBy('region')->orderByDesc('c')->orderBy('region')
                    ->limit(200)->pluck('c', 'region')
                : collect(),
        ]);
    }

    /** 카테고리별 발행 문서 목록 — 플레이스는 키워드 분석, 쇼핑은 시장 분석 링크로 연다. */
    public function published(Request $request, string $type, KeywordCategory $category)
    {
        abort_unless(in_array($type, ['place', 'shopping'], true) && $category->type === $type, 404);

        $kw = trim((string) $request->query('q', ''));
        $categoryIds = $this->categoryDescendantIds($category);

        if ($type === 'shopping') {
            $systemUserId = $this->systemUserId();
            $docs = $systemUserId
                ? MarketAnalysis::with('category.parent.parent')
                    ->where('user_id', $systemUserId)
                    ->whereIn('category_id', $categoryIds)
                    ->when($kw !== '', fn ($q) => $q->where('keyword', 'like', '%'.addcslashes($kw, '\\%_').'%'))
                    ->orderByDesc('id')
                    ->paginate(50)->withQueryString()
                : MarketAnalysis::whereRaw('1 = 0')->paginate(50)->withQueryString();
        } else {
            $docs = KeywordSearch::with('category.parent.parent')
                ->where('origin', 'hub')
                ->whereIn('category_id', $categoryIds)
                ->when($kw !== '', fn ($q) => $q->where('keyword', 'like', '%'.addcslashes($kw, '\\%_').'%'))
                ->orderByDesc('monthly_total')
                ->orderByDesc('id')
                ->paginate(50)->withQueryString();
        }

        return view('admin.keyword-hub.published', [
            'type' => $type,
            'category' => $category->loadMissing('parent.parent'),
            'docs' => $docs,
            'q' => $kw,
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
            'type' => 'nullable|in:place,shopping',
            'category' => 'nullable|integer',
            'source' => 'nullable|string|max:20',
            'q' => 'nullable|string|max:120',
            'region' => 'nullable|string|max:60',
        ]);

        $query = $this->filteredCandidates(
            $data['status'],
            $data['type'] ?? 'place',
            (int) ($data['category'] ?? 0),
            (string) ($data['source'] ?? ''),
            trim((string) ($data['q'] ?? '')),
            trim((string) ($data['region'] ?? '')),
        );

        if ($data['action'] === 'delete') {
            $n = $query->delete();

            return back()->with('status', "필터 전체 {$n}건을 삭제했습니다.");
        }

        $status = ['approve' => 'approved', 'reject' => 'rejected', 'pending' => 'pending'][$data['action']];
        $n = $query->where('status', '!=', 'published')->update(['status' => $status]);

        return back()->with('status', "필터 전체 {$n}건을 '{$status}' 상태로 변경했습니다.");
    }

    /** 승인 큐 공통 필터(목록·전체 일괄 처리가 동일 조건을 쓰도록 단일화). */
    private function filteredCandidates(string $status, string $type, int $catId, string $source, string $kw, string $region = '')
    {
        return KeywordCandidate::query()
            ->where('status', $status)
            ->whereHas('category', fn ($q) => $q->where('type', $type))
            ->when($catId, fn ($q) => $q->where('category_id', $catId))
            ->when($source !== '', fn ($q) => $q->where('source', $source))
            ->when($type === 'place' && $region !== '', fn ($q) => $q->where('region', $region))
            ->when($kw !== '', fn ($q) => $q->where('keyword', 'like', '%'.addcslashes($kw, '\\%_').'%'));
    }

    /** 지금 수집 — 선택 카테고리(없으면 로테이션 순서대로 1개)를 동기 수집. */
    public function collect(Request $request, KeywordHubCollector $collector)
    {
        $catId = (int) $request->input('category_id', 0);
        $type = in_array($request->input('type'), ['place', 'shopping'], true) ? $request->input('type') : 'place';
        $cat = $catId
            ? KeywordCategory::findOrFail($catId)
            // 자동 로테이션은 수동(시드) 카테고리만 — 데이터랩 분류는 시드가 없어 헛돈다(hub:shopping-collect 담당)
            : KeywordCategory::where('type', $type)->where('is_active', true)->whereNull('naver_cid')
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

    private function collectTargetSummary(): array
    {
        return [
            'place_categories' => $this->placeCollectCategories(100000)->count(),
            'shopping_roots' => $this->shoppingRootCategories()->count(),
        ];
    }

    private function candidateTypeCounts(): array
    {
        $rows = KeywordCandidate::query()
            ->join('keyword_categories', 'keyword_categories.id', '=', 'keyword_candidates.category_id')
            ->selectRaw('keyword_candidates.status, keyword_categories.type, count(*) as c')
            ->groupBy('keyword_candidates.status', 'keyword_categories.type')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->status][(string) $row->type] = (int) $row->c;
        }

        return $counts;
    }

    private function recentHubDocs(string $type)
    {
        if ($type === 'shopping') {
            $systemUserId = $this->systemUserId();
            if (! $systemUserId) {
                return collect();
            }

            return MarketAnalysis::latest('id')
                ->where('user_id', $systemUserId)
                ->limit(10)
                ->get();
        }

        return KeywordSearch::with('category')
            ->where('origin', 'hub')
            ->whereHas('category', fn ($q) => $q->where('type', $type))
            ->latest('id')
            ->limit(10)
            ->get();
    }

    private function publishedCounts(): array
    {
        $systemUserId = $this->systemUserId();

        return [
            'place' => KeywordSearch::where('origin', 'hub')
                ->whereHas('category', fn ($q) => $q->where('type', 'place'))
                ->count(),
            'shopping' => $systemUserId
                ? MarketAnalysis::where('user_id', $systemUserId)->count()
                : 0,
        ];
    }

    private function publishedCategoryBreakdown(): array
    {
        $systemUserId = $this->systemUserId();

        return [
            'place' => KeywordCategory::where('type', 'place')
                ->whereNull('parent_id')
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
                ->map(fn (KeywordCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'count' => KeywordSearch::where('origin', 'hub')
                        ->whereIn('category_id', $this->categoryDescendantIds($category))
                        ->count(),
                ])
                ->values(),
            'shopping' => KeywordCategory::where('type', 'shopping')
                ->whereNull('parent_id')
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
                ->map(fn (KeywordCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'count' => $systemUserId
                        ? MarketAnalysis::where('user_id', $systemUserId)
                            ->whereIn('category_id', $this->categoryDescendantIds($category))
                            ->count()
                        : 0,
                ])
                ->values(),
        ];
    }

    private function categoryDescendantIds(KeywordCategory $category): array
    {
        $childIds = KeywordCategory::where('parent_id', $category->id)->pluck('id');

        return collect([$category->id])
            ->merge($childIds)
            ->merge(KeywordCategory::whereIn('parent_id', $childIds)->pluck('id'))
            ->unique()
            ->values()
            ->all();
    }

    private function systemUserId(): ?int
    {
        return User::where('email', (string) config('rankfree.hub.system_user_email', 'hub-system@rankfree.kr'))->value('id');
    }

    private function placeCollectCategories(int $limit)
    {
        return KeywordCategory::with('parent')
            ->where('type', 'place')
            ->where('is_active', true)
            ->whereNull('naver_cid')
            ->orderByRaw('collected_at is null desc')
            ->orderBy('collected_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (KeywordCategory $category) => (bool) $category->seedList())
            ->take($limit)
            ->values();
    }

    private function shoppingRootCategories()
    {
        return KeywordCategory::where('type', 'shopping')
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->whereNotNull('naver_cid')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    private function collectionRunPayload(KeywordHubRun $run): array
    {
        $progress = $run->total_jobs > 0
            ? (int) floor(($run->finished_jobs / max(1, $run->total_jobs)) * 100)
            : 100;

        return [
            'id' => $run->id,
            'type' => $run->type,
            'status' => $run->status,
            'total_jobs' => (int) $run->total_jobs,
            'finished_jobs' => (int) $run->finished_jobs,
            'failed_jobs' => (int) $run->failed_jobs,
            'progress' => $progress,
            'seeds' => (int) $run->seeds,
            'created_candidates' => (int) $run->created_candidates,
            'updated_candidates' => (int) $run->updated_candidates,
            'filtered_candidates' => (int) $run->filtered_candidates,
            'note' => $run->note,
            'created_at' => $run->created_at?->format('Y-m-d H:i:s'),
            'started_at' => $run->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $run->finished_at?->format('Y-m-d H:i:s'),
            'items' => $run->items
                ->sortBy('id')
                ->take(12)
                ->map(fn (KeywordHubRunItem $item) => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'label' => $item->label,
                    'status' => $item->status,
                    'created_candidates' => (int) $item->created_candidates,
                    'error' => $item->error,
                    'note' => $item->note,
                ])->values(),
        ];
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
