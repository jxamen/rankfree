<?php

use App\Http\Controllers\Api\RankController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| rankfree API v1 — 확장/외부 (auth.ext Bearer 토큰)
|--------------------------------------------------------------------------
| ApiV1ServiceProvider 가 prefix('api/v1') 로 로드. 모든 기능은 여기에 API 로 노출.
| 토큰 발급은 확장 로그인(/api/ext/login, ext_tokens) 재사용.
*/

Route::middleware('auth.ext')->group(function () {
    // 순위 추적
    Route::prefix('rank')->name('api.rank.')->group(function () {
        Route::get('/slots', [RankController::class, 'slots'])->name('slots');
        Route::post('/slots', [RankController::class, 'store'])->name('store');       // place + keywords[] 다건
        Route::post('/slots/{slot}/run', [RankController::class, 'run'])->name('run');
        Route::delete('/slots/{slot}', [RankController::class, 'destroy'])->name('destroy');
        Route::post('/resolve', [RankController::class, 'resolve'])->name('resolve');  // 업체명·카테고리 자동조회
        Route::get('/check', [RankController::class, 'check'])->name('check');         // 1회성 순위조회
    });
});
