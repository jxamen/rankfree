<?php

namespace Tests\Unit;

use App\Domain\Place\PlaceCalibration;
use PHPUnit\Framework\TestCase;

class PlaceCalibrationTest extends TestCase
{
    /** last_result 의 date_time 통계에서 일자별 PV 를 뽑고 같은 날짜는 합산한다. */
    public function test_daily_pv_extracts_and_sums(): void
    {
        $lr = ['sections' => ['stats' => ['date_time' => ['data' => [
            ['date_time' => '2026-07-01', 'pv' => 100],
            ['date_time' => '2026-07-02', 'pv' => 150],
            ['date_time' => '2026-07-02T00:00:00', 'pv' => 50], // 같은 날 → 합산, 날짜 정규화
        ]]]]];

        $pv = PlaceCalibration::dailyPv($lr);

        $this->assertSame(['2026-07-01' => 100, '2026-07-02' => 200], $pv);
    }

    /** 데이터 없거나 구조가 다르면 빈 배열. */
    public function test_daily_pv_empty_on_missing(): void
    {
        $this->assertSame([], PlaceCalibration::dailyPv(null));
        $this->assertSame([], PlaceCalibration::dailyPv(['sections' => []]));
    }

    /** align: 합집합(rows)과 교집합(overlap) 분리. */
    public function test_align_union_and_overlap(): void
    {
        $pv = ['2026-07-01' => 100, '2026-07-02' => 200, '2026-07-03' => 300];
        $scores = [
            ['ymd' => '2026-07-02', 'n2' => 90, 'n3' => 100, 'rnk' => 1],
            ['ymd' => '2026-07-04', 'n2' => 80, 'n3' => 95, 'rnk' => 3], // pv 없는 날
        ];

        $al = PlaceCalibration::align($pv, $scores);

        $this->assertCount(4, $al['rows']);       // 07-01..07-04 합집합
        $this->assertCount(1, $al['overlap']);    // pv·n2 둘 다 있는 07-02
        $this->assertSame('2026-07-02', $al['overlap'][0]['ymd']);
        $this->assertSame(200, $al['overlap'][0]['pv']);
    }

    /** pearson: 완전 양의 상관 = 1.0, 표본 부족(n<3) = null. */
    public function test_pearson(): void
    {
        $this->assertSame(1.0, PlaceCalibration::pearson([1, 2, 3, 4], [10, 20, 30, 40]));
        $this->assertSame(-1.0, PlaceCalibration::pearson([1, 2, 3], [30, 20, 10]));
        $this->assertNull(PlaceCalibration::pearson([1, 2], [3, 4]));    // n<3
        $this->assertNull(PlaceCalibration::pearson([5, 5, 5], [1, 2, 3])); // 분산 0
    }

    /** summarize: N2 가 PV 와 함께 오르면 corr_n2_pv 양수, 순위(작을수록 상위)와 PV 는 음의 상관. */
    public function test_summarize_correlations(): void
    {
        $pv = ['2026-07-01' => 100, '2026-07-02' => 200, '2026-07-03' => 300, '2026-07-04' => 400];
        $scores = [
            ['ymd' => '2026-07-01', 'n2' => 60, 'n3' => 50, 'rnk' => 10],
            ['ymd' => '2026-07-02', 'n2' => 70, 'n3' => 60, 'rnk' => 7],
            ['ymd' => '2026-07-03', 'n2' => 80, 'n3' => 80, 'rnk' => 4],
            ['ymd' => '2026-07-04', 'n2' => 90, 'n3' => 95, 'rnk' => 1],
        ];

        $s = PlaceCalibration::summarize($pv, $scores);

        $this->assertSame(4, $s['overlap_n']);
        $this->assertSame(1000, $s['pv_total']);
        $this->assertNotNull($s['corr_n2_pv']);
        $this->assertGreaterThan(0.9, $s['corr_n2_pv']);   // N2↑ ↔ PV↑
        $this->assertLessThan(-0.9, $s['corr_rank_pv']);   // 순위 숫자↓(상위) ↔ PV↑
    }
}
