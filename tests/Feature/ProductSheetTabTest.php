<?php

namespace Tests\Feature;

use App\Domain\Order\OrderDispatchService;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\ProductVendor;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 상품 단위 구글시트 탭(2026-07-22) — 같은 업체를 쓰는 상품끼리 다른 탭으로 발주.
 * 배분(product_vendors.sheet_tab) 오버라이드 → 업체 기본(gsheet_tab) 폴백, 저장·전송 전 구간.
 */
class ProductSheetTabTest extends TestCase
{
    use RefreshDatabase;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        // 키 파일은 형식만 갖추고(readKey 통과), 실제 서명은 건너뛰도록 토큰을 캐시에 선주입한다
        // (Windows PHP 는 openssl.cnf 부재로 키 생성이 안 됨 — Cache::remember 가 캐시 히트면 서명 클로저 미실행)
        $this->keyFile = tempnam(sys_get_temp_dir(), 'gsa');
        file_put_contents($this->keyFile, json_encode(['client_email' => 'test@sa.iam', 'private_key' => 'dummy']));
        config(['services.google_sheets.credentials' => $this->keyFile]);
        \Illuminate\Support\Facades\Cache::put(
            'google-sa-token:'.md5('https://www.googleapis.com/auth/spreadsheets'), 'test-token', 600
        );

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token'], 200),
            'sheets.googleapis.com/*' => Http::response(['sheets' => [['properties' => ['title' => '첫탭']]], 'ok' => true], 200),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    private function makeOrder(?string $allocTab, string $vendorTab = '업체탭'): MarketingOrder
    {
        $admin = User::create(['name' => 'a', 'email' => uniqid().'@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
        $vendor = Vendor::create(['name' => '시트업체', 'channel' => 'gsheet', 'gsheet_id' => 'SHEET-ID', 'gsheet_tab' => $vendorTab, 'is_active' => true]);
        $product = MarketingProduct::create([
            'product_type' => 'REWARD', 'title' => '탭테스트 상품', 'base_cost' => 100, 'min_price' => 100,
            'min_quantity' => 1, 'order_token' => 'tt'.uniqid(), 'is_active' => true, 'created_by' => $admin->id,
        ]);
        ProductVendor::create([
            'product_id' => $product->id, 'vendor_id' => $vendor->id,
            'alloc_type' => 'ratio', 'alloc_value' => 100, 'sheet_tab' => $allocTab, 'sort_order' => 0, 'is_active' => true,
        ]);

        return MarketingOrder::create([
            'product_id' => $product->id, 'user_id' => $admin->id, 'quantity' => 10, 'days' => 1,
            'unit_price' => 100, 'total_price' => 1000, 'status' => 'pending',
            'orderer_name' => '주문자', 'orderer_contact' => '010', 'field_values' => [],
        ]);
    }

    public function test_dispatch_uses_product_level_sheet_tab(): void
    {
        $order = $this->makeOrder('상품전용탭');
        app(OrderDispatchService::class)->dispatch($order);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sheets.googleapis.com')
            && str_contains($r->url(), rawurlencode("'상품전용탭'!A1")));
    }

    public function test_dispatch_falls_back_to_vendor_tab_when_alloc_tab_empty(): void
    {
        $order = $this->makeOrder(null);
        app(OrderDispatchService::class)->dispatch($order);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sheets.googleapis.com')
            && str_contains($r->url(), rawurlencode("'업체탭'!A1")));
    }

    public function test_product_save_persists_alloc_sheet_tab_without_touching_vendor(): void
    {
        $order = $this->makeOrder(null);
        $product = $order->product;
        $vendor = Vendor::first();
        $admin = User::first();

        $this->actingAs($admin)->put(route('admin.products.update', $product), [
            'product_type' => 'REWARD', 'title' => $product->title, 'base_cost' => 100, 'min_price' => 100,
            'min_quantity' => 1, 'max_quantity' => 100, 'min_days' => 1, 'is_active' => 1,
            'quantity_mode' => 'daily', 'default_fulfillment' => 100, 'field_render_mode' => 'inline',
            'fields_json' => '[]',
            'vendors_json' => json_encode([[
                'vendor_id' => $vendor->id, 'alloc_type' => 'ratio', 'alloc_value' => 100,
                'is_active' => true, 'map' => [], 'sheet_tab' => '상품전용탭',
            ]]),
        ])->assertRedirect();

        $this->assertSame('상품전용탭', ProductVendor::first()->sheet_tab);
        $this->assertSame('업체탭', $vendor->fresh()->gsheet_tab, '업체 기본 탭은 그대로여야 한다(타 상품 무영향)');
    }
}
