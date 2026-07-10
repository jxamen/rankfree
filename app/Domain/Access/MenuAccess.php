<?php

namespace App\Domain\Access;

use App\Models\Menu;
use App\Models\MenuPermission;
use App\Models\User;

/**
 * 메뉴 권한 검사 — crm의 접근(메뉴)/액션(직급) 분리를 통합.
 * super 는 전권. 그 외는 사용자의 주체(grade + operator role) 중 하나라도 허용이면 통과(OR).
 * 메뉴에 등록되지 않은 route 는 제어 대상이 아니므로 접근 허용.
 */
class MenuAccess
{
    /** action: access | create | update | delete */
    public static function allows(?User $user, string $route, string $action = 'access'): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }

        $menu = Menu::where('route', $route)->first();
        if (! $menu) {
            return true; // 미등록 라우트는 제어하지 않음
        }

        $subjects = self::subjectsFor($user);
        if (! $subjects) {
            return false;
        }

        $col = 'can_' . $action;

        return MenuPermission::where('menu_id', $menu->id)
            ->where($col, true)
            ->where(function ($w) use ($subjects) {
                foreach ($subjects as [$type, $id]) {
                    $w->orWhere(fn ($x) => $x->where('subject_type', $type)->where('subject_id', $id));
                }
            })
            ->exists();
    }

    /** @return array<int,array{0:string,1:int}> */
    private static function subjectsFor(User $user): array
    {
        $s = [];
        if ($user->grade_id) {
            $s[] = ['grade', (int) $user->grade_id];
        }
        if ($user->operator_role_id) {
            $s[] = ['role', (int) $user->operator_role_id];
        }

        return $s;
    }
}
