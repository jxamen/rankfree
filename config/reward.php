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
