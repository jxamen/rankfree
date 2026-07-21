<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menus') || DB::table('menus')->where('area', 'console')->where('route', 'console.place-store')->exists()) {
            return;
        }

        $now = now();
        $compete = DB::table('menus')->where('area', 'console')->where('route', 'console.compete')->first();
        $parentId = $compete?->parent_id;
        $sortOrder = $compete ? ((int) $compete->sort_order + 1) : (((int) DB::table('menus')->where('area', 'console')->max('sort_order')) + 1);

        $shift = DB::table('menus')->where('area', 'console')->where('sort_order', '>=', $sortOrder);
        $parentId === null ? $shift->whereNull('parent_id') : $shift->where('parent_id', $parentId);
        $shift->increment('sort_order');

        $row = [
            'parent_id' => $parentId,
            'area' => 'console',
            'is_group' => false,
            'name' => '플레이스 개별 분석',
            'route' => 'console.place-store',
            'url' => null,
            'target' => '',
            'icon' => 'fa-solid fa-chart-line',
            'sort_order' => $sortOrder,
            'is_active' => true,
            'meta_title' => '플레이스 개별 분석',
            'meta_description' => '매장 1곳을 키워드 기준으로 분석해 순위, N1 유사도, N2 관련성, N3 랭킹 점수와 리뷰 신호를 확인합니다.',
            'meta_keywords' => '플레이스 개별 분석, 매장 진단, 플레이스 SEO, 네이버 플레이스 분석',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $columns = array_flip(Schema::getColumnListing('menus'));
        $menuId = DB::table('menus')->insertGetId(array_intersect_key($row, $columns));

        foreach (DB::table('member_grades')->pluck('id') as $gradeId) {
            DB::table('menu_permissions')->updateOrInsert(
                ['menu_id' => $menuId, 'subject_type' => 'grade', 'subject_id' => $gradeId],
                ['can_access' => true, 'can_create' => true, 'can_update' => true, 'can_delete' => true, 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')->where('area', 'console')->where('route', 'console.place-store')->delete();
    }
};
