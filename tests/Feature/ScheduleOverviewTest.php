<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 자동 수집 현황(/admin/schedule) — 스케줄 정의를 읽어 작업·주기·최근 수집을 보여준다(열람 전용). */
class ScheduleOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => '관리자', 'email' => 'sch@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    public function test_requires_operator(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'plain-sch@rf.kr', 'password' => 'x1234567']);
        $this->actingAs($user)->get('/admin/schedule')->assertForbidden();
    }

    public function test_lists_scheduled_jobs_with_korean_frequency(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/schedule')->assertOk()->getContent();

        // 항상 걸려 있는 작업들(게이트 없음)
        $this->assertStringContainsString('place:track-run', $html);
        $this->assertStringContainsString('매일 11:30 · 매일 16:30', $html);   // 같은 커맨드 두 시각 → 한 줄 병합
        $this->assertStringContainsString('shop:track-run', $html);
        $this->assertStringContainsString('매시간', $html);
        $this->assertStringContainsString('gsc:collect', $html);
        $this->assertStringContainsString('hub:auto-publish', $html);
        $this->assertStringContainsString('매분', $html);

        // 수집이 아닌 유지보수 작업은 구분 표기
        $this->assertStringContainsString('수집 없음(유지보수)', $html);

        // 게이트(.env 토글) 목록
        $this->assertStringContainsString('NEWBIZ_SCHEDULE_ENABLED', $html);
        $this->assertStringContainsString('HUB_SCHEDULE_ENABLED', $html);
    }

    public function test_shows_last_collected_timestamp(): void
    {
        // 게이트 없는 상시 작업(searchadweb:login)으로 검증 — 게이트 있는 작업은 스케줄이
        // 테스트 프로세스에서 먼저 로드되면(콘솔 커널 선부팅) 테스트 내 config 변경이 안 먹는다
        $at = now()->subHours(3)->startOfMinute();
        \App\Models\NaverAdSession::create(['id' => 1, 'cookies' => '', 'status' => 'ok', 'logged_in_at' => $at, 'checked_at' => $at]);

        $html = $this->actingAs($this->admin())->get('/admin/schedule')->assertOk()->getContent();

        $this->assertStringContainsString('searchadweb:login', $html);
        $this->assertStringContainsString($at->format('m-d H:i'), $html);
    }
}
