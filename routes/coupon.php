<?php

use Illuminate\Support\Facades\Route;

/*
 * 쿠폰(26) 라우트 — web.php 가 아닌 별도 파일.
 * 콘솔 다운로드는 마이페이지(console.me)에서 POST 만 받는다(별도 쿠폰함 페이지·메뉴 없음).
 * bootstrap/app.php 의 withRouting(then:) 에서 로드된다.
 */

// 콘솔 — 쿠폰 다운로드(확인·다운로드 UI 는 마이페이지에 통합, 사용은 셀프마케팅 주문에서)
Route::middleware(['web', 'auth', 'menu.gate', 'usage.gate'])->prefix('console')->name('console.')->group(function () {
    Route::post('/coupons/{coupon}/download', [\App\Http\Controllers\CouponController::class, 'download'])->name('coupons.download');
});

// 관리자 — 쿠폰 CRUD + 발급(특정 회원·전체 회원)·회수·사용 내역
Route::middleware(['web', 'auth', 'operator'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/coupons', [\App\Http\Controllers\Admin\CouponController::class, 'index'])->name('coupons');
    Route::post('/coupons', [\App\Http\Controllers\Admin\CouponController::class, 'store'])->name('coupons.store');
    Route::delete('/coupons/user-coupons/{userCoupon}', [\App\Http\Controllers\Admin\CouponController::class, 'revoke'])->name('coupons.revoke');
    Route::get('/coupons/{coupon}', [\App\Http\Controllers\Admin\CouponController::class, 'show'])->name('coupons.show');
    Route::put('/coupons/{coupon}', [\App\Http\Controllers\Admin\CouponController::class, 'update'])->name('coupons.update');
    Route::post('/coupons/{coupon}/toggle', [\App\Http\Controllers\Admin\CouponController::class, 'toggle'])->name('coupons.toggle');
    Route::post('/coupons/{coupon}/issue', [\App\Http\Controllers\Admin\CouponController::class, 'issue'])->name('coupons.issue');
    Route::post('/coupons/{coupon}/issue-all', [\App\Http\Controllers\Admin\CouponController::class, 'issueAll'])->name('coupons.issue-all');
    Route::delete('/coupons/{coupon}', [\App\Http\Controllers\Admin\CouponController::class, 'destroy'])->name('coupons.destroy');
});
