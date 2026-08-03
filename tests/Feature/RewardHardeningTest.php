<?php

namespace Tests\Feature;

use App\Domain\Reward\MissionSnapshot;
use App\Domain\Reward\MissionSubmitService;
use App\Domain\Reward\MissionSync;
use App\Domain\Reward\RewardCache;
use App\Domain\Reward\TagIndex;
use App\Models\ApiKey;
use App\Models\FarmPlanting;
use App\Models\RewardMedia;
use App\Models\RewardMission;
use App\Models\RewardUser;
use App\Models\User;
use Database\Seeders\FarmCropSeeder;
use Database\Seeders\RewardMediaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3~4 다차원 리뷰에서 확정된 결함의 재발 방지(2026-07-31).
 * 각 테스트는 "고치기 전이면 반드시 실패한다"를 기준으로 쓴다.
 */
class RewardHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-31';

    private const TAGS = ['htag-a', 'htag-b', 'htag-c'];

    private RewardMedia $media;

    private RewardMission $mission;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::DAY.' 10:00', 'Asia/Seoul'));   // S2
        $this->seed([RewardMediaSeeder::class, FarmCropSeeder::class]);

        $this->media = RewardMedia::query()->where('slug', 'quiz-farm')->first();
        $this->media->update(['settings' => ['cooldown_jitter_minutes' => 0, 'cooldown_minutes' => 0]]);

        $this->mission = RewardMission::query()->create([
            'order_item_id' => 930001, 'order_id' => 930, 'status' => 'active',
            'starts_on' => self::DAY, 'ends_on' => Carbon::parse(self::DAY)->addDays(6)->toDateString(),
            'daily_quota' => 50, 'total_quota' => 350, 'unit_revenue' => 375, 'payout_point' => 10,
            'per_user_limit' => 7, 'per_user_daily_limit' => 1,
            'title' => '하드닝 미션', 'description' => '설명', 'tags' => self::TAGS,
            // 상품을 특정할 수 없는 미션은 노출되지 않는다(MissionSnapshot 노출 게이트)
            'shop_name' => '하드닝몰', 'product_title' => '하드닝 상품', 'product_price' => 12000, 'keyword' => '하드닝키워드',
            'landing_url' => 'https://s.example/h1',
        ]);
        DB::table('reward_mission_daily_counters')->insert([
            'mission_id' => $this->mission->id, 'stat_date' => self::DAY, 'daily_quota' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $key): RewardUser
    {
        $user = RewardUser::query()->create([
            'media_id' => $this->media->id, 'user_key_hash' => hash('sha256', $key),
        ]);
        FarmPlanting::query()->create([
            'reward_user_id' => $user->id, 'plot_index' => 0, 'crop_id' => 'lettuce',
            'required_days' => 7, 'reward_points' => 50, 'planted_on' => self::DAY,
        ]);

        return $user;
    }

    private function answerFor(RewardUser $user): string
    {
        return self::TAGS[TagIndex::for($user->user_key_hash, $this->mission->id, self::DAY, count(self::TAGS)) - 1];
    }

    private function submit(RewardUser $user, string $ip = '10.0.0.1'): array
    {
        return app(MissionSubmitService::class)->submit(
            $this->media, $user->refresh(), $this->mission->id, $this->answerFor($user), $ip);
    }

    public function test_밭은_required_days_당일에만_ready_가_된다(): void
    {
        $user = $this->makeUser('grow');
        $planting = FarmPlanting::query()->first();
        // 6일차까지 완료한 상태를 만든다 — 다음 참여가 7일차(= required_days)
        $planting->update(['completed_days' => 6, 'day_mask' => 0b111111,
            'last_tended_on' => Carbon::parse(self::DAY)->subDay()->toDateString()]);

        $this->assertTrue($this->submit($user)['correct']);

        $planting->refresh();
        $this->assertSame(7, (int) $planting->completed_days);
        $this->assertSame('ready', $planting->status);
        // day_mask 비트 수와 status 가 일치해야 한다(하루 일찍 ready 되면 마지막 날 참여가 영영 불가)
        $this->assertSame(7, substr_count(decbin((int) $planting->day_mask), '1'));
    }

    public function test_6일차_참여로는_아직_ready_가_아니다(): void
    {
        $user = $this->makeUser('grow6');
        FarmPlanting::query()->first()->update(['completed_days' => 5, 'day_mask' => 0b11111,
            'last_tended_on' => Carbon::parse(self::DAY)->subDay()->toDateString()]);

        $this->assertTrue($this->submit($user)['correct']);

        $planting = FarmPlanting::query()->first();
        $this->assertSame(6, (int) $planting->completed_days);
        $this->assertSame('growing', $planting->status);   // MariaDB 좌→우 SET 평가면 여기서 'ready' 가 된다
    }

    public function test_같은_IP_다른_사용자들이_일_한도를_넘겨_확정하지_못한다(): void
    {
        $this->media->update(['settings' => ['cooldown_minutes' => 0, 'cooldown_jitter_minutes' => 0,
            'ip_daily_limit' => 3]]);
        $this->media->refresh();

        $accepted = 0;
        foreach (range(1, 6) as $i) {
            if ($this->submit($this->makeUser("ip-u{$i}"), '203.0.113.9')['correct']) {
                $accepted++;
            }
        }

        $this->assertSame(3, $accepted);   // 사전검사만 있으면 경합에서 이 값이 커진다
        $this->assertDatabaseHas('reward_identity_counters', [
            'media_id' => $this->media->id, 'id_type' => 'ip', 'scope_key' => self::DAY, 'used' => 3,
        ]);
        $this->assertDatabaseHas('reward_participation_logs', ['reject_reason' => 'ip_limit']);
    }

    public function test_시도_수는_식별자_축에도_누적된다(): void
    {
        $user = $this->makeUser('att');
        app(MissionSubmitService::class)->submit($this->media, $user, $this->mission->id, '틀린답', '203.0.113.10');

        $row = DB::table('reward_identity_counters')
            ->where('id_hash', hash('sha256', '203.0.113.10'))->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(0, (int) $row->used);
    }

    public function test_한도_변경이_선생성된_익일_카운터에도_반영된다(): void
    {
        $tomorrow = Carbon::parse(self::DAY)->addDay()->toDateString();
        app(MissionSync::class)->ensureDailyCounters();
        $this->assertDatabaseHas('reward_mission_daily_counters',
            ['mission_id' => $this->mission->id, 'stat_date' => $tomorrow, 'daily_quota' => 50]);

        $this->mission->update(['daily_quota' => 10]);
        $this->invokeSyncCounter($this->mission);

        // 익일 행이 낡은 채로 남으면 다음 농장일 06:00~전량대조까지 옛 한도가 집행된다
        $this->assertDatabaseHas('reward_mission_daily_counters',
            ['mission_id' => $this->mission->id, 'stat_date' => $tomorrow, 'daily_quota' => 10]);
    }

    /** MissionSync::syncTodayCounter 는 private — 동기화 본체를 타지 않고 카운터 갱신만 확인한다 */
    private function invokeSyncCounter(RewardMission $mission): void
    {
        $m = new \ReflectionMethod(MissionSync::class, 'syncTodayCounter');
        $m->invoke(app(MissionSync::class), $mission, self::DAY);
    }

    public function test_스냅샷이_직전_농장일_기준이면_다시_굽는다(): void
    {
        // 05:59 = 전 농장일. 그 시점 기준으로 스냅샷을 굽는다
        Carbon::setTestNow(Carbon::parse(self::DAY.' 05:59', 'Asia/Seoul'));
        app(MissionSnapshot::class)->build();
        $this->assertDatabaseHas('reward_mission_snapshots',
            ['built_for_day' => Carbon::parse(self::DAY)->subDay()->toDateString()]);

        // 06:01 = 새 농장일. 오늘 시작하는 미션이 즉시 보여야 한다
        Carbon::setTestNow(Carbon::parse(self::DAY.' 06:01', 'Asia/Seoul'));
        RewardCache::flushLocal();
        $rows = app(MissionSnapshot::class)->cachedList(self::DAY, 0);

        $this->assertCount(1, $rows);
        $this->assertSame($this->mission->id, $rows[0]['id']);
        $this->assertDatabaseHas('reward_mission_snapshots', ['built_for_day' => self::DAY]);
    }

    public function test_warm_cache_는_스냅샷이_없어도_빈_파일을_굽지_않는다(): void
    {
        $file = storage_path('app/reward/missions-'.self::DAY.'.json');
        @unlink($file);

        $this->artisan('reward:warm-cache')->assertSuccessful();   // build-snapshot 을 먼저 돌리지 않는다

        $rows = json_decode((string) file_get_contents($file), true);
        $this->assertCount(1, $rows);   // build() 직후 파일을 읽으면 여기서 0건이 된다
        @unlink($file);
    }

    public function test_L2_연속_실패는_요청_경계를_넘어_브레이커를_연다(): void
    {
        config(['reward.cache.l2_store' => 'no-such-store']);   // 접근 시 예외 → 실패 누적

        // flushLocal = FPM 요청 경계(프로세스 static 초기화). 공유 스토어에 안 남기면 절대 5회에 도달하지 못한다
        foreach (range(1, 3) as $i) {
            RewardCache::remember('reward:test:'.$i, 5, 60, fn () => ['v' => $i]);
            RewardCache::flushLocal();
        }

        $this->assertTrue(RewardCache::isBreakerOpen());
    }

    public function test_벤더_읽기는_참여자_행을_만들지_않는다(): void
    {
        [$key] = $this->vendorKey();

        $this->withHeader('Authorization', 'Bearer '.$key)
            ->getJson('/api/v1/missions?participant_hash=ghost-'.str_repeat('x', 200))->assertOk();

        $this->assertSame(0, RewardUser::query()->where('media_id', '!=', $this->media->id)->count());
    }

    public function test_벤더_오답은_시도_한도까지만_허용된다(): void
    {
        [$key, $media] = $this->vendorKey();
        $media->update(['settings' => ['daily_attempt_limit' => 3]]);
        $uri = '/api/v1/missions/'.$this->mission->id.'/participations';

        foreach (range(1, 3) as $i) {
            $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Idempotency-Key' => 'w'.$i])
                ->postJson($uri, ['participant_hash' => 'bf', 'answer' => 'wrong'.$i])
                ->assertStatus(422)->assertJsonPath('reason', 'verify_failed');
        }

        // 4번째부터는 채점 자체를 하지 않는다 — 오답이 공짜면 정답을 무제한 두드릴 수 있다
        $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Idempotency-Key' => 'w4'])
            ->postJson($uri, ['participant_hash' => 'bf', 'answer' => 'wrong4'])
            ->assertStatus(422)->assertJsonPath('reason', 'not_eligible');
        $this->assertDatabaseHas('reward_participation_logs', ['reject_reason' => 'attempt_limit']);
    }

    public function test_신규_참여자_생성은_IP_예산_안에서만_이뤄진다(): void
    {
        config(['reward.new_user_per_ip_hourly' => 2]);

        foreach (range(1, 2) as $i) {
            $this->getJson('/api/farm/me/state', ['x-user-key' => 'burst-'.$i])->assertOk();
        }
        $this->getJson('/api/farm/me/state', ['x-user-key' => 'burst-3'])->assertStatus(429);

        // 기존 사용자는 예산과 무관하게 계속 쓴다
        $this->getJson('/api/farm/me/state', ['x-user-key' => 'burst-1'])->assertOk();
    }

    /** @return array{0: string, 1: RewardMedia} */
    private function vendorKey(): array
    {
        $user = User::create(['name' => '벤더H', 'email' => 'vh@rankfree.kr',
            'password' => 'secret1234', 'api_scopes' => ['mission']]);
        [, $key] = ApiKey::issue($user, '키', ['mission'], null, null, null);
        $media = RewardMedia::query()->create([
            'slug' => 'ow-h', 'name' => '오퍼월H', 'type' => RewardMedia::TYPE_VENDOR_API,
            'api_user_id' => $user->id, 'verify_mode' => 'server', 'is_active' => true, 'rate_limit_rps' => 100,
        ]);

        return [$key, $media];
    }
}
