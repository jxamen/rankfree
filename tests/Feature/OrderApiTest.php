<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Coupon;
use App\Models\MarketingProduct;
use App\Models\ProductField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 외부 주문 API v1 (scope: order) — 상품 조회·주문 생성(고정값 강제·필드 검증·쿠폰)·조회. */
class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => '외부연동', 'email' => 'api@rankfree.kr', 'password' => 'secret1234']);
        [, $plain] = ApiKey::issue($this->user, '테스트', ['order'], null, null, null);
        $this->key = $plain;
    }

    private function api(string $method, string $uri, array $data = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->key)->json($method, $uri, $data);
    }

    private function makeProduct(array $attrs = [], array $fields = []): MarketingProduct
    {
        $p = MarketingProduct::create($attrs + [
            'product_type' => 'REWARD', 'title' => 'API 테스트 상품', 'quantity_mode' => 'total',
            'base_cost' => 0, 'min_price' => 1000, 'min_quantity' => 1, 'max_quantity' => 100,
            'min_days' => 1, 'is_active' => true,
        ]);
        foreach ($fields as $i => $f) {
            ProductField::create($f + [
                'product_id' => $p->id, 'field_key' => 'field_'.($i + 1), 'field_type' => 'TEXT',
                'label' => '필드'.($i + 1), 'is_required' => true, 'sort_order' => $i, 'is_active' => true,
            ]);
        }

        return $p->fresh('fields');
    }

    public function test_scope_required(): void
    {
        [, $plain] = ApiKey::issue($this->user, '스코프없음', ['rank'], null, null, null);

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/products')
            ->assertStatus(403);
    }

    public function test_products_list_and_detail(): void
    {
        $p = $this->makeProduct(['fixed_quantity' => 50], [
            ['field_key' => 'place_url', 'field_type' => 'URL', 'label' => '플레이스 URL'],
        ]);
        MarketingProduct::create(['product_type' => 'REWARD', 'title' => '비활성', 'quantity_mode' => 'total',
            'base_cost' => 0, 'min_price' => 10, 'min_quantity' => 1, 'max_quantity' => 10, 'min_days' => 1, 'is_active' => false]);

        $this->api('GET', '/api/v1/products')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $p->id)
            ->assertJsonPath('products.0.fixed_quantity', 50)
            ->assertJsonPath('products.0.orderable', true);

        $this->api('GET', "/api/v1/products/{$p->id}")
            ->assertOk()
            ->assertJsonPath('product.fields.0.key', 'place_url')
            ->assertJsonPath('product.fields.0.required', true);
    }

    public function test_required_file_product_not_orderable(): void
    {
        $p = $this->makeProduct([], [
            ['field_key' => 'banner', 'field_type' => 'IMAGE', 'label' => '배너 이미지'],
        ]);

        $this->api('GET', "/api/v1/products/{$p->id}")
            ->assertOk()->assertJsonPath('product.orderable', false);

        $this->api('POST', '/api/v1/orders', ['product_id' => $p->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonPath('field', 'product_id');
    }

    public function test_order_total_product(): void
    {
        $p = $this->makeProduct();

        $res = $this->api('POST', '/api/v1/orders', ['product_id' => $p->id, 'quantity' => 10]);

        $res->assertCreated()
            ->assertJsonPath('order.status', 'pending')
            ->assertJsonPath('order.quantity', 10)
            ->assertJsonPath('order.total_price', 10000);
        $this->assertDatabaseHas('marketing_orders', ['product_id' => $p->id, 'user_id' => $this->user->id, 'quantity' => 10]);
    }

    public function test_fixed_quantity_and_days_enforced(): void
    {
        $p = $this->makeProduct(['quantity_mode' => 'daily', 'fixed_quantity' => 100, 'fixed_days' => 7, 'min_price' => 100]);

        // 다른 값을 보내도 고정값으로 강제 — 100개 × 7일 × 100원 = 70,000
        $this->api('POST', '/api/v1/orders', ['product_id' => $p->id, 'quantity' => 1, 'days' => 1])
            ->assertCreated()
            ->assertJsonPath('order.quantity', 100)
            ->assertJsonPath('order.days', 7)
            ->assertJsonPath('order.total_price', 70000);
    }

    public function test_required_field_validation(): void
    {
        $p = $this->makeProduct([], [
            ['field_key' => 'memo', 'label' => '요청 메모'],
        ]);

        $this->api('POST', '/api/v1/orders', ['product_id' => $p->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonPath('field', 'f_memo');

        $this->api('POST', '/api/v1/orders', ['product_id' => $p->id, 'quantity' => 1, 'fields' => ['memo' => '요청사항']])
            ->assertCreated()
            ->assertJsonPath('order.fields.memo', '요청사항');
    }

    public function test_quantity_range_validation(): void
    {
        $p = $this->makeProduct(['min_quantity' => 5, 'max_quantity' => 10]);

        $this->api('POST', '/api/v1/orders', ['product_id' => $p->id, 'quantity' => 3])
            ->assertStatus(422)->assertJsonPath('field', 'quantity');
    }

    public function test_order_with_coupon(): void
    {
        $p = $this->makeProduct();
        $coupon = Coupon::create(['name' => 'API 5천원', 'discount_type' => 'amount', 'discount_value' => 5000, 'is_active' => true]);
        $uc = $coupon->userCoupons()->create(['user_id' => $this->user->id, 'source' => 'admin']);

        $this->api('POST', '/api/v1/orders', ['product_id' => $p->id, 'quantity' => 10, 'user_coupon_id' => $uc->id])
            ->assertCreated()
            ->assertJsonPath('order.discount_amount', 5000)
            ->assertJsonPath('order.total_price', 5000);
        $this->assertNotNull($uc->fresh()->used_at);

        // 같은 쿠폰 재사용 불가
        $this->api('POST', '/api/v1/orders', ['product_id' => $p->id, 'quantity' => 10, 'user_coupon_id' => $uc->id])
            ->assertStatus(422)->assertJsonPath('field', 'user_coupon_id');
    }

    public function test_order_list_and_show_scoped_to_owner(): void
    {
        $p = $this->makeProduct();
        $orderNo = $this->api('POST', '/api/v1/orders', ['product_id' => $p->id, 'quantity' => 2])->json('order.order_no');

        $this->api('GET', '/api/v1/orders')
            ->assertOk()->assertJsonCount(1, 'orders')->assertJsonPath('meta.total', 1);
        $this->api('GET', "/api/v1/orders/{$orderNo}")
            ->assertOk()->assertJsonPath('order.order_no', $orderNo);

        // 남의 키로는 404
        $other = User::create(['name' => '남', 'email' => 'other@rankfree.kr', 'password' => 'secret1234']);
        [, $otherKey] = ApiKey::issue($other, '남의키', ['order'], null, null, null);
        $this->withHeader('Authorization', 'Bearer '.$otherKey)->getJson("/api/v1/orders/{$orderNo}")
            ->assertStatus(404);
    }
}
