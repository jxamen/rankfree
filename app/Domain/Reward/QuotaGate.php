<?php

namespace App\Domain\Reward;

use Illuminate\Support\Facades\DB;

/**
 * 미션 일 한도 게이트 — C8의 2단 UPDATE.
 * 1차: 구간 상한(slot_cap) + 일 한도 안에서 확정. 2차: 구간 상한만 초과(청구 가능 → 거절하지 않는다).
 * 둘 다 실패 = 일 한도 소진(quota_full, 진짜 손해가 나는 유일한 경우)만 거절.
 * 반드시 확정 트랜잭션 안에서 호출한다. seq_in_day 는 billable 판정(seq <= daily_quota)의 유일한 입력이다.
 */
final class QuotaGate
{
    /** @return array{seq: int, slot_overflow: bool}|null null = 일 한도 소진 */
    public static function consume(int $missionId, string $statDate, int $slotCap): ?array
    {
        $seq = self::attempt($missionId, $statDate, $slotCap, false);
        if ($seq !== null) {
            return ['seq' => $seq, 'slot_overflow' => false];
        }

        $seq = self::attempt($missionId, $statDate, null, true);
        if ($seq !== null) {
            return ['seq' => $seq, 'slot_overflow' => true];
        }

        return null;
    }

    /**
     * 벤더 경로 전용(design-04 §7) — 구간 상한을 넘기지 않는다. 벤더가 하루 물량을 몇 분에 몰아치면
     * 시간대 분산 자체가 무의미해지므로, 슬롯이 차면 다음 슬롯까지 기다리게 한다.
     *
     * @return array{seq: int|null, reason?: string} seq=null 이면 reason 은 quota_full | slot_exhausted
     */
    public static function consumeWithinSlot(int $missionId, string $statDate, int $slotCap): array
    {
        $seq = self::attempt($missionId, $statDate, $slotCap, false);
        if ($seq !== null) {
            return ['seq' => $seq];
        }

        $row = DB::table('reward_mission_daily_counters')
            ->where('mission_id', $missionId)->where('stat_date', $statDate)
            ->first(['used', 'daily_quota']);

        return ['seq' => null,
            'reason' => (! $row || (int) $row->used >= (int) $row->daily_quota) ? 'quota_full' : 'slot_exhausted'];
    }

    private static function attempt(int $missionId, string $statDate, ?int $slotCap, bool $slotOverflow): ?int
    {
        $overflowSet = $slotOverflow ? ', slot_overflow_count = slot_overflow_count + 1' : '';
        $slotCond = $slotCap !== null ? ' AND used < ?' : '';
        $params = array_merge([$missionId, $statDate], $slotCap !== null ? [$slotCap] : []);

        if (DB::getDriverName() === 'sqlite') {
            // sqlite(로컬·테스트): 쓰기가 직렬화되므로 UPDATE 후 SELECT 로 순번을 읽어도 안전하다
            $affected = DB::update(
                "UPDATE reward_mission_daily_counters
                    SET used = used + 1, last_used_at = CURRENT_TIMESTAMP,
                        first_used_at = COALESCE(first_used_at, CURRENT_TIMESTAMP){$overflowSet}
                  WHERE mission_id = ? AND stat_date = ? AND used < daily_quota{$slotCond}",
                $params,
            );

            return $affected
                ? (int) DB::table('reward_mission_daily_counters')
                    ->where('mission_id', $missionId)->where('stat_date', $statDate)->value('used')
                : null;
        }

        // MariaDB: LAST_INSERT_ID(expr)는 커넥션 스코프라 동시 요청에서도 자기 순번만 돌아온다
        $affected = DB::update(
            "UPDATE reward_mission_daily_counters
                SET used = LAST_INSERT_ID(used + 1), last_used_at = NOW(),
                    first_used_at = COALESCE(first_used_at, NOW()){$overflowSet}
              WHERE mission_id = ? AND stat_date = ? AND used < daily_quota{$slotCond}",
            $params,
        );

        return $affected ? (int) DB::selectOne('SELECT LAST_INSERT_ID() AS seq')->seq : null;
    }
}
