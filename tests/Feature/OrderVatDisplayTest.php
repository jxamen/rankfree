<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\ProductField;
use App\Models\User;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 주문 페이지 부가세 표기 — 상품가·주문 total_price 는 공급가액(부가세 별도)이고,
 * 고객에게 보이는 결제·입금 금액은 부가세 10% 를 더한 값이어야 한다.
 */
class OrderVatDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'user'): User
    {
        return User::create(['name' => '주문자', 'email' => 'vat'.uniqid().'@rf.kr', 'password' => 'x1234567', 'role' => $role]);
    }

    private function makeProduct(User $admin): MarketingProduct
    {
        $product = MarketingProduct::create([
            'product_type' => 'REWARD', 'sub_type_code' => 'NAVER_SHOP_QUIZ', 'title' => '쇼핑 유입',
            'base_cost' => 100, 'min_price' => 100, 'min_quantity' => 1, 'order_token' => 'tk'.uniqid(),
            'is_active' => true, 'created_by' => $admin->id,
        ]);
        ProductField::create([
            'product_id' => $product->id, 'field_key' => 'keyword', 'label' => '키워드',
            'field_type' => 'TEXT', 'is_required' => true, 'sort_order' => 0, 'is_active' => true,
        ]);

        return $product;
    }

    public function test_vat_helper_splits_supply_and_tax(): void
    {
        $this->assertSame(10000, Vat::of(100000));
        $this->assertSame(110000, Vat::total(100000));
        // 원 단위 절사 — 공급가액 + 부가세가 결제 금액과 항상 일치
        $this->assertSame(33, Vat::of(333));
        $this->assertSame(366, Vat::total(333));
        $this->assertSame(0, Vat::total(0));
    }

    public function test_order_form_shows_supply_vat_and_rate(): void
    {
        $admin = $this->makeUser('super');
        $product = $this->makeProduct($admin);

        $res = $this->actingAs($this->makeUser())->get('/order/'.$product->order_token);

        $res->assertOk()
            ->assertSee('예상 결제 금액')
            ->assertSee('공급가액')
            ->assertSee('부가세')
            ->assertSee('부가세 포함')
            ->assertSee('(부가세 별도)')          // 단가 표기
            ->assertSee('id="o-supply"', false)
            ->assertSee('id="o-vat"', false)
            ->assertSee('data-vat="0.1"', false); // JS 실시간 계산이 쓰는 부가세율
    }

    public function test_order_done_notice_shows_vat_added_deposit_amount(): void
    {
        $admin = $this->makeUser('super');
        $product = $this->makeProduct($admin);
        $user = $this->makeUser();

        AppSetting::write('bank.name', 'KB국민은행');
        AppSetting::write('bank.account', '123-456-7890');

        $res = $this->actingAs($user)
            ->withSession(['order_done' => 'ORD-1', 'order_amount' => 100000])
            ->get('/order/'.$product->order_token);

        $res->assertOk()
            ->assertSee('입금 금액')
            ->assertSee('110,000원')     // 공급가액 100,000 + 부가세 10,000
            ->assertSee('100,000원')     // 공급가액
            ->assertSee('10,000원')      // 부가세
            ->assertSee('부가세 (10%)');
    }

    public function test_order_history_amount_includes_vat(): void
    {
        $admin = $this->makeUser('super');
        $product = $this->makeProduct($admin);
        $user = $this->makeUser();

        MarketingOrder::create([
            'product_id' => $product->id, 'user_id' => $user->id, 'quantity' => 10,
            'unit_price' => 100, 'total_price' => 1000, 'status' => 'pending',
            'orderer_name' => '주문자', 'orderer_contact' => 't@t.kr',
            'field_values' => ['keyword' => '장롱'],
        ]);

        $res = $this->actingAs($user)->get('/order/'.$product->order_token);

        // 표의 결제 금액은 부가세 포함(1,100원), 상세는 공급가액·부가세로 분해
        $res->assertOk()
            ->assertSee('1,100원')
            ->assertSee('결제 금액')
            ->assertSee('부가세 (10%)');
    }
}
