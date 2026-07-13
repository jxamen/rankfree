<?php

namespace App\Http\Middleware;

use App\Domain\Access\MenuAccess;
use App\Models\Menu;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 메뉴 × 등급별 월간 이용 횟수 제한 — 라우트가 메뉴 route 와 일치하는 GET 요청을 계량.
 * 한도 미설정(-1)이면 아무 동작 안 함(접근 제어는 별도, 여기선 횟수만).
 */
class MenuUsageGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route()?->getName();

        // 계량 예외: 대시보드(리다이렉트 목적지) + 키워드 분석(스냅샷 열람은 무과금 — 컨트롤러가 재분석 시에만 계량)
        $skip = ['console.dashboard', 'console.keyword'];
        if ($user && $route && ! in_array($route, $skip, true) && $request->isMethod('GET') && ! $user->isSuperAdmin()) {
            $menu = Menu::where('route', $route)->first();
            if ($menu) {
                $limit = MenuAccess::menuLimitFor($user, $menu);
                if ($limit >= 0 && ! $user->tryConsumeUsage('menu:'.$menu->id, $limit)) {
                    $msg = "'{$menu->name}' 이번 달 이용 횟수({$limit}회)를 모두 사용했습니다. 요금제를 업그레이드하면 더 이용할 수 있습니다.";
                    if ($request->ajax() || $request->wantsJson()) {
                        abort(429, $msg);
                    }

                    return redirect()->route('console.dashboard')->with('status', $msg);
                }
            }
        }

        return $next($request);
    }
}
