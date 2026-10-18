<?php

namespace Tests\Feature;

use App\Domain\Shopping\BoostingShopCampaignReader;
use App\Domain\Shopping\ShopKeywordAdEndPolicy;
use App\Domain\Shopping\ShopKeywordAdEndService;
use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Jobs\ShopKeywordCheckJob;
use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopKeywordAutoEndTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(array $attrs = []): ShopKeywordAnalysis
    {
        return ShopKeywordAnalysis::create($attrs + ['user_id' => User::factory()->create()->id,
            'core_keyword' => '테스트 키워드', 'product_url' => 'https://smartstore.naver.com/shop/products/111?x=1',
            'product_id' => '111', 'status' => 'checking', 'created_via' => 'api', 'threshold' => 5]);
    }

    private function campaigns(array $rows = []): void
    {
        $this->mock(BoostingShopCampaignReader::class)->shouldReceive('shopping')->andReturn($rows);
    }

    public function test_kst_date_boundary_and_all_pending_statuses(): void
    {
        $p = new ShopKeywordAdEndPolicy;
        $now = CarbonImmutable::parse('2026-10-18 15:10:00', 'UTC'); // KST Oct 19
        foreach (ShopKeywordAdEndPolicy::ACTIVE_STATUSES as $status) {
            $a = ['id' => 1, 'core_keyword' => '테스트', 'status' => $status, 'order_end_date' => '2026-10-18'];
            $this->assertSame('order_expired', $p->decide($a, [], $now)['reason']);
            $a['order_end_date'] = '2026-10-19';
            $this->assertNull($p->decide($a, [], $now)['reason']);
        }
        foreach (['done', 'target_reached', 'error', 'failed', 'ad_ended'] as $status) {
            $this->assertNull($p->decide(['id' => 1, 'core_keyword' => '', 'status' => $status,
                'order_end_date' => '2026-10-01'], [], $now)['reason']);
        }
    }

    public function test_matching_requires_both_product_and_keyword_and_protects_live_renewals(): void
    {
        $p = new ShopKeywordAdEndPolicy;
        $a = $this->analysis()->getAttributes();
        $c = ['no' => 1, 'keyword' => '테스트키워드', 'link' => 'https://m.smartstore.naver.com/shop/products/111',
            'status' => 'end', 'to_date' => '2026-10-17'];
        $now = CarbonImmutable::parse('2026-10-18', 'Asia/Seoul');
        $this->assertSame('campaign_ended', $p->decide($a, $p->index([$c]), $now)['reason']);
        foreach (['cancel', 'ing'] as $status) {
            $expired = $c;
            $expired['status'] = $status;
            $this->assertSame('campaign_ended', $p->decide($a, $p->index([$expired]), $now)['reason']);
        }
        foreach (['ing', 'waiting', null] as $status) {
            $live = array_replace($c, ['no' => 2, 'status' => $status, 'to_date' => '2026-10-18']);
            $this->assertNull($p->decide($a, $p->index([$c, $live]), $now)['reason']);
        }
        $wrong = array_replace($c, ['keyword' => '다른키워드']);
        $this->assertSame([], $p->decide($a, $p->index([$wrong]), $now)['campaign_ids']);
        $this->assertNotSame($p->productKey($c['link']), $p->productKey('https://search.shopping.naver.com/catalog/111'));
    }

    public function test_seven_day_cutoff_uses_last_collection_and_fallback_creation(): void
    {
        $p = new ShopKeywordAdEndPolicy;
        $now = CarbonImmutable::parse('2026-10-18 12:00:00', config('app.timezone'));
        $a = ['id' => 1, 'core_keyword' => '테스트', 'status' => 'paused', 'created_at' => '2026-10-01 00:00:00'];
        $this->assertSame('unmatched_stale', $p->decide($a, [], $now)['reason']);
        $a['last_collected_at'] = '2026-10-11 12:00:00';
        $this->assertSame('unmatched_stale', $p->decide($a, [], $now)['reason']);
        $a['last_collected_at'] = '2026-10-11 12:00:01';
        $this->assertNull($p->decide($a, [], $now)['reason']);
    }

    public function test_dry_run_preserves_data_and_finish_removes_only_matching_jobs_and_is_idempotent(): void
    {
        $this->campaigns();
        $a = $this->analysis(['order_end_date' => now('Asia/Seoul')->subDay()->toDateString()]);
        $item = $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '결과보존', 'rank' => 3]);
        $other = $this->analysis();
        config(['queue.default' => 'database']);
        ShopKeywordCheckJob::dispatch($a->id);
        ShopKeywordCheckJob::dispatch($other->id);
        $this->artisan('shop-keyword:auto-end --dry-run')->assertSuccessful();
        $this->assertSame('checking', $a->fresh()->status);
        $this->assertSame(2, DB::table('jobs')->count());
        $this->artisan('shop-keyword:auto-end')->assertSuccessful();
        $this->assertSame('ad_ended', $a->fresh()->status);
        $this->assertSame('종료(광고 끝남)', $a->fresh()->collectionLabel());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(3, $item->fresh()->rank);
        $this->assertSame(2, ShopKeywordAnalysis::count());
        $this->artisan('shop-keyword:auto-end')->assertSuccessful();
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_inflight_results_jobs_and_reopen_requests_cannot_revive_ended_analysis(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->withoutVite();
        $u = User::factory()->create(['role' => 'admin', 'api_scopes' => ['shop_keyword']]);
        $a = $this->analysis(['user_id' => $u->id, 'status' => 'ad_ended']);
        $item = $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '미수집']);
        $svc = app(ShopKeywordExposureAnalyzer::class);
        $this->assertTrue($svc->applyHtml($a, $item, '')['skipped']);
        $this->assertSame(0, $svc->checkBatch($a)['remaining']);
        (new ShopKeywordCheckJob($a->id))->handle($svc);
        Queue::assertNothingPushed();
        $this->assertFalse($a->update(['status' => 'checking']));
        $this->assertSame('ad_ended', $a->fresh()->status);
        $this->assertNull($item->fresh()->rank);
        $this->actingAs($u)->postJson('/admin/shop-keyword/'.$a->id.'/pause', ['paused' => false])->assertStatus(409);
    }

    public function test_plan_is_revalidated_before_closing_and_read_failure_aborts(): void
    {
        $this->campaigns();
        $a = $this->analysis();
        DB::table('shop_keyword_analyses')->where('id', $a->id)->update(['created_at' => now()->subDays(8)]);
        $svc = app(ShopKeywordAdEndService::class);
        $plan = $svc->plan();
        $this->assertSame('unmatched_stale', $plan[0]['reason']);
        DB::table('shop_keyword_analyses')->where('id', $a->id)->update(['collection_seen_at' => now()]);
        $this->assertSame(0, $svc->finish($plan));
        $this->mock(BoostingShopCampaignReader::class)->shouldReceive('shopping')->andThrow(new \RuntimeException('offline'));
        try {
            app(ShopKeywordAdEndService::class)->plan();
            $this->fail('must abort');
        } catch (\RuntimeException $e) {
            $this->assertSame('offline', $e->getMessage());
        }
        $this->assertSame('checking', $a->fresh()->status);
    }

    public function test_extension_queues_and_late_upload_exclude_ended_and_admin_list_renders_label(): void
    {
        $this->withoutVite();
        $u = User::factory()->create(['role' => 'admin', 'api_scopes' => ['shop_keyword']]);
        $a = $this->analysis(['user_id' => $u->id, 'status' => 'ad_ended']);
        $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '미확인']);
        [, $token] = ExtToken::issue($u);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/ext/shop-keyword/product-queue')->assertOk()->assertJsonPath('data.items', []);
        $this->getJson('/api/ext/shop-keyword/check-queue')->assertOk()->assertJsonPath('data.items', []);
        $this->postJson('/api/ext/shop-keyword/'.$a->id.'/product-info', [])->assertStatus(409);
        $this->actingAs($u)->get('/admin/shop-keyword')->assertOk()->assertSee('종료(광고 끝남)');
        $this->get('/admin/shop-keyword/'.$a->id)->assertOk()->assertDontSee('id="sk-prog"', false);
    }

    public function test_schedule_runs_at_0010_kst(): void
    {
        $events = app(Schedule::class)->events();
        $event = collect($events)->first(fn ($e) => str_contains($e->command ?? '', 'shop-keyword:auto-end'));
        $this->assertNotNull($event);
        $this->assertSame('10 0 * * *', $event->expression);
        $this->assertSame('Asia/Seoul', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }
}
