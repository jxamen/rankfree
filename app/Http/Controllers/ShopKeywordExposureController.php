<?php

namespace App\Http\Controllers;

use App\Domain\Order\OrderFieldAutofill;
use App\Domain\Shopping\NaverShopExposureService;
use App\Domain\Shopping\ShopExposureRunLock;
use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Domain\Shopping\ShopKeywordShortLinkService;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordAnalysisItem;
use App\Models\ShopKeywordShortLink;
use App\Models\ShopProductInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 쇼핑 노출 키워드 분석(25) — 관리자 `admin.shop-keyword`(운영자 전용, 2026-07-21 콘솔→관리자 이동).
 * 핵심 키워드 + 상품 URL → 키워드 자동 추출·조합 생성 후, 모바일 검색 가격비교 순위를 배치로
 * 채워가며(폴링) "상위 N위 노출" 키워드를 찾는다. 추출 키워드·조합은 개별 삭제 가능.
 */
class ShopKeywordExposureController extends Controller
{
    private const REFERENCE_SOURCES = ['autocomplete', 'searchad', 'shopping_related', 'keyword_rec', 'together', 'competitor_brand'];

    public function __construct(private ShopKeywordExposureAnalyzer $analyzer) {}

    /** Browser collection reports use the page session, without an extension API token. */
    public function collectorEvent(Request $request, ShopKeywordAnalysis $analysis): JsonResponse
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'keyword' => 'sometimes|nullable|string|max:300',
            'stage' => 'sometimes|nullable|string|max:80',
            'message' => 'sometimes|nullable|string|max:1000',
            'kind' => 'required|in:403,429,보안문자,success,client_error,watchdog_restart,tab_closed,parallel_reset',
            'blocked_on_resume' => 'sometimes|boolean',
            'interval_ms' => 'sometimes|nullable|integer|min:1000|max:30000',
            'evidence' => 'sometimes|nullable|array:version,signal,url_pattern,visible',
            'evidence.version' => 'required_with:evidence|in:visible-v1',
            'evidence.signal' => 'required_with:evidence|in:none,normal-results,visible-gate-iframe,visible-challenge,http-status,fetch-unverified',
            'evidence.url_pattern' => 'required_with:evidence|in:naver-page,naver-captcha-iframe,naver-captcha-page,naver-fetch',
            'evidence.visible' => 'required_with:evidence|boolean',
        ]);
        if ($data['kind'] === 'parallel_reset') {
            \App\Services\ShopExposureBlockAlert::resetParallel((int) $analysis->id);
            return response()->json(['ok' => true]);
        }
        if ($data['kind'] === 'tab_closed') {
            if (! in_array($analysis->refresh()->status, ['ad_ended', 'target_reached', 'done'], true)) {
                $analysis->update(['status' => 'paused']);
                Cache::forever('shop-exposure:tab-closed:'.$analysis->id, true);
                $lock = app(\App\Domain\Shopping\ShopExposureRunLock::class);
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
            \Illuminate\Support\Facades\Cache::put('shop-product-status:'.$analysis->id, [
                'message' => $data['message'] ?? '', 'updated_at' => now()->toIso8601String(),
            ], now()->addDay());
        }
        if (in_array($data['kind'], ['client_error', 'watchdog_restart'], true)) {
            \Illuminate\Support\Facades\Log::warning('shop_exposure_client_event', [
                'analysis_id' => $analysis->id, 'user_id' => $request->user()->id,
                'kind' => $data['kind'], 'keyword' => $data['keyword'] ?? '',
                'stage' => $data['stage'] ?? '', 'message' => $data['message'] ?? '',
            ]);
            return response()->json(['ok' => true]);
        }
        app(\App\Services\ShopExposureBlockAlert::class)->record(
            $analysis, $request->ip(), 'browser', $data['kind'], $data['blocked_on_resume'] ?? false, $data['interval_ms'] ?? null, $data['evidence'] ?? null
        );
        return response()->json(['ok' => true, 'server_fallback' => ShopKeywordExposureAnalyzer::usesServerFallback($analysis)]);
    }

    public function productStatus(Request $request, ShopKeywordAnalysis $analysis): JsonResponse
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        return response()->json(\Illuminate\Support\Facades\Cache::get('shop-product-status:'.$analysis->id, ['message' => '']));
    }

    public function resetInterval(Request $request, ShopKeywordAnalysis $analysis): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $ip = $request->ip();
        \App\Services\ShopExposureBlockAlert::resetInterval($ip);
        $serverIp = (string) config('rankfree.shopping.exposure.collector_ip', 'server-egress-unknown');
        if ($serverIp !== $ip) \App\Services\ShopExposureBlockAlert::resetInterval($serverIp);
        Cache::lock('shop-serp:server-lane', 30)->block(2, function () {
            $state = Cache::get(NaverShopExposureService::policyKey(), []);
            $state['gap'] = \App\Services\ShopExposureBlockAlert::baseInterval();
            Cache::put(NaverShopExposureService::policyKey(), $state, now()->addDay());
        });
        if (!$request->routeIs('admin.shop-keyword.interval')) \Illuminate\Support\Facades\Log::info('shop_exposure_interval_reset', ['analysis_id' => $analysis->id, 'ip' => $ip, 'user_id' => $request->user()->id, 'user_name' => $request->user()->name, 'at' => now()->toIso8601String()]);
        return response()->json(['interval_ms' => \App\Services\ShopExposureBlockAlert::baseInterval()]);
    }

    public function saveInterval(Request $request, ShopKeywordAnalysis $analysis): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && $analysis->user_id === $request->user()->id, 403);
        $data = $request->validate(['seconds' => 'required|numeric|min:1|max:30', 'collection_mode' => 'sometimes|in:tab,fetch', 'parallel_tabs' => 'sometimes|integer|min:1|max:4', 'tab_open_seconds' => 'sometimes|numeric|min:0|max:60|decimal:0,1', 'keywords_per_day' => 'sometimes|integer|min:10|max:500']);
        // 브라우저 소수 오차(2.2999…초)로 저장이 막히지 않게 서버에서 0.1초 단위로 맞춘다(대표님 10/19 08:49 「주문 목표 저장에 실패」).
        $data['seconds'] = round((float) $data['seconds'], 1);
        // 수집 방식은 사용자별로 서버에도 저장한다 — 확장을 새로 깔거나 다른 브라우저에서 열어도 고른 값이 유지된다(대표님 10-27 17:21 「저장을 했는데 풀렸다」). 간격 · 탭 수는 계속 브라우저별.
        if (isset($data['collection_mode'])) \App\Models\AppSetting::write('shop.exposure.collection_mode.user.'.$request->user()->id, $data['collection_mode']);
        // Other collection preferences live in the browser; only the order target is shared.
        if (isset($data['keywords_per_day'])) \App\Models\AppSetting::write('shop.exposure.keywords_per_day', (string) $data['keywords_per_day']);
        if (isset($data['keywords_per_day'])) {
            DB::transaction(function () use ($analysis, $data) {
                $current = ShopKeywordAnalysis::whereKey($analysis->id)->lockForUpdate()->firstOrFail();
                if ($current->order_days <= 0) return;
                $current->keyword_target = $current->order_days * (int) $data['keywords_per_day'];
                if ($current->status !== 'ad_ended') {
                    if ($current->targetReached()) $current->status = 'target_reached';
                    elseif ($current->status === 'target_reached') $current->status = 'checking';
                }
                $current->save();
            });
        }
        $response = response()->json(['interval_ms' => (int) round($data['seconds'] * 1000)]);
        \Illuminate\Support\Facades\Log::info('shop_exposure_order_target_saved', ['analysis_id' => $analysis->id, 'user_id' => $request->user()->id, 'user_name' => $request->user()->name, 'at' => now()->toIso8601String(), 'keywords_per_day' => $data['keywords_per_day'] ?? null]);
        return $response;
    }

    public function continueNow(Request $request, ShopKeywordAnalysis $analysis): JsonResponse
    {
        abort_unless($request->user()->isAdmin() && $analysis->user_id === $request->user()->id, 403);
        abort_if(in_array($analysis->status, ['ad_ended', 'target_reached']), 409);
        Cache::lock('shop-serp:server-lane', 30)->block(2, function () {
            $state = Cache::get(NaverShopExposureService::policyKey(), []);
            $state['until'] = 0;
            $state['last'] = 0;
            Cache::put(NaverShopExposureService::policyKey(), $state, now()->addDay());
        });
        Cache::forget('shop-exposure:error:'.$analysis->id);
        app(ShopExposureRunLock::class)->release('ip:'.$request->ip(), (int) $analysis->id);
        $analysis->forceFill(['collection_seen_at' => now()])->save();
        $analysis->update(['check_method' => 'search', 'status' => $analysis->combos()->whereNull('rank')->exists() ? 'checking' : 'done']);
        \Illuminate\Support\Facades\Log::info('shop_exposure_continue_now', ['analysis_id' => $analysis->id, 'user_id' => $request->user()->id, 'user_name' => $request->user()->name, 'at' => now()->toIso8601String()]);
        return response()->json(['ok' => true, 'collector_ip' => $request->ip()]);
    }

    public function index(Request $request)
    {
        $uid = $request->user()->id;
        $analyses = ShopKeywordAnalysis::where('user_id', $uid)
            ->withCount('shortLinks')
            ->latest()->limit(30)->get();

        return view('admin.shop-keyword.index', [
            'analyses' => $analyses,
            'top' => (int) config('rankfree.shopping.exposure.top', 5),
        ]);
    }

    public function store(Request $request)
    {
        if ($request->filled('fresh_order_analysis')) {
            $request->validate(['fresh_order_analysis' => 'required|integer']);
            $source = ShopKeywordAnalysis::findOrFail($request->integer('fresh_order_analysis'));
            abort_unless($source->user_id === $request->user()->id && $request->user()->isAdmin(), 403);
            $meta = $source->boostMeta();
            abort_unless(!empty($meta['source_order']), 422);
            $apiRequest = Request::create('/api/v1/shop-keywords', 'POST', [
                'core_keyword' => $source->core_keyword, 'product' => $source->product_url,
                'source_order' => $meta['source_order'], 'force_new' => true,
                'order_start_date' => $source->order_start_date, 'order_end_date' => $source->order_end_date,
            ]);
            $apiRequest->setUserResolver(fn () => $request->user());
            $result = app(\App\Http\Controllers\Api\ShopKeywordApiController::class)->store($apiRequest)->getData(true);
            return redirect()->route('admin.shop-keyword.show', $result['analysis']['id']);
        }
        $data = $request->validate([
            'core_keyword' => 'required|string|max:600',
            'product' => 'required|string|max:500',
            'threshold' => 'nullable|integer|min:1|max:40',
            'check_method' => 'nullable|in:api,search',
        ]);

        // 핵심 키워드 여러 개(쉼표·줄바꿈 구분, 2026-07-22) — 한 핵심으로 노출이 안 찾아질 때
        // 다른 핵심으로도 병행 시도할 수 있게 키워드별 분석을 각각 만든다.
        $cores = collect(preg_split('/[,\r\n]+/u', $data['core_keyword']))
            ->map(fn ($k) => trim($k))->filter()->unique()->values();
        if ($cores->isEmpty()) {
            return back()->withInput()->withErrors(['core_keyword' => '핵심 키워드를 입력하세요.']);
        }
        if ($cores->count() > 5) {
            return back()->withInput()->withErrors(['core_keyword' => '핵심 키워드는 한 번에 최대 5개까지 가능합니다(키워드별 분석이 각각 생성됩니다).']);
        }
        if ($tooLong = $cores->first(fn ($k) => mb_strlen($k) > 120)) {
            return back()->withInput()->withErrors(['core_keyword' => "핵심 키워드가 너무 깁니다: {$tooLong}"]);
        }

        // 빠른 확인(api)은 slot API 1콜이라 상위 20위까지만 판정된다 — 그 밖을 미노출로 오기록하지 않도록 막는다.
        $method = $data['check_method'] ?? 'api';
        $slotMax = NaverShopExposureService::SLOT_MAX;
        if ($method === 'api' && (int) ($data['threshold'] ?? 0) > $slotMax) {
            return back()->withInput()->withErrors([
                'threshold' => "빠른 확인 방식은 상위 {$slotMax}위까지만 판정할 수 있습니다. {$slotMax} 이하로 지정하거나 통합검색 크롤링을 선택하세요.",
            ]);
        }

        // 조합 수는 선택하지 않는다 — 만들 수 있는 조합 전부 생성(hard_cap 안전선).
        $created = [];
        foreach ($cores as $core) {
            $created[] = $this->analyzer->prepare(
                $request->user(),
                $core,
                $data['product'],
                null,
                ['threshold' => $data['threshold'] ?? null, 'check_method' => $data['check_method'] ?? 'api'],
            );
        }

        if (count($created) === 1) {
            return redirect()->route('admin.shop-keyword.show', $created[0]);
        }

        return redirect()->route('admin.shop-keyword')
            ->with('status', count($created).'개 핵심 키워드 분석을 만들었습니다 — 각 분석을 열면 순위 확인이 시작됩니다.');
    }

    /** 배치 순위체크(폴링) — 확장 미설치 폴백. 서버가 직접 fetch 해 일부를 체크하고 진행상황 JSON 반환. */
    public function check(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        abort_if($analysis->refresh()->status === 'ad_ended', 409, '종료(광고 끝남)');
        // 서버가 직접 네이버를 부른다 — 서버 IP 하나로 잠근다
        if ($busy = $this->runBusy($request, $analysis, 'server')) {
            return $busy;
        }

        $progress = $this->analyzer->checkBatch($analysis);
        if (ShopKeywordExposureAnalyzer::usesServerFallback($analysis) && !empty($progress['blocked']) && empty($progress['retry_at'])) {
            $until = (float) (Cache::get(NaverShopExposureService::policyKey(), [])['until'] ?? 0);
            $progress['retry_at'] = (int) ceil(max(microtime(true) + 2, $until) * 1000);
        }
        return response()->json($progress + [
            'server_fallback' => ShopKeywordExposureAnalyzer::usesServerFallback($analysis),
            'collector_ip' => (string) config('rankfree.shopping.exposure.collector_ip', 'server-egress-unknown'),
        ]);
    }

    /**
     * IP별 수집 잠금(2026-09-29) — 같은 IP 에서 다른 곳이 수집 중이면 423 + 안내(화면이 줄 서서 재시도). 획득·갱신되면 null.
     * scope 기본 = 요청 IP(브라우저가 네이버를 부른다). 주인 = 페이지가 보낸 runner(페이지 로드마다 새로),
     * 없으면(반영 전에 열어 둔 옛 탭) 사용자 단위.
     */
    private function runBusy(Request $request, ShopKeywordAnalysis $analysis, ?string $scope = null, bool $renew = true): ?JsonResponse
    {
        $runner = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->input('runner', ''));
        $owner = $runner !== '' ? 'page:'.substr($runner, 0, 40) : 'user:'.$request->user()->id;
        $lock = app(ShopExposureRunLock::class);
        $r = $lock->acquire($scope ?? 'ip:'.$request->ip(), $owner, (int) $analysis->id, (string) $analysis->core_keyword, $renew);
        if ($r['ok']) {
            return null;
        }

        return response()->json(['busy' => true, 'taken_over' => $r['taken_over'] ?? false, 'message' => ($r['taken_over'] ?? false) ? '다른 창/확장이 이어 받았어요' : $lock->busyMessage($r['holder'], (bool) $request->user()?->isAdmin()),
            'analysis_id' => (int) ($r['holder']['analysis_id'] ?? 0)] + $this->analyzer->progress($analysis), 423);
    }

    /** 미확인 조합 배치(확장 화면단 체크용) — 브라우저가 이 목록을 받아 한 건씩 m.search 를 가져온다. */
    public function pending(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        abort_if($analysis->refresh()->status === 'ad_ended', 409, '종료(광고 끝남)');
        if ($analysis->status === 'paused') {
            return response()->json(['data' => ['items' => []] + $this->analyzer->progress($analysis)]);
        }
        if (ShopKeywordExposureAnalyzer::usesServerFallback($analysis)) {
            return response()->json(['data' => ['server_fallback' => true, 'items' => []] + $this->analyzer->progress($analysis)]);
        }
        if ($busy = $this->runBusy($request, $analysis, null, ! $request->boolean('waiting'))) {
            return $busy;
        }

        if ($analysis->status === 'ad_ended' || $analysis->targetReached()) {
            return response()->json(['data' => ['items' => []] + $this->analyzer->progress($analysis)]);
        }
        $request->validate(['exclude' => 'sometimes|array|max:10000', 'exclude.*' => 'integer']);
        if ($request->boolean('policy_only')) app(\App\Domain\Shopping\ShopKeywordClaims::class)->renew($analysis, (string) $request->input('runner', ''));
        $items = $request->boolean('policy_only') ? collect() : app(\App\Domain\Shopping\ShopKeywordClaims::class)->claim(
            $analysis, (string) $request->input('runner', ''), min(\App\Services\ShopExposureBlockAlert::parallelSettings((int) $analysis->id)['parallel_reduced'] ? 1 : 4, max(1, (int) $request->input('limit', \App\Services\ShopExposureBlockAlert::parallelSettings((int) $analysis->id)['parallel_tabs']))), $request->input('exclude', [])
        );
        if ($items->isEmpty() && ! $analysis->combos()->whereNull('rank')->exists()) {
            app(ShopExposureRunLock::class)->release('ip:'.$request->ip(), (int) $analysis->id);
        }

        return response()->json(['data' => ['target' => $this->analyzer->targetOf($analysis), 'collector_ip' => $request->ip(), 'interval_revision' => \App\Services\ShopExposureBlockAlert::revision($request->ip()), 'collection_mode' => \App\Services\ShopExposureBlockAlert::collectionMode(), 'interval_ms' => \App\Services\ShopExposureBlockAlert::interval($request->ip()), 'items' => $items] + \App\Services\ShopExposureBlockAlert::parallelSettings((int) $analysis->id) + $this->analyzer->progress($analysis)]);
    }

    /** 확장(브라우저)이 가져온 m.search HTML 로 조합 1건 순위 저장 — 서버 IP 한도와 무관한 기본 경로. */
    public function checkHtml(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        abort_if($analysis->refresh()->status === 'ad_ended', 409, '종료(광고 끝남)');

        if (ShopKeywordExposureAnalyzer::usesServerFallback($analysis)) {
            return response()->json($this->analyzer->progress($analysis) + ['server_fallback' => true, 'skipped' => true]);
        }

        $data = $request->validate([
            'item_id' => 'required|integer',
            'result_source' => 'nullable|in:integrated,shopping',
            'html' => 'nullable|string|max:4000000',   // _INITIAL_STATE script 조각(수백 KB). 빈 값=가격비교 미노출
        ]);
        if ($busy = $this->runBusy($request, $analysis)) {
            return $busy;
        }

        $item = $analysis->combos()->whereKey((int) $data['item_id'])->first();
        if (! $item) {
            // 사용자가 루프 중 조합을 삭제한 경우 — 진행상황만 반환하고 계속
            return response()->json($this->analyzer->progress($analysis) + ['skipped' => true]);
        }

        return app(\App\Domain\Shopping\ShopKeywordClaims::class)->save($analysis, $item, (string) $request->input('runner', ''), (string) $request->input('claim_token', ''), fn () => response()->json($this->analyzer->applyHtml($analysis, $item, (string) ($data['html'] ?? ''), $data['result_source'] ?? 'integrated')));
    }

    /** 확장이 수집한 코어 키워드 신호(가격비교 HTML·함께많이찾는)를 참고/재료 토큰으로 병합. */
    public function supplement(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        abort_if($analysis->refresh()->status === 'ad_ended', 409, '종료(광고 끝남)');

        $data = $request->validate([
            'mshop_html' => 'nullable|string|max:4000000',
            'related' => 'nullable|array|max:60',
            'related.*' => 'string|max:120',
        ]);

        // 체크 루프가 페이지를 자주 reload 하므로 같은 분석에 반복 병합하지 않는다(10분 가드)
        if (! Cache::add("shop-exposure:supplement:{$analysis->id}", 1, 600)) {
            return response()->json(['data' => ['added' => 0, 'skipped' => true]]);
        }

        return response()->json(['data' => $this->analyzer->supplement(
            $analysis, (string) ($data['mshop_html'] ?? ''), (array) ($data['related'] ?? [])
        )]);
    }

    /**
     * 확장 백그라운드 수집(상품페이지) 후 상품정보를 분석에 반영 — 제목·업체명·가격 + 제목/태그 토큰.
     * 확장이 payload(info)를 페이지로 돌려주면 여기서 직접 저장한다 — 확장 로그인이 다른 서버(prod)를
     * 보고 있어도 현재 사이트의 분석이 동작한다.
     */
    public function refreshProductInfo(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        abort_if($analysis->refresh()->status === 'ad_ended', 409, '종료(광고 끝남)');

        $data = $request->validate([
            'info' => 'nullable|array',
            'info.channel_product_id' => 'nullable|string|max:40',
            'info.title' => 'nullable|string|max:300',
            'info.brand' => 'nullable|string|max:120',
            'info.mall_name' => 'nullable|string|max:150',
            'info.price' => 'nullable|integer|min:0|max:2000000000',
            'info.delivery_fee' => 'nullable|integer|min:0|max:10000000',   // 배송비 — 0=무료
            'info.seller_tags' => 'nullable|array|max:60',
            'info.seller_tags.*' => 'nullable|string|max:80',
            'info.category' => 'nullable|string|max:191',
            'info.thumbnail_url' => 'nullable|string|max:500',   // 대표이미지 — 쇼핑 유입 발주용(2026-07-22)
        ]);

        $info = (array) ($data['info'] ?? []);
        // 이 분석의 상품과 일치할 때만 반영(다른 상품 payload 오염 방지)
        if ($info !== [] && (string) ($info['channel_product_id'] ?? '') === (string) $analysis->product_id
            && ($info['title'] ?? '') !== '') {
            ShopProductInfo::updateOrCreate(
                ['user_id' => $analysis->user_id, 'channel_product_id' => (string) $analysis->product_id],
                [
                    'title' => $info['title'] ?? null,
                    'brand' => $info['brand'] ?? null,
                    'mall_name' => $info['mall_name'] ?? null,
                    'price' => $info['price'] ?? null,
                    'seller_tags' => array_values(array_unique(array_filter(array_map(
                        fn ($s) => trim((string) $s), (array) ($info['seller_tags'] ?? [])
                    )))),
                    'category' => $info['category'] ?? null,
                    'thumbnail_url' => $info['thumbnail_url'] ?? null,
                    'collected_at' => now(),
                    // 배송비는 못 뽑았을 때 기존 값을 지우지 않는다(0=무료 / null=미수집)
                ] + (($info['delivery_fee'] ?? null) !== null ? ['delivery_fee' => (int) $info['delivery_fee']] : []),
            );
        }

        $r = $this->analyzer->refreshProductInfo($analysis);

        // 새 재료(제목 단어·태그)가 들어왔으면 조합을 자동 재편성 — 속성 위주 저효율 조합을
        // 제목 단어 중심으로 갈아끼운다(미확인만 교체, 확인 결과는 보관).
        if (($r['added'] ?? 0) > 0) {
            $regen = $this->analyzer->regenerate($analysis);
            $r['regenerated'] = true;
            $r['combo_added'] = $regen['added'];
        }

        // 상품정보를 다시 수집하면 최신 수집값으로 연결 주문의 내부(숨김) 필드를 갱신한다(force, 2026-07-24).
        // 자동채움 필드(autofill_source)는 수집값이 진실 — 과거에 잘못 매핑돼 채워진 값(예: 상점명 자리에 상품명)도
        // '상품정보 다시 수집' 한 번으로 교정된다. autofill_source 없는 수동 전용 필드는 fillFromAnalysis 가 건드리지 않는다.
        if ($analysis->marketing_order_id) {
            app(OrderFieldAutofill::class)->fillFromAnalysis($analysis->fresh(), force: true);
        }

        return response()->json(['data' => $r]);
    }

    /**
     * "중단"/"이어서 확인" 상태 저장 — 중단을 서버에 기록해 새로고침해도 자동 재시작하지 않게 한다.
     * 재개는 페이지의 "이어서 확인" 버튼(또는 새 조합·노출 재확인 같은 명시적 액션)으로만 된다.
     */
    public function pause(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        abort_if($analysis->refresh()->status === 'ad_ended', 409, '종료(광고 끝남)');

        $paused = $request->boolean('paused', true);
        if (! $paused) {
            \App\Services\ShopExposureBlockAlert::resetParallel((int) $analysis->id);
            $analysis->forceFill(['collection_seen_at' => now()])->save();
        }
        Cache::forget('shop-exposure:tab-closed:'.$analysis->id);
        $hasRemaining = $analysis->combos()->whereNull('rank')->exists();

        if ($paused) {
            $lock = app(ShopExposureRunLock::class);   // 중단 — 다른 수집에 바로 양보
            $lock->release('ip:'.$request->ip(), (int) $analysis->id);
            $lock->release('server', (int) $analysis->id);
        }
        if ($paused && $hasRemaining) {
            $analysis->update(['status' => 'paused']);
        } elseif (! $paused && $analysis->status === 'paused') {
            $analysis->update(['status' => $hasRemaining ? 'checking' : 'done']);
        }

        return response()->json(['data' => ['status' => $analysis->status]]);
    }

    /**
     * 수집 상태를 화면에서 고른 값 그대로 저장한다(관리자, 대표님 10-27 16:59 「상태를 바꿀 수 있게해줘」 · 17:12 「그냥 저장만 시키게」).
     * 조건 검사는 없다 — 남은 조합 · 목표 도달 · 광고 끝남과 상관없이 저장한다(광고 끝남을 되살리지 못하게 막는 모델 이벤트도 거치지 않도록 쿼리로 갱신).
     *   수집 중: 「이어서 확인」과 같게 화면 활동 시각을 찍어 확장 대기열(check-queue)이 이어받게 한다.
     *   일시정지: 「중단」과 같게 수집 잠금을 푼다.
     * 바꾼 사람 · 시각은 로그(shop_keyword_status_changed)와 updated_at 에 남는다.
     */
    public function changeStatus(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($request->user()->isAdmin() && $analysis->user_id === $request->user()->id, 403);
        $new = $request->validate(['status' => ['required', \Illuminate\Validation\Rule::in(array_keys(ShopKeywordAnalysis::MANUAL_STATUSES))]])['status'];
        $old = $analysis->status;
        $changes = ['status' => $new];
        if ($new === 'checking') {
            \App\Services\ShopExposureBlockAlert::resetParallel((int) $analysis->id);
            Cache::forget('shop-exposure:tab-closed:'.$analysis->id);
            $changes['collection_seen_at'] = now();
        } elseif ($new === 'paused') {
            Cache::forget('shop-exposure:tab-closed:'.$analysis->id);
            $lock = app(ShopExposureRunLock::class);   // 중단 — 다른 수집에 바로 양보
            $lock->release('ip:'.$request->ip(), (int) $analysis->id);
            $lock->release('server', (int) $analysis->id);
        }
        ShopKeywordAnalysis::whereKey($analysis->id)->update($changes);
        \Illuminate\Support\Facades\Log::info('shop_keyword_status_changed', ['analysis' => $analysis->id, 'user' => $request->user()->id, 'from' => $old, 'to' => $new, 'ip' => $request->ip()]);
        $analysis->refresh();
        // 수집 중으로 바꿨는데 확인할 조합이 없으면 막지 않고 새 조합을 만들어 돌린다(대표님 10-27 17:31) — 목표 도달 분석은 건드리지 않는다
        $rebuilt = 0;
        if ($new === 'checking' && ! $analysis->combos()->whereNull('rank')->exists() && ! $analysis->targetReached()) {
            $rebuilt = (int) ($this->analyzer->rebuild($analysis)['added'] ?? 0);
            $analysis->refresh();
        }

        return response()->json(['data' => ['status' => $analysis->status, 'label' => $analysis->collectionLabel(), 'changed' => $old !== $new, 'rebuilt' => $rebuilt]]);
    }

    /** "노출 재확인" — 저장된 결과를 보존하고 미확인·수집 실패 항목만 이어서 확인한다. */
    public function recheckExposed(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($analysis) {
            $analysis = ShopKeywordAnalysis::whereKey($analysis->id)->lockForUpdate()->firstOrFail();
            abort_if($analysis->status === 'ad_ended', 409, '종료(광고 끝남)');

            // 수집 실패는 rank=NULL로 남는다. rank=0(확인된 미노출)도 유효한 결과다.
            $remaining = $analysis->combos()->whereNull('rank')->count();
            $analysis->update([
                'checked_count' => $analysis->combos()->whereNotNull('rank')->count(),
                'exposed_count' => $analysis->combos()->whereBetween('rank', [1, (int) $analysis->threshold])->count(),
                'status' => $analysis->targetReached() ? 'target_reached' : ($remaining > 0 ? 'checking' : 'done'),
            ]);

            return response()->json(['data' => ['reset' => 0, 'remaining' => $remaining, 'status' => $analysis->status]]);
        });
    }

    /**
     * "새로 조합" — 상품정보로 토큰을 새로 뽑고, 그 분석의 조합(확인된 것 · 숨긴 것 · 미확인 모두)을 지우고 처음부터 다시 만든 뒤 수집 중으로 돌린다
     * (대표님 10-27 17:31 「새로 조합이면 다 지우고 다시 만들어야지」 · 17:51 「뭔 조건이 이렇게 많이 걸려 있는지」 — 상태 · 광고 끝남 · 목표 도달 같은 앞단 조건 없음).
     * 지우기 전 JSON 백업은 분석기가 남긴다.
     */
    public function regenerate(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $result = $this->analyzer->rebuild($analysis);
        if (($result['added'] ?? 0) > 0) {
            $this->resumeCollection($analysis);
        }
        \Illuminate\Support\Facades\Log::info('shop_keyword_combos_rebuilt', ['analysis' => $analysis->id, 'user' => $request->user()->id, 'added' => $result['added'] ?? 0, 'backup' => $result['backup'] ?? null, 'ip' => $request->ip()]);

        return response()->json($result);
    }

    /** 수집 중으로 돌려 확장 대기열(check-queue)이 바로 이어받게 한다 — 「이어서 확인」과 같다(화면 활동 시각 · 병렬 상태 · 탭 닫힘 표시). */
    private function resumeCollection(ShopKeywordAnalysis $analysis): void
    {
        \App\Services\ShopExposureBlockAlert::resetParallel((int) $analysis->id);
        Cache::forget('shop-exposure:tab-closed:'.$analysis->id);
        ShopKeywordAnalysis::whereKey($analysis->id)->update(['collection_seen_at' => now()]);
        $analysis->refresh();
    }

    public function storeShortLinks(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($request->user()->isAdmin() && $analysis->user_id === $request->user()->id, 403);
        $rules = ['keep_count' => 'sometimes|boolean'];
        if (!$request->boolean('keep_count')) $rules['group_count'] = 'required|integer|min:1|max:100';
        $data = $request->validate($rules);
        $count = $request->boolean('keep_count') ? null : (int) $data['group_count'];
        try {
            $links = app(ShopKeywordShortLinkService::class)->generate($analysis, $count);
        } catch (\DomainException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['group_count' => $e->getMessage()]);
        }
        return $this->shortActionResponse($request, $analysis, '새 URL '.$links->count().'개');
    }

    public function reassignShortLinks(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($request->user()->isAdmin() && $analysis->user_id === $request->user()->id, 403);
        try {
            $links = app(ShopKeywordShortLinkService::class)->reassign($analysis);
        } catch (\DomainException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['short_links' => $e->getMessage()]);
        }
        return $this->shortActionResponse($request, $analysis, '재배정 '.$links->count().'개');
    }

    private function shortActionResponse(Request $request, ShopKeywordAnalysis $analysis, string $message)
    {
        if ($request->expectsJson()) {
            $a = $analysis->fresh()->loadCount('shortLinks');
            return response()->json(['message' => $message, 'analysis_id' => $a->id,
                'short_links_count' => $a->short_links_count,
                'row_html' => view('admin.shop-keyword._row', compact('a'))->render()]);
        }
        return redirect()->route('admin.shop-keyword.show', $analysis)->with('status', $message);
    }

    public function short(string $token)
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $link = ShopKeywordShortLink::where('token', $token)->first();
            if (!$link) return response()->view('shop-keyword-expired', [], 410)
                ->header('Cache-Control', 'no-store');
            $keywords = array_values(array_filter(array_map('strval', (array) $link->keywords)));
            if ($keywords === []) return response()->view('shop-keyword-pending', [], 200)
                ->header('Cache-Control', 'no-store');

            $idx = $link->cursor % count($keywords);
            $keyword = $keywords[$idx];
            $references = array_values(array_filter(array_map('strval', (array) $link->reference_keywords)));
            $reference = $references !== []
                ? $references[random_int(0, count($references) - 1)]
                : (string) ($link->analysis()->value('core_keyword') ?: '');
            $grammar = $link->getConnection()->getQueryGrammar();

            $affected = ShopKeywordShortLink::whereKey($link->id)
                ->where('cursor', $link->cursor)
                ->update([
                    'cursor' => DB::raw($grammar->wrap('cursor').' + 1'),
                    'hit_count' => DB::raw($grammar->wrap('hit_count').' + 1'),
                    'last_served_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($affected === 1) {
                $url = 'https://m.search.naver.com/search.naver?'.http_build_query([
                    'sm' => 'mtp_sug.top',
                    'where' => 'm',
                    'query' => $keyword,
                    'ackey' => Str::lower(Str::random(8)),
                    'acq' => $reference,
                    'acr' => '9',
                    'qdt' => '0',
                ], '', '&', PHP_QUERY_RFC3986);

                return redirect()->away($url, 302)->withHeaders([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                ]);
            }

            usleep(random_int(10_000, 40_000));
        }

        abort(409, 'Short URL cursor conflict. Retry the request.');
    }

    public function show(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $items = $analysis->items()->get();
        $tokens = $items->where('kind', 'token')->groupBy('source');
        $combos = $items->where('kind', 'combo')->where('hidden', false)
            ->sortBy(fn ($i) => $this->rankSort($i->rank))->values();

        // Header and list use the same current visible rows, including previously stale summaries.
        $analysis->checked_count = $combos->whereNotNull('rank')->count();
        $analysis->exposed_count = $combos->whereBetween('rank', [1, (int) $analysis->threshold])->count();

        // 조합 패턴 — 확인된 조합(감춘 것 포함 = 보관 결과)을 유형×단어수로 집계해 노출률 표시
        $th = (int) $analysis->threshold;
        $patterns = $items->where('kind', 'combo')->whereNotNull('rank')
            ->groupBy(fn ($c) => ($c->combo_tag ?: 'etc').'|'.count(preg_split('/\s+/u', trim($c->keyword)) ?: []))
            ->map(function ($g, $key) use ($th) {
                [$tag, $len] = explode('|', $key);

                return ['tag' => $tag, 'len' => (int) $len, 'checked' => $g->count(),
                    'exposed' => $g->filter(fn ($c) => $c->rank >= 1 && $c->rank <= $th)->count(),
                    'ad' => $g->filter(fn ($c) => $c->ad_exposed)->count()];
            })
            ->sortByDesc(fn ($r) => ($r['checked'] ? $r['exposed'] / $r['checked'] : 0) * 1000 + $r['exposed'])
            ->values();

        // 확장 상품페이지 수집분(2026-07-22) — 대표이미지 + 관련 태그 표기
        $productInfo = $analysis->product_id
            ? ShopProductInfo::where('user_id', $analysis->user_id)
                ->where('channel_product_id', $analysis->product_id)->first()
            : null;

        return view('admin.shop-keyword.show', [
            'analysis' => $analysis->loadMissing('order'),
            'tokens' => $tokens,
            'combos' => $combos,
            'patterns' => $patterns,
            'shortLinks' => $analysis->shortLinks()->orderBy('group_no')->get(),
            'thumbnailUrl' => (string) ($productInfo?->thumbnail_url) ?: null,
            'sellerTags' => array_values(array_filter((array) ($productInfo?->seller_tags ?? []))),
        ]);
    }

    /** 추출 키워드/조합 개별 삭제 — 추출 키워드 삭제 시 그 단어를 포함한 조합도 함께 제거(조합 자동 변경). */
    public function deleteItem(Request $request, ShopKeywordAnalysis $analysis, ShopKeywordAnalysisItem $item)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        abort_unless($item->analysis_id === $analysis->id, 404);

        $removedCombos = 0;
        if ($item->kind === 'token') {
            // 그 단어가 든 조합(감춘 것 포함) 삭제 + 삭제어로 기록 → "새로 조합" 때 다시 안 만든다
            $removedCombos = $analysis->allCombos()
                ->where('keyword', 'like', '%'.$this->escapeLike($item->keyword).'%')->delete();
            $banned = (array) ($analysis->banned ?? []);
            $banned[] = $item->keyword;
            $analysis->banned = array_values(array_unique($banned));
            $analysis->save();
        }
        $item->delete();

        $th = (int) $analysis->threshold;
        $analysis->update([
            'token_count' => $analysis->tokens()->count(),
            'combo_count' => $analysis->combos()->count(),
            'checked_count' => $analysis->combos()->whereNotNull('rank')->count(),
            'exposed_count' => $analysis->combos()->whereBetween('rank', [1, $th])->count(),
        ]);

        return response()->json(['ok' => true, 'removed_combos' => $removedCombos]);
    }

    public function destroy(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $analysis->delete();

        return redirect()->route('admin.shop-keyword')->with('status', '분석을 삭제했습니다.');
    }

    private function escapeLike(string $s): string
    {
        return addcslashes($s, '\\%_');
    }

    /** 정렬용 — 노출(1~) 먼저, 그 다음 순위밖(0), 미확인(null) 순. */
    private function rankSort(?int $rank): int
    {
        return match (true) {
            $rank === null => 1_000_002,
            $rank <= 0 => 1_000_000,
            default => $rank,
        };
    }

    private function exposedKeywords(ShopKeywordAnalysis $analysis): array
    {
        $th = (int) $analysis->threshold;

        return $analysis->combos()
            ->whereBetween('rank', [1, $th])
            ->orderBy('checked_at')
            ->orderBy('id')
            ->pluck('keyword')
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function shortLinkKeywordGroups(array $keywords, int $groupCount): array
    {
        $groups = array_fill(0, $groupCount, []);
        foreach ($keywords as $idx => $keyword) {
            $groups[$idx % $groupCount][] = $keyword;
        }

        return $groups;
    }

    private function referenceKeywords(ShopKeywordAnalysis $analysis): array
    {
        $refs = $analysis->tokens()
            ->whereIn('source', self::REFERENCE_SOURCES)
            ->orderBy('id')
            ->pluck('keyword')
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($refs !== []) {
            return $refs;
        }

        return $analysis->tokens()
            ->orderBy('id')
            ->pluck('keyword')
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter()
            ->unique()
            ->values()
            ->all() ?: [$analysis->core_keyword];
    }

    private function newShortToken(): string
    {
        do {
            $token = Str::random(11);
        } while (ShopKeywordShortLink::where('token', $token)->exists());

        return $token;
    }

    private function secondaryDomains(): array
    {
        return array_values(array_filter(array_map(
            fn ($domain) => trim((string) $domain),
            (array) config('rankfree.secondary_domains', []),
        )));
    }
}
