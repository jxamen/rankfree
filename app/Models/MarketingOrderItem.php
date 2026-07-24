<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 세부주문서(일할 주문) — 기간형 주문의 회차(1일 1건) 관리 단위.
 * 회차별 업체 분산·Short URL 순차 배정·개별 발주/취소. 진행일 도래분은 스케줄러가 자동 발주.
 */
class MarketingOrderItem extends Model
{
    public const STATUSES = [
        'pending' => '대기',
        'sent' => '전송됨',
        'failed' => '실패',
        'canceled' => '취소',
    ];

    protected $fillable = [
        'order_id', 'day_no', 'work_date', 'end_date', 'quantity', 'short_url', 'vendor_id', 'status', 'dispatch_id',
    ];

    protected $casts = [
        'day_no' => 'integer',
        'quantity' => 'integer',
        'work_date' => 'date',
        'end_date' => 'date',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketingOrder::class, 'order_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(OrderDispatch::class, 'dispatch_id');
    }

    /**
     * 발주 도래일 — 이 회차가 실제로 업체에 전송돼야 하는 날(2026-07-24).
     * 배정 업체가 '주말 몰아 발주'(vendor.weekend_batch_dispatch)면 토·일·월 회차를
     * 직전 금요일로 앞당긴다(주말 미접수 업체 — 금요일에 한꺼번에 발주). 그 외는 진행일 당일.
     * 승인·발주(dispatchDueItems)와 자동 스케줄러(orders:dispatch-due)가 공통으로 이 값을 쓴다.
     */
    public function dispatchDueDate(): Carbon
    {
        $date = $this->work_date instanceof Carbon ? $this->work_date->copy() : Carbon::parse((string) $this->work_date);

        $vendor = $this->relationLoaded('vendor') ? $this->vendor : ($this->vendor_id ? Vendor::find($this->vendor_id) : null);
        if (! $vendor || ! $vendor->weekend_batch_dispatch) {
            return $date;
        }

        return match ($date->dayOfWeek) {
            Carbon::SATURDAY => $date->subDay(),     // 토 → 직전 금
            Carbon::SUNDAY => $date->subDays(2),     // 일 → 직전 금
            Carbon::MONDAY => $date->subDays(3),     // 월 → 직전 금
            default => $date,                        // 화~금: 당일
        };
    }
}
