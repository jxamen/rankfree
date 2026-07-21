<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 회원별 쿠폰 발급분(1인 1매) — 만료·사용 이력. 주문 취소 시 복원(used_at·order_id 초기화). */
class UserCoupon extends Model
{
    public const SOURCES = ['admin' => '관리자 발행', 'download' => '직접 다운로드'];

    protected $fillable = ['coupon_id', 'user_id', 'source', 'issued_by', 'expires_at', 'used_at', 'order_id'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketingOrder::class, 'order_id');
    }

    /** 미사용 + 발급분 만료 전(쿠폰 활성·기간은 isUsable 에서 함께 검사). */
    public function scopeUnused(Builder $q): Builder
    {
        return $q->whereNull('used_at')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
    }

    /** 지금 사용 가능한지 — 미사용·미만료 + 쿠폰 활성·기간 내(관리자가 기간을 줄였을 수 있어 쿠폰 쪽도 재검사). */
    public function isUsable(): bool
    {
        return ! $this->used_at
            && (! $this->expires_at || $this->expires_at->isFuture())
            && $this->coupon
            && $this->coupon->is_active
            && $this->coupon->inPeriod();
    }

    /** 상태 라벨 — 사용됨 | 만료 | 사용 가능 | 중지(쿠폰 비활성·기간 외). */
    public function statusLabel(): string
    {
        if ($this->used_at) {
            return '사용됨';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return '만료';
        }

        return $this->isUsable() ? '사용 가능' : '중지';
    }
}
