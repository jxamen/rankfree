<?php

use App\Http\Controllers\RankCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// A1 플레이스 순위체크 — 1회성 무료 조회
Route::get('/rank-check', [RankCheckController::class, 'check'])->name('rank.check');
