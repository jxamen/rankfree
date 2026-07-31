<?php

namespace App\Domain\Reward;

use App\Models\RewardMission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 사용자×미션 상한 2-step 원자 갱신(design-01 §2-7) — per_user_limit·per_user_daily_limit.
 * ON DUPLICATE KEY UPDATE 를 쓰지 않는 이유: 상한 조건부 거부를 표현할 수 없어 초과가 통과한다.
 * 반드시 확정 트랜잭션 안에서, 공유 카운터(QuotaGate)보다 먼저 호출한다(C18 락 순서).
 */
final class UserMissionCap
{
    public static function consume(int $userId, RewardMission $mission, string $day, Carbon $now,
        string $rejectReason = 'mission_cap', string $userMessage = '이미 참여한 미션이에요.'): void
    {
        $affected = DB::update(
            'UPDATE reward_user_mission_counters SET
                done_count = done_count + 1,
                today_count = CASE WHEN last_done_on = ? THEN today_count + 1 ELSE 1 END,
                last_done_on = ?
              WHERE reward_user_id = ? AND mission_id = ?
                AND done_count < ?
                AND (CASE WHEN last_done_on = ? THEN today_count ELSE 0 END) < ?',
            [$day, $day, $userId, $mission->id,
                (int) $mission->per_user_limit, $day, (int) $mission->per_user_daily_limit],
        );

        if ($affected) {
            return;
        }

        try {
            DB::table('reward_user_mission_counters')->insert([
                'reward_user_id' => $userId, 'mission_id' => $mission->id,
                'done_count' => 1, 'today_count' => 1, 'last_done_on' => $day, 'created_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new GateRejected($rejectReason, $userMessage);
        }
    }
}
