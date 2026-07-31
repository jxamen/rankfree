<?php

namespace Tests\Feature;

use App\Models\RewardMedia;
use App\Models\RewardUser;
use Database\Seeders\FarmCropSeeder;
use Database\Seeders\RewardMediaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 완료 판정 — 쿠키 없이 x-user-key 헤더만으로 신원이 유지되고,
 * /config 가 매체별 설정(reward_media.settings 오버라이드)을 반영한다.
 */
class FarmApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RewardMediaSeeder::class, FarmCropSeeder::class]);
    }

    public function test_user_key_없으면_401(): void
    {
        $this->getJson('/api/farm/me/state')->assertStatus(401);
    }

    public function test_state_는_사용자_행을_만들고_같은_키면_같은_사용자다(): void
    {
        $key = 'anon-key-123';

        $this->getJson('/api/farm/me/state', ['x-user-key' => $key])
            ->assertOk()
            ->assertJson([
                'plots' => [],
                'todayMissionIds' => [],
                'nextMissionAt' => null,
                'cooldownNotify' => false,
                'earnedPoints' => 0,
                'harvested' => [],
            ]);

        $this->assertDatabaseHas('reward_users', ['user_key_hash' => hash('sha256', $key)]);

        $this->getJson('/api/farm/me/state', ['x-user-key' => $key])->assertOk();
        $this->assertSame(1, RewardUser::query()->count());   // 재요청해도 행이 늘지 않는다

        // 평문 키는 저장하지 않는다 — 암호화 컬럼에서만 복원 가능
        $user = RewardUser::query()->first();
        $this->assertSame($key, $user->anon_key_enc);
        $this->assertNotSame($key, $user->getRawOriginal('anon_key_enc'));
    }

    public function test_config_는_기본값과_작물_포인트를_내려준다(): void
    {
        $this->getJson('/api/farm/config', ['x-user-key' => 'k'])
            ->assertOk()
            ->assertJson([
                'cooldownMinutes' => 120,
                'dailyMissionLimit' => 3,
                'defaultPoints' => 50,
                'maxPointPerUser' => 5000,
            ])
            ->assertJsonPath('pointsByCrop.lettuce', 50)
            ->assertJsonPath('pointsByCrop.corn', 200);
    }

    public function test_매체_settings_가_기본값을_오버라이드한다(): void
    {
        RewardMedia::query()->where('slug', 'quiz-farm')
            ->update(['settings' => json_encode(['cooldown_minutes' => 90, 'daily_mission_limit' => 5])]);

        $this->getJson('/api/farm/config', ['x-user-key' => 'k'])
            ->assertOk()
            ->assertJson(['cooldownMinutes' => 90, 'dailyMissionLimit' => 5, 'maxPointPerUser' => 5000]);
    }

    public function test_쿨다운_알림_토글이_저장된다(): void
    {
        $this->postJson('/api/farm/me/notifications/cooldown', ['enabled' => true], ['x-user-key' => 'k'])
            ->assertOk()
            ->assertJson(['cooldownNotify' => true]);

        $this->assertTrue((bool) RewardUser::query()->first()->cooldown_notify);

        $this->getJson('/api/farm/me/state', ['x-user-key' => 'k'])
            ->assertOk()
            ->assertJson(['cooldownNotify' => true]);
    }

    public function test_비활성_매체는_503(): void
    {
        RewardMedia::query()->where('slug', 'quiz-farm')->update(['is_active' => false]);

        $this->getJson('/api/farm/me/state', ['x-user-key' => 'k'])->assertStatus(503);
    }
}
