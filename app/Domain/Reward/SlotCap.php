<?php

namespace App\Domain\Reward;

use App\Support\RewardDay;
use Illuminate\Support\Carbon;

/**
 * 시간대 분산 — 누적 상한 방식(C1). 구간 카운터·이월 배치 없이
 * "지금 슬롯의 누적 상한(slot_cap)까지만 참여 허용" 하나로 분산을 만든다.
 * 미소진분 이월은 누적 정의상 자동이고, used < daily_quota 가 항상 함께 걸려 총량은 절대 초과하지 않는다.
 */
final class SlotCap
{
    /** 현재(또는 주어진 시각) 슬롯 번호 0~6. 심야 휴지(02~06)는 null */
    public static function slotNo(?Carbon $at = null): ?int
    {
        $code = RewardDay::slot($at);
        if ($code === null) {
            return null;
        }
        foreach (array_values(config('reward.quota.slots')) as $i => $slot) {
            if ($slot['code'] === $code) {
                return $i;
            }
        }

        return null;
    }

    /** 현재 시각의 누적 상한. 휴지면 null */
    public static function for(int $dailyQuota, ?Carbon $at = null): ?int
    {
        $i = self::slotNo($at);

        return $i === null ? null : self::at($dailyQuota, $i);
    }

    /**
     * 다음 슬롯이 열릴 때까지의 초(slot_exhausted 응답의 retry_after_seconds — design-04 §3).
     * 마지막 슬롯(심야 휴지 직전)이면 다음 농장일 개장(06:00)까지 — 한도가 그때 리셋되므로.
     */
    public static function secondsToNextSlot(?Carbon $at = null): int
    {
        $at = ($at ?? now())->tz('Asia/Seoul');
        $i = self::slotNo($at);
        $slots = array_values(config('reward.quota.slots'));

        if ($i === null || $i >= count($slots) - 1) {
            $nextDay = RewardDay::start(Carbon::parse(RewardDay::current($at))->addDay()->toDateString());

            return max(1, (int) $at->diffInSeconds($nextDay, true));
        }

        $end = $at->copy()->setTimeFromTimeString($slots[$i]['to']);
        if ($end->lte($at)) {
            $end->addDay();
        }

        return max(1, (int) $at->diffInSeconds($end, true));
    }

    /**
     * C1 공식 — D >= 2n 이면 누적 비율(각 구간 최소 1 누적 보장), 소액 미션(D < 2n)은 균등 누적.
     * 마지막 구간은 항상 D.
     */
    public static function at(int $dailyQuota, int $slotNo): int
    {
        $slots = array_values(config('reward.quota.slots'));
        $n = count($slots);

        if ($slotNo >= $n - 1) {
            return $dailyQuota;
        }

        if ($dailyQuota >= 2 * $n) {
            $cum = 0;
            for ($k = 0; $k <= $slotNo; $k++) {
                $cum += (int) $slots[$k]['weight'];
            }

            return min($dailyQuota, max((int) floor($dailyQuota * $cum / 100), $slotNo + 1));
        }

        return min($dailyQuota, (int) ceil($dailyQuota * ($slotNo + 1) / $n));
    }
}
