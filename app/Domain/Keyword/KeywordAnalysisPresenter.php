<?php

namespace App\Domain\Keyword;

/**
 * 키워드 분석 대시보드 뷰모델 + 차트 빌더 — 마케팅 키워드 분석 페이지(console.keyword).
 *
 * 입력: NaverKeywordService::analyze()(검색량·경쟁강도·연관키워드) + SearchAdWebClient::keywordDetail()(성별·연령·월별).
 * 순수 계산 — 파생 지표(등급·상업성·환산예상·월별비율)와 인라인 SVG 차트를 생성한다.
 * 색은 전부 디자인 토큰(var(--color-*)) — 하드코딩 hex 금지.
 */
class KeywordAnalysisPresenter
{
    /** 검색광고 userStat 연령 밴드(고정 순서). research: ageGroup[14]. */
    private const AGE_ORDER = ['0-12', '13-19', '20-24', '25-29', '30-39', '40-49', '50-'];

    private const AGE_LABEL = [
        '0-12' => '~12세', '13-19' => '10대', '20-24' => '20대 초', '25-29' => '20대 후',
        '30-39' => '30대', '40-49' => '40대', '50-' => '50대+',
    ];

    /**
     * 키워드 페이지 전체 뷰모델 = 검색량/등급/상업성/연관 + 공유 상세모델(detailModel).
     *
     * @return array<string,mixed>
     */
    public static function build(string $keyword, ?array $base, ?array $detail, ?int $shopTotal = null): array
    {
        $pc = (int) ($base['monthly_pc'] ?? 0);
        $mo = (int) ($base['monthly_mobile'] ?? 0);
        $total = (int) ($base['monthly_total'] ?? ($pc + $mo));
        $comp = isset($base['comp_idx']) ? (string) $base['comp_idx'] : null;

        return [
            'keyword' => $keyword,
            'has_data' => $base !== null || $detail !== null,
            'has_volume' => $base !== null,
            'pc' => $pc,
            'mobile' => $mo,
            'total' => $total,
            'mobile_pct' => $total > 0 ? round($mo / $total * 100, 1) : 0.0,
            'comp_idx' => $comp,
            'grade' => self::grade($total),
            'commercial' => self::commercial($comp, $shopTotal),
            'forecast' => self::forecast($total),
            'related' => self::related($base, $keyword),   // 연관 키워드 — 입력어 토큰 포함분만(키워드 페이지 전용)
        ] + self::detailModel($detail);
    }

    /**
     * 공유 상세 모델 — 성별·연령·성별×연령·트렌드·월별. **키워드/시장 분석 양쪽에서 재사용.**
     * `partials.keyword-detail` 뷰가 이 모델을 렌더한다. 검색량·연관키워드는 포함하지 않는다.
     *
     * @return array{has_detail:bool,has_demo:bool,trend:list<array>,month_ratio:list<array>,gender:array,age:list<array>,pyramid:list<array>}
     */
    public static function detailModel(?array $detail): array
    {
        $demo = self::demographics($detail);
        $trend = self::trend($detail);
        $monthRatio = self::monthRatio($detail);

        return [
            'has_detail' => $detail !== null && (count($trend) > 0 || $demo['has']),
            'has_demo' => $demo['has'],
            'trend' => $trend,
            'month_ratio' => $monthRatio,
            'gender' => $demo['gender'],
            'age' => $demo['age'],
            'pyramid' => $demo['pyramid'],
            'season' => self::season($monthRatio),
            'insights' => self::insights($demo, $monthRatio),
            'issue' => self::issueScore($trend),
            'device' => self::deviceStats($detail),
        ];
    }

    /**
     * 시즌성(계절성) 구조 모델 — 월별 검색 비율의 변동계수(CV)로 시즌 강도를 판정하고
     * 성수기·비수기·**준비 시작 월**(성수기 − 2개월)을 계산한다.
     * 시장 분석 페이지의 "시즌 키워드 → 미리 순위작업" 리드 유도 콜아웃이 이 값을 사용.
     *
     * @param  list<array{m:int,pct:float}>  $monthRatio
     * @return array{has:bool,is_seasonal:bool,cv:float,level:string,strength_label:string,strength_color:string,strength_word:string,peak_months:list<int>,low_months:list<int>,prep_months:list<int>,lead_months:int}|null
     */
    public static function season(array $monthRatio): ?array
    {
        $hasSeason = count(array_filter($monthRatio, fn ($m) => (float) $m['pct'] > 0)) > 0;
        if (! $hasSeason) {
            return null;
        }

        $pcts = array_column($monthRatio, 'pct');
        $avg = array_sum($pcts) / max(1, count($pcts));

        // 시즌을 얼마나 타는지 — 월별 편차(변동계수 CV)로 판정
        $var = 0.0;
        foreach ($pcts as $p) {
            $var += ($p - $avg) ** 2;
        }
        $cv = $avg > 0 ? sqrt($var / max(1, count($pcts))) / $avg : 0.0;

        [$level, $label, $color, $word] = match (true) {
            $cv >= 0.35 => ['strong', '뚜렷함', 'var(--color-error)', '시즌을 많이 타는'],
            $cv >= 0.18 => ['moderate', '보통', 'var(--color-warning)', '어느 정도 시즌을 타는'],
            default => ['flat', '연중 꾸준', 'var(--color-success)', '연중 꾸준한'],
        };

        // 성수기 = 평균 대비 115%↑ 상위 3개월(오름차순), 없으면 최대월 1개
        $sorted = $monthRatio;
        usort($sorted, fn ($a, $b) => $b['pct'] <=> $a['pct']);
        $peak = array_slice(array_values(array_filter($sorted, fn ($m) => $m['pct'] >= $avg * 1.15)) ?: [$sorted[0]], 0, 3);
        usort($peak, fn ($a, $b) => $a['m'] <=> $b['m']);
        $peakMonths = array_map(fn ($m) => (int) $m['m'], $peak);

        // 비수기 = 하위 2개월(오름차순)
        $low = array_slice(array_reverse($sorted), 0, 2);
        usort($low, fn ($a, $b) => $a['m'] <=> $b['m']);
        $lowMonths = array_map(fn ($m) => (int) $m['m'], $low);

        // 준비 시작 월 = 각 성수기 − lead(2)개월(연말↔연초 wrap). dedup·오름차순.
        $lead = 2;
        $prep = array_values(array_unique(array_map(fn ($m) => (($m - $lead - 1 + 12) % 12) + 1, $peakMonths)));
        sort($prep);

        return [
            'has' => true,
            'is_seasonal' => $cv >= 0.18,   // 보통 이상만 "시즌 타는" 취급
            'cv' => round($cv, 2),
            'level' => $level,
            'strength_label' => $label,
            'strength_color' => $color,
            'strength_word' => $word,
            'peak_months' => $peakMonths,
            'low_months' => $lowMonths,
            'prep_months' => $prep,
            'lead_months' => $lead,
        ];
    }

    /**
     * 디바이스(PC·모바일) 통계 — 전체 / 성별별 / 연령별. userStat 14버킷의 pc·mobile 분리값 집계.
     *
     * @return array{has:bool,total:?array,by_gender:list<array>,by_age:list<array>}
     */
    public static function deviceStats(?array $detail): array
    {
        $buckets = (array) ($detail['buckets'] ?? []);
        $split = function (int $p, int $m): array {
            $t = max(1, $p + $m);

            return ['pc' => $p, 'mobile' => $m, 'pc_pct' => round($p / $t * 100, 1), 'mobile_pct' => round($m / $t * 100, 1)];
        };
        if (! $buckets) {
            return ['has' => false, 'total' => null, 'by_gender' => [], 'by_age' => []];
        }

        $gPc = ['f' => 0, 'm' => 0];
        $gMo = ['f' => 0, 'm' => 0];
        $aPc = [];
        $aMo = [];
        $tPc = 0;
        $tMo = 0;
        foreach ($buckets as $b) {
            if (! is_array($b)) {
                continue;
            }
            $g = (string) ($b['gender'] ?? '');
            $a = (string) ($b['age'] ?? '');
            $p = (int) ($b['pc'] ?? 0);
            $m = (int) ($b['mobile'] ?? 0);
            $tPc += $p;
            $tMo += $m;
            if (isset($gPc[$g])) {
                $gPc[$g] += $p;
                $gMo[$g] += $m;
            }
            $aPc[$a] = ($aPc[$a] ?? 0) + $p;
            $aMo[$a] = ($aMo[$a] ?? 0) + $m;
        }

        $byGender = [];
        foreach ([['여성', 'f'], ['남성', 'm']] as [$lab, $k]) {
            if ($gPc[$k] + $gMo[$k] > 0) {
                $byGender[] = ['label' => $lab] + $split($gPc[$k], $gMo[$k]);
            }
        }

        $codes = array_keys($aPc);
        usort($codes, fn ($x, $y) => self::ageRank($x) <=> self::ageRank($y));
        $byAge = [];
        foreach ($codes as $c) {
            if ($aPc[$c] + $aMo[$c] > 0) {
                $byAge[] = ['label' => self::ageLabel($c)] + $split($aPc[$c], $aMo[$c]);
            }
        }

        return ['has' => ($tPc + $tMo) > 0, 'total' => $split($tPc, $tMo), 'by_gender' => $byGender, 'by_age' => $byAge];
    }

    /**
     * 이슈성(시의성) — 12개월 트렌드의 단발 급등을 감지한 자체 추정치.
     * pct = (최고월 − 중앙값) ÷ 연간 합 × 100. 꾸준한 키워드는 0에 수렴, 이벤트성은 커진다.
     *
     * @param  list<array{total:int}>  $trend
     * @return array{pct:float,label:string,color:string}|null
     */
    public static function issueScore(array $trend): ?array
    {
        $vals = array_values(array_filter(array_map(fn ($t) => (int) ($t['total'] ?? 0), $trend), fn ($v) => $v > 0));
        if (count($vals) < 4) {
            return null;
        }
        sort($vals);
        $n = count($vals);
        $median = $n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
        $pct = round(max(0, max($vals) - $median) / max(1, array_sum($vals)) * 100, 1);
        $lv = match (true) {
            $pct >= 30 => ['매우 높음', 'var(--color-error)'],
            $pct >= 18 => ['높음', 'var(--color-warning)'],
            $pct >= 9 => ['보통', 'var(--color-accent)'],
            $pct >= 4 => ['낮음', 'var(--color-success)'],
            default => ['매우 낮음', 'var(--color-muted)'],
        };

        return ['pct' => $pct, 'label' => $lv[0], 'color' => $lv[1]];
    }

    /**
     * 데이터 기반 자동 인사이트 — 성수기/비수기(월별 계절성), 주 타겟 성별·연령, 핵심 세그먼트.
     *
     * @return array{cards: array<int,array{label:string,value:string,color:string}>, summary: string}|null
     */
    public static function insights(array $demo, array $monthRatio): ?array
    {
        $hasSeason = count(array_filter($monthRatio, fn ($m) => (float) $m['pct'] > 0)) > 0;
        if (! $demo['has'] && ! $hasSeason) {
            return null;
        }

        $cards = [];
        $parts = [];

        // 시즌성 강도 + 성수기 / 비수기 (월별 계절성) — season() 계산 재사용(단일 소스)
        $season = self::season($monthRatio);
        if ($season) {
            $peakStr = implode('·', array_map(fn ($m) => $m.'월', $season['peak_months']));
            $lowStr = implode('·', array_map(fn ($m) => $m.'월', $season['low_months']));

            $cards[] = ['group' => 'season', 'label' => '시즌성', 'value' => $season['strength_label'], 'color' => $season['strength_color']];
            $cards[] = ['group' => 'season', 'label' => '성수기', 'value' => $peakStr, 'color' => 'var(--color-badge-orange)'];
            $cards[] = ['group' => 'season', 'label' => '비수기', 'value' => $lowStr, 'color' => 'var(--color-accent)'];
            $parts[] = "{$season['strength_word']} 키워드로, 검색은 {$peakStr}에 몰리고 {$lowStr}에 가장 적습니다";
        }

        // 주 타겟 성별
        $g = $demo['gender'];
        if ($demo['has'] && (($g['female_pct'] ?? 0) + ($g['male_pct'] ?? 0)) > 0) {
            $f = (float) $g['female_pct'];
            $m = (float) $g['male_pct'];
            $balanced = abs($f - $m) <= 12;
            $dom = $f >= $m ? '여성' : '남성';
            $dpct = max($f, $m);
            $cards[] = ['group' => 'target', 'label' => '주 타겟 성별', 'value' => $balanced ? '남녀 고른 분포' : $dom.' '.$dpct.'%', 'color' => 'var(--color-badge-pink)'];
            $genderPhrase = $balanced ? '성별은 고르게 분포' : "{$dom}({$dpct}%) 비중이 높고";
        } else {
            $genderPhrase = '';
        }

        // 주 타겟 연령 (상위 2개)
        $agePhrase = '';
        if ($demo['has'] && count($demo['age'])) {
            $ages = $demo['age'];
            usort($ages, fn ($a, $b) => $b['pct'] <=> $a['pct']);
            $top = array_slice($ages, 0, 2);
            $topLabels = implode('·', array_map(fn ($a) => $a['label'], $top));
            $topSum = round(array_sum(array_map(fn ($a) => $a['pct'], $top)));
            $cards[] = ['group' => 'target', 'label' => '주 타겟 연령', 'value' => $topLabels, 'color' => 'var(--color-accent)'];
            $agePhrase = "{$topLabels}가 검색의 {$topSum}%를 차지합니다";
        }

        // 핵심 세그먼트 (성별×연령 최고 셀)
        if ($demo['has'] && count($demo['pyramid'])) {
            $best = null;
            foreach ($demo['pyramid'] as $p) {
                foreach ([['여성', $p['female']], ['남성', $p['male']]] as [$sex, $v]) {
                    if ($best === null || $v > $best['v']) {
                        $best = ['label' => $p['label'], 'sex' => $sex, 'v' => $v];
                    }
                }
            }
            if ($best && $best['v'] > 0) {
                $cards[] = ['group' => 'target', 'label' => '핵심 타겟', 'value' => $best['label'].' '.$best['sex'], 'color' => 'var(--color-badge-violet)'];
            }
        }

        // 요약 문장 조합
        $target = trim($genderPhrase.' '.$agePhrase);
        $summary = '이 키워드는 '
            .($target !== '' ? $target : '검색 특성을 분석했습니다')
            .($hasSeason ? '. '.$parts[0] : '').'.';

        return ['cards' => $cards, 'summary' => $summary];
    }

    /**
     * AEO 요약 답변 + FAQ — 문서 상단 "요약 답변" 블록과 FAQPage(JSON-LD·화면 동일 문항)용.
     * 데이터 기반 결정적 템플릿(LLM 아님) — 있는 데이터만 문장·문항으로 만든다(22 Phase 2).
     * 답변엔진(AEO)·생성엔진(GEO)이 인용하기 좋은 완결형 수치 문장으로 구성한다.
     *
     * @param  array<string,mixed>  $vm  build() 결과 뷰모델
     * @return array{summary:string,faq:list<array{q:string,a:string}>}
     */
    public static function aeo(array $vm): array
    {
        $kw = (string) ($vm['keyword'] ?? '');
        $total = (int) ($vm['total'] ?? 0);
        $hasVolume = ! empty($vm['has_volume']) && $total > 0;
        $comp = isset($vm['comp_idx']) ? (string) $vm['comp_idx'] : null;
        $grade = isset($vm['grade']) ? (string) $vm['grade'] : null;
        $volLine = '네이버 기준 월 약 '.number_format($total).'회입니다(PC '.number_format((int) ($vm['pc'] ?? 0)).' · 모바일 '.number_format((int) ($vm['mobile'] ?? 0)).'회).';

        // 요약 — ① 검색량 ② 경쟁·등급 ③ 타겟·시즌(insights 요약 문장 재사용)
        $s1 = $hasVolume
            ? "'{$kw}'는 네이버에서 월 약 ".number_format($total).'회 검색되는 키워드입니다(PC '.number_format((int) ($vm['pc'] ?? 0)).' · 모바일 '.number_format((int) ($vm['mobile'] ?? 0)).'회).'
            : "'{$kw}' 키워드의 검색량 데이터를 집계 중입니다.";
        $p = [];
        if ($comp !== null && $comp !== '') {
            $p[] = "광고 경쟁강도는 '{$comp}'";
        }
        if ($grade) {
            $p[] = "검색량 등급은 {$grade}(자체 추정 S~F)";
        }
        $s2 = $p ? implode(', ', $p).'입니다.' : '';
        $ins = $vm['insights'] ?? null;
        $summary = trim($s1.' '.$s2.' '.(is_array($ins) ? trim((string) ($ins['summary'] ?? '')) : ''));

        // FAQ — 검색량(항상) + 시기·타겟·경쟁(데이터 있을 때만)
        $faq = [[
            'q' => "'{$kw}' 월간 검색량은 얼마인가요?",
            'a' => $hasVolume ? $volLine : '검색량 데이터를 집계 중입니다.',
        ]];

        $season = $vm['season'] ?? null;
        if (is_array($season) && ! empty($season['peak_months'])) {
            $peak = implode('·', array_map(fn ($m) => $m.'월', $season['peak_months']));
            $low = implode('·', array_map(fn ($m) => $m.'월', (array) ($season['low_months'] ?? [])));
            $faq[] = [
                'q' => "'{$kw}'는 언제 가장 많이 검색되나요?",
                'a' => "최근 12개월 기준 검색이 가장 많은 달은 {$peak}"
                    .($low !== '' ? ", 가장 적은 달은 {$low}입니다" : '입니다')
                    .' (네이버 월별 검색 추이 기반 자체 집계).',
            ];
        }

        $g = (array) ($vm['gender'] ?? []);
        if (! empty($vm['has_demo']) && ((float) ($g['female_pct'] ?? 0) + (float) ($g['male_pct'] ?? 0)) > 0) {
            $a = '여성 '.($g['female_pct'] ?? 0).'% · 남성 '.($g['male_pct'] ?? 0).'%';
            $ages = (array) ($vm['age'] ?? []);
            if ($ages) {
                usort($ages, fn ($x, $y) => ($y['pct'] ?? 0) <=> ($x['pct'] ?? 0));
                $a .= '이며, '.($ages[0]['label'] ?? '').'('.($ages[0]['pct'] ?? 0).'%)가 가장 많이 검색합니다';
            }
            $faq[] = ['q' => "'{$kw}'는 누가 많이 검색하나요?", 'a' => $a.'.'];
        }

        if (($comp !== null && $comp !== '') || $grade) {
            $faq[] = [
                'q' => "'{$kw}' 경쟁강도는 어느 정도인가요?",
                'a' => trim(($comp !== null && $comp !== '' ? "네이버 검색광고 경쟁강도는 '{$comp}'입니다. " : '')
                    .($grade ? "월간 검색량 등급은 {$grade}입니다(검색량 기반 자체 추정, 네이버 공식 등급 아님)." : '')),
            ];
        }

        return ['summary' => $summary, 'faq' => $faq];
    }

    /** 검색량 기반 자체 추정 등급(S~F). "네이버 공식 등급" 아님. */
    public static function grade(int $total): string
    {
        return match (true) {
            $total >= 100000 => 'S',
            $total >= 30000 => 'A',
            $total >= 10000 => 'B',
            $total >= 3000 => 'C',
            $total >= 1000 => 'D',
            $total >= 100 => 'E',
            default => 'F',
        };
    }

    /**
     * 상업성 추정 — 광고 경쟁(comp)과 구매 의도(쇼핑 상품 수) 중 **강한 신호**로 판정.
     * 경쟁강도만으론 제품 키워드(예: 헤드셋)를 놓치므로 쇼핑 상품 수를 병행한다.
     * 지역·서비스 키워드(예: 강남맛집)는 쇼핑 상품이 없어 경쟁강도가 상업성을 대변한다.
     *
     * @param  int|null  $shopTotal  openapi shop.json total(쇼핑 상품 수). null이면 경쟁강도만(기존 동작).
     */
    public static function commercial(?string $comp, ?int $shopTotal = null): array
    {
        $compPct = match ($comp) {
            '높음' => 70, '중간' => 45, '낮음' => 20,
            default => null,
        };
        if ($compPct === null && $shopTotal === null) {
            return ['label' => '판정 불가', 'is_commercial' => false, 'commercial_pct' => null, 'info_pct' => null];
        }

        // 쇼핑 상품 수 → 구매 의도 점수(로그 스케일). 제품 키워드일수록 높다.
        $shopScore = $shopTotal === null ? null : match (true) {
            $shopTotal >= 500_000 => 88,
            $shopTotal >= 100_000 => 74,
            $shopTotal >= 20_000 => 58,
            $shopTotal >= 5_000 => 42,
            $shopTotal >= 1_000 => 30,
            $shopTotal >= 100 => 16,
            default => 6,
        };

        // 두 신호 중 강한 쪽(광고로 경쟁하거나 상품이 많으면 상업적)
        $pct = (int) round(max($compPct ?? 0, $shopScore ?? 0));

        return [
            'label' => $pct >= 50 ? '상업 키워드' : '비상업 키워드',
            'is_commercial' => $pct >= 50,
            'commercial_pct' => $pct,
            'info_pct' => 100 - $pct,
        ];
    }

    /**
     * 콘텐츠 포화 지수 — 월간 발행량 ÷ 월간 검색량 × 100 (블랙키위 공식).
     * 등급: ≥50 매우높음 / ≥30 높음 / ≥10 보통 / ≥5 낮음 / <5 매우낮음.
     * 집계 상한(캡) 도달 시 실제 발행량은 더 많으므로 '매우 높음'으로 처리한다.
     *
     * @param  array{blog:int,blog_capped?:bool,cafe:int,cafe_capped?:bool}|null  $counts  월간 발행량
     * @return array{blog:array,cafe:array,total:array,search:int}|null
     */
    public static function saturation(?array $counts, int $search): ?array
    {
        if (! $counts || $search <= 0) {
            return null;
        }
        $one = function (int $vol) use ($search) {
            // 누적 발행량 배율(발행량÷검색량)을 0~100% 포화도로 정규화. 배율 15 ≈ 50%.
            $ratio = $vol / max(1, $search);
            $pct = round($ratio / ($ratio + 15) * 100, 1);
            $lv = match (true) {          // 블랙키위 등급표
                $pct >= 50 => ['매우 높음', 'var(--color-error)'],
                $pct >= 30 => ['높음', 'var(--color-warning)'],
                $pct >= 10 => ['보통', 'var(--color-accent)'],
                $pct >= 5 => ['낮음', 'var(--color-success)'],
                default => ['매우 낮음', 'var(--color-muted)'],
            };

            return ['pct' => $pct, 'label' => $lv[0], 'color' => $lv[1], 'volume' => $vol];
        };

        return [
            'blog' => $one((int) ($counts['blog'] ?? 0)),
            'cafe' => $one((int) ($counts['cafe'] ?? 0)),
            'total' => $one((int) ($counts['total'] ?? 0)),
            'search' => $search,
        ];
    }

    /** 월간 검색량 → 일/주 환산 예상(단순 비례, 실측 아님). */
    public static function forecast(int $total): array
    {
        return [
            'daily' => (int) round($total / 30),
            'weekly' => (int) round($total / 30 * 7),
            'monthly' => $total,
        ];
    }

    /**
     * 연관 키워드 — 검색광고 keywordstool 결과 중 **입력어 토큰을 포함한 것만** 전부(제한 없음).
     * 입력어를 공백 단위(주제) + 전체로 분해해, 그 조각을 포함하는 연관어만 남긴다.
     *
     * @return list<array{keyword:string,total:int,comp_idx:?string}>
     */
    public static function related(?array $base, string $keyword = ''): array
    {
        $tokens = self::keywordTokens($keyword);
        $out = [];
        foreach ((array) ($base['related'] ?? []) as $r) {
            if (! is_array($r) || ($r['keyword'] ?? '') === '') {
                continue;
            }
            $kw = (string) $r['keyword'];
            if ($tokens && ! self::containsAnyToken($kw, $tokens)) {
                continue;   // 입력어 토큰을 하나도 포함하지 않으면 제외
            }
            $out[] = [
                'keyword' => $kw,
                'total' => (int) ($r['monthly_total'] ?? 0),
                'comp_idx' => isset($r['comp_idx']) ? (string) $r['comp_idx'] : null,
            ];
        }

        return $out;
    }

    /**
     * 키워드 추천 — 시드 키워드의 연관어(검색광고, 볼륨·경쟁 포함) + 자동완성을 합쳐 "기회 점수"로 랭킹.
     * 기회 점수 = 검색량 × 경쟁가중치(낮음↑). 검색량 많고 경쟁 낮은 "황금 키워드"가 상위.
     *
     * @return list<array{keyword:string,total:?int,pc:?int,mobile:?int,comp_idx:?string,grade:?string,from:string,score:int}>
     */
    public static function recommend(?array $base, array $autocomplete = []): array
    {
        $norm = fn ($s) => mb_strtolower(preg_replace('/\s+/u', '', (string) $s), 'UTF-8');
        $seed = $norm($base['keyword'] ?? '');
        $ac = [];
        foreach ($autocomplete as $s) {
            $ac[$norm($s)] = (string) $s;
        }

        $out = [];
        $seen = [];
        // 연관 키워드(검색광고 — 볼륨·경쟁 보유)
        foreach ((array) ($base['related'] ?? []) as $r) {
            if (! is_array($r) || ($r['keyword'] ?? '') === '') {
                continue;
            }
            $kw = (string) $r['keyword'];
            $nk = $norm($kw);
            if ($nk === $seed || isset($seen[$nk])) {
                continue;
            }
            $seen[$nk] = true;
            $vol = (int) ($r['monthly_total'] ?? 0);
            $comp = isset($r['comp_idx']) ? (string) $r['comp_idx'] : null;
            $out[] = [
                'keyword' => $kw,
                'total' => $vol,
                'pc' => (int) ($r['monthly_pc'] ?? 0),
                'mobile' => (int) ($r['monthly_mobile'] ?? 0),
                'comp_idx' => $comp,
                'grade' => self::grade($vol),
                'from' => isset($ac[$nk]) ? '연관·자동완성' : '연관',
                'score' => self::opportunity($vol, $comp),
            ];
        }
        // 자동완성 전용(연관에 없던 것) — 볼륨 미상
        foreach ($ac as $nk => $kw) {
            if ($nk === $seed || isset($seen[$nk])) {
                continue;
            }
            $seen[$nk] = true;
            $out[] = ['keyword' => $kw, 'total' => null, 'pc' => null, 'mobile' => null, 'comp_idx' => null, 'grade' => null, 'from' => '자동완성', 'score' => 0];
        }

        usort($out, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: (($b['total'] ?? 0) <=> ($a['total'] ?? 0)));

        return $out;
    }

    /** 기회 점수 — 검색량 × 경쟁 가중치(낮을수록 공략 쉬움). */
    private static function opportunity(int $vol, ?string $comp): int
    {
        $w = match ($comp) {
            '낮음' => 1.0, '중간' => 0.6, '높음' => 0.35,
            default => 0.7,
        };

        return (int) round($vol * $w);
    }

    /** 입력 키워드 → 토큰(공백 분리 단어 + 전체, 공백제거·소문자, 2자 이상). */
    public static function keywordTokens(string $keyword): array
    {
        $norm = fn ($s) => mb_strtolower(preg_replace('/\s+/u', '', (string) $s), 'UTF-8');
        $tokens = [];
        foreach (preg_split('/\s+/u', trim($keyword)) ?: [] as $w) {
            $t = $norm($w);
            if (mb_strlen($t) >= 2) {
                $tokens[] = $t;
            }
        }
        $full = $norm($keyword);
        if (mb_strlen($full) >= 2 && ! in_array($full, $tokens, true)) {
            $tokens[] = $full;
        }

        return array_values(array_unique($tokens));
    }

    /** 연관어(공백제거·소문자)가 토큰 중 하나라도 포함하는가. */
    private static function containsAnyToken(string $kw, array $tokens): bool
    {
        $n = mb_strtolower(preg_replace('/\s+/u', '', $kw), 'UTF-8');
        foreach ($tokens as $t) {
            if ($t !== '' && str_contains($n, $t)) {
                return true;
            }
        }

        return false;
    }

    /** 월별 트렌드(최근 12개월) → [label, total]. */
    public static function trend(?array $detail): array
    {
        $out = [];
        foreach ((array) ($detail['monthly'] ?? []) as $m) {
            if (! is_array($m)) {
                continue;
            }
            $label = (string) ($m['label'] ?? '');
            // '2025-07' / '202507' → 'MM' 표기(축약)
            if (preg_match('/(\d{4})\D?(\d{2})/', $label, $mm)) {
                $label = $mm[1].'-'.$mm[2];
            }
            $out[] = ['label' => $label, 'total' => (int) ($m['total'] ?? 0), 'pc' => (int) ($m['pc'] ?? 0), 'mobile' => (int) ($m['mobile'] ?? 0)];
        }

        return $out;
    }

    /** 월별 검색 비율(1~12월) — 트렌드를 월-of-year 로 집계해 비율화. */
    public static function monthRatio(?array $detail): array
    {
        $by = array_fill(1, 12, 0);
        foreach ((array) ($detail['monthly'] ?? []) as $m) {
            if (! is_array($m)) {
                continue;
            }
            if (preg_match('/\d{4}\D?(\d{2})/', (string) ($m['label'] ?? ''), $mm)) {
                $mon = (int) $mm[1];
                if ($mon >= 1 && $mon <= 12) {
                    $by[$mon] += (int) ($m['total'] ?? 0);
                }
            }
        }
        $sum = array_sum($by) ?: 1;
        $out = [];
        for ($i = 1; $i <= 12; $i++) {
            $out[] = ['m' => $i, 'pct' => round($by[$i] / $sum * 100, 1)];
        }

        return $out;
    }

    /** 연령 코드 → 라벨. */
    public static function ageLabel(string $code): string
    {
        return self::AGE_LABEL[$code] ?? ($code !== '' ? $code : '기타');
    }

    private static function ageRank(string $code): int
    {
        $i = array_search($code, self::AGE_ORDER, true);

        return $i === false ? 99 : $i;
    }

    /**
     * 성별·연령·결합(피라미드) — 검색광고 userStat 14버킷(detail['buckets']) 조합.
     * buckets 없으면 사전집계(detail['gender']/['age']) 폴백.
     */
    public static function demographics(?array $detail): array
    {
        $buckets = (array) ($detail['buckets'] ?? []);
        if (! $buckets) {
            return self::demographicsFallback($detail);
        }

        $byAge = [];
        $gTotal = ['f' => 0, 'm' => 0];
        foreach ($buckets as $b) {
            if (! is_array($b)) {
                continue;
            }
            $g = (string) ($b['gender'] ?? '');
            $a = (string) ($b['age'] ?? '');
            $t = (int) ($b['total'] ?? 0);
            $byAge[$a] ??= ['f' => 0, 'm' => 0];
            if ($g === 'f' || $g === 'm') {
                $byAge[$a][$g] += $t;
                $gTotal[$g] += $t;
            }
        }
        $all = ($gTotal['f'] + $gTotal['m']) ?: 1;
        $fSum = $gTotal['f'] ?: 1;
        $mSum = $gTotal['m'] ?: 1;

        $codes = array_keys($byAge);
        usort($codes, fn ($x, $y) => self::ageRank($x) <=> self::ageRank($y));

        $age = [];
        $pyramid = [];
        foreach ($codes as $code) {
            $f = $byAge[$code]['f'];
            $m = $byAge[$code]['m'];
            $t = $f + $m;
            $label = self::ageLabel($code);
            $age[] = ['code' => $code, 'label' => $label, 'total' => $t, 'pct' => round($t / $all * 100, 1)];
            $pyramid[] = [
                'label' => $label, 'male' => $m, 'female' => $f,
                'male_pct' => round($m / $mSum * 100, 1),
                'female_pct' => round($f / $fSum * 100, 1),
            ];
        }

        return [
            'has' => true,
            'gender' => [
                'female' => $gTotal['f'], 'male' => $gTotal['m'],
                'female_pct' => round($gTotal['f'] / $all * 100, 1),
                'male_pct' => round($gTotal['m'] / $all * 100, 1),
            ],
            'age' => $age,
            'pyramid' => $pyramid,
        ];
    }

    /** buckets 없을 때 사전집계값으로 폴백(피라미드 없음). */
    private static function demographicsFallback(?array $detail): array
    {
        $g = (array) ($detail['gender'] ?? []);
        $gender = [
            'female' => (int) ($g['female'] ?? 0), 'male' => (int) ($g['male'] ?? 0),
            'female_pct' => (float) ($g['female_pct'] ?? 0), 'male_pct' => (float) ($g['male_pct'] ?? 0),
        ];
        $age = [];
        foreach ((array) ($detail['age'] ?? []) as $a) {
            if (! is_array($a)) {
                continue;
            }
            $code = (string) ($a['age'] ?? '');
            $age[] = ['code' => $code, 'label' => self::ageLabel($code), 'total' => (int) ($a['total'] ?? 0), 'pct' => (float) ($a['pct'] ?? 0)];
        }
        usort($age, fn ($x, $y) => self::ageRank($x['code']) <=> self::ageRank($y['code']));

        return [
            'has' => ($gender['female'] + $gender['male']) > 0 || count($age) > 0,
            'gender' => $gender,
            'age' => $age,
            'pyramid' => [],
        ];
    }

}
