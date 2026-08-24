<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateExtToken;
use App\Http\Middleware\AuthenticateRewardMedia;
use App\Http\Middleware\AuthenticateRewardUser;
use App\Http\Middleware\BlockProbeIps;
use App\Http\Middleware\CaptureAttribution;
use App\Http\Middleware\EnsureOperator;
use App\Http\Middleware\LogAiCrawler;
use App\Http\Middleware\MenuGate;
use App\Http\Middleware\MenuUsageGate;
use App\Http\Middleware\RedirectCanonicalHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            require __DIR__.'/../routes/coupon.php';   // 쿠폰(26) — 별도 파일
            require __DIR__.'/../routes/farm.php';     // 리워드 참여시스템 — 퀴즈농장 미니앱 API (.claude/reward)
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 대표 도메인 통합(2026-07-27) — rankfree.co.kr(+www) → rankfree.kr 301.
        // 가장 앞에서 처리해 세션·라우팅 비용을 아낀다. 서브도메인(어드민·단축URL)은 정확일치가 아니라 통과.
        $middleware->prepend(RedirectCanonicalHost::class);

        // 취약점 탐침 IP 차단(2026-08-02) — /.env·/.aws/credentials 를 훑는 IP 를 기록·차단.
        // 나중에 prepend 한 것이 앞에 온다: 차단된 IP 는 리다이렉트·세션 비용도 쓰지 않는다.
        $middleware->prepend(BlockProbeIps::class);

        // AI 크롤러 유입 기록(2026-07-27) — GPTBot·ChatGPT-User 등은 JS 미실행이라 GA4 에 안 잡힌다.
        // 리다이렉트 이후에 두어 대표 도메인으로 정리된 요청만 집계한다.
        $middleware->append(LogAiCrawler::class);

        // 가입 유입경로(first-touch) 캡처 — 게스트 첫 방문의 외부 referrer·utm 을 쿠키에 담아 가입 시 저장한다.
        $middleware->append(CaptureAttribution::class);

        $middleware->alias([
            'operator' => EnsureOperator::class,
            'menu.gate' => MenuGate::class,
            'usage.gate' => MenuUsageGate::class,
            'auth.ext' => AuthenticateExtToken::class,
            'auth.apikey' => AuthenticateApiKey::class,
            'auth.reward' => AuthenticateRewardUser::class,
            'auth.media' => AuthenticateRewardMedia::class,   // 제휴 매체 전용 키(회원 키와 분리)
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // api/* 및 JSON 을 기대하는 AJAX 요청(콘솔의 fetch 등)은 예외를 JSON 으로 렌더링
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // 민감정보는 validation 실패 시 세션에 플래시하지 않는다 (naver_pw/cookie = 네이버 자격·세션)
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'cookie', 'naver_pw']);
    })->create();
