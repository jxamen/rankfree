<?php

namespace Tests\Feature;

use App\Models\MemberGrade;
use App\Models\OperatorRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 관리자 회원 관리·구독 관리 화면·액션 검증. */
class AdminMemberSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function superRole(): OperatorRole
    {
        return OperatorRole::create(['name' => '슈퍼관리자', 'slug' => 'super', 'level' => 100, 'is_super' => true]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => '관리자', 'email' => 'admin@rankfree.kr', 'password' => 'secret1234',
            'role' => 'super', 'operator_role_id' => $this->superRole()->id,
        ]);
    }

    private function freeGrade(): MemberGrade
    {
        return MemberGrade::create(['name' => '무료', 'slug' => 'free', 'is_paid' => false, 'tier' => 0, 'rank_slot_limit' => 100]);
    }

    private function proGrade(): MemberGrade
    {
        return MemberGrade::create(['name' => '프로', 'slug' => 'pro', 'is_paid' => true, 'tier' => 1, 'monthly_price' => 29000, 'rank_slot_limit' => -1]);
    }

    public function test_members_page_renders(): void
    {
        $admin = $this->admin();
        User::create(['name' => '홍길동', 'email' => 'hong@test.kr', 'password' => 'x1234567', 'grade_id' => $this->freeGrade()->id]);

        $this->actingAs($admin)->get('/admin/members')
            ->assertOk()->assertSee('회원 관리')->assertSee('홍길동');
    }

    public function test_member_search_filters(): void
    {
        $admin = $this->admin();
        User::create(['name' => '가나다', 'email' => 'a@test.kr', 'password' => 'x1234567']);
        User::create(['name' => '라마바', 'email' => 'b@test.kr', 'password' => 'x1234567']);

        $this->actingAs($admin)->get('/admin/members?q=가나다')
            ->assertOk()->assertSee('가나다')->assertDontSee('라마바');
    }

    public function test_update_member_grade_and_subscription(): void
    {
        $admin = $this->admin();
        $pro = $this->proGrade();
        $member = User::create(['name' => '구매자', 'email' => 'buyer@test.kr', 'password' => 'x1234567']);

        $this->actingAs($admin)->put("/admin/members/{$member->id}", [
            'name' => '구매자', 'phone' => '01012345678',
            'grade_id' => $pro->id,
            'subscription_expires_at' => now()->addMonth()->toDateString(),
        ])->assertRedirect();

        $member->refresh();
        $this->assertSame($pro->id, $member->grade_id);
        $this->assertSame('01012345678', $member->phone);
        $this->assertTrue($member->subscriptionActive());
    }

    public function test_super_can_grant_operator_role(): void
    {
        $admin = $this->admin();
        $role = OperatorRole::create(['name' => '운영자', 'slug' => 'op', 'level' => 10]);
        $member = User::create(['name' => '직원', 'email' => 'staff@test.kr', 'password' => 'x1234567']);

        $this->actingAs($admin)->put("/admin/members/{$member->id}", ['name' => '직원', 'operator_role_id' => $role->id])->assertRedirect();

        $member->refresh();
        $this->assertSame($role->id, $member->operator_role_id);
        $this->assertSame('operator', $member->role);
        $this->assertTrue($member->isOperator());
    }

    public function test_subscriptions_page_renders(): void
    {
        $admin = $this->admin();
        $this->proGrade();

        $this->actingAs($admin)->get('/admin/subscriptions')
            ->assertOk()->assertSee('구독 관리')->assertSee('프로');
    }

    public function test_plan_crud(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/subscriptions/plans', [
            'name' => '엔터프라이즈', 'tier' => 3, 'monthly_price' => 99000, 'rank_slot_limit' => -1, 'is_paid' => '1', 'is_active' => '1',
        ])->assertRedirect();
        $this->assertDatabaseHas('member_grades', ['name' => '엔터프라이즈', 'is_paid' => true]);

        $plan = MemberGrade::where('name', '엔터프라이즈')->first();
        $this->actingAs($admin)->put("/admin/subscriptions/plans/{$plan->id}", [
            'name' => '엔터프라이즈+', 'tier' => 3, 'monthly_price' => 120000, 'rank_slot_limit' => -1,
        ])->assertRedirect();
        $this->assertDatabaseHas('member_grades', ['id' => $plan->id, 'name' => '엔터프라이즈+']);

        $this->actingAs($admin)->delete("/admin/subscriptions/plans/{$plan->id}")->assertRedirect();
        $this->assertDatabaseMissing('member_grades', ['id' => $plan->id]);
    }

    public function test_plan_delete_blocked_when_in_use(): void
    {
        $admin = $this->admin();
        $pro = $this->proGrade();
        User::create(['name' => '사용중', 'email' => 'using@test.kr', 'password' => 'x1234567', 'grade_id' => $pro->id]);

        $this->actingAs($admin)->delete("/admin/subscriptions/plans/{$pro->id}")->assertSessionHasErrors('plan');
        $this->assertDatabaseHas('member_grades', ['id' => $pro->id]);
    }

    public function test_extend_and_cancel_subscription(): void
    {
        $admin = $this->admin();
        $free = $this->freeGrade();
        $pro = $this->proGrade();
        $sub = User::create(['name' => '구독자', 'email' => 'sub@test.kr', 'password' => 'x1234567', 'grade_id' => $pro->id]);

        $this->actingAs($admin)->post("/admin/subscriptions/{$sub->id}/extend", ['months' => 3])->assertRedirect();
        $sub->refresh();
        $this->assertNotNull($sub->subscription_expires_at);
        $this->assertTrue($sub->subscription_expires_at->isFuture());

        $this->actingAs($admin)->post("/admin/subscriptions/{$sub->id}/cancel")->assertRedirect();
        $sub->refresh();
        $this->assertSame($free->id, $sub->grade_id);
        $this->assertNull($sub->subscription_expires_at);
    }

    public function test_members_requires_operator(): void
    {
        $member = User::create(['name' => '일반', 'email' => 'plain@test.kr', 'password' => 'x1234567']);
        $this->actingAs($member)->get('/admin/members')->assertForbidden();
    }
}
