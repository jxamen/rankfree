<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** 외부 API 키 — 원문은 발급 시 1회만 노출, DB에는 sha256 해시만 저장. */
class ApiKey extends Model
{
    /** scope 코드 → 표시명 (경량/상세 키워드분석은 별도 상품으로 분리) */
    public const SCOPES = [
        'rank' => '순위추적',
        'compete' => '경쟁분석',
        'keyword' => '키워드분석',
        'keyword_detail' => '키워드 상세분석',
        'order' => '마케팅 상품 주문',
    ];

    protected $fillable = [
        'user_id', 'name', 'key_prefix', 'key_hash', 'key_encrypted', 'scopes',
        'allowed_ips', 'expires_at', 'daily_limit', 'is_active', 'last_used_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'key_encrypted' => 'encrypted', // 소유자 재복사용 원문(암호화 저장)
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'is_active' => 'boolean',
        'daily_limit' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ApiKeyUsage::class);
    }

    /**
     * 키 발급 — [모델, 평문 키] 반환. 평문은 이 시점 이후 복구 불가.
     *
     * @param  string[]  $scopes
     * @return array{0: self, 1: string}
     */
    public static function issue(User $user, string $name, array $scopes, ?string $expiresAt, ?int $dailyLimit, ?string $allowedIps): array
    {
        $plain = 'rk_'.Str::random(45);

        $key = $user->apiKeys()->create([
            'name' => $name,
            'key_prefix' => substr($plain, 0, 11),
            'key_hash' => hash('sha256', $plain),
            'key_encrypted' => $plain,
            'scopes' => array_values(array_unique($scopes)),
            'allowed_ips' => $allowedIps !== null && trim($allowedIps) !== '' ? trim($allowedIps) : null,
            'expires_at' => $expiresAt,
            'daily_limit' => $dailyLimit,
            'is_active' => true,
        ]);

        return [$key, $plain];
    }

    /** 소유자 재복사용 원문 — 암호화 저장분이 있으면 복호화, 없으면(구 키) null. */
    public function plainKey(): ?string
    {
        try {
            return $this->key_encrypted ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 키 원문 회전(재발급) — 이름·권한·설정 유지, 새 평문 반환. */
    public function rotate(): string
    {
        $plain = 'rk_'.Str::random(45);
        $this->update([
            'key_prefix' => substr($plain, 0, 11),
            'key_hash' => hash('sha256', $plain),
            'key_encrypted' => $plain,
        ]);

        return $plain;
    }

    public static function findByPlain(?string $plain): ?self
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        return static::where('key_hash', hash('sha256', $plain))->first();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }

    /** 허용 IP 검사 — 비면 전체 허용. 줄바꿈/쉼표 구분, 정확 일치 + 접두 와일드카드(예: 1.2.3.*) */
    public function ipAllowed(?string $ip): bool
    {
        $rules = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $this->allowed_ips) ?: []));
        if (! count($rules)) {
            return true;
        }
        foreach ($rules as $rule) {
            if ($rule === '*' || $rule === $ip) {
                return true;
            }
            if (str_ends_with($rule, '*') && $ip !== null && str_starts_with($ip, rtrim($rule, '*'))) {
                return true;
            }
        }

        return false;
    }

    public function usedToday(): int
    {
        return (int) $this->usages()->where('used_date', now()->toDateString())->value('count');
    }
}
