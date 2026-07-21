<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\MemberGrade;
use App\Models\Notice;
use App\Models\PlaceRankLookup;
use App\Models\Popup;
use Illuminate\Http\Request;

class ConsoleController extends Controller
{
    /** 마이페이지 — 계정 정보 · 순위체크 슬롯 현황 · 추천 링크(백엔드 자동 처리). */
    public function me(Request $request)
    {
        $user = $request->user();

        // 쿠폰(26) — 별도 메뉴 없이 마이페이지에서 확인·다운로드(2026-07-22 운영 요청 "메뉴가 너무 많아")
        $downloadable = \App\Models\Coupon::where('is_active', true)->where('is_downloadable', true)
            ->whereDoesntHave('userCoupons', fn ($q) => $q->where('user_id', $user->id))
            ->latest()->get()
            ->filter(fn ($c) => $c->inPeriod() && ($c->remainingIssuance() === null || $c->remainingIssuance() > 0))
            ->values();
        $myCoupons = $user->userCoupons()->with(['coupon', 'order'])->latest()->get();

        return view('console.me', [
            'user' => $user,
            'referralUrl' => route('register').'?ref='.$user->referralCode(),
            'referredCount' => \App\Models\User::where('referred_by', $user->id)->count(),
            'bonusSlots' => (int) $user->referral_bonus_slots,
            'bonusPer' => \App\Domain\Member\ReferralService::bonusPer(),
            'bonusMax' => \App\Domain\Member\ReferralService::bonusMax(),
            'downloadable' => $downloadable,
            'myCoupons' => $myCoupons,
            'couponProductTitles' => \App\Models\MarketingProduct::whereIn(
                'id',
                $downloadable->concat($myCoupons->pluck('coupon'))->filter()
                    ->flatMap(fn ($c) => $c->product_ids ?? [])->unique()->values()
            )->pluck('title', 'id'),
        ]);
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        $now = now();

        $monthCount = PlaceRankLookup::where('user_id', $user->id)
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->count();

        $recent = PlaceRankLookup::where('user_id', $user->id)
            ->latest()
            ->limit(6)
            ->get();

        // 기능별 월 사용량 (구독 등급 기준)
        $features = [];
        foreach (MemberGrade::FEATURES as $key => $label) {
            $limit = $user->featureLimit($key);
            $features[] = [
                'label' => $label,
                'used' => $user->featureUsed($key),
                'limit' => $limit,                       // -1 무제한, 0 미제공, N 월 N회
                'remaining' => $user->featureRemaining($key),
            ];
        }

        return view('console.dashboard', [
            'usedSlots' => $user->rankSlotsUsedTotal(),
            'maxSlots' => $user->rankSlotLimit(),
            'monthCount' => $monthCount,
            'recent' => $recent,
            'features' => $features,
            'notices' => Notice::visible()->listed()->limit(5)->get(),
            'banners' => Banner::activeNow()->sorted()->get(),
            'popups' => Popup::activeNow()->sorted()->get(),
        ]);
    }
}
