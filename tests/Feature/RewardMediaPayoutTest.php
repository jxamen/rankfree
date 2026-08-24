<?php

namespace Tests\Feature;

use App\Models\OperatorRole;
use App\Models\RewardMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 제휴 매체 × 미션 유형별 지급 단가(design-04 §2-1) — 지출 계산의 입력.
 * 유형별 행이 있으면 그 단가, 없으면 매체 기본 단가로 폴백하는지 + 어드민 저장·삭제를 본다.
 */
class RewardMediaPayoutTest extends TestCase
{
    use RefreshDatabase;

    private RewardMedia $media;

    protected function setUp(): void
    {
        parent::setUp();
        $this->media = RewardMedia::query()->create([
            'slug' => 'payout-media', 'name' => '단가매체', 'type' => RewardMedia::TYPE_VENDOR_API,
            'payout_unit_price' => 100, 'rate_limit_rps' => 100, 'verify_mode' => 'server', 'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => '관리자', 'email' => 'payout-admin@rankfree.kr', 'password' => 'secret1234', 'role' => 'super',
            'operator_role_id' => OperatorRole::create([
                'name' => '슈퍼관리자', 'slug' => 'super', 'level' => 100, 'is_super' => true,
            ])->id,
        ]);
    }

    /** 어드민 저장 폼과 같은 페이로드 — 기본 정보는 그대로 두고 유형별 단가만 바꾼다 */
    private function payload(array $payoutRows): array
    {
        return [
            'name' => '단가매체', 'slug' => 'payout-media', 'type' => RewardMedia::TYPE_VENDOR_API,
            'payout_unit_price' => 100, 'rate_limit_rps' => 100, 'verify_mode' => 'server', 'is_active' => '1',
            'payout_submitted' => '1', 'payout' => $payoutRows,
        ];
    }

    public function test_유형별_단가가_있으면_그_값_없으면_기본_단가(): void
    {
        DB::table('reward_media_payouts')->insert([
            'media_id' => $this->media->id, 'kind' => 'attendance', 'unit_price' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(30, $this->media->payoutFor('attendance'));
        $this->assertSame(100, $this->media->payoutFor('external'));   // 유형 행 없음 → 기본 단가
        $this->assertSame(100, $this->media->payoutFor(null));
        $this->assertSame(100, $this->media->payoutFor(''));
    }

    public function test_어드민에서_유형별_단가를_저장한다(): void
    {
        $this->actingAs($this->admin())->put("/admin/reward/media/{$this->media->id}", $this->payload([
            ['kind' => 'attendance', 'unit_price' => '30'],
            ['kind' => 'external', 'unit_price' => '250'],
            ['kind' => '', 'unit_price' => '999'],              // 유형 코드가 없는 행은 무시
            ['kind' => 'internal', 'unit_price' => ''],         // 단가가 빈 행은 무시
        ]))->assertRedirect();

        $this->assertSame(30, $this->media->payoutFor('attendance'));
        $this->assertSame(250, $this->media->payoutFor('external'));
        $this->assertSame(100, $this->media->payoutFor('internal'));
        $this->assertSame(2, DB::table('reward_media_payouts')->where('media_id', $this->media->id)->count());
    }

    public function test_화면에서_지운_유형은_삭제되어_기본_단가로_돌아간다(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put("/admin/reward/media/{$this->media->id}", $this->payload([
            ['kind' => 'attendance', 'unit_price' => '30'],
            ['kind' => 'external', 'unit_price' => '250'],
        ]))->assertRedirect();

        $this->actingAs($admin)->put("/admin/reward/media/{$this->media->id}", $this->payload([
            ['kind' => 'attendance', 'unit_price' => '30'],
        ]))->assertRedirect();

        $this->assertSame(30, $this->media->payoutFor('attendance'));
        $this->assertSame(100, $this->media->payoutFor('external'));
        $this->assertSame(1, DB::table('reward_media_payouts')->where('media_id', $this->media->id)->count());
    }

    /** 유형 행을 전부 비우면 남아 있던 단가가 조용히 계속 적용되면 안 된다 */
    public function test_유형_행을_전부_비우면_모두_삭제된다(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put("/admin/reward/media/{$this->media->id}",
            $this->payload([['kind' => 'attendance', 'unit_price' => '30']]))->assertRedirect();

        $this->actingAs($admin)->put("/admin/reward/media/{$this->media->id}", $this->payload([]))->assertRedirect();

        $this->assertSame(0, DB::table('reward_media_payouts')->where('media_id', $this->media->id)->count());
        $this->assertSame(100, $this->media->payoutFor('attendance'));
    }
}
