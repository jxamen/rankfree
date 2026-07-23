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
        $due = MarketingOrderItem::with('order.product')
            ->where('status', 'pending')
            ->whereDate('work_date', '<=', today())
            ->whereHas('order', fn ($q) => $q->where('status', 'processing'))
            ->orderBy('order_id')->orderBy('day_no')
            ->get();

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
