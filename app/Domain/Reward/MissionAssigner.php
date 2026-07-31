<?php

namespace App\Domain\Reward;

use Illuminate\Support\Facades\DB;

/**
 * 단건 할당(design-04 §3-0 "단건 할당" 방식) — "미션 참여하기"를 누르면 서버가 하나를 골라 내려준다.
 * 송출 규칙은 §7 그대로: ① 현재 슬롯 잔여가 있는 미션만 ② 사용자 상한을 채운 미션 제외
 * ③ 소진율 낮은 미션 우선(진행률 균형 — 특정 미션만 먼저 끝나는 것 방지) ④ 동률은 사용자별 결정적 셔플.
 *
 * ⚠️ 할당은 예약이 아니다. 확정은 언제나 제출 시점의 원자 게이트(QuotaGate)가 한다 —
 * 여기서 고른 미션이 제출 시점엔 소진됐을 수 있고, 그건 정상이다(선점은 Phase 11 옵션).
 */
class MissionAssigner
{
    /**
     * 참여 가능한 미션 1건. 후보가 없으면 null.
     *
     * @return array<string, mixed>|null 스냅샷 행(MissionSnapshot::liveRows 형태)
     */
    public function pick(int $rewardUserId, string $userKeyHash, string $day, int $slotNo): ?array
    {
        $rows = collect(app(MissionSnapshot::class)->cachedList($day, $slotNo))
            ->filter(fn (array $m) => (int) $m['used']
                < min(SlotCap::at((int) $m['daily_quota'], $slotNo), (int) $m['daily_quota']));

        if ($rows->isEmpty()) {
            return null;
        }

        $counters = DB::table('reward_user_mission_counters')
            ->where('reward_user_id', $rewardUserId)
            ->whereIn('mission_id', $rows->pluck('id'))
            ->get()->keyBy('mission_id');

        $rows = $rows->reject(function (array $m) use ($counters, $day) {
            $c = $counters[$m['id']] ?? null;

            return $c && ((int) $c->done_count >= (int) $m['per_user_limit']
                || ($c->last_done_on === $day && (int) $c->today_count >= (int) $m['per_user_daily_limit']));
        });

        return $rows->sortBy(fn (array $m) => $this->rank($m, $userKeyHash, $day))->first();
    }

    /**
     * 정렬 키 — "소진율(1000분율) + 사용자별 셔플". 문자열로 만들어 정렬을 결정적으로 고정한다.
     * 셔플이 없으면 소진율이 같은 미션들에서 항상 같은 미션이 먼저 뽑혀 한 미션만 몰린다.
     */
    private function rank(array $m, string $userKeyHash, string $day): string
    {
        $quota = max(1, (int) $m['daily_quota']);
        $progress = min(999, (int) floor((int) $m['used'] * 1000 / $quota));
        $shuffle = substr(hash_hmac('sha256', $userKeyHash.'|'.$day.'|'.$m['id'], (string) config('app.key')), 0, 8);

        return sprintf('%03d-%s', $progress, $shuffle);
    }
}
