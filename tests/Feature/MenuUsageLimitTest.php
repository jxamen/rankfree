<?php

namespace Tests\Feature;

use App\Models\MemberGrade;
use App\Models\Menu;
use App\Models\MenuPermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 메뉴 × 등급별 월간 이용 횟수 제한 (usage.gate). */
class MenuUsageLimitTest extends TestCase
{
    use RefreshDatabase;

    private function grade(): MemberGrade
    {
        return MemberGrade::create(['name' => '무료', 'slug' => 'free', 'is_paid' => false, 'tier' => 0, 'rank_slot_limit' => 100]);
    }

    private function user(MemberGrade $g): User
    {
        return User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567', 'grade_id' => $g->id]);
    }

    /** 시장 분석 메뉴(console.market)에 등급 월 2회 한도 설정 */
    private function limitMarket(MemberGrade $g, int $limit): void
    {
        $menu = Menu::create(['area' => 'console', 'is_group' => false, 'name' => '시장 분석', 'route' => 'console.market']);
        MenuPermission::create([
            'menu_id' => $menu->id, 'subject_type' => 'grade', 'subject_id' => $g->id,
            'can_access' => true, 'monthly_limit' => $limit,
        ]);
    }

    public function test_no_limit_by_default(): void
    {
        $g = $this->grade();
        $user = $this->user($g);
        // 메뉴/권한행 없음 → 무제한
        $this->actingAs($user)->get('/console/market')->assertOk();
        $this->actingAs($user)->get('/console/market')->assertOk();
        $this->assertSame(0, $user->featureUsages()->count());
    }

    public function test_menu_usage_blocked_over_limit(): void
    {
        $g = $this->grade();
        $this->limitMarket($g, 2);
        $user = $this->user($g);

        $this->actingAs($user)->get('/console/market')->assertOk();
        $this->actingAs($user)->get('/console/market')->assertOk();
        // 3번째 → 대시보드로 리다이렉트(차단)
        $this->actingAs($user)->get('/console/market')
            ->assertRedirect(route('console.dashboard'))
            ->assertSessionHas('status');
    }

    public function test_zero_limit_disables_menu(): void
    {
        $g = $this->grade();
        $this->limitMarket($g, 0);
        $user = $this->user($g);

        $this->actingAs($user)->get('/console/market')->assertRedirect(route('console.dashboard'));
    }

    public function test_super_admin_bypasses_limit(): void
    {
        $g = $this->grade();
        $this->limitMarket($g, 1);
        $super = User::create(['name' => 's', 'email' => 's@rf.kr', 'password' => 'x1234567', 'role' => 'super', 'grade_id' => $g->id]);
        config(['rankfree.super_admins' => ['s@rf.kr']]);

        $this->actingAs($super)->get('/console/market')->assertOk();
        $this->actingAs($super)->get('/console/market')->assertOk();
    }

    public function test_savePermissions_stores_limit(): void
    {
        $g = $this->grade();
        $menu = Menu::create(['area' => 'console', 'is_group' => false, 'name' => '시장 분석', 'route' => 'console.market']);
        $admin = User::create(['name' => 'a', 'email' => 'a@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
        config(['rankfree.super_admins' => ['a@rf.kr']]);

        $this->actingAs($admin)->post("/admin/menus/{$menu->id}/permissions", [
            'perm' => ['grade:'.$g->id => ['access' => '1', 'limit' => '5']],
        ])->assertRedirect();

        $this->assertDatabaseHas('menu_permissions', [
            'menu_id' => $menu->id, 'subject_type' => 'grade', 'subject_id' => $g->id, 'monthly_limit' => 5,
        ]);
    }
}
