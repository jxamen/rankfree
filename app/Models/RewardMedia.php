<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'slug', 'name', 'type', 'vendor_id', 'api_user_id',
        'rate_limit_rps', 'payout_unit_price', 'verify_mode', 'settings', 'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    /** 매체 설정 — settings 값이 있으면 그 값, 없으면 config('reward.defaults.*') 폴백 */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key)
            ?? config('reward.defaults.'.$key, $default);
    }

    /**
     * API 키 소유 회원 → 벤더 매체 경량 조회(rate limiter 용, L1 30초).
     * 캐시엔 순수 배열만 — 매체 없으면 ['id' => null] 센티널.
     *
     * @return array{id: int|null, rate_limit_rps?: int}
     */
    public static function forApiUser(int $userId): array
    {
        return \App\Domain\Reward\RewardCache::remember('reward:media:u'.$userId,
            (int) config('reward.cache.ttl.media_l1'), 60,
            function () use ($userId) {
                $m = self::query()->where('type', self::TYPE_VENDOR_API)
                    ->where('api_user_id', $userId)->where('is_active', true)
                    ->first(['id', 'rate_limit_rps']);

                return $m ? ['id' => (int) $m->id, 'rate_limit_rps' => (int) $m->rate_limit_rps] : ['id' => null];
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
