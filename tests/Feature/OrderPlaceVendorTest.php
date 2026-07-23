<?php

namespace Tests\Feature;

use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\ProductField;
use App\Models\ProductVendor;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 주문 목록 '주문넣기'(2026-07-23) — 필수값 검증 후 매핑 업체 발주. 비면 경고·차단. */
class OrderPlaceVendorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MarketingProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['vendor.example.com/*' => Http::response(['ok' => true], 200)]);
        $this->admin = User::factory()->create(['role' => 'operator']);
        $this->product = MarketingProduct::create([
            'product_type' => 'REWARD', 'title' => '발주 테스트 상품', 'quantity_mode' => 'total',
            'base_cost' => 0, 'min_price' => 100, 'min_quantity' => 1, 'max_quantity' => 1000, 'min_days' => 1, 'is_active' => true,
        ]);
        // 필수 필드 2개 — keyword(고객 입력) + mall_name(숨김·자동수집)
        ProductField::create(['product_id' => $this->product->id, 'field_key' => 'keyword', 'field_type' => 'TEXT',
            'label' => '검색 키워드', 'is_required' => true, 'sort_order' => 0, 'is_active' => true]);
        ProductField::create(['product_id' => $this->product->id, 'field_key' => 'mall_name', 'field_type' => 'TEXT',
            'label' => '상점명', 'is_required' => true, 'sort_order' => 1, 'is_active' => true]);
    }

    private function makeOrder(array $fieldValues, string $status = 'pending'): MarketingOrder
    {
        return MarketingOrder::create([
            'product_id' => $this->product->id, 'user_id' => $this->admin->id,
            'quantity' => 10, 'field_values' => $fieldValues, 'unit_price' => 100, 'total_price' => 1000,
            'status' => $status, 'orderer_name' => 'x', 'orderer_contact' => 'x@x.kr',
        ]);
    }

    private function addVendor(): void
    {
        $vendor = Vendor::create(['name' => '테스트업체', 'channel' => 'api',
            'api_url' => 'https://vendor.example.com/orders', 'api_method' => 'POST', 'is_active' => true]);
        ProductVendor::create(['product_id' => $this->product->id, 'vendor_id' => $vendor->id,
            'alloc_type' => 'ratio', 'alloc_value' => 100, 'sort_order' => 0, 'is_active' => true]);
    }

    public function test_missing_required_values_block_placement(): void
    {
        $this->addVendor();
        $order = $this->makeOrder(['keyword' => '중고컴퓨터', 'mall_name' => '']);   // 상점명 미수집

        $res = $this->actingAs($this->admin)->post(route('admin.orders.place', $order));

        $res->assertSessionHasErrors('place');
        $this->assertStringContainsString('상점명', session('errors')->first('place'));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('order_dispatches', 0);
    }

    public function test_no_vendor_allocation_blocks_placement(): void
    {
        $order = $this->makeOrder(['keyword' => '중고컴퓨터', 'mall_name' => '루나텍']);

        $this->actingAs($this->admin)->post(route('admin.orders.place', $order))
            ->assertSessionHasErrors('place');
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_complete_order_dispatches_to_mapped_vendor(): void
    {
        $this->addVendor();
        $order = $this->makeOrder(['keyword' => '중고컴퓨터', 'mall_name' => '루나텍']);

        $res = $this->actingAs($this->admin)->post(route('admin.orders.place', $order));

        $res->assertSessionHasNoErrors();
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertDatabaseHas('order_dispatches', ['order_id' => $order->id, 'status' => 'sent', 'quantity' => 10]);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'vendor.example.com'));
    }

    public function test_non_pending_order_blocked(): void
    {
        $this->addVendor();
        $order = $this->makeOrder(['keyword' => 'x', 'mall_name' => 'y'], 'processing');

        $this->actingAs($this->admin)->post(route('admin.orders.place', $order))
            ->assertSessionHasErrors('place');
        $this->assertDatabaseCount('order_dispatches', 0);
    }
}
