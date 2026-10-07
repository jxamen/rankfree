<?php

namespace Tests\Feature;

use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\OrderDispatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 부스팅샵 미주문 알림(09·13·17시) — 미처리 판별·슬랙 문구·개인정보 제외(2026-10-07). */
class OrdersBoostingReminderTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $status, array $fields, string $title = '플레이스 저장'): MarketingOrder
    {
        $product = MarketingProduct::create([
            'product_type' => 'REWARD', 'title' => $title, 'quantity_mode' => 'daily',
            'base_cost' => 0, 'min_price' => 100, 'min_quantity' => 1, 'max_quantity' => 10000, 'min_days' => 1, 'is_active' => true,
        ]);

        return MarketingOrder::create([
            'product_id' => $product->id, 'user_id' => User::factory()->create()->id,
            'quantity' => 20, 'days' => 3, 'unit_price' => 100, 'total_price' => 6000,
            'status' => $status, 'orderer_name' => '홍길동', 'orderer_contact' => '010-1234-5678',
            'field_values' => $fields,
        ]);
    }

    public function test_lists_only_orders_not_yet_sent_to_boostingshop(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        config(['services.slack.jcurve_group_webhook' => 'https://hooks.slack.com/services/T/B/X']);
        $place = ['keyword' => '풍동헬스', 'place_url' => 'https://m.place.naver.com/place/1234567'];
        $shop = ['keyword' => '여자가죽자켓', 'shop_url' => 'https://smartstore.naver.com/x/products/111'];

        $wantPlace = $this->order('pending', $place);
        $wantShop = $this->order('processing', $shop, '쇼핑 유입');
        $sent = $this->order('processing', $place);
        OrderDispatch::create(['order_id' => $sent->id, 'vendor_name' => OrderDispatch::BOOSTING_VENDOR, 'channel' => 'api', 'quantity' => 20, 'status' => 'sent']);
        $done = $this->order('completed', $place);
        $notBoosting = $this->order('pending', ['keyword' => '블로그 체험단']);

        $this->artisan('orders:boosting-reminder')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) use ($wantPlace, $wantShop, $sent, $done, $notBoosting) {
            $t = $r['text'] ?? '';

            return str_contains($t, '*부스팅샵 미주문 2건* (플레이스 1건 · 쇼핑 1건)')
                && str_contains($t, '/admin/orders/'.$wantPlace->id.'|'.$wantPlace->order_no.'>')
                && str_contains($t, $wantShop->order_no) && str_contains($t, '유입키워드 분석 전')
                && ! str_contains($t, $sent->order_no) && ! str_contains($t, $done->order_no) && ! str_contains($t, $notBoosting->order_no)
                // 고객 개인정보·키워드는 넣지 않는다
                && ! str_contains($t, '홍길동') && ! str_contains($t, '010-1234') && ! str_contains($t, '풍동헬스');
        });
    }

    public function test_no_message_when_nothing_pending(): void
    {
        Http::fake();
        config(['services.slack.jcurve_group_webhook' => 'https://hooks.slack.com/services/T/B/X']);

        $this->artisan('orders:boosting-reminder')->assertSuccessful();

        Http::assertNothingSent();
    }
}
