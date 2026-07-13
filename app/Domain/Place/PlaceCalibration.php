<?php

namespace App\Domain\Place;

use Illuminate\Support\Carbon;

/**
 * 실측 캘리브레이션 — 내 매장의 스마트플레이스 실제 조회수(일자별 PV)와
 * 경쟁분석 추정치(N2/N3/순위)를 날짜로 정렬해 상관을 산출한다.
 *
 * 목적: N2(자체 추정 인기도)가 실제 방문/조회 추세를 얼마나 잘 따라가는지 검증·튜닝.
 * 순수 계산만 담당(네트워크 X) — SmartplaceCollector 가 수집한 last_result 와
 * PlaceSeoScore(is_mine) 시계열을 입력으로 받는다.
 */
class PlaceCalibration
{
    /**
     * last_result → ['YYYY-MM-DD' => pv]. 통계 6종 중 date_time(일자별 조회수) 사용.
     * 각 행 구조 {date_time: 'YYYY-MM-DD', pv: int} (SmartplaceReportPresenter 와 동일 경로).
     */
    public static function dailyPv(?array $lastResult): array
    {
        $rows = $lastResult['sections']['stats']['date_time']['data'] ?? null;
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $d = substr((string) ($r['date_time'] ?? ''), 0, 10);
            if ($d === '') {
                continue;
            }
            $out[$d] = ($out[$d] ?? 0) + (int) round((float) ($r['pv'] ?? 0));
        }
        ksort($out);

        return $out;
    }

    /**
     * pv(날짜별) + 점수행(ymd·n2·n3·rnk)을 날짜축으로 정렬.
     *
     * @param  array<string,int>  $pvByDate
     * @param  iterable  $scoreRows  각 {ymd, n2, n3, rnk} (배열 또는 모델)
     * @return array{rows: list<array>, overlap: list<array>}  rows=합집합(차트용), overlap=pv·n2 둘 다 있는 날(상관용)
     */
    public static function align(array $pvByDate, iterable $scoreRows): array
    {
        $scoreByDate = [];
        foreach ($scoreRows as $s) {
            $ymd = self::raw($s, 'ymd');
            $ymd = $ymd instanceof Carbon ? $ymd->toDateString() : substr((string) $ymd, 0, 10);
            if ($ymd === '') {
                continue;
            }
            $scoreByDate[$ymd] = ['n2' => self::num($s, 'n2'), 'n3' => self::num($s, 'n3'), 'rnk' => self::num($s, 'rnk')];
        }
        $dates = array_values(array_unique(array_merge(array_keys($pvByDate), array_keys($scoreByDate))));
        sort($dates);

        $rows = [];
        $overlap = [];
        foreach ($dates as $d) {
            $pv = $pvByDate[$d] ?? null;
            $sc = $scoreByDate[$d] ?? null;
            $row = ['ymd' => $d, 'pv' => $pv, 'n2' => $sc['n2'] ?? null, 'n3' => $sc['n3'] ?? null, 'rnk' => $sc['rnk'] ?? null];
            $rows[] = $row;
            if ($pv !== null && ($sc['n2'] ?? null) !== null) {
                $overlap[] = $row;
            }
        }

        return ['rows' => $rows, 'overlap' => $overlap];
    }

    /** 피어슨 상관계수 (표본 n≥3, 양쪽 분산>0). 조건 미충족 시 null. */
    public static function pearson(array $xs, array $ys): ?float
    {
        $xs = array_values($xs);
        $ys = array_values($ys);
        $n = min(count($xs), count($ys));
        if ($n < 3) {
            return null;
        }
        $mx = array_sum(array_slice($xs, 0, $n)) / $n;
        $my = array_sum(array_slice($ys, 0, $n)) / $n;
        $sxy = $sxx = $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $mx;
            $dy = $ys[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }
        if ($sxx <= 0 || $syy <= 0) {
            return null;
        }

        return round($sxy / sqrt($sxx * $syy), 3);
    }

    /**
     * 종합 요약 — 시계열(rows) + 상관(N2↔PV, 순위↔PV) + 기초 통계.
     *
     * @param  array<string,int>  $pvByDate
     */
    public static function summarize(array $pvByDate, iterable $scoreRows): array
    {
        $al = self::align($pvByDate, $scoreRows);
        $ov = $al['overlap'];

        $pvN2 = array_map(fn ($r) => (float) $r['pv'], $ov);
        $n2 = array_map(fn ($r) => (float) $r['n2'], $ov);

        $ovRank = array_values(array_filter($ov, fn ($r) => $r['rnk'] !== null && (int) $r['rnk'] < 300));
        $pvRank = array_map(fn ($r) => (float) $r['pv'], $ovRank);
        $rnk = array_map(fn ($r) => (float) $r['rnk'], $ovRank);

        return [
            'rows' => $al['rows'],
            'overlap_n' => count($ov),
            'pv_days' => count($pvByDate),
            'pv_total' => array_sum($pvByDate),
            'pv_avg' => count($pvByDate) ? round(array_sum($pvByDate) / count($pvByDate), 1) : null,
            'n2_avg' => count($n2) ? round(array_sum($n2) / count($n2), 1) : null,
            // N2↔조회수: 양(+)이 높을수록 N2가 실측을 잘 따라감. 순위↔조회수: 음(−)이 정상(상위일수록 조회↑).
            'corr_n2_pv' => self::pearson($n2, $pvN2),
            'corr_rank_pv' => self::pearson($rnk, $pvRank),
        ];
    }

    private static function raw(mixed $s, string $k): mixed
    {
        return is_array($s) ? ($s[$k] ?? null) : ($s->$k ?? null);
    }

    private static function num(mixed $s, string $k): ?float
    {
        $v = self::raw($s, $k);

        return ($v === null || $v === '') ? null : (float) $v;
    }
}
