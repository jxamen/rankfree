<?php

namespace Tests\Unit;

use App\Support\RewardDay;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 1 완료 판정(design-02 §12 FarmDayBoundaryTest) —
 * 05:59/06:00 의 농장일이 다르고, 01:59/02:00 의 슬롯이 다르다.
 */
class RewardDayBoundaryTest extends TestCase
{
    private function kst(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Seoul');
    }

    public function test_농장일은_06시에_바뀐다(): void
    {
        $this->assertSame('2026-07-30', RewardDay::current($this->kst('2026-07-31 05:59')));
        $this->assertSame('2026-07-31', RewardDay::current($this->kst('2026-07-31 06:00')));
        $this->assertSame('2026-07-31', RewardDay::current($this->kst('2026-07-31 23:59')));
        $this->assertSame('2026-07-31', RewardDay::current($this->kst('2026-08-01 00:30')));   // 자정 넘어도 같은 농장일
    }

    public function test_슬롯은_02시에_휴지로_바뀐다(): void
    {
        $this->assertSame('S7', RewardDay::slot($this->kst('2026-07-31 01:59')));
        $this->assertNull(RewardDay::slot($this->kst('2026-07-31 02:00')));
        $this->assertNull(RewardDay::slot($this->kst('2026-07-31 05:59')));
        $this->assertSame('S1', RewardDay::slot($this->kst('2026-07-31 06:00')));
    }

    public function test_슬롯_경계와_대표_시각(): void
    {
        $this->assertSame('S2', RewardDay::slot($this->kst('2026-07-31 09:00')));
        $this->assertSame('S3', RewardDay::slot($this->kst('2026-07-31 12:30')));
        $this->assertSame('S4', RewardDay::slot($this->kst('2026-07-31 14:00')));
        $this->assertSame('S5', RewardDay::slot($this->kst('2026-07-31 19:59')));
        $this->assertSame('S6', RewardDay::slot($this->kst('2026-07-31 21:00')));
        $this->assertSame('S7', RewardDay::slot($this->kst('2026-07-31 22:00')));
        $this->assertSame('S7', RewardDay::slot($this->kst('2026-07-31 23:30')));
    }

    public function test_isQuiet_와_start(): void
    {
        $this->assertTrue(RewardDay::isQuiet($this->kst('2026-07-31 03:00')));
        $this->assertFalse(RewardDay::isQuiet($this->kst('2026-07-31 07:00')));

        $start = RewardDay::start('2026-07-31');
        $this->assertSame('2026-07-31 06:00', $start->format('Y-m-d H:i'));
        $this->assertSame('Asia/Seoul', $start->timezone->getName());
    }
}
