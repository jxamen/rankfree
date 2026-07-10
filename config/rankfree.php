<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 최상위 관리자(슈퍼어드민) 이메일
    |--------------------------------------------------------------------------
    | 이 목록의 이메일은 가입 시 자동으로 role=super 가 부여되고,
    | User::isSuperAdmin() 이 항상 true 를 반환한다.
    */
    'super_admins' => [
        'jxamen@gmail.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | 네이버 플레이스 순위체크 (A1) — crm ads/smartplace 이식
    |--------------------------------------------------------------------------
    | pcmap-api GraphQL 순위 조회 설정. 시크릿/환경값은 .env 로.
    */
    'place' => [
        // 순위 조회 요청 User-Agent
        'ua' => env('RANKFREE_PLACE_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36'),

        // nCaptcha 토큰 최후 폴백(정상 운영은 DB place_rank_tokens 의 발급 토큰 사용)
        'ncaptcha_fallback' => env('RANKFREE_NCAPTCHA_TOKEN', ''),

        // 외부 순위 릴레이(안막히는 IP 서버). 토큰 없을 때 폴백. GET ?action=get_place_rank&url=&keyword=
        'relay_url' => env('RANKFREE_RANK_RELAY', ''),

        // 순회 최대 페이지(1페이지=50개) — 6페이지=300위까지
        'max_pages' => (int) env('RANKFREE_RANK_MAX_PAGES', 6),

        // 페이지 간 대기(초) — 네이버 봇탐지 완화
        'page_delay' => (int) env('RANKFREE_RANK_PAGE_DELAY', 3),

        // 요청 타임아웃(초)
        'timeout' => (int) env('RANKFREE_RANK_TIMEOUT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | 네이버 검색광고 API — 키워드 도구(keywordstool)
    |--------------------------------------------------------------------------
    | 크롬 확장 '키워드 분석'(월간 검색량·경쟁강도)에 사용.
    | 자격증명이 비어 있으면 기능은 조용히 비활성화된다.
    | 발급: https://manage.searchad.naver.com → 도구 → API 사용 관리
    */
    'searchad' => [
        'base' => env('NAVER_SEARCHAD_BASE', 'https://api.searchad.naver.com'),
        'api_key' => env('NAVER_SEARCHAD_API_KEY', ''),
        'secret_key' => env('NAVER_SEARCHAD_SECRET', ''),
        'customer_id' => env('NAVER_SEARCHAD_CUSTOMER_ID', ''),
        'timeout' => (int) env('NAVER_SEARCHAD_TIMEOUT', 10),
    ],
];
