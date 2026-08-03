<?php

namespace Tests\Feature;

use App\Domain\Reward\MediaQuota;
use App\Models\RewardMedia;
use App\Models\RewardMission;
use App\Models\RewardUser;
use App\Models\FarmPlanting;
use App\Domain\Reward\MissionSubmitService;
use App\Domain\Reward\TagIndex;
use Database\Seeders\FarmCropSeeder;
use Database\Seeders\RewardMediaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 매체별 배분(design-04 §2-1) — "벤더1에 어떤 미션을 어떤 비율로".
 * 설정만 있고 집행이 안 되면 의미가 없으므로, 상한이 **실제로 참여를 막는지**를 본다.
 */
class RewardMediaAllocationTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-08-01';

    private const TAGS = ['alloc-a', 'alloc-b', 'alloc-c'];

    private RewardMedia $media;

    private RewardMission $mission;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::DAY.' 23:00', 'Asia/Seoul'));   // 마지막 슬롯 = 구간 상한이 일 수량
        $this->seed([RewardMediaSeeder::class, FarmCropSeeder::class]);

        $this->media = RewardMedia::query()->where('slug', 'quiz-farm')->first();
        $this->media->update(['settings' => ['cooldown_minutes' => 0, 'cooldown_jitter_minutes' => 0,
            'daily_mission_limit' => 50, 'ip_daily_limit' => 0]]);
        $this->media->refresh();

        $this->mission = RewardMission::query()->create([
            'order_item_id' => 950001, 'order_id' => 950, 'status' => 'active', 'kind' => 'external',
            'starts_on' => self::DAY, 'ends_on' => Carbon::parse(self::DAY)->addDays(6)->toDateString(),
            'daily_quota' => 100, 'total_quota' => 700, 'unit_revenue' => 300, 'payout_point' => 10,
            'per_user_limit' => 1, 'per_user_daily_limit' => 1,
            'title' => '배분 미션', 'description' => '설명', 'tags' => self::TAGS,
            'shop_name' => '테스트몰', 'product_title' => '테스트 상품', 'product_price' => 10000,
            'landing_url' => 'https://link.example.com/alloc',
        ]);
        DB::table('reward_mission_daily_counters')->insert([
            'mission_id' => $this->mission->id, 'stat_date' => self::DAY, 'daily_quota' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function allocate(array $attrs): void
    {
        DB::table('reward_media_allocations')->insert(array_merge([
            'media_id' => $this->media->id, 'scope' => 'all', 'scope_key' => '',
            'ratio' => null, 'max_per_day' => null, 'min_per_day' => 0,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** 참여 1건 — 매번 새 사용자로(미션 1인 1회 제한) */
    private function participate(int $i): bool
    {
        $user = RewardUser::query()->create([
            'media_id' => $this->media->id, 'user_key_hash' => hash('sha256', 'alloc-u'.$i),
        ]);
        FarmPlanting::query()->create([
            'reward_user_id' => $user->id, 'plot_index' => 0, 'crop_id' => 'lettuce',
            'required_days' => 7, 'reward_points' => 50, 'planted_on' => self::DAY,
        ]);
        $idx = TagIndex::for($user->user_key_hash, $this->mission->id, self::DAY, count(self::TAGS));

        // refresh() 필수 — 새로 만든 모델에는 DB 기본값(status='active')이 없어 blocked 로 판정된다
        return (bool) app(MissionSubmitService::class)->submit(
            $this->media, $user->refresh(), $this->mission->id, self::TAGS[$idx - 1], null)['correct'];
    }

    public function test_규칙이_없으면_제한_없이_참여된다(): void
    {
        $ok = 0;
        foreach (range(1, 5) as $i) {
            $ok += $this->participate($i) ? 1 : 0;
        }

        $this->assertSame(5, $ok);
        $this->assertNull(MediaQuota::capFor($this->mission, MediaQuota::rulesFor($this->media->id)));
    }

    public function test_비율_상한이_참여를_실제로_막는다(): void
    {
        $this->allocate(['ratio' => 3]);   // 일 수량 100 의 3% = 3건

        $ok = 0;
        foreach (range(1, 6) as $i) {
            $ok += $this->participate($i) ? 1 : 0;
        }

        $this->assertSame(3, $ok);
        $this->assertDatabaseHas('reward_mission_media_counters', [
            'mission_id' => $this->mission->id, 'media_id' => $this->media->id, 'used' => 3,
        ]);
        $this->assertDatabaseHas('reward_participation_logs', ['reject_reason' => 'media_cap']);

        // 미션 자체의 일 수량(100)은 아직 남아 있다 — 막은 것은 이 매체의 몫이다
        $this->assertSame(3, (int) DB::table('reward_mission_daily_counters')->value('used'));
    }

    public function test_일_상한과_비율_중_작은_값이_적용된다(): void
    {
        $this->allocate(['ratio' => 50, 'max_per_day' => 2]);   // 50건 vs 2건 → 2건

        $this->assertSame(2, MediaQuota::capFor($this->mission, MediaQuota::rulesFor($this->media->id)));

        $ok = 0;
        foreach (range(1, 4) as $i) {
            $ok += $this->participate($i) ? 1 : 0;
        }
        $this->assertSame(2, $ok);
    }

    public function test_개별_미션_규칙이_전체_규칙을_이긴다(): void
    {
        $this->allocate(['scope' => 'all', 'ratio' => 1]);                                   // 전체 1%
        $this->allocate(['scope' => 'mission', 'scope_key' => (string) $this->mission->id, 'ratio' => 4]);

        $rules = MediaQuota::rulesFor($this->media->id);
        $this->assertSame(4, MediaQuota::capFor($this->mission, $rules));   // 좁은 범위가 우선
    }

    public function test_유형_규칙도_적용된다(): void
    {
        $this->allocate(['scope' => 'kind', 'scope_key' => 'external', 'ratio' => 7]);

        $this->assertSame(7, MediaQuota::capFor($this->mission, MediaQuota::rulesFor($this->media->id)));
    }

    public function test_배분을_다_쓴_미션은_할당되지_않는다(): void
    {
        $this->allocate(['max_per_day' => 1]);
        $this->assertTrue($this->participate(1));

        $this->artisan('reward:build-snapshot')->assertSuccessful();

        // 매체 몫이 끝났으므로 줄 미션이 없다
        $this->postJson('/api/farm/missions/assign', [], ['x-user-key' => 'alloc-next'])
            ->assertOk()->assertJsonPath('mission', null)->assertJsonPath('meta.reason', 'no_mission');
    }
}
