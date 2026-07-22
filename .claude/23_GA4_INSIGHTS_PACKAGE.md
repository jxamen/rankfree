# 23. GA4 상세 분석 대시보드 (이식형 패키지)

> `/admin/traffic-stats` 방문 분석을 **초보자용 상세 대시보드**로 고도화하고, 다른 서비스에도 이식 가능한 **독립 패키지 `jcurve/ga4-insights`** 로 분리했다. (2026-07-17)

## 무엇

GA4 Data API(`batchRunReports`/`runRealtimeReport`)를 **라이브**로 조회해 한 화면에 표시:
- **① 개요** — 10개 KPI(사용자·신규·세션·페이지뷰·참여율·평균 방문시간·이탈률·세션당 페이지뷰·이벤트·주요이벤트) + **직전 같은 기간 대비 증감**(이탈률은 색 반전) + 지표 툴팁
- **② 방문 추이**(일별 막대 — **막대 위 사용자 수 표기**, 1천↑ 천/만 축약, 31일 초과 시 숫자 생략·툴팁만) · **③ 유입**(채널 막대 + 소스/매체·캠페인 표) · **④ 랜딩(유입 페이지)** · **⑤ 인기 페이지**(평균 체류) · **⑥ 이탈 많은 랜딩** · **⑦ 기기·신규재방문·브라우저** · **⑧ 이벤트** · **⑨ 시간대** · **⑩ 지역(도시)**(2026-07-22 ⑦에서 개별 섹션으로 분리) · **실시간(지금 접속 중)**
- 기간 프리셋 **오늘**(당일 집계 중 · 어제와 비교)/**최근 1일**(어제 하루 · 그제와 비교)/7/14/28/90일 — `?days=today|1|7|…`, Reporter 가 today 는 당일~당일로 조회. 새로고침(캐시 비움), 쉬운 한국어 섹션 설명

### 섹션 드래그앤드롭 12그리드 (2026-07-22)

- 모든 섹션은 헤더의 **⠿ 핸들**로 드래그 — **다른 섹션 위에 놓으면 같은 줄에 나란히(균등 분할: 2=6+6, 3=4+4+4, 최대 4)**, 줄 사이 틈에 놓으면 그 자리 한 줄. `↺ 배치 초기화` 버튼으로 원복.
- 배치는 **localStorage**(`ga4-insights-layout-v1`, 브라우저별) 저장 — 서버 의존 없음(패키지 이식성 유지). 없는 섹션 키는 무시, 새 섹션은 맨 아래 자동 추가.
- ⚠️ **HTML5 DnD 를 쓰지 않는다** — dragenter/dragover 를 스펙대로 전부 취소해도 환경(헤드리스 등)에 따라 drop 이 유실됐다(실측). **Pointer Events 커스텀 드래그**(임계 5px·고스트·가장자리 자동 스크롤·pointercancel 처리)로 구현. 드래그 하이라이트는 **지오메트리를 바꾸지 않는다**(배경/아웃라인만 — 높이가 변하면 커서 아래 요소가 요동쳐 드롭이 깨진다).
- 섹션은 `container-type:inline-size` — KPI 그리드·2열 카드가 **컨테이너 쿼리**로 컬럼 폭에 적응(반폭 배치 시 자동 축소).

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
