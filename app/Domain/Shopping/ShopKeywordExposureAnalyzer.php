<?php

namespace App\Domain\Shopping;

use App\Domain\Keyword\KeywordHubCollector;
use App\Domain\Keyword\NaverAutocompleteService;
use App\Domain\Keyword\NaverKeywordService;
use App\Domain\Keyword\NaverSerpService;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordAnalysisItem;
use App\Models\ShopProductInfo;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 쇼핑 노출 키워드 분석(25) — 핵심 키워드 + 상품 URL → 다중 소스 키워드 추출 →
 * 조합 생성 → 각 조합으로 쇼핑 검색해 내 상품이 상위 N위(기본 5)에 노출되는지 판정.
 *
 * 흐름:
 *  - prepare(): 추출·조합 생성 후 저장(순위 미확인 상태). 빠르게 끝나 리다이렉트.
 *  - applyHtml(): **확장(브라우저)이 가져온 m.search HTML** 로 조합 1건 순위 확정 — 기본 경로.
 *    서버 IP rate-limit 과 무관해 수백 조합도 끝까지 확인된다.
 *  - checkBatch(): 확장이 없을 때의 폴백 — 서버가 직접 fetch 해 배치 순위체크(폴링). 한도(429)면 중단 후 재개.
 *
 * 조합은 상품 고유 속성·브랜드를 곱한 2~5단어 롱테일까지 생성 — 단어가 많을수록 경쟁이 얇아
 * 상위 5위 노출이 나온다. 순위는 모바일 검색 가격비교(광고 제외) 문서상 노출 위치.
 */
class ShopKeywordExposureAnalyzer
{
    /** 소스별 토큰 추출 상한. */
    private const PER_SOURCE = [
        'title' => 24,          // 제목 단어(조합 핵심)
        'attribute' => 24,      // tail 전용(제목/브랜드 조합에 1개씩 — 단독 결합은 패턴 실측 0/298로 제거)
        'suffix' => 40,         // tail 전용(핵심+어미 2단어 단독은 1/20으로 제거)
        'seller_tag' => 40,
        'autocomplete' => 20,
        'searchad' => 30,
        'together' => 40,       // 함께 많이 찾는 전부
        'competitor_brand' => 20,
    ];

    public function __construct(
        private NaverShoppingRankService $shop,
        private NaverShopExposureService $exposure,
        private NaverAutocompleteService $ac,
        private NaverKeywordService $keyword,
        private NaverSerpService $serp,
        private ShopFilterHtmlParser $filterParser,
    ) {}

    /**
     * 추출 + 조합 생성 + 저장(순위 미확인). 순위체크는 checkBatch() 가 배치로 채운다.
     *
     * @param  array{threshold?:int, max_combos?:int, include_together?:bool, suffixes?:list<string>}  $opts
     */
    public function prepare(User $user, string $coreKeyword, string $productInput, ?string $filterHtml = null, array $opts = []): ShopKeywordAnalysis
    {
        $core = trim($coreKeyword);
        $cfg = (array) config('rankfree.shopping.exposure');
        $threshold = max(1, (int) ($opts['threshold'] ?? $cfg['top'] ?? 5));
        // 조합 수는 사용자가 고르지 않는다 — 만들 수 있는 조합을 전부 생성(hard_cap 은 폭발 방지 안전선).
        $hardCap = max(100, (int) ($cfg['hard_cap'] ?? 3000));
        $maxCombos = min($hardCap, max(10, (int) ($opts['max_combos'] ?? $hardCap)));
        $maxTokens = max(2, min(6, (int) ($cfg['max_tokens'] ?? 5)));

        $target = $this->shop->resolveTarget(trim($productInput));

        // 확장이 수집한 상품정보(있으면 조합 재료로 — 제목·브랜드·가격·SEO태그). 서버는 상품페이지 429라 확장 수집분 사용.
        if (empty($opts['product_info']) && ($target['product_id'] ?? '') !== '') {
            $pi = \App\Models\ShopProductInfo::where('user_id', $user->id)
                ->where('channel_product_id', $target['product_id'])->first();
            if ($pi) {
                $opts['product_info'] = [
                    'title' => (string) $pi->title, 'brand' => (string) ($pi->brand ?: $pi->mall_name),
                    'mall' => (string) $pi->mall_name,
                    'price' => (int) $pi->price, 'seller_tags' => (array) $pi->seller_tags,
                ];
            }
        }

        $ext = $this->extractTokens($core, $target, $opts);
        $tokens = $ext['tokens'];
        $me = $ext['me'];
        $combos = $this->buildCombos($core, $tokens, $me, $maxCombos, $maxTokens);
        // 순위 확인 방식(2026-07-22) — 기본 api(shop.json). ⚠️ 클로저 밖에서 확정(use 누락 시 조용히 기본값이 된다)
        $checkMethod = in_array($opts['check_method'] ?? '', ['api', 'search'], true) ? $opts['check_method'] : 'api';

        return DB::transaction(function () use ($user, $core, $target, $productInput, $threshold, $tokens, $me, $combos, $checkMethod) {
            $tokenRows = $this->tokenRows($tokens);

            $analysis = ShopKeywordAnalysis::create([
                'user_id' => $user->id,
                'core_keyword' => $core,
                'product_url' => $target['url'] ?: (preg_match('#^https?://#i', trim($productInput)) ? trim($productInput) : null),
                'product_id' => $target['product_id'] ?: null,
                'mall_name' => ($me['mall'] ?? '') !== '' ? $me['mall'] : ($target['mall_name'] ?: null),   // 실제 상점명(스토어)
                'brand' => $me['brand'] ?: null,                                 // 제조사·브랜드(콤보 재료)
                'product_title' => $me['title'] ?: null,
                'product_price' => $me['price'] ?: null,
                'threshold' => $threshold,
                'token_count' => count($tokenRows),
                'combo_count' => count($combos),
                'checked_count' => 0,
                'exposed_count' => 0,
                'status' => count($combos) ? 'checking' : 'done',
                'check_method' => $checkMethod,
            ]);

            $now = now();
            $rows = [];
            foreach ($tokenRows as $r) {
                $rows[] = ['analysis_id' => $analysis->id, 'kind' => 'token', 'source' => $r['source'],
                    'keyword' => $r['keyword'], 'combo_tag' => null, 'rank' => null, 'monthly_total' => null,
                    'checked_at' => null, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach ($combos as $c) {
                $rows[] = ['analysis_id' => $analysis->id, 'kind' => 'combo', 'source' => 'combo',
                    'keyword' => $c['keyword'], 'combo_tag' => $c['tag'], 'rank' => null, 'monthly_total' => null,
                    'checked_at' => null, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('shop_keyword_analysis_items')->insert($chunk);
            }

            return $analysis;
        });
    }

    /**
     * 미확인 조합을 배치로 순위체크. 시간예산·개수 상한 내에서만 처리하고 진행상황을 반환한다.
     * 429(전 키 소진)면 즉시 중단(남은 건 미확인 유지 → 나중에 재개).
     *
     * @return array{remaining:int, checked:int, exposed:int, total:int, blocked:bool, status:string}
     */
    public function checkBatch(ShopKeywordAnalysis $analysis, ?int $limit = null): array
    {
        $cfg = (array) config('rankfree.shopping.exposure');
        $limit = max(1, (int) ($limit ?? $cfg['batch_size'] ?? 15));
        $budget = (float) ($cfg['batch_sec'] ?? 12);
        $scanPages = max(1, (int) ($cfg['scan_pages'] ?? 1));
        $delayMs = max(0, (int) ($cfg['fetch_delay_ms'] ?? 250));
        $threshold = (int) $analysis->threshold;

        $httpTimeout = (int) config('rankfree.shopping.timeout', 15);

        // analysis 단위 락 — 동시 폴링(여러 탭)이 같은 미확인 조합을 중복 체크해 쿼터를 배로 태우는 것을 막는다.
        $lock = Cache::lock("shop-exposure:{$analysis->id}", (int) ceil($budget) + 20);
        if (! $lock->get()) {
            return $this->progress($analysis);   // 다른 폴링 진행 중 — 현재 상태만 반환
        }

        try {
            $target = $this->targetOf($analysis);
            // 방식(2026-07-22): api = shop.json 1콜/조합(빠름·차단 없음, 쇼핑 순위추적과 동일 기준·광고 판별 없음)
            //                  search = 통합검색 크롤링(실화면 오가닉·광고 판별, IP rate-limit 가능)
            $useApi = $analysis->check_method !== 'search';

            $pending = $analysis->combos()->whereNull('rank')->orderBy('id')->limit($limit)->get();
            $t0 = microtime(true);
            $stopped = false;
            $batchResults = [];   // 이번 배치 건별 결과 — 화면이 노출 테이블·배지를 리로드 없이 갱신(요약 숫자와 불일치 방지)

            foreach ($pending as $item) {
                $elapsed = microtime(true) - $t0;
                if ($elapsed > $budget) {
                    break;
                }
                try {
                    if ($useApi) {
                        // shop.json 상위 40 안 순위(1페이지 1콜 — threshold 최대 40 커버). 광고 판별 없음.
                        $api = $this->shop->checkRank($item->keyword, $target, [
                            'display' => 40, 'max_pages' => 1,
                            'timeout' => max(2, min($httpTimeout, (int) ceil($budget - $elapsed))),
                        ]);
                        // ⚠️ checkRank 는 일부 키가 429 여도 다음 키로 성공하면 blocked=true + found 를 함께 준다(실측)
                        //    — found 면 성공으로 확정하고, 못 찾았는데 blocked 일 때만 중단(미노출 오기록 방지).
                        $res = ! empty($api['found'])
                            ? ['rank' => (int) $api['rank'], 'ad' => false, 'blocked' => false, 'error' => null]
                            : ['rank' => 0, 'ad' => false, 'blocked' => ! empty($api['blocked']), 'error' => $api['error'] ?? null];
                    } else {
                        // 모바일 검색 가격비교 오가닉 노출 위치(광고 제외) + 광고 노출 여부
                        $res = $this->exposure->exposure($item->keyword, $target, max(2, min($httpTimeout, (int) ceil($budget - $elapsed))));
                    }
                } catch (Throwable) {
                    $stopped = true;   // 일시 오류 — rank 오기록 대신 중단(재시도 가능)
                    break;
                }
                // fetch 실패/차단 → rank 저장 안 함(미확인 유지, 재시도 가능)
                if (! empty($res['error']) || ! empty($res['blocked'])) {
                    $stopped = true;
                    break;
                }
                $item->rank = (int) ($res['rank'] ?? 0);
                $item->ad_exposed = ! empty($res['ad']);
                $item->checked_at = now();
                $item->save();
                $batchResults[] = ['id' => $item->id, 'keyword' => $item->keyword, 'rank' => (int) $item->rank,
                    'ad' => (bool) $item->ad_exposed, 'combo_tag' => $item->combo_tag];
                // search: m.search 연속 호출 IP rate-limit 완화 간격. api: 키 로테이션이 있어 짧게만.
                $gap = $useApi ? min(120, $delayMs) : $delayMs;
                if ($gap > 0) {
                    usleep($gap * 1000);
                }
            }

            $checked = (int) $analysis->combos()->whereNotNull('rank')->count();
            $exposed = (int) $analysis->combos()->whereBetween('rank', [1, $threshold])->count();
            $remaining = (int) $analysis->combos()->whereNull('rank')->count();
            // 사용자가 "중단"을 누른 뒤 진행 중이던 배치가 늦게 끝나도 paused 를 덮어쓰지 않는다
            $paused = $analysis->refresh()->status === 'paused';
            $status = $remaining > 0 ? ($stopped ? 'blocked' : ($paused ? 'paused' : 'checking')) : 'done';
            $analysis->update(['checked_count' => $checked, 'exposed_count' => $exposed, 'status' => $status]);

            return ['remaining' => $remaining, 'checked' => $checked, 'exposed' => $exposed,
                'total' => (int) $analysis->combo_count, 'blocked' => $stopped && $remaining > 0, 'status' => $status,
                'items' => $batchResults];
        } finally {
            $lock->release();
        }
    }

    /**
     * 확장(브라우저)이 가져온 m.search HTML 로 조합 1건 순위 확정 — 서버 IP 한도와 무관한 기본 경로.
     * 매칭 슬롯을 만나면 분석 헤더의 제목·업체명·가격도 백필한다.
     *
     * @return array{remaining:int, checked:int, exposed:int, total:int, blocked:bool, status:string, rank:int, ad:bool}
     */
    public function applyHtml(ShopKeywordAnalysis $analysis, ShopKeywordAnalysisItem $item, string $html): array
    {
        $threshold = (int) $analysis->threshold;

        $res = $this->exposure->rankFromHtml($html, $this->targetOf($analysis));
        $item->rank = (int) ($res['rank'] ?? 0);
        $item->ad_exposed = ! empty($res['ad']);
        $item->checked_at = now();
        $item->save();

        $this->backfillMe($analysis, $res['me'] ?? null);

        $checked = (int) $analysis->combos()->whereNotNull('rank')->count();
        $exposed = (int) $analysis->combos()->whereBetween('rank', [1, $threshold])->count();
        $remaining = (int) $analysis->combos()->whereNull('rank')->count();
        // 브라우저 경로는 한도가 없어 blocked 를 해제한다. 단 "중단" 직후 늦게 도착한 건이 paused 를 덮어쓰지 않게 유지
        $status = $remaining > 0 ? ($analysis->refresh()->status === 'paused' ? 'paused' : 'checking') : 'done';
        $analysis->update(['checked_count' => $checked, 'exposed_count' => $exposed, 'status' => $status]);

        return ['remaining' => $remaining, 'checked' => $checked, 'exposed' => $exposed,
            'total' => (int) $analysis->combo_count, 'blocked' => false, 'status' => $status,
            'rank' => (int) $item->rank, 'ad' => (bool) $item->ad_exposed,
            'combo_tag' => (string) $item->combo_tag];   // 패턴 카드 실시간 갱신용
    }

    /**
     * 확장이 수집한 코어 키워드 신호를 토큰으로 병합 — 함께많이찾는(related)·경쟁브랜드·속성.
     * 서버 fetch 실패로 빈약했던 참고/재료 키워드를 브라우저 수집분으로 보충한다.
     *
     * @param  list<string>  $related  "함께 많이 찾는"(qra) 키워드
     * @return array{added:int}
     */
    public function supplement(ShopKeywordAnalysis $analysis, string $mshopHtml, array $related): array
    {
        $core = (string) $analysis->core_keyword;
        $add = [];

        if ($related !== []) {
            $add['together'] = array_slice($this->clean(array_map('strval', $related)), 0, self::PER_SOURCE['together']);
        }
        if ($mshopHtml !== '') {
            $sig = $this->exposure->signalsFromHtml($mshopHtml, $this->targetOf($analysis));
            $this->backfillMe($analysis, $sig['me'] ?? null);
            $add['competitor_brand'] = array_slice(
                $this->cleanLoose(array_map(fn ($m) => $this->cleanBrand($m), $sig['competitor_malls'])),
                0, self::PER_SOURCE['competitor_brand']);
            // 속성 후보에서 경쟁 브랜드명 제외(extractTokens 와 동일 — 남의 브랜드가 조합 재료로 섞이는 것 방지)
            $brandEx = array_merge([(string) $analysis->brand, (string) $analysis->mall_name], $sig['competitor_malls'], $add['competitor_brand']);
            $add['attribute'] = array_slice(
                $this->cleanLoose($this->attrsFromProductNames($core, $brandEx, $sig['product_names'])),
                0, self::PER_SOURCE['attribute']);
        }

        return ['added' => $this->addTokens($analysis, $add)];
    }

    /**
     * 확장이 저장한 상품정보(ShopProductInfo)를 분석에 반영 — 제목·업체명·가격 백필 +
     * 제목 단어·제목 구절·SEO태그 토큰 추가(이후 "새로 조합"의 재료가 된다).
     *
     * @return array{found:bool, added:int, title:?string, mall:?string, price:?int}
     */
    public function refreshProductInfo(ShopKeywordAnalysis $analysis): array
    {
        $pi = ShopProductInfo::where('user_id', $analysis->user_id)
            ->where('channel_product_id', (string) $analysis->product_id)->first();
        if (! $pi) {
            return ['found' => false, 'added' => 0, 'title' => $analysis->product_title,
                'mall' => $analysis->mall_name, 'price' => $analysis->product_price];
        }

        $this->backfillMe($analysis, [
            'title' => (string) $pi->title,
            'mall' => (string) $pi->mall_name,
            'brand' => (string) ($pi->brand ?: $pi->mall_name),
            'price' => (int) $pi->price,
        ]);
        // 재수집은 명시적 새로고침 — 가격은 최신 수집값으로 강제 갱신(정가로 잘못 박힌 기존 분석 교정, 2026-07-24)
        if ((int) $pi->price > 0 && (int) $pi->price !== (int) $analysis->product_price) {
            $analysis->update(['product_price' => (int) $pi->price]);
        }
        $analysis->refresh();

        $core = (string) $analysis->core_keyword;
        $maxTokens = max(2, min(6, (int) config('rankfree.shopping.exposure.max_tokens', 5)));
        $added = $this->addTokens($analysis, [
            'title' => $this->titleWords($core, (string) ($analysis->brand ?: $analysis->mall_name), (string) $analysis->product_title),
            'title_phrase' => $this->titlePhrases((string) $analysis->product_title, $maxTokens),
            'seller_tag' => $this->cleanLoose((array) $pi->seller_tags),
        ]);

        return ['found' => true, 'added' => $added, 'title' => $analysis->product_title,
            'mall' => $analysis->mall_name, 'price' => $analysis->product_price];
    }

    /** 순위체크 대상 식별자(target) — 분석 레코드에서 복원. */
    private function targetOf(ShopKeywordAnalysis $analysis): array
    {
        return [
            'type' => $analysis->product_id ? 'product' : 'mall',
            'product_id' => (string) $analysis->product_id,
            'mall_name' => (string) $analysis->mall_name,
            'url' => (string) $analysis->product_url,
            'id_kind' => str_contains((string) $analysis->product_url, '/catalog/') ? 'nvmid' : 'channel',
        ];
    }

    /** 검색결과 매칭 슬롯의 제목·업체명·가격으로 분석 헤더의 빈 필드만 채운다. */
    private function backfillMe(ShopKeywordAnalysis $analysis, ?array $me): void
    {
        if (! $me) {
            return;
        }
        $dirty = [];
        if (! $analysis->product_title && trim((string) ($me['title'] ?? '')) !== '') {
            $dirty['product_title'] = trim((string) $me['title']);
        }
        if (! $analysis->mall_name && trim((string) ($me['mall'] ?? '')) !== '') {
            $dirty['mall_name'] = trim((string) $me['mall']);   // 실제 상점명 — 정제(cleanBrand)하지 않는다
        }
        if (! $analysis->brand && trim((string) ($me['brand'] ?? '')) !== '') {
            $dirty['brand'] = $this->cleanBrand((string) $me['brand']);
        }
        if (! $analysis->product_price && (int) ($me['price'] ?? 0) > 0) {
            $dirty['product_price'] = (int) $me['price'];
        }
        if ($dirty) {
            $analysis->update($dirty);
        }
    }

    /**
     * 토큰 추가 병합 — 같은 소스 안의 중복·삭제어(banned)만 제외하고 넣고 token_count 갱신.
     * 소스 간 중복은 허용한다: "함께 많이 찾는"이 검색광고 추천에도 있다고 그 섹션에서 빠지면
     * 실제 검색 화면과 개수가 어긋난다(사용자는 화면 그대로를 기대).
     *
     * @param  array<string, list<string>>  $bySource
     */
    private function addTokens(ShopKeywordAnalysis $analysis, array $bySource): int
    {
        $seen = [];   // "{source}|{norm}" — 같은 소스 안에서만 중복 제거
        foreach ($analysis->tokens()->get(['source', 'keyword']) as $t) {
            $seen[$t->source.'|'.$this->norm($t->keyword)] = true;
        }
        $banned = (array) ($analysis->banned ?? []);

        $now = now();
        $rows = [];
        foreach ($bySource as $source => $list) {
            foreach ((array) $list as $kw) {
                $kw = trim((string) $kw);
                $k = $this->norm($kw);
                if ($k === '' || isset($seen[$source.'|'.$k]) || $this->containsBanned($kw, $banned)) {
                    continue;
                }
                $seen[$source.'|'.$k] = true;
                $rows[] = ['analysis_id' => $analysis->id, 'kind' => 'token', 'source' => (string) $source,
                    'keyword' => $kw, 'rank' => null, 'monthly_total' => null, 'checked_at' => null,
                    'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('shop_keyword_analysis_items')->insert($chunk);
            }
            $analysis->update(['token_count' => $analysis->tokens()->count()]);
        }

        return count($rows);
    }

    /**
     * "새로 조합" — 미확인 조합은 버리고(정보 손실 없음), 확인 결과는 보관(노출 실패는 감춤)한 뒤
     * 현재 재료(백필된 제목·태그 포함)로 우선순위를 처음부터 다시 편성한다.
     * 상품정보가 늦게 도착한 분석(속성 위주 저효율 조합)이 제목 단어 중심으로 재편된다.
     *
     * @return array{added:int, remaining:int, total:int}
     */
    public function regenerate(ShopKeywordAnalysis $analysis, ?int $limit = null): array
    {
        $cfg = (array) config('rankfree.shopping.exposure');
        $hardCap = max(100, (int) ($cfg['hard_cap'] ?? 3000));
        $max = min($hardCap, max(10, (int) ($limit ?? $hardCap)));
        $maxTokens = max(2, min(6, (int) ($cfg['max_tokens'] ?? 5)));
        $threshold = (int) $analysis->threshold;

        // ① 미확인 조합은 삭제 — 재료가 좋아졌으면(제목 백필 등) 우선순위를 처음부터 다시 편성한다.
        //    확인된 결과(rank)는 어떤 조합 유형이 먹히는지 패턴 데이터라 지우지 않는다.
        $analysis->combos()->whereNull('rank')->delete();

        // ② 노출 안 된 확인 완료 조합은 감춤(결과 보관 + 재생성 중복 방지)
        $analysis->combos()->whereNotNull('rank')
            ->where(fn ($q) => $q->where('rank', '<=', 0)->orWhere('rank', '>', $threshold))
            ->update(['hidden' => true]);

        // ③ 저장된 토큰으로 재료 복원 — 속성(tail 재료)에서 경쟁 브랜드명 제외(오분류 정리: '고려은단' 등)
        $tokens = [];
        foreach ($analysis->tokens()->get() as $it) {
            $tokens[$it->source][] = $it->keyword;
        }
        $compNorms = array_map(fn ($k) => $this->norm($k), $tokens['competitor_brand'] ?? []);
        if ($compNorms) {
            $tokens['attribute'] = array_values(array_filter($tokens['attribute'] ?? [],
                fn ($k) => ! in_array($this->norm($k), $compNorms, true)));
        }
        $me = ['title' => (string) $analysis->product_title, 'brand' => (string) ($analysis->brand ?: $analysis->mall_name),
            'mall' => (string) $analysis->mall_name, 'price' => (int) $analysis->product_price];
        $core = (string) $analysis->core_keyword;

        // ④ 패턴 기반 자동 제외 — 충분히(≥12) 확인했는데 노출률 10% 미만인 유형(combo_tag)은
        //    더 만들지 않는다. 보관해 둔 확인 결과가 곧 학습 데이터다.
        $skipTags = [];
        $byTag = $analysis->allCombos()->whereNotNull('rank')->get(['combo_tag', 'rank'])
            ->groupBy(fn ($c) => (string) $c->combo_tag);
        foreach ($byTag as $tag => $g) {
            $exposedCnt = $g->filter(fn ($c) => $c->rank >= 1 && $c->rank <= $threshold)->count();
            if ($tag !== '' && $g->count() >= 12 && $exposedCnt / $g->count() < 0.1) {
                $skipTags[$tag] = true;
            }
        }

        // 남아 있는(확인된 것 + 감춘 것) 조합 + 삭제어 제외
        $exclude = [];
        foreach ($analysis->allCombos()->pluck('keyword') as $kw) {
            $exclude[$this->norm($kw)] = true;
        }
        $banned = (array) ($analysis->banned ?? []);

        $new = array_values(array_filter(
            $this->buildCombos($core, $tokens, $me, $max, $maxTokens, $exclude, $skipTags),
            fn ($c) => ! $this->containsBanned($c['keyword'], $banned)
        ));

        if ($new) {
            $now = now();
            $rows = array_map(fn ($c) => [
                'analysis_id' => $analysis->id, 'kind' => 'combo', 'source' => 'combo', 'keyword' => $c['keyword'],
                'combo_tag' => $c['tag'], 'rank' => null, 'hidden' => false, 'monthly_total' => null, 'checked_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ], $new);
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('shop_keyword_analysis_items')->insert($chunk);
            }
        }

        $analysis->update([
            'combo_count' => $analysis->combos()->count(),
            'checked_count' => $analysis->combos()->whereNotNull('rank')->count(),
            'exposed_count' => $analysis->combos()->whereBetween('rank', [1, $threshold])->count(),
            'status' => $analysis->combos()->whereNull('rank')->exists() ? 'checking' : 'done',
        ]);

        return ['added' => count($new), 'remaining' => (int) $analysis->combos()->whereNull('rank')->count(), 'total' => (int) $analysis->combo_count];
    }

    private function containsBanned(string $kw, array $banned): bool
    {
        $n = $this->norm($kw);
        foreach ($banned as $b) {
            $nb = $this->norm((string) $b);
            if ($nb !== '' && str_contains($n, $nb)) {
                return true;
            }
        }

        return false;
    }

    /** 순위체크 없이 현재 진행상황만 반환(락 미획득·항목 소실 시). */
    public function progress(ShopKeywordAnalysis $analysis): array
    {
        $threshold = (int) $analysis->threshold;

        return [
            'remaining' => (int) $analysis->combos()->whereNull('rank')->count(),
            'checked' => (int) $analysis->combos()->whereNotNull('rank')->count(),
            'exposed' => (int) $analysis->combos()->whereBetween('rank', [1, $threshold])->count(),
            'total' => (int) $analysis->combo_count,
            'blocked' => $analysis->status === 'blocked',
            'status' => (string) $analysis->status,
        ];
    }

    /**
     * 소스별 토큰 + 내 상품정보(me) 추출.
     *
     * 조합 재료: title(제목단어)·title_phrase(제목구절)·seller_tag(상세SEO태그) + me(브랜드·가격)
     *   + tail 재료 attribute(속성)·suffix(어미) — 제목/브랜드 조합에 1개씩만 덧붙인다(단독 결합은 패턴 실측으로 제거).
     * 참고(조합 X, 표시만): autocomplete·searchad(검색광고)·shopping_related(쇼핑연관)·keyword_rec(쇼핑추천)·
     *   together(함께많이찾는)·competitor_brand(경쟁브랜드).
     *
     * @return array{tokens: array<string, list<string>>, me: array{title:string, brand:string, price:int}}
     */
    private function extractTokens(string $core, array $target, array $opts): array
    {
        $t = [
            'title' => [], 'title_phrase' => [], 'attribute' => [], 'suffix' => [], 'seller_tag' => [],
            'autocomplete' => [], 'searchad' => [], 'shopping_related' => [], 'keyword_rec' => [], 'together' => [], 'competitor_brand' => [],
        ];
        $me = ['title' => '', 'brand' => '', 'mall' => '', 'price' => 0];

        // 어미(tail 재료 — 제목/브랜드 조합에 1개씩 덧붙일 때만 사용)
        $t['suffix'] = $this->cleanLoose((array) config('rankfree.shopping.exposure.suffixes', []));

        // 확장 수집 상품정보(있으면 우선) — 제목·브랜드·가격·SEO태그
        $pi = (array) ($opts['product_info'] ?? []);
        if (($pi['title'] ?? '') !== '') {
            $me = ['title' => (string) $pi['title'], 'brand' => (string) ($pi['brand'] ?? ''),
                'mall' => (string) ($pi['mall'] ?? ''), 'price' => (int) ($pi['price'] ?? 0)];
        }
        $t['seller_tag'] = $this->cleanLoose((array) ($pi['seller_tags'] ?? []));
        $t['shopping_related'] = $this->clean((array) ($pi['shopping_related'] ?? []));

        // 참고: 자동완성
        try {
            $t['autocomplete'] = $this->clean($this->ac->suggest($core, self::PER_SOURCE['autocomplete']));
        } catch (Throwable) {
        }
        // 참고: 검색광고 추천(keywordstool 연관)
        try {
            $a = $this->keyword->analyze($core);
            $rel = [];
            foreach ((array) ($a['related'] ?? []) as $row) {
                $kw = is_array($row) ? (string) ($row['keyword'] ?? $row['relKeyword'] ?? '') : (string) $row;
                if ($kw !== '') {
                    $rel[] = $kw;
                }
            }
            $t['searchad'] = array_slice($this->clean($rel), 0, self::PER_SOURCE['searchad']);
        } catch (Throwable) {
        }
        // 참고: 함께 많이 찾는(전부 수집 — 10개+)
        try {
            $sec = $this->serp->sections($core);
            $t['together'] = $this->clean(array_map(fn ($r) => (string) ($r['keyword'] ?? ''), (array) ($sec['related'] ?? [])));
        } catch (Throwable) {
        }

        // 모바일 검색 가격비교: 내 상품정보(fallback) + 경쟁브랜드(수집만) + 속성(상품명 빈출어)
        try {
            $sig = $this->exposure->keywordSignals($core, $target);
            if ($me['title'] === '' && ! empty($sig['me']['title'])) {
                // SERP 매칭 슬롯 — mall 은 실제 상점명, 브랜드 정보는 없어 상점명 정제값으로 대신한다
                $me = ['title' => (string) $sig['me']['title'], 'brand' => $this->cleanBrand((string) $sig['me']['mall']),
                    'mall' => (string) $sig['me']['mall'], 'price' => (int) $sig['me']['price']];
            }
            $t['competitor_brand'] = array_slice($this->cleanLoose(array_map(fn ($m) => $this->cleanBrand($m), $sig['competitor_malls'])), 0, self::PER_SOURCE['competitor_brand']);
            // ★ 속성 후보에서 경쟁 브랜드명 제외 — 브랜드명도 여러 상품명에 반복 등장해 '속성'으로 오분류된다
            //   (실측: '고려은단'이 속성 → 남의 브랜드가 내 조합에 섞임)
            $brandEx = array_merge([$me['brand']], $sig['competitor_malls'], $t['competitor_brand']);
            $t['attribute'] = array_slice($this->cleanLoose($this->attrsFromProductNames($core, $brandEx, $sig['product_names'])), 0, self::PER_SOURCE['attribute']);
        } catch (Throwable) {
        }

        // 제목 단어(조합 핵심 재료) — 내 제품 제목에서 핵심어·브랜드·수량 제외한 단어
        $t['title'] = $this->titleWords($core, $me['brand'], $me['title']);
        // 제목 연속 구절(n-gram) — "600정 X 1개" 처럼 제목에 붙어있는 그대로도 조합(내 상품이 그 구절로 노출)
        $t['title_phrase'] = $this->titlePhrases($me['title'], max(2, min(6, (int) config('rankfree.shopping.exposure.max_tokens', 5))));

        return ['tokens' => $t, 'me' => $me];
    }

    /** 제목의 연속 구절(2~$maxTokens 단어 n-gram) — 공백 구분, 붙어있는 그대로(예: "600정 X 1개"). @return list<string> */
    private function titlePhrases(string $title, int $maxTokens): array
    {
        // trim() 은 바이트 단위라 문자 목록에 멀티바이트(·)가 있으면 한글이 반쪽으로 잘려 깨진 UTF-8 이 생긴다 — 유니코드 안전 trim
        $toks = array_values(array_filter(
            array_map(fn ($t) => (string) preg_replace('/^[\s.,·()\[\]{}"\']+|[\s.,·()\[\]{}"\']+$/u', '', $t), preg_split('/\s+/u', trim($title)) ?: []),
            fn ($t) => $t !== ''
        ));
        $out = [];
        $seen = [];
        $n = count($toks);
        for ($i = 0; $i < $n; $i++) {
            for ($len = 2; $len <= $maxTokens && $i + $len <= $n; $len++) {
                $phrase = preg_replace('/\s+/u', ' ', implode(' ', array_slice($toks, $i, $len)));
                if ($phrase === null || $phrase === '') {
                    continue;   // 깨진 UTF-8 등 정규식 실패 — 이 구절만 건너뛴다
                }
                if ($this->hasNegative($phrase) || ! KeywordHubCollector::acceptableKeyword($phrase)) {
                    continue;
                }
                $k = $this->norm($phrase);
                if ($k === '' || isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $out[] = $phrase;
            }
        }

        return array_slice($out, 0, 40);
    }

    /**
     * 제목에서 조합용 단어 추출(핵심어·1자 제외). **브랜드도 포함**한다 —
     * 제목에 브랜드가 있으면(예: "유유제약 NCI200 …") 사용자는 그것도 제목 단어로 기대하고,
     * 브랜드 결합 조합의 핵심 재료다.
     * 분리는 **공백 기준**(판매자 제목 관례) — 양끝 괄호·구두점만 정리한다("(20개월분)" → "20개월분").
     *
     * @return list<string>
     */
    private function titleWords(string $core, string $brand, string $title): array
    {
        $normCore = $this->norm($core);
        $out = [];
        $seen = [];
        foreach (preg_split('/\s+/u', trim($title)) ?: [] as $w) {
            // 바이트 trim 에 멀티바이트(·)가 있으면 "캐비넷"류 한글 끝 바이트가 잘려 깨진 UTF-8 이 된다 — 유니코드 안전 trim
            $w = (string) preg_replace('/^[\s.,·\/()\[\]{}"\']+|[\s.,·\/()\[\]{}"\']+$/u', '', $w);
            $nw = $this->norm($w);
            if ($nw === '' || isset($seen[$nw]) || mb_strlen($w, 'UTF-8') < 2) {
                continue;
            }
            if (str_contains($nw, $normCore) || str_contains($normCore, $nw)) {
                continue;
            }
            // 수량·기간(600정·20개월분 등)도 내 제품 제목이면 그대로 조합 재료로 쓴다(제외하지 않음).
            if ($this->hasNegative($w) || preg_match('/[^\p{Hangul}a-zA-Z0-9]/u', $w)) {
                continue;   // 부정어·기호(×) 만 제외
            }
            $seen[$nw] = true;
            $out[] = $w;
        }

        return array_slice($out, 0, self::PER_SOURCE['title']);
    }

    /** 판매몰명 → 브랜드 정리(공식스토어/공식/스토어 등 접미 제거). */
    private function cleanBrand(string $mall): string
    {
        $b = trim(preg_replace('/\s*(공식\s*스토어|공식몰|공식|브랜드\s*스토어|스토어|공식판매처)$/u', '', trim($mall)));

        return $b !== '' ? $b : trim($mall);
    }

    /**
     * 상품명들에서 카테고리 속성 후보를 뽑는다 — 2개 이상 상품에 공통 등장한 토큰(핵심어·브랜드·순수 수량 제외).
     *
     * @param  list<string>  $brands
     * @param  list<string>  $names
     * @return list<string>
     */
    private function attrsFromProductNames(string $core, array $brands, array $names): array
    {
        $normCore = $this->norm($core);
        $brandNorms = array_map(fn ($b) => $this->norm($b), $brands);
        $freq = [];
        $display = [];
        foreach ($names as $name) {
            $seen = [];
            foreach (preg_split('/[\s,\/()\[\]·]+/u', trim($name)) ?: [] as $w) {
                $w = trim($w);
                $nw = $this->norm($w);
                if ($nw === '' || isset($seen[$nw]) || mb_strlen($w, 'UTF-8') < 2) {
                    continue;
                }
                $seen[$nw] = true;
                if (str_contains($nw, $normCore) || str_contains($normCore, $nw) || in_array($nw, $brandNorms, true)) {
                    continue;   // 핵심어·브랜드 제외
                }
                if (preg_match('/^\d+(개|포|정|박스|병|매|입|월|개월|스틱|캡슐|g|kg|mg|ml|l)$/u', $nw)) {
                    continue;   // 순수 수량 토큰 제외(30포·12개·30정)
                }
                $freq[$nw] = ($freq[$nw] ?? 0) + 1;
                $display[$nw] ??= $w;
            }
        }
        arsort($freq);
        $out = [];
        foreach ($freq as $nw => $c) {
            if ($c >= 2) {
                $out[] = $display[$nw];   // 2개 이상 상품 공통 = 카테고리 속성
            }
        }

        return $out;
    }

    /**
     * 추출 키워드에서 핵심어를 제거한 "수식어" 토큰을 뽑는다.
     *
     * @param  list<list<string>>  $keywordLists
     * @return list<string>
     */
    private function deriveModifiers(string $core, array $keywordLists): array
    {
        $normCore = $this->norm($core);
        $mods = [];
        foreach ($keywordLists as $list) {
            foreach ($list as $kw) {
                $words = preg_split('/\s+/u', trim((string) $kw)) ?: [];
                if (count($words) > 1) {
                    foreach ($words as $w) {
                        $nw = $this->norm($w);
                        if ($nw !== '' && $nw !== $normCore && ! str_contains($normCore, $nw) && ! str_contains($nw, $normCore)) {
                            $mods[] = $w;
                        }
                    }
                } else {
                    // 단일어(복합어)에서 핵심어 위치를 잘라 앞/뒤 조각을 각각 수식어로 —
                    // 무관한 앞뒤를 이어 붙이지 않는다(예: "풋사과즙"/core 사과 → "풋"·"즙" 각 1자라 탈락, 쓰레기 방지).
                    $nw = $this->norm($kw);
                    if ($nw !== $normCore && $normCore !== '' && str_contains($nw, $normCore)) {
                        foreach (explode('|', str_replace($normCore, '|', $nw)) as $piece) {
                            $piece = trim($piece);
                            if (mb_strlen($piece, 'UTF-8') >= 2) {
                                $mods[] = $piece;
                            }
                        }
                    }
                }
            }
        }

        return $this->cleanLoose($mods);
    }

    /**
     * 조합 생성 — 전부 핵심 키워드를 포함하는 2~$maxTokens 단어 조합.
     *
     * 상품 고유 속성·브랜드를 곱해 [브랜드?] + 핵심 + 속성부분집합(+어미?) 형태로 만든다.
     * 단어수가 많을수록 경쟁이 얇아 상위 5위 노출이 나오므로, 길이별 버킷을 만들고
     * **짧은 길이부터 라운드로빈**으로 뽑아 모든 길이가 골고루 체크되게 한다(짧을수록 검색량 큼).
     *
     * @param  array<string, list<string>>  $tokens
     * @param  array<string, true>  $skipTags  패턴상 노출이 안 나오는 유형(재생성 시 자동 제외)
     * @return list<array{keyword:string, tag:string}>
     */
    private function buildCombos(string $core, array $tokens, array $me, int $max, int $maxTokens, array $exclude = [], array $skipTags = []): array
    {
        $normCore = $this->norm($core);
        $brand = trim((string) ($me['brand'] ?? ''));
        // 브랜드가 비면 제목 첫 단어를 브랜드로 본다 — 판매자 제목 관례("유유제약 NCI200 …", 사용자 확인).
        // 브랜드가 있어야 브랜드 결합·브랜드+속성 조합이 만들어진다.
        if ($brand === '' && trim((string) ($me['title'] ?? '')) !== '') {
            $first = trim((string) ((preg_split('/\s+/u', trim((string) $me['title'])) ?: [])[0] ?? ''), " \t.,·/()[]{}\"'");
            $nf = $this->norm($first);
            if ($nf !== '' && $nf !== $normCore && ! str_contains($nf, $normCore) && ! str_contains($normCore, $nf)
                && mb_strlen($first, 'UTF-8') >= 2 && ! preg_match('/[^\p{Hangul}a-zA-Z0-9]/u', $first)) {
                $brand = $first;
            }
        }
        $price = (int) ($me['price'] ?? 0);
        $priceTok = $price > 0 ? (string) $price : '';
        $titleWords = array_values(array_slice($tokens['title'] ?? [], 0, 16));
        $sellerTags = $tokens['seller_tag'] ?? [];
        // tail 재료 — 속성·어미는 단독 티어가 아니라 제목/브랜드 조합에 1개씩만 덧붙인다
        $attrs = array_values(array_slice($tokens['attribute'] ?? [], 0, 8));
        $suffixes = array_values(array_slice($tokens['suffix'] ?? [], 0, 12));

        $perTierCap = max(60, $max);
        $genCap = max($max, 200) * 12;   // 넉넉히 생성 → "새로 조합"으로 여러 라운드 확장 가능

        $tiers = [];
        $seen = $exclude;   // 이미 만든/제외(재생성) 조합 norm 집합
        $total = 0;
        $push = function (array $parts, int $minLen, bool $needCore, string $tag) use (&$tiers, &$seen, &$total, $normCore, $maxTokens, $perTierCap, $genCap, $skipTags) {
            if ($total >= $genCap || isset($skipTags[$tag])) {
                return;   // 패턴상 노출이 안 나오는 유형은 더 만들지 않는다
            }
            $cand = preg_replace('/\s+/u', ' ', trim(implode(' ', array_map(fn ($p) => trim((string) $p), $parts))));
            if ($cand === '') {
                return;
            }
            $words = preg_split('/\s+/u', $cand) ?: [];
            $len = count($words);
            if ($len < $minLen || $len > $maxTokens) {
                return;
            }
            $nc = $this->norm($cand);
            if ($nc === $normCore || isset($seen[$nc]) || $this->hasNegative($cand) || ! KeywordHubCollector::acceptableKeyword($cand)) {
                return;
            }
            if ($needCore && ! $this->containsCore($words, $normCore)) {
                return;
            }
            $tiers[$len] ??= [];
            if (count($tiers[$len]) >= $perTierCap) {
                return;
            }
            $seen[$nc] = true;
            $tiers[$len][$nc] = ['keyword' => $cand, 'tag' => $tag];   // tag = 생성 유형(노출 패턴 집계용)
            $total++;
        };

        // 우선순위: 제목 단어 조합 → seller 태그 → +브랜드 → +브랜드+가격 → 브랜드+속성 → 어미.
        // (같은 tier 안 앞자리를 제목단어가 차지 → 라운드로빈에서 우선 생존)
        $titleSubsets = $this->attrSubsets($titleWords, $maxTokens - 1, $genCap);

        // A) 제목 단어 조합: core + 제목단어 부분집합 (최대한 많이)
        foreach ($titleSubsets as $sub) {
            $push(array_merge([$core], $sub), 2, true, 'title');
        }
        // B) 내 상품 SEO 태그 + 제목 연속 구절(핵심 미포함도 허용 — 내 상품이 그 태그/구절로 노출)
        foreach ($sellerTags as $tg) {
            $push([$tg], 1, false, 'tag');
        }
        foreach ($tokens['title_phrase'] ?? [] as $ph) {
            $push([$ph], 2, false, 'phrase');
        }
        // C) 브랜드 + core + 제목단어 부분집합
        if ($brand !== '') {
            foreach (array_merge([[]], $titleSubsets) as $sub) {
                $push(array_merge([$brand, $core], $sub), 2, true, 'brand');
            }
            // D) + 가격
            if ($priceTok !== '') {
                foreach (array_merge([[]], $titleSubsets) as $sub) {
                    $push(array_merge([$brand, $core], $sub, [$priceTok]), 3, true, 'brand_price');
                }
            }
        }
        // 단독형 속성·어미 티어(브랜드+핵심+속성, 핵심+어미 2단어)는 패턴 실측으로 **제거**(0/298·1/20).
        // E) 대신 성과 좋은 조합(제목·브랜드) 위에 속성/어미를 **1개 tail 로** 덧붙인다(사용자 요청 4종).
        //    풀을 작게(제목단어 8·부분집합 ≤2) 잡아 상위 티어를 밀어내지 않고, combo_tag 별 패턴
        //    자동 제외(≥12회·<10%)가 성과를 검증한다.
        $tailSubsets = $this->attrSubsets(array_slice($titleWords, 0, 8), min(2, max(1, $maxTokens - 2)), $genCap);
        foreach ($tailSubsets as $sub) {
            foreach ($attrs as $at) {
                $push(array_merge([$core], $sub, [$at]), 3, true, 'title_attr');      // 핵심+제목단어+속성
            }
            foreach ($suffixes as $sf) {
                $push(array_merge([$core], $sub, [$sf]), 3, true, 'title_suffix');    // 핵심+제목단어+어미
            }
        }
        if ($brand !== '') {
            foreach (array_merge([[]], $this->attrSubsets(array_slice($titleWords, 0, 8), 1, $genCap)) as $sub) {
                foreach ($attrs as $at) {
                    $push(array_merge([$brand, $core], $sub, [$at]), 3, true, 'brand_attr');      // 브랜드+제목+속성
                }
                foreach ($suffixes as $sf) {
                    $push(array_merge([$brand, $core], $sub, [$sf]), 3, true, 'brand_suffix');    // 브랜드+제목+어미
                }
            }
        }

        // 짧은 길이부터 라운드로빈 → 모든 길이 대표
        ksort($tiers);
        $lists = array_map('array_values', $tiers);
        $out = [];
        $progress = true;
        while (count($out) < $max && $progress) {
            $progress = false;
            foreach ($lists as $len => &$arr) {
                if ($arr) {
                    $out[] = array_shift($arr);
                    $progress = true;
                    if (count($out) >= $max) {
                        break;
                    }
                }
            }
            unset($arr);
        }

        return array_slice($out, 0, $max);
    }

    /**
     * 단어 배열에 핵심어가 존재하는가 — 단어 경계 넘는 오탐 방지.
     * (a) 한 단어(복합어) 안에 핵심어가 substring 으로 있거나, (b) 연속 단어들이 핵심어로 정확히 이어지면 통과.
     * "대구 미니선풍기"(공백 제거 substring "구미")처럼 단어 경계를 넘어 우연히 겹치는 건 제외한다.
     */
    private function containsCore(array $words, string $normCore): bool
    {
        if ($normCore === '') {
            return false;
        }
        $coreLen = mb_strlen($normCore, 'UTF-8');
        foreach ($words as $w) {
            if (str_contains($this->norm($w), $normCore)) {
                return true; // (a) 단어 내부 복합어
            }
        }
        $n = count($words);
        for ($i = 0; $i < $n; $i++) {   // (b) 연속 단어 = 핵심어(띄어쓴 핵심어)
            $acc = '';
            for ($j = $i; $j < $n; $j++) {
                $acc .= $this->norm($words[$j]);
                if ($acc === $normCore) {
                    return true;
                }
                if (mb_strlen($acc, 'UTF-8') >= $coreLen) {
                    break;
                }
            }
        }

        return false;
    }

    /**
     * 크기 1..$maxSize 의 부분집합(작은 크기 우선, $cap 개 상한).
     *
     * @param  list<string>  $items
     * @return list<list<string>>
     */
    private function attrSubsets(array $items, int $maxSize, int $cap): array
    {
        $n = count($items);
        $out = [];
        for ($size = 1; $size <= $maxSize && $size <= $n; $size++) {
            $idx = range(0, $size - 1);
            while (true) {
                if (count($out) >= $cap) {
                    return $out;
                }
                $out[] = array_map(fn ($j) => $items[$j], $idx);
                $k = $size - 1;
                while ($k >= 0 && $idx[$k] === $n - $size + $k) {
                    $k--;
                }
                if ($k < 0) {
                    break;
                }
                $idx[$k]++;
                for ($m = $k + 1; $m < $size; $m++) {
                    $idx[$m] = $idx[$m - 1] + 1;
                }
            }
        }

        return $out;
    }

    /**
     * 토큰 행(소스 우선순위로 전역 중복 제거) — 저장·표시용.
     *
     * @param  array<string, list<string>>  $tokens
     * @return list<array{source:string, keyword:string}>
     */
    private function tokenRows(array $tokens): array
    {
        $rows = [];
        $seen = [];
        foreach (['title', 'title_phrase', 'seller_tag', 'attribute', 'suffix', 'shopping_related', 'keyword_rec', 'searchad', 'autocomplete', 'together', 'competitor_brand'] as $src) {
            foreach ($tokens[$src] ?? [] as $kw) {
                $k = $this->norm($kw);
                if ($k === '' || isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $rows[] = ['source' => $src, 'keyword' => $kw];
            }
        }

        return $rows;
    }

    /** 형태 필터 + 중복 제거. @param list<string> $list @return list<string> */
    private function clean(array $list): array
    {
        $out = [];
        $seen = [];
        foreach ($list as $kw) {
            $kw = preg_replace('/\s+/u', ' ', trim((string) $kw));
            if ($kw === '' || $this->hasNegative($kw) || ! KeywordHubCollector::acceptableKeyword($kw)) {
                continue;
            }
            $k = $this->norm($kw);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $kw;
        }

        return $out;
    }

    /** 부정적 단어(과다복용·부작용 등)를 포함하면 조합 금지. config rankfree.shopping.exposure.negatives. */
    private function hasNegative(string $s): bool
    {
        $n = $this->norm($s);
        foreach ((array) config('rankfree.shopping.exposure.negatives', []) as $neg) {
            $neg = $this->norm((string) $neg);
            if ($neg !== '' && str_contains($n, $neg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 결합용 토큰(브랜드·속성) 완화 정리 — 단독 형태 필터 없이 한글·영문·숫자·공백만,
     * 1~40자·중복 제거. 최종 조합은 buildCombos 가 검증한다.
     *
     * @param  list<string>  $list
     * @return list<string>
     */
    private function cleanLoose(array $list): array
    {
        $out = [];
        $seen = [];
        foreach ($list as $kw) {
            $kw = preg_replace('/\s+/u', ' ', trim((string) $kw));
            $len = mb_strlen($kw, 'UTF-8');
            if ($kw === '' || $len < 1 || $len > 40 || $this->hasNegative($kw) || preg_match('/[^\p{Hangul}a-zA-Z0-9\s]/u', $kw)) {
                continue;
            }
            $k = $this->norm($kw);
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $kw;
        }

        return $out;
    }

    private function norm(string $s): string
    {
        return mb_strtolower(str_replace(' ', '', trim($s)), 'UTF-8');
    }
}
