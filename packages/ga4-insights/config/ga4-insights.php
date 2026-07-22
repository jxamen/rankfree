<?php

return [
    /*
    |--------------------------------------------------------------------------
    | GA4 Insights — 이식 가능한 GA4 상세 분석 대시보드
    |--------------------------------------------------------------------------
    | 자격증명(속성 ID·토큰)은 Ga4Credentials 구현으로 주입한다.
    | 아래 property_id/access_token 은 기본 impl(ConfigGa4Credentials)용 폴백.
    */

    // 기본 자격증명(ConfigGa4Credentials) — 앱이 자체 Ga4Credentials 를 바인딩하면 무시됨
    'property_id' => env('GA4_PROPERTY_ID', ''),
    // 정적 Bearer 토큰 또는 토큰을 돌려주는 callable
    'access_token' => env('GA4_ACCESS_TOKEN', ''),

    // 라우트 마운트
    'route' => [
        'enabled' => true,
        'prefix' => env('GA4_INSIGHTS_PREFIX', 'ga4-insights'),
        'name' => env('GA4_INSIGHTS_ROUTE_NAME', 'ga4-insights'), // route('{name}') · route('{name}.refresh')
        'middleware' => ['web'],
    ],

    // 뷰 — 호스트 레이아웃에 끼워 넣기
    'view' => [
        'layout' => 'ga4-insights::layout', // 호스트 레이아웃(@extends 대상). 예: 'admin.layout'
        'section' => 'content',             // 콘텐츠를 넣을 @section 이름. 예: 'admin-content'
    ],

    // 사이트 절대 URL(페이지 경로 링크용). 빈 값이면 링크 미표시
    'site_url' => env('GA4_INSIGHTS_SITE_URL', env('APP_URL', '')),

    // 표시 타임존
    'timezone' => env('GA4_INSIGHTS_TZ', 'Asia/Seoul'),

    // 리포트 캐시 TTL(초) — GA4 쿼터 절약. 0=끔
    'cache_ttl' => (int) env('GA4_INSIGHTS_CACHE_TTL', 600),

    // 각 표에 노출할 행 수
    'rows' => (int) env('GA4_INSIGHTS_ROWS', 15),

    // 검색 유입 키워드(선택) — GA4 는 자연검색 검색어를 안 내려주므로 호스트 앱이 보완.
    // 클래스명 문자열만 허용(config:cache 안전). null 이면 해당 카드가 비어 있는 상태로 표시된다.
    'keywords' => [
        'gsc_provider' => null,     // Jcurve\Ga4Insights\Contracts\KeywordsProvider 구현 — 실제 검색어(예: 서치 콘솔)
        'landing_resolver' => null, // Jcurve\Ga4Insights\Contracts\LandingKeywordResolver 구현 — 랜딩 경로 → 키워드 환원
    ],
];
