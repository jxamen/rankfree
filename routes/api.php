<?php

use App\Http\Controllers\Api\CompeteController;
use App\Http\Controllers\Api\ExtAuthController;
use App\Http\Controllers\Api\ExtKeywordController;
use App\Http\Controllers\Api\ExtMarketController;
use App\Http\Controllers\Api\ExtProductController;
use App\Http\Controllers\Api\ExtSellerPowerController;
use App\Http\Controllers\Api\KeywordController;
use App\Http\Controllers\Api\RankController;
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
        Route::get('/keyword-analysis/detail', [KeywordController::class, 'detail'])->middleware('throttle:15,1');

        // 플레이스 리스트 순위(map.naver 배지) — 키워드 상위 오가닉 순위 목록
        Route::get('/place-serp', [RankController::class, 'serp'])->middleware('throttle:20,1');

        // 쇼핑 시장 분석 저장/내역
        Route::post('/market-analyses', [ExtMarketController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/market-analyses', [ExtMarketController::class, 'index']);
        Route::get('/market-analyses/{analysis}', [ExtMarketController::class, 'show']);

        // 상품 분석(리뷰 분석) 저장/내역
        Route::post('/product-analyses', [ExtProductController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/product-analyses', [ExtProductController::class, 'index']);
        Route::get('/product-analyses/{analysis}', [ExtProductController::class, 'show']);

        // 셀러력(상품 SEO·지수 경쟁 비교) 수집·계산·저장/내역
        Route::get('/seller-power/competitors', [ExtSellerPowerController::class, 'competitors'])->middleware('throttle:30,1');
        Route::post('/seller-power', [ExtSellerPowerController::class, 'store'])->middleware('throttle:15,1');
        Route::get('/seller-power', [ExtSellerPowerController::class, 'index']);
        Route::post('/talk-contacts', [ExtSellerPowerController::class, 'harvestTalk'])->middleware('throttle:20,1');
        Route::get('/seller-power/{analysis}', [ExtSellerPowerController::class, 'show']);
    });
});

/*
|--------------------------------------------------------------------------
| 외부 공개 API v1 — API 키 인증 (auth.apikey:{scope})
| 키 발급·허용기간·일일 한도·허용 IP: 콘솔 → API 키 관리
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function (): void {
    // 순위추적 (scope: rank)
    Route::middleware('auth.apikey:rank')->prefix('rank')->group(function (): void {
        Route::get('/slots', [RankController::class, 'slots']);
        Route::post('/slots', [RankController::class, 'store']);
        Route::get('/resolve', [RankController::class, 'resolve']);
        Route::post('/slots/{slot}/run', [RankController::class, 'run']);
        Route::delete('/slots/{slot}', [RankController::class, 'destroy']);
        Route::post('/check', [RankController::class, 'check']);
    });

    // 경쟁분석 (scope: compete)
    Route::middleware('auth.apikey:compete')->prefix('compete')->group(function (): void {
        Route::get('/tracks', [CompeteController::class, 'tracks']);
        Route::get('/{slot}', [CompeteController::class, 'show']);
        Route::post('/{slot}/analyze', [CompeteController::class, 'analyze']);
    });

    // 키워드분석 — 경량(scope: keyword)과 상세(scope: keyword_detail)를 분리 제공
    Route::middleware('auth.apikey:keyword')->get('/keyword', [KeywordController::class, 'show']);
    Route::middleware('auth.apikey:keyword_detail')->get('/keyword/detail', [KeywordController::class, 'detail']);
});
