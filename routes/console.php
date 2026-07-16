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

// 커뮤니티 페르소나 자동 활동 — 살아있는 게시판(설정으로 on/off). 30분마다 소량 활동.
if (config('rankfree.community.schedule_enabled', true)) {
    Schedule::command('community:simulate')
        ->everyThirtyMinutes()
        ->withoutOverlapping();
}

// 앱 타임존이 UTC 라 시각 지정 스케줄은 반드시 timezone('Asia/Seoul') 로 KST 고정.

// 플레이스 순위추적 — 매일 오전 11:30·오후 4:30(KST). 활성 슬롯 순위 조회·기록(nCaptcha 토큰 필요).
Schedule::command('place:track-run')->timezone('Asia/Seoul')->dailyAt('11:30')->withoutOverlapping()->runInBackground();
Schedule::command('place:track-run')->timezone('Asia/Seoul')->dailyAt('16:30')->withoutOverlapping()->runInBackground();

// 쇼핑 순위추적 — 매시간. 활성 슬롯 순위 조회·기록(openapi shop.json).
Schedule::command('shop:track-run')->hourly()->withoutOverlapping()->runInBackground();

// 스마트플레이스 리포트 수집 + 세션 유지 — 매일 새벽 3시(KST). (crm cron/smartplace_collect.php 이식)
Schedule::command('smartplace:collect')->timezone('Asia/Seoul')->dailyAt('03:00')->withoutOverlapping()->runInBackground();

// 카페 글감 수집 — 매일 새벽 5:10(KST) 인기글·본문·댓글 수집 → 커뮤니티 글밥 전환.
// ⚠️ 수집 세션은 scripts/.naver-cafe-profile — 카페 멤버 계정으로 `node scripts/naver-cafe-crawler.cjs --reset --headful` 1회 로그인 필요.
if (config('rankfree.cafe_crawl.schedule_enabled', true)) {
    Schedule::command('cafe:crawl --seed')->timezone('Asia/Seoul')->dailyAt('05:10')->withoutOverlapping()->runInBackground();
}
