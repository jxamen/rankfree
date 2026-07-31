<?php

namespace App\Domain\Reward;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 식별자(IP·ADID) 한도 게이트(design-04 §8) — UserMissionCap 과 같은 2-step 조건부 원자 갱신.
 * 사전검사(check)는 사용자에게 빠른 사유를 주기 위한 것이고, 진짜 한도는 consume 이 지킨다:
 * 사전 COUNT 만으로는 같은 IP 뒤 여러 사용자가 동시에 통과해 한도가 그대로 뚫린다.
 * 반드시 확정 트랜잭션 안에서, 공유 카운터(QuotaGate)보다 먼저 호출한다(C18 락 순서).
 */
final class IdentityGate
{
    public const TYPE_IP = 'ip';

    public const TYPE_ADID = 'adid';

    public static function hash(string $identifier): string
    {
        return hash('sha256', $identifier);
    }

    /** 한도 안이면 used +1 하고 true. 초과면 false(카운터 불변) */
    public static function consume(int $mediaId, string $idType, string $idHash, string $day, int $limit): bool
    {
        if ($limit <= 0) {
            return true;   // 0 = 끔(NAT 오탐 시)
        }

        $affected = DB::update(
            'UPDATE reward_identity_counters SET used = used + 1, updated_at = ?
              WHERE media_id = ? AND id_type = ? AND id_hash = ? AND scope = ? AND scope_key = ? AND used < ?',
            [now(), $mediaId, $idType, $idHash, 'day', $day, $limit],
        );
        if ($affected) {
            return true;
        }

        try {
            DB::table('reward_identity_counters')->insert([
                'media_id' => $mediaId, 'id_type' => $idType, 'id_hash' => $idHash,
                'scope' => 'day', 'scope_key' => $day, 'used' => 1, 'updated_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;   // 행이 이미 있는데 UPDATE 가 안 걸렸다 = 한도 도달
        }
    }

    /** 시도 수 누적(오답·거절 포함). 한도 초과여도 기록만 하고 막지 않는다 — 판정은 check 가 한다 */
    public static function bumpAttempt(int $mediaId, string $idType, string $idHash, string $day): void
    {
        $affected = DB::update(
            'UPDATE reward_identity_counters SET attempts = attempts + 1, updated_at = ?
              WHERE media_id = ? AND id_type = ? AND id_hash = ? AND scope = ? AND scope_key = ?',
            [now(), $mediaId, $idType, $idHash, 'day', $day],
        );
        if ($affected) {
            return;
        }

        try {
            DB::table('reward_identity_counters')->insert([
                'media_id' => $mediaId, 'id_type' => $idType, 'id_hash' => $idHash,
                'scope' => 'day', 'scope_key' => $day, 'attempts' => 1, 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            self::bumpAttempt($mediaId, $idType, $idHash, $day);   // 동시 생성 — 한 번만 재시도하면 UPDATE 가 걸린다
        }
    }

    /**
     * 사전검사 — 확정·시도 한도 중 걸리는 것이 있으면 그 사유를 돌려준다(없으면 null).
     * 카운터 행 1건 조회로 둘을 함께 본다.
     */
    public static function check(int $mediaId, string $idType, string $idHash, string $day,
        int $usedLimit, int $attemptLimit): ?string
    {
        if ($usedLimit <= 0 && $attemptLimit <= 0) {
            return null;
        }

        $row = DB::table('reward_identity_counters')
            ->where('media_id', $mediaId)->where('id_type', $idType)->where('id_hash', $idHash)
            ->where('scope', 'day')->where('scope_key', $day)
            ->first(['used', 'attempts']);
        if (! $row) {
            return null;
        }

        if ($usedLimit > 0 && (int) $row->used >= $usedLimit) {
            return 'ip_limit';
        }
        if ($attemptLimit > 0 && (int) $row->attempts >= $attemptLimit) {
            return 'ip_attempt_limit';
        }

        return null;
    }
}
