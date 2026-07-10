<?php

use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\RankCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// A1 플레이스 순위체크 — 1회성 무료 조회
Route::get('/rank-check', [RankCheckController::class, 'check'])->name('rank.check');

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
