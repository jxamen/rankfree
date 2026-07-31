<?php

namespace Tests\Unit;

use App\Domain\Reward\SlotCap;
use Tests\TestCase;

/** Phase 3 완료 판정 — C1 공식: 단조 증가, 마지막 구간은 항상 D(design-02 §12 FarmSlotCapTest). */
class RewardSlotCapTest extends TestCase
{
    public function test_설계_예시값과_일치한다(): void
    {
        $this->assertSame([4, 10, 18, 25, 32, 42, 50], array_map(fn ($i) => SlotCap::at(50, $i), range(0, 6)));
        $this->assertSame([2, 3, 5, 6, 8, 9, 10], array_map(fn ($i) => SlotCap::at(10, $i), range(0, 6)));
        $this->assertSame([1, 1, 2, 2, 3, 3, 3], array_map(fn ($i) => SlotCap::at(3, $i), range(0, 6)));
        $this->assertSame([1, 1, 1, 1, 1, 1, 1], array_map(fn ($i) => SlotCap::at(1, $i), range(0, 6)));
    }

    public function test_모든_D에서_단조증가하고_마지막_구간은_D다(): void
    {
        foreach ([...range(1, 300), 500, 1000, 5000, 50000, 100000] as $d) {
            $prev = 0;
            foreach (range(0, 6) as $i) {
                $cap = SlotCap::at($d, $i);
                $this->assertGreaterThanOrEqual($prev, $cap, "D={$d} slot={$i} 단조 위반");
                $this->assertLessThanOrEqual($d, $cap, "D={$d} slot={$i} 상한 초과");
                $prev = $cap;
            }
            $this->assertSame($d, SlotCap::at($d, 6), "D={$d} 마지막 구간 != D");
        }
    }

    public function test_각_구간_최소_1_누적이_보장된다(): void
    {
        // D >= 2n 구간 — floor 만 쓰면 첫 구간 0 이 될 수 있어 max(i+1) 보정이 있어야 한다
        foreach ([14, 20, 30] as $d) {
            foreach (range(0, 6) as $i) {
                $this->assertGreaterThanOrEqual($i + 1, SlotCap::at($d, $i));
            }
        }
    }
}
