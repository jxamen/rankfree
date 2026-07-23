<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 어드민 호스트 게이트(2026-07-24) — /admin/* 을 비밀 서브도메인으로만 열어 URL 유추를 차단한다.
 * config rankfree.admin_host(ADMIN_HOST)가 설정돼 있으면 그 호스트가 아닌 요청은 404(존재 은닉).
 * 비어 있으면 게이트 없음(로컬 개발 기본).
 */
class AdminHostOnly
{
    public function handle(Request $request, Closure $next)
    {
        $host = strtolower(trim((string) config('rankfree.admin_host')));
        if ($host !== '' && ! hash_equals($host, strtolower($request->getHost()))) {
            abort(404);
        }

        return $next($request);
    }
}
