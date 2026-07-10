<?php

use App\Http\Controllers\Api\ExtAuthController;
use App\Http\Controllers\Api\ExtKeywordController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 크롬 확장(rankfree extension) API — Bearer 토큰(ext_tokens) 인증
|--------------------------------------------------------------------------
*/
Route::prefix('ext')->group(function (): void {
    Route::post('/login', [ExtAuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth.ext')->group(function (): void {
        Route::get('/me', [ExtAuthController::class, 'me']);
        Route::post('/logout', [ExtAuthController::class, 'logout']);
        Route::get('/keyword-analysis', [ExtKeywordController::class, 'show'])->middleware('throttle:30,1');
    });
});
