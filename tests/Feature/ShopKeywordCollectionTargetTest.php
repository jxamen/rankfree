<?php

namespace Tests\Feature;

use App\Domain\Shopping\NaverShopExposureService;
use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Domain\Shopping\ShopKeywordShortLinkService;
use App\Models\ApiKey;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopKeywordCollectionTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_batch_retries_blocked_item_with_three_minute_schedule_and_events(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        config(['services.slack.notifications.bot_user_oauth_token' => null]);
        Http::fake(['*' => Http::response('', 403)]);
        \Illuminate\Support\Facades\Log::spy();
        $u = User::factory()->create();
        $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '반복',
            'product_id' => '111', 'threshold' => 5, 'keyword_target' => 2,
            'check_method' => 'search', 'status' => 'checking', 'combo_count' => 1]);
        $item = $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '검색어']);
        for ($i = 0; $i < 4; $i++) {
            $p = app(ShopKeywordExposureAnalyzer::class)->checkBatch($a, 1);
            $this->assertTrue($p['blocked']);
            $this->assertGreaterThan(microtime(true) * 1000 + 179000, $p['retry_at']);
            $this->assertLessThanOrEqual(microtime(true) * 1000 + 180001, $p['retry_at']);
            $this->assertNull($item->fresh()->rank);
            $state = \Illuminate\Support\Facades\Cache::get(\App\Domain\Shopping\NaverShopExposureService::policyKey());
            $this->assertSame(3300 + 300 * $i, $state['gap']);
            $state['until'] = microtime(true) - 1;
            $state['last'] = microtime(true) - 11;
            \Illuminate\Support\Facades\Cache::put(\App\Domain\Shopping\NaverShopExposureService::policyKey(), $state);
        }
        Http::assertSentCount(4);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->with('shop_exposure_block_event', \Mockery::type('array'))->times(4);
    }

    public function test_stops_at_target_and_ignores_already_dispatched_results(): void
    {
        $user = User::factory()->create();
        $a = ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '테스트',
            'product_id' => '111', 'threshold' => 5, 'keyword_target' => 2, 'status' => 'checking', 'combo_count' => 4]);
        foreach (range(1, 4) as $i) {
            $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '검색어'.$i]);
        }
        $this->mock(NaverShopExposureService::class)->shouldReceive('rankFromHtml')->twice()->andReturn(['rank' => 3]);
        $svc = app(ShopKeywordExposureAnalyzer::class);
        $items = $a->combos()->orderBy('id')->get();
        $this->assertSame(3, $svc->applyHtml($a, $items[0], '')['remaining']);
        $p = $svc->applyHtml($a, $items[1], '');
        $this->assertSame('target_reached', $p['status']);
        $this->assertSame(0, $p['remaining']);
        $this->assertTrue($svc->applyHtml($a, $items[2], '')['skipped']);
        $this->assertNull($items[2]->fresh()->rank);
        $this->assertSame(2, $a->fresh()->topFiveCount());
        $this->assertCount(2, app(ShopKeywordShortLinkService::class)->exposedKeywords($a));
        $this->assertSame('목표 도달로 멈춤', $a->fresh()->collectionLabel());
    }

    public function test_sixth_place_does_not_count_and_existing_results_are_capped(): void
    {
        $u = User::factory()->create();
        $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '테스트', 'threshold' => 10, 'keyword_target' => 2]);
        foreach ([6, 5, 2, 3] as $i => $rank) {
            $a->items()->create([
                'kind' => 'combo', 'source' => 'combo', 'keyword' => '키워드'.$i, 'rank' => $rank, 'checked_at' => now()]);
        }
        $this->assertSame(3, $a->topFiveCount());
        $this->assertSame(['키워드1', '키워드2'], app(ShopKeywordShortLinkService::class)->exposedKeywords($a));
    }

    public function test_order_dates_create_target_and_reuse_updates_metadata(): void
    {
        Http::fake();
        config(['rankfree.shopping.exposure.keywords_per_day' => 50]);
        $u = User::factory()->create(['api_scopes' => ['shop_keyword']]);
        [, $key] = ApiKey::issue($u, 'test', ['shop_keyword'], null, null, null);
        $this->withHeader('Authorization', 'Bearer '.$key);
        $data = ['core_keyword' => '테스트', 'product' => 'https://smartstore.naver.com/x/products/111',
            'order_start_date' => '2026-10-18', 'order_end_date' => '2026-10-22'];
        $res = $this->postJson('/api/v1/shop-keywords', $data)->assertCreated()
            ->assertJsonPath('analysis.order_days', 5)->assertJsonPath('analysis.keyword_target', 250);
        $this->postJson('/api/v1/shop-keywords', $data)->assertOk()->assertJsonPath('reused', true)
            ->assertJsonPath('analysis.id', $res->json('analysis.id'));
        $data['order_end_date'] = '2026-10-17';
        $this->postJson('/api/v1/shop-keywords', $data)->assertUnprocessable();
        unset($data['order_end_date']);
        $this->postJson('/api/v1/shop-keywords', $data)->assertUnprocessable();
    }

    public function test_five_day_url_groups_use_only_top_five_target_keywords(): void
    {
        $u = User::factory()->create();
        $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '검증', 'threshold' => 5,
            'order_days' => 5, 'keyword_target' => 250]);
        foreach (range(1, 255) as $i) {
            $a->items()->create([
                'kind' => 'combo', 'source' => 'combo', 'keyword' => '키워드'.$i, 'rank' => 3, 'checked_at' => now()]);
        }
        $links = app(ShopKeywordShortLinkService::class)->generate($a, 5);
        $this->assertCount(5, $links);
        $this->assertSame([50, 50, 50, 50, 50], $links->map(fn ($link) => count($link->keywords))->all());
        $this->assertNotContains('키워드251', $links->flatMap(fn ($link) => $link->keywords)->all());
    }
}
