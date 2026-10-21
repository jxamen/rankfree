<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopAnalysisReuse;
use App\Domain\Shopping\ShopKeywordShortLinkService;
use App\Http\Controllers\Api\ExtShopKeywordController;
use App\Models\ApiKey;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopProductInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BoostProductRecollectTest extends TestCase
{
    use RefreshDatabase;

    public function test_reused_order_recollects_new_product_without_losing_links_or_changing_other_order(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create(['api_scopes' => ['shop_keyword']]);
        [, $key] = ApiKey::issue($user, 'isolated-test', ['shop_keyword'], null, null, null);
        $this->withToken($key);
        $source = ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '시험',
            'product_id' => '1234567', 'product_url' => 'https://smartstore.naver.com/test/products/1234567',
            'product_title' => '이전 상품', 'status' => 'done', 'threshold' => 5]);
        $source->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '기존 키워드', 'rank' => 1, 'checked_at' => now()]);
        $source->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '기존 키워드2', 'rank' => 2, 'checked_at' => now()]);
        ShopProductInfo::create(['user_id' => $user->id, 'channel_product_id' => '1234567',
            'title' => '이전 상품', 'price' => 1000, 'seller_tags' => ['이전태그'], 'delivery_fee' => 3000, 'collected_at' => now()->subDay()]);
        $reuse = app(ShopAnalysisReuse::class);
        $order = $reuse->copyLatest($user, $source->product_url, '시험', 'boosting:1', []);
        $other = $reuse->copyLatest($user, $source->product_url, '시험', 'boosting:2', []);
        $otherBefore = $other->fresh()->getAttributes();
        $links = app(ShopKeywordShortLinkService::class)->generate($order, 2);
        $links->first()->update(['hit_count' => 7]);
        $linksBefore = $order->shortLinks()->orderBy('id')->get()->toArray();
        $itemsBefore = $order->items()->orderBy('id')->get()->toArray();
        $this->getJson('/api/v1/shop-keywords/'.$order->id)->assertOk()->assertJsonPath('analysis.product.title', '이전 상품');
        $r = $this->postJson('/api/v1/shop-keywords/'.$order->id.'/recollect-product')->assertOk();
        $previous = $r->json('collected_at');
        $this->postJson('/api/v1/shop-keywords/'.$order->id.'/recollect-product')->assertOk();
        $this->getJson('/api/v1/shop-keywords?source_order=boosting:1')->assertOk()->assertJsonPath('analyses.0.id', $order->id);
        $this->getJson('/api/v1/shop-keywords/'.$order->id)->assertJsonPath('analysis.product.title', '이전 상품');
        $this->travel(2)->seconds();
        $request = Request::create('/test', 'POST', ['info' => ['channel_product_id' => '1234567',
            'title' => '새 상품', 'price' => 2000, 'mall_name' => '새 상점', 'delivery_fee' => 0,
            'seller_tags' => ['새태그'], 'thumbnail_url' => 'https://example.com/new.jpg', 'category' => '새 분류']]);
        $request->setUserResolver(fn () => $user);
        $saved = app(ExtShopKeywordController::class)->productInfo($request, $order->fresh());
        $this->assertSame(200, $saved->getStatusCode());
        $after = $this->getJson('/api/v1/shop-keywords/'.$order->id)->assertOk()
            ->assertJsonPath('analysis.product.title', '새 상품')->assertJsonPath('analysis.product.delivery_fee', 0)
            ->assertJsonPath('analysis.product.seller_tags.0', '새태그')->assertJsonPath('analysis.product.price', 2000)
            ->assertJsonPath('analysis.product_collection.state', 'completed')->assertJsonPath('analysis.status', 'done');
        $this->assertNotSame($previous, $after->json('analysis.product.collected_at'));
        $this->assertSame('boosting:1', $order->fresh()->boostMeta()['source_order']);
        $this->assertSame('새 상품', $order->fresh()->product_title);
        $this->assertSame($linksBefore, $order->shortLinks()->orderBy('id')->get()->toArray());
        $this->assertSame($itemsBefore, $order->items()->orderBy('id')->get()->toArray());
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $this->getJson('/api/v1/shop-keywords/'.$other->id)->assertJsonPath('analysis.product.title', '이전 상품');
        $this->getJson('/api/v1/shop-keywords?source_order=boosting:1')->assertJsonPath('analyses.0.id', $order->id);
        Http::assertNothingSent();
    }
}
