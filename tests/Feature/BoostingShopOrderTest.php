<?php

namespace Tests\Feature;

use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\OrderDispatch;
use App\Models\ProductField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 부스팅샵 플레이스 주문(2026-08-27) — 주문 상세 [부스팅샵 주문] → 전송값 확인 → /api/order/place 접수.
 * HTTP 는 항상 200이고 성공 여부는 body 의 result 로 판정한다는 점이 이 연동의 핵심.
 */
class BoostingShopOrderTest extends TestCase
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
            'product_type' => 'REWARD', 'title' => '네이버 플레이스 퀴즈', 'quantity_mode' => 'daily',
            'base_cost' => 0, 'min_price' => 100, 'min_quantity' => 1, 'max_quantity' => 10000, 'min_days' => 1, 'is_active' => true,
        ]);
        foreach ([
            ['keyword', '검색 키워드'], ['place_url', '플레이스 주소'], ['daily_qty', '일수량'],
            ['start_date', '시작일'], ['end_date', '종료일'], ['field_6', '유입키워드 1'], ['field_7', '유입키워드 2'],
        ] as $i => [$key, $label]) {
            ProductField::create(['product_id' => $this->product->id, 'field_key' => $key, 'field_type' => 'TEXT',
                'label' => $label, 'is_required' => true, 'sort_order' => $i, 'is_active' => true]);
        }

        // 플레이스 자동 수집(PlaceProfileFetcher)이 실제 네이버를 조회하지 않도록 캐시를 미리 채운다
        Cache::put('place:profile:1011101134', [
            'place_id' => '1011101134', 'name' => '테디케이짐 헬스 PT 풍동점', 'category' => '헬스장',
            'address' => '경기 고양시 일산동구 풍동 1234', 'road_address' => '', 'phone' => '0507-1483-6336',
        ], now()->addDay());
    }

    private function makeOrder(array $overrides = [], string $status = 'pending'): MarketingOrder
    {
        return MarketingOrder::create([
            'product_id' => $this->product->id, 'user_id' => $this->admin->id,
            'quantity' => 60, 'days' => 3, 'unit_price' => 100, 'total_price' => 6000,
            'status' => $status, 'orderer_name' => '주문자', 'orderer_contact' => 't@t.kr',
            'field_values' => array_merge([
                'keyword' => '풍동헬스',
                'place_url' => 'https://m.place.naver.com/place/1011101134/home',
                'daily_qty' => '20',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-03',
                'field_6' => '풍동헬스장',
                'field_7' => '일산헬스장추천',
            ], $overrides),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'product_no' => 47,
            'link' => 'https://m.place.naver.com/place/1011101134/home',
            'product_name' => '테디케이짐 헬스 PT 풍동점',
            'keyword' => '풍동헬스',
            'search_keywords' => "풍동헬스\n풍동헬스장\n일산헬스장추천",
            'day_quantity' => 20,
            'fr_date' => '2026-09-01',
            'to_date' => '2026-09-03',
        ], $overrides);
    }

    private function fakeSuccess(): void
    {
        Http::fake(['boostings.shop/api/order/place' => Http::response([
            'result' => 'success', 'order_no' => 54890, 'status' => 'waiting',
            'day_quantity' => 20, 'total_quantity' => 60, 'ads_period' => 3, 'balance' => 4100000,
        ], 200)]);
    }

    public function test_form_autofills_shop_name_phone_and_product_no(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->get(route('admin.orders.boosting-shop', $order))
            ->assertOk()
            ->assertSee('테디케이짐 헬스 PT 풍동점', false)      // 플레이스에서 자동 수집한 상호명
            ->assertSee('0507-1483-6336', false)                // 전화도 자동 수집
            ->assertSee('헬스장')                                // 수집 정보(업종) 표기 — 키워드 추천 재료
            ->assertSee('value="47" selected', false);          // 유입 상품 기본 선택(베이직)
    }

    public function test_save_product_defaults_to_save_product_no(): void
    {
        $this->product->update(['title' => '네이버 플레이스 저장']);
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->get(route('admin.orders.boosting-shop', $order))
            ->assertOk()
            ->assertSee('value="52" selected', false);          // 저장 계열 베이직
    }

    public function test_remembered_product_no_wins_over_default(): void
    {
        $this->product->update(['boosting_product_no' => 49]);
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->get(route('admin.orders.boosting-shop', $order))
            ->assertOk()
            ->assertSee('value="49" selected', false);          // 지난 주문에서 고른 등급(엘리트)을 기억
    }

    public function test_keyword_suggestion_returns_only_exposed_keywords(): void
    {
        $order = $this->makeOrder();
        $profile = app(\App\Domain\Place\PlaceProfileFetcher::class)->fetch('1011101134');
        $candidates = app(\App\Domain\Place\PlaceKeywordSuggester::class)->candidates($profile);
        $this->assertNotEmpty($candidates);

        // 통합검색 판정 캐시를 미리 채워 네트워크 없이 결과를 통제한다(앞 2개만 노출)
        foreach ($candidates as $i => $kw) {
            Cache::put('place:serp:1011101134:'.md5($kw), $i < 2, now()->addHour());
        }
        Cache::put('place:serp:1011101134:'.md5('풍동헬스'), false, now()->addHour());

        $res = $this->actingAs($this->admin)->postJson(route('admin.orders.boosting-shop.keywords', $order), [
            'link' => 'https://m.place.naver.com/place/1011101134/home',
            'current' => '풍동헬스',
        ]);

        $res->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(array_slice($candidates, 0, 2), $res->json('exposed'));
        // 주문에 적힌 키워드가 실제로는 노출되지 않는다는 사실도 함께 알려준다
        $this->assertContains('풍동헬스', $res->json('missed'));
    }

    public function test_keyword_suggestion_rejects_url_without_place_id(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)
            ->postJson(route('admin.orders.boosting-shop.keywords', $order), ['link' => 'https://example.com/none'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_detail_page_shows_boosting_button_and_list_does_not(): void
    {
        $order = $this->makeOrder();

        // 버튼은 주문 상세에만(2026-08-27 사용자 확정 — 목록에서 상세로 옮김)
        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('부스팅샵 주문')
            ->assertSee(route('admin.orders.boosting-shop', $order), false);

        $this->actingAs($this->admin)->get(route('admin.orders'))
            ->assertOk()
            ->assertDontSee('부스팅샵 주문');
    }

    public function test_detail_shows_received_badge_after_success(): void
    {
        $this->fakeSuccess();
        $order = $this->makeOrder();
        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload());

        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('부스팅샵 접수됨')
            ->assertDontSee(route('admin.orders.boosting-shop', $order), false);
    }

    public function test_form_prefills_values_from_order(): void
    {
        $order = $this->makeOrder();

        $res = $this->actingAs($this->admin)->get(route('admin.orders.boosting-shop', $order));

        $res->assertOk()
            ->assertSee('https://m.place.naver.com/place/1011101134/home', false)   // 플레이스 주소
            ->assertSee('1011101134', false)                                        // URL 에서 뽑은 pid
            ->assertSee('풍동헬스장')                                                // 유입키워드 필드 수집
            ->assertSee('일산헬스장추천')
            ->assertSee('2026-09-01', false);
    }

    public function test_successful_place_records_dispatch_and_advances_order(): void
    {
        $this->fakeSuccess();
        $order = $this->makeOrder();

        $res = $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload());

        $res->assertRedirect(route('admin.orders.show', $order));
        $this->assertStringContainsString('54890', session('status'));

        // 전송 파라미터 — 유입 키워드는 배열, 인증 키는 요청에만 붙는다
        Http::assertSent(function ($request) {
            $d = $request->data();

            return $request->url() === 'https://boostings.shop/api/order/place'
                && $d['key'] === 'TESTKEY'
                && $d['product_no'] === 47
                && $d['search_keywords'] === ['풍동헬스', '풍동헬스장', '일산헬스장추천']
                && $d['day_quantity'] === 20
                && $d['fr_date'] === '2026-09-01';
        });

        $dispatch = OrderDispatch::where('order_id', $order->id)->first();
        $this->assertSame(OrderDispatch::BOOSTING_VENDOR, $dispatch->vendor_name);
        $this->assertSame('sent', $dispatch->status);
        $this->assertSame(60, $dispatch->quantity);                       // 응답 total_quantity
        $this->assertStringContainsString('54890', $dispatch->response);
        $this->assertArrayNotHasKey('key', $dispatch->payload);           // 시크릿은 기록에 남기지 않는다

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(47, $this->product->fresh()->boosting_product_no);   // 다음 주문 자동 채움
    }

    public function test_failed_result_is_recorded_and_order_stays(): void
    {
        Http::fake(['boostings.shop/api/order/place' => Http::response([
            'result' => 'fail', 'error' => '적립금이 부족합니다.',
        ], 200)]);
        $order = $this->makeOrder();

        $res = $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload());

        $res->assertSessionHasErrors('boosting');
        $this->assertStringContainsString('적립금이 부족합니다.', session('errors')->first('boosting'));

        $dispatch = OrderDispatch::where('order_id', $order->id)->first();
        $this->assertSame('failed', $dispatch->status);
        $this->assertStringContainsString('적립금이 부족합니다.', $dispatch->response);
        $this->assertSame('pending', $order->fresh()->status);            // 실패는 주문을 진행중으로 넘기지 않는다
        $this->assertNull($this->product->fresh()->boosting_product_no);
    }

    public function test_duplicate_place_is_blocked_until_canceled(): void
    {
        $this->fakeSuccess();
        $order = $this->makeOrder();
        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload());

        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload())
            ->assertSessionHasErrors('boosting');
        $this->assertSame(1, OrderDispatch::where('order_id', $order->id)->count());

        // 발주를 취소하면 다시 넣을 수 있다(기존 발주 흐름과 동일한 규칙)
        OrderDispatch::where('order_id', $order->id)->update(['status' => 'canceled']);
        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload())
            ->assertRedirect(route('admin.orders.show', $order));
        $this->assertSame(2, OrderDispatch::where('order_id', $order->id)->count());
    }

    public function test_too_many_search_keywords_are_rejected(): void
    {
        Http::fake();
        $order = $this->makeOrder();
        $many = collect(range(1, 31))->map(fn ($i) => "키워드{$i}")->implode("\n");

        $this->actingAs($this->admin)
            ->post(route('admin.orders.boosting-shop.place', $order), $this->payload(['search_keywords' => $many]))
            ->assertSessionHasErrors('search_keywords');

        Http::assertNothingSent();
        $this->assertDatabaseCount('order_dispatches', 0);
    }

    public function test_missing_api_key_blocks_sending(): void
    {
        config(['rankfree.boosting_shop.api_key' => '']);
        Http::fake();
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->post(route('admin.orders.boosting-shop.place', $order), $this->payload())
            ->assertSessionHasErrors('boosting');

        Http::assertNothingSent();
        $this->assertSame('failed', OrderDispatch::where('order_id', $order->id)->first()->status);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_invalid_input_is_validated_before_sending(): void
    {
        Http::fake();
        $order = $this->makeOrder();

        $this->actingAs($this->admin)
            ->post(route('admin.orders.boosting-shop.place', $order), $this->payload(['to_date' => '2026-08-01']))
            ->assertSessionHasErrors('to_date');

        Http::assertNothingSent();
    }
}
