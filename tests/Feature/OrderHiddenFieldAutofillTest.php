<?php

namespace Tests\Feature;

use App\Domain\Order\OrderFieldAutofill;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\ProductField;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopProductInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 내부(숨김) 필드 + 유입키워드 수집값 자동 채움(2026-07-22) —
 * 빌더 저장 라운드트립, 고객 폼 미노출·미검증, autofill 서비스, 관리자 편집·다시 채우기, 승인 가드.
 */
class OrderHiddenFieldAutofillTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(User $admin): MarketingProduct
    {
        $product = MarketingProduct::create([
            'product_type' => 'REWARD', 'sub_type_code' => 'NAVER_SHOP_QUIZ', 'title' => '쇼핑 유입',
            'base_cost' => 100, 'min_price' => 100, 'min_quantity' => 1, 'order_token' => 'tk'.uniqid(),
            'is_active' => true, 'created_by' => $admin->id,
        ]);
        // 고객 입력 필드(키워드·URL) + 내부 필드(상점명=수집 매핑, 상품 이미지=수집 매핑, 메모=수동)
        foreach ([
            ['field_key' => 'keyword', 'label' => '키워드', 'field_type' => 'TEXT', 'is_required' => true],
            ['field_key' => 'shop_url', 'label' => '상품 URL', 'field_type' => 'TEXT', 'is_required' => true],
            ['field_key' => 'mall_name', 'label' => '상점명', 'field_type' => 'TEXT', 'is_required' => true, 'is_hidden' => true, 'autofill_source' => 'mall_name'],
            ['field_key' => 'thumb', 'label' => '상품 이미지 URL', 'field_type' => 'TEXT', 'is_required' => true, 'is_hidden' => true, 'autofill_source' => 'thumbnail_url'],
            ['field_key' => 'memo', 'label' => '내부 메모', 'field_type' => 'TEXT', 'is_required' => false, 'is_hidden' => true, 'default_value' => '기본메모'],
        ] as $i => $f) {
            ProductField::create($f + ['product_id' => $product->id, 'sort_order' => $i, 'is_active' => true]);
        }

        return $product;
    }

    private function makeAdmin(): User
    {
        return User::create(['name' => '관리자', 'email' => 'hf'.uniqid().'@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    private function makeOrder(User $user, MarketingProduct $product, array $fv = []): MarketingOrder
    {
        return MarketingOrder::create([
            'product_id' => $product->id, 'user_id' => $user->id, 'quantity' => 10,
            'unit_price' => 100, 'total_price' => 1000, 'status' => 'pending',
            'orderer_name' => '주문자', 'orderer_contact' => 't@t.kr',
            'field_values' => $fv + ['keyword' => '장롱', 'shop_url' => 'https://smartstore.naver.com/x/products/123'],
        ]);
    }

    private function makeAnalysis(User $user, ?MarketingOrder $order = null): ShopKeywordAnalysis
    {
        return ShopKeywordAnalysis::create([
            'user_id' => $user->id, 'marketing_order_id' => $order?->id,
            'core_keyword' => '장롱', 'product_url' => 'https://smartstore.naver.com/x/products/123',
            'product_id' => '123', 'mall_name' => '두둘리앙', 'product_title' => '원목 장롱',
            'status' => 'ready',
        ]);
    }

    public function test_builder_saves_hidden_and_autofill_roundtrip(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->makeProduct($admin);

        $this->actingAs($admin)->put(route('admin.products.update', $product), [
            'title' => $product->title, 'product_type' => 'REWARD', 'sub_type_code' => 'NAVER_SHOP_QUIZ',
            'base_cost' => 100, 'min_price' => 100, 'min_quantity' => 1, 'max_quantity' => 100000,
            'min_days' => 1, 'quantity_mode' => 'total', 'is_active' => 1, 'field_render_mode' => 'inline',
            'fields_json' => json_encode([
                ['field_key' => 'keyword', 'label' => '키워드', 'field_type' => 'TEXT', 'is_required' => true],
                ['field_key' => 'store', 'label' => '상점명', 'field_type' => 'TEXT', 'is_required' => true, 'is_hidden' => true, 'autofill' => 'mall_name'],
                ['field_key' => 'bad', 'label' => '잘못된 소스', 'field_type' => 'TEXT', 'is_hidden' => true, 'autofill' => 'nope'],
            ]),
        ])->assertSessionDoesntHaveErrors()->assertRedirect();

        $store = ProductField::where('product_id', $product->id)->where('field_key', 'store')->first();
        $this->assertTrue($store->is_hidden);
        $this->assertSame('mall_name', $store->autofill_source);
        // 지원하지 않는 autofill 소스는 무시(null)
        $bad = ProductField::where('product_id', $product->id)->where('field_key', 'bad')->first();
        $this->assertTrue($bad->is_hidden);
        $this->assertNull($bad->autofill_source);
        // 편집 화면 fields_json 에 다시 실려 나온다
        $html = $this->actingAs($admin)->get(route('admin.products.edit', $product))->assertOk()->getContent();
        $this->assertStringContainsString('"autofill":"mall_name"', $html);
    }

    public function test_customer_form_hides_hidden_fields_and_order_skips_their_validation(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->makeProduct($admin);
        $customer = User::create(['name' => '고객', 'email' => 'cus'.uniqid().'@rf.kr', 'password' => 'x1234567']);

        // 주문 페이지에 숨김 필드 라벨이 렌더되지 않는다
        $html = $this->actingAs($customer)->get(route('order.show', $product->order_token))->assertOk()->getContent();
        $this->assertStringContainsString('키워드', $html);
        $this->assertStringNotContainsString('상품 이미지 URL', $html);
        $this->assertStringNotContainsString('내부 메모', $html);

        // 필수+숨김(상점명·이미지)을 입력하지 않아도 주문이 접수된다 — 기본값만 시드
        $this->actingAs($customer)->post(route('order.store', $product->order_token), [
            'f_keyword' => '장롱', 'f_shop_url' => 'https://smartstore.naver.com/x/products/123', 'quantity' => 10,
        ])->assertRedirect(route('order.show', $product->order_token));

        $order = MarketingOrder::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->field_values['mall_name']);
        $this->assertSame('기본메모', $order->field_values['memo']);
    }

    public function test_autofill_fills_mapped_fields_and_preserves_manual_values(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->makeProduct($admin);
        $order = $this->makeOrder($admin, $product, ['mall_name' => null, 'thumb' => null]);
        $analysis = $this->makeAnalysis($admin, $order);
        ShopProductInfo::create([
            'user_id' => $admin->id, 'channel_product_id' => '123',
            'title' => '원목 장롱', 'mall_name' => '두둘리앙', 'price' => 129000,
            'seller_tags' => ['원목장롱', '장롱추천'], 'thumbnail_url' => 'https://img.example/t.jpg',
            'collected_at' => now(),
        ]);

        $filled = app(OrderFieldAutofill::class)->fillFromAnalysis($analysis);
        $this->assertSame(2, $filled);

        $order->refresh();
        $this->assertSame('두둘리앙', $order->field_values['mall_name']);
        $this->assertSame('https://img.example/t.jpg', $order->field_values['thumb']);

        // 수동 입력 보존 — force 아님
        $fv = $order->field_values;
        $fv['mall_name'] = '수동상점';
        $order->update(['field_values' => $fv]);
        app(OrderFieldAutofill::class)->fillFromAnalysis($analysis);
        $this->assertSame('수동상점', $order->refresh()->field_values['mall_name']);

        // force=true(다시 채우기)면 수집값으로 덮어씀
        app(OrderFieldAutofill::class)->fillFromAnalysis($analysis, force: true);
        $this->assertSame('두둘리앙', $order->refresh()->field_values['mall_name']);
    }

    public function test_admin_internal_fields_card_save_and_refill_routes(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->makeProduct($admin);
        $order = $this->makeOrder($admin, $product, ['mall_name' => null, 'thumb' => null, 'memo' => null]);
        $analysis = $this->makeAnalysis($admin, $order);
        ShopProductInfo::create([
            'user_id' => $admin->id, 'channel_product_id' => '123',
            'title' => '원목 장롱', 'mall_name' => '두둘리앙', 'thumbnail_url' => 'https://img.example/t.jpg',
            'collected_at' => now(),
        ]);

        // 상세에 내부 필드 카드 렌더
        $html = $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()->getContent();
        $this->assertStringContainsString('내부 필드', $html);
        $this->assertStringContainsString('수집값 다시 채우기', $html);

        // 수동 저장
        $this->actingAs($admin)->put(route('admin.orders.internal-fields', $order), [
            'internal' => ['memo' => '발주 메모'],
        ])->assertRedirect();
        $this->assertSame('발주 메모', $order->refresh()->field_values['memo']);

        // 다시 채우기(force) — 수집값 반영
        $this->actingAs($admin)->post(route('admin.orders.autofill', $order))->assertRedirect();
        $order->refresh();
        $this->assertSame('두둘리앙', $order->field_values['mall_name']);
        $this->assertSame('https://img.example/t.jpg', $order->field_values['thumb']);
    }

    public function test_extension_product_info_collection_triggers_autofill(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->makeProduct($admin);
        $order = $this->makeOrder($admin, $product, ['mall_name' => null, 'thumb' => null]);
        $analysis = $this->makeAnalysis($admin, $order);

        // 확장이 상품정보를 보내는 시점(refreshProductInfo) — 저장과 동시에 주문 내부 필드가 채워진다
        $this->actingAs($admin)->post(route('admin.shop-keyword.product-info', $analysis), [
            'info' => [
                'channel_product_id' => '123', 'title' => '원목 장롱', 'mall_name' => '두둘리앙',
                'price' => 129000, 'seller_tags' => ['원목장롱'], 'thumbnail_url' => 'https://img.example/t.jpg',
            ],
        ])->assertOk();

        $order->refresh();
        $this->assertSame('두둘리앙', $order->field_values['mall_name']);
        $this->assertSame('https://img.example/t.jpg', $order->field_values['thumb']);
    }

    public function test_approve_blocked_until_required_hidden_fields_filled(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->makeProduct($admin);
        $order = $this->makeOrder($admin, $product, ['mall_name' => null, 'thumb' => null]);

        // 발주 전송은 가드 통과 후에만 도달해야 하므로 가짜 dispatcher 로 대체
        $this->mock(\App\Domain\Order\OrderDispatchService::class, function ($m) {
            $m->shouldReceive('dispatch')->andReturn(['ok' => true, 'message' => '테스트 전송']);
        });

        $this->actingAs($admin)->post(route('admin.orders.approve', $order))
            ->assertSessionHasErrors('approve');
        $this->assertSame('pending', $order->refresh()->status);

        // 필수 내부 필드를 채우면 가드 통과 → 발주·진행중 전환
        $fv = $order->field_values;
        $fv['mall_name'] = '두둘리앙';
        $fv['thumb'] = 'https://img.example/t.jpg';
        $order->update(['field_values' => $fv]);

        $this->actingAs($admin)->post(route('admin.orders.approve', $order))
            ->assertSessionDoesntHaveErrors('approve');
        $this->assertSame('processing', $order->refresh()->status);
    }
}
