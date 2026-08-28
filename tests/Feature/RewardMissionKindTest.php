<?php

namespace Tests\Feature;

use App\Models\RewardMedia;
use App\Models\RewardMission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 미션 유형별 수신(2026-08-28) — 매체가 쇼핑·플레이스 등 원하는 유형만 골라 받는다.
 * 종전에는 유형을 요청할 수도, 응답에서 알 수도 없어 매체가 전부 쇼핑으로 취급했다(design-05 §2 대분류).
 */
class RewardMissionKindTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-31';

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::DAY.' 10:00', 'Asia/Seoul'));   // S2(slot 1)

        $media = RewardMedia::query()->create([
            'slug' => 'offerwall-kind', 'name' => '오퍼월', 'type' => RewardMedia::TYPE_VENDOR_API,
            'verify_mode' => 'server', 'is_active' => true,
        ]);
        $this->key = $media->issueKey();

        $this->makeMission(920001, 'shopping', '쇼핑 미션');
        $this->makeMission(920002, 'place', '플레이스 미션');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeMission(int $itemId, string $kind, string $title): RewardMission
    {
        $m = RewardMission::query()->create([
            'order_item_id' => $itemId, 'order_id' => 920, 'status' => 'active', 'kind' => $kind,
            'starts_on' => self::DAY, 'ends_on' => Carbon::parse(self::DAY)->addDays(6)->toDateString(),
            'daily_quota' => 5, 'total_quota' => 35, 'unit_revenue' => 375, 'payout_point' => 10,
            'per_user_limit' => 7, 'per_user_daily_limit' => 1,
            'title' => $title, 'description' => '설명', 'tags' => ['t-one', 't-two', 't-three'],
            'shop_name' => '몰', 'product_title' => '상품 '.$itemId, 'product_price' => 10000, 'keyword' => 'kw'.$itemId,
            'landing_url' => 'https://s.example/'.$itemId,
        ]);
        DB::table('reward_mission_daily_counters')->insert([
            'mission_id' => $m->id, 'stat_date' => self::DAY, 'daily_quota' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $m;
    }

    private function apiGet(string $uri)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->key)->getJson($uri);
    }

    private function apiPost(string $uri, array $body = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->key)->postJson($uri, $body);
    }

    public function test_목록은_유형을_함께_내려주고_지원_유형을_알려준다(): void
    {
        $res = $this->apiGet('/api/v1/missions')->assertOk();

        $kinds = collect($res->json('missions'))->pluck('kind')->sort()->values()->all();
        $this->assertSame(['place', 'shopping'], $kinds);          // 유형이 응답에 실린다
        $this->assertSame(['shopping', 'place', 'mall', 'web'], $res->json('meta.kinds'));
    }

    public function test_목록을_유형으로_거를_수_있다(): void
    {
        $res = $this->apiGet('/api/v1/missions?kind=place')->assertOk();

        $this->assertCount(1, $res->json('missions'));
        $this->assertSame('place', $res->json('missions.0.kind'));
        $this->assertSame('플레이스 미션', $res->json('missions.0.title'));

        // 콤마로 여러 유형을 한 번에
        $this->assertCount(2, $this->apiGet('/api/v1/missions?kind=place,shopping')->assertOk()->json('missions'));
        // 해당 유형이 없으면 빈 목록(오류가 아니다)
        $this->assertCount(0, $this->apiGet('/api/v1/missions?kind=web')->assertOk()->json('missions'));
    }

    public function test_유형_축_이관_전_레거시값은_쇼핑으로_취급한다(): void
    {
        // 운영에는 아직 kind=external(확인 경로 축 시절 값)인 미션이 남아 있다 — 그대로 두면 어떤 유형으로도 안 잡힌다
        $this->makeMission(920003, 'external', '레거시 미션');

        $titles = collect($this->apiGet('/api/v1/missions?kind=shopping')->assertOk()->json('missions'))
            ->pluck('title')->all();
        $this->assertContains('레거시 미션', $titles);
        $this->assertContains('쇼핑 미션', $titles);

        $kinds = collect($this->apiGet('/api/v1/missions')->assertOk()->json('missions'))->pluck('kind')->unique()->sort()->values()->all();
        $this->assertSame(['place', 'shopping'], $kinds);   // 응답에도 external 이 그대로 새어 나가지 않는다
    }

    public function test_모르는_유형은_조용히_무시하지_않고_알려준다(): void
    {
        $res = $this->apiGet('/api/v1/missions?kind=pleace')->assertStatus(422);

        $this->assertStringContainsString('pleace', $res->json('message'));
        $this->assertSame(['shopping', 'place', 'mall', 'web'], $res->json('kinds'));
    }

    public function test_단건_할당도_유형을_지정할_수_있다(): void
    {
        $res = $this->apiPost('/api/v1/missions/assign', ['participant_hash' => 'u_kind', 'kind' => 'place'])->assertOk();

        $this->assertSame('place', $res->json('mission.kind'));
        $this->assertSame('플레이스 미션', $res->json('mission.title'));
    }

    public function test_지정한_유형에_줄_미션이_없으면_204(): void
    {
        $this->apiPost('/api/v1/missions/assign', ['participant_hash' => 'u_kind2', 'kind' => 'web'])
            ->assertStatus(204);
    }
}
