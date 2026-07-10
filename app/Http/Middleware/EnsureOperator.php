<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 관리 영역(admin) 게이트 — 운영자(operator/admin/super)만 통과. */
class EnsureOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isOperator()) {
            abort(403, '관리자 권한이 필요합니다.');
        }

        return $next($request);
    }
}
