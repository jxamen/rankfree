<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * 회원 가입 유입경로(first-touch) 캡처 — 게스트의 첫 방문에서 외부 referrer·utm 을 쿠키(1년)에 담아둔다.
 * 이후 여러 페이지를 거쳐 가입해도 '최초 유입'이 보존된다. 로그인 회원·내부 이동은 무시(쿠키 있으면 덮지 않음).
 * 가입 처리(AuthController::register / SocialAuthController::completeSignup)가 self::applyTo() 로 저장하고 쿠키를 지운다.
 */
class CaptureAttribution
{
    /** 소셜 로그인 경유 도메인 — 유입원이 아니라 인증 왕복이므로 first-touch 로 기록하지 않는다. */
    private const AUTH_HOSTS = ['accounts.google.com', 'kauth.kakao.com', 'nid.naver.com'];

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
            // 자사 도메인(rankfree.kr·rankfree.co.kr·ops-*)·소셜 인증 경유가 아닌 곳에서 왔으면 외부 유입
            $external = $refHost !== ''
                && stripos($refHost, 'rankfree') === false
                && ! in_array(strtolower($refHost), self::AUTH_HOSTS, true);

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

    /**
     * 가입 유입경로 저장 — 위에서 심은 first-touch 쿠키(rf_attr)의 referrer·utm·landing 을 users 에 기록한다.
     * 쿠키가 없으면(직접 유입) 아무것도 하지 않는다. 이메일 가입·소셜 가입 양쪽에서 호출한다.
     */
    public static function applyTo(Request $request, User $user): void
    {
        $raw = $request->cookie('rf_attr');
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($d)) {
            return;
        }
        $user->forceFill([
            'signup_referrer' => $d['referrer'] ?? null,
            'signup_utm' => (! empty($d['utm']) && is_array($d['utm'])) ? $d['utm'] : null,
            'signup_landing' => $d['landing'] ?? null,
        ])->save();
    }
}
