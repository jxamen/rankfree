<?php

namespace Tests\Feature;

use App\Domain\Order\OrderInputException;
use App\Domain\Order\OrderPlacer;
use App\Domain\Order\PrepaidWallet;
use App\Http\Controllers\Admin\MarketingOrderController;
use App\Models\ApiKey;
use App\Models\Coupon;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\OrderDispatch;
use App\Models\ProductVendor;
use App\Models\Topup;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Review evidence only. All data is SQLite :memory:; no real external HTTP. */
class PrepaidDispatchReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $member;
    private User $admin;
    private MarketingProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Bus::fake();
        Http::preventStrayRequests();
        Http::fake(['vendor.test.invalid/*' => Http::response(['ok' => true], 200)]);
        $this->member = User::create(['name' => 'Review member', 'email' => 'review-member@test.invalid', 'password' => 'password', 'api_scopes' => ['order']]);
        $this->admin = User::create(['name' => 'Review admin', 'email' => 'review-admin@test.invalid', 'password' => 'password', 'role' => 'super']);
        $this->product = MarketingProduct::create(['product_type' => 'REWARD', 'title' => 'Review product', 'quantity_mode' => 'total', 'base_cost' => 0, 'min_price' => 1000, 'min_quantity' => 1, 'max_quantity' => 100, 'min_days' => 1, 'is_active' => true]);
    }

    private function addVendor(): void
    {
        $vendor = Vendor::create(['name' => 'Review fake vendor', 'channel' => 'api', 'api_url' => 'https://vendor.test.invalid/orders', 'api_method' => 'POST', 'is_active' => true]);
        ProductVendor::create(['product_id' => $this->product->id, 'vendor_id' => $vendor->id, 'alloc_type' => 'ratio', 'alloc_value' => 100, 'sort_order' => 0, 'is_active' => true]);
    }

    private function coupon()
    {
        $coupon = Coupon::create(['name' => 'Review 5000', 'discount_type' => 'amount', 'discount_value' => 5000, 'is_active' => true]);
        return $coupon->userCoupons()->create(['user_id' => $this->member->id, 'source' => 'admin']);
    }

    public function test_v1_api_preserves_the_previous_status_set(): void
    {
        [, $key] = ApiKey::issue($this->member, 'Review key', ['order'], null, null, null);
        $response = $this->withHeader('Authorization', 'Bearer '.$key)->postJson('/api/v1/orders', ['product_id' => $this->product->id, 'quantity' => 1]);
        $response->assertCreated()->assertJsonPath('order.status', 'pending');
        $order = MarketingOrder::firstOrFail();
        $this->actingAs($this->admin)->put('/admin/orders/'.$order->id.'/status', ['status' => 'rejected'])->assertSessionHasNoErrors();
        $result = $this->actingAs($this->member)->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/v1/orders/'.$order->order_no)->assertOk();
        $this->assertSame(0, (int) $this->member->fresh()->prepaid_balance);
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertContains($result->json('order.status'), ['pending', 'processing', 'completed', 'canceled'], 'API v1 exposes a new status after admin rejection.');
    }

    public function test_cancellation_between_guard_and_vendor_send_must_prevent_dispatch(): void
    {
        $this->addVendor();
        app(PrepaidWallet::class)->change($this->member->id, 1100, 'review:fund', 'Review fund');
        $order = app(OrderPlacer::class)->place($this->member, $this->product, [], ['quantity' => 1, 'prepaid' => true]);
        // Deterministic interleaving of request B after request A passed its guard,
        // before A inserts its dispatch or invokes the fake vendor HTTP endpoint.
        OrderDispatch::creating(function (OrderDispatch $dispatch) use ($order) {
            app(MarketingOrderController::class)->updateStatus(Request::create('/review-cancel', 'PUT', ['status' => 'canceled']), $order->fresh());
            $this->assertSame('canceled', $order->fresh()->status);
            $this->assertSame(1100, (int) $this->member->fresh()->prepaid_balance);
        });
        $this->actingAs($this->admin)->post('/admin/orders/'.$order->id.'/place')->assertSessionHasNoErrors();
        $evidence = ['balance' => (int) $this->member->fresh()->prepaid_balance, 'status' => $order->fresh()->status, 'refund_entries' => DB::table('wallet_entries')->where('reference', 'refund:'.$order->id)->count(), 'sent_dispatches' => $order->dispatches()->where('status', 'sent')->count()];
        $this->assertSame(['balance' => 1100, 'status' => 'canceled', 'refund_entries' => 1, 'sent_dispatches' => 0], $evidence, 'A refunded order was dispatched and restored to processing.');
    }

    public function test_in_flight_dispatch_blocks_refund_and_delete(): void
    {
        $this->addVendor();
        app(PrepaidWallet::class)->change($this->member->id, 1100, 'review:fund', 'Review fund');
        $order = app(OrderPlacer::class)->place($this->member, $this->product, [], ['quantity' => 1, 'prepaid' => true]);
        Http::fake(function () use ($order) {
            $this->assertSame('dispatching', $order->fresh()->status);
            foreach (['canceled', 'pending'] as $status) {
                try {
                    app(MarketingOrderController::class)->updateStatus(Request::create('/review', 'PUT', ['status' => $status]), $order->fresh());
                    $this->fail('In-flight order must be protected');
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $this->assertArrayHasKey('status', $e->errors());
                }
            }
            return Http::response(['ok' => true]);
        });
        $this->actingAs($this->admin)->post('/admin/orders/'.$order->id.'/place')->assertSessionHasNoErrors();
        $this->assertSame('processing', $order->fresh()->status);
        $this->put('/admin/orders/'.$order->id.'/status', ['status' => 'canceled'])->assertSessionHasErrors('status');
        $this->delete('/admin/orders/'.$order->id)->assertSessionHasErrors('status');
        $this->assertSame(0, (int) $this->member->fresh()->prepaid_balance);
        $this->assertDatabaseMissing('wallet_entries', ['reference' => 'refund:'.$order->id]);
    }

    public function test_direct_scheduled_item_cannot_send_canceled_order(): void
    {
        $this->addVendor();
        $order = app(OrderPlacer::class)->place($this->member, $this->product, [], ['quantity' => 1]);
        $order->update(['status' => 'canceled']);
        $item = $order->items()->create(['day_no' => 1, 'quantity' => 1, 'work_date' => today(), 'status' => 'pending']);
        $result = app(\App\Domain\Order\OrderDispatchService::class)->dispatchItem($item);
        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
        $this->assertSame('canceled', $order->fresh()->status);
    }

    public function test_api_list_and_filters_map_internal_states(): void
    {
        [, $key] = ApiKey::issue($this->member, 'Review key', ['order'], null, null, null);
        foreach (['rejected', 'dispatching'] as $status) {
            $order = app(OrderPlacer::class)->place($this->member, $this->product, [], ['quantity' => 1]);
            $order->update(['status' => $status]);
        }
        $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/v1/orders?status=canceled')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('orders.0.status', 'canceled')->assertJsonPath('orders.0.status_label', '취소');
        $this->getJson('/api/v1/orders?status=processing')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('orders.0.status', 'processing');
        $this->getJson('/api/v1/orders?status=rejected')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_coupon_vat_refund_and_old_cancel_preserve_reused_coupon(): void
    {
        $coupon = $this->coupon();
        $wallet = app(PrepaidWallet::class);
        $wallet->change($this->member->id, 5500, 'review:fund', 'Review fund');
        $first = app(OrderPlacer::class)->place($this->member, $this->product, [], ['quantity' => 10, 'user_coupon_id' => $coupon->id, 'prepaid' => true]);
        $this->assertSame(5000, (int) $first->total_price);
        $this->assertDatabaseHas('wallet_entries', ['reference' => 'order:'.$first->id, 'amount' => -5500]);
        $this->assertSame(0, (int) $this->member->fresh()->prepaid_balance);
        $this->actingAs($this->admin)->put('/admin/orders/'.$first->id.'/status', ['status' => 'canceled'])->assertSessionHasNoErrors();
        $this->assertSame(5500, (int) $this->member->fresh()->prepaid_balance);
        $second = app(OrderPlacer::class)->place($this->member, $this->product, [], ['quantity' => 10, 'user_coupon_id' => $coupon->id, 'prepaid' => true]);
        $this->put('/admin/orders/'.$first->id.'/status', ['status' => 'canceled'])->assertSessionHasNoErrors();
        $this->delete('/admin/orders/'.$first->id)->assertRedirect('/admin/orders');
        $this->assertSame($second->id, $coupon->fresh()->order_id);
        $this->assertNotNull($coupon->fresh()->used_at);
        $this->assertSame(0, (int) $this->member->fresh()->prepaid_balance);
        $this->assertSame(1, DB::table('wallet_entries')->where('reference', 'refund:'.$first->id)->count());
    }

    public function test_insufficient_balance_rolls_back_order_coupon_and_ledger(): void
    {
        $coupon = $this->coupon();
        app(PrepaidWallet::class)->change($this->member->id, 5499, 'review:fund', 'Review fund');
        try {
            app(OrderPlacer::class)->place($this->member, $this->product, [], ['quantity' => 10, 'user_coupon_id' => $coupon->id, 'prepaid' => true]);
            $this->fail('Must reject insufficient balance');
        } catch (OrderInputException $e) {
            $this->assertSame('balance', $e->field);
        }
        $this->assertSame(5499, (int) $this->member->fresh()->prepaid_balance);
        $this->assertNull($coupon->fresh()->used_at);
        $this->assertNull($coupon->fresh()->order_id);
        $this->assertDatabaseCount('marketing_orders', 0);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_legacy_order_edit_approve_cancel_at_zero_balance(): void
    {
        $this->addVendor();
        $order = app(OrderPlacer::class)->place($this->member, $this->product, [], ['quantity' => 1]);
        $this->actingAs($this->admin)->put('/admin/orders/'.$order->id.'/info', ['quantity' => 2])->assertSessionHasNoErrors();
        $this->post('/admin/orders/'.$order->id.'/approve')->assertSessionHasNoErrors();
        $this->assertSame('processing', $order->fresh()->status);
        $this->put('/admin/orders/'.$order->id.'/status', ['status' => 'canceled'])->assertSessionHasNoErrors();
        $this->assertSame(0, (int) $this->member->fresh()->prepaid_balance);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_admin_topup_missing_csrf_token_is_rejected_outside_test_bypass(): void
    {
        $topup = Topup::create(['user_id' => $this->member->id, 'amount' => 5000, 'depositor' => 'Review', 'code' => 'RF-REVIEW-CSRF']);
        $this->actingAs($this->admin);
        $this->app->instance('env', 'local');
        try {
            $this->post('/admin/topups/'.$topup->id.'/review', ['status' => 'confirmed'])->assertStatus(419);
            $this->assertSame('pending', $topup->fresh()->status);
            $this->assertSame(0, (int) $this->member->fresh()->prepaid_balance);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_topup_authorization_validation_and_double_confirmation(): void
    {
        $topup = Topup::create(['user_id' => $this->member->id, 'amount' => 5000, 'depositor' => 'Review', 'code' => 'RF-REVIEW-ONCE']);
        $this->post('/admin/topups/'.$topup->id.'/review', ['status' => 'confirmed'])->assertRedirect('/login');
        $this->actingAs($this->member)->post('/admin/topups/'.$topup->id.'/review', ['status' => 'confirmed'])->assertForbidden();
        $this->actingAs($this->admin)->post('/admin/topups/'.$topup->id.'/review', ['status' => 'arbitrary'])->assertSessionHasErrors('status');
        $this->post('/admin/topups/'.$topup->id.'/review', ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->post('/admin/topups/'.$topup->id.'/review', ['status' => 'confirmed'])->assertSessionHasErrors('topup');
        $this->post('/admin/topups/'.$topup->id.'/review', ['status' => 'rejected'])->assertSessionHasErrors('topup');
        $this->assertSame(5000, (int) $this->member->fresh()->prepaid_balance);
        $this->assertSame('confirmed', $topup->fresh()->status);
        $this->assertSame($this->admin->id, $topup->fresh()->reviewed_by);
        $this->assertNotNull($topup->fresh()->reviewed_at);
        $this->assertDatabaseCount('wallet_entries', 1);
    }
}
