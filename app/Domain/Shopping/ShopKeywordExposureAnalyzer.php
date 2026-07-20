<?php

namespace App\Domain\Shopping;

use App\Domain\Keyword\KeywordHubCollector;
use App\Domain\Keyword\NaverAutocompleteService;
use App\Domain\Keyword\NaverKeywordService;
use App\Domain\Keyword\NaverSerpService;
use App\Models\ShopKeywordAnalysis;
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
 *  - checkBatch(): 미확인 조합을 배치로 순위체크(폴링). 100개도 타임아웃 없이, 429면 중단 후 재개.
 *
 * 조합은 상품 고유 속성·브랜드를 곱한 2~5단어 롱테일까지 생성 — 단어가 많을수록 경쟁이 얇아
 * 상위 5위 노출이 나온다. 순위는 openapi shop.json(sort=sim) 추정치.
 */
class ShopKeywordExposureAnalyzer
{
    /** 소스별 토큰 추출 상한(과다 조합·쿼터 폭주 방지). */
    private const PER_SOURCE = [
        'autocomplete' => 15,
        'related' => 20,
        'together' => 10,
        'brand' => 10,
        'keyword_rec' => 15,
        'attribute' => 20,
        'suffix' => 40,
        'modifier' => 24,
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
        $maxCombos = max(10, min(500, (int) ($opts['max_combos'] ?? $cfg['max_combos'] ?? 100)));
        $maxTokens = max(2, min(6, (int) ($cfg['max_tokens'] ?? 5)));

        $target = $this->shop->resolveTarget(trim($productInput));
        $tokens = $this->extractTokens($core, $filterHtml, $opts);
        $combos = $this->buildCombos($core, $tokens, $maxCombos, $maxTokens);

        return DB::transaction(function () use ($user, $core, $target, $productInput, $threshold, $tokens, $combos) {
            $tokenRows = $this->tokenRows($tokens);

            $analysis = ShopKeywordAnalysis::create([
                'user_id' => $user->id,
                'core_keyword' => $core,
                'product_url' => $target['url'] ?: (preg_match('#^https?://#i', trim($productInput)) ? trim($productInput) : null),
                'product_id' => $target['product_id'] ?: null,
                'mall_name' => $target['mall_name'] ?: null,
                'product_title' => null,
                'threshold' => $threshold,
                'token_count' => count($tokenRows),
                'combo_count' => count($combos),
                'checked_count' => 0,
                'exposed_count' => 0,
                'status' => count($combos) ? 'checking' : 'done',
            ]);

            $now = now();
            $rows = [];
            foreach ($tokenRows as $r) {
                $rows[] = ['analysis_id' => $analysis->id, 'kind' => 'token', 'source' => $r['source'],
                    'keyword' => $r['keyword'], 'rank' => null, 'monthly_total' => null, 'checked_at' => null,
                    'created_at' => $now, 'updated_at' => $now];
            }
            foreach ($combos as $c) {
                $rows[] = ['analysis_id' => $analysis->id, 'kind' => 'combo', 'source' => 'combo',
                    'keyword' => $c, 'rank' => null, 'monthly_total' => null, 'checked_at' => null,
                    'created_at' => $now, 'updated_at' => $now];
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
        $threshold = (int) $analysis->threshold;

        $httpTimeout = (int) config('rankfree.shopping.timeout', 15);

        // analysis 단위 락 — 동시 폴링(여러 탭)이 같은 미확인 조합을 중복 체크해 쿼터를 배로 태우는 것을 막는다.
        $lock = Cache::lock("shop-exposure:{$analysis->id}", (int) ceil($budget) + 20);
        if (! $lock->get()) {
            return $this->progress($analysis);   // 다른 폴링 진행 중 — 현재 상태만 반환
        }

        try {
            $type = $analysis->product_id ? 'product' : 'mall';
            $idKind = str_contains((string) $analysis->product_url, '/catalog/') ? 'nvmid' : 'channel';
            $target = ['type' => $type, 'product_id' => (string) $analysis->product_id,
                'mall_name' => (string) $analysis->mall_name, 'url' => (string) $analysis->product_url, 'id_kind' => $idKind];

            $pending = $analysis->combos()->whereNull('rank')->orderBy('id')->limit($limit)->get();
            $t0 = microtime(true);
            $stopped = false;
            $newlyExposed = [];

            foreach ($pending as $item) {
                $elapsed = microtime(true) - $t0;
                if ($elapsed > $budget) {
                    break;
                }
                try {
                    // 모바일 검색 가격비교 오가닉 노출 위치(광고 제외) + 광고 노출 여부
                    $res = $this->exposure->exposure($item->keyword, $target, max(2, min($httpTimeout, (int) ceil($budget - $elapsed))));
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
                if ($item->rank >= 1 && $item->rank <= $threshold) {
                    $newlyExposed[] = $item;
                }
            }

            // 노출 조합의 월 검색량(best-effort) — 예산이 남았을 때만(초과 시 다음 폴링에서 보충)
            if ($newlyExposed && (microtime(true) - $t0) < $budget) {
                try {
                    $vols = $this->keyword->volumes(array_map(fn ($i) => $i->keyword, $newlyExposed));
                    foreach ($newlyExposed as $i) {
                        if (isset($vols[$i->keyword]['monthly_total'])) {
                            $i->monthly_total = $vols[$i->keyword]['monthly_total'];
                            $i->save();
                        }
                    }
                } catch (Throwable) {
                }
            }

            $checked = (int) $analysis->combos()->whereNotNull('rank')->count();
            $exposed = (int) $analysis->combos()->whereBetween('rank', [1, $threshold])->count();
            $remaining = (int) $analysis->combos()->whereNull('rank')->count();
            $status = $remaining > 0 ? ($stopped ? 'blocked' : 'checking') : 'done';
            $analysis->update(['checked_count' => $checked, 'exposed_count' => $exposed, 'status' => $status]);

            return ['remaining' => $remaining, 'checked' => $checked, 'exposed' => $exposed,
                'total' => (int) $analysis->combo_count, 'blocked' => $stopped && $remaining > 0, 'status' => $status];
        } finally {
            $lock->release();
        }
    }

    /** 순위체크 없이 현재 진행상황만 반환(락 미획득 시). */
    private function progress(ShopKeywordAnalysis $analysis): array
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
     * 소스별 토큰 추출. 각 소스는 독립 best-effort.
     *
     * @return array<string, list<string>>  source => 키워드 목록
     */
    private function extractTokens(string $core, ?string $filterHtml, array $opts): array
    {
        $t = ['autocomplete' => [], 'related' => [], 'together' => [], 'brand' => [], 'keyword_rec' => [], 'attribute' => [], 'suffix' => [], 'modifier' => []];

        $suffixes = array_merge(
            (array) config('rankfree.shopping.exposure.suffixes', []),
            (array) ($opts['suffixes'] ?? []),
        );
        $t['suffix'] = array_slice($this->cleanLoose($suffixes), 0, self::PER_SOURCE['suffix']);

        try {
            $t['autocomplete'] = $this->clean($this->ac->suggest($core, self::PER_SOURCE['autocomplete']));
        } catch (Throwable) {
        }

        try {
            $a = $this->keyword->analyze($core);
            $rel = [];
            foreach ((array) ($a['related'] ?? []) as $row) {
                $kw = is_array($row) ? (string) ($row['keyword'] ?? $row['relKeyword'] ?? '') : (string) $row;
                if ($kw !== '') {
                    $rel[] = $kw;
                }
            }
            $t['related'] = array_slice($this->clean($rel), 0, self::PER_SOURCE['related']);
        } catch (Throwable) {
        }

        // 함께 많이 찾는(통합검색 SERP) — 캐시(24h) 있어 대개 빠름. 실패해도 best-effort.
        if ($opts['include_together'] ?? true) {
            try {
                $sec = $this->serp->sections($core);
                $tg = array_map(fn ($r) => (string) ($r['keyword'] ?? ''), (array) ($sec['related'] ?? []));
                $t['together'] = array_slice($this->clean($tg), 0, self::PER_SOURCE['together']);
            } catch (Throwable) {
            }
        }

        $parsed = $this->filterParser->parse($filterHtml);   // (선택) 붙여넣은 HTML — API/확장 경로
        $t['brand'] = $this->cleanLoose($parsed['brands']);
        $t['keyword_rec'] = array_slice($this->clean($parsed['keyword_recs']), 0, self::PER_SOURCE['keyword_rec']);
        $t['attribute'] = $this->cleanLoose($parsed['attributes']);

        // 자동: core 키워드 모바일 검색 가격비교 결과에서 브랜드(판매몰)·속성(상품명 빈출어) 추출.
        try {
            $sig = $this->exposure->keywordSignals($core);
            $brands = array_map(fn ($m) => $this->cleanBrand($m), $sig['brands']);
            $t['brand'] = array_slice($this->cleanLoose(array_merge($t['brand'], $brands)), 0, self::PER_SOURCE['brand']);
            $prodAttrs = $this->attrsFromProductNames($core, $t['brand'], $sig['product_names']);
            $t['attribute'] = array_slice($this->cleanLoose(array_merge($t['attribute'], $prodAttrs)), 0, self::PER_SOURCE['attribute']);
        } catch (Throwable) {
            $t['brand'] = array_slice($t['brand'], 0, self::PER_SOURCE['brand']);
            $t['attribute'] = array_slice($t['attribute'], 0, self::PER_SOURCE['attribute']);
        }

        // 수식어 — 추출 키워드에서 핵심어를 뗀 조각(속성이 빈약한 상품도 롱테일 조합을 만들 수 있게).
        // 예: "무선이어폰 블루투스" → "블루투스", "고함량비타민c" → "고함량"
        $t['modifier'] = array_slice(
            $this->deriveModifiers($core, [$t['autocomplete'], $t['related'], $t['together'], $t['keyword_rec']]),
            0, self::PER_SOURCE['modifier']
        );

        return $t;
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
     * @return list<string>
     */
    private function buildCombos(string $core, array $tokens, int $max, int $maxTokens): array
    {
        $normCore = $this->norm($core);
        $cfg = (array) config('rankfree.shopping.exposure');
        $attrPool = max(0, (int) ($cfg['attr_pool'] ?? 10));

        // 롱테일 스택 재료 = **실제 상품 속성만**(1000mg·고함량·리포좀 등). 수식어·어미는 스택하지 않는다
        // — 스택하면 "비타민c 과다복용 추천 효능 천연" 같은 쓰레기 조합이 나온다(2단어로만 붙임).
        $components = [];
        $seenComp = [];
        foreach ($tokens['attribute'] as $c) {
            $k = $this->norm($c);
            if ($k !== '' && $k !== $normCore && ! isset($seenComp[$k])) {
                $seenComp[$k] = true;
                $components[] = $c;
            }
        }
        $attrs = array_slice($components, 0, $attrPool);
        $brandOpts = array_merge([null], array_slice($tokens['brand'], 0, 5));
        // 2단어로만 붙일 것들: 어미 + 수식어(핵심 포함 완결어는 C 경로에서 그대로)
        $pairWords = array_values(array_unique(array_merge($tokens['suffix'], $tokens['modifier'])));
        $perTierCap = max(20, $max);
        $genCap = $max * 5;

        // 실단어수 => [정규화키 => 문구]
        $tiers = [];
        $seen = [];   // 전역 중복 방지(tier 를 넘나드는 동일 문구)
        $total = 0;
        $push = function (array $parts, int $minLen = 2) use (&$tiers, &$seen, &$total, $normCore, $maxTokens, $perTierCap, $genCap) {
            if ($total >= $genCap) {
                return;
            }
            // 다단어 컴포넌트(공백 포함 브랜드·속성)도 실제 단어 수로 세도록 문구를 단어로 재분해
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
            if ($nc === $normCore || isset($seen[$nc]) || ! $this->containsCore($words, $normCore) || ! KeywordHubCollector::acceptableKeyword($cand)) {
                return;
            }
            $tiers[$len] ??= [];
            if (count($tiers[$len]) >= $perTierCap) {
                return;
            }
            $seen[$nc] = true;
            $tiers[$len][$nc] = $cand;
            $total++;
        };

        // A) 상품 특이 조합: 각 부분집합마다 [무브랜드·브랜드] 변형을 인터리브 —
        //    브랜드 루프를 안쪽에 둬 '브랜드×핵심×속성' 롱테일이 cap 선착순에 밀리지 않게 한다.
        $subsets = array_merge([[]], $this->attrSubsets($attrs, $maxTokens - 1, $genCap));
        foreach ($subsets as $sub) {
            foreach ($brandOpts as $b) {
                $push(array_merge($b !== null ? [$b] : [], [$core], $sub));
            }
            if ($total >= $genCap) {
                break;
            }
        }

        // B) 어미·수식어는 2단어(핵심+단어)로만 — 스택하면 "핵심 과다복용 추천 효능" 같은 쓰레기가 나온다.
        foreach ($pairWords as $w) {
            $push([$core, $w]);
        }

        // C) 완결 검색어(키워드추천/자동완성/연관/함께많이찾는) — 1단어여도 완결된 검색어라 그대로 후보
        foreach (['keyword_rec', 'autocomplete', 'related', 'together'] as $src) {
            foreach ($tokens[$src] as $kw) {
                $push([$kw], 1);
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
        foreach (['brand', 'keyword_rec', 'attribute', 'modifier', 'suffix', 'autocomplete', 'related', 'together'] as $src) {
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
