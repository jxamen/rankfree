<?php
namespace Tests\Feature;
use App\Domain\Shopping\NaverShopExposureService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class ShopSerpPacingTest extends TestCase
{
    public function test_block_cools_down_other_keywords_without_retrying(): void
    {
        Cache::flush(); Http::fake(['*' => Http::response('', 403)]);
        $s = new NaverShopExposureService();
        $this->assertSame('http_403', $s->exposure('a', [])['error']);
        $this->assertSame('collector_cooldown', $s->exposure('b', [])['error']);
        Http::assertSentCount(1);
        $this->assertSame(3200, Cache::get('shop-serp:server-policy')['gap']);
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
}
