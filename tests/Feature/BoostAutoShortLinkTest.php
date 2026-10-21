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

class BoostAutoShortLinkTest extends TestCase
{
    use RefreshDatabase;

    private function loginApi(): User
    {
        Http::fake();
        $user = User::factory()->create(['api_scopes' => ['shop_keyword']]);
        [, $key] = ApiKey::issue($user, 'isolated-test', ['shop_keyword'], null, null, null);
        $this->withToken($key);
        return $user;
    }

    public function test_start_creates_period_links_before_collection_and_retry_preserves_them(): void
    {
        $this->loginApi();
        $body = ['source_order' => 'boosting:991', 'core_keyword' => '시험',
            'product' => 'https://smartstore.naver.com/test/products/1234567',
            'order_start_date' => '2026-10-21', 'order_end_date' => '2026-10-25', 'auto_short_links' => true];
        $response = $this->postJson('/api/v1/shop-keywords', $body)->assertCreated()
            ->assertJsonPath('analysis.status', 'pending')->assertJsonCount(5, 'short_links');
        $analysis = ShopKeywordAnalysis::findOrFail($response->json('analysis.id'));
        $before = $analysis->shortLinks()->orderBy('group_no')->get();
        $this->assertSame([], $before->pluck('keywords')->flatten()->all());
        $this->assertSame(5, $analysis->order_days);
        $this->assertSame(250, $analysis->keyword_target);
        $this->postJson('/api/v1/shop-keywords', $body)->assertOk()->assertJsonCount(5, 'short_links');
        $this->assertSame($before->pluck('token')->all(), $analysis->shortLinks()->orderBy('group_no')->pluck('token')->all());
        $this->get(route('shop-keyword.short', $before->first()->token))->assertOk()->assertSee('키워드를 준비하고 있어요');
        $body['source_order'] = 'boosting:992';
        $body['order_end_date'] = '2026-10-22';
        $this->postJson('/api/v1/shop-keywords', $body)->assertCreated()->assertJsonCount(2, 'short_links');
        $body['source_order'] = 'boosting:994';
        $body['order_end_date'] = '2026-10-21';
        $this->postJson('/api/v1/shop-keywords', $body)->assertCreated()->assertJsonCount(1, 'short_links');
        $count = ShopKeywordAnalysis::count();
        $body['source_order'] = 'boosting:995';
        $body['order_end_date'] = '2027-03-21';
        $this->postJson('/api/v1/shop-keywords', $body)->assertUnprocessable();
        $this->assertSame($count, ShopKeywordAnalysis::count());
        Http::assertNothingSent();
    }

    public function test_discovered_keywords_append_in_order_without_changing_urls_hits_or_manual_assignments(): void
    {
        Http::fake();
        $analysis = ShopKeywordAnalysis::create(['user_id' => User::factory()->create()->id,
            'core_keyword' => '시험', 'product_id' => '1234567', 'status' => 'checking', 'threshold' => 5,
            'keyword_target' => 250, 'order_days' => 5, 'check_method' => 'search']);
        $service = app(ShopKeywordShortLinkService::class);
        $links = $service->ensureInitialLinks($analysis);
        $tokens = $links->pluck('token')->all();
        $links->first()->update(['hit_count' => 7, 'cursor' => 2]);
        $this->mock(NaverShopExposureService::class, function ($mock) {
            $mock->shouldReceive('rankFromHtml')->andReturnUsing(fn ($html) => ['rank' => (int) $html]);
        });
        $analyzer = app(ShopKeywordExposureAnalyzer::class);
        $items = [];
        // Leave one pending item: completion deliberately reshuffles the temporary assignment.
        for ($i = 1; $i <= 10; $i++) $items[] = $analysis->items()->create([
            'kind' => 'combo', 'source' => 'test', 'keyword' => '키워드 '.$i]);
        foreach (array_slice($items, 0, 7) as $item) $analyzer->applyHtml($analysis, $item, '3');
        $analyzer->applyHtml($analysis, $items[7], '6');
        $expected = [['키워드 1', '키워드 6'], ['키워드 2', '키워드 7'], ['키워드 3'], ['키워드 4'], ['키워드 5']];
        $links = $analysis->shortLinks()->orderBy('group_no')->get();
        $this->assertSame($expected, $links->pluck('keywords')->all());
        $this->assertSame($tokens, $links->pluck('token')->all());
        $this->assertSame(7, $links->first()->hit_count);
        $this->assertSame(2, $links->first()->cursor);
        $analyzer->applyHtml($analysis, $items[0], '3');
        $this->assertSame($expected, $service->appendDiscoveredKeywords($analysis)->pluck('keywords')->all());
        $random = $service->reassign($analysis)->pluck('keywords')->all();
        $this->assertSame($random, $service->appendDiscoveredKeywords($analysis)->pluck('keywords')->all());
        $analyzer->applyHtml($analysis, $items[8], '5');
        $after = $analysis->shortLinks()->orderBy('group_no')->get();
        $random[2][] = '키워드 9';
        $this->assertSame($random, $after->pluck('keywords')->all());
        $this->assertCount(8, array_unique($after->pluck('keywords')->flatten()->all()));
        $this->get(route('shop-keyword.short', $tokens[0]))->assertRedirect();
        Http::assertNothingSent();
    }

    public function test_initialization_keeps_existing_link_count_and_other_analyses_untouched(): void
    {
        $user = $this->loginApi();
        $a = ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '시험', 'status' => 'checking', 'order_days' => 2]);
        $service = app(ShopKeywordShortLinkService::class);
        $before = $service->ensureInitialLinks($a)->toArray();
        $a->update(['order_days' => 5]);
        $this->assertSame($before, $service->ensureInitialLinks($a)->toArray());
        $other = ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '별도', 'status' => 'checking']);
        $other->items()->create(['kind' => 'combo', 'source' => 'test', 'keyword' => '별도 키워드', 'rank' => 1]);
        $this->assertCount(0, $service->appendDiscoveredKeywords($other));
        $this->assertSame($before, $a->shortLinks()->orderBy('group_no')->get()->toArray());
    }

    public function test_manual_import_still_copies_without_precreating_links(): void
    {
        $user = $this->loginApi();
        $a = ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '시험',
            'product_url' => 'https://smartstore.naver.com/test/products/1234567', 'product_title' => '시험 상품', 'status' => 'done']);
        $a->items()->create(['kind' => 'combo', 'source' => 'test', 'keyword' => '시험 상품', 'rank' => 1]);
        $r = $this->postJson('/api/v1/shop-keywords', ['source_order' => 'boosting:993', 'core_keyword' => '시험',
            'product' => $a->product_url, 'reuse_only' => true, 'auto_short_links' => true])->assertOk()->assertJsonPath('reuse_mode', 'copied');
        $this->assertSame(0, ShopKeywordAnalysis::findOrFail($r->json('analysis.id'))->shortLinks()->count());
        $this->assertSame(0, $a->shortLinks()->count());
        Http::assertNothingSent();
    }
}
