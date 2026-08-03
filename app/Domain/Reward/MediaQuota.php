<?php

namespace App\Domain\Reward;

use App\Models\RewardMission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 매체별 배분 상한(design-04 §2-1) — 매체마다 지급 단가와 처리 능력이 다르므로
 * "이 매체는 이 미션을 하루 몇 건까지" 를 정할 수 있어야 한다.
 *
 * 규칙(reward_media_allocations)은 좁은 범위가 우선한다: mission > kind > all.
 * 규칙이 없으면 **제한 없음**(공유 풀) — 기존 동작과 같다.
 * 집행은 QuotaGate 와 같은 방식으로 확정 트랜잭션 안 조건부 원자 UPDATE 다.
 */
final class MediaQuota
{
    /** 제한 없음을 뜻하는 cap — 미션 일 수량을 그대로 쓴다 */
    public const UNLIMITED = null;

    /**
     * 그 매체가 그 미션을 하루 몇 건까지 가져갈 수 있는지. null = 제한 없음.
     *
     * @param  array<string, mixed>  $rules  media_id 로 미리 로드한 규칙들
     */
    public static function capFor(RewardMission|array $mission, array $rules): ?int
    {
        $missionId = is_array($mission) ? (int) $mission['id'] : $mission->id;
        $kind = is_array($mission) ? (string) ($mission['kind'] ?? '') : (string) $mission->kind;
        $quota = is_array($mission) ? (int) $mission['daily_quota'] : (int) $mission->daily_quota;

        $rule = $rules['mission:'.$missionId] ?? $rules['kind:'.$kind] ?? $rules['all:'] ?? null;
        if (! $rule) {
            return self::UNLIMITED;
        }

        $cap = null;
        if ($rule['ratio'] !== null) {
            $cap = (int) floor($quota * (int) $rule['ratio'] / 100);
        }
        if ($rule['max_per_day'] !== null) {
            $cap = $cap === null ? (int) $rule['max_per_day'] : min($cap, (int) $rule['max_per_day']);
        }

        return $cap;   // null 이면 규칙은 있으나 상한 항목이 비어 있음 = 제한 없음
    }

    /**
     * 매체의 활성 규칙을 조회 키('scope:key')로 뽑는다.
     *
     * @return array<string, array{ratio:?int, max_per_day:?int, min_per_day:int}>
     */
    public static function rulesFor(int $mediaId): array
    {
        return DB::table('reward_media_allocations')
            ->where('media_id', $mediaId)->where('is_active', true)
            ->get(['scope', 'scope_key', 'ratio', 'max_per_day', 'min_per_day'])
            ->mapWithKeys(fn ($r) => [$r->scope.':'.$r->scope_key => [
                'ratio' => $r->ratio === null ? null : (int) $r->ratio,
                'max_per_day' => $r->max_per_day === null ? null : (int) $r->max_per_day,
                'min_per_day' => (int) $r->min_per_day,
            ]])->all();
    }

    /**
     * 확정 트랜잭션 안에서 매체 몫을 원자적으로 소비한다. 상한 초과면 false.
     * 상한이 없으면(null) 카운터만 올리고 항상 통과 — 소진 현황은 어드민에서 봐야 하므로 기록은 남긴다.
     */
    public static function consume(int $missionId, int $mediaId, string $day, ?int $cap): bool
    {
        $sql = 'UPDATE reward_mission_media_counters SET used = used + 1, updated_at = ?
                  WHERE mission_id = ? AND media_id = ? AND stat_date = ?';
        $params = [now(), $missionId, $mediaId, $day];

        if ($cap !== null) {
            $sql .= ' AND used < ?';
            $params[] = $cap;
        }

        if (DB::update($sql, $params)) {
            return true;
        }

        // 행이 아직 없을 수 있다(첫 참여). 있는데 못 걸렸다면 상한 도달이다
        try {
            DB::table('reward_mission_media_counters')->insert([
                'mission_id' => $missionId, 'media_id' => $mediaId, 'stat_date' => $day,
                'cap' => $cap ?? 0, 'used' => 1, 'updated_at' => now(),
            ]);

            return $cap === null || $cap > 0;
        } catch (UniqueConstraintViolationException) {
            return false;   // 동시에 만들어졌고 UPDATE 가 상한에 막혔다
        }
    }

    /**
     * 그 매체가 지금 가져갈 수 있는 잔여(노출 필터용). null = 제한 없음.
     *
     * @param  array<int, array<string, mixed>>  $rows  스냅샷 행
     * @return array<int, int|null> mission_id => 잔여
     */
    public static function remainingFor(int $mediaId, array $rows, string $day): array
    {
        $rules = self::rulesFor($mediaId);
        if ($rules === []) {
            return [];   // 규칙 없음 = 전부 제한 없음
        }

        $used = DB::table('reward_mission_media_counters')
            ->where('media_id', $mediaId)->where('stat_date', $day)
            ->whereIn('mission_id', array_column($rows, 'id'))
            ->pluck('used', 'mission_id');

        $out = [];
        foreach ($rows as $m) {
            $cap = self::capFor($m, $rules);
            $out[(int) $m['id']] = $cap === null ? null : max(0, $cap - (int) ($used[$m['id']] ?? 0));
        }

        return $out;
    }
}
