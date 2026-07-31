<?php

namespace Tests\Feature;

use App\Domain\Reward\MissionSnapshot;
use App\Domain\Reward\RewardCache;
use App\Models\ApiKey;
use App\Models\RewardMedia;
use App\Models\RewardMission;
use App\Models\User;
use Database\Seeders\FarmCropSeeder;
use Database\Seeders\RewardMediaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4 완료 판정 — 노출·캐시 계층.
 * 심야 목록 = DB 쿼리 0 · 쿨다운 중 = 미션 테이블 조회 0 · 스냅샷 공유 · ETag 304 · 벤더 토큰버킷.
 */
class RewardExposureCacheTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-31';

    private RewardMission $mission;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::DAY.' 10:00', 'Asia/Seoul'));
        $this->seed([RewardMediaSeeder::class, FarmCropSeeder::class]);

        $this->mission = RewardMission::query()->create([
            'order_item_id' => 920001, 'order_id' => 920, 'status' => 'active',
            'starts_on' => self::DAY, 'ends_on' => Carbon::parse(self::DAY)->addDays(6)->toDateString(),
            'daily_quota' => 50, 'total_quota' => 350, 'unit_revenue' => 375, 'payout_point' => 10,
            'title' => '캐시 미션', 'description' => '설명', 'tags' => ['cachetag-x'],
            'landing_url' => 'https://s.example/c1',
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

    public function test_심야_목록은_DB_쿼리_0으로_닫힌다(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 03:00', 'Asia/Seoul'));

        DB::enableQueryLog();
        $this->getJson('/api/farm/missions', ['x-user-key' => 'quiet-u'])
            ->assertOk()->assertJsonPath('meta.closed', true);
        $this->assertCount(0, DB::getQueryLog());   // 인증·캐시 포함 아무것도 안 탄다
        DB::disableQueryLog();
    }

    public function test_쿨다운_중_목록은_미션_테이블을_조회하지_않는다(): void
    {
        // 첫 요청으로 사용자 생성 + C1 캐시 적재
        $this->getJson('/api/farm/missions', ['x-user-key' => 'cool-u'])->assertOk();
        DB::table('reward_users')->update(['cooldown_until' => now()->addHour()]);

        DB::enableQueryLog();
        $res = $this->getJson('/api/farm/missions', ['x-user-key' => 'cool-u'])->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $res->assertJsonPath('meta.locked', true)->assertJsonPath('meta.lockReason', 'cooldown');
        $this->assertNotEmpty($res->json('meta.unlockAt'));
        $this->assertStringNotContainsString('reward_missions', $queries);           // C1 캐시가 흡수
        $this->assertStringNotContainsString('reward_user_mission_counters', $queries);   // 잠금 중 개인화 생략
    }

    public function test_스냅샷을_모든_읽기가_공유하고_버전_갱신으로_무효화된다(): void
    {
        $this->artisan('reward:build-snapshot')->assertSuccessful();
        $this->assertDatabaseHas('reward_mission_snapshots', ['slot_key' => 'active', 'item_count' => 1]);

        // 캐시 적재 후 미션 테이블을 더 조회하지 않는다
        $this->getJson('/api/farm/missions', ['x-user-key' => 'u1'])->assertOk();
        DB::enableQueryLog();
        $this->getJson('/api/farm/missions', ['x-user-key' => 'u1'])->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();
        $this->assertStringNotContainsString('reward_missions', $queries);

        // 미션이 바뀌어도 옛 버전 캐시가 남지만, bumpVersion 이 키를 갈아 즉시 반영된다
        $this->mission->update(['title' => '바뀐 제목']);
        app(MissionSnapshot::class)->build();
        RewardCache::bumpVersion();
        $this->getJson('/api/farm/missions', ['x-user-key' => 'u1'])
            ->assertOk()->assertJsonPath('missions.0.title', '바뀐 제목');
    }

    public function test_warm_cache_가_파일_폴백을_굽는다(): void
    {
        $this->artisan('reward:build-snapshot')->assertSuccessful();
        $this->artisan('reward:warm-cache')->assertSuccessful();

        $file = storage_path('app/reward/missions-'.self::DAY.'.json');
        $this->assertFileExists($file);
        $rows = json_decode((string) file_get_contents($file), true);
        $this->assertSame('캐시 미션', $rows[0]['title']);
        $this->assertArrayNotHasKey('answer', $rows[0]);   // 파일에도 정답 계열 없음
        $this->assertArrayNotHasKey('tags', $rows[0]);

        @unlink($file);
    }

    public function test_벤더_목록은_ETag_304와_토큰버킷이_동작한다(): void
    {
        $user = User::create(['name' => '벤더', 'email' => 'v4@rankfree.kr',
            'password' => 'secret1234', 'api_scopes' => ['mission']]);
        [, $key] = ApiKey::issue($user, '키', ['mission'], null, null, null);
        RewardMedia::query()->create([
            'slug' => 'ow-4', 'name' => '오퍼월4', 'type' => RewardMedia::TYPE_VENDOR_API,
            'api_user_id' => $user->id, 'is_active' => true, 'rate_limit_rps' => 2,
        ]);

        $first = $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/v1/missions')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeaders(['Authorization' => 'Bearer '.$key, 'If-None-Match' => $etag])
            ->getJson('/api/v1/missions')->assertStatus(304);   // 폴링을 304 로 흡수

        // 토큰버킷 — rate_limit_rps=2, 시간 동결 상태라 같은 초의 3번째 요청은 429
        $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/v1/missions')->assertStatus(429);
    }

    public function test_flush_커맨드가_계층을_비운다(): void
    {
        $this->artisan('reward:build-snapshot')->assertSuccessful();
        $this->artisan('reward:warm-cache')->assertSuccessful();
        $this->artisan('reward:flush-cache')->assertSuccessful();

        $this->assertFileDoesNotExist(storage_path('app/reward/missions-'.self::DAY.'.json'));
    }
}
