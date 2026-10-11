<?php

namespace Tests\Feature;

use App\Domain\Place\PlaceRankChecker;
use App\Models\ApiKey;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\PlaceRankSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 플레이스 주문 → 플레이스 순위체크 자동 등록(2026-10-11): API(부스팅샵) · 명령(랭크프리 주문) · 중복 없음 · 자동 중단 슬롯은 하루 한 번만 다시 켬. */
class PlaceRankAutoRegisterTest extends TestCase
{
    use RefreshDatabase;

    private const PLACE = 'https://m.place.naver.com/place/1011101134/home';

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(PlaceRankChecker::class, function ($m): void {
            $m->shouldReceive('resolvePlaceId')->andReturn('1011101134');
            $m->shouldReceive('placeSummary')->andReturn(['name' => '테스트 매장', 'category' => '헬스장']);
            $m->shouldReceive('check')->andReturn(['blocked' => false, 'found' => true, 'rank' => 7, 'review_count' => 10, 'blog_review_count' => 5,
                'save_count' => 3, 'review_score' => 4.5, 'list_total' => 300, 'place_id' => '1011101134', 'place_name' => '테스트 매장', 'category' => '헬스장']);
        });
    }

    private function key(User $user): string
    {
        return ApiKey::issue($user, '부스팅샵', ['shop_keyword'], null, null, null)[1];
    }

    public function test_api_creates_slot_once_and_runs_first_check(): void
    {
        $user = User::factory()->create(['role' => 'super']);
        $plain = $this->key($user);
        $body = ['place' => self::PLACE, 'keywords' => ['풍동헬스'], 'label' => '부스팅샵 56181'];

        $this->withToken($plain)->postJson('/api/v1/place-rank/slots', $body)->assertCreated()
            ->assertJsonPath('slots.0.status', 'created')->assertJsonPath('slots.0.last_rank', 7);
        $this->withToken($plain)->postJson('/api/v1/place-rank/slots', $body)->assertCreated()->assertJsonPath('slots.0.status', 'exists');

        $this->assertSame(1, PlaceRankSlot::where('user_id', $user->id)->count());
        $this->assertDatabaseCount('place_rank_records', 1);
    }

    public function test_stopped_slot_is_reactivated_once_a_day(): void
    {
        $user = User::factory()->create(['role' => 'super']);
        $plain = $this->key($user);
        $slot = PlaceRankSlot::create(['user_id' => $user->id, 'keyword' => '풍동헬스', 'place_id' => '1011101134', 'place_name' => '테스트 매장',
            'place_url' => self::PLACE, 'category' => '헬스장', 'share_token' => 'tok', 'is_active' => false, 'last_checked_at' => now()->subDays(2)]);
        $body = ['place' => self::PLACE, 'keywords' => ['풍동헬스']];

        $this->withToken($plain)->postJson('/api/v1/place-rank/slots', $body)->assertCreated()->assertJsonPath('slots.0.status', 'reactivated');
        $this->assertTrue((bool) $slot->fresh()->is_active);

        // 오늘 확인하고 다시 자동 중단된 슬롯은 내일까지 그대로 둔다(켜고 끄기 반복 방지)
        $slot->fresh()->update(['is_active' => false, 'last_checked_at' => now()]);
        $this->withToken($plain)->postJson('/api/v1/place-rank/slots', $body)->assertCreated()->assertJsonPath('slots.0.status', 'exists');
        $this->assertFalse((bool) $slot->fresh()->is_active);
    }

    public function test_command_registers_processing_place_orders_only(): void
    {
        $owner = User::factory()->create(['role' => 'super']);
        config(['rankfree.shop_rank_auto_user_id' => $owner->id]);
        $buyer = User::factory()->create();
        $product = MarketingProduct::create(['product_type' => 'REWARD', 'title' => '플레이스 유입', 'quantity_mode' => 'daily',
            'base_cost' => 0, 'min_price' => 100, 'min_quantity' => 1, 'max_quantity' => 10000, 'min_days' => 1, 'is_active' => true]);
        $make = fn (string $status, array $fv) => MarketingOrder::create(['product_id' => $product->id, 'user_id' => $buyer->id,
            'quantity' => 10, 'days' => 3, 'unit_price' => 100, 'total_price' => 3000, 'status' => $status,
            'orderer_name' => '주문자', 'orderer_contact' => 't@t.kr', 'field_values' => $fv]);
        $place = ['keyword' => '풍동헬스', 'place_url' => self::PLACE];
        $make('processing', $place);
        $make('pending', ['keyword' => '일산헬스', 'place_url' => self::PLACE]);
        $make('processing', ['keyword' => '강아지 사료', 'shop_url' => 'https://smartstore.naver.com/x/products/1234567']);
        $make('processing', ['keyword' => '기간끝', 'place_url' => self::PLACE, 'start_date' => '2026-07-28', 'end_date' => '2026-07-30']);

        $this->artisan('orders:place-rank-sync')->assertSuccessful();
        $this->artisan('orders:place-rank-sync')->assertSuccessful();   // 두 번 돌려도 하나

        $this->assertSame(1, PlaceRankSlot::count());
        $this->assertDatabaseHas('place_rank_slots', ['user_id' => $owner->id, 'keyword' => '풍동헬스', 'place_id' => '1011101134', 'is_active' => true]);
    }
}
