<?php

namespace App\Domain\Reward;

use App\Models\FarmPlanting;
use App\Models\RewardMedia;
use App\Models\RewardMission;
use App\Models\RewardUser;
use App\Support\RewardDay;
use Illuminate\Support\Facades\DB;

/**
 * 미션 제출 — A 사전 게이트 → B 채점 → C 확정 트랜잭션(C18 5단계) → D 응답.
 * 순서가 곧 보안이다: 게이트를 통과하기 전에는 정답 여부를 알려주지 않는다(내일 쓸 정답 탐색 차단).
 * 거절 응답은 전부 200 {correct:false, message}(미니앱 UX — server-api-spec §2).
 * 거절·오답 로그는 트랜잭션 밖에서 INSERT 한다(롤백에 휩쓸리면 어뷰징 추적 불가).
 */
class MissionSubmitService
{
    public function submit(RewardMedia $media, RewardUser $user, int $missionId, string $answer, ?string $ip): array
    {
        $day = RewardDay::current();
        $now = now();
        $slotNo = SlotCap::slotNo();
        $mission = RewardMission::query()->find($missionId);

        // ── A. 사전 게이트 (정답을 절대 보지 않는다 — design-02 §8-3, C16 컬럼 게이트) ──────────
        if ($slotNo === null) {
            return $this->reject($media, $user, $mission, $missionId, $day, 0, 'closed', '미션은 아침 6시에 열려요.', $ip);
        }
        if ($user->status !== 'active') {
            return $this->reject($media, $user, $mission, $missionId, $day, $slotNo, 'blocked', '지금은 참여할 수 없어요.', $ip);
        }

        $minGap = (int) $media->setting('submit_min_interval_seconds');
        if ($user->last_submit_at && $user->last_submit_at->gt($now->copy()->subSeconds($minGap))) {
            return $this->reject($media, $user, $mission, $missionId, $day, $slotNo, 'too_fast', '잠시 후 다시 시도해 주세요.', $ip);
        }

        $isToday = $user->today_date?->toDateString() === $day;
        if (($isToday ? (int) $user->today_attempts : 0) >= (int) $media->setting('daily_attempt_limit')) {
            return $this->reject($media, $user, $mission, $missionId, $day, $slotNo, 'too_fast', '오늘은 더 시도할 수 없어요.', $ip);
        }

        // 식별자(IP) 한도 — 사전검사는 사유 안내용이고, 진짜 한도는 확정 트랜잭션의 IdentityGate 가 지킨다(§8)
        $ipLimit = (int) $media->setting('ip_daily_limit');
        $ipAttemptLimit = (int) $media->setting('ip_attempt_limit');
        $ipHash = $ip ? IdentityGate::hash($ip) : null;
        if ($ipHash && ($hit = IdentityGate::check($media->id, IdentityGate::TYPE_IP, $ipHash, $day, $ipLimit, $ipAttemptLimit))) {
            return $this->reject($media, $user, $mission, $missionId, $day, $slotNo, $hit, '잠시 후 다시 시도해 주세요.', $ip);
        }

        if ($user->cooldown_until && $user->cooldown_until->isFuture()) {
            $at = $user->cooldown_until->tz('Asia/Seoul');

            return $this->reject($media, $user, $mission, $missionId, $day, $slotNo, 'cooldown',
                '다음 미션은 '.$at->format('H:i').'에 열려요.', $ip, ['nextMissionAt' => $at->toIso8601String()]);
        }

        $dailyLimit = (int) $media->setting('daily_mission_limit');
        if (($isToday ? (int) $user->today_count : 0) >= $dailyLimit) {
            return $this->reject($media, $user, $mission, $missionId, $day, $slotNo, 'daily_limit', '오늘 참여를 모두 마쳤어요.', $ip);
        }

        // 대상 밭 — growing 중 오늘 아직 안 돌본 밭(낮은 plot_index 우선). 서버가 고른다
        $growing = FarmPlanting::query()
            ->where('reward_user_id', $user->id)->where('status', 'growing')
            ->orderBy('plot_index')->get();
        if ($growing->isEmpty()) {
            return $this->reject($media, $user, $mission, $missionId, $day, $slotNo, 'plot_empty', '먼저 밭에 작물을 심어 주세요.', $ip);
        }
        $planting = $growing->first(fn ($p) => ! $p->last_tended_on || $p->last_tended_on->toDateString() < $day);
        if (! $planting) {
            return $this->reject($media, $user, $mission, $missionId, $day, $slotNo, 'plot_done', '오늘은 모든 밭을 돌봤어요.', $ip);
        }

        if (! $mission || $mission->status !== 'active'
            || $day < $mission->starts_on->toDateString() || $day > $mission->ends_on->toDateString()) {
            return $this->reject($media, $user, $mission, $missionId, $day, $slotNo, 'mission_closed', '종료된 미션이에요.', $ip);
        }

        $counter = DB::table('reward_user_mission_counters')
            ->where('reward_user_id', $user->id)->where('mission_id', $mission->id)->first();
        if ($counter && ((int) $counter->done_count >= (int) $mission->per_user_limit
            || ($counter->last_done_on === $day && (int) $counter->today_count >= (int) $mission->per_user_daily_limit))) {
            return $this->reject($media, $user, $mission, $missionId, $day, $slotNo, 'mission_cap', '이미 참여한 미션이에요.', $ip);
        }

        $payout = (int) $mission->payout_point;
        $pointCap = (int) $media->setting('point_cap');
        if ((int) $user->accrued_points + $payout > $pointCap) {
            return $this->reject($media, $user, $mission, $missionId, $day, $slotNo, 'point_cap', '더 받을 수 있는 포인트가 없어요.', $ip);
        }

        // ── B. 채점 (모든 사전 게이트 통과 후에만) ──────────────────────────────────────────
        $graded = MissionGrader::grade($mission, $user, $day, $answer);
        if (! $graded['correct']) {
            // 오답은 미션·한도 카운터를 건드리지 않는다(hot row 보호) — 시도 수·로그만 남긴다
            $this->bumpAttempt($media, $user, $day, $now, $ipHash);
            $this->insertLog($media, $user, $mission, $missionId, $day, $slotNo, 'wrong', null, $graded['norm'], $ip, $planting);

            return ['correct' => false, 'message' => '다시 한 번 확인해 주세요.'];
        }

        // ── C. 확정 트랜잭션 (C18 — ③→④ 순서 불변 · 내부에서 외부 호출 금지) ──────────────
        $slotCap = SlotCap::at((int) $mission->daily_quota, $slotNo);
        $jitter = (int) $media->setting('cooldown_jitter_minutes');
        $cooldownUntil = $now->copy()->addMinutes((int) $media->setting('cooldown_minutes') + ($jitter > 0 ? random_int(-$jitter, $jitter) : 0));
        $dayNo = (int) $planting->completed_days + 1;

        try {
            $gate = DB::transaction(function () use ($media, $user, $mission, $planting, $day, $now, $slotNo, $slotCap, $dailyLimit, $pointCap, $payout, $cooldownUntil, $dayNo, $ip, $ipHash, $ipLimit) {
                // ① 사용자 원자 UPDATE — 일 상한·쿨다운·포인트 상한·시도·IP 를 한 문장에 (design-01 §2-1)
                $affected = DB::update(
                    "UPDATE reward_users SET
                        today_count = CASE WHEN today_date = ? THEN today_count + 1 ELSE 1 END,
                        today_attempts = CASE WHEN today_date = ? THEN today_attempts + 1 ELSE 1 END,
                        today_date = ?,
                        cooldown_until = ?, last_submit_at = ?, last_participated_at = ?,
                        total_participations = total_participations + 1,
                        accrued_points = accrued_points + ?, daily_ip = ?, updated_at = ?
                      WHERE id = ? AND status = 'active'
                        AND (today_date IS NULL OR today_date <> ? OR today_count < ?)
                        AND (cooldown_until IS NULL OR cooldown_until <= ?)
                        AND accrued_points + ? <= ?",
                    [$day, $day, $day, $cooldownUntil, $now, $now, $payout, $ip, $now,
                        $user->id, $day, $dailyLimit, $now, $payout, $pointCap],
                );
                if (! $affected) {
                    // 사전 게이트와 락 획득 사이의 경합 — 사유는 후속 SELECT 1회로 구분(design-01 §2-1)
                    $fresh = DB::table('reward_users')->where('id', $user->id)->first();
                    if ($fresh->cooldown_until && $fresh->cooldown_until > $now->toDateTimeString()) {
                        throw new GateRejected('cooldown', '다음 미션은 잠시 후에 열려요.');
                    }
                    if ((int) $fresh->accrued_points + $payout > $pointCap) {
                        throw new GateRejected('point_cap', '더 받을 수 있는 포인트가 없어요.');
                    }
                    throw new GateRejected('daily_limit', '오늘 참여를 모두 마쳤어요.');
                }

                // ② 밭 하루치 성장 원자 UPDATE — day_mask 비트가 최종 방어(design-01 §2-8)
                // status 판정은 PHP 가 계산한 $dayNo 를 바인딩한다: MariaDB 는 SET 을 좌→우로 평가해
                // 앞에서 갱신한 completed_days 를 뒤 표현식이 보므로, 컬럼 참조로 쓰면 하루 일찍 ready 가 된다.
                $bit = 1 << ($dayNo - 1);
                $affected = DB::update(
                    "UPDATE farm_plantings SET
                        day_mask = day_mask | ?, completed_days = completed_days + 1,
                        last_tended_on = ?, accrued_points = accrued_points + ?,
                        status = CASE WHEN ? >= required_days THEN 'ready' ELSE 'growing' END,
                        updated_at = ?
                      WHERE id = ? AND reward_user_id = ? AND status = 'growing'
                        AND (last_tended_on IS NULL OR last_tended_on < ?)
                        AND (day_mask & ?) = 0",
                    [$bit, $day, $payout, $dayNo, $now, $planting->id, $user->id, $day, $bit],
                );
                if (! $affected) {
                    throw new GateRejected('plot_done', '이 밭은 오늘 이미 돌봤어요.');
                }

                // ③ 사용자×미션 카운터 2-step — 사용자 자원을 먼저, 공유 자원(④)을 마지막에 가장 짧게
                UserMissionCap::consume($user->id, $mission, $day, $now);

                // ③-b 식별자(IP) 한도 원자 소비 — 같은 IP 뒤 여러 사용자가 동시에 통과하는 것을 여기서 막는다
                if ($ipHash && ! IdentityGate::consume($media->id, IdentityGate::TYPE_IP, $ipHash, $day, $ipLimit)) {
                    throw new GateRejected('ip_limit', '잠시 후 다시 시도해 주세요.');
                }

                // ④ 미션 일 한도 — C8 2단 UPDATE. 구간 상한 초과는 통과(청구 가능), 일 한도 소진만 거절
                $gate = QuotaGate::consume($mission->id, $day, $slotCap);
                if ($gate === null) {
                    throw new GateRejected('quota_full', '방금 마감됐어요. 다른 미션을 해보세요.');
                }

                // ⑤ 참여 로그 — append-only. 정산(billable = seq_in_day <= daily_quota)의 원천
                DB::table('reward_participation_logs')->insert([
                    'media_id' => $media->id,
                    'stat_month' => (int) substr(str_replace('-', '', $day), 0, 6),
                    'stat_date' => $day, 'slot_no' => $slotNo,
                    'reward_user_id' => $user->id, 'mission_id' => $mission->id,
                    'order_item_id' => $mission->order_item_id, 'order_id' => $mission->order_id,
                    'vendor_id' => $mission->vendor_id,
                    'planting_id' => $planting->id, 'plot_index' => $planting->plot_index,
                    'day_no' => $dayNo, 'round_no' => $planting->round_no, 'crop_id' => $planting->crop_id,
                    'result' => 'correct', 'answer_norm' => null,
                    'unit_revenue' => $mission->unit_revenue, 'payout_point' => $payout,
                    'seq_in_day' => $gate['seq'], 'daily_quota' => $mission->daily_quota,
                    'is_overflow' => $gate['seq'] > (int) $mission->daily_quota,
                    'slot_overflow' => $gate['slot_overflow'],
                    'ip' => $ip, 'created_at' => $now,
                ]);

                return $gate;
            });
        } catch (GateRejected $e) {
            $this->bumpAttempt($media, $user, $day, $now, $ipHash);
            $this->insertLog($media, $user, $mission, $missionId, $day, $slotNo, 'rejected', $e->reason, null, $ip, $planting);

            return ['correct' => false, 'message' => $e->userMessage];
        }

        // ── D. 커밋 후 — 캐시 무효화는 Phase 4(캐시 도입)에서. 응답에 다음 참여 시각 동봉 ──────
        $user->refresh();

        return [
            'correct' => true,
            'reward' => ['item' => $mission->reward_item, 'count' => (int) $mission->reward_count],
            'points' => $payout,
            'nextMissionAt' => $user->cooldown_until?->tz('Asia/Seoul')->toIso8601String(),
        ];
    }

    /** A-게이트 거절 — 시도 수 반영 + 거절 로그(트랜잭션 밖) + 200 payload */
    private function reject(RewardMedia $media, RewardUser $user, ?RewardMission $mission, int $missionId,
        string $day, int $slotNo, string $reason, string $message, ?string $ip, array $extra = []): array
    {
        $this->bumpAttempt($media, $user, $day, now(), $ip ? IdentityGate::hash($ip) : null);
        $this->insertLog($media, $user, $mission, $missionId, $day, $slotNo, 'rejected', $reason, null, $ip, null);

        return ['correct' => false, 'message' => $message] + $extra;
    }

    /**
     * 시도 수(오답·거절 포함)는 확정 트랜잭션 밖에서도 증가한다(C16). 날짜가 바뀌면 오늘 카운터를 리셋한다.
     * 같은 시도를 식별자(IP) 축에도 남긴다 — 계정을 갈아도 브루트포스가 카운트되게.
     */
    private function bumpAttempt(RewardMedia $media, RewardUser $user, string $day,
        \Illuminate\Support\Carbon $now, ?string $ipHash): void
    {
        DB::update(
            'UPDATE reward_users SET
                today_count = CASE WHEN today_date = ? THEN today_count ELSE 0 END,
                today_attempts = CASE WHEN today_date = ? THEN today_attempts + 1 ELSE 1 END,
                today_date = ?, last_submit_at = ?, updated_at = ?
              WHERE id = ?',
            [$day, $day, $day, $now, $now, $user->id],
        );

        if ($ipHash) {
            IdentityGate::bumpAttempt($media->id, IdentityGate::TYPE_IP, $ipHash, $day);
        }
    }

    private function insertLog(RewardMedia $media, RewardUser $user, ?RewardMission $mission, int $missionId,
        string $day, int $slotNo, string $result, ?string $reason, ?string $answerNorm, ?string $ip, ?FarmPlanting $planting): void
    {
        DB::table('reward_participation_logs')->insert([
            'media_id' => $media->id,
            'stat_month' => (int) substr(str_replace('-', '', $day), 0, 6),
            'stat_date' => $day, 'slot_no' => $slotNo,
            'reward_user_id' => $user->id, 'mission_id' => $mission?->id ?? $missionId,
            'order_item_id' => $mission?->order_item_id ?? 0, 'order_id' => $mission?->order_id ?? 0,
            'vendor_id' => $mission?->vendor_id,
            'planting_id' => $planting?->id, 'plot_index' => $planting?->plot_index,
            'day_no' => $planting ? (int) $planting->completed_days + 1 : null,
            'round_no' => $planting?->round_no, 'crop_id' => $planting?->crop_id,
            'result' => $result, 'reject_reason' => $reason, 'answer_norm' => $answerNorm,
            'unit_revenue' => $mission?->unit_revenue ?? 0, 'payout_point' => $mission?->payout_point ?? 0,
            'seq_in_day' => 0, 'daily_quota' => $mission?->daily_quota ?? 0,
            'ip' => $ip, 'created_at' => now(),
        ]);
    }
}
