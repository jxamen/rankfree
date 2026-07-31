<?php

namespace Tests\Feature;

use App\Domain\Reward\TagIndex;
use App\Models\ApiKey;
use App\Models\RewardMedia;
use App\Models\RewardMission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3.5 완료 판정 — 벤더 S2S 미션 API(scope: mission).
 * 스코프 게이트·슬롯 잔여 노출·클릭 검증·멱등 제출(같은 키 = 카운터 1회)·거절 규약.
 */
class RewardVendorApiTest extends TestCase
{
    use RefreshDatabase;

    private const TAGS = ['vtag-one', 'vtag-two', 'vtag-three'];

    private const DAY = '2026-07-31';

    private User $user;

    private string $key;

    private RewardMedia $media;

    private RewardMission $mission;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::DAY.' 10:00', 'Asia/Seoul'));   // S2(slot 1)

        $this->user = User::create(['name' => '벤더연동', 'email' => 'vendor@rankfree.kr',
            'password' => 'secret1234', 'api_scopes' => ['mission']]);
        [, $this->key] = ApiKey::issue($this->user, '벤더키', ['mission'], null, null, null);

        $this->media = RewardMedia::query()->create([
            'slug' => 'offerwall-a', 'name' => '오퍼월A', 'type' => RewardMedia::TYPE_VENDOR_API,
            'api_user_id' => $this->user->id, 'verify_mode' => 'server', 'is_active' => true,
        ]);

        $this->mission = RewardMission::query()->create([
            'order_item_id' => 910001, 'order_id' => 910, 'status' => 'active',
            'starts_on' => self::DAY, 'ends_on' => Carbon::parse(self::DAY)->addDays(6)->toDateString(),
            'daily_quota' => 5, 'total_quota' => 35, 'unit_revenue' => 375, 'payout_point' => 10,
            'per_user_limit' => 7, 'per_user_daily_limit' => 1,
            'title' => '벤더 미션', 'description' => '설명', 'tags' => self::TAGS,
            'landing_url' => 'https://s.example/v1',
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

    private function api(string $method, string $uri, array $data = [], array $headers = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->key)
            ->withHeaders($headers)->json($method, $uri, $data);
    }

    private function correctAnswer(string $participantHash): string
    {
        $idx = TagIndex::for(hash('sha256', $participantHash), $this->mission->id, self::DAY, count(self::TAGS));

        return self::TAGS[$idx - 1];
    }

    public function test_scope와_매체_연결이_없으면_거부된다(): void
    {
        [, $noScope] = ApiKey::issue($this->user, '스코프없음', ['rank'], null, null, null);
        $this->withHeader('Authorization', 'Bearer '.$noScope)->getJson('/api/v1/missions')->assertStatus(403);

        $this->media->update(['is_active' => false]);
        $this->api('GET', '/api/v1/missions')->assertStatus(403);   // 매체 미연결/비활성
    }

    public function test_목록은_슬롯_잔여만_노출하고_정답을_싣지_않는다(): void
    {
        $res = $this->api('GET', '/api/v1/missions')->assertOk();

        // S2 누적 상한 = ceil(5×2/7) = 2 → remaining 은 하루 잔여(5)가 아니라 슬롯 잔여(2)
        $res->assertJsonPath('missions.0.remaining', 2)
            ->assertJsonPath('missions.0.id', (string) $this->mission->id)
            ->assertJsonPath('meta.verifyMode', 'server');

        foreach (self::TAGS as $tag) {
            $this->assertStringNotContainsString($tag, $res->getContent());
        }
    }

    public function test_클릭_검증은_실존·자격을_확인하고_상세를_준다(): void
    {
        $this->api('GET', '/api/v1/missions/999999?participant_hash=p1')->assertStatus(410);   // 미존재

        $res = $this->api('GET', "/api/v1/missions/{$this->mission->id}?participant_hash=p1")->assertOk();
        $res->assertJsonPath('status', 'ok')
            ->assertJsonPath('mission.quiz.tagCount', count(self::TAGS));
        $this->assertNotNull($res->json('mission.quiz.tagIndex'));

        // 재조회에도 같은 번호(결정적)
        $again = $this->api('GET', "/api/v1/missions/{$this->mission->id}?participant_hash=p1")->assertOk();
        $this->assertSame($res->json('mission.quiz.tagIndex'), $again->json('mission.quiz.tagIndex'));
    }

    public function test_제출은_멱등키가_필수이고_같은_키는_한_번만_차감된다(): void
    {
        $uri = "/api/v1/missions/{$this->mission->id}/participations";

        $this->api('POST', $uri, ['participant_hash' => 'p1', 'answer' => 'x'])->assertStatus(400);   // 키 없음

        $headers = ['Idempotency-Key' => 'idem-001'];
        $first = $this->api('POST', $uri,
            ['participant_hash' => 'p1', 'answer' => '#'.$this->correctAnswer('p1')], $headers)->assertOk();
        $first->assertJsonPath('status', 'accepted')->assertJsonPath('remaining', 1);   // 슬롯 잔여 2 → 1
        $pid = $first->json('participation_id');
        $this->assertNotNull($pid);   // design-04 §3 계약 — snake_case

        $retry = $this->api('POST', $uri,
            ['participant_hash' => 'p1', 'answer' => $this->correctAnswer('p1')], $headers)->assertOk();
        $retry->assertJsonPath('status', 'duplicate')->assertJsonPath('participation_id', $pid);

        $this->assertSame(1, (int) DB::table('reward_mission_daily_counters')->value('used'));   // 재시도 차감 0
        $this->assertSame(1, DB::table('reward_participation_logs')->where('result', 'correct')->count());
    }

    public function test_오답과_재참여와_소진이_계약된_사유로_거절된다(): void
    {
        $uri = "/api/v1/missions/{$this->mission->id}/participations";

        // 오답 — verify_failed, 카운터 불변
        $this->api('POST', $uri, ['participant_hash' => 'p1', 'answer' => '틀림'],
            ['Idempotency-Key' => 'k-wrong'])->assertStatus(422)->assertJsonPath('reason', 'verify_failed');
        $this->assertSame(0, (int) DB::table('reward_mission_daily_counters')->value('used'));

        // 정상 수락 후 같은 참여자 재참여 — participant_duplicate (미션 1일 1회)
        $this->api('POST', $uri, ['participant_hash' => 'p1', 'answer' => $this->correctAnswer('p1')],
            ['Idempotency-Key' => 'k-1'])->assertOk();
        $this->api('POST', $uri, ['participant_hash' => 'p1', 'answer' => $this->correctAnswer('p1')],
            ['Idempotency-Key' => 'k-2'])->assertStatus(422)->assertJsonPath('reason', 'participant_duplicate');

        // 슬롯 상한(S2 누적 2)까지만 수락 — 벤더가 하루 물량을 몇 분에 몰아치지 못하게 한다(§7)
        $this->api('POST', $uri, ['participant_hash' => 'p2', 'answer' => $this->correctAnswer('p2')],
            ['Idempotency-Key' => 'k-p2'])->assertOk();
        $slotFull = $this->api('POST', $uri, ['participant_hash' => 'p3', 'answer' => $this->correctAnswer('p3')],
            ['Idempotency-Key' => 'k-p3'])->assertStatus(422);
        $slotFull->assertJsonPath('reason', 'slot_exhausted');
        $this->assertGreaterThan(0, $slotFull->json('retry_after_seconds'));   // 다음 슬롯까지 대기 안내
        $this->assertSame(2, (int) DB::table('reward_mission_daily_counters')->value('used'));

        // 마지막 슬롯(S7)은 누적 상한 = 일 한도 → 남은 3건 수락 후 quota_full. used 는 정확히 5
        Carbon::setTestNow(Carbon::parse(self::DAY.' 23:00', 'Asia/Seoul'));
        foreach (range(3, 5) as $i) {
            $this->api('POST', $uri, ['participant_hash' => "p{$i}", 'answer' => $this->correctAnswer("p{$i}")],
                ['Idempotency-Key' => "k-late-p{$i}"])->assertOk();
        }
        $this->api('POST', $uri, ['participant_hash' => 'p9', 'answer' => $this->correctAnswer('p9')],
            ['Idempotency-Key' => 'k-p9'])->assertStatus(422)->assertJsonPath('reason', 'quota_full');
        $this->assertSame(5, (int) DB::table('reward_mission_daily_counters')->value('used'));
    }

    public function test_단건_할당은_미션_하나를_상세로_준다(): void
    {
        $res = $this->api('POST', '/api/v1/missions/assign', ['participant_hash' => 'p1'])->assertOk();

        $res->assertJsonPath('status', 'ok')
            ->assertJsonPath('mission.id', (string) $this->mission->id)
            ->assertJsonPath('mission.remaining', 2)          // S2 슬롯 잔여
            ->assertJsonPath('mission.quiz.tagCount', count(self::TAGS));
        $this->assertIsInt($res->json('mission.quiz.tagIndex'));

        foreach (self::TAGS as $tag) {
            $this->assertStringNotContainsString($tag, $res->getContent());
        }

        // 받은 미션에 그대로 제출할 수 있다
        $idx = (int) $res->json('mission.quiz.tagIndex');
        $this->api('POST', "/api/v1/missions/{$this->mission->id}/participations",
            ['participant_hash' => 'p1', 'answer' => self::TAGS[$idx - 1]], ['Idempotency-Key' => 'k-assign'])
            ->assertOk()->assertJsonPath('status', 'accepted');
    }

    public function test_할당할_미션이_없으면_204와_대기시간을_준다(): void
    {
        // S2 누적 상한(2)을 채우면 이 구간에는 줄 미션이 없다
        DB::table('reward_mission_daily_counters')->update(['used' => 2]);
        $this->artisan('reward:build-snapshot')->assertSuccessful();

        $res = $this->api('POST', '/api/v1/missions/assign', ['participant_hash' => 'p9'])->assertStatus(204);
        $this->assertGreaterThan(0, (int) $res->headers->get('Retry-After'));
    }

    public function test_할당은_participant_hash가_필수다(): void
    {
        $this->api('POST', '/api/v1/missions/assign')->assertStatus(422);
    }

    public function test_소프트_차단_참여자는_빈_피드와_중립_사유만_받는다(): void
    {
        $svc = app(\App\Domain\Reward\VendorSubmitService::class);
        $svc->participant($this->media, 'heavy')->update(['status' => 'blocked', 'blocked_reason' => 'risk']);

        // 피드 — 오류가 아니라 "남은 미션 없음"(§8)
        $this->api('GET', '/api/v1/missions?participant_hash=heavy')
            ->assertOk()->assertJsonPath('missions', []);

        // 제출 — 세부 사유 비공개(not_eligible)
        $this->api('POST', "/api/v1/missions/{$this->mission->id}/participations",
            ['participant_hash' => 'heavy', 'answer' => $this->correctAnswer('heavy')],
            ['Idempotency-Key' => 'k-h'])->assertStatus(422)->assertJsonPath('reason', 'not_eligible');
    }

    public function test_정산_대사가_일자별_수락을_집계한다(): void
    {
        $uri = "/api/v1/missions/{$this->mission->id}/participations";
        foreach (range(1, 2) as $i) {
            $this->api('POST', $uri, ['participant_hash' => "p{$i}", 'answer' => $this->correctAnswer("p{$i}")],
                ['Idempotency-Key' => "k-{$i}"])->assertOk();
        }

        $this->api('GET', '/api/v1/participations?date='.self::DAY)
            ->assertOk()
            ->assertJsonPath('totalAccepted', 2)
            ->assertJsonPath('byMission.0.missionId', (string) $this->mission->id)
            ->assertJsonPath('byMission.0.accepted', 2);
    }
}
