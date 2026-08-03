<?php

namespace Tests\Feature;

use App\Domain\Reward\MissionSubmitService;
use App\Domain\Reward\QuotaGate;
use App\Domain\Reward\TagIndex;
use App\Models\FarmPlanting;
use App\Models\RewardMedia;
use App\Models\RewardMission;
use App\Models\RewardUser;
use Database\Seeders\FarmCropSeeder;
use Database\Seeders\RewardMediaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3 완료 판정 — 참여 확정 경로.
 * 쿨다운·일 한도·quota_full 정지·오답 무해성·tagIndex 결정성·정답 미노출(HANDOFF §10).
 */
class RewardSubmitTest extends TestCase
{
    use RefreshDatabase;

    private RewardMedia $media;

    private RewardMission $mission;

    private const TAGS = ['tagsecret-a', 'tagsecret-b', 'tagsecret-c', 'tagsecret-d'];

    private const DAY = '2026-07-31';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::DAY.' 10:00', 'Asia/Seoul'));   // S2 구간, 농장일 = DAY

        $this->seed([RewardMediaSeeder::class, FarmCropSeeder::class]);
        $this->media = RewardMedia::query()->where('slug', 'quiz-farm')->first();
        $this->media->update(['settings' => ['cooldown_jitter_minutes' => 0]]);   // 결정적 테스트

        $this->mission = RewardMission::query()->create([
            'order_item_id' => 900001, 'order_id' => 900, 'status' => 'active',
            'starts_on' => self::DAY, 'ends_on' => Carbon::parse(self::DAY)->addDays(6)->toDateString(),
            'daily_quota' => 5, 'total_quota' => 35, 'unit_revenue' => 375,
            'payout_point' => 10, 'per_user_limit' => 7, 'per_user_daily_limit' => 1,
            'title' => '테스트 미션', 'description' => '설명', 'tags' => self::TAGS,
            'shop_name' => '테스트몰', 'product_title' => '테스트 상품', 'product_price' => 15000, 'keyword' => '테스트키워드',
            'landing_url' => 'https://s.example/m1',
        ]);
        DB::table('reward_mission_daily_counters')->insert([
            'mission_id' => $this->mission->id, 'stat_date' => self::DAY, 'daily_quota' => 5,
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

    private function correctAnswer(RewardUser $user): string
    {
        $idx = TagIndex::for($user->user_key_hash, $this->mission->id, self::DAY, count(self::TAGS));

        return self::TAGS[$idx - 1];
    }

    private function submit(RewardUser $user, string $answer): array
    {
        return app(MissionSubmitService::class)->submit(
            $this->media, $user->refresh(), $this->mission->id, $answer, '10.0.0.1');
    }

    public function test_정답_제출이_확정된다(): void
    {
        $user = $this->makeUser('u1');
        $res = $this->submit($user, '#'.strtoupper($this->correctAnswer($user)));   // #·대소문자 정규화 확인

        $this->assertTrue($res['correct']);
        $this->assertSame(10, $res['points']);
        $this->assertNotEmpty($res['nextMissionAt']);

        $user->refresh();
        $this->assertSame(1, (int) $user->today_count);
        $this->assertSame(10, (int) $user->accrued_points);
        $this->assertTrue($user->cooldown_until->isFuture());

        $p = FarmPlanting::query()->first();
        $this->assertSame(1, (int) $p->completed_days);
        $this->assertSame(1, (int) $p->day_mask);
        $this->assertSame(self::DAY, $p->last_tended_on->toDateString());

        $this->assertDatabaseHas('reward_mission_daily_counters',
            ['mission_id' => $this->mission->id, 'stat_date' => self::DAY, 'used' => 1]);
        $this->assertDatabaseHas('reward_participation_logs',
            ['reward_user_id' => $user->id, 'result' => 'correct', 'seq_in_day' => 1, 'payout_point' => 10]);
    }

    public function test_쿨다운_직후_거부되고_경과_후_허용된다(): void
    {
        $user = $this->makeUser('u1');
        $this->assertTrue($this->submit($user, $this->correctAnswer($user))['correct']);

        Carbon::setTestNow(now()->addMinutes(5));   // 쿨다운 중(120분 전)
        $res = $this->submit($user, $this->correctAnswer($user));
        $this->assertFalse($res['correct']);
        $this->assertStringContainsString('다음 미션은', $res['message']);
        $this->assertDatabaseHas('reward_participation_logs', ['reject_reason' => 'cooldown']);

        // 121분 경과 — 두 번째 밭을 심으면 허용(첫 밭은 오늘 이미 돌봄).
        // 같은 미션 재참여이므로 미션 1일 상한은 이 테스트 목적(쿨다운)에서 제외한다
        $this->mission->update(['per_user_daily_limit' => 3]);
        FarmPlanting::query()->create([
            'reward_user_id' => $user->id, 'plot_index' => 1, 'crop_id' => 'carrot',
            'required_days' => 7, 'reward_points' => 70, 'planted_on' => self::DAY,
        ]);
        Carbon::setTestNow(Carbon::parse(self::DAY.' 10:00', 'Asia/Seoul')->addMinutes(121));
        $this->assertTrue($this->submit($user, $this->correctAnswer($user))['correct']);
    }

    public function test_오답은_어떤_카운터도_건드리지_않는다(): void
    {
        $user = $this->makeUser('u1');
        $res = $this->submit($user, '틀린답');

        $this->assertFalse($res['correct']);
        $user->refresh();
        $this->assertSame(0, (int) $user->today_count);
        $this->assertSame(1, (int) $user->today_attempts);   // 시도 수만 오른다(C16)
        $this->assertNull($user->cooldown_until);
        $this->assertSame(0, (int) DB::table('reward_mission_daily_counters')->value('used'));
        $this->assertSame(0, (int) FarmPlanting::query()->value('completed_days'));
        $this->assertDatabaseHas('reward_participation_logs', ['result' => 'wrong', 'answer_norm' => '틀린답']);
    }

    public function test_일_한도에서_정확히_멈춘다(): void
    {
        // 사용자 8명이 연속 확정 시도 — daily_quota 5 에서 정확히 멈추고 seq 는 1~5 로 빈틈없다
        $results = [];
        foreach (range(1, 8) as $i) {
            $user = $this->makeUser("user-{$i}");
            $results[] = $this->submit($user, $this->correctAnswer($user));
        }

        $this->assertSame(5, collect($results)->where('correct', true)->count());
        $this->assertSame(3, collect($results)->where('correct', false)->count());
        $this->assertSame(5, (int) DB::table('reward_mission_daily_counters')->value('used'));   // 초과 0

        $seqs = DB::table('reward_participation_logs')->where('result', 'correct')
            ->orderBy('seq_in_day')->pluck('seq_in_day')->all();
        $this->assertSame([1, 2, 3, 4, 5], array_map('intval', $seqs));   // 전역 순번 빈틈 없음

        $this->assertSame(3, DB::table('reward_participation_logs')
            ->where('reject_reason', 'quota_full')->count());
        // S2 구간 누적 상한 = ceil(5×2/7) = 2 → 3·4·5번째는 구간 초과지만 청구 가능이므로 통과(C8)
        $this->assertSame(3, DB::table('reward_participation_logs')
            ->where('result', 'correct')->where('slot_overflow', true)->count());
    }

    public function test_사용자_일_3회와_미션_1일_1회_상한(): void
    {
        $user = $this->makeUser('u1');
        $this->assertTrue($this->submit($user, $this->correctAnswer($user))['correct']);

        // 같은 미션 재참여 — per_user_daily_limit=1 (쿨다운 이후로 시간 이동)
        FarmPlanting::query()->create([
            'reward_user_id' => $user->id, 'plot_index' => 1, 'crop_id' => 'carrot',
            'required_days' => 7, 'reward_points' => 70, 'planted_on' => self::DAY,
        ]);
        Carbon::setTestNow(Carbon::parse(self::DAY.' 13:00', 'Asia/Seoul'));
        $res = $this->submit($user, $this->correctAnswer($user));
        $this->assertFalse($res['correct']);
        $this->assertDatabaseHas('reward_participation_logs', ['reject_reason' => 'mission_cap']);
    }

    public function test_심야_휴지에는_제출과_목록이_닫힌다(): void
    {
        $user = $this->makeUser('u1');
        Carbon::setTestNow(Carbon::parse('2026-08-01 03:00', 'Asia/Seoul'));

        $res = $this->submit($user, $this->correctAnswer($user));
        $this->assertFalse($res['correct']);
        $this->assertStringContainsString('아침 6시', $res['message']);

        $this->getJson('/api/farm/missions', ['x-user-key' => 'u1'])
            ->assertOk()->assertJsonPath('meta.closed', true)->assertJsonPath('missions', []);
    }

    public function test_목록은_tagIndex가_결정적이고_정답을_노출하지_않는다(): void
    {
        $this->makeUser('u1');

        $first = $this->getJson('/api/farm/missions', ['x-user-key' => 'u1'])->assertOk();
        $second = $this->getJson('/api/farm/missions', ['x-user-key' => 'u1'])->assertOk();

        $idx = $first->json('missions.0.quiz.tagIndex');
        $this->assertNotNull($idx);
        $this->assertSame($idx, $second->json('missions.0.quiz.tagIndex'));   // 재조회에도 같은 번호
        $this->assertSame(count(self::TAGS), $first->json('missions.0.quiz.tagCount'));

        foreach (self::TAGS as $tag) {
            $this->assertStringNotContainsString($tag, $first->getContent());   // 태그 목록·정답 미노출
        }
    }

    public function test_밭이_없으면_심으라고_안내한다(): void
    {
        $user = RewardUser::query()->create([
            'media_id' => $this->media->id, 'user_key_hash' => hash('sha256', 'no-plot'),
        ]);
        $res = $this->submit($user, 'x');

        $this->assertFalse($res['correct']);
        $this->assertStringContainsString('심어', $res['message']);
        $this->assertDatabaseHas('reward_participation_logs', ['reject_reason' => 'plot_empty']);
    }

    public function test_심기_API가_스냅샷과_중복_방지를_지킨다(): void
    {
        $this->postJson('/api/farm/plots/0/plant', ['cropId' => 'tomato'], ['x-user-key' => 'u9'])
            ->assertOk()->assertJson(['ok' => true]);

        $p = FarmPlanting::query()->first();
        $this->assertSame(150, (int) $p->reward_points);   // 심을 때 포인트 스냅샷(소급 금지)
        $this->assertSame(7, (int) $p->required_days);

        $this->postJson('/api/farm/plots/0/plant', ['cropId' => 'corn'], ['x-user-key' => 'u9'])
            ->assertStatus(422);   // 같은 밭 중복 심기 거부

        // 상태 응답 — 위치 기반 3칸 배열 + 심은 시점 금액
        $this->getJson('/api/farm/me/state', ['x-user-key' => 'u9'])
            ->assertOk()
            ->assertJsonPath('plots.0.cropId', 'tomato')
            ->assertJsonPath('plots.0.rewardPoints', 150)
            ->assertJsonPath('plots.1.cropId', null);
    }

    public function test_쿼터게이트_단독_100회_호출에서_초과가_없다(): void
    {
        DB::table('reward_mission_daily_counters')
            ->where('mission_id', $this->mission->id)->update(['daily_quota' => 50]);

        $seqs = [];
        foreach (range(1, 100) as $i) {
            $gate = DB::transaction(fn () => QuotaGate::consume($this->mission->id, self::DAY, 50));
            if ($gate !== null) {
                $seqs[] = $gate['seq'];
            }
        }

        $this->assertCount(50, $seqs);                     // 정확히 daily_quota 에서 멈춘다
        $this->assertSame(range(1, 50), $seqs);            // 순번 빈틈·중복 없음
        $this->assertSame(50, (int) DB::table('reward_mission_daily_counters')->value('used'));
    }
}
