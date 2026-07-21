<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * 쿠폰(26) — 마케팅 상품 주문 할인. 관리자 발행·전체 발급·회원 다운로드.
 * ⚠️ 할인 계산은 discountFor() 한 곳으로 — 화면(JS)은 미리보기일 뿐, 최종 금액은 항상 서버 재계산.
 */
class Coupon extends Model
{
    public const TYPES = ['amount' => '정액(원)', 'percent' => '정률(%)'];

    protected $fillable = [
        'code', 'name', 'discount_type', 'discount_value', 'max_discount', 'min_order_amount',
        'starts_at', 'ends_at', 'valid_days', 'is_downloadable', 'max_issuance',
        'product_ids', 'memo', 'is_active', 'created_by',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2', 'max_discount' => 'decimal:2', 'min_order_amount' => 'decimal:2',
        'starts_at' => 'date', 'ends_at' => 'date',
        'valid_days' => 'integer', 'max_issuance' => 'integer',
        'is_downloadable' => 'boolean', 'is_active' => 'boolean',
        'product_ids' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            if (! $c->code) {
                do {
                    $code = 'CP'.now()->format('ymd').strtoupper(Str::random(6));
                } while (self::where('code', $code)->exists());
                $c->code = $code;
            }
        });
    }

    public function userCoupons(): HasMany
    {
        return $this->hasMany(UserCoupon::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** 주문 금액에 대한 할인액(원 단위 내림, 주문 금액 초과 불가). 최소 주문 금액 미달이면 0. */
    public function discountFor(float $amount): int
    {
        if ($amount <= 0 || $amount < (float) $this->min_order_amount) {
            return 0;
        }
        if ($this->discount_type === 'percent') {
            $d = $amount * ((float) $this->discount_value / 100);
            if ($this->max_discount !== null) {
                $d = min($d, (float) $this->max_discount);
            }
        } else {
            $d = (float) $this->discount_value;
        }

        return (int) min(floor($d), floor($amount));
    }

    /** 오늘이 쿠폰 사용 가능 기간 안인지(시작~종료일, null 은 무제한). */
    public function inPeriod(): bool
    {
        $today = now()->startOfDay();
        if ($this->starts_at && $today->lt($this->starts_at->startOfDay())) {
            return false;
        }
        if ($this->ends_at && $today->gt($this->ends_at->endOfDay())) {
            return false;
        }

        return true;
    }

    /** 이 상품에 적용 가능한지(product_ids null = 전체 상품). */
    public function appliesTo(int $productId): bool
    {
        $ids = $this->product_ids;

        return ! $ids || in_array($productId, array_map('intval', $ids), true);
    }

    /** 발급분 만료일 — 쿠폰 종료일과 발급일+valid_days 중 이른 쪽(둘 다 없으면 null). */
    public function expiresAtForIssue(): ?\Illuminate\Support\Carbon
    {
        $candidates = array_filter([
            $this->valid_days ? now()->addDays($this->valid_days)->endOfDay() : null,
            $this->ends_at?->copy()->endOfDay(),
        ]);

        return $candidates ? min($candidates) : null;
    }

    /** 남은 발급 가능 수(무제한이면 null). */
    public function remainingIssuance(): ?int
    {
        if ($this->max_issuance === null) {
            return null;
        }

        return max(0, $this->max_issuance - $this->userCoupons()->count());
    }

    /** 회원이 지금 다운로드 가능한지(활성·다운로드형·기간 내·수량 여유·미보유). */
    public function downloadableBy(User $user): bool
    {
        return $this->is_active && $this->is_downloadable && $this->inPeriod()
            && ($this->remainingIssuance() === null || $this->remainingIssuance() > 0)
            && ! $this->userCoupons()->where('user_id', $user->id)->exists();
    }

    /** 할인 표기 — '5,000원' / '10% (최대 3,000원)'. */
    public function discountLabel(): string
    {
        if ($this->discount_type === 'percent') {
            $s = rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.').'%';
            if ($this->max_discount !== null) {
                $s .= ' (최대 '.number_format((float) $this->max_discount).'원)';
            }

            return $s;
        }

        return number_format((float) $this->discount_value).'원';
    }
}
