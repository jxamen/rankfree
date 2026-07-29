<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Domain\Shopping\ShopKeywordShortLinkService;
use App\Http\Controllers\Controller;
use App\Models\ShopKeywordAnalysis;
use DomainException;
use Illuminate\Http\Request;

/**
 * 쇼핑 유입키워드 분석 외부 API v1 (scope: shop_keyword) — 2026-07-26.
 *
 * 분석 생성(키워드 추출·조합) → 순위 확인 → 노출 키워드로 Short URL 그룹 생성까지 외부에서 자동화한다.
 * 규칙은 화면과 동일한 곳을 쓴다: 추출·조합·확인 [ShopKeywordExposureAnalyzer], Short URL [ShopKeywordShortLinkService].
 *
 * 확장(브라우저) 의존(2026-07-29 개편):
 *  - 서버는 **순위를 자동으로 돌리지 않는다.** 요청자 계정의 확장이 /api/ext/shop-keyword/* 큐를 폴링해
 *    ① 상품정보 수집(product-queue) ② 순위 확인(check-queue → check-html) 까지 화면 없이 이어서 처리한다.
 *  - 그래서 API 생성분은 created_via='api' · check_method='search'(확장이 읽는 통합검색 기준)로 고정된다.
 *  - 확장이 꺼져 있으면 progress.remaining 이 줄지 않는다(분석은 유지되며 확장이 켜지면 이어서 진행).
 */
class ShopKeywordApiController extends Controller
{
    public function __construct(
        private ShopKeywordExposureAnalyzer $analyzer,
        private ShopKeywordShortLinkService $shortLinks,
    ) {}

    /** 분석 생성 + 순위 확인 자동 시작. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'core_keyword' => 'required|string|max:120',
            'product' => 'required|string|max:500',
            'threshold' => 'nullable|integer|in:4,5',   // 외부 API 는 상위 4·5위 판정만(2026-07-29)
            'check_method' => 'nullable|in:api,search',
            // 조합 재료 — 확장 수집분이 없을 때 외부에서 상품정보를 직접 전달(제목이 없으면 조합 품질이 떨어진다)
            'product_info' => 'nullable|array',
            'product_info.title' => 'nullable|string|max:300',
            'product_info.brand' => 'nullable|string|max:120',
            'product_info.mall' => 'nullable|string|max:150',
            'product_info.price' => 'nullable|integer|min:0|max:2000000000',
            'product_info.seller_tags' => 'nullable|array|max:60',
            'product_info.seller_tags.*' => 'nullable|string|max:80',
        ]);

        // 같은 회원이 같은 키워드 + 같은 상품을 다시 요청하면 새로 만들지 않고 기존 분석을 돌려준다(2026-07-29).
        // 반복 호출로 같은 분석이 계속 쌓이던 문제(실측: '양말' 5건) 방지. 대상 판별은 문자열 파싱뿐이라 비용이 없다.
        if ($existing = $this->findExisting($request->user()->id, trim($data['core_keyword']), trim($data['product']))) {
            return response()->json([
                'analysis' => $this->payload($existing) + ['product' => $this->productPayload($existing, $this->oneProductInfo($existing))],
                'reused' => true,   // 새로 만들지 않고 기존 분석을 돌려줬다
            ]);
        }

        $opts = [
            'threshold' => $data['threshold'] ?? null,
            // 외부 API 분석은 요청자의 확장이 순위를 확인한다(2026-07-29). 확장은 통합검색 HTML 만 읽을 수 있으므로
            // 확인 방식을 search 로 고정한다 — 기록된 순위 근거와 실제 확인 경로를 일치시킨다.
            'check_method' => 'search',
            'created_via' => 'api',
        ];
        if (! empty($data['product_info']['title'])) {
            $opts['product_info'] = [
                'title' => (string) $data['product_info']['title'],
                'brand' => (string) ($data['product_info']['brand'] ?? ''),
                'mall' => (string) ($data['product_info']['mall'] ?? ''),
                'price' => (int) ($data['product_info']['price'] ?? 0),
                'seller_tags' => array_values(array_filter(array_map(
                    fn ($t) => trim((string) $t), (array) ($data['product_info']['seller_tags'] ?? [])
                ))),
            ];
        }

        $analysis = $this->analyzer->prepare($request->user(), trim($data['core_keyword']), trim($data['product']), null, $opts);

        // 서버는 순위를 자동으로 돌리지 않는다(2026-07-29) — 조합이 생기면 status=checking 으로 두고,
        // 요청자 계정의 확장이 /api/ext/shop-keyword/check-queue 를 폴링해 확인을 이어받는다.
        if ((int) $analysis->combo_count === 0 && trim((string) $analysis->product_title) === '') {
            // 상품 제목을 아직 못 구해 조합이 0개 — '완료'가 아니라 **상품정보 수집 대기**다(2026-07-29).
            // 요청자의 확장이 /api/ext/shop-keyword/product-queue 를 폴링해 채우면 조합·순위확인이 이어진다.
            $analysis->update(['status' => 'pending']);
        }

        return response()->json([
            'analysis' => $this->payload($analysis) + ['product' => $this->productPayload($analysis, $this->oneProductInfo($analysis))],
        ], 201);
    }

    /** 내 분석 목록. */
    public function index(Request $request)
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $rows = ShopKeywordAnalysis::where('user_id', $request->user()->id)
            ->latest('id')->paginate($perPage);

        $infos = $this->productInfoMap($request->user()->id, collect($rows->items())->pluck('product_id')->all());

        return response()->json([
            'analyses' => collect($rows->items())
                ->map(fn ($a) => $this->payload($a) + ['product' => $this->productPayload($a, $infos[(string) $a->product_id] ?? null)])
                ->all(),
            'page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
        ]);
    }

    /** 분석 상세 — 진행 상태 · 노출 키워드 · 생성된 Short URL. */
    public function show(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return response()->json([
            'analysis' => $this->payload($analysis) + ['product' => $this->productPayload($analysis, $this->oneProductInfo($analysis))],
            'exposed_keywords' => $this->shortLinks->exposedKeywords($analysis),
            'short_links' => $this->linkPayload($analysis),
        ]);
    }

    /**
     * 같은 회원 · 같은 키워드 · 같은 상품의 기존 분석(가장 최근). 없으면 null.
     * 상품 식별은 URL 에서 뽑은 product_id 우선, 업체명 입력처럼 ID 가 없으면 입력값 그대로 비교한다.
     */
    private function findExisting(int $userId, string $coreKeyword, string $productInput): ?ShopKeywordAnalysis
    {
        $target = app(\App\Domain\Shopping\NaverShoppingRankService::class)->resolveTarget($productInput);
        $pid = (string) ($target['product_id'] ?? '');

        return ShopKeywordAnalysis::where('user_id', $userId)
            ->where('core_keyword', $coreKeyword)
            ->when($pid !== '',
                fn ($q) => $q->where('product_id', $pid),
                // ⚠️ orWhere 는 반드시 그룹으로 — 안 묶으면 user_id·core_keyword 조건을 빠져나간다
                fn ($q) => $q->where(fn ($w) => $w->where('product_url', $productInput)->orWhere('mall_name', $productInput)),
            )
            ->latest('id')->first();
    }

    /** 분석 1건의 상품정보 조회(생성·상세용). 목록은 productInfoMap 으로 일괄 조회한다. */
    private function oneProductInfo(ShopKeywordAnalysis $analysis): ?\App\Models\ShopProductInfo
    {
        return \App\Models\ShopProductInfo::where('user_id', $analysis->user_id)
            ->where('channel_product_id', (string) $analysis->product_id)->first();
    }

    /** 목록용 일괄 조회 — 페이지의 상품정보를 한 번에 읽어 N+1 을 막는다. 키 = channel_product_id */
    private function productInfoMap(int $userId, array $productIds)
    {
        $ids = array_values(array_filter(array_unique(array_map('strval', $productIds))));
        if ($ids === []) {
            return collect();
        }

        return \App\Models\ShopProductInfo::where('user_id', $userId)
            ->whereIn('channel_product_id', $ids)->get()->keyBy('channel_product_id');
    }

    /**
     * 수집·저장된 상품 정보 전체 — SEO 태그(해시태그)·카테고리·대표이미지는 분석 행이 아니라
     * 상품정보 저장소(ShopProductInfo)에 있으므로 여기서 합쳐 돌려준다. 수집 전에는 빈 값.
     */
    private function productPayload(ShopKeywordAnalysis $analysis, ?\App\Models\ShopProductInfo $pi = null): array
    {
        return [
            'title' => (string) ($pi->title ?? $analysis->product_title),
            'brand' => (string) ($pi->brand ?? $analysis->brand),
            'mall_name' => (string) ($pi->mall_name ?? $analysis->mall_name),
            'price' => (int) ($pi->price ?? $analysis->product_price),
            'category' => (string) ($pi->category ?? ''),
            'thumbnail_url' => (string) ($pi->thumbnail_url ?? ''),
            'seller_tags' => array_values((array) ($pi->seller_tags ?? [])),
            'collected_at' => optional($pi?->collected_at)->toIso8601String(),
        ];
    }

    /** Short URL 생성 — 노출 키워드를 group_count 개 그룹으로 나눈다(화면과 동일 규칙). */
    public function storeShortLinks(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $data = $request->validate(['group_count' => 'required|integer|min:1|max:100']);

        try {
            $links = $this->shortLinks->generate($analysis, (int) $data['group_count']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => 'group_count'], 422);
        }

        return response()->json(['short_links' => $this->linkPayload($analysis)], 201);
    }

    /** Short URL 목록만 조회 — 발주 시스템이 링크·배정 키워드를 그대로 가져다 쓴다. */
    public function shortLinks(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return response()->json(['short_links' => $this->linkPayload($analysis)]);
    }

    /**
     * Short URL 재배정 — 이미 배포한 URL 은 그대로 두고 키워드만 다시 나눈다.
     * 순위 확인이 더 진행돼 노출 키워드가 늘었을 때 사용(생성은 호출된 링크가 있으면 막힌다).
     */
    public function reassignShortLinks(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        try {
            $this->shortLinks->reassign($analysis);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'field' => 'short_links'], 422);
        }

        return response()->json(['short_links' => $this->linkPayload($analysis)]);
    }

    /** Short URL 표현 — 그룹 번호·주소·배정 키워드·호출 수. */
    private function linkPayload(ShopKeywordAnalysis $analysis): array
    {
        return $analysis->shortLinks()->orderBy('group_no')->get()
            ->map(fn ($l) => [
                'group_no' => (int) $l->group_no,
                'url' => $l->url(),
                'keywords' => (array) $l->keywords,
                'hit_count' => (int) $l->hit_count,
            ])->all();
    }

    /** 공통 분석 페이로드 — 진행 상태(progress)를 함께 준다. */
    private function payload(ShopKeywordAnalysis $analysis): array
    {
        $p = $this->analyzer->progress($analysis);

        return [
            'id' => (int) $analysis->id,
            'core_keyword' => (string) $analysis->core_keyword,
            // 수집·저장된 상품 정보 — 확장이 채운 값이 그대로 나간다(2026-07-29)
            'product_url' => (string) $analysis->product_url,
            'product_id' => (string) $analysis->product_id,
            'mall_name' => (string) $analysis->mall_name,
            'product_title' => (string) $analysis->product_title,
            'brand' => (string) $analysis->brand,
            'product_price' => (int) $analysis->product_price,
            'threshold' => (int) $analysis->threshold,
            'check_method' => (string) $analysis->check_method,
            'status' => (string) $analysis->status,
            'progress' => [
                'total' => (int) $p['total'],
                'checked' => (int) $p['checked'],
                'remaining' => (int) $p['remaining'],
                'exposed' => (int) $p['exposed'],
                'blocked' => (bool) $p['blocked'],
            ],
            'created_at' => optional($analysis->created_at)->toIso8601String(),
        ];
    }
}
