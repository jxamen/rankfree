<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * 차단 IP(2026-08-02) — 취약점 탐침(/.env·/.aws/credentials 등)을 보낸 출처.
 *
 * 영구 차단이 아니라 blocked_until 까지만 유지한다. 스캐너는 IP 를 계속 갈아타므로 영구 목록은
 * 커지기만 하고, 통신사 NAT 처럼 여러 사람이 공유하는 IP 를 잘못 잡았을 때 피해도 시간으로 제한된다.
 */
class BlockedIp extends Model
{
    protected $fillable = ['ip', 'reason', 'hit_path', 'hits', 'blocked_until'];

    protected $casts = [
        'blocked_until' => 'datetime',
        'hits' => 'integer',
    ];

    private const CACHE_KEY = 'security:blocked-ips';

    private const CACHE_TTL = 60;   // 초 — 차단 반영이 최대 1분 늦어도 무방하다

    /**
     * 현재 유효한 차단 IP 목록.
     * 요청마다 DB 를 때리지 않도록 캐시하되, **문자열 배열만** 담는다
     * (운영 database 캐시에 Eloquent 객체를 넣으면 역직렬화가 깨진다).
     *
     * @return list<string>
     */
    public static function activeList(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => static::query()
            ->where(fn ($q) => $q->whereNull('blocked_until')->orWhere('blocked_until', '>', now()))
            ->pluck('ip')
            ->all());
    }

    public static function isBlocked(string $ip): bool
    {
        return in_array($ip, static::activeList(), true);
    }

    /** 차단 등록·연장. $ttlHours 가 0 이면 무기한(수동 차단). */
    public static function block(string $ip, string $path, string $reason = 'probe', ?int $ttlHours = null): self
    {
        $ttlHours ??= (int) config('security.probe_block.ttl_hours', 24);

        $row = static::query()->firstOrNew(['ip' => $ip]);
        $row->reason = $reason;
        $row->hit_path = mb_substr($path, 0, 255);
        $row->blocked_until = $ttlHours > 0 ? now()->addHours($ttlHours) : null;
        $row->hits = ($row->exists ? $row->hits : 0) + 1;
        $row->save();

        static::flushCache();

        return $row;
    }

    public static function unblock(string $ip): bool
    {
        $deleted = (bool) static::query()->where('ip', $ip)->delete();
        static::flushCache();

        return $deleted;
    }

    /** 만료된 기록 정리 — 목록이 무한정 불어나지 않게. */
    public static function prune(): int
    {
        $n = static::query()->whereNotNull('blocked_until')->where('blocked_until', '<=', now())->delete();
        static::flushCache();

        return $n;
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
