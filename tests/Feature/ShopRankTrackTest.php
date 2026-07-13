<?php

namespace Tests\Feature;

use App\Models\ShopRankSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopRankTrackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rankfree.shopping.api_keys' => [['id' => 'a', 'secret' => 'b']],
            'rankfree.shopping.max_pages' => 1, 'rankfree.shopping.page_delay_ms' => 0]);
    }

    private function fakeShop(string $productId = '1234567', int $ok = 200): void
    {
        Http::fake(['*/v1/search/shop.json*' => Http::response(['total' => 42, 'items' => [
            ['productId' => '111', 'title' => 'A', 'mallName' => 'm1', 'lprice' => '1000', 'link' => 'x', 'image' => ''],
            ['productId' => $productId, 'title' => '내 상품', 'mallName' => '내몰', 'lprice' => '19900', 'link' => 'http://x/'.$productId, 'image' => ''],
        ]], $ok)]);
    }

    public function test_index_renders(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('console.shop-rank'))->assertOk()->assertSee('쇼핑 순위추적');
    }

    public function test_store_creates_slots_and_runs_first_check(): void
    {
        $this->fakeShop('1234567');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('console.shop-rank.store'), [
            'target' => 'https://smartstore.naver.com/x/products/1234567',
            'keywords' => ['강아지 사료', '고양이 사료'],
            'label' => '신상',
        ])->assertRedirect(route('console.shop-rank'));

        $this->assertDatabaseCount('shop_rank_slots', 2);
        $this->assertDatabaseHas('shop_rank_slots', ['keyword' => '강아지 사료', 'product_id' => '1234567', 'target_type' => 'product', 'last_rank' => 2]);
        // 생성 직후 첫 순위 기록(2슬롯) — rank 2 발견
        $this->assertDatabaseCount('shop_rank_records', 2);
        $this->assertDatabaseHas('shop_rank_records', ['rank' => 2, 'price' => 19900]);
    }

    public function test_store_rejects_duplicate_keyword(): void
    {
        $this->fakeShop();
        $user = User::factory()->create();
        $payload = ['target' => 'https://smartstore.naver.com/x/products/1234567', 'keywords' => ['강아지 사료']];
        $this->actingAs($user)->post(route('console.shop-rank.store'), $payload);
        $this->actingAs($user)->post(route('console.shop-rank.store'), $payload); // 중복
        $this->assertDatabaseCount('shop_rank_slots', 1);
    }

    public function test_run_returns_json(): void
    {
        $this->fakeShop('999');
        $user = User::factory()->create();
        $slot = ShopRankSlot::create([
            'user_id' => $user->id, 'keyword' => '사료', 'target_type' => 'product', 'product_id' => '999',
            'share_token' => 'tok'.$user->id, 'is_active' => true,
        ]);

        $this->actingAs($user)->postJson(route('console.shop-rank.run', $slot))
            ->assertOk()->assertJson(['ok' => true, 'found' => true, 'rank' => 2]);
    }

    public function test_run_forbidden_for_other_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $slot = ShopRankSlot::create(['user_id' => $owner->id, 'keyword' => 'k', 'target_type' => 'mall', 'mall_name' => 'm', 'is_active' => true]);
        $this->actingAs($other)->post(route('console.shop-rank.run', $slot))->assertForbidden();
    }

    public function test_share_page_public(): void
    {
        $user = User::factory()->create();
        $slot = ShopRankSlot::create([
            'user_id' => $user->id, 'keyword' => '사료', 'target_type' => 'product', 'product_id' => '1', 'product_title' => '내 상품',
            'share_token' => 'publicshoptoken123', 'is_active' => true,
        ]);
        $this->get(route('shop-rank.shared', 'publicshoptoken123'))->assertOk()->assertSee('내 상품');
    }
}
