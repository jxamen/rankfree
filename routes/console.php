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

// 구글 서치 콘솔 검색 성과 수집 — 매일 새벽 4시(KST). 서비스 계정 미설정 시 실패 로그만 남고 무해.
Schedule::command('gsc:collect')->timezone('Asia/Seoul')->dailyAt('04:00')->withoutOverlapping()->runInBackground();

// GA4 방문 통계 수집 — 매일 새벽 4시 10분(KST).
Schedule::command('ga:collect')->timezone('Asia/Seoul')->dailyAt('04:10')->withoutOverlapping()->runInBackground();

// 카페 글감 수집 — 매일 새벽 5:10(KST) 인기글·본문·댓글 수집 → 커뮤니티 글밥 전환.
// ⚠️ 수집 세션은 scripts/.naver-cafe-profile — 카페 멤버 계정으로 `node scripts/naver-cafe-crawler.cjs --reset --headful` 1회 로그인 필요.
if (config('rankfree.cafe_crawl.schedule_enabled', true)) {
    Schedule::command('cafe:crawl --seed')->timezone('Asia/Seoul')->dailyAt('05:10')->withoutOverlapping()->runInBackground();
}

// 사이트맵 갱신 — 매일 새벽 5:40(KST). 분석 공유 슬러그 백필 + 사이트맵 캐시 무효화.
Schedule::command('sitemap:refresh')->timezone('Asia/Seoul')->dailyAt('05:40')->withoutOverlapping()->runInBackground();

// 신규 개업(24) — 인허가 공공데이터 수집 → 네이버 플레이스 매칭.
// 기본 off(.env NEWBIZ_SCHEDULE_ENABLED=true). 원천이 D-2 현행화라 새벽 1회면 충분.
if (config('rankfree.newbiz.schedule_enabled', false)) {
    Schedule::command('newbiz:collect')->timezone('Asia/Seoul')->dailyAt('07:10')->withoutOverlapping()->runInBackground();
    Schedule::command('newbiz:place-match')->timezone('Asia/Seoul')->dailyAt('07:30')->withoutOverlapping()->runInBackground();
}

// 키워드 콘텐츠 허브(22) — 승인 후보 자동 발행. 발굴과 분리(관리자 승인분만 처리 → 쿼터/도어웨이 리스크 없음).
// 기본 on: publish_interval(분)마다 승인 후보 ≤publish_per_run 발행. 승인 큐가 빌 때까지 자동으로 계속 드레인, 없으면 idle.
if (config('rankfree.hub.publish_enabled', true)) {
    $__hubPublishItv = max(1, min(60, (int) config('rankfree.hub.publish_interval', 10)));
    Schedule::command('hub:publish')->cron("*/{$__hubPublishItv} * * * *")->withoutOverlapping()->runInBackground();
}

// 키워드 허브 자동 발행(관리자 토글) — 켜져 있을 때만 쌓인 후보를 유형별로 매분 배치 발행. 꺼져 있으면 즉시 no-op.
Schedule::command('hub:auto-publish')->everyMinute()->withoutOverlapping()->runInBackground();

// 키워드 허브 발굴·갱신 — 후보 수집/발굴/갱신. 기본 off(.env HUB_SCHEDULE_ENABLED=true 로 활성) — 검색광고 쿼터 보호.
if (config('rankfree.hub.schedule_enabled', false)) {
    Schedule::command('hub:collect')->timezone('Asia/Seoul')->dailyAt('06:10')->withoutOverlapping()->runInBackground();
    Schedule::command('hub:discover')->timezone('Asia/Seoul')->dailyAt('06:20')->withoutOverlapping()->runInBackground(); // GSC 유입 쿼리 발굴(gsc:collect 04:00 이후)
    Schedule::command('hub:refresh')->timezone('Asia/Seoul')->dailyAt('06:40')->withoutOverlapping()->runInBackground();
    // 데이터랩 쇼핑인사이트 인기검색어 — 순위 변동이 느려 주 1회면 충분(월요일 새벽)
    Schedule::command('hub:shopping-collect')->timezone('Asia/Seoul')->weeklyOn(1, '06:50')->withoutOverlapping()->runInBackground();
}
