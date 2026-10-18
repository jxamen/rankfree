<?php
namespace Tests\Feature;

use App\Domain\Shopping\ShopBrowserWatchdog;
use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Jobs\ShopKeywordCheckJob;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopBrowserWatchdogTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_browser_transfers_once_progresses_without_page_and_returns_on_recovery(): void
    {
        Cache::flush(); Queue::fake();
        config(['queue.default'=>'database', 'rankfree.shopping.exposure.fetch_delay_ms'=>0]);
        $a = ShopKeywordAnalysis::create(['user_id'=>User::factory()->create()->id,'core_keyword'=>'컵','status'=>'checking','created_via'=>'admin','check_method'=>'search','collection_source'=>'브라우저','collection_ip'=>'127.0.0.1','collection_seen_at'=>now()]);
        $a->forceFill(['collection_source'=>'브라우저','collection_ip'=>'127.0.0.1','collection_seen_at'=>now()])->save();
        $item = $a->items()->create(['kind'=>'combo','source'=>'combo','keyword'=>'컵 테스트']);
        $w = app(ShopBrowserWatchdog::class);
        $this->assertSame(0, $w->scan());
        $this->travel(61)->seconds();
        $this->assertSame(1, $w->scan());
        $this->assertSame(0, $w->scan());
        Queue::assertPushed(ShopKeywordCheckJob::class, 1);
        Http::fake(['*'=>Http::response('<html></html>')]);
        (new ShopKeywordCheckJob($a->id, serverOnly:true))->handle(app(ShopKeywordExposureAnalyzer::class));
        $this->assertSame(0, $item->fresh()->rank);
        $a->items()->create(['kind'=>'combo','source'=>'combo','keyword'=>'다음 컵']);
        $batch = Cache::lock('shop-exposure:'.$a->id, 30); $batch->get();
        $w->recover($a);
        $this->assertTrue(ShopKeywordExposureAnalyzer::usesServerFallback($a));
        $batch->release(); $w->recover($a);
        $this->assertFalse(ShopKeywordExposureAnalyzer::usesServerFallback($a));
        Http::fake(['*'=>Http::response('must not fetch')]);
        (new ShopKeywordCheckJob($a->id, serverOnly:true))->handle(app(ShopKeywordExposureAnalyzer::class));
        Http::assertNothingSent();
    }

    public function test_extension_queue_recovers_absence_and_unavailable_event_dispatches_for_admin_analysis(): void
    {
        Cache::flush(); Queue::fake(); config(['queue.default'=>'database']);
        $u = User::factory()->create(['role'=>'operator','api_scopes'=>['shop_keyword']]);
        $a = ShopKeywordAnalysis::create(['user_id'=>$u->id,'core_keyword'=>'컵','status'=>'checking','created_via'=>'api']);
        $a->items()->create(['kind'=>'combo','source'=>'combo','keyword'=>'컵']);
        [, $token] = \App\Models\ExtToken::issue($u);
        $this->withHeader('Authorization','Bearer '.$token);
        $this->postJson('/api/ext/shop-keyword/'.$a->id.'/collector-event',['kind'=>'unavailable'])->assertJsonPath('server_fallback',true);
        Queue::assertPushed(ShopKeywordCheckJob::class, fn ($job) => $job->serverOnly);
        $this->getJson('/api/ext/shop-keyword/check-queue')->assertJsonCount(1,'data.items');
        $this->assertFalse(ShopKeywordExposureAnalyzer::usesServerFallback($a));
        $a->update(['created_via'=>'admin']);
        $this->actingAs($u)->postJson(route('admin.shop-keyword.collector-event',$a),['kind'=>'unavailable'])->assertJsonPath('server_fallback',true);
        Queue::assertPushed(ShopKeywordCheckJob::class,2);
    }

    public function test_paused_and_recent_browsers_are_not_taken_and_real_blocks_are_not_cleared(): void
    {
        Cache::flush(); Queue::fake(); config(['queue.default'=>'database']);
        $a = ShopKeywordAnalysis::create(['user_id'=>User::factory()->create()->id,'core_keyword'=>'컵','status'=>'paused','collection_source'=>'확장 수집기','collection_seen_at'=>now()->subMinutes(2)]);
        $a->forceFill(['collection_source'=>'확장 수집기','collection_seen_at'=>now()->subMinutes(2)])->save();
        $a->items()->create(['kind'=>'combo','source'=>'combo','keyword'=>'컵']);
        $w = app(ShopBrowserWatchdog::class);
        $this->assertSame(0,$w->scan());
        $this->assertFalse($w->takeover($a));
        Cache::forever('shop-exposure:server-fallback:'.$a->id,true);
        $w->recover($a);
        $this->assertTrue(ShopKeywordExposureAnalyzer::usesServerFallback($a));
        Queue::assertNothingPushed();
    }
}
