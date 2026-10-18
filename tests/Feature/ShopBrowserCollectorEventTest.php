<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ShopBrowserCollectorEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_without_api_scope_and_extension_emit_browser_events_in_existing_order(): void
    {
        config(['services.slack.notifications.bot_user_oauth_token' => null]);
        Log::spy();
        $u = User::factory()->create(['role' => 'operator', 'api_scopes' => []]);
        $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '에어슈트', 'threshold' => 5, 'status' => 'checking']);
        $this->actingAs($u)->withServerVariables(['REMOTE_ADDR' => '125.186.176.132']);
        foreach (['403', '429', '보안문자', 'success'] as $kind) {
            $this->postJson(route('admin.shop-keyword.collector-event', $a), ['kind' => $kind, 'ip' => 'fake'])->assertOk();
            $this->assertEvent($a, $kind);
        }
        $u->update(['api_scopes' => ['shop_keyword']]);
        [, $token] = ExtToken::issue($u);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/ext/shop-keyword/{$a->id}/collector-event", ['kind' => '403', 'collector' => 'extension'])->assertOk();
        Log::shouldHaveReceived('info')->with('shop_exposure_block_event', \Mockery::on(fn ($e) => $e['collector'] === 'browser' && $e['today_count'] === 4))->once();
    }

    public function test_success_event_immediately_clears_failure_but_preserves_pause_and_counts(): void
    {
        config(['services.slack.notifications.bot_user_oauth_token' => null]);
        $u = User::factory()->create(['role' => 'operator']);
        $this->actingAs($u);
        foreach (['blocked', 'error', 'paused', 'target_reached', 'ad_ended'] as $status) {
            $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '회복', 'status' => $status]);
            \Illuminate\Support\Facades\Cache::put('shop-exposure:error:'.$a->id, 'http_403');
            $this->postJson(route('admin.shop-keyword.collector-event', $a), ['kind' => 'success'])->assertOk();
            $this->assertSame(in_array($status, ['blocked', 'error']) ? 'checking' : $status, $a->fresh()->status);
            $this->assertNull(\Illuminate\Support\Facades\Cache::get('shop-exposure:error:'.$a->id));
        }
    }

    private function assertEvent(ShopKeywordAnalysis $a, string $kind): void
    {
        Log::shouldHaveReceived('info')->with('shop_exposure_block_event', \Mockery::on(fn ($e) =>
            array_keys($e) === ['ip', 'collector', 'analysis_id', 'core_keyword', 'kind', 'today_count', 'blocked_on_resume', 'retry_at', 'interval_ms']
            && $e['ip'] === '125.186.176.132' && $e['collector'] === 'browser' && $e['analysis_id'] === $a->id && $e['kind'] === $kind
            && ($kind === 'success' ? $e['retry_at'] === null : $e['retry_at'] === now('Asia/Seoul')->addMinutes(3)->toIso8601String())
        ))->once();
    }

    public function test_event_endpoint_rejects_other_owners_and_invalid_kinds(): void
    {
        $owner = User::factory()->create(['role' => 'operator']);
        $other = User::factory()->create(['role' => 'operator']);
        $a = ShopKeywordAnalysis::create(['user_id' => $owner->id, 'core_keyword' => '에어슈트', 'threshold' => 5]);
        $this->actingAs($other)->postJson(route('admin.shop-keyword.collector-event', $a), ['kind' => '403'])->assertForbidden();
        $this->actingAs($owner)->postJson(route('admin.shop-keyword.collector-event', $a), ['kind' => '500'])->assertUnprocessable();
    }
}
