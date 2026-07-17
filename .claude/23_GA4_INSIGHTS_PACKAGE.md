# 23. GA4 상세 분석 대시보드 (이식형 패키지)

> `/admin/traffic-stats` 방문 분석을 **초보자용 상세 대시보드**로 고도화하고, 다른 서비스에도 이식 가능한 **독립 패키지 `jcurve/ga4-insights`** 로 분리했다. (2026-07-17)

## 무엇

GA4 Data API(`batchRunReports`/`runRealtimeReport`)를 **라이브**로 조회해 한 화면에 표시:
- **① 개요** — 10개 KPI(사용자·신규·세션·페이지뷰·참여율·평균 방문시간·이탈률·세션당 페이지뷰·이벤트·주요이벤트) + **직전 같은 기간 대비 증감**(이탈률은 색 반전) + 지표 툴팁
- **② 방문 추이**(일별 막대) · **③ 유입**(채널 막대 + 소스/매체·캠페인 표) · **④ 랜딩(유입 페이지)** · **⑤ 인기 페이지**(평균 체류) · **⑥ 이탈 많은 랜딩** · **⑦ 기기·신규재방문·브라우저·지역** · **⑧ 이벤트** · **⑨ 시간대** · **실시간(지금 접속 중)**
- 기간 프리셋 7/14/28/90일, 새로고침(캐시 비움), 쉬운 한국어 섹션 설명

## 패키지 `jcurve/ga4-insights` (packages/ga4-insights)

프레임워크 외 의존 없음. 인증·속성ID를 **`Ga4Credentials` 계약으로 분리**해 어떤 Laravel 앱이든 그 인터페이스만 구현하면 대시보드를 그대로 쓴다.

| 파일 | 역할 |
|---|---|
| `src/Contracts/Ga4Credentials.php` | 속성ID·토큰 공급(앱이 구현) |
| `src/Support/ConfigGa4Credentials.php` | 기본 impl(config 기반) — 정적 토큰용 |
| `src/Ga4Client.php` | GA4 Data API 클라이언트(batch 5개/호출·realtime) |
| `src/Ga4Reporter.php` | 전 섹션 리포트 빌더(14요청 배치 + 기간대비 + 캐시) |
| `src/Support/Format.php` | 숫자·비율·시간·증감 |
| `src/Http/Ga4DashboardController.php` | index/refresh |
| `resources/views/dashboard.blade.php` + `partials/*` | 초보자용 UI(**자체 스코프 CSS** — 호스트 `--color-*` 토큰 있으면 상속, 없으면 폴백) |
| `config/ga4-insights.php` | route(prefix/name/middleware)·view(layout/section)·site_url·cache_ttl·setup_help |
| `README.md` | 이식 가이드 |

- **뷰 이식성**: `.ga4-dash` 스코프 CSS 가 `var(--color-primary, #0052ff)` 식 폴백 → rankfree 에선 Coinbase Blue 자동 상속, 빈 앱에선 기본색.
- **레이아웃 이식성**: `view.layout`/`view.section` config 로 호스트 레이아웃에 끼워 넣거나, 비우면 패키지 내장 레이아웃으로 독립 렌더.

## rankfree 연결

- 오토로드: 루트 `composer.json` PSR-4 `Jcurve\Ga4Insights\ → packages/ga4-insights/src/`, [bootstrap/providers.php](../bootstrap/providers.php) 에 프로바이더 등록(다른 앱은 auto-discovery).
- 자격증명: [app/Support/AppGa4Credentials.php](../app/Support/AppGa4Credentials.php) — 속성ID=환경설정(`ga.property_id`), 토큰=관리자 OAuth(→서비스계정 폴백, `GoogleToken`). [AppServiceProvider](../app/Providers/AppServiceProvider.php) 에서 바인딩.
- 설정: [config/ga4-insights.php](../config/ga4-insights.php) — `/admin/traffic-stats`(route명 `admin.traffic-stats`) · `admin.layout`/`admin-content` · operator 미들웨어 · setup_help(환경설정 유도).
- 구 `TrafficStatsController`/뷰/라우트는 제거(패키지가 대체). `GaStat`·`ga:collect`·`GoogleAnalyticsService`(속성ID/토큰 헬퍼)는 유지.

## 검증

- **Playwright 실 GA4**(속성 545801506, OAuth): 9섹션·10 KPI·실시간 렌더, 실데이터(28일 사용자 6·세션 7·참여율 57.1%·이탈률 42.9%·채널 Referral/Direct·기기·이벤트) 확인, 기간 전환 동작.
- **피처 테스트** [tests/Feature/Ga4InsightsTest.php](../tests/Feature/Ga4InsightsTest.php) 4건 — operator 권한·미연동 안내·Http::fake 라이브 렌더·API 오류 배너. 전체 288개 통과.

## 주의

- 라이브 조회라 GA4 쿼터를 쓴다 — `cache_ttl`(기본 10분)로 절약, 새로고침은 캐시만 비움.
- GA4 Data API에는 UA식 '나간 페이지(exit)' 지표가 없어, **이탈은 랜딩 이탈률(참여 없이 종료)** 로 표현. 정밀 경로는 GA4 '탐색 → 경로'.
- 날짜는 어제까지(당일 미확정) 집계, 타임존 config(`Asia/Seoul`).
