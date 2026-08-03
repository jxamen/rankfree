<?php

namespace Tests\Feature;

use App\Models\RewardMedia;
use App\Models\RewardMission;
use Database\Seeders\FarmCropSeeder;
use Database\Seeders\RewardMediaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 단건 할당(design-04 §3-0) — "미션 참여하기" 한 번에 미션 하나.
 * 송출 규칙(§7): 슬롯 잔여 있는 미션만 · 사용자 상한 제외 · 소진율 낮은 미션 우선 · 사용자별 결정적 셔플.
 */
class RewardAssignTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-31';

    private RewardMedia $media;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::DAY.' 10:00', 'Asia/Seoul'));   // S2
        $this->seed([RewardMediaSeeder::class, FarmCropSeeder::class]);
        $this->media = RewardMedia::query()->where('slug', 'quiz-farm')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeMission(int $itemId, int $used = 0, int $quota = 50): RewardMission
    {
        $m = RewardMission::query()->create([
            'order_item_id' => $itemId, 'order_id' => 940, 'status' => 'active',
            'starts_on' => self::DAY, 'ends_on' => Carbon::parse(self::DAY)->addDays(6)->toDateString(),
            'daily_quota' => $quota, 'total_quota' => $quota * 7, 'unit_revenue' => 375, 'payout_point' => 10,
            'per_user_limit' => 1, 'per_user_daily_limit' => 1,
            'title' => '미션 '.$itemId, 'description' => '설명', 'tags' => ['tag-a', 'tag-b', 'tag-c'],
            // 상품을 특정할 수 없는 미션은 노출되지 않는다
            'shop_name' => '테스트몰', 'product_title' => '상품 '.$itemId, 'product_price' => 10000, 'keyword' => '키워드'.$itemId,
            'landing_url' => 'https://s.example/'.$itemId,
        ]);
        DB::table('reward_mission_daily_counters')->insert([
            'mission_id' => $m->id, 'stat_date' => self::DAY, 'daily_quota' => $quota, 'used' => $used,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $m;
    }

    public function test_한_건만_내려주고_정답_계열은_싣지_않는다(): void
    {
        $mission = $this->makeMission(940001);

        $res = $this->postJson('/api/farm/missions/assign', [], ['x-user-key' => 'u1'])->assertOk();

        $res->assertJsonPath('mission.id', (string) $mission->id)
            ->assertJsonPath('mission.quiz.tagCount', 3)
            ->assertJsonPath('meta.dailyLimit', 3);
        $this->assertIsInt($res->json('mission.quiz.tagIndex'));

        // 태그 원문·정답은 어떤 경로로도 나가지 않는다
        foreach (['tag-a', 'tag-b', 'tag-c'] as $tag) {
            $this->assertStringNotContainsString($tag, $res->getContent());
        }
        $this->assertArrayNotHasKey('missions', $res->json());   // 목록이 아니라 단건
    }

    public function test_소진율이_낮은_미션을_우선_할당한다(): void
    {
        $this->makeMission(940001, used: 20);   // 40% 소진
        $fresh = $this->makeMission(940002, used: 2);    // 4% 소진 — 이쪽이 뽑혀야 한다
        $this->artisan('reward:build-snapshot')->assertSuccessful();

        $this->postJson('/api/farm/missions/assign', [], ['x-user-key' => 'u1'])
            ->assertOk()->assertJsonPath('mission.id', (string) $fresh->id);
    }

    public function test_슬롯_상한을_채운_미션은_할당되지_않는다(): void
    {
        // S2 누적 상한 = floor(50 × 21 / 100) = 10 → used 10 이면 이 슬롯에서는 더 못 준다
        $this->makeMission(940001, used: 10);
        $this->artisan('reward:build-snapshot')->assertSuccessful();

        $this->postJson('/api/farm/missions/assign', [], ['x-user-key' => 'u1'])
            ->assertOk()
            ->assertJsonPath('mission', null)
            ->assertJsonPath('meta.reason', 'no_mission');
    }

    public function test_이미_참여한_미션은_다시_할당되지_않는다(): void
    {
        $done = $this->makeMission(940001);
        $other = $this->makeMission(940002);
        $this->artisan('reward:build-snapshot')->assertSuccessful();

        // 첫 할당을 받은 미션을 참여 완료 처리
        $this->postJson('/api/farm/missions/assign', [], ['x-user-key' => 'u1'])->assertOk();
        $userId = (int) DB::table('reward_users')->value('id');
        DB::table('reward_user_mission_counters')->insert([
            'reward_user_id' => $userId, 'mission_id' => $done->id,
            'done_count' => 1, 'today_count' => 1, 'last_done_on' => self::DAY, 'created_at' => now(),
        ]);

        $this->postJson('/api/farm/missions/assign', [], ['x-user-key' => 'u1'])
            ->assertOk()->assertJsonPath('mission.id', (string) $other->id);
    }

    public function test_쿨다운_중에는_사유와_해제_시각을_준다(): void
    {
        $this->makeMission(940001);
        $this->postJson('/api/farm/missions/assign', [], ['x-user-key' => 'cool'])->assertOk();
        DB::table('reward_users')->update(['cooldown_until' => now()->addHour()]);

        $res = $this->postJson('/api/farm/missions/assign', [], ['x-user-key' => 'cool'])->assertOk();
        $res->assertJsonPath('mission', null)->assertJsonPath('meta.reason', 'cooldown');
        $this->assertNotEmpty($res->json('meta.unlockAt'));
    }

    public function test_소프트_차단_사용자는_사유_없이_없음만_받는다(): void
    {
        $this->makeMission(940001);
        $this->postJson('/api/farm/missions/assign', [], ['x-user-key' => 'blocked-u'])->assertOk();
        DB::table('reward_users')->update(['status' => 'blocked', 'blocked_reason' => 'risk']);

        $this->postJson('/api/farm/missions/assign', [], ['x-user-key' => 'blocked-u'])
            ->assertOk()
            ->assertJsonPath('mission', null)
            ->assertJsonPath('meta.reason', 'no_mission');   // 차단 사실을 알려주지 않는다(§8)
    }

    public function test_심야에는_닫힘을_알린다(): void
    {
        $this->makeMission(940001);
        Carbon::setTestNow(Carbon::parse('2026-08-01 03:00', 'Asia/Seoul'));

        $this->postJson('/api/farm/missions/assign', [], ['x-user-key' => 'night'])
            ->assertOk()
            ->assertJsonPath('mission', null)
            ->assertJsonPath('meta.closed', true)
            ->assertJsonPath('meta.reason', 'closed');
    }

    public function test_할당된_미션에_바로_제출할_수_있다(): void
    {
        $mission = $this->makeMission(940001);
        $this->artisan('reward:build-snapshot')->assertSuccessful();

        $res = $this->postJson('/api/farm/missions/assign', [], ['x-user-key' => 'flow'])->assertOk();
        $tagIndex = (int) $res->json('mission.quiz.tagIndex');
        $this->postJson('/api/farm/plots/0/plant', ['cropId' => 'lettuce'], ['x-user-key' => 'flow'])->assertOk();

        $this->postJson('/api/farm/missions/'.$mission->id.'/submit',
            ['answer' => ['tag-a', 'tag-b', 'tag-c'][$tagIndex - 1]], ['x-user-key' => 'flow'])
            ->assertOk()->assertJsonPath('correct', true);

        $this->assertDatabaseHas('reward_participation_logs',
            ['mission_id' => $mission->id, 'result' => 'correct', 'seq_in_day' => 1]);
    }
}
