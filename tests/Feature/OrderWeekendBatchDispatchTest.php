<?php

namespace Tests\Feature;

use App\Domain\Order\OrderDispatchService;
use App\Models\MarketingOrder;
use App\Models\MarketingOrderItem;
use App\Models\MarketingProduct;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 주말 몰아 발주(2026-07-24) — 주말 미접수 업체(weekend_batch_dispatch)의 토·일·월 세부주문 회차를
 * 직전 금요일에 발주(회차 개별 유지). 발주 도래일 계산 + 자동 스케줄러 연계.
 */
class OrderWeekendBatchDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();   // 고정 시각 해제
        parent::tearDown();
    }

    private function order(): MarketingOrder
    {
        $admin = User::create(['name' => '관리자', 'email' => 'wb'.uniqid().'@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
        $product = MarketingProduct::create([
            'product_type' => 'REWARD', 'sub_type_code' => 'NAVER_SHOP_QUIZ', 'title' => '쇼핑 유입',
            'base_cost' => 100, 'min_price' => 100, 'min_quantity' => 1, 'order_token' => 'tk'.uniqid(),
            'quantity_mode' => 'daily', 'is_active' => true, 'created_by' => $admin->id,
        ]);

        return MarketingOrder::create([
            'product_id' => $product->id, 'user_id' => $admin->id, 'quantity' => 300, 'days' => 5,
            'unit_price' => 100, 'total_price' => 150000, 'status' => 'processing',
            'orderer_name' => '주문자', 'orderer_contact' => 't@t.kr', 'field_values' => [],
        ]);
    }

    private function item(MarketingOrder $order, int $dayNo, string $date, ?Vendor $vendor): MarketingOrderItem
    {
        return MarketingOrderItem::create([
            'order_id' => $order->id, 'day_no' => $dayNo, 'work_date' => $date, 'end_date' => $date,
            'quantity' => 100, 'vendor_id' => $vendor?->id, 'status' => 'pending',
        ]);
    }

    public function test_batch_vendor_shifts_weekend_and_monday_to_friday(): void
    {
        $fri = Carbon::today()->next(Carbon::FRIDAY);
        $batch = Vendor::create(['name' => '주말몰아', 'channel' => 'api', 'api_method' => 'POST', 'is_active' => true, 'weekend_batch_dispatch' => true]);
        $order = $this->order();

        $sat = $this->item($order, 1, $fri->copy()->addDay()->toDateString(), $batch);
        $sun = $this->item($order, 2, $fri->copy()->addDays(2)->toDateString(), $batch);
        $mon = $this->item($order, 3, $fri->copy()->addDays(3)->toDateString(), $batch);
        $tue = $this->item($order, 4, $fri->copy()->addDays(4)->toDateString(), $batch);

        // 토·일·월 → 직전 금요일, 화 → 당일
        $this->assertSame($fri->toDateString(), $sat->dispatchDueDate()->toDateString());
        $this->assertSame($fri->toDateString(), $sun->dispatchDueDate()->toDateString());
        $this->assertSame($fri->toDateString(), $mon->dispatchDueDate()->toDateString());
        $this->assertSame($fri->copy()->addDays(4)->toDateString(), $tue->dispatchDueDate()->toDateString());
    }

    public function test_normal_vendor_dispatch_due_is_workdate(): void
    {
        $fri = Carbon::today()->next(Carbon::FRIDAY);
        $normal = Vendor::create(['name' => '정상', 'channel' => 'api', 'api_method' => 'POST', 'is_active' => true, 'weekend_batch_dispatch' => false]);
        $order = $this->order();

        $sat = $this->item($order, 1, $fri->copy()->addDay()->toDateString(), $normal);
        // 주말 몰아 발주가 아니면 토요일 회차의 발주일은 그대로 토요일
        $this->assertSame($fri->copy()->addDay()->toDateString(), $sat->dispatchDueDate()->toDateString());
    }

    public function test_scheduler_dispatches_batch_weekend_items_on_friday(): void
    {
        $fri = Carbon::today()->next(Carbon::FRIDAY);
        Carbon::setTestNow($fri->copy()->setTime(9, 0, 0));   // 오늘 = 금요일 09:00

        $batch = Vendor::create(['name' => '주말몰아', 'channel' => 'api', 'api_method' => 'POST', 'is_active' => true, 'weekend_batch_dispatch' => true]);
        $order = $this->order();
        $this->item($order, 1, $fri->copy()->addDay()->toDateString(), $batch);    // 토
        $this->item($order, 2, $fri->copy()->addDays(2)->toDateString(), $batch);  // 일
        $this->item($order, 3, $fri->copy()->addDays(3)->toDateString(), $batch);  // 월
        $this->item($order, 4, $fri->copy()->addDays(4)->toDateString(), $batch);  // 화 — 아직 아님

        // 실제 외부 전송 대신 dispatchItem 을 가로채 발주 대상만 기록
        $seen = [];
        $this->mock(OrderDispatchService::class, function ($m) use (&$seen) {
            $m->shouldReceive('dispatchItem')->andReturnUsing(function (MarketingOrderItem $item) use (&$seen) {
                $seen[] = $item->day_no;
                $item->update(['status' => 'sent']);

                return ['ok' => true, 'message' => 'ok'];
            });
        });

        $this->artisan('orders:dispatch-due')->assertExitCode(0);

        sort($seen);
        $this->assertSame([1, 2, 3], $seen);   // 금요일에 토·일·월 발주, 화요일 회차는 제외
    }
}
