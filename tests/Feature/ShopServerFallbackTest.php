<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Jobs\ShopKeywordCheckJob;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopServerFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_blocks_never_dispatch_or_remove_browser_pending_items(): void
    {
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.slack.notifications.bot_user_oauth_token' => null]);
        $u = User::factory()->create(['role' => 'operator']);
        $this->actingAs($u);
        foreach (['403', '429', '보안문자'] as $kind) {
            $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '브라우저', 'status' => 'checking', 'created_via' => 'api', 'check_method' => 'search', 'threshold' => 5]);
            $item = $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => $kind]);
            $this->postJson(route('admin.shop-keyword.collector-event', $a), ['kind' => $kind])->assertOk()->assertJsonPath('server_fallback', false);
            $this->assertFalse(Cache::has('shop-exposure:server-fallback:'.$a->id));
            $this->getJson(route('admin.shop-keyword.pending', $a))->assertOk()->assertJsonPath('data.items.0.id', $item->id);
            app(\App\Domain\Shopping\ShopExposureRunLock::class)->release('ip:127.0.0.1', $a->id);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_stale_flags_and_queued_takeovers_cannot_trigger_server_collection(): void
    {
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        $u = User::factory()->create(['role' => 'operator']);
        foreach (['api', 'search'] as $method) {
            $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '과거 이관', 'status' => 'checking', 'check_method' => $method]);
            $item = $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '보안문자']);
            Cache::forever('shop-exposure:server-fallback:'.$a->id, true);
            $this->assertFalse(ShopKeywordExposureAnalyzer::usesServerFallback($a));
            $analyzer = app(ShopKeywordExposureAnalyzer::class);
            (new ShopKeywordCheckJob($a->id))->handle($analyzer);
            (new ShopKeywordCheckJob($a->id, serverOnly: true))->handle($analyzer);
            $analyzer->checkBatch($a);
            $this->assertNull($item->fresh()->rank);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
