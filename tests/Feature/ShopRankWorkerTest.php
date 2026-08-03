<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopRankFromProducts;
use App\Domain\Shopping\ShopRankSlotService;
use App\Models\ShopRankJob;
use App\Models\ShopRankSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 쇼핑 순위체크 확장 워커(2026-08-03) — .claude/14_SHOPPING_RANK.md
 *
 * openapi shop.json 종료(공지 32564) 후, 확장이 켜진 PC 들이 시장분석 수집기로 목록을 가져와
 * 서버가 순위를 판정한다. 여러 대가 켜져 있어도 한 작업을 한 대만 가져가야 한다.
 */
class ShopRankWorkerTest extends TestCase
{
    use RefreshDatabase;

    /** 확장이 수집한 형태 그대로 — 광고가 섞여 있고, 순서가 곧 노출 순서다. */
    private function products(): array
    {
        return [
            ['isAd' => true, 'link' => 'https://smartstore.naver.com/ad/products/999', 'mallName' => '광고몰', 'title' => '광고상품'],
            ['isAd' => false, 'link' => 'https://smartstore.naver.com/a/products/111', 'mallName' => '가몰', 'title' => 'A', 'price' => 1000],
            ['isAd' => false, 'link' => 'https://smartstore.naver.com/b/products/222', 'mallName' => '나몰', 'title' => 'B', 'price' => 2000],
            ['isAd' => false, 'nvMid' => '555', 'link' => 'https://search.shopping.naver.com/catalog/555', 'mallName' => '다몰', 'title' => 'C', 'price' => 3000],
        ];
    }

    public function test_광고를_빼고_오가닉_순위를_센다(): void
    {
        $res = (new ShopRankFromProducts)->rank($this->products(), [
            'type' => 'product', 'product_id' => '222', 'id_kind' => 'channel', 'mall_name' => '',
        ], 5000);

        $this->assertTrue($res['found']);
        $this->assertSame(2, $res['rank'], '광고 1건이 앞에 있어도 오가닉 2위여야 한다');
        $this->assertSame('B', $res['title']);
        $this->assertSame(2000, $res['price']);
        $this->assertSame(5000, $res['total']);
    }

    public function test_가격비교_상품은_nvmid_로_매칭한다(): void
    {
        $res = (new ShopRankFromProducts)->rank($this->products(), [
            'type' => 'product', 'product_id' => '555', 'id_kind' => 'nvmid', 'mall_name' => '',
        ]);

        $this->assertTrue($res['found']);
        $this->assertSame(3, $res['rank']);
    }

    public function test_광고로만_걸리면_순위는_없고_광고만_표시한다(): void
    {
        $res = (new ShopRankFromProducts)->rank($this->products(), [
            'type' => 'product', 'product_id' => '999', 'id_kind' => 'channel', 'mall_name' => '',
        ]);

        $this->assertFalse($res['found'], '광고 자리는 순위가 아니다');
        $this->assertSame(0, $res['rank']);
        $this->assertTrue($res['ad']);
    }

    public function test_업체명으로도_매칭한다(): void
    {
        $res = (new ShopRankFromProducts)->rank($this->products(), [
            'type' => 'mall', 'product_id' => '', 'id_kind' => 'channel', 'mall_name' => '나 몰',
        ]);

        $this->assertTrue($res['found']);
        $this->assertSame(2, $res['rank']);
    }

    /** 깊은 순위도 그대로 센다 — 800위대 상품이 '미노출'이 되면 안 된다. */
    public function test_깊은_순위도_정확히_센다(): void
    {
        $products = [];
        for ($i = 1; $i <= 900; $i++) {
            $products[] = ['isAd' => false, 'link' => "https://smartstore.naver.com/s/products/{$i}", 'mallName' => "몰{$i}"];
        }

        $res = (new ShopRankFromProducts)->rank($products, [
            'type' => 'product', 'product_id' => '873', 'id_kind' => 'channel', 'mall_name' => '',
        ]);

        $this->assertTrue($res['found']);
        $this->assertSame(873, $res['rank']);
    }

    /**
     * 🔴 순위추적은 구 shop.json 과 같은 1000위 범위를 봐야 한다.
     * 시장분석 기본값(80)을 그대로 쓰면 80위 밖 상품이 전부 '미노출'로 기록된다.
     */
    public function test_기본_수집_범위는_1000위다(): void
    {
        $user = User::factory()->create();
        $slot = ShopRankSlot::create([
            'user_id' => $user->id, 'keyword' => 'kw', 'target_type' => 'product',
            'product_id' => '222', 'is_active' => true,
        ]);

        $job = app(ShopRankSlotService::class)->enqueue($slot);

        $this->assertGreaterThanOrEqual(13, $job->pages, '80개씩 13페이지 = 1040위');

        // 확장에 넘기는 count 도 1000 이상이어야 한다
        ShopRankJob::touchWorkerSeen('w1');
        $res = $this->postJson('/api/ext/shop-rank/claim', ['worker_id' => 'w1', 'limit' => 1])->assertOk();
        $this->assertGreaterThanOrEqual(1000, (int) $res->json('data.items.0.count'));
    }

    /** 🔴 워커 여러 대가 같은 작업을 가져가면 중복 수집이다 — claim 은 원자적이어야 한다. */
    public function test_한_작업을_두_워커가_동시에_가져가지_못한다(): void
    {
        ShopRankJob::create(['keyword' => 'kw', 'target_type' => 'product', 'product_id' => '1', 'source' => 'guest']);

        $a = ShopRankJob::claim('worker-A', 5);
        $b = ShopRankJob::claim('worker-B', 5);

        $this->assertCount(1, $a);
        $this->assertCount(0, $b, '이미 가져간 작업을 다른 워커가 또 잡으면 안 된다');
        $this->assertSame('worker-A', $a[0]->fresh()->claimed_by);
    }

    /** 워커가 죽어 리스가 끊기면 다른 워커가 회수한다(작업이 영원히 묶이지 않게). */
    public function test_리스가_끊긴_작업은_다른_워커가_회수한다(): void
    {
        $job = ShopRankJob::create(['keyword' => 'kw', 'target_type' => 'product', 'product_id' => '1', 'source' => 'guest']);
        ShopRankJob::claim('dead-worker', 1);

        $job->fresh()->update(['lease_until' => now()->subMinute()]);

        $again = ShopRankJob::claim('worker-B', 1);
        $this->assertCount(1, $again);
        $this->assertSame('worker-B', $again[0]->fresh()->claimed_by);
    }

    /** 리스는 깊은 스캔(2~4분)보다 길어야 한다 — 짧으면 정상 수집 중인 작업을 뺏어간다. */
    public function test_리스는_깊은_스캔보다_길다(): void
    {
        $this->assertGreaterThanOrEqual(300, ShopRankJob::LEASE_SECONDS);
    }

    /** 캡차는 그 PC 문제일 뿐 — 작업을 버리지 않고 백오프 후 다시 큐에 둔다. */
    public function test_실패는_백오프_후_다시_큐로_돌아온다(): void
    {
        $job = ShopRankJob::create(['keyword' => 'kw', 'target_type' => 'product', 'product_id' => '1', 'source' => 'guest']);
        ShopRankJob::claim('w1', 1);

        $job->refresh()->failAttempt('captcha');

        $this->assertSame('pending', $job->fresh()->status);
        $this->assertTrue($job->fresh()->available_at->isFuture());
        $this->assertCount(0, ShopRankJob::claim('w2', 1), '백오프 중에는 주지 않는다');
    }

    public function test_재시도_상한을_넘으면_실패로_확정한다(): void
    {
        $job = ShopRankJob::create(['keyword' => 'kw', 'target_type' => 'product', 'product_id' => '1', 'source' => 'guest']);

        for ($i = 0; $i < ShopRankJob::MAX_ATTEMPTS; $i++) {
            $job->update(['available_at' => null]);
            ShopRankJob::claim('w', 1);
            $job->refresh()->failAttempt('captcha');
        }

        $this->assertSame('failed', $job->fresh()->status);
    }

    /**
     * 워커 API 왕복 — claim 으로 받고 result 로 제출하면 슬롯·일별기록에 반영된다.
     * 순위체크는 **서버가 시키는 일**이라 사용자 로그인 없이 돈다.
     */
    public function test_로그인_없이_제출한_결과가_슬롯에_반영된다(): void
    {
        $user = User::factory()->create();
        $slot = ShopRankSlot::create([
            'user_id' => $user->id, 'keyword' => '캠핑의자', 'target_type' => 'product',
            'product_id' => '222', 'product_url' => 'https://smartstore.naver.com/b/products/222',
            'is_active' => true,
        ]);
        $job = app(ShopRankSlotService::class)->enqueue($slot);

        $claimed = ShopRankJob::claim('w1', 1);
        $this->assertSame($job->id, $claimed[0]->id);

        $this->postJson("/api/ext/shop-rank/{$job->id}/result", [
            'worker_id' => 'w1', 'products' => $this->products(), 'total' => 4321,
        ])
            ->assertOk()
            ->assertJsonPath('data.rank', 2);

        $this->assertSame('done', $job->fresh()->status);
        $this->assertSame(2, (int) $slot->fresh()->last_rank);
        $this->assertDatabaseHas('shop_rank_records', ['slot_id' => $slot->id, 'rank' => 2, 'list_total' => 4321]);
    }

    /** 리스가 끊겨 다른 워커가 가져간 뒤 늦게 도착한 결과로 덮어쓰지 않는다. */
    public function test_소유자가_아닌_워커의_제출은_거부한다(): void
    {
        $job = ShopRankJob::create(['keyword' => 'kw', 'target_type' => 'product', 'product_id' => '222', 'source' => 'guest']);
        ShopRankJob::claim('w1', 1);

        $this->postJson("/api/ext/shop-rank/{$job->id}/result", ['worker_id' => 'w2', 'products' => $this->products()])
            ->assertStatus(409);

        $this->assertSame('claimed', $job->fresh()->status);
    }

    /** limit:0 = 핑 — 작업을 가져가지 않고 큐 상태만 본다(연결 확인용). */
    public function test_핑은_작업을_가져가지_않는다(): void
    {
        ShopRankJob::create(['keyword' => 'kw', 'target_type' => 'product', 'product_id' => '1', 'source' => 'guest']);

        $this->postJson('/api/ext/shop-rank/claim', ['worker_id' => 'w1', 'limit' => 0])
            ->assertOk()
            ->assertJsonPath('data.ping', true)
            ->assertJsonPath('data.pending', 1);

        $this->assertSame('pending', ShopRankJob::first()->status, '핑이 작업을 물면 안 된다');
    }

    /**
     * 🔴 확장이 꺼져 있으면 기다리지 않고 즉시 돌아온다.
     * 붙잡고 있어봐야 결과는 안 오고 요청 처리 슬롯만 먹는다.
     */
    public function test_워커가_없으면_기다리지_않고_즉시_반환한다(): void
    {
        $user = User::factory()->create();
        $slot = ShopRankSlot::create([
            'user_id' => $user->id, 'keyword' => 'kw', 'target_type' => 'product',
            'product_id' => '222', 'is_active' => true,
        ]);

        $t0 = microtime(true);
        $res = app(ShopRankSlotService::class)->run($slot);
        $elapsed = microtime(true) - $t0;

        $this->assertTrue($res['queued']);
        $this->assertTrue($res['no_worker']);
        $this->assertLessThan(3, $elapsed, '워커가 없는데 기다리면 안 된다');
        $this->assertSame(1, ShopRankJob::where('slot_id', $slot->id)->count(), '작업은 큐에 남아야 한다');
    }

    /** 이미 끝난 작업은 기다리지 않고 즉시 돌려준다. */
    public function test_끝난_작업은_즉시_결과를_준다(): void
    {
        $job = ShopRankJob::create(['keyword' => 'kw', 'target_type' => 'product', 'product_id' => '222', 'source' => 'guest']);
        ShopRankJob::claim('w1', 1);
        $job->refresh()->complete((new ShopRankFromProducts)->rank($this->products(), $job->target(), 777));
        ShopRankJob::touchWorkerSeen('w1');

        $t0 = microtime(true);
        $done = $job->fresh()->waitForResult(30);
        $elapsed = microtime(true) - $t0;

        $this->assertNotNull($done);
        $this->assertSame('done', $done->status);
        $this->assertSame(2, (int) $done->rank);
        $this->assertLessThan(3, $elapsed);
    }

    /** 워커는 켜져 있는데 시간 안에 못 끝내면 '확인 중'으로 돌아온다(무한 대기 금지). */
    public function test_시간_안에_안_끝나면_확인중으로_돌아온다(): void
    {
        config(['rankfree.shopping.worker_wait_sec' => 1]);
        ShopRankJob::touchWorkerSeen('w1');

        $user = User::factory()->create();
        $slot = ShopRankSlot::create([
            'user_id' => $user->id, 'keyword' => 'kw', 'target_type' => 'product',
            'product_id' => '222', 'is_active' => true,
        ]);

        $res = app(ShopRankSlotService::class)->run($slot);

        $this->assertTrue($res['queued']);
        $this->assertTrue($res['pending']);
        $this->assertArrayNotHasKey('no_worker', $res);
    }

    /** 같은 슬롯을 여러 번 눌러도 큐가 불어나지 않는다. */
    public function test_대기중인_슬롯은_중복_적재하지_않는다(): void
    {
        $user = User::factory()->create();
        $slot = ShopRankSlot::create([
            'user_id' => $user->id, 'keyword' => 'kw', 'target_type' => 'product',
            'product_id' => '1', 'is_active' => true,
        ]);
        $svc = app(ShopRankSlotService::class);

        $a = $svc->enqueue($slot);
        $b = $svc->enqueue($slot);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, ShopRankJob::where('slot_id', $slot->id)->count());
    }
}
