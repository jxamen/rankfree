<?php

/*
|--------------------------------------------------------------------------
| 네이버 검색광고 "웹 콘솔" 세션 클라이언트 (공식 API에 없는 기능용)
|--------------------------------------------------------------------------
| 성별·연령별 비율, 월별 검색 트렌드 = ads.naver.com 웹 내부 API (쿠키 세션).
| 로그인 자격증명·세션은 .env 로만. 쿠키는 DB 에 암호화 저장.
| (공식 HMAC API 설정은 config/rankfree.php 의 'searchad' 참조 — 별개)
*/

return [
    'base' => env('NAVER_ADS_WEB_BASE', 'https://ads.naver.com'),
    'customer_id' => env('NAVER_ADS_CUSTOMER_ID', env('NAVER_SEARCHAD_CUSTOMER_ID', '')),
    'account_no' => env('NAVER_ADS_ACCOUNT_NO', ''),

    'login' => [
        'id' => env('NAVER_ADS_LOGIN_ID', ''),
        'pw' => env('NAVER_ADS_LOGIN_PW', ''),
    ],

    'timeout' => (int) env('NAVER_ADS_WEB_TIMEOUT', 15),
    'ua' => env('NAVER_ADS_WEB_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36'),

    // 로그인 자동화(Playwright) — 없으면 형제 프로젝트 node_modules 재사용
    'playwright' => env('RANKFREE_PLAYWRIGHT', 'C:/Users/jxame/Documents/project/sign/node_modules/playwright'),
    'node' => env('RANKFREE_NODE', 'node'),
];
