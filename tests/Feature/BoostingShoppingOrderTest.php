<?php

namespace Tests\Feature;

use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\OrderDispatch;
use App\Models\ProductField;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordShortLink;
use App\Models\ShopProductInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 부스팅샵 쇼핑 주문(2026-09-07) — 주문 상세 [부스팅샵 주문] → 전송값 확인 → /api/order/shopping 접수.
 * 핵심은 **생성된 Short URL 을 landing_urls[] 로 한 번에 모두 보내** 부스팅샵이 하루씩 돌려 쓰게 하는 것.
 */
class BoostingShoppingOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MarketingProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rankfree.boosting_shop.base_url' => 'https://boostings.shop', 'rankfree.boosting_shop.api_key' => 'TESTKEY']);

        $this->admin = User::factory()->create(['role' => 'operator']);
        $this->product = MarketingProduct::create([
            'product_type' => 'REWARD', 'title' => '네이버 쇼핑 퀴즈', 'quantity_mode' => 'daily',
            'base_cost' => 0, 'min_price' => 100, 'min_quantity' => 1, 'max_quantity' => 10000, 'min_days' => 1, 'is_active' => true,
        ]);
        foreach ([
            ['keyword', '검색 키워드'], ['shop_url', '스마트스토어/가격비교 주소'], ['daily_qty', '일수량'],
            ['start_date', '시작일'], ['end_date', '종료일'],
        ] as $i => [$key, $label]) {
            ProductField::create(['product_id' => $this->product->id, 'field_key' => $key, 'field_type' => 'TEXT',
                'label' => $label, 'is_required' => true, 'sort_order' => $i, 'is_active' => true]);
        }
    }

    private function makeOrder(array $overrides = [], string $status = 'pending'): MarketingOrder
    {
        return MarketingOrder::create([
            'product_id' => $this->product->id, 'user_id' => $this->admin->id,
            'quantity' => 900, 'days' => 3, 'unit_price' => 100, 'total_price' => 90000,
            'status' => $status, 'orderer_name' => '주문자', 'orderer_contact' => 't@t.kr',
            'field_values' => array_merge([
                'keyword' => '비타민c',
                'shop_url' => 'https://smartstore.naver.com/mystore/products/1234567890',
                'daily_qty' => '300',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-03',
            ], $overrides),
        ]);
    }

    /** 주문에 연결된 유입키워드 분석 + Short URL(그룹 순) — 랜딩 URL 자동 채움의 재료. */
    private function makeAnalysisWithLinks(MarketingOrder $order, int $links = 3): ShopKeywordAnalysis
    {
        $analysis = ShopKeywordAnalysis::create([
            'user_id' => $this->admin->id, 'marketing_order_id' => $order->id,
            'core_keyword' => '비타민c', 'product_url' => 'https://smartstore.naver.com/mystore/products/1234567890',
            'product_id' => '1234567890', 'mall_name' => '땡땡스토어', 'product_title' => '비타민C 1000mg 2개월분',
            'product_price' => 19900, 'status' => 'done',
        ]);
        foreach (range(1, $links) as $n) {
            ShopKeywordShortLink::create([
                'analysis_id' => $analysis->id, 'token' => 'TOKEN'.$n, 'domain' => 'sunny-5f1a8.rankfree.co.kr',
                'group_no' => $n, 'group_count' => 1, 'keywords' => ['비타민c '.$n],
            ]);
        }

        return $analysis;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'product_no' => 57,
            'keyword' => '비타민c',
            'product_url' => 'https://smartstore.naver.com/mystore/products/1234567890',
            'mid' => '87654321098',
            'landing_urls' => "https://sunny-5f1a8.rankfree.co.kr/s/TOKEN1\nhttps://sunny-5f1a8.rankfree.co.kr/s/TOKEN2\nhttps://sunny-5f1a8.rankfree.co.kr/s/TOKEN3",
            'tags' => '비타민, 고함량, 2개월분',
            'product_name' => '비타민C 1000mg 2개월분',
            'mall_name' => '땡땡스토어',
            'amount' => '19,900',
            'image_url' => 'https://shop-phinf.pstatic.net/sample.jpg',
            'day_quantity' => 300,
            'fr_date' => '2026-10-01',
            'to_date' => '2026-10-03',
        ], $overrides);
    }

    private function fakeSuccess(): void
    {
        Http::fake(['boostings.shop/api/order/shopping' => Http::response([
            'result' => 'success', 'order_no' => 128745, 'status' => 'waiting',
            'day_quantity' => 300, 'total_quantity' => 900, 'ads_period' => 3, 'search_type' => 'smartstore', 'balance' => 4100000,
        ], 200)]);
    }

    public function test_form_autofills_landing_urls_from_short_links(): void
    {
        $order = $this->makeOrder();
        $this->makeAnalysisWithLinks($order);

        $this->actingAs($this->admin)->get(route('admin.orders.boosting-shop', $order))
            ->assertOk()
            ->assertSee('부스팅샵 쇼핑 주문')
            ->assertSee('https://sunny-5f1a8.rankfree.co.kr/s/TOKEN1', false)
            ->assertSee('https://sunny-5f1a8.rankfree.co.kr/s/TOKEN3', false)   // 1개만이 아니라 전부 채운다
            ->assertSee('Short URL 다시 불러오기 (3)')
            ->assertSee('비타민C 1000mg 2개월분', false)                          // 수집값 자동 채움
            ->assertSee('땡땡스토어', false);
    }

    public function test_form_autofills_tags_and_image_from_collected_product_info(): void
    {
        $order = $this->makeOrder();
        $this->makeAnalysisWithLinks($order);
        ShopProductInfo::create([
            'user_id' => $this->admin->id, 'channel_product_id' => '1234567890', 'title' => '비타민C 1000mg 2개월분',
            'mall_name' => '땡땡스토어', 'price' => 19900, 'seller_tags' => ['비타민', '고함량', '2개월분'],
            'thumbnail_url' => 'https://shop-phinf.pstatic.net/sample.jpg',
        ]);

        $this->actingAs($this->admin)->get(route('admin.orders.boosting-shop', $order))
            ->assertOk()
            ->assertSee('비타민, 고함량, 2개월분', false)
            ->assertSee('https://shop-phinf.pstatic.net/sample.jpg', false)
            // 스마트스토어 URL 의 숫자는 MID 가 아니므로 자동으로 채우지 않는다 — 다만 MID 는 없어도 주문된다
            ->assertSee('비워도 주문됩니다');
    }

    /** 쇼핑은 등급표가 없어 매번 손으로 넣어야 했다 — 기본값을 고정해 그냥 열면 채워져 있게 한다. */
    public function test_form_prefills_fixed_shopping_product_no(): void
    {
        $order = $this->makeOrder();
        $this->product->update(['boosting_product_no' => null]);   // 아직 한 번도 주문 안 한 상품

        $this->actingAs($this->admin)->get(route('admin.orders.boosting-shop', $order))
            ->assertOk()
            ->assertSee('value="'.\App\Domain\Order\BoostingShopClient::SHOPPING_DEFAULT_PRODUCT_NO.'"', false);
    }

    /** 상품에 기억된 번호가 있으면 고정 기본값보다 그쪽이 우선한다. */
    public function test_remembered_product_no_wins_over_default(): void
    {
        $order = $this->makeOrder();
        $this->product->update(['boosting_product_no' => 61]);

        $this->actingAs($this->admin)->get(route('admin.orders.boosting-shop', $order))
            ->assertOk()
            ->assertSee('value="61"', false);
    }

    /** 확인을 눌러도 아무 반응이 없어 다시 누르는 사고를 막는다 — 전체화면 로딩 표식. */
    public function test_form_asks_for_fullscreen_loading_after_confirm(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->get(route('admin.orders.boosting-shop', $order))
            ->assertOk()
            ->assertSee('data-loading="부스팅샵으로 접수하는 중…"', false);
    }

    public function test_order_sends_all_landing_urls_as_array(): void
    {
        $this->fakeSuccess();
        $order = $this->makeOrder();
        $this->makeAnalysisWithLinks($order);

        $this->actingAs($this->admin)
            ->post(route('admin.orders.boosting-shop.place', $order), $this->payload())
            ->assertRedirect(route('admin.orders.show', $order));

        Http::assertSent(function ($request) {
            $d = $request->data();

            return $request->url() === 'https://boostings.shop/api/order/shopping'
                && $d['key'] === 'TESTKEY'
                && $d['landing_urls'] === [
                    'https://sunny-5f1a8.rankfree.co.kr/s/TOKEN1',
                    'https://sunny-5f1a8.rankfree.co.kr/s/TOKEN2',
                    'https://sunny-5f1a8.rankfree.co.kr/s/TOKEN3',
                ]
                && $d['tags'] === ['비타민', '고함량', '2개월분']
                && $d['amount'] === 19900             // 콤마가 섞인 입력도 숫자로 정리해 보낸다
                && $d['mid'] === '87654321098'
                && $d['product_no'] === 57;
        });

        $dispatch = OrderDispatch::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('sent', $dispatch->status);
        $this->assertSame(OrderDispatch::BOOSTING_VENDOR, $dispatch->vendor_name);
        $this->assertSame(900, $dispatch->quantity);
        $this->assertCount(3, $dispatch->payload['landing_urls']);
        $this->assertArrayNotHasKey('key', $dispatch->payload);         // 시크릿은 발주 기록에 남지 않는다
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(57, $this->product->fresh()->boosting_product_no);   // 다음 주문에 기억
    }

    public function test_landing_urls_accepts_comma_and_dedupes(): void
    {
        $this->fakeSuccess();
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload([
            'landing_urls' => 'https://a.kr/s/1, https://b.kr/s/2 , https://a.kr/s/1',
        ]))->assertRedirect(route('admin.orders.show', $order));

        // 주문이 진행중으로 넘어가며 순위추적도 요청을 보내므로 접수 엔드포인트만 골라 확인한다
        Http::assertSent(fn ($request) => $request->url() !== 'https://boostings.shop/api/order/shopping'
            || $request->data()['landing_urls'] === ['https://a.kr/s/1', 'https://b.kr/s/2']);
    }

    public function test_landing_urls_required_and_capped(): void
    {
        $this->fakeSuccess();
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload(['landing_urls' => '  ']))
            ->assertSessionHasErrors('landing_urls');

        $many = collect(range(1, 101))->map(fn ($n) => 'https://a.kr/s/'.$n)->implode("\n");
        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload(['landing_urls' => $many]))
            ->assertSessionHasErrors('landing_urls');

        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload(['landing_urls' => "https://a.kr/s/1\n그냥문자열"]))
            ->assertSessionHasErrors('landing_urls');

        Http::assertNothingSent();
        $this->assertSame(0, OrderDispatch::count());
    }

    public function test_tags_required(): void
    {
        $this->fakeSuccess();
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload(['tags' => ' , ']))
            ->assertSessionHasErrors('tags');

        Http::assertNothingSent();
    }

    public function test_fail_result_is_recorded_as_failed_dispatch(): void
    {
        // HTTP 는 200 이지만 result 가 fail — 이 연동의 핵심 함정
        Http::fake(['boostings.shop/api/order/shopping' => Http::response([
            'result' => 'fail', 'error' => '적립금이 부족합니다.',
        ], 200)]);
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload())
            ->assertSessionHasErrors('boosting');

        $dispatch = OrderDispatch::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('failed', $dispatch->status);
        $this->assertStringContainsString('적립금이 부족합니다.', $dispatch->response);
        $this->assertSame('pending', $order->fresh()->status);   // 실패는 상태를 넘기지 않는다
    }

    public function test_duplicate_order_is_blocked(): void
    {
        $this->fakeSuccess();
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload());
        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload())
            ->assertSessionHasErrors('boosting');

        $this->assertSame(1, OrderDispatch::where('order_id', $order->id)->count());
    }

    public function test_saved_draft_wins_over_collected_values(): void
    {
        $order = $this->makeOrder();
        $this->makeAnalysisWithLinks($order);

        $this->actingAs($this->admin)->postJson(route('admin.orders.boosting-shop.save', $order), [
            'product_no' => 61,
            'landing_urls' => "https://c.kr/s/9\nhttps://c.kr/s/8",
            'tags' => '손질한태그',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->actingAs($this->admin)->get(route('admin.orders.boosting-shop', $order))
            ->assertOk()
            ->assertSee('https://c.kr/s/9', false)
            ->assertSee('손질한태그', false)
            ->assertSee('value="61"', false)
            // 자동 수집분은 [다시 불러오기]로 되살릴 수 있게 그대로 남아 있다
            ->assertSee('Short URL 다시 불러오기 (3)');
    }

    public function test_place_order_still_uses_place_endpoint(): void
    {
        // 쇼핑 분기가 플레이스 주문을 가로채지 않는지 — 같은 라우트를 공유하므로 함께 지킨다
        Http::fake(['boostings.shop/api/order/place' => Http::response(['result' => 'success', 'order_no' => 1], 200)]);
        $placeProduct = MarketingProduct::create([
            'product_type' => 'REWARD', 'title' => '네이버 플레이스 퀴즈', 'quantity_mode' => 'daily',
            'base_cost' => 0, 'min_price' => 100, 'min_quantity' => 1, 'max_quantity' => 10000, 'min_days' => 1, 'is_active' => true,
        ]);
        $order = MarketingOrder::create([
            'product_id' => $placeProduct->id, 'user_id' => $this->admin->id,
            'quantity' => 60, 'days' => 3, 'unit_price' => 100, 'total_price' => 6000,
            'status' => 'pending', 'orderer_name' => '주문자', 'orderer_contact' => 't@t.kr',
            'field_values' => ['keyword' => '풍동헬스', 'place_url' => 'https://m.place.naver.com/place/1011101134/home'],
        ]);

        $this->assertSame('place', $order->boostingService());

        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), [
            'product_no' => 47, 'link' => 'https://m.place.naver.com/place/1011101134/home',
            'product_name' => '테디케이짐', 'keyword' => '풍동헬스', 'search_keywords' => '풍동헬스',
            'day_quantity' => 20, 'fr_date' => '2026-10-01', 'to_date' => '2026-10-03',
        ])->assertRedirect(route('admin.orders.show', $order));

        Http::assertSent(fn ($request) => $request->url() === 'https://boostings.shop/api/order/place');
    }
}
