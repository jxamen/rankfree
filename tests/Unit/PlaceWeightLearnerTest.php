<?php

namespace Tests\Unit;

use App\Domain\Place\PlaceWeightLearner;
use PHPUnit\Framework\TestCase;

class PlaceWeightLearnerTest extends TestCase
{
    private array $prior = [
        'd1' => 0.18, 'd2' => 0.09, 'd3' => 0.07, 'd4' => 0.12,
        'd5' => 0.08, 'd6' => 0.08, 'd7' => 0.14, 'd9' => 0.20, 'd10' => 0.12,
    ];

    /** 표본 3개 미만이면 상관을 못 구해 recommended == prior(정확), apply=false. */
    public function test_too_few_samples_keeps_prior(): void
    {
        $samples = [
            ['store' => 'A', 'd' => ['d1' => 50, 'd9' => 40], 'pv' => 100],
            ['store' => 'B', 'd' => ['d1' => 60, 'd9' => 55], 'pv' => 200],
        ];

        $r = PlaceWeightLearner::recommend($samples, $this->prior, 10);

        $this->assertFalse($r['apply']);
        $this->assertSame($r['prior'], $r['recommended']); // 정확히 프라이어(정규화 동일)
        $this->assertStringContainsString('표본 부족', $r['verdict']);
    }

    /** 라벨이 적으면(예: 3매장) apply=false — 반영하지 않고 진단만. */
    public function test_below_apply_min_does_not_apply(): void
    {
        $samples = [];
        foreach (range(1, 3) as $i) {
            $samples[] = ['store' => "S$i", 'd' => ['d1' => $i * 10, 'd2' => 100 - $i * 10, 'd9' => $i * 12], 'pv' => $i * 100];
        }

        $r = PlaceWeightLearner::recommend($samples, $this->prior, 10);

        $this->assertFalse($r['apply']);
        $this->assertSame(3, $r['n_stores']);
    }

    /** 라벨 충분(15매장) + 방향 학습: PV와 양의 상관 차원↑, 음의 상관 차원↓, 합=1, apply=true. */
    public function test_learns_direction_with_enough_labels(): void
    {
        $samples = [];
        foreach (range(1, 15) as $i) {
            $samples[] = ['store' => "S$i", 'd' => [
                'd1' => $i * 4,          // PV 와 양의 상관
                'd2' => 100 - $i * 3,    // PV 와 음의 상관
                'd9' => $i * 5,          // PV 와 양의 상관
                'd7' => 50,              // 분산 0 → 상관 null → 프라이어 유지
            ], 'pv' => $i * 100];
        }

        $r = PlaceWeightLearner::recommend($samples, $this->prior, 10);

        $this->assertTrue($r['apply']);
        $this->assertGreaterThan($r['prior']['d1'], $r['recommended']['d1']);   // ↑
        $this->assertGreaterThan($r['prior']['d9'], $r['recommended']['d9']);   // ↑
        $this->assertLessThan($r['prior']['d2'], $r['recommended']['d2']);      // ↓
        $this->assertNull($r['evidence']['d7']['corr']);                        // 분산 0 → 근거 없음
        $this->assertEqualsWithDelta(1.0, array_sum($r['recommended']), 0.001); // 합=1
    }
}
