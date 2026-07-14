<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'phone_verified_at', 'provider', 'provider_id', 'password', 'role', 'grade_id', 'operator_role_id', 'subscription_expires_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'subscription_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** 유료 구독 활성 여부 — 유료 등급이며 만료일이 없거나(무기한) 아직 안 지남. */
    public function subscriptionActive(): bool
    {
        if (! $this->grade || ! $this->grade->is_paid) {
            return false;
        }

        return $this->subscription_expires_at === null || $this->subscription_expires_at->isFuture();
    }

    public function featureUsages(): HasMany
    {
        return $this->hasMany(FeatureUsage::class);
    }

    /** 기능 월간 한도 — 등급 미지정이면 -1(무제한). */
    public function featureLimit(string $key): int
    {
        return $this->grade ? $this->grade->featureLimit($key) : -1;
    }

    /** 이번 달 사용량. */
    public function featureUsed(string $key): int
    {
        return (int) $this->featureUsages()
            ->where('feature', $key)
            ->where('period', now()->format('Y-m'))
            ->value('count');
    }

    /** 남은 횟수 (-1 한도는 무제한). */
    public function featureRemaining(string $key): int
    {
        $limit = $this->featureLimit($key);
        if ($limit < 0) {
            return PHP_INT_MAX;
        }

        return max(0, $limit - $this->featureUsed($key));
    }

    /**
     * 기능 사용 시도 — 한도 내면 카운트 1 증가 후 true, 초과/미제공이면 false.
     */
    public function tryConsumeFeature(string $key): bool
    {
        return $this->tryConsumeUsage($key, $this->featureLimit($key));
    }

    /** 명시 한도로 사용 시도(메뉴 등). -1 무제한, 0 미제공, N 월 N회. */
    public function tryConsumeUsage(string $key, int $limit): bool
    {
        if ($limit < 0) {
            return true; // 무제한
        }
        if ($limit === 0) {
            return false; // 미제공
        }

        $row = $this->featureUsages()->firstOrCreate(
            ['feature' => $key, 'period' => now()->format('Y-m')],
            ['count' => 0],
        );
        if ($row->count >= $limit) {
            return false;
        }
        $row->increment('count');

        return true;
    }

    /** 특정 키 이번 달 사용량. */
    public function usageUsed(string $key): int
    {
        return (int) $this->featureUsages()
            ->where('feature', $key)
            ->where('period', now()->format('Y-m'))
            ->value('count');
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(MemberGrade::class, 'grade_id');
    }

    public function operatorRole(): BelongsTo
    {
        return $this->belongsTo(OperatorRole::class, 'operator_role_id');
    }

    public function rankSlots(): HasMany
    {
        return $this->hasMany(PlaceRankSlot::class);
    }

    public function shopRankSlots(): HasMany
    {
        return $this->hasMany(ShopRankSlot::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function smartplaceAccounts(): HasMany
    {
        return $this->hasMany(SmartplaceAccount::class);
    }

    public function marketAnalyses(): HasMany
    {
        return $this->hasMany(MarketAnalysis::class);
    }

    public function bulkKeywords(): HasMany
    {
        return $this->hasMany(BulkKeyword::class);
    }

    public function productAnalyses(): HasMany
    {
        return $this->hasMany(ProductAnalysis::class);
    }

    public function placeStoreAnalyses(): HasMany
    {
        return $this->hasMany(PlaceStoreAnalysis::class);
    }

    public function sellerPowerAnalyses(): HasMany
    {
        return $this->hasMany(SellerPowerAnalysis::class);
    }

    /** 최상위 관리자 — role=super / 운영자레벨 is_super / config 이메일 목록. */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super'
            || (bool) $this->operatorRole?->is_super
            || in_array(
                strtolower((string) $this->email),
                array_map('strtolower', (array) config('rankfree.super_admins', [])),
                true,
            );
    }

    /** 운영자(관리 영역 접근 주체). */
    public function isOperator(): bool
    {
        return $this->isSuperAdmin()
            || $this->operator_role_id !== null
            || in_array($this->role, ['operator', 'admin'], true);
    }

    public function isAdmin(): bool
    {
        return $this->isOperator();
    }

    /** 순위 추적 슬롯 한도 — 등급 기준(무등급=무료 100). -1 = 무제한. 플레이스+쇼핑 공유 풀. */
    public function rankSlotLimit(): int
    {
        $lim = $this->grade?->rank_slot_limit;

        return $lim === null ? 100 : (int) $lim;
    }

    public function rankSlotsUsed(): int
    {
        return $this->rankSlots()->where('is_active', true)->count();
    }

    /** 쇼핑 순위추적 슬롯 사용량. */
    public function shopRankSlotsUsed(): int
    {
        return $this->shopRankSlots()->where('is_active', true)->count();
    }

    /** 순위추적 총 사용량 — 플레이스 + 쇼핑 합산(한도는 공유 풀). */
    public function rankSlotsUsedTotal(): int
    {
        return $this->rankSlotsUsed() + $this->shopRankSlotsUsed();
    }

    /** 합산 기준 추가 여유 슬롯 수(-1 한도는 매우 큰 값). */
    public function rankSlotsRemaining(): int
    {
        $lim = $this->rankSlotLimit();

        return $lim < 0 ? PHP_INT_MAX : max(0, $lim - $this->rankSlotsUsedTotal());
    }

    public function canAddRankSlot(): bool
    {
        $lim = $this->rankSlotLimit();

        return $lim < 0 || $this->rankSlotsUsedTotal() < $lim;
    }
}
