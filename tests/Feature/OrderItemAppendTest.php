<?php

namespace Tests\Feature;

use App\Domain\Order\OrderItemPlanner;
use App\Models\MarketingOrder;
use App\Models\MarketingOrderItem;
use App\Models\MarketingProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 세부주문 부족분 추가(2026-08-24) — 기간을 늘렸는데 **이미 전송된 회차가 있어 재생성이 막힌** 주문.
 * 운영 주문 35에서 실제로 났다: days=6 인데 세부주문 3건이 전부 sent 라 늘린 3일치를 만들 방법이 없었다.
 */
class OrderItemAppendTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $days): MarketingOrder
    {
        $admin = User::create(['name' => '관리자', 'email' => 'append-admin@rankfree.kr', 'password' => 'secret1234']);
        $product = MarketingProduct::create([
            'product_type' => 'REWARD', 'sub_type_code' => 'NAVER_SHOP_QUIZ', 'title' => '쇼핑 유입',
            'base_cost' => 100, 'min_price' => 100, 'min_quantity' => 1, 'order_token' => 'tk'.uniqid(),
            'quantity_mode' => 'daily', 'default_fulfillment' => 40, 'is_active' => true, 'created_by' => $admin->id,
        ]);

        return MarketingOrder::create([
            'product_id' => $product->id, 'user_id' => $admin->id, 'quantity' => 200, 'days' => $days,
            'unit_price' => 100, 'total_price' => 120000, 'status' => 'processing',
            'orderer_name' => '주문자', 'orderer_contact' => 't@t.kr',
            'field_values' => ['start_date' => '2026-08-21', 'end_date' => '2026-08-26'],
        ]);
    }

    private function sentItem(MarketingOrder $order, int $dayNo, string $date): void
    {
        MarketingOrderItem::create([
            'order_id' => $order->id, 'day_no' => $dayNo, 'work_date' => $date, 'end_date' => $date,
            'quantity' => 80, 'status' => 'sent',
        ]);
    }

    public function test_전송된_회차가_있어도_빠진_회차를_이어_만든다(): void
    {
        $order = $this->order(6);
        $this->sentItem($order, 1, '2026-08-21');
        $this->sentItem($order, 2, '2026-08-22');
        $this->sentItem($order, 3, '2026-08-24');   // 하루 밀려 진행된 회차

        $planner = app(OrderItemPlanner::class);
        $this->assertSame([4, 5, 6], $planner->missingDayNos($order));
        $this->assertSame(-1, $planner->regenerate($order), '전송분이 있으면 재생성은 여전히 막혀야 한다');

        $this->assertSame(3, $planner->appendMissingDays($order->fresh()));

        $items = $order->fresh()->items()->orderBy('day_no')->get();
        $this->assertCount(6, $items);
        // 기존 회차는 그대로(전송 상태·날짜 보존)
        $this->assertSame('sent', $items[2]->status);
        $this->assertSame('2026-08-24', $items[2]->work_date->toDateString());
        // 추가분은 마지막 진행일 다음 날부터 이어진다
        $this->assertSame(['2026-08-25', '2026-08-26', '2026-08-27'],
            $items->slice(3)->map(fn ($i) => $i->work_date->toDateString())->values()->all());
        // 일 발주량 = 200 × 40% = 80
        $this->assertSame([80, 80, 80], $items->slice(3)->pluck('quantity')->map(fn ($q) => (int) $q)->values()->all());
        $this->assertSame(['pending', 'pending', 'pending'], $items->slice(3)->pluck('status')->values()->all());
    }

    public function test_이미_기간만큼_있으면_추가하지_않는다(): void
    {
        $order = $this->order(2);
        $this->sentItem($order, 1, '2026-08-21');
        $this->sentItem($order, 2, '2026-08-22');

        $planner = app(OrderItemPlanner::class);
        $this->assertSame([], $planner->missingDayNos($order));
        $this->assertSame(0, $planner->appendMissingDays($order));
        $this->assertSame(2, $order->fresh()->items()->count());
    }

    /** 회차가 하나도 없으면 시작일부터 — generate() 와 같은 규칙 */
    public function test_회차가_없으면_시작일부터_만든다(): void
    {
        $order = $this->order(2);

        $this->assertSame(2, app(OrderItemPlanner::class)->appendMissingDays($order));
        $this->assertSame(['2026-08-21', '2026-08-22'],
            $order->fresh()->items()->orderBy('day_no')->get()->map(fn ($i) => $i->work_date->toDateString())->all());
    }
}
