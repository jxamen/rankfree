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
    /**
     * 접근 허용 여부. 기본 허용(미설정 메뉴/주체는 통과).
     * 관리자가 명시적으로 접근을 끈(can_access=false) 주체만 차단.
     * 여러 주체(등급+직원) 중 하나라도 허용이면 통과(OR).
     */
    public static function allows(?User $user, string $route, string $action = 'access'): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true; // 슈퍼는 항상 전권
        }

        $menu = Menu::where('route', $route)->first();
        if (! $menu) {
            return true; // 미등록 라우트는 제어하지 않음
        }

        $subjects = self::subjectsFor($user);
        if (! $subjects) {
            return true; // 주체 없음 → 기본 허용
        }

        $rows = MenuPermission::where('menu_id', $menu->id)
            ->where(function ($w) use ($subjects) {
                foreach ($subjects as [$type, $id]) {
                    $w->orWhere(fn ($x) => $x->where('subject_type', $type)->where('subject_id', $id));
                }
            })
            ->get(['can_access']);

        if ($rows->isEmpty()) {
            return true; // 이 메뉴에 주체 설정 없음 → 기본 허용
        }

        // 주체 중 하나라도 허용이면 통과
        return $rows->contains(fn ($r) => (bool) $r->can_access);
    }

    /**
     * 사용자 주체(등급/역할) 중 이 메뉴에 설정된 월 이용 한도의 최댓값.
     * 어느 주체도 제한 미설정(-1)이면 -1(무제한). super 는 항상 -1.
     * 반환: -1 무제한 | 0 미제공(모든 주체 0) | N 월 N회
     */
    public static function menuLimitFor(User $user, Menu $menu): int
    {
        if ($user->isSuperAdmin()) {
            return -1;
        }

        $subjects = self::subjectsFor($user);
        if (! $subjects) {
            return -1; // 주체 없음 → 제한 미적용(접근 게이트와 별개)
        }

        $limits = MenuPermission::where('menu_id', $menu->id)
            ->where(function ($w) use ($subjects) {
                foreach ($subjects as [$type, $id]) {
                    $w->orWhere(fn ($x) => $x->where('subject_type', $type)->where('subject_id', $id));
                }
            })
            ->pluck('monthly_limit')
            ->all();

        if (! $limits) {
            return -1; // 이 메뉴에 주체 권한행 없음 → 무제한
        }
        // 여러 주체 중 하나라도 무제한(-1)이면 무제한, 아니면 최대 허용치
        if (in_array(-1, array_map('intval', $limits), true)) {
            return -1;
        }

        return max(array_map('intval', $limits));
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
