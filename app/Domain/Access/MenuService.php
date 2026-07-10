<?php

namespace App\Domain\Access;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Support\Collection;

/** 사이드바용 접근 가능 메뉴 트리. route 없는 그룹 헤더는 항상 노출, route 있으면 can_access 필터. */
class MenuService
{
    public static function sidebar(?User $user, string $area): Collection
    {
        if (! $user) {
            return collect();
        }

        return Menu::where('area', $area)
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (Menu $m) => ! $m->route || MenuAccess::allows($user, $m->route, 'access'))
            ->values();
    }
}
