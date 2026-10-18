<?php
namespace Tests\Feature;

use App\Models\{AppSetting, ShopKeywordAnalysis, ShopKeywordAnalysisItem, User};
use App\Services\ShopExposureBlockAlert;
use App\Domain\Shopping\NaverShopExposureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ShopKeywordIntervalTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(): ShopKeywordAnalysis
    {
        $user = User::factory()->create(['role' => 'operator']);
        $this->actingAs($user);
        return ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '테스트', 'threshold' => 5, 'status' => 'paused']);
    }

    public function test_saved_base_applies_to_both_policies_and_daily_reset_preserves_counts(): void
    {
        Cache::flush();
        $a = $this->analysis();
        $this->assertSame(4000, ShopExposureBlockAlert::baseInterval());
        $this->postJson(route('admin.shop-keyword.interval', $a), ['seconds' => '12.5', 'collection_mode' => 'fetch'])->assertOk()->assertJsonPath('interval_ms', 12500);
        $this->assertSame('12500', AppSetting::read('shop.exposure.interval_ms'));
        $this->assertSame('fetch', ShopExposureBlockAlert::collectionMode());
        $this->assertSame(12500, Cache::get(NaverShopExposureService::policyKey())['gap']);
        $this->assertSame(12500, ShopExposureBlockAlert::interval('127.0.0.1'));
        $countKey = 'shop-block:'.hash('sha256', '127.0.0.1').':'.now('Asia/Seoul')->format('Y-m-d');
        Cache::put($countKey, 2);
        $this->assertSame(13100, ShopExposureBlockAlert::interval('127.0.0.1'));
        $this->postJson(route('admin.shop-keyword.reset-interval', $a))->assertOk()->assertJsonPath('interval_ms', 12500);
        $this->assertSame(2, Cache::get($countKey));
        $this->assertSame(12500, ShopExposureBlockAlert::interval('127.0.0.1'));
        $this->travel(1)->days();
        $this->assertSame(12500, ShopExposureBlockAlert::interval('127.0.0.1'));
    }

    public function test_continue_preserves_pending_keyword_results_and_statistics(): void
    {
        Cache::flush();
        $a = $this->analysis();
        $item = ShopKeywordAnalysisItem::create(['analysis_id' => $a->id, 'kind' => 'combo', 'source' => 'combo', 'keyword' => '같은 키워드']);
        Cache::put('shop-exposure:error:'.$a->id, 'http_403');
        Cache::put(NaverShopExposureService::policyKey(), ['until' => microtime(true) + 180, 'gap' => 5500, 'requests' => 15]);
        $this->postJson(route('admin.shop-keyword.continue-now', $a))->assertOk();
        $this->assertSame('checking', $a->fresh()->status);
        $this->assertSame('search', $a->fresh()->check_method);
        $this->assertNull($item->fresh()->rank);
        $this->assertFalse(Cache::has('shop-exposure:error:'.$a->id));
        $this->assertSame(['until' => 0, 'gap' => 5500, 'requests' => 15, 'last' => 0], Cache::get(NaverShopExposureService::policyKey()));
    }

    public function test_controls_render_with_saved_value_even_when_paused(): void
    {
        $a = $this->analysis();
        ShopKeywordAnalysisItem::create(['analysis_id' => $a->id, 'kind' => 'combo', 'source' => 'combo', 'keyword' => '테스트']);
        AppSetting::write('shop.exposure.interval_ms', '12500');
        $this->get(route('admin.shop-keyword.show', $a))->assertOk()
            ->assertSee('간격(초)')->assertSee('value="12.5"', false)->assertSee('id="sk-stop"', false)->assertDontSee('id="sk-continue-now"', false)->assertDontSee('id="sk-resume"', false);
    }

    public function test_validation_and_admin_ownership(): void
    {
        $a = $this->analysis();
        foreach (['0.9', '30.1', '1.25', 'abc'] as $seconds) {
            $this->postJson(route('admin.shop-keyword.interval', $a), compact('seconds'))->assertUnprocessable();
        }
        foreach (['1.0', '30.0'] as $seconds) {
            $this->postJson(route('admin.shop-keyword.interval', $a), compact('seconds'))->assertOk();
        }
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $this->postJson(route('admin.shop-keyword.continue-now', $a))->assertForbidden();
        $this->postJson(route('admin.shop-keyword.interval', $a), ['seconds' => 4])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'user']));
        $this->postJson(route('admin.shop-keyword.continue-now', $a))->assertForbidden();
        $this->postJson(route('admin.shop-keyword.interval', $a), ['seconds' => 4])->assertForbidden();
    }
}
