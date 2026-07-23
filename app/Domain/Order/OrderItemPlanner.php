<?php

namespace App\Domain\Order;

use App\Models\MarketingOrder;
use App\Models\MarketingOrderItem;
use Illuminate\Support\Carbon;

/**
 * 세부주문서(일할) 생성·배정 (2026-07-23).
 * 기간형(daily) 주문을 진행일 단위 회차로 쪼개고,
 *  - 업체: 상품 배분(비율/고정)을 "일수"에 적용해 회차를 블록으로 분산(배분 순서대로), 수동 변경 가능
 *  - Short URL: 유입키워드 분석의 링크를 회차 순서대로 배정(부족하면 순환), 수동 교체 가능
 */
class OrderItemPlanner
{
    public function __construct(private OrderDispatchService $dispatcher) {}

    /** 세부주문 생성 — 기간형(daily)·days>=1 주문만. 이미 있으면 0. @return 생성 수 */
    public function generate(MarketingOrder $order): int
    {
        $order->loadMissing('product');
        $days = (int) $order->days;
        if (! $order->product || $order->product->quantity_mode !== 'daily' || $days < 1 || $order->items()->exists()) {
            return 0;
        }

        $fv = (array) $order->field_values;
        $start = trim((string) ($fv['start_date'] ?? ''));
        $startDate = $start !== '' ? Carbon::parse($start) : $order->product->earliestStartDate();

        for ($i = 1; $i <= $days; $i++) {
            MarketingOrderItem::create([
                'order_id' => $order->id,
                'day_no' => $i,
                'work_date' => $startDate->copy()->addDays($i - 1)->toDateString(),
                'quantity' => (int) $order->quantity,
                'status' => 'pending',
            ]);
        }

        $this->assignVendors($order);
        $this->assignShortUrls($order);

        return $days;
    }

    /** 업체 자동 배정 — 배분 규칙(비율/고정)을 일수에 적용, 배분 순서대로 연속 블록. 이미 지정된 회차는 유지. */
    public function assignVendors(MarketingOrder $order): void
    {
        $rows = $order->product?->vendorAllocations()->with('vendor')->where('is_active', true)->orderBy('sort_order')->get()
            ->filter(fn ($pv) => $pv->vendor && $pv->vendor->is_active)->values();
        if (! $rows || $rows->isEmpty()) {
            return;
        }

        $items = $order->items()->whereNull('vendor_id')->orderBy('day_no')->get();
        if ($items->isEmpty()) {
            return;
        }

        // 비율/고정을 "회차 수"에 적용(기존 수량 배분과 동일 규칙) → [업체, 일수] 블록
        $allocs = $this->dispatcher->allocate($items->count(), $rows);
        $queue = [];
        foreach ($allocs as [$pv, $dayCount]) {
            for ($i = 0; $i < $dayCount; $i++) {
                $queue[] = $pv->vendor->id;
            }
        }
        foreach ($items as $idx => $item) {
            if (isset($queue[$idx])) {
                $item->update(['vendor_id' => $queue[$idx]]);
            }
        }
    }

    /** 상품의 활성 업체 매핑이 Short URL 항목을 실제로 쓰는지 — 쓸 때만 발주 가드를 건다(2026-07-23 확정). */
    public function mappingUsesShortUrl(?\App\Models\MarketingProduct $product): bool
    {
        if (! $product) {
            return false;
        }
        $product->loadMissing('fields');
        $keys = $product->fields->where('autofill_source', 'short_url')->pluck('field_key')->all();
        foreach ($product->vendorAllocations()->where('is_active', true)->get() as $pv) {
            foreach ((array) $pv->field_map as $m) {
                $src = (string) ($m['src'] ?? '');
                if ($src === 'item:short_url') {
                    return true;
                }
                if (str_starts_with($src, 'field:') && in_array(substr($src, 6), $keys, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Short URL 순차 배정 — 분석 링크를 회차 순서대로(부족하면 순환). 기본은 빈 회차만(수동 교체 보존). */
    public function assignShortUrls(MarketingOrder $order, bool $force = false): int
    {
        $analysis = $order->shopKeywordAnalyses()->latest('id')->first();
        if (! $analysis) {
            return 0;
        }
        $links = $analysis->shortLinks()->orderBy('group_no')->get();
        if ($links->isEmpty()) {
            return 0;
        }

        $n = 0;
        foreach ($order->items as $item) {
            if (! $force && trim((string) $item->short_url) !== '') {
                continue;
            }
            $item->update(['short_url' => $links[($item->day_no - 1) % $links->count()]->url()]);
            $n++;
        }

        return $n;
    }
}
