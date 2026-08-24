<?php

namespace App\Models;

use App\Domain\Reward\RewardCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * 리워드 매체(design-04 §2) — 미니앱·벤더 API 등 참여 채널.
 * 매체별 환경설정은 settings(JSON)가 config('reward.defaults')를 오버라이드한다.
 */
class RewardMedia extends Model
{
    public const TYPE_MINIAPP = 'miniapp';

    public const TYPE_VENDOR_API = 'vendor_api';

    protected $table = 'reward_media';

    protected $fillable = [
        'slug', 'name', 'type', 'vendor_id',
        'rate_limit_rps', 'payout_unit_price', 'verify_mode', 'settings', 'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'api_key_encrypted' => 'encrypted',     // 운영자 재전달용 원문
        'api_key_last_used_at' => 'datetime',
    ];

    protected $hidden = ['api_key_hash', 'api_key_encrypted'];

    /** 매체 설정 — settings 값이 있으면 그 값, 없으면 config('reward.defaults.*') 폴백 */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key)
            ?? config('reward.defaults.'.$key, $default);
    }

    /**
     * 미션 유형별 지급 단가(원) — 지출 계산의 입력.
     * 유형(reward_missions.kind)별 행이 있으면 그 값, 없으면 매체 기본 단가로 폴백한다.
     * 대량 집계는 이 메서드를 행마다 부르지 말고 reward_media_payouts 를 조인한다.
     */
    public function payoutFor(?string $kind): int
    {
        $kind = trim((string) $kind);
        if ($kind !== '') {
            $price = \Illuminate\Support\Facades\DB::table('reward_media_payouts')
                ->where('media_id', $this->id)->where('kind', $kind)->value('unit_price');
            if ($price !== null) {
                return (int) $price;
            }
        }

        return (int) $this->payout_unit_price;
    }

    /**
     * 매체 전용 키 발급 — 원문은 이 호출에서만 돌려받는다(DB엔 sha256 해시 + 재전달용 암호화 원문).
     * 고객용 회원 키(api_keys)와 별개 체계다: 키가 곧 매체 인증 주체다.
     */
    public function issueKey(): string
    {
        $plain = 'rkm_'.Str::random(44);

        $this->forceFill([
            'api_key_prefix' => substr($plain, 0, 12),
            'api_key_hash' => hash('sha256', $plain),
            'api_key_encrypted' => $plain,
        ])->save();

        RewardCache::forget('reward:media:k'.hash('sha256', $plain));

        return $plain;
    }

    /** 운영자 재전달용 원문 — 암호화 저장분이 있으면 그 값, 없으면(키 미발급) null */
    public function plainKey(): ?string
    {
        try {
            return $this->api_key_encrypted ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function findByKey(?string $plain): ?self
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        return static::query()->where('api_key_hash', hash('sha256', $plain))->first();
    }

    /**
     * 매체 키 → 경량 조회(rate limiter 용, L1 30초). 캐시엔 순수 배열만 —
     * 키가 없거나 매체가 비활성이면 ['id' => null] 센티널.
     * 인증 자체는 findByKey(DB 직조회)가 하므로 이 캐시가 폐기된 키를 통과시키지는 않는다.
     *
     * @return array{id: int|null, rate_limit_rps?: int}
     */
    public static function forKey(?string $plain): array
    {
        if ($plain === null || $plain === '') {
            return ['id' => null];
        }

        return RewardCache::remember('reward:media:k'.hash('sha256', $plain),
            (int) config('reward.cache.ttl.media_l1'), 60,
            function () use ($plain) {
                $m = self::findByKey($plain);

                return $m && $m->is_active
                    ? ['id' => (int) $m->id, 'rate_limit_rps' => (int) $m->rate_limit_rps]
                    : ['id' => null];
            });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(RewardUser::class, 'media_id');
    }
}
