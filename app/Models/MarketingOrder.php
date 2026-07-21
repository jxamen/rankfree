<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'user_coupon_id', 'discount_amount',
    ];

    protected $casts = [
        'field_values' => 'array',
        'quantity' => 'integer', 'days' => 'integer',
        'unit_price' => 'decimal:2', 'total_price' => 'decimal:2', 'discount_amount' => 'decimal:2',
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

    public function dispatches(): HasMany
    {
        return $this->hasMany(OrderDispatch::class, 'order_id');
    }

    /** 사용된 쿠폰 발급분(할인 적용 시). */
    public function userCoupon(): BelongsTo
    {
        return $this->belongsTo(UserCoupon::class, 'user_coupon_id');
    }

    /** 주문 취소·삭제 시 쿠폰 복원 — 사용 이력을 지워 다시 쓸 수 있게(만료됐으면 만료 상태로 돌아감). */
    public function restoreCoupon(): void
    {
        if ($this->user_coupon_id && $this->userCoupon) {
            $this->userCoupon->update(['used_at' => null, 'order_id' => null]);
        }
    }
}
