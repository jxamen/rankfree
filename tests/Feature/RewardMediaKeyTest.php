<?php

namespace Tests\Feature;

use App\Models\OperatorRole;
use App\Models\RewardMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 제휴 매체 전용 키(design-04 §2) — 고객용 회원 키와 분리한 인증 체계.
 * "매체를 등록했는데 호출 수단이 없다"가 실제로 났던 문제라, **등록 = 키 발급**을 테스트로 고정한다.
 */
class RewardMediaKeyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => '관리자', 'email' => 'media-key-admin@rankfree.kr', 'password' => 'secret1234', 'role' => 'super',
            'operator_role_id' => OperatorRole::create([
                'name' => '슈퍼관리자', 'slug' => 'super', 'level' => 100, 'is_super' => true,
            ])->id,
        ]);
    }

    public function test_매체를_등록하면_전용_키가_함께_발급된다(): void
    {
        $this->actingAs($this->admin())->post('/admin/reward/media', [
            'name' => '꼬꼬농장', 'slug' => 'koko-farm', 'type' => RewardMedia::TYPE_VENDOR_API,
            'payout_unit_price' => 10, 'rate_limit_rps' => 100, 'verify_mode' => 'server', 'is_active' => '1',
        ])->assertRedirect();

        $media = RewardMedia::query()->where('slug', 'koko-farm')->sole();
        $key = $media->plainKey();

        $this->assertNotNull($key);
        $this->assertStringStartsWith('rkm_', $key);
        $this->assertSame(substr($key, 0, 12), $media->api_key_prefix);
        $this->assertSame(hash('sha256', $key), $media->api_key_hash);

        // 발급된 키로 바로 호출된다
        $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/v1/missions')->assertOk();
    }

    public function test_재발급하면_이전_키는_무효가_된다(): void
    {
        $media = RewardMedia::query()->create([
            'slug' => 'ow-key', 'name' => '오퍼월키', 'type' => RewardMedia::TYPE_VENDOR_API,
            'verify_mode' => 'server', 'is_active' => true,
        ]);
        $old = $media->issueKey();

        $this->actingAs($this->admin())
            ->post("/admin/reward/media/{$media->id}/regenerate-key")->assertRedirect();

        $new = $media->fresh()->plainKey();
        $this->assertNotSame($old, $new);

        $this->withHeader('Authorization', 'Bearer '.$old)->getJson('/api/v1/missions')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer '.$new)->getJson('/api/v1/missions')->assertOk();
    }

    /** 키가 없는 매체(분리 이전에 만들어진 행)는 호출을 통과시키지 않는다 */
    public function test_키가_없는_매체로는_인증되지_않는다(): void
    {
        RewardMedia::query()->create([
            'slug' => 'no-key', 'name' => '키없음', 'type' => RewardMedia::TYPE_VENDOR_API,
            'verify_mode' => 'server', 'is_active' => true,
        ]);

        $this->getJson('/api/v1/missions')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer ')->getJson('/api/v1/missions')->assertStatus(401);
    }
}
