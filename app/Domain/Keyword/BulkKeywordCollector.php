<?php

namespace App\Domain\Keyword;

use App\Domain\SearchAdWeb\SearchAdWebClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 키워드 대량 분석 — 키워드 1개의 전체 컬럼을 기존 네이버 서비스로 수집.
 * 검색광고(검색량·경쟁·연관·성별/연령/12개월) + openapi(발행량·포화) + 데이터랩(요일) + SERP(섹션배치).
 * 구글월별·일별30·월별36·연별9 는 후속(구글 토큰/데이터랩 스케일링) — 여기선 네이버 측만.
 */
class BulkKeywordCollector
{
    public function __construct(
        private NaverKeywordService $light,
        private SearchAdWebClient $web,
        private NaverContentVolumeService $content,
        private NaverDataLabService $datalab,
        private NaverSerpService $serp,
    ) {}

    /**
     * @return array{ok:bool,reason:?string,data:array}
     */
    public function collect(string $keyword, bool $includeSerp = true): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['ok' => false, 'reason' => '빈 키워드', 'data' => []];
        }

        try {
            $base = $this->light->analyze($keyword);
            if ($base === null) {
                return ['ok' => false, 'reason' => '검색광고 데이터 없음', 'data' => []];
            }

            $total = (int) ($base['monthly_total'] ?? 0);
            $pc = (int) ($base['monthly_pc'] ?? 0);
            $mobile = (int) ($base['monthly_mobile'] ?? 0);
            $comp = isset($base['comp_idx']) ? (string) $base['comp_idx'] : null;

            // 상세(성별·연령·12개월) — 성공만 6h 캐시
            $detail = $this->cachedDetail($keyword);

            // 파생 지표
            $shopTotal = $this->content->shopTotal($keyword);
            $commercial = KeywordAnalysisPresenter::commercial($comp, $shopTotal);
            $forecast = KeywordAnalysisPresenter::forecast($total);
            $demo = KeywordAnalysisPresenter::demographics($detail);
            $trend = KeywordAnalysisPresenter::trend($detail);          // 12개월 절대치
            $monthRatio = KeywordAnalysisPresenter::monthRatio($detail);
            $issue = KeywordAnalysisPresenter::issueScore($trend);

            // 발행량·포화
            $counts = $this->content->counts($keyword);
            $sat = KeywordAnalysisPresenter::saturation($counts, $total);

            // 요일별(데이터랩)
            $weekday = $this->datalab->weekdayRatio($keyword);

            // 섹션배치(SERP) — 옵션
            $serp = $includeSerp ? $this->serp->sections($keyword) : null;

            // 월말/어제까지 추정(월간 비례)
            $today = CarbonImmutable::now('Asia/Seoul');
            $daily = (int) ($forecast['daily'] ?? 0);
            $yesterdayEst = $daily * max(0, $today->day - 1);
            $monthEndEst = $daily * $today->daysInMonth;

            $data = [
                'keyword' => $keyword,
                'collected_at' => $today->format('Y-m-d'),
                'total' => $total, 'pc' => $pc, 'mobile' => $mobile,
                'daily_avg' => $total > 0 ? round($total / 30, 1) : 0,
                'grade' => KeywordAnalysisPresenter::grade($total),
                'comp_idx' => $comp,
                'commercial_pct' => $commercial['commercial_pct'],
                'info_pct' => $commercial['info_pct'],
                'adult' => '비성인',   // openapi 성인 플래그 미제공 — 기본값
                'blog_total' => $counts['blog'] ?? null,
                'cafe_total' => $counts['cafe'] ?? null,
                'blog_sat' => $sat['blog']['pct'] ?? null,
                'cafe_sat' => $sat['cafe']['pct'] ?? null,
                'total_sat' => $sat['total']['pct'] ?? null,
                'yesterday_est' => $yesterdayEst,
                'monthend_est' => $monthEndEst,
                'related' => $this->relatedStr($base, 20),
                'trend' => array_map(fn ($t) => ['label' => $t['label'], 'total' => $t['total']], $trend),
                'month_ratio' => array_column($monthRatio, 'pct', 'm'),           // [1=>..,12=>..]
                'weekday' => $weekday ? array_column($weekday, 'pct', 'w') : null, // [월=>..,일=>..]
                'gender' => ['male' => $demo['gender']['male_pct'] ?? 0, 'female' => $demo['gender']['female_pct'] ?? 0],
                'age5' => $this->age5($demo['age'] ?? []),
                'issue_pct' => $issue['pct'] ?? null,
                'serp_pc' => $serp['pc'] ?? null,
                'serp_mobile' => $serp['mobile'] ?? null,
            ];

            return ['ok' => true, 'reason' => null, 'data' => $data];
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => '수집 오류: '.mb_strimwidth($e->getMessage(), 0, 80), 'data' => []];
        }
    }

    /** 상세(성별·연령·12개월) — index 컨트롤러와 동일 캐시 키 재사용. */
    private function cachedDetail(string $keyword): ?array
    {
        $key = 'kw:detail:'.md5(mb_strtoupper(str_replace(' ', '', $keyword)));
        $detail = Cache::get($key);
        if ($detail === null) {
            $d = $this->web->keywordDetail($keyword);
            $detail = isset($d['error']) ? null : $d;
            if ($detail !== null) {
                Cache::put($key, $detail, now()->addHours(6));
            }
        }

        return $detail;
    }

    /** 연관 키워드 상위 N → "키워드_볼륨, …" (샘플 포맷). */
    private function relatedStr(array $base, int $n): string
    {
        $out = [];
        foreach (array_slice((array) ($base['related'] ?? []), 0, $n) as $r) {
            if (is_array($r) && ($r['keyword'] ?? '') !== '') {
                $out[] = $r['keyword'].'_'.(int) ($r['monthly_total'] ?? 0);
            }
        }

        return implode(', ', $out);
    }

    /** 7밴드 연령 → 5밴드(10대/20대/30대/40대/50대+) %. */
    private function age5(array $age): array
    {
        $map = ['0-12' => '10대', '13-19' => '10대', '20-24' => '20대', '25-29' => '20대', '30-39' => '30대', '40-49' => '40대', '50-' => '50대+'];
        $out = ['10대' => 0.0, '20대' => 0.0, '30대' => 0.0, '40대' => 0.0, '50대+' => 0.0];
        foreach ($age as $a) {
            $b = $map[$a['code'] ?? ''] ?? null;
            if ($b !== null) {
                $out[$b] += (float) ($a['pct'] ?? 0);
            }
        }
        foreach ($out as $k => $v) {
            $out[$k] = round($v, 1);
        }

        return $out;
    }
}
