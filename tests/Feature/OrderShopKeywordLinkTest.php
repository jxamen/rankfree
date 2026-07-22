<?php

namespace Tests\Feature;

use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 쇼핑 유입 주문 ↔ 노출 키워드 분석 연결(2026-07-22) —
 * 주문 목록 No(desc)·수집요청 버튼, 수집요청 시 분석 생성·상호 연결, 멱등.
 */
class OrderShopKeywordLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rankfree.searchad.api_key' => '', 'rankfree.searchad.accounts' => []]);
        Http::fake(fn () => Http::response('', 200));   // prepare() 의 외부 추출은 전부 무응답 처리
    }

    private function shoppingOrder(): array
    {
        $admin = User::create(['name' => '관리자', 'email' => 'oskl@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
        $product = MarketingProduct::create([
            'product_type' => 'REWARD', 'sub_type_code' => 'NAVER_SHOP_QUIZ', 'title' => '네이버 쇼핑 퀴즈',
            'base_cost' => 100, 'min_price' => 100, 'min_quantity' => 1, 'order_token' => 'tk'.uniqid(),
            'is_active' => true, 'created_by' => $admin->id,
        ]);
        $order = MarketingOrder::create([
            'product_id' => $product->id, 'user_id' => $admin->id, 'quantity' => 300, 'days' => 5,
            'unit_price' => 100, 'total_price' => 30000, 'status' => 'pending',
            'orderer_name' => '주문자', 'orderer_contact' => '010-0000-0000',
            'field_values' => ['keyword' => '장롱', 'shop_url' => 'https://smartstore.naver.com/doodooolien/products/12084958317', 'daily_qty' => '300'],
        ]);

        return [$admin, $order];
    }

    public function test_index_shows_no_desc_and_collect_button(): void
    {
        [$admin, $order] = $this->shoppingOrder();

        $html = $this->actingAs($admin)->get('/admin/orders')->assertOk()->getContent();
        $this->assertStringContainsString('수집요청', $html);
        $this->assertStringContainsString('<th class="text-left px-5 py-3 font-semibold" style="width:56px;">No</th>', $html);
    }

    public function test_collect_request_creates_linked_analysis_and_is_idempotent(): void
    {
        [$admin, $order] = $this->shoppingOrder();

        $res = $this->actingAs($admin)->post(route('admin.orders.shop-keyword', $order));

        $a = ShopKeywordAnalysis::latest('id')->first();
        $this->assertNotNull($a);
        $this->assertSame($order->id, $a->marketing_order_id);
        $this->assertSame('장롱', $a->core_keyword);
        $res->assertRedirect(route('admin.shop-keyword.show', $a));

        // 멱등 — 다시 눌러도 새 분석을 만들지 않고 기존 분석으로 이동
        $this->actingAs($admin)->post(route('admin.orders.shop-keyword', $order))
            ->assertRedirect(route('admin.shop-keyword.show', $a));
        $this->assertSame(1, ShopKeywordAnalysis::count());

        // 주문 상세에 연결 표시 + 분석 페이지에 주문 역링크
        $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('장롱')->assertSee('Short URL');
        $this->actingAs($admin)->get(route('admin.shop-keyword.show', $a))->assertOk()
            ->assertSee($order->order_no);
    }

    public function test_non_shopping_order_shows_no_button_and_rejects_request(): void
    {
        [$admin, $order] = $this->shoppingOrder();
        $order->update(['field_values' => []]);   // 키워드·URL 없음 = 쇼핑 유입 주문 아님

        $html = $this->actingAs($admin)->get('/admin/orders')->assertOk()->getContent();
        $this->assertStringNotContainsString('수집요청', $html);

        $this->actingAs($admin)->post(route('admin.orders.shop-keyword', $order))
            ->assertSessionHasErrors('shop_keyword');
        $this->assertSame(0, ShopKeywordAnalysis::count());
    }
}
