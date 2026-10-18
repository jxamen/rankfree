<?php
namespace Tests\Feature;

use App\Domain\Shopping\ShopKeywordClaims;
use App\Models\{AppSetting, ApiKey, ShopKeywordAnalysis, ShopKeywordAnalysisItem, User};
use App\Services\ShopExposureBlockAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

class ShopParallelClaimsTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(): ShopKeywordAnalysis
    {
        Cache::flush();
        $u = User::factory()->create(['role' => 'operator', 'api_scopes' => ['shop_keyword']]);
        $this->actingAs($u);
        $a = ShopKeywordAnalysis::create(['user_id'=>$u->id, 'core_keyword'=>'병렬', 'threshold'=>5, 'status'=>'checking', 'check_method'=>'search', 'combo_count'=>8]);
        foreach (range(1,8) as $i) $a->items()->create(['kind'=>'combo','source'=>'combo','keyword'=>'키워드 '.$i]);
        return $a;
    }

    public function test_claims_are_disjoint_across_runners_and_expire_after_two_minutes(): void
    {
        $a = $this->analysis();
        $claims = app(ShopKeywordClaims::class);
        $one = $claims->claim($a, 'one', 4);
        $two = $claims->claim($a, 'two', 4);
        $this->assertCount(4, $one);
        $this->assertCount(4, $two);
        $this->assertEmpty(array_intersect($one->pluck('id')->all(), $two->pluck('id')->all()));
        $this->assertCount(0, $claims->claim($a, 'three', 4));
        $this->travel(119)->seconds();
        $this->assertCount(0, $claims->claim($a, 'three', 4));
        $this->travel(2)->seconds();
        $new = $claims->claim($a, 'three', 4);
        $this->assertSame($one->pluck('id')->all(), $new->pluck('id')->all());
        $item = ShopKeywordAnalysisItem::find($one[0]['id']);
        $this->assertSame(409, $claims->save($a,$item,'one',$one[0]['claim_token'],fn()=>abort(500))->status());
    }

    public function test_pending_batch_policy_reads_do_not_claim_and_saves_require_own_token(): void
    {
        $a = $this->analysis();
        // The legacy default must not cap this browser's requested concurrency.
        AppSetting::write('shop.exposure.parallel_tabs','1');
        $url = route('admin.shop-keyword.pending',$a).'?runner=page&limit=4';
        $items = $this->getJson($url)->assertOk()->assertJsonCount(4,'data.items')->json('data.items');
        $this->getJson($url.'&policy_only=1')->assertOk()->assertJsonCount(0,'data.items');
        $save = route('admin.shop-keyword.check-html',$a);
        $body = ['runner'=>'page','item_id'=>$items[0]['id'],'claim_token'=>$items[1]['claim_token'],'html'=>''];
        $this->postJson($save,$body)->assertConflict();
        $body['claim_token']=$items[0]['claim_token'];
        $this->postJson($save,$body)->assertOk();
        $this->assertSame(0,ShopKeywordAnalysisItem::find($items[0]['id'])->rank);
        $next = $this->getJson($url)->assertOk()->json('data.items');
        $this->assertEmpty(array_intersect(array_column($items,'id'),array_column($next,'id')));
    }

    public function test_settings_validate_persist_and_recalculate_current_target_in_both_directions(): void
    {
        $a=$this->analysis();
        $a->update(['order_days'=>2,'keyword_target'=>2,'status'=>'target_reached']);
        foreach ($a->combos()->limit(2)->get() as $item) $item->update(['rank'=>1]);
        $url=route('admin.shop-keyword.interval',$a);
        $this->postJson($url,['seconds'=>4,'parallel_tabs'=>4,'tab_open_seconds'=>'0.5','keywords_per_day'=>100])->assertOk();
        $this->assertSame(['parallel_reduced'=>false,'parallel_tabs'=>1,'tab_open_interval_ms'=>3000],ShopExposureBlockAlert::parallelSettings());
        $this->assertSame(100,ShopExposureBlockAlert::keywordsPerDay());
        $this->assertSame(200,$a->fresh()->keyword_target);
        $this->assertSame('checking',$a->fresh()->status);
        // The lower target is already satisfied.
        $a->update(['order_days'=>1]);
        foreach (range(9,12) as $i) $a->items()->create(['kind'=>'combo','source'=>'combo','keyword'=>'추가 '.$i,'rank'=>1]);
        foreach ($a->combos()->get() as $item) $item->update(['rank'=>1]);
        $this->postJson($url,['seconds'=>4,'keywords_per_day'=>10])->assertOk();
        $this->assertSame('target_reached',$a->fresh()->status);
        foreach ([['parallel_tabs'=>5],['parallel_tabs'=>0],['tab_open_seconds'=>'0.25'],['tab_open_seconds'=>61],['keywords_per_day'=>9],['keywords_per_day'=>501]] as $invalid) {
            $this->postJson($url,['seconds'=>4]+$invalid)->assertUnprocessable();
        }
    }

    public function test_block_event_caps_server_claims_for_this_analysis_only(): void
    {
        $a=$this->analysis();
        AppSetting::write('shop.exposure.parallel_tabs','4');
        $this->postJson(route('admin.shop-keyword.collector-event',$a),['kind'=>'보안문자'])->assertOk();
        $this->getJson(route('admin.shop-keyword.pending',$a).'?runner=page&limit=4')->assertOk()
            ->assertJsonPath('data.parallel_tabs',1)->assertJsonPath('data.parallel_reduced',true)->assertJsonCount(1,'data.items');
        $this->assertSame(4,ShopExposureBlockAlert::parallelSettings($a->id+1)['parallel_tabs']);
    }

    public function test_heartbeat_renews_only_live_reservations_owned_by_runner(): void
    {
        $a=$this->analysis();
        $claims=app(ShopKeywordClaims::class);
        $first=$claims->claim($a,'one',4);
        $claims->claim($a,'two',4);
        $this->travel(90)->seconds();
        $claims->renew($a,'one');
        $this->travel(31)->seconds();
        $available=$claims->claim($a,'three',4);
        $this->assertCount(4,$available);
        $this->assertEmpty(array_intersect($first->pluck('id')->all(),$available->pluck('id')->all()));
        $this->travel(90)->seconds();
        $claims->renew($a,'one'); // expired reservations cannot be revived
        $this->assertSame($first->pluck('id')->all(),$claims->claim($a,'four',4)->pluck('id')->all());
    }

    public function test_new_api_analysis_uses_saved_daily_target(): void
    {
        Http::fake();
        $a=$this->analysis();
        AppSetting::write('shop.exposure.keywords_per_day','123');
        [, $key]=ApiKey::issue($a->user,'test',['shop_keyword'],null,null,null);
        $this->withHeader('Authorization','Bearer '.$key)->postJson('/api/v1/shop-keywords',[
            'core_keyword'=>'새 목표','product'=>'https://smartstore.naver.com/x/products/123456',
            'order_start_date'=>'2026-10-18','order_end_date'=>'2026-10-20',
        ])->assertCreated()->assertJsonPath('analysis.keyword_target',369);
    }
}
