<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Anthropic Claude API — 커뮤니티 페르소나 콘텐츠 생성용. 키 없으면 템플릿 폴백.
    // 어드민 '환경 설정 > API 설정'에서 등록 시 이 키를 런타임 오버라이드(SettingsServiceProvider).
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'), // 대량 활동 시 'claude-haiku-4-5' 로 비용 절감 가능
    ],

    // Google Gemini — 커뮤니티 글 재작성 기본 공급자(무료 티어). OpenAI 는 저장·표시용.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        // 별칭(-latest) 사용 — 구모델이 종료돼도 현재 모델로 자동 승계.
        // (신규 프로젝트는 gemini-2.5-pro/2.5-flash/2.0-flash 가 404. 3.x·-latest 만 사용 가능)
        'model' => env('GEMINI_MODEL', 'gemini-flash-latest'),
        // 캡차 이미지(영수증) 분석 Gemini 기본 — Pro(정확도). quiz.model 설정이 우선.
        'quiz_model' => env('GEMINI_QUIZ_MODEL', 'gemini-pro-latest'),
        // Gemini 모델이 404/429/503 이면 자동 전환할 폴백(한도 넉넉·저비용 Flash).
        'quiz_fallback_model' => env('GEMINI_QUIZ_FALLBACK_MODEL', 'gemini-flash-latest'),
    ],
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5'),   // 커뮤니티 글 재작성용 기본(모델은 환경 설정 드롭다운에서 선택)
    ],

    // xAI Grok — 커뮤니티 글 재작성 공급자(OpenAI Chat Completions 호환). 키는 환경 설정 > AI 모델 API 키.
    'xai' => [
        'key' => env('XAI_API_KEY'),
        'model' => env('XAI_MODEL', 'grok-4'),
    ],

    // Cloudflare Turnstile — 비회원 무료 순위조회 봇 차단. 키 미설정 시 검증 생략(로컬/미배포).
    'turnstile' => [
        'key' => env('TURNSTILE_SITE_KEY'),      // 위젯 렌더용 사이트 키(공개)
        'secret' => env('TURNSTILE_SECRET'),     // 서버 검증용 시크릿
    ],

    // 소셜 로그인 — 각 플랫폼 개발자센터에서 앱 등록 후 Client ID/Secret 발급.
    // Redirect(콜백) URL 등록: {APP_URL}/auth/{provider}/callback
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],
    'kakao' => [
        'client_id' => env('KAKAO_CLIENT_ID'),
        'client_secret' => env('KAKAO_CLIENT_SECRET'), // 카카오는 선택(REST API 키만으로도 가능)
        'redirect' => env('KAKAO_REDIRECT_URI', '/auth/kakao/callback'),
    ],

    // 알리고 SMS — 전화번호 인증 문자 발송. 발신번호(sender)는 알리고에 사전 등록 필요.
    'aligo' => [
        'user_id' => env('ALIGO_USER_ID'),
        'key' => env('ALIGO_API_KEY'),
        'sender' => env('ALIGO_SENDER'),
        'test' => env('ALIGO_TEST_MODE', false), // true면 실제 발송 없이 성공 응답(개발용)
    ],

];
