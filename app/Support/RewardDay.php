<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 리워드 하루(농장일) — KST 06:00 시작, 02:00~06:00 심야 휴지 (design-02 §2, D11).
 * 사용자 한도·미션 수량이 전부 이 축을 쓴다. 자정(00:00) 기준 날짜 사용 금지(I5).
 */
final class RewardDay
{
    /** 현재(또는 주어진 시각)가 속한 농장일 'Y-m-d' — 06:00 이전은 전날 */
    public static function current(?Carbon $at = null): string
    {
        $t = ($at ?? Carbon::now())->copy()->tz('Asia/Seoul');
        if ($t->format('H:i') < config('reward.quota.quiet_to')) {
            $t->subDay();
        }

        return $t->toDateString();
    }

    /** 농장일 시작 시각 — 그 날짜의 06:00 KST */
    public static function start(?string $day = null): Carbon
    {
        return Carbon::parse(($day ?? self::current()).' '.config('reward.quota.quiet_to'), 'Asia/Seoul');
    }

    /** 현재 시간 구간 코드('S1'~'S7'), 심야 휴지(02:00~06:00)면 null */
    public static function slot(?Carbon $at = null): ?string
    {
        $t = ($at ?? Carbon::now())->copy()->tz('Asia/Seoul')->format('H:i');

        foreach (config('reward.quota.slots') as $slot) {
            $in = $slot['from'] < $slot['to']
                ? ($t >= $slot['from'] && $t < $slot['to'])
                : ($t >= $slot['from'] || $t < $slot['to']);   // 자정을 넘는 구간(S7 22:00~02:00)

            if ($in) {
                return $slot['code'];
            }
        }

        return null;
    }

    public static function isQuiet(?Carbon $at = null): bool
    {
        return self::slot($at) === null;
    }
}
