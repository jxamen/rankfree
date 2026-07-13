<?php

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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'operator' => \App\Http\Middleware\EnsureOperator::class,
            'menu.gate' => \App\Http\Middleware\MenuGate::class,
            'usage.gate' => \App\Http\Middleware\MenuUsageGate::class,
            'auth.ext' => \App\Http\Middleware\AuthenticateExtToken::class,
            'auth.apikey' => \App\Http\Middleware\AuthenticateApiKey::class,
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
