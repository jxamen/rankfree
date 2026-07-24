<?php

namespace App\Console\Commands;

use App\Domain\Order\OrderDispatchService;
use App\Domain\Order\OrderItemPlanner;
use App\Models\MarketingOrderItem;
use Illuminate\Console\Command;

/**
 * 세부주문(일할) 예약 발주 (2026-07-23) — 진행일이 된 대기 회차를 자동 전송.
 * 승인된(진행중) 주문만 대상. 매일 아침 09:00(KST) 스케줄 실행 + 수동 실행 가능.
 * Short URL 을 매핑에 쓰는 상품인데 회차 URL 이 비어 있으면 건너뛰고 경고만 남긴다.
 */
class OrdersDispatchDue extends Command
{
    protected $signature = 'orders:dispatch-due';

    protected $description = '진행일이 된 세부주문(일할 발주)을 업체로 자동 전송';

    public function handle(OrderDispatchService $dispatcher, OrderItemPlanner $planner): int
    {
        // 주말 몰아 발주 업체는 토·일·월 회차를 최대 3일 미리(직전 금요일) 보내므로,
        // 후보를 오늘+3일까지 넓혀 온 뒤 회차별 발주 도래일(dispatchDueDate)로 실제 대상만 거른다.
        $due = MarketingOrderItem::with('order.product', 'vendor')
            ->where('status', 'pending')
            ->whereDate('work_date', '<=', today()->copy()->addDays(3))
            ->whereHas('order', fn ($q) => $q->where('status', 'processing'))
            ->orderBy('order_id')->orderBy('day_no')
            ->get()
            ->filter(fn ($item) => $item->dispatchDueDate()->lte(today()))
            ->values();

        $sent = 0;
        $skipped = 0;
        foreach ($due as $item) {
            if ($planner->mappingUsesShortUrl($item->order?->product) && trim((string) $item->short_url) === '') {
                $this->warn("스킵 {$item->order?->order_no} {$item->day_no}일차 — Short URL 미배정");
                $skipped++;

                continue;
            }
            $r = $dispatcher->dispatchItem($item);
            $this->line(($r['ok'] ? 'OK ' : 'FAIL ').$item->order?->order_no.' '.$r['message']);
            $r['ok'] ? $sent++ : null;
        }

        $this->info("세부주문 예약 발주 — 대상 {$due->count()} · 성공 {$sent} · 스킵 {$skipped}");

        return self::SUCCESS;
    }
}
