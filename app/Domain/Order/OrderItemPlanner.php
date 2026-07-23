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

    /**
     * 세부주문 생성 — 기간형(daily)·days>=1 주문만. 이미 있으면 0.
     * 일 발주량 = 고객 일수량 × 상품 기본 이행률(%) (예: 300 × 40% = 120 — 그 날의 맥스).
     * 업체 배분(비율/고정)은 그 일 발주량에 적용해 **일×업체** 세부주문을 만든다 —
     * allocate() 가 잔여 한도로 캡핑하므로 하루 합이 일 발주량을 절대 초과하지 않는다.
     *
     * @return int 생성 수
     */
    public function generate(MarketingOrder $order): int
    {
        $order->loadMissing('product');
        $days = (int) $order->days;
        if (! $order->product || $order->product->quantity_mode !== 'daily' || $days < 1 || $order->items()->exists()) {
            return 0;
        }

        // 이행률 — 미설정·0 이하는 100% 취급
        $rate = (float) ($order->product->default_fulfillment ?? 100);
        if ($rate <= 0) {
            $rate = 100.0;
        }
        $dailyBase = (int) floor($order->quantity * $rate / 100);
        if ($dailyBase < 1) {
            return 0;
        }

        $rows = $order->product->vendorAllocations()->with('vendor')->where('is_active', true)->orderBy('sort_order')->get()
            ->filter(fn ($pv) => $pv->vendor && $pv->vendor->is_active)->values();

        $fv = (array) $order->field_values;
        $start = trim((string) ($fv['start_date'] ?? ''));
        $startDate = $start !== '' ? Carbon::parse($start) : $order->product->earliestStartDate();

        $n = 0;
        for ($i = 1; $i <= $days; $i++) {
            $date = $startDate->copy()->addDays($i - 1)->toDateString();
            if ($rows->isEmpty()) {
                // 배분 미설정 — 업체 미지정 1건(발주 시 배분 1순위 필요)
                MarketingOrderItem::create(['order_id' => $order->id, 'day_no' => $i, 'work_date' => $date, 'end_date' => $date,
                    'quantity' => $dailyBase, 'status' => 'pending']);
                $n++;

                continue;
            }
            foreach ($this->dispatcher->allocate($dailyBase, $rows) as [$pv, $q]) {
                if ($q < 1) {
                    continue;   // 배분 0 업체는 그 날 세부주문 없음
                }
                MarketingOrderItem::create(['order_id' => $order->id, 'day_no' => $i, 'work_date' => $date, 'end_date' => $date,
                    'quantity' => $q, 'vendor_id' => $pv->vendor->id, 'status' => 'pending']);
                $n++;
            }
        }

        $this->assignShortUrls($order);

        return $n;
    }

    /** 재생성 — 전송된 회차가 없을 때만(대기·실패·취소 전부 삭제 후 새 기준으로). @return -1 = 전송분 존재 */
    public function regenerate(MarketingOrder $order): int
    {
        if ($order->items()->where('status', 'sent')->exists()) {
            return -1;
        }
        $order->items()->delete();

        return $this->generate($order->fresh());
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
