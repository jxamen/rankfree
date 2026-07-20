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
        'jcurve19@gmail.com',
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
        // 노출 키워드 분석(25) — 조합 후보를 순위체크할 때의 상한(쿼터 보호)
        'exposure' => [
            'top' => (int) env('SHOP_EXPOSURE_TOP', 5),           // 이 순위 이내면 "노출"로 본다
            'max_combos' => (int) env('SHOP_EXPOSURE_MAX_COMBOS', 100), // 기본 조합 수(입력창 select 로 30~500 조절)
            // 부정적 단어(명백한 것만 — 나머지는 결과에서 개별 삭제). 이 조각 포함 키워드/조합은 만들지 않는다.
            'negatives' => [
                '과다복용', '부작용', '후유증', '독성', '리콜', '소송', '고발', '가짜', '짝퉁', '허위', '과대광고',
            ],
            'max_tokens' => (int) env('SHOP_EXPOSURE_MAX_TOKENS', 5),  // 조합 최대 단어 수(2~5) — 속성 많을수록 롱테일 top5
            'attr_pool' => (int) env('SHOP_EXPOSURE_ATTR_POOL', 10),   // 조합 재료(속성+수식어) 풀 크기(부분집합 폭발 방지)
            'scan_pages' => (int) env('SHOP_EXPOSURE_SCAN_PAGES', 1),  // 조합당 shop.json 페이지 수(1=상위 100, 조합당 1콜)
            'batch_size' => (int) env('SHOP_EXPOSURE_BATCH_SIZE', 15), // 폴링 1회에 순위체크할 조합 수
            'batch_sec' => (int) env('SHOP_EXPOSURE_BATCH_SEC', 12),   // 폴링 1회 시간예산(초) — 게이트웨이 타임아웃 방지
            // 어미/수식어 — "{핵심} {어미}" 조합을 대량 생성한다. 관리자·사용자가 추가 가능(입력창).
            'suffixes' => [
                '추천', '인기', '순위', '무료배송', '정품', '최저가', '가성비', '할인', '세일', '특가',
                '베스트', '신상', '내돈내산', '후기', '리뷰', '비교', '당일발송', '오늘출발', '기획전', '선물',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 키워드 콘텐츠 허브 (22_KEYWORD_CONTENT_HUB) — 카테고리별 키워드 수집·발행
    |--------------------------------------------------------------------------
    | hub:collect(시드→연관·자동완성 후보 수집) → 관리자 승인 → hub:publish(분석 발행)
    | → hub:refresh(주기 갱신).
    | 발굴(collect/discover/refresh)은 기본 off(쿼터 보호). 발행(publish)은 관리자 승인분만 처리하므로
    | 발굴과 분리해 기본 on — 승인 큐가 빌 때까지 자동으로 계속 드레인한다(회당 ≤publish_per_run).
    */
    'hub' => [
        // 발굴(hub:collect/discover/refresh/shopping-collect) 자동 실행 — 쿼터·도어웨이 보호로 기본 off
        'schedule_enabled' => (bool) env('HUB_SCHEDULE_ENABLED', false),
        // 승인 후보 자동 발행 — 발굴과 분리(관리자 승인분만 처리). 기본 on: 승인분을 자동으로 계속 발행
        'publish_enabled' => (bool) env('HUB_PUBLISH_ENABLED', true),
        // hub:publish 자동 실행 간격(분, 1~60) — 이 주기마다 승인 후보 ≤publish_per_run 발행(없으면 idle)
        'publish_interval' => (int) env('HUB_PUBLISH_INTERVAL', 10),
        // 후보 자동 필터: 이 미만 월간 검색량은 후보에서 제외(자동완성 등 볼륨 미상은 pending 유지)
        'min_volume' => (int) env('HUB_MIN_VOLUME', 1000),
        // hub:collect 1회에 처리할 카테고리 수(collected_at 오래된 순 로테이션)
        'collect_categories' => (int) env('HUB_COLLECT_CATEGORIES', 3),
        // hub:publish 1회 발행 상한(검색광고 쿼터 보호 — 도어웨이 대량 발행 방지)
        'publish_per_run' => (int) env('HUB_PUBLISH_PER_RUN', 10),
        // hub:auto-publish(관리자 토글, 매분) 1회 발행 상한 — 시간 예산(45초) 안에서 이 개수까지
        'auto_per_run' => (int) env('HUB_AUTO_PER_RUN', 15),
        // hub:refresh 1회 재수집 상한(refreshed_at 오래된 순)
        'refresh_per_run' => (int) env('HUB_REFRESH_PER_RUN', 20),
        // 발행 문서 재수집 주기(일) — 이보다 어린 문서는 갱신하지 않음
        'refresh_after_days' => (int) env('HUB_REFRESH_AFTER_DAYS', 30),
        // 후보 제외 패턴(정규식 조각, u 플래그) — 업체명·개인정보성 키워드 등
        'banned_patterns' => [],
        // GSC 발굴(hub:discover) — 유입 쿼리를 후보로 적재할 카테고리 슬러그(빈값=발굴 끔)·최소 노출수
        'discover_category' => env('HUB_DISCOVER_CATEGORY', ''),
        'discover_min_impressions' => (int) env('HUB_DISCOVER_MIN_IMPRESSIONS', 30),
        // 데이터랩 쇼핑 인기검색어 수집 페이지 수(hub:shopping-collect, 페이지당 20개 · 실측 최대 25=500위)
        'datalab_pages' => (int) env('HUB_DATALAB_PAGES', 25),
        // 순위 매핑(keyword_place_ranks·keyword_shop_ranks) 보존 개월 수(현재 월 포함) —
        // hub:partition-rotate 가 이 기간 지난 월 파티션을 DROP. 0 이하 = 파기 안 함(선생성만 수행)
        'rank_retention_months' => (int) env('HUB_RANK_RETENTION_MONTHS', 13),
    ],

    /*
    |--------------------------------------------------------------------------
    | 신규 개업(인허가) 수집 — 관리자 확인용 (24_NEW_BUSINESS.md)
    |--------------------------------------------------------------------------
    | 지방행정 인허가 공공데이터에서 "최근 인허가 업소"를 받아 관리자 화면에서 열람하고,
    | 해당 업소의 네이버 플레이스 등록 여부를 붙인다.
    |   ⚠️ 이 데이터로 문자·이메일 광고를 보내면 정보통신망법 제50조 위반이다
    |      (수신자가 사업자여도 면제 없음, 과태료 750/1,500/3,000만원). 발송 기능을 붙이지 말 것.
    |   ⚠️ 전화는 암호화 저장(개인정보 취급). 자동 파기는 운영 결정으로 두지 않는다(2026-07-17).
    | 전국(data.go.kr) 확장은 별도 인증키 발급 필요 — 현재는 서울 열린데이터광장 제공분.
    */
    'newbiz' => [
        // 서울 열린데이터광장 인증키(https://data.seoul.go.kr) — 빈값이면 sample(응답 5건 제한)
        'seoul_key' => env('SEOUL_OPENAPI_KEY', 'sample'),
        'seoul_base' => env('SEOUL_OPENAPI_BASE', 'http://openapi.seoul.go.kr:8088'),
        /*
         * 수집 업종 — 서비스명 => 표시명.
         * ⚠️ 첫 선택인자의 인허가일자 필터는 **서비스마다 동작이 다르다**(실측 2026-07):
         *    - LOCALDATA_072404(일반음식점): 필터 정상('2026-07-10' → 48건, 'YYYY-MM' 접두도 동작)
         *    - LOCALDATA_072405(휴게음식점): 필터 무시 — 전체 14.6만건이 그대로 응답
         *      → 날짜로 못 좁혀 전체 스캔(147콜/일)이 필요하므로 제외. 전국 확장(data.go.kr,
         *        cond[인허가일자::GTE] 지원) 때 함께 붙인다.
         * 새 업종을 넣기 전에 반드시 날짜 필터가 먹는지 확인할 것 — 수집기가 어긋난 행은 버리고 오류를 낸다.
         */
        'services' => [
            'LOCALDATA_072404' => '일반음식점',
        ],
        'page_size' => (int) env('NEWBIZ_PAGE_SIZE', 1000),   // 서울 API 1회 최대 1000(sample 키는 5)
        'timeout' => (int) env('NEWBIZ_TIMEOUT', 20),
        // 수집 기본 기간(일) — 인허가일자 기준 최근 N일
        'collect_days' => (int) env('NEWBIZ_COLLECT_DAYS', 7),
        /*
         * 플레이스 확인은 **건수 상한이 없다** — 대상이 0 이 될 때까지 전부 확인한다.
         * 아래는 상한이 아니라 **화면(AJAX) 1회 요청당 배치 크기**다. 한 요청에서 수백 건 × 네이버 왕복을
         * 돌리면 게이트웨이 타임아웃에 걸려 결과를 못 돌려주므로, 화면이 이 크기로 끊어서 이어 호출한다.
         * CLI(newbiz:collect / newbiz:place-match)는 배치 없이 한 번에 전부 처리한다.
         */
        'place_match_batch' => (int) env('NEWBIZ_PLACE_MATCH_BATCH', 5),   // 작게 — 진행률이 자주 갱신된다(5건 ≈ 5초)
        // 미등록(not_found) 재확인 주기 — 개업 직후엔 플레이스가 없다가 나중에 생기므로 계속 다시 찾는다
        'recheck_after_days' => (int) env('NEWBIZ_RECHECK_AFTER_DAYS', 3),
        // 재확인을 계속할 기간 — 인허가 후 N일까지(그 뒤로도 없으면 플레이스를 안 내는 업소로 본다)
        'recheck_max_age_days' => (int) env('NEWBIZ_RECHECK_MAX_AGE_DAYS', 90),
        // 자동 스케줄 on/off — 기본 off(수동 실행으로 시작)
        'schedule_enabled' => (bool) env('NEWBIZ_SCHEDULE_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | 커뮤니티 페르소나 자동 활동
    |--------------------------------------------------------------------------
    | 스케줄러(schedule:run)가 주기적으로 community:simulate 를 실행하고,
    | 어드민 '지금 활동 생성' 버튼도 같은 시뮬레이터를 쓴다.
    */
    'community' => [
        // 스케줄 1회 실행 시 시도할 활동(글/댓글/좋아요) 총 개수
        'tick_actions' => (int) env('COMMUNITY_TICK_ACTIONS', 8),
        // 스케줄 자동 활동 on/off (수동 버튼은 항상 동작)
        'schedule_enabled' => (bool) env('COMMUNITY_SCHEDULE_ENABLED', true),
        // 한 번의 활동에서 글:댓글:좋아요 기본 비율(페르소나 가중치와 곱해짐)
        'mix' => ['post' => 1, 'comment' => 3, 'like' => 5],
        // 글밥 재작성(AI) — 어드민 환경 설정에서 오버라이드(SettingsServiceProvider)
        'rewrite' => [
            'provider' => env('COMMUNITY_REWRITE_PROVIDER', 'auto'), // auto(Gemini 우선)|gemini|anthropic|off
            'model' => env('COMMUNITY_REWRITE_MODEL', ''),           // 빈값 = 공급자 기본 모델
            'fallback' => (bool) env('COMMUNITY_REWRITE_FALLBACK', true), // AI 실패 시 원문 가벼운 변형 사용 허용
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 카페 글감 수집 (cafe:crawl) — scripts/naver-cafe-crawler.cjs 연동
    |--------------------------------------------------------------------------
    | 네이버 카페 인기글(제목·본문·작성일·댓글)을 수집해 cafe_crawl_* 에 저장하고,
    | --seed 옵션으로 커뮤니티 글밥(community_seeds)으로 전환한다.
    | 로그인 세션은 scripts/.naver-cafe-profile — 카페 멤버 계정으로
    | `node scripts/naver-cafe-crawler.cjs --reset --headful` 1회 로그인 필요.
    */
    'cafe_crawl' => [
        'node' => env('RANKFREE_NODE_BIN', 'node'),
        // 어드민 '지금 수집' 버튼의 백그라운드 artisan 실행용 php 경로(빈값=자동 감지)
        'php_bin' => env('RANKFREE_PHP_BIN', ''),
        // 수집 대상 카페 ID (기본: 아프니까사장이다)
        'cafe_id' => (int) env('CAFE_CRAWL_CAFE_ID', 23611966),
        // 스케줄 자동 수집 on/off
        'schedule_enabled' => (bool) env('CAFE_CRAWL_SCHEDULE_ENABLED', true),
        // 글밥 전환 시 댓글 최소 길이(이보다 짧은 댓글은 글밥으로 안 씀)
        'seed_min_comment_length' => (int) env('CAFE_CRAWL_SEED_MIN_COMMENT', 8),
    ],

    // 저품질 키워드 가지치기 — 남은 키워드 조회수 확인 후 월 <=10 삭제(발행분 유지). 10분마다 청크.
    'keyword_prune' => [
        'schedule_enabled' => (bool) env('KEYWORD_PRUNE_SCHEDULE_ENABLED', true),
    ],

    // 플레이스 좌표 백필 — >10 발행문서 SERP 재수집으로 업체 좌표 적재(지리 추천). 20분마다 청크.
    'place_coords' => [
        'schedule_enabled' => (bool) env('PLACE_COORDS_SCHEDULE_ENABLED', true),
    ],
];
