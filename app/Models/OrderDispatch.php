<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 주문 → 외부 업체 전송 기록. 승인 시 배분 설정대로 생성·전송, 실패 건은 재전송 가능. */
class OrderDispatch extends Model
{
    public const STATUSES = [
        'pending' => '대기',
        'sent' => '전송됨',
        'failed' => '실패',
        'canceled' => '취소',   // 발주 취소 — 시트에 적힌 행은 지우지 않는다(수동 정리). 전부 취소되면 재발주 가능
    ];

    protected $fillable = [
        'order_id', 'vendor_id', 'vendor_name', 'channel', 'quantity',
        'payload', 'status', 'response', 'sent_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketingOrder::class, 'order_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
