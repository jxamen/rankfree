<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\ShopRankSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 쇼핑 주문 → 쇼핑 순위체크 자동 등록(2026-10-08): API(부스팅샵) · 명령(랭크프리 주문) · 중복 없음 · 꺼진 슬롯 다시 켬. */
class ShopRankAutoRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rankfree.shopping.api_keys' => [['id' => 'a', 'secret' => 'b']],
            'rankfree.shopping.max_pages' => 1, 'rankfree.shopping.page_delay_ms' => 0]);
        Http::fake(['*/v1/search/shop.json*' => Http::response(['total' => 42, 'items' => [
            ['productId' => '111', 'title' => 'A', 'mallName' => 'm1', 'lprice' => '1000', 'link' => 'x', 'image' => ''],
            ['productId' => '1234567', 'title' => '내 상품', 'mallName' => '내몰', 'lprice' => '19900', 'link' => 'http://x/1234567', 'image' => ''],
        ]], 200)]);
    }

    private function key(User $user): string
    {
        return ApiKey::issue($user, '부스팅샵', ['shop_keyword'], null, null, null)[1];
    }

    public function test_api_creates_slot_once_and_runs_first_check(): void
    {
        $user = User::factory()->create(['role' => 'super']);
        $plain = $this->key($user);
        $body = ['target' => 'https://smartstore.naver.com/x/products/1234567', 'keywords' => ['강아지 사료'], 'label' => '부스팅샵 56180'];

        $this->withToken($plain)->postJson('/api/v1/shop-rank/slots', $body)->assertCreated()
            ->assertJsonPath('slots.0.status', 'created');
        // 다시 보내도 새로 만들지 않는다
        $this->withToken($plain)->postJson('/api/v1/shop-rank/slots', $body)->assertCreated()->assertJsonPath('slots.0.status', 'exists');

        $this->assertSame(1, ShopRankSlot::where('user_id', $user->id)->count());
        // 첫 순위 — 바로 기록되거나(상위 20위 즉시 조회) 확장 작업 대기열에 들어간다
        $this->assertGreaterThanOrEqual(1, \App\Models\ShopRankRecord::count() + \App\Models\ShopRankJob::count());
    }

    public function test_api_reactivates_turned_off_slot(): void
    {
        $user = User::factory()->create(['role' => 'super']);
        $plain = $this->key($user);
        ShopRankSlot::create(['user_id' => $user->id, 'keyword' => '강아지 사료', 'target_type' => 'product', 'product_id' => '1234567',
            'product_url' => 'https://smartstore.naver.com/x/products/1234567', 'share_token' => 'tok', 'is_active' => false]);

        $this->withToken($plain)->postJson('/api/v1/shop-rank/slots', ['target' => 'https://smartstore.naver.com/x/products/1234567', 'keywords' => ['강아지 사료']])
            ->assertCreated()->assertJsonPath('slots.0.status', 'reactivated');

        $this->assertSame(1, ShopRankSlot::count());
        $this->assertTrue((bool) ShopRankSlot::first()->is_active);
    }

    public function test_command_registers_processing_shopping_orders_only(): void
    {
        $owner = User::factory()->create(['role' => 'super']);
        config(['rankfree.shop_rank_auto_user_id' => $owner->id]);
        $buyer = User::factory()->create();
        $product = MarketingProduct::create(['product_type' => 'REWARD', 'title' => '쇼핑 유입', 'quantity_mode' => 'daily',
            'base_cost' => 0, 'min_price' => 100, 'min_quantity' => 1, 'max_quantity' => 10000, 'min_days' => 1, 'is_active' => true]);
        $make = fn (string $status, array $fv) => MarketingOrder::create(['product_id' => $product->id, 'user_id' => $buyer->id,
            'quantity' => 10, 'days' => 3, 'unit_price' => 100, 'total_price' => 3000, 'status' => $status,
            'orderer_name' => '주문자', 'orderer_contact' => 't@t.kr', 'field_values' => $fv]);
        $shop = ['keyword' => '강아지 사료', 'shop_url' => 'https://smartstore.naver.com/x/products/1234567'];
        $make('processing', $shop);
        $make('pending', ['keyword' => '고양이 사료', 'shop_url' => 'https://smartstore.naver.com/x/products/7654321']);
        $make('processing', ['keyword' => '풍동헬스', 'place_url' => 'https://m.place.naver.com/place/1234567']);
        // 기간이 끝났는데 상태만 processing 으로 남은 주문은 뺀다
        $make('processing', ['keyword' => '오리 사료', 'shop_url' => 'https://smartstore.naver.com/x/products/5555555', 'start_date' => '2026-07-28', 'end_date' => '2026-07-30']);

        $this->artisan('orders:shop-rank-sync')->assertSuccessful();
        $this->artisan('orders:shop-rank-sync')->assertSuccessful();   // 두 번 돌려도 하나

        $this->assertSame(1, ShopRankSlot::count());
        $this->assertDatabaseHas('shop_rank_slots', ['user_id' => $owner->id, 'keyword' => '강아지 사료', 'product_id' => '1234567', 'is_active' => true]);
    }
}
