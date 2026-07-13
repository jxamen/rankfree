<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** 마케팅 상품 주문 — 동적 필드 입력값 + 수량·금액. */
class MarketingOrder extends Model
{
    /** 주문 상태 (코드 => 라벨). */
    public const STATUSES = [
        'pending' => '접수',
        'processing' => '진행중',
        'completed' => '완료',
        'canceled' => '취소',
    ];

    protected $fillable = [
        'order_no', 'product_id', 'user_id', 'quantity', 'days', 'field_values',
        'unit_price', 'total_price', 'status', 'orderer_name', 'orderer_contact',
    ];

    protected $casts = [
        'field_values' => 'array',
        'quantity' => 'integer', 'days' => 'integer',
        'unit_price' => 'decimal:2', 'total_price' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $o) {
            if (! $o->order_no) {
                $o->order_no = 'MO'.now()->format('ymd').strtoupper(Str::random(6));
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MarketingProduct::class, 'product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
