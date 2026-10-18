<?php

namespace Tests\Feature;

use App\Domain\Shopping\NaverShopExposureService;
use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Jobs\ShopKeywordCheckJob;
use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopServerFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_blocks_transfer_only_that_analysis_and_server_preserves_unchecked_items_during_cooldown(): void
    {
        Cache::flush();
        config(['services.slack.notifications.bot_user_oauth_token' => null]);
        $user = User::factory()->create(['role' => 'operator']);
        $this->actingAs($user);
        foreach (['403', '429', '보안문자'] as $kind) {
            $a = ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '테스트', 'status' => 'checking', 'check_method' => 'api', 'threshold' => 5, 'combo_count' => 1]);
            $item = $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '검색 '.$kind]);
            $this->postJson(route('admin.shop-keyword.collector-event', $a), ['kind' => 'success'])->assertJsonPath('server_fallback', false);
            $this->postJson(route('admin.shop-keyword.collector-event', $a), ['kind' => $kind])->assertJsonPath('server_fallback', true);
            $this->getJson(route('admin.shop-keyword.pending', $a))->assertJsonPath('data.server_fallback', true)->assertJsonPath('data.items', []);
            $this->postJson(route('admin.shop-keyword.check-html', $a), ['item_id' => $item->id, 'html' => ''])->assertJsonPath('skipped', true);
            $this->assertNull($item->fresh()->rank);
            Http::swap(new Factory);
            Http::fake(['*' => Http::response('', 403)]);
            Cache::forget(NaverShopExposureService::policyKey());
            $r = $this->postJson(route('admin.shop-keyword.check', $a))->assertOk()->assertJsonPath('error', 'http_403')->assertJsonPath('interval_ms', 2300);
            $this->assertGreaterThanOrEqual(time() * 1000 + 179000, $r->json('retry_at'));
            $this->postJson(route('admin.shop-keyword.check', $a))->assertJsonPath('error', 'collector_cooldown');
            Http::assertSentCount(1); // Includes no slot API or further network request during cooldown.
            $this->assertNull($item->fresh()->rank);
            $until = Cache::get(NaverShopExposureService::policyKey())['until'];
            $this->postJson(route('admin.shop-keyword.collector-event', $a), ['kind' => 'success'])->assertJsonPath('server_fallback', false);
            $this->assertSame($until, Cache::get(NaverShopExposureService::policyKey())['until']);
            $this->assertSame('checking', $a->fresh()->status);
            $state = Cache::get(NaverShopExposureService::policyKey());
            $state['until'] = 0;
            $state['last'] = 0;
            Cache::put(NaverShopExposureService::policyKey(), $state);
            Http::swap(new Factory);
            Http::fake(['*' => Http::response('<html></html>')]);
            $this->postJson(route('admin.shop-keyword.check', $a))->assertJsonPath('remaining', 0);
            $this->assertSame(0, $item->fresh()->rank);
        }
        $other = ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '다른 분석']);
        $this->assertFalse(ShopKeywordExposureAnalyzer::usesServerFallback($other));
    }

    public function test_api_analysis_dispatches_once_and_job_requeues_after_server_block(): void
    {
        Cache::flush();
        Queue::fake();
        config(['queue.default' => 'database', 'services.slack.notifications.bot_user_oauth_token' => null]);
        $u = User::factory()->create(['role' => 'operator']);
        $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '테스트', 'status' => 'checking', 'created_via' => 'api', 'check_method' => 'search', 'threshold' => 5, 'combo_count' => 1]);
        $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '서버 확인']);
        $this->actingAs($u);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson(route('admin.shop-keyword.collector-event', $a), ['kind' => '403'])->assertOk();
        }
        Queue::assertPushed(ShopKeywordCheckJob::class, 1);
        Http::fake(['*' => Http::response('', 429)]);
        (new ShopKeywordCheckJob($a->id))->handle(app(ShopKeywordExposureAnalyzer::class));
        Queue::assertPushed(ShopKeywordCheckJob::class, 2);
        Queue::assertPushed(ShopKeywordCheckJob::class, fn ($job) => $job->round === 2 && $job->delay->timestamp >= time() + 179);
        $this->assertSame('blocked', $a->fresh()->status);
        $a->update(['status' => 'paused']);
        (new ShopKeywordCheckJob($a->id, 2))->handle(app(ShopKeywordExposureAnalyzer::class));
        Http::assertSentCount(1);
    }

    public function test_extension_block_also_transfers_and_its_queue_stops_browser_work(): void
    {
        Cache::flush();
        Queue::fake();
        config(['queue.default' => 'database', 'services.slack.notifications.bot_user_oauth_token' => null]);
        $u = User::factory()->create(['role' => 'operator', 'api_scopes' => ['shop_keyword']]);
        $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '확장', 'status' => 'checking', 'created_via' => 'api']);
        $item = $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '확장 테스트']);
        [, $token] = ExtToken::issue($u);
        $this->withHeader('Authorization', 'Bearer '.$token);
        $this->postJson('/api/ext/shop-keyword/'.$a->id.'/collector-event', ['kind' => '429'])->assertJsonPath('server_fallback', true);
        Queue::assertPushed(ShopKeywordCheckJob::class, 1);
        $this->getJson('/api/ext/shop-keyword/check-queue')->assertJsonPath('data.server_fallback', true)->assertJsonPath('data.items', []);
        $this->postJson('/api/ext/shop-keyword/'.$a->id.'/check-html', ['item_id' => $item->id, 'html' => ''])->assertJsonPath('skipped', true);
        $this->assertNull($item->fresh()->rank);
    }
}
