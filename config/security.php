<?php

/*
 * 취약점 탐침(probe) 자동 차단 — .env·.aws/credentials 같은 자격증명 파일을 훑는 IP 를 기록·차단한다.
 * 구현: App\Http\Middleware\BlockProbeIps · App\Models\BlockedIp
 *
 * ⚠️ 전제: 실제 클라이언트 IP 가 REMOTE_ADDR 로 들어와야 한다.
 *    현재 rankfree 는 TrustProxies 미설정 + Cloudflare DNS-only 라 이 조건이 성립한다.
 *    Cloudflare 프록시(주황 구름)를 켜면 REMOTE_ADDR 이 엣지 IP 가 되어
 *    엣지 하나를 차단하는 순간 전체 사용자가 막힌다. 그때는 TrustProxies 설정이 선행돼야 하며,
 *    아래 trust_proxy_guard 가 그 사고를 막는 안전장치다.
 */
return [

    'probe_block' => [

        'enabled' => env('SECURITY_PROBE_BLOCK', true),

        // 차단 유지 시간(시간). 스캐너는 IP 를 계속 바꾸므로 영구 차단은 목록만 불린다.
        // 공유 IP(통신사 NAT)에 잘못 걸렸을 때의 피해도 이 시간으로 제한된다.
        'ttl_hours' => (int) env('SECURITY_PROBE_BLOCK_HOURS', 24),

        /*
         * 탐침으로 볼 경로(정규식, 앞의 "/" 없는 형태로 매칭).
         * 사람이 브라우저로는 절대 요청하지 않는 경로만 넣는다 — 한 번만 걸려도 차단하기 때문이다.
         */
        'patterns' => [
            '#^\.env#i',                                    // .env .env.bak .env.old .env.example
            '#^\.git(/|-credentials|config|ignore)#i',      // .git/HEAD .git-credentials .gitconfig
            '#^\.aws/#i',
            '#^\.ssh/#i',
            '#^\.(vscode|idea|svn|hg)/#i',
            '#^\.DS_Store$#i',
            '#(^|/)wp-(admin|login\.php|includes/|content/)#i',   // 워드프레스가 아니다
            '#(^|/)xmlrpc\.php$#i',
            '#(^|/)(phpmyadmin|phpMyAdmin|pma|myadmin|adminer\.php)#i',
            '#(^|/)vendor/phpunit/#i',                      // CVE-2017-9841 RCE 탐침
            '#(^|/)(credentials|secrets)\.(json|ya?ml|txt|bak)$#i',
            '#^server-status#i',
        ],

        /*
         * 절대 탐침으로 보지 않을 경로.
         * 🔴 .well-known 은 점으로 시작하지만 정상 경로다 — Let's Encrypt 인증서 갱신(acme-challenge)이
         *    여기로 들어온다. 여기를 막으면 인증서 갱신이 실패해 사이트 전체가 HTTPS 로 안 열린다.
         */
        'safe_paths' => [
            '#^\.well-known/#i',
        ],

        // 어떤 경우에도 차단하지 않을 IP(운영자 사무실·모니터링·프록시 등).
        // 쉼표로 구분해 SECURITY_NEVER_BLOCK_IPS 에 넣는다.
        'never_block' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SECURITY_NEVER_BLOCK_IPS', '127.0.0.1,::1'))
        ))),

        /*
         * 🔴 프록시 뒤 오차단 방지. 요청에 아래 헤더가 있는데 TrustProxies 가 설정돼 있지 않다면
         * REMOTE_ADDR 은 실제 클라이언트가 아니라 프록시(CDN)일 가능성이 크다 — 이때는 차단하지 않는다.
         * Cloudflare 프록시를 켜는 순간 전체 사용자를 막아버리는 사고를 여기서 끊는다.
         */
        'trust_proxy_guard' => true,
        'proxy_headers' => ['CF-Connecting-IP', 'X-Forwarded-For', 'True-Client-IP'],
    ],
];
