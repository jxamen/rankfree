<?php

namespace Tests\Feature;

use App\Domain\Place\PlaceRankChecker;
use App\Domain\Place\RankSlotService;
use App\Domain\Shopping\NaverShoppingRankService;
use App\Domain\Shopping\ShopRankSlotService;
use App\Models\PlaceRankRecord;
use App\Models\PlaceRankSlot;
use App\Models\ShopRankRecord;
use App\Models\ShopRankSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 순위추적 결함 수정 회귀 테스트 —
 * ① NaPm 등 추적 파라미터 붙은 긴 상품 URL 등록(22001 Data too long 재발 방지: 쿼리스트링 제거)
 * ② 차단(전 키 429) 시 그날의 유효 순위 기록을 -1 로 덮지 않음(쇼핑) / 미기록(플레이스)
 */
class RankTrackFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rankfree.shopping.api_keys' => [['id' => 'a', 'secret' => 'b']],
            'rankfree.shopping.max_pages' => 1, 'rankfree.shopping.page_delay_ms' => 0]);
    }

    public function test_resolve_target_strips_tracking_query_from_url(): void
    {
        $long = 'https://smartstore.naver.com/wooriteam/products/13330996895'
            .'?NaPm=ct%3Dmrsmpsi0%7Cci%3D'.str_repeat('a', 300).'&nl-query=%ED%86%A0%EC%8A%A4';
        $t = app(NaverShoppingRankService::class)->resolveTarget($long);

        $this->assertSame('13330996895', $t['product_id']);
        $this->assertSame('https://smartstore.naver.com/wooriteam/products/13330996895', $t['url']);
    }

    public function test_resolve_target_caps_mall_name_at_column_length(): void
    {
        $t = app(NaverShoppingRankService::class)->resolveTarget(str_repeat('가', 300));

        $this->assertSame(150, mb_strlen($t['mall_name']));
    }

    public function test_store_accepts_long_tracking_url_with_multiple_keywords(): void
    {
        Http::fake(['*/v1/search/shop.json*' => Http::response(['total' => 1, 'items' => [
            ['productId' => '13330996895', 'title' => '단말기', 'mallName' => '우리팀', 'lprice' => '50000', 'link' => 'x', 'image' => ''],
        ]], 200)]);
        $user = User::factory()->create();
        $long = 'https://smartstore.naver.com/wooriteam/products/13330996895?NaPm='.str_repeat('b', 350);

        $this->actingAs($user)->post(route('console.shop-rank.store'), [
            'target' => $long,
            'keywords' => ['토스단말기', '카드단말기'],
        ])->assertRedirect(route('console.shop-rank'));

        $this->assertDatabaseCount('shop_rank_slots', 2);
        $this->assertDatabaseHas('shop_rank_slots', [
            'keyword' => '토스단말기',
            'product_url' => 'https://smartstore.naver.com/wooriteam/products/13330996895',
        ]);
    }

    public function test_not_found_after_key_rotation_is_not_blocked(): void
    {
        config(['rankfree.shopping.api_keys' => [['id' => 'a', 'secret' => 'b'], ['id' => 'c', 'secret' => 'd']],
            'rankfree.shopping.display' => 2]);

        // 첫 키는 429, 두 번째 키는 정상 — 대상은 결과에 없음(순위권 밖)
        Http::fake(function ($request) {
            if ($request->header('X-Naver-Client-Id')[0] === 'a') {
                return Http::response(['errorMessage' => 'quota'], 429);
            }

            return Http::response(['total' => 1, 'items' => [
                ['productId' => '111', 'title' => 'A', 'mallName' => 'm', 'lprice' => '1', 'link' => 'x', 'image' => ''],
            ]], 200);
        });

        $res = app(NaverShoppingRankService::class)
            ->checkRank('킹크랩', ['type' => 'product', 'product_id' => '999', 'mall_name' => '', 'url' => '']);

        // 두 번째 키로 전 범위 확인 완료 — 차단(-1)이 아니라 '순위권 밖'(rank 0)
        $this->assertFalse($res['blocked']);
        $this->assertFalse($res['found']);
        $this->assertSame(0, $res['rank']);
    }

    public function test_shop_blocked_keeps_todays_valid_record(): void
    {
        Http::fake(['*/v1/search/shop.json*' => Http::response(['errorMessage' => 'quota'], 429)]);
        $user = User::factory()->create();
        $slot = ShopRankSlot::create([
            'user_id' => $user->id, 'keyword' => '킹크랩', 'target_type' => 'product',
            'product_id' => '111', 'share_token' => Str::random(32), 'is_active' => true, 'last_rank' => 7,
        ]);
        ShopRankRecord::create(['slot_id' => $slot->id, 'checked_date' => now()->toDateString(), 'rank' => 7]);

        $res = app(ShopRankSlotService::class)->run($slot);

        $this->assertSame(7, $res['stored_rank']);
        $this->assertDatabaseHas('shop_rank_records', ['slot_id' => $slot->id, 'rank' => 7]);
        $this->assertDatabaseMissing('shop_rank_records', ['slot_id' => $slot->id, 'rank' => ShopRankSlotService::RANK_BLOCKED]);
        $this->assertSame(7, $slot->fresh()->last_rank); // -1 로 격하되지 않음
    }

    public function test_shop_blocked_records_sentinel_when_no_valid_record(): void
    {
        Http::fake(['*/v1/search/shop.json*' => Http::response(['errorMessage' => 'quota'], 429)]);
        $user = User::factory()->create();
        $slot = ShopRankSlot::create([
            'user_id' => $user->id, 'keyword' => '킹크랩', 'target_type' => 'product',
            'product_id' => '111', 'share_token' => Str::random(32), 'is_active' => true,
        ]);

        app(ShopRankSlotService::class)->run($slot);

        $this->assertDatabaseHas('shop_rank_records', ['slot_id' => $slot->id, 'rank' => ShopRankSlotService::RANK_BLOCKED]);
    }

    public function test_place_blocked_does_not_write_record(): void
    {
        $this->mock(PlaceRankChecker::class, function ($m) {
            $m->shouldReceive('check')->andReturn([
                'blocked' => true, 'found' => false, 'rank' => 0, 'list_total' => 0,
                'category' => '', 'place_id' => '', 'place_name' => '',
                'review_count' => null, 'blog_review_count' => null, 'save_count' => null,
                'review_score' => null, 'tags' => [],
            ]);
        });
        $user = User::factory()->create();
        $slot = PlaceRankSlot::create([
            'user_id' => $user->id, 'keyword' => '인천한방병원', 'place_id' => '123',
            'place_name' => '테스트병원', 'category' => 'hospital',
            'share_token' => Str::random(32), 'is_active' => true,
        ]);
        PlaceRankRecord::create(['slot_id' => $slot->id, 'checked_date' => now()->toDateString(), 'rank' => 3]);

        app(RankSlotService::class)->run($slot);

        // 차단 결과는 당일 유효 기록(3위)을 건드리지 않고, 확인 시각만 남긴다
        $this->assertSame(1, PlaceRankRecord::where('slot_id', $slot->id)->count());
        $this->assertSame(3, (int) PlaceRankRecord::where('slot_id', $slot->id)->value('rank'));
        $this->assertNotNull($slot->fresh()->last_checked_at);
    }
}
