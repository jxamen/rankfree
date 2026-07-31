<?php

use App\Http\Controllers\Api\Farm\FarmAppController;
use Illuminate\Support\Facades\Route;

/*
 * 리워드 참여시스템 — 퀴즈농장(miniapp) API. bootstrap/app.php 의 withRouting(then:) 에서 로드된다.
 * 인증: x-user-key 헤더(auth.reward:quiz-farm) — 쿠키 세션 불가 환경(server-api-spec.md).
 * 계약이 미니앱 클라이언트와 공유되므로 경로·응답 형태를 바꾸면 farm-quiz 클라이언트도 같이 고쳐야 한다.
 */
Route::prefix('api/farm')->middleware(['api', 'auth.reward:quiz-farm'])->group(function (): void {
    Route::get('/config', [FarmAppController::class, 'config']);
    Route::get('/missions', [FarmAppController::class, 'missions']);
    // 단건 할당(design-04 §3-0) — "미션 참여하기" 한 번에 미션 하나. 목록과 같은 미션 객체를 돌려준다
    Route::post('/missions/assign', [FarmAppController::class, 'assign'])->middleware('throttle:60,1');
    Route::post('/missions/{mission}/submit', [FarmAppController::class, 'submit'])
        ->whereNumber('mission')->middleware('throttle:30,1');
    Route::post('/plots/{index}/plant', [FarmAppController::class, 'plant'])->whereNumber('index');
    Route::get('/me/state', [FarmAppController::class, 'state']);
    Route::post('/me/notifications/cooldown', [FarmAppController::class, 'cooldownNotify']);
});
