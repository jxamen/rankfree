<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopExposureRunLock;
use App\Models\{ApiKey, ExtToken, ShopKeywordAnalysis, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopBatchAutostartTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_api_start_is_immediately_claimable_after_an_empty_queue_and_completed_lock(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        config(['rankfree.searchad.api_key' => '', 'rankfree.searchad.accounts' => [], 'rankfree.shopping.exposure.max_combos' => 20]);
        $user = User::factory()->create(['api_scopes' => ['shop_keyword']]);
        [, $api] = ApiKey::issue($user, 'batch', ['shop_keyword'], null, null, null);
        [, $ext] = ExtToken::issue($user);
        $old = ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '직전', 'created_via' => 'api', 'status' => 'checking', 'check_method' => 'search', 'threshold' => 5]);
        $old->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '남은 조합']);
        $lock = app(ShopExposureRunLock::class);
        $this->assertTrue($lock->acquire('ip:127.0.0.1', 'screen:previous', $old->id)['ok']);
        $old->update(['status' => 'target_reached']);
        $this->withToken($ext)->getJson('/api/ext/shop-keyword/check-queue?runner=batch')->assertOk()->assertJsonCount(0, 'data.items');

        $id = $this->withToken($api)->postJson('/api/v1/shop-keywords', [
            'core_keyword' => '비타민', 'product' => 'https://smartstore.naver.com/test/products/12345678',
            'product_info' => ['title' => '비타민 고함량 스틱 건강 영양제', 'seller_tags' => ['고함량', '영양제']],
        ])->assertCreated()->json('analysis.id');
        $this->assertSame('api', ShopKeywordAnalysis::findOrFail($id)->created_via);
        $this->withToken($ext)->getJson('/api/ext/shop-keyword/check-queue?runner=batch&limit=1')
            ->assertOk()->assertJsonPath('data.analysis_id', $id)->assertJsonCount(1, 'data.items');
        $this->assertSame('확장 수집기', ShopKeywordAnalysis::findOrFail($id)->collection_source);
    }

    public function test_active_other_analysis_keeps_ip_lock_and_paused_analysis_is_not_claimed(): void
    {
        $user = User::factory()->create(['api_scopes' => ['shop_keyword']]);
        [, $ext] = ExtToken::issue($user);
        $holder = ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '진행', 'status' => 'checking', 'check_method' => 'search']);
        app(ShopExposureRunLock::class)->acquire('ip:127.0.0.1', 'screen:active', $holder->id);
        $next = ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '다음', 'created_via' => 'api', 'status' => 'checking', 'check_method' => 'search', 'threshold' => 5]);
        $next->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '다음 검색']);
        $this->withToken($ext)->getJson('/api/ext/shop-keyword/check-queue?runner=batch')->assertOk()->assertJsonPath('data.busy', true);
        $next->update(['status' => 'paused']);
        $holder->update(['status' => 'done']);
        $this->withToken($ext)->getJson('/api/ext/shop-keyword/check-queue?runner=batch')->assertOk()->assertJsonCount(0, 'data.items');
    }
}
