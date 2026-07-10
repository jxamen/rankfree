<?php

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
