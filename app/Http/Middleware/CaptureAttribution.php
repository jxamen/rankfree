<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * 회원 가입 유입경로(first-touch) 캡처 — 게스트의 첫 방문에서 외부 referrer·utm 을 쿠키(1년)에 담아둔다.
 * 이후 여러 페이지를 거쳐 가입해도 '최초 유입'이 보존된다. 로그인 회원·내부 이동은 무시(쿠키 있으면 덮지 않음).
 * 가입 처리(AuthController::register)가 이 쿠키를 읽어 users 에 저장하고 쿠키를 지운다.
 */
class CaptureAttribution
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ! Auth::check() && ! $request->cookie('rf_attr')) {
            $utm = array_filter([
                'source' => $request->query('utm_source'),
                'medium' => $request->query('utm_medium'),
                'campaign' => $request->query('utm_campaign'),
                'term' => $request->query('utm_term'),
                'content' => $request->query('utm_content'),
            ], fn ($v) => is_string($v) && trim($v) !== '');

            $ref = (string) $request->headers->get('referer', '');
            $refHost = $ref !== '' ? (string) parse_url($ref, PHP_URL_HOST) : '';
            // 자사 도메인(rankfree.kr·rankfree.co.kr·ops-*) 이 아닌 곳에서 왔으면 외부 유입
            $external = $refHost !== '' && stripos($refHost, 'rankfree') === false;

            if ($utm !== [] || $external) {
                Cookie::queue('rf_attr', json_encode([
                    'referrer' => mb_substr($ref, 0, 500),
                    'utm' => $utm,
                    'landing' => mb_substr($request->path(), 0, 200),
                ], JSON_UNESCAPED_UNICODE), 60 * 24 * 365);
            }
        }

        return $next($request);
    }
}
