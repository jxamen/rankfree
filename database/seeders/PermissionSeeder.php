<?php

namespace Database\Seeders;

use App\Models\MemberGrade;
use App\Models\Menu;
use App\Models\MenuPermission;
use App\Models\OperatorRole;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // ── 회원 등급 (무료/유료 모델 단계) ──
        foreach ([
            ['name' => '무료', 'slug' => 'free', 'is_paid' => false, 'tier' => 0, 'monthly_price' => 0, 'rank_slot_limit' => 100, 'description' => '순위체크 100개(추천인 최대 200)', 'sort_order' => 0],
            ['name' => '프로', 'slug' => 'pro', 'is_paid' => true, 'tier' => 1, 'monthly_price' => 29000, 'rank_slot_limit' => -1, 'description' => '순위 무제한·자동추적', 'sort_order' => 1],
            ['name' => '대행', 'slug' => 'agency', 'is_paid' => true, 'tier' => 2, 'monthly_price' => null, 'rank_slot_limit' => -1, 'description' => '마케팅 대행', 'sort_order' => 2],
        ] as $g) {
            MemberGrade::updateOrCreate(['slug' => $g['slug']], $g);
        }

        // ── 운영자 레벨 ──
        foreach ([
            ['name' => '슈퍼관리자', 'slug' => 'super', 'level' => 100, 'is_super' => true, 'description' => '전권', 'sort_order' => 0],
            ['name' => '관리자', 'slug' => 'admin', 'level' => 50, 'is_super' => false, 'description' => '운영 관리', 'sort_order' => 1],
            ['name' => '운영자', 'slug' => 'operator', 'level' => 10, 'is_super' => false, 'description' => '제한 운영', 'sort_order' => 2],
        ] as $r) {
            OperatorRole::updateOrCreate(['slug' => $r['slug']], $r);
        }

        // ── 메뉴 트리 ──
        $console = [
            ['name' => '대시보드', 'route' => 'console.dashboard', 'icon' => 'home'],
            ['name' => '순위 추적', 'route' => 'console.rank', 'icon' => 'trending-up'],
            ['name' => '경쟁 분석', 'route' => 'console.compete', 'icon' => 'bar-chart'],
            ['name' => '키워드 분석', 'route' => 'console.keyword', 'icon' => 'search'],
            ['name' => '설정', 'route' => 'console.settings', 'icon' => 'settings'],
        ];
        $admin = [
            ['name' => '회원 관리', 'route' => 'admin.members', 'icon' => 'users'],
            ['name' => '구독 관리', 'route' => 'admin.subscriptions', 'icon' => 'credit-card'],
            ['name' => '메뉴 관리', 'route' => 'admin.menus', 'icon' => 'list'],
            ['name' => '권한 설정', 'route' => 'admin.permissions', 'icon' => 'shield'],
        ];

        $consoleIds = [];
        $order = 0;
        foreach ($console as $m) {
            $consoleIds[] = Menu::updateOrCreate(
                ['route' => $m['route']],
                ['area' => 'console', 'name' => $m['name'], 'icon' => $m['icon'], 'sort_order' => $order++, 'is_active' => true, 'parent_id' => null],
            )->id;
        }
        $adminIds = [];
        $order = 0;
        foreach ($admin as $m) {
            $adminIds[] = Menu::updateOrCreate(
                ['route' => $m['route']],
                ['area' => 'admin', 'name' => $m['name'], 'icon' => $m['icon'], 'sort_order' => $order++, 'is_active' => true, 'parent_id' => null],
            )->id;
        }

        // ── 기본 권한 매트릭스 ──
        // 콘솔: 모든 등급 접근+CRUD(자기 데이터). 관리: admin 전권, operator 접근만. super는 로직상 전권.
        $gradeIds = MemberGrade::pluck('id');
        foreach ($consoleIds as $mid) {
            foreach ($gradeIds as $gid) {
                MenuPermission::updateOrCreate(
                    ['menu_id' => $mid, 'subject_type' => 'grade', 'subject_id' => $gid],
                    ['can_access' => true, 'can_create' => true, 'can_update' => true, 'can_delete' => true],
                );
            }
        }
        $adminRoleId = OperatorRole::where('slug', 'admin')->value('id');
        $operatorRoleId = OperatorRole::where('slug', 'operator')->value('id');
        foreach ($adminIds as $mid) {
            MenuPermission::updateOrCreate(
                ['menu_id' => $mid, 'subject_type' => 'role', 'subject_id' => $adminRoleId],
                ['can_access' => true, 'can_create' => true, 'can_update' => true, 'can_delete' => true],
            );
            MenuPermission::updateOrCreate(
                ['menu_id' => $mid, 'subject_type' => 'role', 'subject_id' => $operatorRoleId],
                ['can_access' => true, 'can_create' => false, 'can_update' => false, 'can_delete' => false],
            );
        }
    }
}
