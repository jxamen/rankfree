<?php

namespace App\Http\Middleware;

use App\Domain\Access\MenuAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 라우트명 기준 메뉴 접근 게이트 (menu_permissions.can_access). */
class MenuGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->getName();
        if ($route && ! MenuAccess::allows($request->user(), $route, 'access')) {
            abort(403, '접근 권한이 없습니다.');
        }

        return $next($request);
    }
}
