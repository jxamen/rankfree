<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopAnalysisReuse;
use App\Models\ApiKey;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BoostAnalysisReuseTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): ShopKeywordAnalysis
    {
        Http::fake();
        $user = User::factory()->create(['api_scopes' => ['shop_keyword']]);
        [, $key] = ApiKey::issue($user, 'test', ['shop_keyword'], null, null, null);
        $this->withToken($key);
        $a = ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '다른 핵심',
            'product_url' => 'https://smartstore.naver.com/test/products/1234567?option=1', 'product_id' => '1234567',
            'product_title' => '시험 상품 정보', 'mall_name' => '시험 상점', 'status' => 'target_reached', 'threshold' => 5,
            'created_at' => now()->subYear()]);
        $a->forceFill(['created_at' => now()->subYear()])->save();
        $a->items()->create(['kind' => 'token', 'source' => 'title', 'keyword' => '시험']);
        $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '시험 유입', 'rank' => 3, 'checked_at' => now()->subYear()]);
        $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '미확인', 'rank' => null]);
        return $a;
    }

    private function requestOrder(int $id, array $extra = [])
    {
        return $this->postJson('/api/v1/shop-keywords', array_merge(['source_order' => 'boosting:'.$id,
            'core_keyword' => '새 핵심', 'product' => 'http://m.smartstore.naver.com/test/products/1234567/?utm_source=test&option=1',
            'order_start_date' => now()->toDateString(), 'order_end_date' => now()->addDay()->toDateString()], $extra));
    }

    public function test_two_orders_reuse_by_url_without_date_or_keyword_limit_and_no_search(): void
    {
        $source = $this->fixture();
        $first = $this->requestOrder(1001)->assertOk()->assertJsonPath('reuse_mode', 'copied');
        $second = $this->requestOrder(1002)->assertOk()->assertJsonPath('reuse_mode', 'copied');
        $second->assertJsonPath('analysis.source_date', now()->subYear()->toDateString());
        $this->assertNotSame($first->json('analysis.id'), $second->json('analysis.id'));
        $a = ShopKeywordAnalysis::findOrFail($second->json('analysis.id'));
        $this->assertSame('시험 상품 정보', $second->json('analysis.product.title'));
        $this->assertSame(0, $a->combos()->whereNull('rank')->count());
        $this->assertSame(1, $a->combos()->count());
        $a->items()->delete();
        $this->assertSame(3, $source->items()->count());
        $this->assertSame(2, ShopKeywordAnalysis::find($first->json('analysis.id'))->items()->count());
        Http::assertNothingSent();
    }

    public function test_manual_import_missing_is_read_only_and_existing_order_is_not_overwritten(): void
    {
        $this->fixture();
        $one = $this->requestOrder(1001, ['reuse_only' => true])->assertOk()->assertJsonPath('reuse_mode', 'copied');
        $this->requestOrder(1002, ['reuse_only' => true])->assertJsonPath('reuse_mode', 'copied');
        $this->requestOrder(1003, ['reuse_only' => true, 'product' => 'https://smartstore.naver.com/other/products/1234567?option=1'])
            ->assertOk()->assertJsonPath('reuse_mode', 'missing')->assertJsonPath('analysis', null);
        $this->requestOrder(1001, ['reuse_only' => true])->assertJsonPath('reuse_mode', 'existing')->assertJsonPath('analysis.id', $one->json('analysis.id'));
        $this->assertSame(3, ShopKeywordAnalysis::count());
        Http::assertNothingSent();
    }

    public function test_significant_query_prevents_reuse_and_force_new_affects_only_that_order(): void
    {
        $source = $this->fixture();
        $one = $this->requestOrder(1001)->json('analysis.id');
        $two = $this->requestOrder(1002)->json('analysis.id');
        $this->requestOrder(1003, ['product' => 'https://smartstore.naver.com/test/products/1234567?option=2'])
            ->assertCreated()->assertJsonPath('reused', false)->assertJsonPath('analysis.status', 'pending');
        $fresh = $this->requestOrder(1001, ['force_new' => true])->assertCreated()->assertJsonPath('analysis.status', 'pending');
        $this->assertNotSame($one, $fresh->json('analysis.id'));
        $this->assertSame('done', ShopKeywordAnalysis::find($two)->status);
        $this->assertSame('target_reached', $source->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_other_users_completed_analysis_is_not_reused(): void
    {
        $source = $this->fixture();
        $source->update(['user_id' => User::factory()->create()->id]);
        $this->requestOrder(1001, ['reuse_only' => true])->assertJsonPath('reuse_mode', 'missing');
    }

    public function test_admin_reuse_notice_and_fresh_button_only_replace_the_selected_order(): void
    {
        $source = $this->fixture();
        $owner = $source->user;
        $owner->update(['role' => 'super']);
        $id = $this->requestOrder(1001)->json('analysis.id');
        $other = $this->requestOrder(1002)->json('analysis.id');
        $this->actingAs($owner)->get(route('admin.shop-keyword.show', $id))
            ->assertOk()->assertSee('이전 분석 재사용')->assertSee('새로 분석');
        $response = $this->post(route('admin.shop-keyword.store'), ['fresh_order_analysis' => $id])->assertRedirect();
        $this->assertStringNotContainsString('/'.$id.'?', $response->headers->get('Location'));
        $this->assertSame('done', ShopKeywordAnalysis::find($other)->status);
        $this->actingAs(User::factory()->create(['role' => 'super']))
            ->post(route('admin.shop-keyword.store'), ['fresh_order_analysis' => $id])->assertForbidden();
    }
}
