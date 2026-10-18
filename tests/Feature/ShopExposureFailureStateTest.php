<?php
namespace Tests\Feature;

use App\Domain\Shopping\NaverShopExposureService;
use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordAnalysisItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ShopExposureFailureStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_block_errors_keep_reason_and_success_clears_previous_failure(): void
    {
        config(['rankfree.shopping.exposure.fetch_delay_ms' => 0, 'services.slack.notifications.bot_user_oauth_token' => null]);
        foreach (['http_403', 'http_429', 'captcha', 'http_503', 'http_exception', 'collector_busy'] as $error) {
            $a = ShopKeywordAnalysis::create(['user_id' => User::factory()->create()->id,
                'core_keyword' => '테스트', 'product_url' => 'https://smartstore.naver.com/x/products/111',
                'status' => 'checking', 'check_method' => 'api', 'threshold' => 5, 'combo_count' => 2]);
            foreach (['a', 'b'] as $keyword) ShopKeywordAnalysisItem::create(['analysis_id' => $a->id, 'kind' => 'combo', 'source' => 'combo', 'keyword' => $keyword]);
            $service = \Mockery::mock(NaverShopExposureService::class)->makePartial();
            $service->shouldReceive('exposureBySlotApi')->once()->andReturn(['error' => $error, 'blocked' => true]);
            $service->shouldReceive('exposureBySlotApi')->once()->andReturn(['rank' => 1, 'blocked' => false]);
            $this->app->instance(NaverShopExposureService::class, $service);
            $analyzer = $this->app->make(ShopKeywordExposureAnalyzer::class);
            $r = $analyzer->checkBatch($a, 1);
            $this->assertSame(in_array($error, ['http_403', 'http_429', 'captcha']) ? 'blocked' : 'error', $r['status']);
            $this->assertSame($error, $r['error']);
            $this->assertSame($error, $analyzer->progress($a->refresh())['error']);
            $this->assertSame(2, $r['remaining']);
            $r = $analyzer->checkBatch($a, 1);
            $this->assertSame('checking', $r['status']);
            $this->assertNull($r['error']);
            $this->assertNull(Cache::get('shop-exposure:error:'.$a->id));
        }
    }
}
