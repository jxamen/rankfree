<?php

use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompeteController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\RankCheckController;
use App\Http\Controllers\RankTrackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// A1 플레이스 순위체크 — 1회성 무료 조회
Route::get('/rank-check', [RankCheckController::class, 'check'])->name('rank.check');

// 순위 추적 공개 리포트 — 공유 토큰으로 비로그인 열람
Route::get('/r/{token}', [RankTrackController::class, 'shared'])->name('rank.shared');
// 경쟁 분석 공개 리포트 — 공유 토큰으로 비로그인 열람
Route::get('/rc/{token}', [CompeteController::class, 'shared'])->name('compete.shared');

// 인증
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// 콘솔 (로그인 필요)
Route::middleware('auth')->prefix('console')->name('console.')->group(function () {
    Route::get('/', [ConsoleController::class, 'dashboard'])->name('dashboard');

    // 순위 추적 슬롯
    Route::get('/rank', [RankTrackController::class, 'index'])->name('rank');
    Route::get('/rank/resolve', [RankTrackController::class, 'resolve'])->name('rank.resolve');
    Route::get('/rank/export', [RankTrackController::class, 'export'])->name('rank.export');
    Route::post('/rank', [RankTrackController::class, 'store'])->name('rank.store');
    Route::post('/rank/{slot}/run', [RankTrackController::class, 'run'])->name('rank.run');
    Route::put('/rank/{slot}', [RankTrackController::class, 'update'])->name('rank.update');
    Route::delete('/rank/{slot}', [RankTrackController::class, 'destroy'])->name('rank.destroy');

    // 경쟁 분석 (SEO 점수 + 순위추적)
    Route::get('/compete', [CompeteController::class, 'index'])->name('compete');
    Route::get('/compete/{slot}', [CompeteController::class, 'show'])->name('compete.show');
    Route::get('/compete/{slot}/explain/{place}', [CompeteController::class, 'explain'])->name('compete.explain');
    Route::get('/compete/{slot}/history/{place}', [CompeteController::class, 'history'])->name('compete.history');
    Route::post('/compete/{slot}/analyze', [CompeteController::class, 'analyze'])->name('compete.analyze');

    // API 키 관리 (발급·허용기간·일일 한도·허용 IP)
    Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::post('/api-keys/{key}/toggle', [ApiKeyController::class, 'toggle'])->name('api-keys.toggle');
    Route::delete('/api-keys/{key}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
});

// 관리자 (운영자 전용)
Route::middleware(['auth', 'operator'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', fn () => redirect()->route('admin.menus'))->name('home');
    Route::view('/members', 'admin.stub', ['title' => '회원 관리'])->name('members');
    Route::view('/subscriptions', 'admin.stub', ['title' => '구독 관리'])->name('subscriptions');

    // 메뉴 관리
    Route::get('/menus', [MenuController::class, 'index'])->name('menus');
    Route::post('/menus', [MenuController::class, 'store'])->name('menus.store');
    Route::put('/menus/{menu}', [MenuController::class, 'update'])->name('menus.update');
    Route::delete('/menus/{menu}', [MenuController::class, 'destroy'])->name('menus.destroy');
    Route::post('/menus/{menu}/toggle', [MenuController::class, 'toggle'])->name('menus.toggle');
    Route::post('/menus/reorder', [MenuController::class, 'reorder'])->name('menus.reorder');
    Route::post('/menus/{menu}/permissions', [MenuController::class, 'savePermissions'])->name('menus.permissions');

    Route::view('/permissions', 'admin.stub', ['title' => '권한 설정'])->name('permissions');
});
