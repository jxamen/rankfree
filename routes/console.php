<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| 스케줄 — `php artisan schedule:run` 을 1분마다 크론에 걸어야 동작한다.
|   리눅스:  * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
|   윈도(로컬): 작업 스케줄러로 동일 실행
*/

// 네이버 검색광고 웹 세션 유지 — 매시간 만료 감지 시에만 자동 재로그인(성별·연령·트렌드 데이터용)
Schedule::command('searchadweb:login --if-stale')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
