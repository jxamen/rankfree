<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 순위추적·스마트플레이스 자동수집 커맨드가 부팅·실행되는지(빈 세트 스모크). */
class ScheduledCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_track_run_boots_and_completes(): void
    {
        $this->artisan('shop:track-run', ['--user' => 999999])
            ->assertOk()
            ->expectsOutputToContain('처리 시작');
    }

    public function test_place_track_run_boots_and_completes(): void
    {
        $this->artisan('place:track-run', ['--user' => 999999])->assertOk();
    }

    public function test_smartplace_collect_boots_and_completes(): void
    {
        $this->artisan('smartplace:collect', ['--user' => 999999])
            ->assertOk()
            ->expectsOutputToContain('수집 시작');
    }

    public function test_schedule_registers_new_commands(): void
    {
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $cmds = array_map(fn ($e) => $e->command ?? '', $events);
        $joined = implode(' ', $cmds);

        $this->assertStringContainsString('place:track-run', $joined);
        // 쇼핑 순위추적은 스케줄에서 빠져 있어야 한다 — 네이버가 쇼핑 검색 API 를 2026-07-31 종료했다.
        $this->assertStringNotContainsString('shop:track-run', $joined);
        $this->assertStringContainsString('smartplace:collect', $joined);
        $this->assertStringContainsString('hub:partition-rotate', $joined);
    }
}
