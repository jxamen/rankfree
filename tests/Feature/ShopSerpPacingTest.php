<?php
namespace Tests\Feature;
use App\Domain\Shopping\NaverShopExposureService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class ShopSerpPacingTest extends TestCase
{
    public function test_legacy_wait_is_capped_without_losing_statistics_and_success_clears_it(): void
    {
        Cache::flush();
        Cache::put(NaverShopExposureService::policyKey(), ['until' => microtime(true) + 1800, 'successes' => 8]);
        Http::fake(['*' => Http::response('<html></html>')]);
        $service = new NaverShopExposureService();
        $this->assertSame('collector_cooldown', $service->exposure('legacy', [])['error']);
        $state = Cache::get(NaverShopExposureService::policyKey());
        $this->assertEqualsWithDelta(microtime(true) + 180, $state['until'], 1);
        $this->assertSame(8, $state['successes']);
        Http::assertNothingSent();
        $state['until'] = microtime(true) - 1;
        Cache::put(NaverShopExposureService::policyKey(), $state);
        $this->assertFalse($service->exposure('legacy', [])['blocked']);
        $this->assertSame(0, Cache::get(NaverShopExposureService::policyKey())['until']);
    }

    public function test_block_cools_down_other_keywords_without_retrying(): void
    {
        Cache::flush(); Http::fake(['*' => Http::response('', 403)]);
        $s = new NaverShopExposureService();
        $this->assertSame('http_403', $s->exposure('a', [])['error']);
        $this->assertSame('collector_cooldown', $s->exposure('b', [])['error']);
        Http::assertSentCount(1);
        $this->assertSame(3300, Cache::get(NaverShopExposureService::policyKey())['gap']);
    }
    public function test_resume_block_renews_wait_and_is_logged(): void
    {
        Cache::flush();
        Cache::put(NaverShopExposureService::policyKey(), ['gap' => 3200, 'until' => microtime(true) - 1]);
        Http::fake(['*' => Http::response('', 403)]);
        \Illuminate\Support\Facades\Log::spy();
        $s = new NaverShopExposureService();
        $this->assertSame('http_403', $s->exposure('resume', [])['error']);
        $this->assertSame(3500, Cache::get(NaverShopExposureService::policyKey())['gap']);
        $this->assertGreaterThan(microtime(true) + 179, Cache::get(NaverShopExposureService::policyKey())['until']);
        $this->assertLessThanOrEqual(microtime(true) + 180, Cache::get(NaverShopExposureService::policyKey())['until']);
        $this->assertSame('collector_cooldown', $s->exposure('next', [])['error']);
        Http::assertSentCount(1);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->with('shop_serp_request', \Mockery::on(fn ($data) => $data['blocked_on_resume'] === true))->once();
    }
    public function test_success_does_not_change_interval(): void
    {
        Cache::flush(); Http::fake(['*' => Http::response('<html></html>')]);
        Cache::put(NaverShopExposureService::policyKey(), ['gap' => 3200, 'successes' => 19]);
        (new NaverShopExposureService())->exposure('success', []);
        $this->assertSame(3200, Cache::get(NaverShopExposureService::policyKey())['gap']);
    }
    public function test_captcha_is_never_cached_as_a_valid_result(): void
    {
        Cache::flush(); Http::fake(['*' => Http::response('<html>보안문자 입력</html>')]);
        $s = new NaverShopExposureService();
        $this->assertSame('captcha', $s->exposure('a', [])['error']);
        $this->assertSame('collector_cooldown', $s->exposure('a', [])['error']);
        Http::assertSentCount(1);
    }
    public function test_same_day_raw_html_is_reused_and_reparsed_for_each_target(): void
    {
        Cache::flush();
        $html = '<script>naver.search.ext.newshopping["shopping"]._INITIAL_STATE = '.json_encode(['initProps'=>['pagedSlot'=>[['slots'=>[['data'=>['sourceType'=>'SAS','channelProductId'=>'111','rank'=>1]]]]]]]).';</script>';
        Http::fake(['*' => Http::response($html)]);
        $s = new NaverShopExposureService();
        $this->assertSame(1, $s->exposure('a', ['id_kind'=>'channel','product_id'=>'111'])['rank']);
        $this->assertSame(0, $s->exposure('a', ['id_kind'=>'channel','product_id'=>'222'])['rank']);
        Http::assertSentCount(1);
    }
    public function test_gap_caps_resets_next_day_and_is_separate_for_server_ips(): void
    {
        Cache::flush();
        config(['rankfree.shopping.exposure.collector_ip' => '192.0.2.1']);
        $key = NaverShopExposureService::policyKey();
        Cache::put($key, ['gap' => 9900, 'day' => now('Asia/Seoul')->format('Y-m-d')]);
        Http::fake(['*' => Http::sequence()->push('', 429)->push('', 429)->push('<html></html>', 200)]);
        $s = new NaverShopExposureService();
        $this->assertSame(10000, $s->exposure('cap', [])['interval_ms']);
        config(['rankfree.shopping.exposure.collector_ip' => '192.0.2.2']);
        $this->assertSame(3300, $s->exposure('ip2', [])['interval_ms']);
        config(['rankfree.shopping.exposure.collector_ip' => '192.0.2.1']);
        $state = Cache::get($key);
        $state['until'] = 0;
        $state['last'] = 0;
        $state['day'] = now('Asia/Seoul')->subDay()->format('Y-m-d');
        Cache::put($key, $state);
        $this->assertSame(3000, $s->exposure('tomorrow', [])['interval_ms']);
    }
}
