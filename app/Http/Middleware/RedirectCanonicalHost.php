<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 대표 도메인 통합(2026-07-27) — rankfree.co.kr(+www) 로 들어온 요청을 rankfree.kr 로 301 보낸다.
 * 두 도메인이 같은 내용을 200 + self-canonical 로 서빙해 검색 자산이 갈라지던 문제 해결.
 *
 * ⚠️ **서브도메인은 절대 건드리지 않는다** — 정확히 일치하는 호스트만 리다이렉트한다.
 *   - 어드민 비밀 호스트: ops-….rankfree.co.kr
 *   - 단축 URL 도메인 16개: sunny-….rankfree.co.kr 등 (이미 발주로 배포된 주소)
 *   와일드카드로 처리하면 관리자 접속이 막히고 배포된 단축 URL 이 전부 깨진다.
 *
 * 설정: config('rankfree.canonical.host') / config('rankfree.canonical.redirect_hosts')
 */
class RedirectCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $target = trim((string) config('rankfree.canonical.host', ''));
        if ($target === '') {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        $from = array_map(
            fn ($h) => strtolower(trim((string) $h)),
            (array) config('rankfree.canonical.redirect_hosts', []),
        );

        // 정확 일치만 — 서브도메인은 통과(어드민·단축 URL 보호)
        if (! in_array($host, array_filter($from), true)) {
            return $next($request);
        }

        // 경로·쿼리는 그대로 유지해 개별 문서의 링크 자산도 대표 도메인으로 넘긴다
        return redirect()->away('https://'.$target.$request->getRequestUri(), 301);
    }
}
