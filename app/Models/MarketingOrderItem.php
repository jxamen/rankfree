<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'order_id', 'day_no', 'work_date', 'quantity', 'short_url', 'vendor_id', 'status', 'dispatch_id',
    ];

    protected $casts = [
        'day_no' => 'integer',
        'quantity' => 'integer',
        'work_date' => 'date',
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
}
