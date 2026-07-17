<?php

/*
| ga4-insights 패키지 설정(rankfree 앱 오버라이드).
| 자격증명은 App\Support\AppGa4Credentials 로 주입하므로 property_id/access_token 은 미사용.
*/
return [
    'property_id' => '',   // AppGa4Credentials 가 환경설정에서 읽음
    'access_token' => '',  // AppGa4Credentials 가 구글 OAuth 로 발급

    // /admin/traffic-stats 에 마운트 — 기존 라우트명 유지(메뉴 링크 호환)
    'route' => [
        'enabled' => true,
        'prefix' => 'admin/traffic-stats',
        'name' => 'admin.traffic-stats',
        'middleware' => ['web', 'auth', 'operator'],
    ],

    // 관리자 레이아웃에 끼워 넣기
    'view' => [
        'layout' => 'admin.layout',
        'section' => 'admin-content',
    ],

    // 페이지 경로 링크용 절대 URL
    'site_url' => env('APP_URL', 'https://rankfree.kr'),

    'timezone' => 'Asia/Seoul',
    'cache_ttl' => (int) env('GA4_INSIGHTS_CACHE_TTL', 600),
    'rows' => 15,

    // 미연동 안내(앱 전용) — 환경설정으로 유도
    'setup_help' => '<b>환경설정 › 외부 연동</b>에서 <b>[구글 계정으로 연동]</b> 후 <b>GA4 속성 ID(숫자)</b>를 등록하세요. '
        .'(대안: 서비스 계정 키를 설정하고 그 계정을 GA4 속성에 뷰어로 추가) '
        .'연결되면 이 화면에 방문 데이터가 표시됩니다.',
];
