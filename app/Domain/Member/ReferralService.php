<?php

namespace App\Domain\Member;

use App\Models\AppSetting;
use App\Models\User;

/**
 * 추천인 처리 — 추천 링크(/register?ref=CODE)로 가입하면 백엔드에서 자동으로
 * 추천 관계를 연결하고 추천인의 순위체크 보너스 슬롯을 가산한다(설정 상한까지).
 * 1회당 증가량·최대 증가량은 환경설정(회원 탭)에서 관리.
 */
class ReferralService
{
    public const SESSION_KEY = 'referral_code';

    public static function bonusPer(): int
    {
        return max(0, (int) (AppSetting::read('referral.bonus_per') ?? 20));
    }

    public static function bonusMax(): int
    {
        return max(0, (int) (AppSetting::read('referral.bonus_max') ?? 200));
    }

    /** 가입 완료 직후 호출 — 세션의 추천 코드로 추천인을 찾아 연결·보상. 실패해도 가입은 막지 않는다. */
    public function apply(User $newUser): void
    {
        $code = strtoupper(trim((string) session(self::SESSION_KEY, '')));
        session()->forget(self::SESSION_KEY);
        if ($code === '') {
            return;
        }

        $referrer = User::where('referral_code', $code)->first();
        if (! $referrer || $referrer->id === $newUser->id) {
            return;
        }

        $newUser->forceFill(['referred_by' => $referrer->id])->save();

        // 보너스 가산 — 최대치 캡(이미 상한이면 관계만 기록)
        $per = self::bonusPer();
        $max = self::bonusMax();
        $next = min($max, (int) $referrer->referral_bonus_slots + $per);
        if ($next > (int) $referrer->referral_bonus_slots) {
            $referrer->forceFill(['referral_bonus_slots' => $next])->save();
        }
    }
}
