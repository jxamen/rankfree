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

// 시각 지정 스케줄은 timezone('Asia/Seoul') 명시를 유지한다(앱 타임존 설정과 무관하게 KST 의도를 고정).

// 플레이스 순위추적 — 매일 오전 11:30·오후 4:30(KST). 활성 슬롯 순위 조회·기록(nCaptcha 토큰 필요).
Schedule::command('place:track-run')->timezone('Asia/Seoul')->dailyAt('11:30')->withoutOverlapping()->runInBackground();
Schedule::command('place:track-run')->timezone('Asia/Seoul')->dailyAt('16:30')->withoutOverlapping()->runInBackground();

// 쇼핑 순위추적 — 매시간. 활성 슬롯 순위 조회·기록(openapi shop.json).
Schedule::command('shop:track-run')->hourly()->withoutOverlapping()->runInBackground();

// 세부주문(일할) 예약 발주 — 진행일 도래 회차를 매일 아침 업체로 자동 전송(승인된 주문만).
Schedule::command('orders:dispatch-due')->timezone('Asia/Seoul')->dailyAt('09:00')->withoutOverlapping()->runInBackground();

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

// 신규 개업(24) — 인허가 공공데이터 수집 → 네이버 플레이스 매칭(수집이 매칭까지 한 흐름).
// 기본 off(.env NEWBIZ_SCHEDULE_ENABLED=true — 운영은 on). 원천이 D-2 현행화라 하루 1회면 충분.
if (config('rankfree.newbiz.schedule_enabled', false)) {
    Schedule::command('newbiz:collect')->timezone('Asia/Seoul')->dailyAt('08:00')->withoutOverlapping()->runInBackground();
    // 수집 흐름이 매칭까지 끝내므로 아래는 잔여분(재확인 주기 도래분) 캐치업
    Schedule::command('newbiz:place-match')->timezone('Asia/Seoul')->dailyAt('08:20')->withoutOverlapping()->runInBackground();
}

// 키워드 자동 분석 — 승인/대기 후보 자동 발행. 플레이스는 키워드 분석, 쇼핑은 시장 분석.
// 기본 on: publish_interval(분)마다 승인 후보 ≤publish_per_run 발행. 승인 큐가 빌 때까지 자동으로 계속 드레인, 없으면 idle.
if (config('rankfree.hub.publish_enabled', true)) {
    $__hubPublishItv = max(1, min(60, (int) config('rankfree.hub.publish_interval', 10)));
    Schedule::command('hub:publish')->cron("*/{$__hubPublishItv} * * * *")->withoutOverlapping()->runInBackground();
}

// 키워드 자동 분석(관리자 토글) — 켜져 있을 때만 쌓인 후보를 매분 큐에 넣는다. 실제 처리는 Supervisor 워커가 병렬 수행.
Schedule::command('hub:auto-publish')->everyMinute()->withoutOverlapping()->runInBackground();

// 키워드 허브 순위 매핑 월 파티션 로테이션 — 이번 달~2개월 뒤 선생성 + 보존기간(기본 13개월) 지난 월 파기.
// 수집은 발굴 스케줄과 무관하게(확장 업로드·관리자 탐색) 일어나므로 발굴 게이트 밖에서 항상 실행. 멱등·경량.
Schedule::command('hub:partition-rotate')->timezone('Asia/Seoul')->dailyAt('05:50')->withoutOverlapping()->runInBackground();

// ── 대량 백필: 저품질 키워드 가지치기 + 플레이스 좌표 수집 (한도 내 청크로 자동 완주) ──
// 남은(미조회) 키워드의 네이버 조회수 확인 → 월 <=10(의미없음) 삭제, >10 보존(발행분은 유지).
// 10분마다 청크 처리. searchad 일일 한도 소진 시 무응답으로 넘기고 다음 실행에서 재시도(멱등).
if (config('rankfree.keyword_prune.schedule_enabled', true)) {
    Schedule::command('keywords:prune-low-volume --type=place --limit=500 --batch=25 --sleep=1')
        ->everyTenMinutes()->withoutOverlapping(15)->runInBackground();
}

// 플레이스 좌표 백필 — >10 발행문서 SERP 재수집으로 업체 좌표 적재(지리 "주변 추천"). 20분마다 청크.
// nCaptcha 토큰 의존(searchadweb/토큰 크론). 연속 차단 시 커맨드가 자동 중단, 다음 주기에 재개.
if (config('rankfree.place_coords.schedule_enabled', true)) {
    Schedule::command('place:backfill-coords --limit=100 --days=30 --sleep=3 --top=40')
        ->cron('*/20 * * * *')->withoutOverlapping(25)->runInBackground();
}

// 키워드 허브 발굴·갱신 — 후보 수집/발굴/갱신. 기본 off(.env HUB_SCHEDULE_ENABLED=true 로 활성) — 검색광고 쿼터 보호.
if (config('rankfree.hub.schedule_enabled', false)) {
    Schedule::command('hub:collect')->timezone('Asia/Seoul')->dailyAt('06:10')->withoutOverlapping()->runInBackground();
    Schedule::command('hub:discover')->timezone('Asia/Seoul')->dailyAt('06:20')->withoutOverlapping()->runInBackground(); // GSC 유입 쿼리 발굴(gsc:collect 04:00 이후)
    Schedule::command('hub:refresh')->timezone('Asia/Seoul')->dailyAt('06:40')->withoutOverlapping()->runInBackground();
    // 데이터랩 쇼핑인사이트 인기검색어 — 순위 변동이 느려 주 1회면 충분(월요일 새벽)
    Schedule::command('hub:shopping-collect')->timezone('Asia/Seoul')->weeklyOn(1, '06:50')->withoutOverlapping()->runInBackground();
}
