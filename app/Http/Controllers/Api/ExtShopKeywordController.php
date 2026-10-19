<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shopping\ShopExposureRunLock;
use App\Domain\Shopping\ShopKeywordClaims;
use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Http\Controllers\Controller;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopProductInfo;
use App\Services\ShopExposureBlockAlert;
use App\Support\DeliveryDiagnostic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 확장 — 쇼핑 유입키워드 상품정보 자동 수집(2026-07-29).
 *
 * 서버는 상품페이지를 직접 못 읽는다(네이버 429 + 스토어 SPA). 관리자 화면에서는 사람이 분석 화면을
 * 열면 확장이 collectProductPage 로 채워주는데, 외부 API 로 만든 분석은 그 화면을 여는 사람이 없어
 * 제목을 못 구하면 조합이 0개가 된다.
 *
 * → 요청자도 확장을 켜 두면, 확장이 이 큐를 폴링해 **자기 분석의** 상품정보를 채우고
 *   조합 재생성·순위확인까지 이어진다. 화면을 열 필요가 없다는 점만 다르고 규칙은 관리자와 동일하다.
 *
 * 권한: shop_keyword API 권한이 허용된 회원만(관리자가 부여). 대상은 항상 본인 분석뿐이다.
 */
class ExtShopKeywordController extends Controller
{
    public function intervalPolicy(Request $request): JsonResponse
    {
        $this->authorizeScope($request);

        return response()->json(['data' => [
            'collector_ip' => $request->ip(),
            'browser_default_interval_ms' => ShopExposureBlockAlert::baseInterval(),
            'collection_mode' => ShopExposureBlockAlert::collectionMode(), 'interval_ms' => ShopExposureBlockAlert::interval($request->ip()),
            'interval_revision' => ShopExposureBlockAlert::revision($request->ip()),
        ] + ShopExposureBlockAlert::parallelSettings()]);
    }

    public function __construct(private ShopKeywordExposureAnalyzer $analyzer) {}

    public function collectorState(Request $request, ShopKeywordAnalysis $analysis): JsonResponse
    {
        $this->authorizeScope($request);
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return response()->json(['data' => ['status' => $analysis->status]]);
    }

    public function collectorEvent(Request $request, ShopKeywordAnalysis $analysis): JsonResponse
    {
        $this->authorizeScope($request);
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'keyword' => 'sometimes|nullable|string|max:300',
            'stage' => 'sometimes|nullable|string|max:80',
            'message' => 'sometimes|nullable|string|max:1000',
            'kind' => 'required|in:403,429,보안문자,success,client_error,watchdog_restart,tab_closed,parallel_reset',
            'collector' => 'sometimes|in:extension,screen,browser',
            'blocked_on_resume' => 'sometimes|boolean',
            'interval_ms' => 'sometimes|nullable|integer|min:1000|max:30000',
        ]);
        if ($data['kind'] === 'parallel_reset') {
            ShopExposureBlockAlert::resetParallel((int) $analysis->id);

            return response()->json(['ok' => true]);
        }
        if ($data['kind'] === 'tab_closed') {
            if (! in_array($analysis->refresh()->status, ['ad_ended', 'target_reached', 'done'], true)) {
                $analysis->update(['status' => 'paused']);
                Cache::forever('shop-exposure:tab-closed:'.$analysis->id, true);
                $lock = app(ShopExposureRunLock::class);
                $lock->release('ip:'.$request->ip(), (int) $analysis->id);
                $lock->release('server', (int) $analysis->id);
            }

            return response()->json(['ok' => true]);
        }
        if ($analysis->refresh()->status === 'paused' && in_array($data['kind'], ['403', '429', '보안문자'], true)) {
            return response()->json(['ok' => true]);
        }
        // Product failures are independent of SERP blocking/pacing and analysis status.
        if (($data['stage'] ?? '') === 'product' && $data['kind'] === 'client_error') {
            $state = $analysis->product_collection;
            if ($state && ($state['state'] ?? '') === 'collecting') {
                $failed = ($state['attempts'] ?? 0) >= 3;
                ShopKeywordAnalysis::whereKey($analysis->id)
                    ->where('product_collection->state', 'collecting')
                    ->where(fn ($q) => $q->where('product_collection->mode', 'product_only')->orWhereNotIn('status', ['paused', 'target_reached', 'ad_ended']))
                    ->update([...(($state['mode'] ?? '') === 'product_only' ? [] : ['status' => $failed ? 'failed' : 'pending']), 'product_collection' => json_encode([
                        ...$state, 'state' => $failed ? 'failed' : 'retry',
                        'next_at' => now()->timestamp + 30,
                        'message' => ($data['message'] ?? '') ?: '상품정보 수집 실패',
                    ], JSON_UNESCAPED_UNICODE)]);
            }

            Cache::put('shop-product-status:'.$analysis->id, [
                'message' => $data['message'] ?? '', 'updated_at' => now()->toIso8601String(),
            ], now()->addDay());
        }
        if (in_array($data['kind'], ['client_error', 'watchdog_restart'], true)) {
            Log::warning('shop_exposure_client_event', [
                'analysis_id' => $analysis->id, 'user_id' => $request->user()->id,
                'kind' => $data['kind'], 'keyword' => $data['keyword'] ?? '',
                'stage' => $data['stage'] ?? '', 'message' => $data['message'] ?? '',
            ]);

            return response()->json(['ok' => true]);
        }
        app(ShopExposureBlockAlert::class)->record($analysis, $request->ip(), 'browser', $data['kind'], $data['blocked_on_resume'] ?? false, $data['interval_ms'] ?? null);

        return response()->json(['ok' => true, 'server_fallback' => ShopKeywordExposureAnalyzer::usesServerFallback($analysis)]);
    }

    /** shop_keyword 권한 없으면 403 — 이 자동 수집은 허용된 회원 전용. */
    private function authorizeScope(Request $request): void
    {
        abort_unless($request->user()?->canUseApiScope('shop_keyword'), 403, '쇼핑 유입키워드 권한이 없습니다.');
    }

    /** 전역 수집 잠금 주인 — 확장(브라우저)마다 다른 토큰의 해시(토큰 값은 저장하지 않는다). */
    private function extRunner(Request $request): string
    {
        return 'ext:'.substr(hash('sha256', (string) $request->bearerToken()), 0, 16).':'.substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->input('runner', 'legacy')), 0, 40);
    }

    /**
     * 상품정보(제목)가 비어 조합을 못 만든 내 분석 목록 — 확장이 순서대로 수집한다.
     * 확장은 product_url 을 백그라운드 탭으로 열어 수집한 뒤 productInfo 로 돌려준다.
     */
    public function productQueue(Request $request): JsonResponse
    {
        $this->authorizeScope($request);

        $limit = min(20, max(1, (int) $request->query('limit', 5)));

        $base = ShopKeywordAnalysis::where('user_id', $request->user()->id)->backgroundEligible()
            ->whereNotIn('status', ['paused', 'target_reached', 'ad_ended'])
            ->whereNotNull('product_id')->where('product_id', '!=', '')
            ->whereNotNull('product_url')->where('product_url', '!=', '')
            ->where(function ($q) {
                // ① 아직 한 번도 안 채워진 분석
                $q->whereNull('product_title')->orWhere('product_title', '')
                    // ② 제목은 채워졌지만 **태그가 빈** 분석 — 다시 수집한다.
                    //    네이버가 상태 JSON 구조를 바꾸면(2026-08-10 실제 발생) 확장이 제목만 뽑고
                    //    태그를 비운 채 저장하는데, 예전 조건은 제목만 봐서 이런 건이 큐에서 영영 빠졌다.
                    //    확장을 고쳐도 재수집이 안 돌아 API 응답의 seller_tags 가 계속 빈 배열이었다.
                    //    태그가 원래 없는 상품에서 무한 재수집이 되지 않도록 **24시간에 한 번**만 다시 시도한다.
                    ->orWhereNotExists(function ($s) {
                        $s->selectRaw('1')->from('shop_product_infos')
                            ->whereColumn('shop_product_infos.user_id', 'shop_keyword_analyses.user_id')
                            ->whereColumn('shop_product_infos.channel_product_id', 'shop_keyword_analyses.product_id')
                            ->where(fn ($w) => $w->where(fn ($complete) => $complete->whereRaw('LENGTH(shop_product_infos.seller_tags) > 2')->whereNotNull('shop_product_infos.delivery_fee')->whereNotNull('shop_product_infos.thumbnail_url')->where('shop_product_infos.thumbnail_url', '!=', ''))
                                ->orWhere('shop_product_infos.collected_at', '>=', now()->subDay()));
                    });
            });

        // Durable leases prevent simultaneous extensions from claiming the same product.
        $firstItems = [];
        $candidates = ShopKeywordAnalysis::where('user_id', $request->user()->id)
            ->whereIn('product_collection->state', ['pending', 'collecting', 'retry'])
            ->where(fn ($q) => $q->where('product_collection->mode', 'product_only')->orWhere(fn ($q) => $q->backgroundEligible()->whereNotIn('status', ['paused', 'target_reached', 'ad_ended'])))
            ->orderBy('id')->get();
        foreach ($candidates as $candidate) {
            if (count($firstItems) >= $limit) {
                break;
            }
            DB::transaction(function () use ($candidate, &$firstItems) {
                $a = ShopKeywordAnalysis::whereKey($candidate->id)->lockForUpdate()->first();
                $state = $a->product_collection;
                if (($state['mode'] ?? '') !== 'product_only' && ($a->collectionStopped() || ! ShopKeywordAnalysis::whereKey($a->id)->backgroundEligible()->exists())) {
                    return;
                }
                if (in_array($state['state'] ?? '', ['completed', 'failed'], true)
                    || ($state['next_at'] ?? 0) > now()->timestamp) {
                    return;
                }
                if (($state['attempts'] ?? 0) >= 3) {
                    $a->update([...(($state['mode'] ?? '') === 'product_only' ? [] : ['status' => 'failed']), 'product_collection' => [...$state,
                        'state' => 'failed', 'message' => '상품 수집 응답 시간 초과 (3회)']]);

                    return;
                }
                $a->update(['product_collection' => [...$state, 'state' => 'collecting',
                    'attempts' => ($state['attempts'] ?? 0) + 1, 'next_at' => now()->timestamp + 90]]);
                $firstItems[] = ['analysis_id' => (int) $a->id, 'core_keyword' => $a->core_keyword,
                    'product_id' => $a->product_id, 'product_url' => $a->product_url];
            });
        }
        $base->whereNull('product_collection');
        $rows = (clone $base)->latest('id')->limit(max(0, $limit - count($firstItems)))
            ->get(['id', 'core_keyword', 'product_id', 'product_url']);

        return response()->json(['data' => [
            'items' => array_merge($firstItems, $rows->map(fn ($a) => [
                'analysis_id' => (int) $a->id,
                'core_keyword' => (string) $a->core_keyword,
                'product_id' => (string) $a->product_id,
                'product_url' => (string) $a->product_url,
            ])->all()),
            'remaining' => count($firstItems) + (clone $base)->count(),
        ]]);
    }

    /**
     * 확장이 수집한 상품정보 반영 — 저장 → 조합 재생성 → 순위확인 자동 시작.
     * 관리자 화면의 refreshProductInfo 와 같은 규칙(소유자 확인 · 상품 일치 확인).
     */
    public function productInfo(Request $request, ShopKeywordAnalysis $analysis): JsonResponse
    {
        $this->authorizeScope($request);
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return Cache::lock('shop-product-save:'.$analysis->id, 120)
            ->block(5, fn () => $this->productInfoLocked($request, $analysis));
    }

    private function productInfoLocked(Request $request, ShopKeywordAnalysis $analysis): JsonResponse
    {
        $this->authorizeScope($request);
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $productOnly = ($analysis->refresh()->product_collection['mode'] ?? '') === 'product_only';
        abort_if(! $productOnly && $analysis->status === 'ad_ended', 409, '종료(광고 끝남)');

        abort_if(! $productOnly && $analysis->product_collection && $analysis->collectionStopped(), 409, '분석이 중단되었습니다.');

        $data = $request->validate([
            'info' => 'required|array',
            'info.channel_product_id' => 'nullable|string|max:40',
            'info.title' => 'nullable|string|max:300',
            'info.brand' => 'nullable|string|max:120',
            'info.mall_name' => 'nullable|string|max:150',
            'info.price' => 'nullable|integer|min:0|max:2000000000',
            'info.delivery_diagnostic' => 'nullable|string|max:2048',
            'info.delivery_fee' => 'nullable|integer|min:0|max:10000000',   // 배송비 — 0=무료
            'info.seller_tags' => 'nullable|array|max:60',
            'info.seller_tags.*' => 'nullable|string|max:80',
            'info.category' => 'nullable|string|max:191',
            'info.thumbnail_url' => 'nullable|string|max:500',
        ]);

        $info = (array) $data['info'];
        // 이 분석의 상품과 일치할 때만 반영(다른 상품 payload 오염 방지) — 관리자 경로와 동일
        if ((string) ($info['channel_product_id'] ?? '') !== (string) $analysis->product_id
            || trim((string) ($info['title'] ?? '')) === '') {
            return response()->json(['ok' => false, 'message' => '이 분석의 상품 정보가 아닙니다.'], 422);
        }

        if (($info['delivery_fee'] ?? null) === null && ! empty($info['delivery_diagnostic'])) {
            Log::info('product_delivery_diagnostic', [
                'user_id' => $request->user()->id, 'product_id' => $info['channel_product_id'] ?? '',
                'evidence' => DeliveryDiagnostic::sanitize($info['delivery_diagnostic']),
            ]);
        }

        $tags = array_values(array_unique(array_filter(array_map(
            fn ($s) => trim((string) $s), (array) ($info['seller_tags'] ?? [])
        ))));

        $attrs = [
            'title' => $info['title'],
            'brand' => $info['brand'] ?? null,
            'mall_name' => $info['mall_name'] ?? null,
            'price' => $info['price'] ?? null,
            'category' => $info['category'] ?? null,
            'thumbnail_url' => $info['thumbnail_url'] ?? null,
            'collected_at' => now(),
        ];
        // 태그가 비어 왔으면 기존 값을 지우지 않는다 — 네이버가 상태 JSON 구조를 바꾸면(2026-08-10 실제 발생)
        // 확장이 제목만 뽑고 태그는 빈 채로 보내는데, 그걸 그대로 덮으면 잘 모아둔 태그가 통째로 날아간다.
        if ($tags !== []) {
            $attrs['seller_tags'] = $tags;
        }
        // 배송비를 못 뽑았으면(구버전 확장 = 키 없음 / 신버전 = null) 기존 값을 지우지 않는다
        if (($info['delivery_fee'] ?? null) !== null) {
            $attrs['delivery_fee'] = (int) $info['delivery_fee'];
        }

        ShopProductInfo::updateOrCreate(
            ['user_id' => $analysis->user_id, 'channel_product_id' => (string) $analysis->product_id],
            $attrs,
        );

        if ($productOnly) {
            $analysis->update(['product_collection' => [...$analysis->product_collection, 'state' => 'completed', 'completed_at' => now()->toIso8601String()]]);

            return response()->json(['ok' => true, 'data' => ['analysis_id' => $analysis->id, 'status' => $analysis->status, 'check_pending' => false]]);
        }

        if ($analysis->product_collection) {
            $pi = ShopProductInfo::where('user_id', $analysis->user_id)
                ->where('channel_product_id', (string) $analysis->product_id)->firstOrFail();
            if (($analysis->product_collection['state'] ?? '') !== 'completed') {
                $this->analyzer->completeProductPreparation($analysis, $pi);
            }
            $r = ['added' => 0];
        } else {
            $r = $this->analyzer->refreshProductInfo($analysis);
        }
        if (($r['added'] ?? 0) > 0) {
            $this->analyzer->regenerate($analysis);
        }

        // 조합이 생겼으면 확인 대기 상태로만 바꾼다 — 서버는 순위를 자동으로 돌리지 않고(2026-07-29),
        // 확장이 check-queue 를 폴링해 이어서 확인한다. status 는 큐 선별 조건이라 반드시 갱신한다.
        $fresh = $analysis->fresh();
        $pending = false;
        if (! $fresh->targetReached() && ! $fresh->collectionStopped() && $fresh->combos()->whereNull('rank')->exists()) {
            if ($fresh->status !== 'checking') {
                $fresh->update(['status' => 'checking']);
                $fresh = $fresh->fresh();
            }
            $pending = true;
        }

        return response()->json(['ok' => true, 'data' => [
            'analysis_id' => (int) $fresh->id,
            'title' => (string) $fresh->product_title,
            'combo_count' => (int) $fresh->combo_count,
            'status' => (string) $fresh->status,
            'check_pending' => $pending,   // 확인할 조합이 생겼다 — 확장이 check-queue 로 이어받는다
        ]]);
    }

    /**
     * 순위 확인 대기열 — 확인이 남은 **내 API 분석** 하나와 그 미확인 조합을 준다.
     * 관리자·주문 분석은 최근 10분 활동이 있고 저장이 2분간 끊겼을 때만 이어받는다.
     */
    public function checkQueue(Request $request): JsonResponse
    {
        $this->authorizeScope($request);

        $request->validate(['exclude' => 'sometimes|array|max:10000', 'exclude.*' => 'integer']);
        $limit = min(4, max(1, (int) $request->query('limit', ShopExposureBlockAlert::parallelSettings()['parallel_tabs'])));

        // Failed reads remain unknown. Once this runner has skipped every remaining item,
        // release its own lease so the next analysis can start immediately.
        if ($request->input('exclude', [])) {
            $skipped = ShopKeywordAnalysis::where('user_id', $request->user()->id)->backgroundEligible()->where(fn ($q) => $q->where('created_via', 'api')->orWhere(fn ($active) => $active->where('status', 'checking')->where('collection_source', '확장 수집기'))->orWhere(fn ($w) => $w->where('status', 'checking')->where('created_at', '<=', now()->subMinutes(2))
                ->whereDoesntHave('combos', fn ($c) => $c->where('checked_at', '>', now()->subMinutes(2)))
                ->when(! $request->boolean('watchdog'), fn ($w) => $w->where(fn ($v) => $v->whereNull('collection_seen_at')->orWhere('collection_seen_at', '<=', now()->subSeconds(90))))))
                ->whereHas('combos', fn ($q) => $q->whereNull('rank')->whereIn('id', $request->input('exclude')))
                ->whereDoesntHave('combos', fn ($q) => $q->whereNull('rank')->whereNotIn('id', $request->input('exclude')))->pluck('id');
            foreach ($skipped as $id) {
                app(ShopExposureRunLock::class)->release('ip:'.$request->ip(), (int) $id, $this->extRunner($request));
            }
        }

        $analysis = ShopKeywordAnalysis::where('user_id', $request->user()->id)->backgroundEligible()
            ->where(fn ($q) => $q->where('created_via', 'api')->orWhere(fn ($active) => $active->where('status', 'checking')->where('collection_source', '확장 수집기'))->orWhere(fn ($w) => $w->where('status', 'checking')->where('created_at', '<=', now()->subMinutes(2))
                ->whereDoesntHave('combos', fn ($c) => $c->where('checked_at', '>', now()->subMinutes(2)))
                ->when(! $request->boolean('watchdog'), fn ($w) => $w->where(fn ($v) => $v->whereNull('collection_seen_at')->orWhere('collection_seen_at', '<=', now()->subSeconds(90))))))
            ->whereNotIn('status', ['paused', 'target_reached', 'ad_ended'])       // 사용자가 중단한 분석은 건드리지 않는다
            ->where(fn ($q) => $q->whereNull('product_collection')->orWhere('product_collection->state', 'completed'))
            ->where(fn ($q) => $q->whereNull('product_collection->mode')->orWhere('product_collection->mode', '!=', 'product_only')->orWhere('product_collection->resume_search', true))
            ->whereHas('combos', fn ($q) => $q->whereNull('rank')->whereNotIn('id', $request->input('exclude', [])))
            ->withMax('combos', 'checked_at')->orderByDesc('combos_max_checked_at')->orderBy('id')->first();

        if (! $analysis) {
            return response()->json(['data' => ['items' => [], 'remaining' => 0]]);
        }
        // A watchdog must observe saves, not lease/policy heartbeats from a frozen page.
        if ($request->boolean('watchdog')) {
            $last = $analysis->combos()->max('checked_at');
            if ($last && Carbon::parse($last)->gt(now()->subMinutes(2))) {
                return response()->json(['data' => ['items' => [], 'recent_save' => true]]);
            }
        }
        if (ShopKeywordExposureAnalyzer::usesServerFallback($analysis)) {
            return response()->json(['data' => ['server_fallback' => true, 'items' => []] + ShopExposureBlockAlert::parallelSettings() + $this->analyzer->progress($analysis)]);
        }
        // IP별 수집 잠금(2026-09-29) — 같은 IP 에서 다른 곳이 수집 중이면 빈 큐(확장은 다음 알람 3분 뒤 재시도 = 줄 서기)
        $lock = app(ShopExposureRunLock::class);
        $r = $lock->acquire('ip:'.$request->ip(), $this->extRunner($request), (int) $analysis->id, (string) $analysis->core_keyword);
        if (! $r['ok']) {
            return response()->json(['data' => ['items' => [], 'busy' => true, 'taken_over' => $r['taken_over'] ?? false, 'message' => ($r['taken_over'] ?? false) ? '다른 창/확장이 이어 받았어요' : $lock->busyMessage($r['holder'], (bool) $request->user()?->isAdmin())]]);
        }

        $limit = min($limit, ShopExposureBlockAlert::parallelSettings((int) $analysis->id)['parallel_reduced'] ? 1 : 4);
        // Never hand the extension more work than the remaining top-five quota.
        if ($analysis->keyword_target) {
            $limit = min($limit, max(1, $analysis->keyword_target - $analysis->topFiveCount()));
        }
        if ($request->boolean('policy_only')) {
            app(ShopKeywordClaims::class)->renew($analysis, (string) $request->input('runner', ''));
        }
        $items = $request->boolean('policy_only') ? collect() : app(ShopKeywordClaims::class)->claim($analysis, (string) $request->input('runner', ''), $limit, $request->input('exclude', []));

        return response()->json(['data' => [
            'collector_ip' => $request->ip(), 'interval_revision' => ShopExposureBlockAlert::revision($request->ip()),
            'collection_mode' => ShopExposureBlockAlert::collectionMode(), 'interval_ms' => ShopExposureBlockAlert::interval($request->ip()),
            'target' => $this->analyzer->targetOf($analysis),
            'analysis_id' => (int) $analysis->id,
            'items' => $items->map(fn ($i) => ['item_id' => $i['id'], 'keyword' => $i['keyword'], 'claim_token' => $i['claim_token']])->all(),
        ] + ShopExposureBlockAlert::parallelSettings((int) $analysis->id) + $this->analyzer->progress($analysis)]);
    }

    /**
     * 확장이 가져온 통합검색 HTML 로 조합 1건의 순위를 판정·저장한다.
     * 관리자 화면의 checkHtml 과 같은 규칙(소유자 확인 · 이 분석의 조합만).
     */
    public function checkHtml(Request $request, ShopKeywordAnalysis $analysis): JsonResponse
    {
        $this->authorizeScope($request);
        abort_unless($analysis->user_id === $request->user()->id, 403);
        abort_if($analysis->refresh()->status === 'ad_ended', 409, '종료(광고 끝남)');

        abort_unless(ShopKeywordAnalysis::whereKey($analysis->id)->backgroundEligible()->exists(), 409, '최근 화면/수집 활동이 없는 분석입니다. 화면에서 다시 시작해 주세요.');

        abort_if($analysis->product_collection && ($analysis->product_collection['state'] ?? '') !== 'completed', 409, '상품 세부 정보 수집 대기');

        if (ShopKeywordExposureAnalyzer::usesServerFallback($analysis)) {
            return response()->json($this->analyzer->progress($analysis) + ['server_fallback' => true, 'skipped' => true]);
        }

        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'result_source' => 'nullable|in:integrated,shopping',
            'html' => ['nullable', 'string', 'max:4000000'],
        ]);

        // IP별 수집 잠금 — 같은 IP 에서 다른 곳이 수집 중이면 403(현 확장은 403 에서 그 루프를 즉시 멈춘다)
        $lock = app(ShopExposureRunLock::class);
        $r = $lock->acquire('ip:'.$request->ip(), $this->extRunner($request), (int) $analysis->id, (string) $analysis->core_keyword);
        if (! $r['ok']) {
            return response()->json(['busy' => true, 'taken_over' => $r['taken_over'] ?? false, 'message' => ($r['taken_over'] ?? false) ? '다른 창/확장이 이어 받았어요' : $lock->busyMessage($r['holder'], (bool) $request->user()?->isAdmin())], 403);
        }

        $item = $analysis->combos()->whereKey((int) $data['item_id'])->first();
        if (! $item) {
            return response()->json($this->analyzer->progress($analysis) + ['skipped' => true]);
        }

        // html 이 비어 있어도 정상 입력 — 가격비교 슬롯을 못 찾은 것으로 보고 순위 없음으로 기록한다.
        return app(ShopKeywordClaims::class)->save($analysis, $item, (string) $request->input('runner', ''), (string) $request->input('claim_token', ''), fn () => response()->json($this->analyzer->applyHtml($analysis, $item, (string) ($data['html'] ?? ''), $data['result_source'] ?? 'integrated')));
    }
}
