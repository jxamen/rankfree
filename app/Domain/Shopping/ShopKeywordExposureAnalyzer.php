<?php

namespace App\Domain\Shopping;

use App\Domain\Keyword\KeywordHubCollector;
use App\Domain\Keyword\NaverAutocompleteService;
use App\Domain\Keyword\NaverKeywordService;
use App\Domain\Keyword\NaverSerpService;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordAnalysisItem;
use App\Models\User;
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
        $maxCombos = max(10, min(200, (int) ($opts['max_combos'] ?? $cfg['max_combos'] ?? 80)));
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

        $type = $analysis->product_id ? 'product' : 'mall';
        $target = ['type' => $type, 'product_id' => (string) $analysis->product_id,
            'mall_name' => (string) $analysis->mall_name, 'url' => (string) $analysis->product_url];

        $pending = $analysis->combos()->whereNull('rank')->orderBy('id')->limit($limit)->get();
        $t0 = microtime(true);
        $blocked = false;
        $newlyExposed = [];

        foreach ($pending as $item) {
            if (microtime(true) - $t0 > $budget) {
                break;
            }
            try {
                $res = $this->shop->checkRank($item->keyword, $target, ['max_pages' => $scanPages]);
            } catch (Throwable) {
                $item->rank = 0;
                $item->checked_at = now();
                $item->save();

                continue;
            }
            if (! empty($res['blocked']) && empty($res['found'])) {
                $blocked = true;   // 전 키 429 — 남은 건 건드리지 않고 중단
                break;
            }
            $item->rank = (int) ($res['rank'] ?? 0);
            $item->checked_at = now();
            $item->save();
            if ($item->rank >= 1 && $item->rank <= $threshold) {
                $newlyExposed[] = $item;
            }
        }

        // 새로 노출된 조합의 월 검색량(best-effort)
        if ($newlyExposed) {
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
        $status = $remaining > 0 ? ($blocked ? 'blocked' : 'checking') : 'done';
        $analysis->update(['checked_count' => $checked, 'exposed_count' => $exposed, 'status' => $status]);

        return ['remaining' => $remaining, 'checked' => $checked, 'exposed' => $exposed,
            'total' => (int) $analysis->combo_count, 'blocked' => $blocked, 'status' => $status];
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

        if ($opts['include_together'] ?? true) {
            try {
                $sec = $this->serp->sections($core);
                $tg = array_map(fn ($r) => (string) ($r['keyword'] ?? ''), (array) ($sec['related'] ?? []));
                $t['together'] = array_slice($this->clean($tg), 0, self::PER_SOURCE['together']);
            } catch (Throwable) {
            }
        }

        $parsed = $this->filterParser->parse($filterHtml);
        $t['brand'] = array_slice($this->cleanLoose($parsed['brands']), 0, self::PER_SOURCE['brand']);
        $t['keyword_rec'] = array_slice($this->clean($parsed['keyword_recs']), 0, self::PER_SOURCE['keyword_rec']);
        $t['attribute'] = array_slice($this->cleanLoose($parsed['attributes']), 0, self::PER_SOURCE['attribute']);

        // 수식어 — 추출 키워드에서 핵심어를 뗀 조각(속성이 빈약한 상품도 롱테일 조합을 만들 수 있게).
        // 예: "무선이어폰 블루투스" → "블루투스", "고함량비타민c" → "고함량"
        $t['modifier'] = array_slice(
            $this->deriveModifiers($core, [$t['autocomplete'], $t['related'], $t['together'], $t['keyword_rec']]),
            0, self::PER_SOURCE['modifier']
        );

        return $t;
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
                    $nw = $this->norm($kw);
                    if ($nw !== $normCore && $normCore !== '' && str_contains($nw, $normCore)) {
                        $rem = trim(str_replace($normCore, ' ', $nw));
                        if ($rem !== '' && mb_strlen($rem, 'UTF-8') >= 2) {
                            $mods[] = $rem;
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

        // 조합 재료 = 상품 속성(우선) + 추출 수식어. 속성이 빈약한 상품도 수식어로 롱테일을 만든다.
        $components = [];
        $seenComp = [];
        foreach (array_merge($tokens['attribute'], $tokens['modifier']) as $c) {
            $k = $this->norm($c);
            if ($k !== '' && $k !== $normCore && ! isset($seenComp[$k])) {
                $seenComp[$k] = true;
                $components[] = $c;
            }
        }
        $attrs = array_slice($components, 0, $attrPool);
        $brandOpts = array_merge([null], array_slice($tokens['brand'], 0, 5));
        $suffixes = $tokens['suffix'];
        $perTierCap = max(20, $max);
        $genCap = $max * 4;

        // 길이(단어수) => [정규화키 => 문구]
        $tiers = [];
        $count = fn () => array_sum(array_map('count', $tiers));
        $push = function (array $parts, int $minLen = 2) use (&$tiers, $normCore, $maxTokens, $perTierCap) {
            $parts = array_values(array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== ''));
            $len = count($parts);
            if ($len < $minLen || $len > $maxTokens) {
                return;
            }
            $cand = preg_replace('/\s+/u', ' ', implode(' ', $parts));
            $nc = $this->norm($cand);
            if ($nc === $normCore || ! str_contains($nc, $normCore) || ! KeywordHubCollector::acceptableKeyword($cand)) {
                return;
            }
            $tiers[$len] ??= [];
            if (! isset($tiers[$len][$nc]) && count($tiers[$len]) < $perTierCap) {
                $tiers[$len][$nc] = $cand;
            }
        };

        // A) 상품 특이 조합(최우선): [브랜드?] + 핵심 + 속성/수식어 부분집합(크기 0..maxTokens-1).
        //    각 tier 의 앞자리를 차지해 라운드로빈에서 살아남게 한다.
        $subsets = array_merge([[]], $this->attrSubsets($attrs, $maxTokens - 1, $genCap));
        foreach ($brandOpts as $b) {
            foreach ($subsets as $sub) {
                $push(array_merge($b !== null ? [$b] : [], [$core], $sub));
                if ($count() >= $genCap) {
                    break 2;
                }
            }
        }

        // B) 어미는 2단어(핵심+어미)로만 — 3단어 이상으로 곱하면 tier 가 저품질 어미조합으로 넘쳐 특이조합을 밀어낸다.
        foreach ($suffixes as $sf) {
            $push([$core, $sf]);
        }

        // C) 완결 검색어(키워드추천/자동완성/연관/함께많이찾는) — 1단어여도 완결된 검색어라 그대로 후보
        foreach (['keyword_rec', 'autocomplete', 'related', 'together'] as $src) {
            foreach ($tokens[$src] as $kw) {
                $push(preg_split('/\s+/u', trim($kw)) ?: [], 1);
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
            if ($kw === '' || ! KeywordHubCollector::acceptableKeyword($kw)) {
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
            if ($kw === '' || $len < 1 || $len > 40 || preg_match('/[^\p{Hangul}a-zA-Z0-9\s]/u', $kw)) {
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
