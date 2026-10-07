<?php

namespace Tests\Feature;

use App\Jobs\SendJandiOrderNotification;
use App\Models\AppSetting;
use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 주문 접수 알림 — 웹훅 주소가 슬랙이면 슬랙 형식, 잔디면 기존 잔디 형식(2026-10-07). */
class OrderSlackNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(): MarketingOrder
    {
        $user = User::factory()->create();
        $product = MarketingProduct::create([
            'product_type' => 'REWARD', 'title' => '네이버 플레이스 퀴즈', 'quantity_mode' => 'daily',
            'base_cost' => 0, 'min_price' => 100, 'min_quantity' => 1, 'max_quantity' => 10000, 'min_days' => 1, 'is_active' => true,
        ]);

        return MarketingOrder::create([
            'product_id' => $product->id, 'user_id' => $user->id,
            'quantity' => 20, 'days' => 3, 'unit_price' => 100, 'total_price' => 6000,
            'status' => 'pending', 'orderer_name' => 'A&B', 'orderer_contact' => 't@t.kr',
            'field_values' => ['keyword' => '풍동헬스', 'start_date' => '2026-09-01', 'end_date' => '2026-09-03'],
        ]);
    }

    public function test_slack_webhook_gets_slack_text(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
        AppSetting::write('jandi.order_webhook_url', 'https://hooks.slack.com/services/T/B/X');
        $order = $this->makeOrder();

        (new SendJandiOrderNotification($order))->handle();

        Http::assertSent(function (Request $r) use ($order) {
            $lines = explode("\n", $r['text'] ?? '');

            return str_starts_with($r->url(), 'https://hooks.slack.com/')
                && ! isset($r['connectInfo'])
                && count($lines) === 3
                && $lines[0] === '<@U0C4ZMY6RPV> '.$order->created_at->format('m/d H:i')
                && $lines[1] === '[랭크프리] 네이버 플레이스 퀴즈 · 풍동헬스 · 전체 60 · 일 20 · 6,000원 · A&amp;B'
                && str_ends_with($lines[2], '/admin/orders/'.$order->id.'|주문 상세 보기 ›>');
        });
    }

    public function test_jandi_webhook_keeps_jandi_format(): void
    {
        Http::fake(['wh.jandi.com/*' => Http::response('', 200)]);
        AppSetting::write('jandi.order_webhook_url', 'https://wh.jandi.com/connect-api/webhook/1/abc');
        $order = $this->makeOrder();

        (new SendJandiOrderNotification($order))->handle();

        Http::assertSent(fn (Request $r) => isset($r['connectInfo']) && str_contains($r['body'], $order->order_no));
    }
}
