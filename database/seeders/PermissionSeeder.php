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

        // ── 운영자 레벨 (슈퍼 + 직원 2단) ──
        foreach ([
            ['name' => '슈퍼관리자', 'slug' => 'super', 'level' => 100, 'is_super' => true, 'description' => '전권', 'sort_order' => 0],
            ['name' => '직원', 'slug' => 'operator', 'level' => 10, 'is_super' => false, 'description' => '운영 지원', 'sort_order' => 1],
        ] as $r) {
            OperatorRole::updateOrCreate(['slug' => $r['slug']], $r);
        }
        // 구 '관리자' 역할 제거 — 소속 회원은 '직원'으로 이관
        if ($adminRole = OperatorRole::where('slug', 'admin')->first()) {
            $staffId = OperatorRole::where('slug', 'operator')->value('id');
            \App\Models\User::where('operator_role_id', $adminRole->id)->update(['operator_role_id' => $staffId]);
            $adminRole->delete();
        }

        // ── 메뉴 (신규 설치 시에만; 기존 DB는 parent/정렬 보존 위해 icon/이름 갱신 안 함) ──
        $console = [
            ['name' => '대시보드', 'route' => 'console.dashboard', 'icon' => '🏠'],
            ['name' => '순위 추적', 'route' => 'console.rank', 'icon' => '📈'],
            ['name' => '쇼핑 순위추적', 'route' => 'console.shop-rank', 'icon' => '🛒'],
            ['name' => '경쟁 분석', 'route' => 'console.compete', 'icon' => '📊'],
            ['name' => '스마트플레이스', 'route' => 'console.smartplace', 'icon' => '🏪'],
            ['name' => '키워드 분석', 'route' => 'console.keyword', 'icon' => '🔍'],
            ['name' => '키워드 추천', 'route' => 'console.keyword-recommend', 'icon' => '💡'],
            ['name' => '키워드 대량 분석', 'route' => 'console.bulk', 'icon' => '📚'],
            ['name' => '블로그 지수 분석', 'route' => 'console.blog', 'icon' => '✍️'],
            ['name' => '블로그 1개 분석', 'route' => 'console.blog-single', 'icon' => '📝'],
        ];
        $admin = [
            ['name' => '마케팅 상품', 'route' => 'admin.products', 'icon' => '🧩'],
            ['name' => '주문 관리', 'route' => 'admin.orders', 'icon' => '🧾'],
            ['name' => '회원 관리', 'route' => 'admin.members', 'icon' => '👥'],
            ['name' => '구독 관리', 'route' => 'admin.subscriptions', 'icon' => '💳'],
            ['name' => '페르소나 관리', 'route' => 'admin.personas', 'icon' => '🎭'],
            ['name' => '환경 설정', 'route' => 'admin.settings', 'icon' => '⚙️'],
            ['name' => '메뉴 관리', 'route' => 'admin.menus', 'icon' => '📋'],
            ['name' => '권한 설정', 'route' => 'admin.permissions', 'icon' => '🛡️'],
        ];

        $consoleIds = [];
        $order = 0;
        foreach ($console as $m) {
            $menu = Menu::firstOrNew(['route' => $m['route']]);
            if (! $menu->exists) {
                $menu->fill(['area' => 'console', 'name' => $m['name'], 'icon' => $m['icon'], 'sort_order' => $order, 'is_active' => true, 'parent_id' => null])->save();
            }
            $consoleIds[] = $menu->id;
            $order++;
        }
        $adminIds = [];
        $order = 0;
        foreach ($admin as $m) {
            $menu = Menu::firstOrNew(['route' => $m['route']]);
            if (! $menu->exists) {
                $menu->fill(['area' => 'admin', 'name' => $m['name'], 'icon' => $m['icon'], 'sort_order' => $order, 'is_active' => true, 'parent_id' => null])->save();
            }
            $adminIds[] = $menu->id;
            $order++;
        }

        // ── 기본 권한 매트릭스 ──
        $gradeIds = MemberGrade::pluck('id');
        foreach ($consoleIds as $mid) {
            foreach ($gradeIds as $gid) {
                MenuPermission::firstOrCreate(
                    ['menu_id' => $mid, 'subject_type' => 'grade', 'subject_id' => $gid],
                    ['can_access' => true, 'can_create' => true, 'can_update' => true, 'can_delete' => true],
                );
            }
        }
        // 직원(operator) 역할은 관리 메뉴 접근 허용 (슈퍼는 항상 전권이라 별도 설정 불필요)
        $operatorRoleId = OperatorRole::where('slug', 'operator')->value('id');
        foreach ($adminIds as $mid) {
            MenuPermission::firstOrCreate(['menu_id' => $mid, 'subject_type' => 'role', 'subject_id' => $operatorRoleId], ['can_access' => true]);
        }
    }
}
