<?php

namespace Tests\Feature;

use App\Domain\Order\OrderRankTracker;
use App\Domain\Order\RankTrackingExclusions;
use App\Domain\Place\RankSlotService;
use App\Domain\Shopping\ShopRankSlotService;
use App\Models\ApiKey;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\PlaceRankSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankTrackingExclusionsTest extends TestCase
{
    use RefreshDatabase;

    private string $policyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policyPath = tempnam(sys_get_temp_dir(), 'rank-policy-');
        file_put_contents($this->policyPath, json_encode(['boost_member_ids' => ['excluded-agency'], 'business_names' => ['Excluded Agency']]));
        config(['rank_tracking_exclusions.path' => $this->policyPath]);
        $this->app->instance(RankTrackingExclusions::class, new class extends RankTrackingExclusions
        {
            protected function boostingOrderMember(int $orderId): ?string
            {
                return $orderId === 123 ? 'excluded-agency' : 'other-agency';
            }
        });
    }

    protected function tearDown(): void
    {
        unlink($this->policyPath);
        parent::tearDown();
    }

    public function test_excluded_api_orders_cannot_create_or_reactivate_or_run_slots(): void
    {
        $user = User::factory()->create(['role' => 'super']);
        $key = ApiKey::issue($user, 'test', ['shop_keyword'], null, null, null)[1];
        $slot = PlaceRankSlot::create(['user_id' => $user->id, 'keyword' => 'test', 'place_id' => '1234567', 'share_token' => 'test', 'is_active' => false]);
        $this->mock(RankSlotService::class)->shouldNotReceive('ensureTracked');
        $this->mock(ShopRankSlotService::class)->shouldNotReceive('ensureTracked');
        foreach (['place' => 'place', 'shop' => 'target'] as $route => $field) {
            $this->withToken($key)->postJson('/api/v1/'.$route.'-rank/slots', [$field => 'https://example.com/1234567', 'keywords' => ['test'], 'label' => '부스팅샵 123'])
                ->assertCreated()->assertJsonPath('excluded', true)->assertJsonPath('slots', []);
        }
        $this->assertFalse($slot->fresh()->is_active);
        $this->assertDatabaseCount('place_rank_slots', 1);
        $this->assertDatabaseCount('shop_rank_slots', 0);
        $this->assertDatabaseCount('place_rank_records', 0);
        $this->assertDatabaseCount('shop_rank_records', 0);
    }

    public function test_other_order_and_manual_labels_are_not_excluded(): void
    {
        $policy = app(RankTrackingExclusions::class);
        $this->assertTrue($policy->excludesBoostingLabel('부스팅샵 123'));
        $this->assertFalse($policy->excludesBoostingLabel('부스팅샵 124'));
        $this->assertFalse($policy->excludesBoostingLabel(null));
        $this->assertFalse($policy->excludesBoostingLabel('manual'));
    }

    public function test_native_order_event_and_both_syncs_skip_excluded_customer_without_order_changes(): void
    {
        $owner = User::factory()->create(['role' => 'super']);
        config(['rankfree.shop_rank_auto_user_id' => $owner->id]);
        $buyer = User::factory()->create(['name' => 'Excluded Agency']);
        $product = MarketingProduct::create(['product_type' => 'REWARD', 'title' => 'test', 'quantity_mode' => 'daily', 'base_cost' => 0, 'min_price' => 100, 'min_quantity' => 1, 'max_quantity' => 10000, 'min_days' => 1, 'is_active' => true]);
        $this->mock(RankSlotService::class)->shouldNotReceive('addMany', 'ensureTracked', 'run');
        $this->mock(ShopRankSlotService::class)->shouldNotReceive('addMany', 'ensureTracked', 'run');
        foreach (['place_url', 'shop_url'] as $field) {
            $order = MarketingOrder::withoutEvents(fn () => MarketingOrder::create(['order_no' => 'EXCLUDE-'.$field, 'product_id' => $product->id, 'user_id' => $buyer->id, 'quantity' => 10, 'days' => 3, 'unit_price' => 100, 'total_price' => 3000, 'status' => 'processing', 'orderer_name' => 'test', 'orderer_contact' => 'test@example.com', 'field_values' => ['keyword' => 'test', $field => 'https://m.place.naver.com/place/1234567/home']]));
            $before = $order->fresh()->getRawOriginal();
            app(OrderRankTracker::class)->register($order);
            $this->artisan('orders:place-rank-sync')->assertSuccessful();
            $this->artisan('orders:shop-rank-sync')->assertSuccessful();
            $this->assertSame($before, $order->fresh()->getRawOriginal());
        }
        $this->assertDatabaseCount('place_rank_slots', 0);
        $this->assertDatabaseCount('shop_rank_slots', 0);
    }

    public function test_policy_changes_are_reversible_and_existing_member_id_alias_is_matched(): void
    {
        $order = new MarketingOrder(['user_id' => 99]);
        $order->setRelation('user', new User(['name' => 'unrelated', 'email' => 'excluded-agency@example.com']));
        $policy = app(RankTrackingExclusions::class);
        $this->assertTrue($policy->excludesOrder($order));
        file_put_contents($this->policyPath, '{}');
        $this->assertFalse($policy->excludesOrder($order));
        $this->assertFalse($policy->excludesBoostingLabel('부스팅샵 123'));
    }
}
