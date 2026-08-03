<?php

namespace App\Domain\Reward;

use App\Models\RewardMedia;
use App\Models\RewardMission;
use App\Models\RewardUser;
use App\Support\RewardDay;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 벤더 참여 제출(design-04 §3·§4-4) — 미니앱과 같은 코어 게이트(UserMissionCap·QuotaGate)를 지나되,
 * 밭·쿨다운(게임 규칙)·포인트 상한(토스 정책) 없이 식별자 한도 중심으로 판정한다.
 * 멱등키는 수락 건만 저장 — 같은 키 재시도는 첫 응답을 duplicate 로 재반환하고 카운터를 건드리지 않는다.
 * 거절 사유는 계약된 reason 만 노출한다(소프트 차단은 not_eligible 로 뭉개서 — §8).
 *
 * @return array{0: array, 1: int} [payload, http status]
 */
class VendorSubmitService
{
    public function submit(RewardMedia $media, int $missionId, string $participantHash,
        ?string $answer, string $idemKey, ?string $ip): array
    {
        // 멱등키 1차 조회 — 커밋된 수락 건이면 첫 응답 재반환(카운터 불변)
        if ($stored = $this->storedResponse($media, $idemKey)) {
            return [$this->duplicatePayload($stored), 200];
        }

        $day = RewardDay::current();
        $now = now();
        $slotNo = SlotCap::slotNo();
        $mission = RewardMission::query()->find($missionId);

        if ($slotNo === null) {
            return [['status' => 'rejected', 'reason' => 'closed',
                'retry_after_seconds' => now()->diffInSeconds(RewardDay::start(\Illuminate\Support\Carbon::parse($day)->addDay()->toDateString()), true)], 422];
        }
        if (! $mission) {
            return [['status' => 'rejected', 'reason' => 'not_found'], 410];
        }
        if ($mission->status !== 'active'
            || $day < $mission->starts_on->toDateString() || $day > $mission->ends_on->toDateString()) {
            return [['status' => 'rejected', 'reason' => 'closed'], 422];
        }

        $user = $this->participant($media, $participantHash);
        if ($user->status !== 'active') {
            // 소프트 차단 — 세부 사유는 내부에만(§8). 벤더 응답은 중립 사유로 뭉갠다
            $this->log($media, $user, $mission, $day, $slotNo, 'rejected', 'blocked', null, $ip);

            return [['status' => 'rejected', 'reason' => 'not_eligible'], 422];
        }

        $ipHash = $ip ? IdentityGate::hash($ip) : null;
        $ipLimit = (int) $media->setting('ip_daily_limit');
        $attemptLimit = (int) $media->setting('daily_attempt_limit');

        // 시도 한도 — 오답이 공짜면 정답을 무제한으로 두드릴 수 있다(§4-6 verify_mode=server 의 전제)
        $isToday = $user->today_date?->toDateString() === $day;
        if ($attemptLimit > 0 && ($isToday ? (int) $user->today_attempts : 0) >= $attemptLimit) {
            $this->rejectAttempt($media, $user, $mission, $day, $slotNo, 'attempt_limit', $ip, $ipHash);

            return [['status' => 'rejected', 'reason' => 'not_eligible'], 422];
        }
        if ($ipHash && ($hit = IdentityGate::check($media->id, IdentityGate::TYPE_IP, $ipHash, $day,
            $ipLimit, (int) $media->setting('ip_attempt_limit')))) {
            $this->rejectAttempt($media, $user, $mission, $day, $slotNo, $hit, $ip, $ipHash);

            return [['status' => 'rejected', 'reason' => 'not_eligible'], 422];
        }

        // 식별자 사전 검사(원자 확정은 트랜잭션 안에서 다시) — 명확한 사유를 돌려줄 수 있는 것만 여기서
        $counter = DB::table('reward_user_mission_counters')
            ->where('reward_user_id', $user->id)->where('mission_id', $mission->id)->first();
        if ($counter && ((int) $counter->done_count >= (int) $mission->per_user_limit
            || ($counter->last_done_on === $day && (int) $counter->today_count >= (int) $mission->per_user_daily_limit))) {
            // 첫 요청이 방금 커밋된 재시도일 수 있다 — 멱등 계약이 한도보다 우선한다
            if ($stored = $this->storedResponse($media, $idemKey)) {
                return [$this->duplicatePayload($stored), 200];
            }
            $this->rejectAttempt($media, $user, $mission, $day, $slotNo, 'mission_cap', $ip, $ipHash);

            return [['status' => 'rejected', 'reason' => 'participant_duplicate'], 422];
        }

        // 채점 — verify_mode=server 만. vendor 모드는 벤더 자율 + 사후 감사(§4-6)
        if ($media->verify_mode === 'server') {
            $graded = MissionGrader::grade($mission, $user, $day, (string) $answer);
            if (! $graded['correct']) {
                $this->bumpAttempt($media, $user, $day, $now, $ipHash);
                $this->log($media, $user, $mission, $day, $slotNo, 'wrong', null, $graded['norm'], $ip);

                return [['status' => 'rejected', 'reason' => 'verify_failed'], 422];
            }
        }

        $slotCap = SlotCap::at((int) $mission->daily_quota, $slotNo);
        $mediaCap = MediaQuota::capFor($mission, MediaQuota::rulesFor($media->id));   // 매체 배분 상한(§2-1)
        $dailyLimit = (int) $media->setting('daily_mission_limit');         // 식별자 일 한도(§8 표 기본 3)
        $cooldownMin = (int) data_get($media->settings, 'cooldown_minutes', 0);   // 벤더는 명시 설정시에만 쿨다운

        try {
            $result = DB::transaction(function () use ($media, $user, $mission, $day, $now, $slotNo, $slotCap, $mediaCap, $dailyLimit, $cooldownMin, $idemKey, $ip, $ipHash, $ipLimit) {
                // ① 참여자 원자 UPDATE — 일 한도(+옵션 쿨다운). 포인트 상한·밭은 벤더 경로에 없다
                $affected = DB::update(
                    "UPDATE reward_users SET
                        today_count = CASE WHEN today_date = ? THEN today_count + 1 ELSE 1 END,
                        today_attempts = CASE WHEN today_date = ? THEN today_attempts + 1 ELSE 1 END,
                        today_date = ?,
                        cooldown_until = ?, last_submit_at = ?, last_participated_at = ?,
                        total_participations = total_participations + 1, daily_ip = ?, updated_at = ?
                      WHERE id = ? AND status = 'active'
                        AND (today_date IS NULL OR today_date <> ? OR today_count < ?)
                        AND (cooldown_until IS NULL OR cooldown_until <= ?)",
                    [$day, $day, $day, $cooldownMin > 0 ? $now->copy()->addMinutes($cooldownMin) : null,
                        $now, $now, $ip, $now, $user->id, $day, $dailyLimit, $now],
                );
                if (! $affected) {
                    throw new GateRejected('daily_limit', 'not_eligible');   // 사유 비공개(§8)
                }

                // ② 사용자×미션 상한 → ③ 공유 한도(C8) — 락 순서는 미니앱과 동일
                UserMissionCap::consume($user->id, $mission, $day, $now, 'mission_cap', 'participant_duplicate');

                // ②-b 식별자(IP) 한도 원자 소비 — participant_hash 를 갈아치워도 이 축은 남는다(§8)
                if ($ipHash && ! IdentityGate::consume($media->id, IdentityGate::TYPE_IP, $ipHash, $day, $ipLimit)) {
                    throw new GateRejected('ip_limit', 'not_eligible');
                }

                // ②-c 매체 배분 상한(§2-1) — 이 벤더에 배정된 몫을 넘기지 않는다
                if (! MediaQuota::consume($mission->id, $media->id, $day, $mediaCap)) {
                    throw new GateRejected('media_cap', 'quota_full');
                }

                // 벤더는 구간 상한을 넘기지 않는다(§7) — 하루 물량 몰아치기 차단
                $gate = QuotaGate::consumeWithinSlot($mission->id, $day, $slotCap);
                if ($gate['seq'] === null) {
                    throw new GateRejected($gate['reason'], $gate['reason']);
                }

                $logId = DB::table('reward_participation_logs')->insertGetId([
                    'media_id' => $media->id,
                    'stat_month' => (int) substr(str_replace('-', '', $day), 0, 6),
                    'stat_date' => $day, 'slot_no' => $slotNo,
                    'reward_user_id' => $user->id, 'mission_id' => $mission->id,
                    'order_item_id' => $mission->order_item_id, 'order_id' => $mission->order_id,
                    'vendor_id' => $mission->vendor_id,
                    'result' => 'correct', 'answer_norm' => null,
                    'unit_revenue' => $mission->unit_revenue, 'payout_point' => $mission->payout_point,
                    'seq_in_day' => $gate['seq'], 'daily_quota' => $mission->daily_quota,
                    'is_overflow' => $gate['seq'] > (int) $mission->daily_quota,
                    'slot_overflow' => false,   // 벤더 경로는 구간 상한을 넘겨 확정하지 않는다
                    'ip' => $ip, 'created_at' => $now,
                ]);

                $payload = [
                    'status' => 'accepted',
                    'participation_id' => $logId,   // design-04 §3 계약 — snake_case
                    'remaining' => max(0, min($slotCap, (int) $mission->daily_quota) - $gate['seq']),   // 현재 슬롯 잔여(§7)
                ];

                // 멱등키 저장(같은 트랜잭션) — 동시 같은 키는 unique 가 잡는다
                DB::table('reward_idempotency_keys')->insert([
                    'media_id' => $media->id, 'idem_key' => $idemKey,
                    'participation_log_id' => $logId, 'response' => json_encode($payload),
                    'created_at' => $now,
                ]);

                return $payload;
            });
        } catch (UniqueConstraintViolationException) {
            // 동시 재시도가 먼저 커밋됨 — 저장된 첫 응답을 재반환
            $stored = $this->storedResponse($media, $idemKey);

            return [$stored ? $this->duplicatePayload($stored) : ['status' => 'rejected', 'reason' => 'conflict'], $stored ? 200 : 409];
        } catch (GateRejected $e) {
            // 첫 요청이 트랜잭션 중이라 1차 조회를 빠져나간 재시도일 수 있다 — 거절보다 멱등 계약이 우선한다
            if ($stored = $this->storedResponse($media, $idemKey)) {
                return [$this->duplicatePayload($stored), 200];
            }

            $this->bumpAttempt($media, $user, $day, $now, $ipHash);
            $this->log($media, $user, $mission, $day, $slotNo, 'rejected', $e->reason, null, $ip);

            $payload = ['status' => 'rejected', 'reason' => $e->userMessage];
            if ($e->userMessage === 'slot_exhausted') {
                $payload['retry_after_seconds'] = SlotCap::secondsToNextSlot();   // §3 계약
            }

            return [$payload, 422];
        }

        return [$result, 200];
    }

    private function storedResponse(RewardMedia $media, string $idemKey): ?object
    {
        return DB::table('reward_idempotency_keys')
            ->where('media_id', $media->id)->where('idem_key', $idemKey)->first();
    }

    /** 사전 게이트 거절 — 시도 수(사용자·식별자)를 올리고 거절 로그를 남긴다 */
    private function rejectAttempt(RewardMedia $media, RewardUser $user, RewardMission $mission,
        string $day, int $slotNo, string $reason, ?string $ip, ?string $ipHash): void
    {
        $this->bumpAttempt($media, $user, $day, now(), $ipHash);
        $this->log($media, $user, $mission, $day, $slotNo, 'rejected', $reason, null, $ip);
    }

    /** 시도 수는 확정과 무관하게 누적한다(C16) — 오답·거절이 공짜면 브루트포스를 막을 수단이 없다 */
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

    /** 벤더측 사용자 식별 — participant_hash 를 매체 스코프 사용자로 등록(식별자 한도·소프트 차단의 앵커) */
    public function participant(RewardMedia $media, string $participantHash): RewardUser
    {
        $hash = hash('sha256', $participantHash);

        try {
            $user = RewardUser::query()->firstOrCreate(['media_id' => $media->id, 'user_key_hash' => $hash]);
        } catch (UniqueConstraintViolationException) {
            return RewardUser::query()
                ->where('media_id', $media->id)->where('user_key_hash', $hash)->firstOrFail();
        }

        if ($user->wasRecentlyCreated) {
            $user->refresh();   // DB 기본값(status='active' 등)을 로드 — 없으면 신규가 차단으로 오판된다
        }

        return $user;
    }

    private function duplicatePayload(object $stored): array
    {
        $first = json_decode((string) $stored->response, true) ?: [];

        return ['status' => 'duplicate', 'participation_id' => (int) $stored->participation_log_id] + $first;
    }

    /** 벤더측 사용자 조회 — 없으면 null(읽기 경로에서 행을 만들지 않는다). 생성은 participant() 만 한다 */
    public function findParticipant(RewardMedia $media, string $participantHash): ?RewardUser
    {
        return RewardUser::query()
            ->where('media_id', $media->id)
            ->where('user_key_hash', hash('sha256', $participantHash))
            ->first();
    }

    private function log(RewardMedia $media, RewardUser $user, RewardMission $mission,
        string $day, int $slotNo, string $result, ?string $reason, ?string $answerNorm, ?string $ip): void
    {
        DB::table('reward_participation_logs')->insert([
            'media_id' => $media->id,
            'stat_month' => (int) substr(str_replace('-', '', $day), 0, 6),
            'stat_date' => $day, 'slot_no' => $slotNo,
            'reward_user_id' => $user->id, 'mission_id' => $mission->id,
            'order_item_id' => $mission->order_item_id, 'order_id' => $mission->order_id,
            'vendor_id' => $mission->vendor_id,
            'result' => $result, 'reject_reason' => $reason, 'answer_norm' => $answerNorm,
            'unit_revenue' => $mission->unit_revenue, 'payout_point' => $mission->payout_point,
            'daily_quota' => $mission->daily_quota,
            'ip' => $ip, 'created_at' => now(),
        ]);
    }
}
