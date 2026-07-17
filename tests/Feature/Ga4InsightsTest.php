<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Jcurve\Ga4Insights\Contracts\Ga4Credentials;
use Tests\TestCase;

/** GA4 상세 분석 대시보드(ga4-insights 패키지) — 마운트·권한·미연동·라이브 조회 렌더. */
class Ga4InsightsTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::create(['name' => 'op', 'email' => 'op@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    /** 설정된 GA4 자격증명(가짜)로 바인딩. */
    private function fakeConfigured(): void
    {
        $this->app->bind(Ga4Credentials::class, fn () => new class implements Ga4Credentials
        {
            public function propertyId(): ?string
            {
                return '123456';
            }

            public function accessToken(): ?string
            {
                return 'fake-token';
            }

            public function isConfigured(): bool
            {
                return true;
            }
        });
    }

    public function test_dashboard_requires_operator(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
        $this->actingAs($user)->get('/admin/traffic-stats')->assertForbidden();
    }

    public function test_not_configured_shows_setup_guide(): void
    {
        // 기본 자격증명 미설정(환경설정 비어있음) → 안내 표시
        $this->actingAs($this->operator())->get('/admin/traffic-stats')
            ->assertOk()
            ->assertSee('방문 상세 분석')
            ->assertSee('연결되지 않았');
    }

    public function test_dashboard_renders_sections_with_live_ga4(): void
    {
        $this->fakeConfigured();

        // GA4 Data API 목킹 — batchRunReports 는 요청 수만큼 report 반환, realtime 은 빈 결과
        Http::fake([
            '*batchRunReports' => function ($request) {
                $n = count($request->data()['requests'] ?? []);
                $reports = [];
                for ($i = 0; $i < $n; $i++) {
                    $reports[] = $i === 0
                        ? $this->kpiReport()      // 0번 = 개요 KPI
                        : ['dimensionHeaders' => [], 'metricHeaders' => [], 'rows' => []];
                }

                return Http::response(['reports' => $reports], 200);
            },
            '*runRealtimeReport' => Http::response(['rows' => []], 200),
        ]);

        $res = $this->actingAs($this->operator())->get('/admin/traffic-stats?days=28');
        $res->assertOk()
            ->assertSee('방문 상세 분석')
            ->assertSee('핵심 지표')
            ->assertSee('유입 채널')
            ->assertSee('랜딩 페이지')
            ->assertSee('이탈 많은')
            ->assertSee('시간대')
            ->assertDontSee('불러오지 못했습니다')
            ->assertSee('1,234');   // KPI 사용자 값(목킹) 렌더
    }

    public function test_api_error_shows_banner(): void
    {
        $this->fakeConfigured();
        Http::fake([
            '*batchRunReports' => Http::response(['error' => ['message' => 'permission denied']], 403),
            '*runRealtimeReport' => Http::response(['rows' => []], 200),
        ]);

        $this->actingAs($this->operator())->get('/admin/traffic-stats')
            ->assertOk()
            ->assertSee('불러오지 못했습니다');
    }

    /** 개요 KPI 리포트(2 dateRange: 현재/이전) 목킹. */
    private function kpiReport(): array
    {
        $metrics = ['totalUsers', 'newUsers', 'sessions', 'screenPageViews', 'engagementRate',
            'averageSessionDuration', 'bounceRate', 'screenPageViewsPerSession', 'eventCount', 'keyEvents'];
        $curVals = [1234, 800, 1500, 4200, 0.57, 44, 0.43, 2.8, 9000, 30];
        $prevVals = [1000, 700, 1300, 3800, 0.52, 40, 0.48, 2.5, 8000, 20];
        $mk = fn ($vals) => ['metricValues' => array_map(fn ($v) => ['value' => (string) $v], $vals)];

        return [
            'dimensionHeaders' => [['name' => 'dateRange']],
            'metricHeaders' => array_map(fn ($m) => ['name' => $m], $metrics),
            'rows' => [
                ['dimensionValues' => [['value' => 'date_range_0']]] + $mk($curVals),
                ['dimensionValues' => [['value' => 'date_range_1']]] + $mk($prevVals),
            ],
        ];
    }
}
