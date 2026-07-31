<?php

namespace App\Domain\Reward;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 리워드 2단 캐시(design-02 §7) — L1(APCu, 없으면 프로세스 static) → L2(선택 스토어) → resolver(DB).
 * 쓰기는 DB 원자 UPDATE 만, 무효화는 DEL 만(SET 갱신 금지 — 순서 역전에도 멱등, D14).
 * L2 는 서킷 브레이커로 감싼다: 연속 5회 실패 시 60초간 스킵(타임아웃 대기로 인한 워커 고갈 방지, §7-4).
 * 캐시에는 순수 배열만 저장한다(Eloquent 직렬화 사고 — rankfree 실사고 규칙).
 */
final class RewardCache
{
    private const BREAKER_KEY = 'reward:breaker';

    private const BREAKER_FAILS = 'reward:breaker:fail';

    /** @var array<string, array{0: mixed, 1: int}> APCu 미가용 환경(CLI·테스트)의 L1 폴백 */
    private static array $local = [];

    public static function remember(string $key, int $l1Ttl, int $l2Ttl, Closure $resolver): mixed
    {
        $hit = self::l1Get($key);
        if ($hit !== null) {
            return $hit;
        }

        $hit = self::l2Get($key);
        if ($hit !== null) {
            self::l1Put($key, $hit, $l1Ttl);

            return $hit;
        }

        $value = $resolver();
        if ($value !== null) {
            self::l1Put($key, $value, $l1Ttl);
            self::l2Put($key, $value, $l2Ttl);
        }

        return $value;
    }

    public static function forget(string $key): void
    {
        unset(self::$local[$key]);
        if (self::apcu()) {
            apcu_delete($key);
        }
        if ($store = self::l2()) {
            try {
                Cache::store($store)->forget($key);
            } catch (\Throwable) {
                // 무효화 실패는 TTL 이 흡수한다 — 요청을 죽이지 않는다
            }
        }
    }

    /** @param string[] $keys */
    public static function forgetMany(array $keys): void
    {
        foreach ($keys as $key) {
            self::forget($key);
        }
    }

    /** C11 미션 목록 버전 — 동기화·어드민 변경 시 INCR 하면 v{ver} 캐시 키가 통째로 갈린다 */
    public static function version(): int
    {
        $ver = self::l1Get('reward:ver');
        if ($ver === null) {
            $ver = (int) self::shared()->get('reward:ver', 1);   // 서버 간 공유 스토어 — 재생성 가능 값
            self::l1Put('reward:ver', $ver, 10);
        }

        return (int) $ver;
    }

    public static function bumpVersion(): void
    {
        try {
            self::shared()->add('reward:ver', 1, now()->addDays(7));
            self::shared()->increment('reward:ver');
        } catch (\Throwable $e) {
            Log::warning('reward.cache: 버전 INCR 실패 — '.$e->getMessage());
        }
        unset(self::$local['reward:ver']);
        if (self::apcu()) {
            apcu_delete('reward:ver');
        }
    }

    /** 테스트·커맨드용 — 현재 브레이커 상태(열림이면 L2 를 건너뛴다) */
    public static function isBreakerOpen(): bool
    {
        return self::breakerOpen();
    }

    // ── L1 — APCu(프로세스 간) 또는 static(프로세스 내) ─────────────────────────────

    private static function l1Get(string $key): mixed
    {
        if (self::apcu()) {
            $v = apcu_fetch($key, $ok);

            return $ok ? $v : null;
        }
        $entry = self::$local[$key] ?? null;
        if ($entry === null || $entry[1] < time()) {
            unset(self::$local[$key]);

            return null;
        }

        return $entry[0];
    }

    private static function l1Put(string $key, mixed $value, int $ttl): void
    {
        if (self::apcu()) {
            apcu_store($key, $value, $ttl);

            return;
        }
        self::$local[$key] = [$value, time() + $ttl];
    }

    // ── L2 — 설정된 스토어(redis 권장), 서킷 브레이커 부착 ──────────────────────────

    private static function l2Get(string $key): mixed
    {
        $store = self::l2();
        if ($store === null || self::breakerOpen()) {
            return null;
        }

        try {
            $v = Cache::store($store)->get($key);
            self::breakerPut(self::BREAKER_FAILS, null, 0);

            return $v;
        } catch (\Throwable $e) {
            self::tripBreaker($e);

            return null;
        }
    }

    private static function l2Put(string $key, mixed $value, int $ttl): void
    {
        $store = self::l2();
        if ($store === null || self::breakerOpen()) {
            return;
        }

        try {
            Cache::store($store)->put($key, $value, $ttl);
        } catch (\Throwable $e) {
            self::tripBreaker($e);
        }
    }

    private static function tripBreaker(\Throwable $e): void
    {
        $fails = (int) self::breakerGet(self::BREAKER_FAILS) + 1;
        self::breakerPut(self::BREAKER_FAILS, $fails, 120);
        if ($fails >= 5) {
            self::breakerPut(self::BREAKER_KEY, 1, 60);
            Log::warning('reward.cache: L2 차단(60초 DB 폴백) — '.$e->getMessage());
        }
    }

    private static function breakerOpen(): bool
    {
        return (bool) self::breakerGet(self::BREAKER_KEY);
    }

    /*
     * 브레이커 상태는 요청 간 살아남아야 의미가 있다(연속 5회 = 여러 요청에 걸친 누적).
     * APCu 가 있으면 그것으로 충분하고, 없으면 static 은 요청마다 초기화되므로
     * 공유 스토어(L2 와 독립)에 둔다 — 없으면 브레이커가 영원히 열리지 않는다.
     */
    private static function breakerGet(string $key): mixed
    {
        if (self::apcu()) {
            return self::l1Get($key);
        }

        try {
            return self::shared()->get($key);
        } catch (\Throwable) {
            return self::l1Get($key);
        }
    }

    private static function breakerPut(string $key, mixed $value, int $ttl): void
    {
        if (self::apcu()) {
            $value === null ? apcu_delete($key) : self::l1Put($key, $value, $ttl);

            return;
        }

        try {
            $value === null ? self::shared()->forget($key) : self::shared()->put($key, $value, $ttl);
        } catch (\Throwable) {
            if ($value === null) {
                unset(self::$local[$key]);
            } else {
                self::l1Put($key, $value, $ttl);
            }
        }
    }

    /** 서버 간 공유가 필요한 값(버전·브레이커)의 스토어 — 기본 스토어는 운영에서 file(서버 로컬)이다 */
    private static function shared(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('reward.cache.shared_store');

        return is_string($store) && $store !== '' ? Cache::store($store) : Cache::store();
    }

    private static function l2(): ?string
    {
        $store = config('reward.cache.l2_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    private static function apcu(): bool
    {
        static $ok = null;

        return $ok ??= function_exists('apcu_fetch') && function_exists('apcu_enabled') && apcu_enabled();
    }

    /** 테스트 전용 — 프로세스 L1 초기화 */
    public static function flushLocal(): void
    {
        self::$local = [];
    }
}
