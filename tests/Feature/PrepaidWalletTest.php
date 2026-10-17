<?php

namespace Tests\Feature;

use App\Domain\Order\OrderInputException;
use App\Domain\Order\OrderPlacer;
use App\Domain\Order\PrepaidWallet;
use App\Models\ApiKey;
use App\Models\AppSetting;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\Topup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrepaidWalletTest extends TestCase
{
    use RefreshDatabase;

    private function capture(string $name, string $html): void
    {
        if ($dir = getenv('RF_SCREENSHOT_DIR')) {
            file_put_contents($dir.'/'.$name.'.html', $html);
        }
    }

    private function user(string $role = 'user'): User
    {
        return User::create(['name' => '충전자', 'email' => uniqid().'@test.invalid', 'password' => 'test-password', 'role' => $role, 'api_scopes' => ['order']]);
    }

    private function product(): MarketingProduct
    {
        return MarketingProduct::create(['product_type' => 'REWARD', 'title' => '충전 주문 상품', 'quantity_mode' => 'total', 'base_cost' => 0, 'min_price' => 1000, 'min_quantity' => 1, 'max_quantity' => 100, 'min_days' => 1, 'is_active' => true]);
    }

    public function test_topup_confirmation_is_once_and_audited(): void
    {
        $user = $this->user();
        $admin = $this->user('super');
        AppSetting::write('bank.name', '테스트은행');
        AppSetting::write('bank.account', 'TEST-ACCOUNT');
        $this->actingAs($user)->post('/wallet/topups', ['amount' => 5000, 'depositor' => '충전자'])->assertRedirect()->assertSessionHasNoErrors();
        $topup = Topup::firstOrFail();
        $this->assertSame(0, (int) $user->fresh()->prepaid_balance);
        $this->actingAs($user)->post('/admin/topups/'.$topup->id.'/review', ['status' => 'confirmed'])->assertForbidden();
        $adminPage = $this->actingAs($admin)->get('/admin/topups')->assertOk()->assertSee($topup->code);
        $this->capture('admin-confirm', $adminPage->getContent());
        $this->post('/admin/topups/'.$topup->id.'/review', ['status' => 'confirmed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/admin/topups/'.$topup->id.'/review', ['status' => 'confirmed'])->assertSessionHasErrors('topup');
        $this->assertSame(5000, (int) $user->fresh()->prepaid_balance);
        $this->assertSame($admin->id, (int) $topup->fresh()->reviewed_by);
        $this->assertDatabaseCount('wallet_entries', 1);
        $walletPage = $this->actingAs($user)->get('/wallet')->assertOk()->assertViewHas('balance', 5000)->assertSee('5,000');
        $this->capture('topup', $walletPage->getContent());
    }

    public function test_web_debits_vat_and_insufficient_order_is_rolled_back(): void
    {
        $user = $this->user();
        $product = $this->product();
        app(PrepaidWallet::class)->change($user->id, 1100, 'test:fund', '테스트 충전');
        $this->actingAs($user)->post('/order/'.$product->order_token, ['quantity' => 1])->assertRedirect()->assertSessionHas('order_prepaid', true);
        $done = $this->get('/order/'.$product->order_token)->assertOk()->assertSee('잔액에서 1,100원을 차감');
        $this->capture('order-debit', $done->getContent());
        $this->assertSame(0, (int) $user->fresh()->prepaid_balance);
        $order = MarketingOrder::firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertDatabaseHas('wallet_entries', ['reference' => 'order:'.$order->id, 'amount' => -1100]);
        $this->actingAs($this->user('super'))->put('/admin/orders/'.$order->id.'/info', ['quantity' => 2])->assertSessionHasErrors('quantity');
        $this->actingAs($user);
        $this->post('/order/'.$product->order_token, ['quantity' => 1])->assertSessionHasErrors('balance');
        $this->assertDatabaseCount('marketing_orders', 1);
        $this->get('/order/'.$product->order_token)->assertOk()->assertSee('잔액·충전·내역');
    }

    public function test_cancel_reject_and_delete_refund_only_once_and_legacy_is_unchanged(): void
    {
        $user = $this->user();
        $admin = $this->user('super');
        $product = $this->product();
        $wallet = app(PrepaidWallet::class);
        $wallet->change($user->id, 3300, 'test:fund', '충전');
        foreach (['canceled', 'rejected'] as $status) {
            $order = app(OrderPlacer::class)->place($user, $product, [], ['quantity' => 1, 'prepaid' => true]);
            $this->actingAs($admin)->put('/admin/orders/'.$order->id.'/status', ['status' => $status])->assertSessionHasNoErrors();
            $this->put('/admin/orders/'.$order->id.'/status', ['status' => $status])->assertSessionHasNoErrors();
            $this->put('/admin/orders/'.$order->id.'/status', ['status' => 'processing'])->assertSessionHasErrors('status');
            $this->post('/admin/orders/'.$order->id.'/approve')->assertSessionHasErrors('status');
            $this->delete('/admin/orders/'.$order->id)->assertRedirect('/admin/orders');
            $this->assertSame(3300, (int) $user->fresh()->prepaid_balance);
        }
        $legacy = app(OrderPlacer::class)->place($user, $product, [], ['quantity' => 1]);
        $this->put('/admin/orders/'.$legacy->id.'/status', ['status' => 'processing'])->assertSessionHasNoErrors();
        $this->put('/admin/orders/'.$legacy->id.'/status', ['status' => 'canceled'])->assertSessionHasNoErrors();
        $this->assertSame(3300, (int) $user->fresh()->prepaid_balance);
    }

    public function test_api_owner_with_zero_balance_can_still_order(): void
    {
        $user = $this->user();
        $product = $this->product();
        [, $key] = ApiKey::issue($user, '테스트', ['order'], null, null, null);
        $this->withHeader('Authorization', 'Bearer '.$key)->postJson('/api/v1/orders', ['product_id' => $product->id, 'quantity' => 1])->assertSuccessful();
        $this->assertSame(0, (int) $user->fresh()->prepaid_balance);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_rejected_topup_and_other_users_history(): void
    {
        $user = $this->user();
        $admin = $this->user('super');
        $other = $this->user();
        $topup = Topup::create(['user_id' => $user->id, 'amount' => 2000, 'depositor' => '비공개입금자', 'code' => 'RF-PRIVATE']);
        app(PrepaidWallet::class)->review($topup, $admin, 'rejected');
        $this->assertSame(0, (int) $user->fresh()->prepaid_balance);
        $this->actingAs($other)->get('/wallet')->assertOk()->assertDontSee('RF-PRIVATE');
        $this->post('/wallet/topups', ['amount' => -1, 'depositor' => '실패'])->assertSessionHasErrors('amount');
    }

    public function test_second_debit_sees_current_balance_even_with_stale_user(): void
    {
        $user = $this->user();
        $product = $this->product();
        $wallet = app(PrepaidWallet::class);
        $wallet->change($user->id, 1100, 'test:fund', '충전');
        $placer = app(OrderPlacer::class);
        $placer->place($user, $product, [], ['quantity' => 1, 'prepaid' => true]);
        try {
            $placer->place($user, $product, [], ['quantity' => 1, 'prepaid' => true]);
            $this->fail('Must reject second debit');
        } catch (OrderInputException $e) {
            $this->assertSame('balance',$e->field);
        }
        $this->assertSame(0,(int) $user->fresh()->prepaid_balance);
        $this->assertDatabaseCount('marketing_orders',1);
    }
}
