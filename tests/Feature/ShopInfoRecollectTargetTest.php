<?php

namespace Tests\Feature;

use App\Models\ShopKeywordAnalysis;
use App\Models\ShopProductInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopInfoRecollectTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_recollection_after_target_saves_shipping_without_restarting_search(): void
    {
        Http::preventStrayRequests();
        $u = User::factory()->create(['role' => 'operator']);
        $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '사료',
            'product_id' => '111', 'product_title' => '강아지 사료', 'product_url' => 'https://smartstore.naver.com/s/products/111',
            'threshold' => 5, 'keyword_target' => 1, 'status' => 'target_reached', 'combo_count' => 1]);
        $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '강아지 사료', 'rank' => 1, 'checked_at' => now()]);
        foreach ([3000, 0, null] as $i => $fee) {
            $this->actingAs($u)->postJson(route('admin.shop-keyword.product-info', $a), ['info' => [
                'channel_product_id' => '111', 'title' => '강아지 사료', 'price' => 10000,
                'seller_tags' => ['새로운태그'.$i], 'delivery_fee' => $fee,
            ]])->assertOk()->assertJsonPath('data.combo_added', 0);
            $this->assertSame($fee ?? 0, ShopProductInfo::sole()->delivery_fee);
            $this->assertSame('target_reached', $a->fresh()->status);
            $this->assertSame(1, $a->combos()->count());
        }
        Http::assertNothingSent();
    }
}
