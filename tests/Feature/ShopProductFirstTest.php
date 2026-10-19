<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopProductInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopProductFirstTest extends TestCase
{
    use RefreshDatabase;

    private string $api;

    private string $ext;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response('', 200)]);
        config(['rankfree.searchad.api_key' => '', 'rankfree.searchad.accounts' => []]);
        $this->owner = User::factory()->create(['api_scopes' => ['shop_keyword']]);
        [, $this->api] = ApiKey::issue($this->owner, 'batch', ['shop_keyword'], null, null, null);
        [, $this->ext] = ExtToken::issue($this->owner);
    }

    private function start(string $pid = '111'): int
    {
        return $this->withToken($this->api)->postJson('/api/v1/shop-keywords', [
            'core_keyword' => '비타민', 'product' => 'https://smartstore.naver.com/test/products/'.$pid,
            'order_start_date' => now()->toDateString(), 'order_end_date' => now()->toDateString(),
        ])->assertSuccessful()->json('analysis.id');
    }

    private function queue()
    {
        return $this->withToken($this->ext)->getJson('/api/ext/shop-keyword/product-queue')->assertOk();
    }

    private function productFailure(int $id): void
    {
        $this->withToken($this->ext)->postJson('/api/ext/shop-keyword/'.$id.'/collector-event', [
            'stage' => 'product', 'kind' => 'client_error', 'message' => '네이버 제한 화면',
        ])->assertOk();
    }

    public function test_detail_must_complete_before_any_tokens_combinations_or_keyword_claims(): void
    {
        ShopProductInfo::create(['user_id' => $this->owner->id, 'channel_product_id' => '111',
            'title' => '오래된 상품 제목', 'collected_at' => now()->subDays(2)]);
        $id = $this->start();
        $a = ShopKeywordAnalysis::findOrFail($id);
        $this->assertSame('pending', $a->status);
        $this->assertSame(0, $a->items()->count());
        Http::assertNothingSent();
        $this->queue()->assertJsonPath('data.items.0.analysis_id', $id);
        $this->queue()->assertJsonCount(0, 'data.items'); // another runner cannot duplicate the lease
        // Even a leftover legacy combination cannot bypass the detail gate.
        $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '미리 만든 조합']);
        $this->withToken($this->ext)->getJson('/api/ext/shop-keyword/check-queue')->assertJsonCount(0, 'data.items');
        $this->withToken($this->ext)->postJson('/api/ext/shop-keyword/'.$id.'/product-info', [
            'info' => ['channel_product_id' => '222', 'title' => '다른 상품'],
        ])->assertUnprocessable();
        $this->withToken($this->ext)->postJson('/api/ext/shop-keyword/'.$id.'/product-info', [
            'info' => ['channel_product_id' => '111', 'title' => '비타민 고함량 스틱 건강 영양제',
                'seller_tags' => ['고함량영양제'], 'category' => '건강', 'delivery_fee' => 0],
        ])->assertOk()->assertJsonPath('data.check_pending', true);
        $a->refresh();
        $this->assertSame('completed', $a->product_collection['state']);
        $this->assertTrue($a->tokens()->where('source', 'seller_tag')->where('keyword', '고함량영양제')->exists());
        $this->withToken($this->ext)->getJson('/api/ext/shop-keyword/check-queue?limit=1')
            ->assertOk()->assertJsonPath('data.analysis_id', $id)->assertJsonCount(1, 'data.items');
        $this->productFailure($id); // a delayed error must not undo successful collection
        $this->assertSame('completed', $a->fresh()->product_collection['state']);
    }

    public function test_three_failures_expose_reason_and_do_not_starve_the_next_item_and_explicit_start_retries(): void
    {
        $id = $this->start();
        $next = $this->start('222');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->queue();
            $this->productFailure($id);
            $this->assertSame($attempt, ShopKeywordAnalysis::find($id)->product_collection['attempts']);
            if ($attempt < 3) {
                $this->queue()->assertJsonCount(0, 'data.items');
                $this->travel(30)->seconds();
            }
        }
        $this->withToken($this->api)->getJson('/api/v1/shop-keywords/'.$id)->assertOk()
            ->assertJsonPath('analysis.status', 'failed')
            ->assertJsonPath('analysis.product_collection.message', '네이버 제한 화면');
        $this->assertSame('collecting', ShopKeywordAnalysis::find($next)->product_collection['state']);
        $this->queue()->assertJsonCount(0, 'data.items');
        $this->assertSame($id, $this->start());
        $this->assertSame(0, ShopKeywordAnalysis::find($id)->product_collection['attempts']);
    }

    public function test_expired_leases_retry_after_worker_loss_and_eventually_fail(): void
    {
        $id = $this->start();
        for ($i = 0; $i < 3; $i++) {
            $this->queue()->assertJsonPath('data.items.0.analysis_id', $id);
            $this->travel(91)->seconds();
        }
        $this->queue()->assertJsonCount(0, 'data.items');
        $this->assertSame('failed', ShopKeywordAnalysis::find($id)->status);
        $other = User::factory()->create(['api_scopes' => ['shop_keyword']]);
        [, $token] = ExtToken::issue($other);
        $this->withToken($token)->getJson('/api/ext/shop-keyword/product-queue')->assertJsonCount(0, 'data.items');
        $this->withToken($token)->postJson('/api/ext/shop-keyword/'.$id.'/product-info', ['info' => []])->assertForbidden();
    }

    public function test_reused_search_title_without_detail_is_gated_and_paused_work_is_left_paused(): void
    {
        $a = ShopKeywordAnalysis::create(['user_id' => $this->owner->id, 'core_keyword' => '비타민',
            'product_id' => '111', 'product_url' => 'https://smartstore.naver.com/test/products/111',
            'product_title' => '검색 제목만 있음', 'created_via' => 'api', 'status' => 'checking', 'check_method' => 'search']);
        $this->assertSame($a->id, $this->start());
        $this->assertSame('pending', $a->fresh()->product_collection['state']);
        $this->queue();
        $a->refresh()->update(['status' => 'paused']);
        $this->productFailure($a->id);
        $this->assertSame('paused', $a->fresh()->status);
        $this->queue()->assertJsonCount(0, 'data.items');
        $this->withToken($this->ext)->postJson('/api/ext/shop-keyword/'.$a->id.'/product-info', [
            'info' => ['channel_product_id' => '111', 'title' => '비타민 고함량'],
        ])->assertConflict();
        $this->assertSame($a->id, $this->start());
        $this->assertSame('pending', $a->fresh()->status);
    }
}
