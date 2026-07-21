<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 쿠폰 다운로드(26) — 별도 쿠폰함 페이지·메뉴 없이 **마이페이지(console.me)** 안에서 확인·다운로드한다
 * (2026-07-22 운영 요청 "메뉴가 너무 많아"). 사용은 셀프마케팅 주문 페이지에서.
 */
class CouponController extends Controller
{
    /** 다운로드 — 1인 1매. 동시 클릭은 unique(coupon_id, user_id) 제약이 최종 방어. */
    public function download(Request $request, Coupon $coupon)
    {
        $user = $request->user();

        if (! $coupon->downloadableBy($user)) {
            return back()->withErrors(['coupon' => '이미 받았거나 지금은 받을 수 없는 쿠폰입니다.']);
        }

        try {
            DB::transaction(function () use ($coupon, $user) {
                // 수량 제한 쿠폰은 잠금 후 재확인(동시 다운로드로 초과 발급 방지)
                if ($coupon->max_issuance !== null) {
                    $locked = Coupon::whereKey($coupon->id)->lockForUpdate()->first();
                    if (($locked->remainingIssuance() ?? 1) < 1) {
                        throw new \RuntimeException('soldout');
                    }
                }
                $coupon->userCoupons()->create([
                    'user_id' => $user->id,
                    'source' => 'download',
                    'expires_at' => $coupon->expiresAtForIssue(),
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return back()->withErrors(['coupon' => '이미 받은 쿠폰입니다(1인 1매).']);
        } catch (\RuntimeException) {
            return back()->withErrors(['coupon' => '쿠폰이 모두 소진되었습니다.']);
        }

        return back()->with('status', "'{$coupon->name}' 쿠폰을 받았습니다. 셀프마케팅 주문 시 사용할 수 있습니다.");
    }
}
