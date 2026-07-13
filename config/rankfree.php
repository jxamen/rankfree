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
    | 경쟁분석 점수(N2) 가중치 — 실측 학습으로 개선 가능
    |--------------------------------------------------------------------------
    | N2 관련성 = D1~D10 세부지표의 가중평균(weighted() 가 present 차원으로 재정규화).
    | 기본값은 crm 이식 수동 튜닝치(프라이어). 소유매장의 실제 조회수(스마트플레이스)를
    | 라벨로 PlaceWeightLearner 가 학습해 개선하며, 표본이 충분(≥ apply_min_stores)해질 때까지
    | 이 프라이어를 그대로 사용한다. 학습이 확정되면 이 값을 갱신(수동/자동)한다.
    */
    'scoring' => [
        'n2_weights' => [
            'd1' => 0.18, 'd2' => 0.09, 'd3' => 0.07, 'd4' => 0.12,
            'd5' => 0.08, 'd6' => 0.08, 'd7' => 0.14, 'd9' => 0.20, 'd10' => 0.12,
        ],
        // 학습 가중치를 실제 반영하기 위한 최소 라벨(소유매장) 수. 미만이면 프라이어 유지.
        'apply_min_stores' => (int) env('RANKFREE_WEIGHT_APPLY_MIN', 10),
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

    /*
    |--------------------------------------------------------------------------
    | 네이버 쇼핑 검색 OpenAPI — 쇼핑 순위추적
    |--------------------------------------------------------------------------
    | openapi.naver.com/v1/search/shop.json (query·display·start·sort). 헤더 X-Naver-Client-Id/Secret.
    | 다중 키(콤마 구분 "id:secret,...") — 429(한도)면 다음 키로 로테이션. .env 에만 보관.
    */
    'shopping' => [
        // "id:secret,id:secret,…" → [['id'=>,'secret'=>], …]
        'api_keys' => array_values(array_filter(array_map(
            function ($pair) {
                $p = array_map('trim', explode(':', trim($pair), 2));

                return (count($p) === 2 && $p[0] !== '' && $p[1] !== '') ? ['id' => $p[0], 'secret' => $p[1]] : null;
            },
            explode(',', (string) env('NAVER_SHOPPING_API_KEYS', '')),
        ))),
        'display' => 100,       // 페이지당 결과(최대 100)
        'max_pages' => (int) env('NAVER_SHOPPING_MAX_PAGES', 10),  // 100×10 = 1000위까지
        'page_delay_ms' => (int) env('NAVER_SHOPPING_PAGE_DELAY_MS', 200),
        'timeout' => (int) env('NAVER_SHOPPING_TIMEOUT', 15),
    ],
];
