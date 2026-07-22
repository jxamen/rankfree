<?php

namespace App\Http\Controllers;

use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordAnalysisItem;
use App\Models\ShopKeywordShortLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $data = $request->validate([
            'core_keyword' => 'required|string|max:120',
            'product' => 'required|string|max:500',
            'threshold' => 'nullable|integer|min:1|max:40',
            'check_method' => 'nullable|in:api,search',
        ]);

        // 조합 수는 선택하지 않는다 — 만들 수 있는 조합 전부 생성(hard_cap 안전선).
        $analysis = $this->analyzer->prepare(
            $request->user(),
            $data['core_keyword'],
            $data['product'],
            null,
            ['threshold' => $data['threshold'] ?? null, 'check_method' => $data['check_method'] ?? 'api'],
        );

        return redirect()->route('admin.shop-keyword.show', $analysis);
    }

    /** 배치 순위체크(폴링) — 확장 미설치 폴백. 서버가 직접 fetch 해 일부를 체크하고 진행상황 JSON 반환. */
    public function check(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return response()->json($this->analyzer->checkBatch($analysis));
    }

    /** 미확인 조합 배치(확장 화면단 체크용) — 브라우저가 이 목록을 받아 한 건씩 m.search 를 가져온다. */
    public function pending(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $items = $analysis->combos()->whereNull('rank')->orderBy('id')->limit(40)
            ->get(['id', 'keyword'])
            ->map(fn ($i) => ['id' => $i->id, 'keyword' => $i->keyword])->values();

        return response()->json(['data' => ['items' => $items] + $this->analyzer->progress($analysis)]);
    }

    /** 확장(브라우저)이 가져온 m.search HTML 로 조합 1건 순위 저장 — 서버 IP 한도와 무관한 기본 경로. */
    public function checkHtml(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'item_id' => 'required|integer',
            'html' => 'nullable|string|max:4000000',   // _INITIAL_STATE script 조각(수백 KB). 빈 값=가격비교 미노출
        ]);

        $item = $analysis->combos()->whereKey((int) $data['item_id'])->first();
        if (! $item) {
            // 사용자가 루프 중 조합을 삭제한 경우 — 진행상황만 반환하고 계속
            return response()->json($this->analyzer->progress($analysis) + ['skipped' => true]);
        }

        return response()->json($this->analyzer->applyHtml($analysis, $item, (string) ($data['html'] ?? '')));
    }

    /** 확장이 수집한 코어 키워드 신호(가격비교 HTML·함께많이찾는)를 참고/재료 토큰으로 병합. */
    public function supplement(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'mshop_html' => 'nullable|string|max:4000000',
            'related' => 'nullable|array|max:60',
            'related.*' => 'string|max:120',
        ]);

        // 체크 루프가 페이지를 자주 reload 하므로 같은 분석에 반복 병합하지 않는다(10분 가드)
        if (! \Illuminate\Support\Facades\Cache::add("shop-exposure:supplement:{$analysis->id}", 1, 600)) {
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

        $data = $request->validate([
            'info' => 'nullable|array',
            'info.channel_product_id' => 'nullable|string|max:40',
            'info.title' => 'nullable|string|max:300',
            'info.brand' => 'nullable|string|max:120',
            'info.mall_name' => 'nullable|string|max:150',
            'info.price' => 'nullable|integer|min:0|max:2000000000',
            'info.seller_tags' => 'nullable|array|max:60',
            'info.seller_tags.*' => 'nullable|string|max:80',
            'info.category' => 'nullable|string|max:191',
            'info.thumbnail_url' => 'nullable|string|max:500',   // 대표이미지 — 쇼핑 유입 발주용(2026-07-22)
        ]);

        $info = (array) ($data['info'] ?? []);
        // 이 분석의 상품과 일치할 때만 반영(다른 상품 payload 오염 방지)
        if ($info !== [] && (string) ($info['channel_product_id'] ?? '') === (string) $analysis->product_id
            && ($info['title'] ?? '') !== '') {
            \App\Models\ShopProductInfo::updateOrCreate(
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
                ],
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

        // 연결된 주문이 있으면 수집값으로 내부(숨김) 필드 자동 채움 — 외부 발주 전달용(2026-07-22)
        if ($analysis->marketing_order_id) {
            app(\App\Domain\Order\OrderFieldAutofill::class)->fillFromAnalysis($analysis->fresh());
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

        $paused = $request->boolean('paused', true);
        $hasRemaining = $analysis->combos()->whereNull('rank')->exists();

        if ($paused && $hasRemaining) {
            $analysis->update(['status' => 'paused']);
        } elseif (! $paused && $analysis->status === 'paused') {
            $analysis->update(['status' => $hasRemaining ? 'checking' : 'done']);
        }

        return response()->json(['data' => ['status' => $analysis->status]]);
    }

    /**
     * "노출 재확인" — 노출(1~threshold위) 판정 조합만 미확인으로 되돌려 다시 확인한다.
     * 광고 판별 로직 개선(슈퍼적립 등) 전에 오가닉으로 오판된 기록을 정정하는 용도.
     */
    public function recheckExposed(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $reset = $analysis->combos()->whereBetween('rank', [1, (int) $analysis->threshold])
            ->update(['rank' => null, 'ad_exposed' => false, 'checked_at' => null]);

        $analysis->update([
            'checked_count' => $analysis->combos()->whereNotNull('rank')->count(),
            'exposed_count' => 0,
            'status' => $reset > 0 ? 'checking' : $analysis->status,
        ]);

        return response()->json(['data' => ['reset' => $reset]]);
    }

    /** "새로 조합" — 노출 실패분 감추고 새 조합 생성(노출 키워드는 유지). */
    public function regenerate(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return response()->json($this->analyzer->regenerate($analysis));
    }

    public function storeShortLinks(Request $request, ShopKeywordAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'group_count' => 'required|integer|min:1|max:100',
        ]);

        $keywords = $this->exposedKeywords($analysis);
        $groupCount = (int) $data['group_count'];
        if ($keywords === []) {
            return back()->withErrors(['group_count' => '상위 노출 키워드가 아직 없습니다. 순위 확인을 먼저 완료하세요.']);
        }
        if ($groupCount > count($keywords)) {
            return back()->withErrors(['group_count' => 'Short URL 개수는 상위 노출 키워드 수보다 많을 수 없습니다.']);
        }
        if ($analysis->shortLinks()->where('hit_count', '>', 0)->exists()) {
            return back()->withErrors(['short_links' => '이미 호출된 Short URL이 있어 다시 생성할 수 없습니다.']);
        }

        $references = $this->referenceKeywords($analysis);
        $domains = $this->secondaryDomains();
        $groups = $this->shortLinkKeywordGroups($keywords, $groupCount);

        DB::transaction(function () use ($analysis, $references, $groupCount, $domains, $groups): void {
            $analysis->shortLinks()->delete();

            for ($groupNo = 1; $groupNo <= $groupCount; $groupNo++) {
                ShopKeywordShortLink::create([
                    'analysis_id' => $analysis->id,
                    'token' => $this->newShortToken(),
                    'domain' => $domains !== [] ? $domains[($groupNo - 1) % count($domains)] : null,
                    'group_no' => $groupNo,
                    'group_count' => $groupCount,
                    'keywords' => $groups[$groupNo - 1],
                    'reference_keywords' => $references,
                ]);
            }
        });

        return redirect()->route('admin.shop-keyword.show', $analysis)->with('status', "Short URL {$groupCount}개를 생성했습니다.");
    }

    public function reassignShortLinks(Request $request, ShopKeywordAnalysis $analysis): RedirectResponse
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        $links = $analysis->shortLinks()->orderBy('group_no')->orderBy('id')->get();
        if ($links->isEmpty()) {
            return back()->withErrors(['short_links' => '재배정할 Short URL이 없습니다. 먼저 Short URL을 생성하세요.']);
        }

        $keywords = $this->exposedKeywords($analysis);
        $groupCount = $links->count();
        if ($keywords === []) {
            return back()->withErrors(['short_links' => '상위 노출 키워드가 아직 없습니다. 순위 확인을 먼저 완료하세요.']);
        }
        if ($groupCount > count($keywords)) {
            return back()->withErrors(['short_links' => 'Short URL 수가 상위 노출 키워드 수보다 많아 재배정할 수 없습니다.']);
        }

        $references = $this->referenceKeywords($analysis);
        $groups = $this->shortLinkKeywordGroups($keywords, $groupCount);

        DB::transaction(function () use ($links, $references, $groupCount, $groups): void {
            foreach ($links->values() as $idx => $link) {
                $link->update([
                    'group_no' => $idx + 1,
                    'group_count' => $groupCount,
                    'keywords' => $groups[$idx],
                    'reference_keywords' => $references,
                    'cursor' => 0,
                ]);
            }
        });

        return redirect()->route('admin.shop-keyword.show', $analysis)->with('status', "Short URL {$groupCount}개에 키워드를 재배정했습니다.");
    }

    public function short(string $token): RedirectResponse
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $link = ShopKeywordShortLink::where('token', $token)->firstOrFail();
            $keywords = array_values(array_filter(array_map('strval', (array) $link->keywords)));
            abort_unless($keywords !== [], 404);

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
            ? \App\Models\ShopProductInfo::where('user_id', $analysis->user_id)
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
