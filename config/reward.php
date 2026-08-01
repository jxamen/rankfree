<?php

/*
 * 리워드 참여시스템 코어 설정 (.claude/reward/ 설계)
 * 매체별 값은 reward_media.settings 가 이 기본값을 오버라이드한다(design-04 §2, RewardMedia::setting()).
 */
return [

    /*
     * 리워드 풀 벤더 id — 세부주문서 → 미션 동기화 필터의 기준(design-04 §2-1).
     * .env 가 아니라 어드민 환경설정(app_settings 'reward.pool_vendor_id')이 지정한다(HANDOFF §7).
     * 미지정(0)이면 동기화가 중단 + 경고 로그.
     */
    'pool_vendor_id' => 0,

    // 매체 공통 기본값 — 미니앱 /config 응답과 참여 판정의 폴백
    'defaults' => [
        'cooldown_minutes' => 120,     // 참여 간 쿨다운(분) — design-02 D3
        'cooldown_jitter_minutes' => 10,   // 쿨다운 ±지터(분) — 정각 몰림 완화
        'daily_mission_limit' => 3,    // 1인 하루 참여 횟수 — design-02 C6
        'daily_attempt_limit' => 10,   // 1인 하루 시도(오답·거절 포함) 상한 — design-02 C16
        'submit_min_interval_seconds' => 3,   // 제출 최소 간격(초) — too_fast 게이트
        'ip_daily_limit' => 30,        // IP 일 참여(확정) 상한 — design-04 §8. 0 = 끔(NAT 오탐 시)
        'ip_attempt_limit' => 300,     // IP 일 시도(오답·거절 포함) 상한 — 정답 브루트포스 차단. 0 = 끔
        'point_cap' => 5000,           // 토스 프로모션 1인 누적 상한(P) — 정책 한도(HANDOFF §7)
        'default_points' => 50,        // 작물 목록에 없을 때의 표시 폴백(server-api-spec §0)
    ],

    // 미션 목록에 내려주는 개수(design-02 §8-1)
    'exposure_limit' => 8,

    /*
     * 상품 사진이 없을 때 대신 내려줄 이미지(어드민 설정 'reward.default_product_image').
     * 쇼핑 미션 안내는 사진·상품명·가격을 함께 보여주는데, 사진 자리가 비면 화면이 깨져 보인다.
     */
    'default_product_image' => 'https://shop-phinf.pstatic.net/20260508_43/1778232942496MPY10_PNG/112365762552778230_452519374.png?type=o1000',

    /*
     * 정답을 무엇으로 낼지 — 신규 미션의 기본값(어드민 설정 'reward.answer_source' 로 변경).
     * 미션별로 다르게 하려면 reward_missions.answer_type 을 바꾼다(그 값이 최우선).
     *   tag   = 상품 해시태그 중 참여자별 N번째 (기본)
     *   price = 상품 판매가 (tolerance_percent 만큼 오차 허용)
     *   text  = 운영자가 입력한 고정 정답(문자)
     *   number= 운영자가 입력한 고정 정답(숫자, tolerance_percent 적용)
     */
    'answer_source' => 'tag',
    'answer_sources' => [
        'tag' => '상품 해시태그 (N번째)',
        'price' => '상품 판매가',
        'text' => '고정 정답 (문자)',
        'number' => '고정 정답 (숫자)',
    ],

    /*
     * 미션 안내 문구 템플릿 — 클라이언트가 하드코딩하지 않도록 API 응답에 실어 보낸다.
     * 어드민 환경설정('reward.copy.*')이 이 기본값을 덮어쓰므로 문구 변경에 배포가 필요 없다.
     * 치환 변수: {tagIndex} {tagCount} {shop_name} {product_title} {keyword} {price} {reward_item} {reward_count}
     * 미션별로 다른 문구를 쓰려면 reward_missions 의 guide/question/placeholder 를 채운다(그 값이 최우선).
     */
    'copy' => [
        'shopping_tag' => [
            'guide' => [
                "'참여하기'를 누르면 네이버에서 「{keyword}」 검색 결과가 열려요.",
                '[광고] 표시가 없는 {shop_name}의 「{product_title}」 상품을 찾아 눌러 주세요.',
                '상품 페이지에서 [상세정보 펼쳐보기]를 누르고 맨 아래까지 내리면 태그가 있어요.',
                '앞에서부터 세어 {tagIndex}번째 태그를 입력해 주세요. # 기호는 빼고 글자만 적으면 돼요.',
            ],
            'question' => '{tagIndex}번째 태그를 입력해 주세요',
            'placeholder' => '{tagIndex}번째 태그 입력',
            'notice' => '[광고] 가 붙은 상품은 참여할 수 없어요. 아래 정보와 같은 상품을 찾아 주세요.',
            'description' => '{keyword} 검색에서 {shop_name} 상품을 찾아 태그를 확인하면 {reward_item} {reward_count}개를 받아요.',
        ],
        // 태그가 없는 미션(고정 정답형) 폴백
        'fallback' => [
            'guide' => [
                "'참여하기'를 누르면 상품 페이지가 열려요.",
                '상품 정보를 확인하고 돌아와 정답을 입력해 주세요.',
            ],
            'question' => '정답을 입력해 주세요',
            'placeholder' => '',
            'notice' => '',
            'description' => '{keyword} 검색 결과에서 {shop_name} 상품을 확인하면 {reward_item} {reward_count}개를 받아요.',
        ],
    ],

    /*
     * 신규 참여자 행 생성 예산(IP·시간당) — x-user-key 는 클라이언트가 자유 발급하므로
     * 인증 없는 읽기 엔드포인트만으로 reward_users 를 무제한 만들 수 있다. 0 = 끔.
     */
    'new_user_per_ip_hourly' => 60,

    // 일 한도 초과 정책(C8) — reject(기본) | absorb. 실측 거절률 0.5% 초과 시 absorb 전환 검토
    'overflow_policy' => 'reject',

    /*
     * 캐시 계층(design-02 §7) — L1(APCu/static)은 항상 켜져 있고, L2 는 스토어를 지정해야 켜진다.
     * Redis 도입 시 REWARD_L2_STORE=reward_l2 (config/cache.php 에 정의) 로 승격 — 없이도 완전 동작(§7-5).
     */
    'cache' => [
        'l2_store' => env('REWARD_L2_STORE'),   // null = L2 끔(현 운영 기본) | reward_l2 = Redis
        // C11 버전 카운터·L2 서킷 브레이커가 쓰는 스토어 — 서버 간 공유가 필요하다.
        // 기본 스토어는 운영에서 file(서버 로컬)이라 다중 서버로 늘면 무효화가 서버마다 따로 논다.
        'shared_store' => env('REWARD_SHARED_CACHE_STORE', 'database'),
        'ttl' => [
            'mission_list_l1' => 5, 'mission_list_l2' => 60,    // C1
            'media_l1' => 30,                                    // 벤더 매체 매핑(rate limit 용)
        ],
    ],

    /*
     * 시간 구간(slot) — design-02 §2-2. weight 합계 100.
     * 02:00~06:00 은 심야 휴지(노출·참여 중단), 농장일 경계는 quiet_to(06:00).
     */
    'quota' => [
        'slots' => [
            ['code' => 'S1', 'from' => '06:00', 'to' => '09:00', 'weight' => 8],
            ['code' => 'S2', 'from' => '09:00', 'to' => '12:00', 'weight' => 13],
            ['code' => 'S3', 'from' => '12:00', 'to' => '14:00', 'weight' => 15],
            ['code' => 'S4', 'from' => '14:00', 'to' => '18:00', 'weight' => 14],
            ['code' => 'S5', 'from' => '18:00', 'to' => '20:00', 'weight' => 14],
            ['code' => 'S6', 'from' => '20:00', 'to' => '22:00', 'weight' => 21],
            ['code' => 'S7', 'from' => '22:00', 'to' => '02:00', 'weight' => 15],
        ],
        'quiet_from' => '02:00',
        'quiet_to' => '06:00',
    ],
];
