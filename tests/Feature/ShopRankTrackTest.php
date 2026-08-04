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
        // 구 shop.json 엔진 경로 검증 — 기본값은 확장 워커 큐다(shop.json 은 2026-07-31 폐지).
        config(["rankfree.shopping.rank_source" => "api"]);
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

    /** 미노출(순위 밖)만 기록되게 — 내 상품이 검색결과에 없는 응답. */
    private function fakeMiss(): void
    {
        Http::fake(['*/v1/search/shop.json*' => Http::response(['total' => 42, 'items' => [
            ['productId' => '111', 'title' => 'A', 'mallName' => 'm1', 'lprice' => '1000', 'link' => 'x', 'image' => ''],
        ]], 200)]);
    }

    private function missSlot(): ShopRankSlot
    {
        config(['rankfree.shopping.rank_source' => 'api']);   // 확장·서버 브라우저를 타지 않게

        return ShopRankSlot::create([
            'user_id' => User::factory()->create()->id, 'keyword' => 'kw', 'target_type' => 'product',
            'product_id' => '1234567', 'share_token' => 'stoptoken1234567890', 'is_active' => true,
        ]);
    }

    /**
     * 🔴 3일 연속 순위 밖이면 추적을 중단한다 — 그 전엔 끄지 않는다.
     * 하루라도 일찍 끄면 롤링(검색결과 회전)으로 잠깐 빠진 슬롯이 꺼진다.
     */
    public function test_3일_연속_순위_밖이면_추적이_중단된다(): void
    {
        $this->fakeMiss();
        $slot = $this->missSlot();
        $service = app(\App\Domain\Shopping\ShopRankSlotService::class);
        $base = now()->startOfDay()->addHours(9);

        foreach ([0, 1] as $d) {
            $this->travelTo($base->copy()->addDays($d));
            $service->run($slot->fresh());
            $this->assertTrue($slot->fresh()->is_active, ($d + 1).'일차엔 아직 중단하지 않는다');
        }

        $this->travelTo($base->copy()->addDays(2));
        $service->run($slot->fresh());
        $this->assertFalse($slot->fresh()->is_active, '3일 연속 순위 밖이면 중단한다');
    }

    /** 중간에 한 번이라도 순위가 잡히면 연속이 끊긴다 — 계속 추적해야 한다. */
    public function test_중간에_순위가_잡히면_중단하지_않는다(): void
    {
        $miss = ['total' => 42, 'items' => [
            ['productId' => '111', 'title' => 'A', 'mallName' => 'm1', 'lprice' => '1000', 'link' => 'x', 'image' => ''],
        ]];
        $hit = ['total' => 42, 'items' => [
            ['productId' => '111', 'title' => 'A', 'mallName' => 'm1', 'lprice' => '1000', 'link' => 'x', 'image' => ''],
            ['productId' => '1234567', 'title' => '내 상품', 'mallName' => '내몰', 'lprice' => '19900', 'link' => 'http://x/1', 'image' => ''],
        ]];
        // 미노출 → 노출 → 미노출. fake 를 다시 호출해도 같은 패턴은 처음 것이 이기므로 시퀀스로 준다.
        Http::fake(['*/v1/search/shop.json*' => Http::sequence()
            ->push($miss, 200)->push($hit, 200)->push($miss, 200)]);

        $slot = $this->missSlot();
        $service = app(\App\Domain\Shopping\ShopRankSlotService::class);
        $base = now()->startOfDay()->addHours(9);

        foreach ([0, 1, 2] as $d) {
            $this->travelTo($base->copy()->addDays($d));
            $service->run($slot->fresh());
        }

        $this->assertTrue($slot->fresh()->is_active, '연속 3일이 아니면 중단하지 않는다');
    }
}
