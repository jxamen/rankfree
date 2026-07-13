<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\MemberGrade;
use App\Models\OperatorRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 요금제 기능별 횟수 제한 — 설정·소비·초과 차단. */
class FeatureLimitTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = OperatorRole::create(['name' => '슈퍼', 'slug' => 'super', 'level' => 100, 'is_super' => true]);

        return User::create(['name' => '관리자', 'email' => 'a@rf.kr', 'password' => 'x1234567', 'role' => 'super', 'operator_role_id' => $role->id]);
    }

    private function marketPayload(): array
    {
        return [
            'keyword' => '고구마', 'total_count' => 100, 'item_count' => 10, 'include_ads' => false,
            'sales_6m' => 1, 'revenue_6m' => 1, 'avg_price' => 1, 'median_price' => 1, 'top10_share' => 1,
            'snapshot' => ['top_products' => []],
        ];
    }

    public function test_plan_stores_feature_limits(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/subscriptions/plans', [
            'name' => '프로', 'tier' => 1, 'monthly_price' => 29000, 'rank_slot_limit' => -1,
            'feature_limits' => ['market_analysis' => 5, 'product_analysis' => 0, 'keyword_analysis' => 100],
        ])->assertRedirect();

        $plan = MemberGrade::where('name', '프로')->first();
        $this->assertSame(5, $plan->featureLimit('market_analysis'));
        $this->assertSame(0, $plan->featureLimit('product_analysis'));
        $this->assertSame(-1, $plan->featureLimit('compete_analysis')); // 미입력 → 무제한
    }

    public function test_unlimited_by_default(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
        $this->assertSame(-1, $user->featureLimit('market_analysis'));
        $this->assertTrue($user->tryConsumeFeature('market_analysis'));
        $this->assertSame(0, $user->featureUsages()->count()); // 무제한은 기록 안 함
    }

    public function test_consume_and_block_over_limit(): void
    {
        $grade = MemberGrade::create([
            'name' => '무료', 'slug' => 'free', 'is_paid' => false, 'tier' => 0, 'rank_slot_limit' => 100,
            'feature_limits' => ['market_analysis' => 2],
        ]);
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567', 'grade_id' => $grade->id]);

        $this->assertTrue($user->tryConsumeFeature('market_analysis'));
        $this->assertTrue($user->tryConsumeFeature('market_analysis'));
        $this->assertFalse($user->tryConsumeFeature('market_analysis')); // 3번째 차단
        $this->assertSame(2, $user->featureUsed('market_analysis'));
        $this->assertSame(0, $user->featureRemaining('market_analysis'));
    }

    public function test_zero_limit_means_disabled(): void
    {
        $grade = MemberGrade::create([
            'name' => '무료', 'slug' => 'free', 'is_paid' => false, 'tier' => 0, 'rank_slot_limit' => 100,
            'feature_limits' => ['product_analysis' => 0],
        ]);
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567', 'grade_id' => $grade->id]);

        $this->assertFalse($user->tryConsumeFeature('product_analysis'));
    }

    public function test_market_analysis_endpoint_enforces_limit(): void
    {
        $grade = MemberGrade::create([
            'name' => '무료', 'slug' => 'free', 'is_paid' => false, 'tier' => 0, 'rank_slot_limit' => 100,
            'feature_limits' => ['market_analysis' => 1],
        ]);
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567', 'grade_id' => $grade->id]);
        [, $token] = ExtToken::issue($user);
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/ext/market-analyses', $this->marketPayload(), $headers)->assertOk();
        $this->postJson('/api/ext/market-analyses', $this->marketPayload(), $headers)
            ->assertStatus(429)->assertJsonPath('limit_exceeded', true);

        $this->assertDatabaseCount('market_analyses', 1);
    }
}
