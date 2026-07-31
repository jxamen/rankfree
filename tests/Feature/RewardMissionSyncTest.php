<?php

namespace Tests\Feature;

use App\Domain\Reward\MissionSync;
use App\Models\RewardMission;
use App\Support\RewardDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 완료 판정 — 세부주문서를 만들면 미션이 draft 로 뜨고 unit_revenue(C9)가 채워지며,
 * 한도 하향이 카운터 overflow 로 기록된다(C12).
 */
class RewardMissionSyncTest extends TestCase
{
    use RefreshDatabase;

    private int $poolVendorId;

    private int $orderId;

    private int $poolItemId;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $this->poolVendorId = (int) DB::table('vendors')->insertGetId(
            ['name' => '리워드 풀', 'channel' => 'reward', 'created_at' => $now, 'updated_at' => $now]);
        $otherVendorId = (int) DB::table('vendors')->insertGetId(
            ['name' => '외주 업체', 'channel' => 'api', 'created_at' => $now, 'updated_at' => $now]);

        config(['reward.pool_vendor_id' => $this->poolVendorId]);

        $productId = (int) DB::table('marketing_products')->insertGetId([
            'product_type' => 'traffic', 'title' => '쇼핑 유입', 'order_token' => 'tok-reward-test',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // C9 시나리오: total_price 225,000 · 전체 회차 수량 합 600(풀 120 + 타 벤더 480) → 단가 375원
        $this->orderId = (int) DB::table('marketing_orders')->insertGetId([
            'order_no' => 'RWD-1', 'product_id' => $productId, 'quantity' => 600, 'days' => 5,
            'unit_price' => 150, 'total_price' => 225000, 'status' => 'processing',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $today = RewardDay::current();
        $ends = Carbon::parse($today)->addDays(4)->toDateString();

        $this->poolItemId = (int) DB::table('marketing_order_items')->insertGetId([
            'order_id' => $this->orderId, 'day_no' => 1, 'work_date' => $today, 'end_date' => $ends,
            'quantity' => 120, 'short_url' => 'https://s.example/abc', 'vendor_id' => $this->poolVendorId,
            'status' => 'sent', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('marketing_order_items')->insert([
            'order_id' => $this->orderId, 'day_no' => 1, 'work_date' => $today, 'end_date' => $ends,
            'quantity' => 480, 'short_url' => null, 'vendor_id' => $otherVendorId,
            'status' => 'sent', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function test_동기화가_풀_벤더_회차만_draft_미션으로_만든다(): void
    {
        $stats = app(MissionSync::class)->sync();

        $this->assertSame(1, $stats['synced']);
        $this->assertSame(1, RewardMission::query()->count());   // 타 벤더 회차는 미러되지 않는다

        $m = RewardMission::query()->first();
        $this->assertSame('draft', $m->status);                  // 정답 미입력 = draft
        $this->assertSame(120, $m->daily_quota);
        $this->assertSame(600, $m->total_quota);                 // 120 × 5일(종료일 포함)
        $this->assertSame(375.0, (float) $m->unit_revenue);      // 225,000 ÷ 600 — 전 벤더 분모(C9)
        $this->assertSame('https://s.example/abc', $m->landing_url);
        $this->assertNotSame('', $m->title);

        // 당일 + 익일 카운터 선생성(기간 내 날짜만)
        $today = RewardDay::current();
        $this->assertDatabaseHas('reward_mission_daily_counters',
            ['mission_id' => $m->id, 'stat_date' => $today, 'daily_quota' => 120]);
        $this->assertDatabaseHas('reward_mission_daily_counters',
            ['mission_id' => $m->id, 'stat_date' => Carbon::parse($today)->addDay()->toDateString()]);
    }

    public function test_정답이_입력된_draft_는_재동기화에서_active_로_승격된다(): void
    {
        app(MissionSync::class)->sync();
        RewardMission::query()->first()->update(['answer' => '12500']);

        app(MissionSync::class)->sync();

        $this->assertSame('active', RewardMission::query()->first()->status);
    }

    /**
     * 해시태그형 미션은 상품 태그가 곧 정답이라 운영자 입력이 필요 없다 —
     * 정답 컬럼만 보고 draft 에 묶어두면 실제 미션이 영영 노출되지 않는다.
     */
    public function test_상품_해시태그가_있으면_정답_입력_없이_active_가_된다(): void
    {
        $now = now();
        $userId = (int) DB::table('users')->insertGetId([
            'name' => '광고주', 'email' => 'adv-sync@rankfree.kr', 'password' => bcrypt('secret1234'),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('shop_keyword_analyses')->insert([
            'user_id' => $userId, 'marketing_order_id' => $this->orderId, 'core_keyword' => '여름 원피스',
            'product_id' => 'PID-1', 'mall_name' => '테스트몰', 'product_title' => '린넨 원피스',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('shop_product_infos')->insert([
            'user_id' => $userId, 'channel_product_id' => 'PID-1', 'title' => '린넨 원피스',
            'seller_tags' => json_encode(['여름원피스', '린넨', '데일리룩']),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        app(MissionSync::class)->sync();

        $m = RewardMission::query()->first();
        $this->assertSame('active', $m->status);
        $this->assertNull($m->answer);                       // 정답 컬럼은 비어 있다
        $this->assertSame(['여름원피스', '린넨', '데일리룩'], $m->tags);
    }

    public function test_한도_하향은_오늘_카운터에_overflow_로_기록된다(): void
    {
        app(MissionSync::class)->sync();
        $m = RewardMission::query()->first();
        $today = RewardDay::current();

        DB::table('reward_mission_daily_counters')
            ->where('mission_id', $m->id)->where('stat_date', $today)->update(['used' => 100]);
        DB::table('marketing_order_items')->where('id', $this->poolItemId)->update(['quantity' => 80]);

        app(MissionSync::class)->sync(orderId: $this->orderId);   // 어드민 저장 훅과 같은 경로

        $counter = DB::table('reward_mission_daily_counters')
            ->where('mission_id', $m->id)->where('stat_date', $today)->first();
        $this->assertSame(80, (int) $counter->daily_quota);
        $this->assertSame(20, (int) $counter->overflow_count);    // used 100 > 새 한도 80 → 초과 20 기록
    }

    public function test_unit_revenue_0_이면_정답이_있어도_draft_로_막는다(): void
    {
        DB::table('marketing_orders')->where('id', $this->orderId)->update(['total_price' => 0]);

        app(MissionSync::class)->sync();
        RewardMission::query()->first()->update(['answer' => '100']);
        app(MissionSync::class)->sync();

        $this->assertSame('draft', RewardMission::query()->first()->status);
    }

    public function test_전량_동기화가_종료·취소를_정리한다(): void
    {
        app(MissionSync::class)->sync();
        $today = RewardDay::current();

        // 시간 경과 시뮬레이션 — 원본 회차와 미러 모두 기간이 지난 상태(원본 필터에 안 걸리므로 스윕이 정리)
        DB::table('marketing_order_items')->where('id', $this->poolItemId)->update([
            'work_date' => Carbon::parse($today)->subDays(9)->toDateString(),
            'end_date' => Carbon::parse($today)->subDay()->toDateString(),
        ]);
        RewardMission::query()->first()->update([
            'status' => 'active',
            'starts_on' => Carbon::parse($today)->subDays(9)->toDateString(),
            'ends_on' => Carbon::parse($today)->subDay()->toDateString(),
        ]);

        $stats = app(MissionSync::class)->sync();
        $this->assertSame(1, $stats['ended']);
        $this->assertSame('ended', RewardMission::query()->first()->status);

        // 원본 회차 취소 → 미션 취소
        RewardMission::query()->first()->update(['status' => 'active', 'ends_on' => Carbon::parse($today)->addDays(4)->toDateString()]);
        DB::table('marketing_order_items')->where('id', $this->poolItemId)->update(['status' => 'canceled']);

        $stats = app(MissionSync::class)->sync();
        $this->assertSame(1, $stats['canceled']);
        $this->assertSame('canceled', RewardMission::query()->first()->status);
    }

    public function test_풀_벤더_미지정이면_동기화가_중단된다(): void
    {
        config(['reward.pool_vendor_id' => 0]);

        $this->assertSame(['error' => 'pool_vendor_unset'], app(MissionSync::class)->sync());
        $this->assertSame(0, RewardMission::query()->count());
    }
}
