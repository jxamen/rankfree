<?php

namespace App\Domain\Access;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Support\Collection;

/** 사이드바 렌더용 접근 가능 메뉴. */
class MenuService
{
    /** (레거시) 최상위 접근 가능 메뉴 flat 목록. */
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
            ->filter(fn (Menu $m) => self::canAccess($user, $m))
            ->values();
    }

    /**
     * 사이드바 트리 — 접근 가능한 대분류(하위 항목을 menuItems 관계에 담아) + 최상위 항목.
     * 대분류는 하위에 노출 가능한 항목이 하나라도 있어야 표시된다(crm 규칙).
     */
    public static function sidebarTree(?User $user, string $area): Collection
    {
        if (! $user) {
            return collect();
        }

        $all = Menu::where('area', $area)->where('is_active', true)->orderBy('sort_order')->get();
        $byParent = $all->groupBy('parent_id');
        $result = collect();

        foreach ($all->whereNull('parent_id') as $root) {
            if ($root->is_group) {
                $items = self::descendantItems($root->id, $byParent)
                    ->filter(fn (Menu $m) => self::canAccess($user, $m))
                    ->values();
                if ($items->isNotEmpty()) {
                    $root->setRelation('menuItems', $items);
                    $result->push($root);
                }
            } elseif (self::canAccess($user, $root)) {
                $result->push($root);
            }
        }

        return $result;
    }

    private static function canAccess(User $user, Menu $m): bool
    {
        return ! $m->route || MenuAccess::allows($user, $m->route, 'access');
    }

    /** 그룹 하위의 모든 페이지 항목(중분류 관통, sort 순). */
    private static function descendantItems(int $groupId, Collection $byParent): Collection
    {
        $out = collect();
        foreach (($byParent[$groupId] ?? collect()) as $child) {
            if ($child->is_group) {
                $out = $out->merge(self::descendantItems($child->id, $byParent));
            } else {
                $out->push($child);
            }
        }

        return $out;
    }
}
