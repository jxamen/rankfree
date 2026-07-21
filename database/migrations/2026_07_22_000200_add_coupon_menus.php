<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 쿠폰(26) 메뉴 — 관리자 '쿠폰 관리'(주문 관리 다음)만 추가.
 * 콘솔에는 메뉴를 만들지 않는다 — 쿠폰 확인·다운로드는 마이페이지(console.me)에 통합(2026-07-22 "메뉴가 너무 많아").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        $this->insertMenu('admin', 'admin.coupons', '쿠폰 관리', 'fa-solid fa-ticket', 'admin.orders', [
            'meta_title' => '쿠폰 관리',
        ]);
    }

    /** anchor 라우트 메뉴 바로 다음 순서로 삽입(없으면 맨 뒤). 콘솔 메뉴는 전 등급 접근 허용. */
    private function insertMenu(string $area, string $route, string $name, string $icon, string $anchorRoute, array $meta): void
    {
        if (DB::table('menus')->where('area', $area)->where('route', $route)->exists()) {
            return;
        }

        $now = now();
        $anchor = DB::table('menus')->where('area', $area)->where('route', $anchorRoute)->first();
        $parentId = $anchor?->parent_id;
        $sortOrder = $anchor ? ((int) $anchor->sort_order + 1) : (((int) DB::table('menus')->where('area', $area)->max('sort_order')) + 1);

        $shift = DB::table('menus')->where('area', $area)->where('sort_order', '>=', $sortOrder);
        $parentId === null ? $shift->whereNull('parent_id') : $shift->where('parent_id', $parentId);
        $shift->increment('sort_order');

        $row = [
            'parent_id' => $parentId,
            'area' => $area,
            'is_group' => false,
            'name' => $name,
            'route' => $route,
            'url' => null,
            'target' => '',
            'icon' => $icon,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ] + $meta;
        $columns = array_flip(Schema::getColumnListing('menus'));
        $menuId = DB::table('menus')->insertGetId(array_intersect_key($row, $columns));

        if ($area === 'console') {
            foreach (DB::table('member_grades')->pluck('id') as $gradeId) {
                DB::table('menu_permissions')->updateOrInsert(
                    ['menu_id' => $menuId, 'subject_type' => 'grade', 'subject_id' => $gradeId],
                    ['can_access' => true, 'can_create' => true, 'can_update' => true, 'can_delete' => true, 'updated_at' => $now, 'created_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')->where('area', 'admin')->where('route', 'admin.coupons')->delete();
    }
};
