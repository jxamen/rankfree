<?php

namespace Tests\Feature;

use App\Domain\Shopping\IntegratedShopCollector;
use App\Domain\Shopping\IntegratedShopParser;
use App\Http\Controllers\Api\IntegratedShopRankController;
use App\Http\Middleware\BlockProbeIps;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class IntegratedShopRankTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.file' => ['driver' => 'array']]);
        Cache::forgetDriver('file');
        Http::preventStrayRequests();
        $this->withoutMiddleware(BlockProbeIps::class);
        Route::post('/_integrated-test', IntegratedShopRankController::class);
    }

    private function html(?array $slots = null): string
    {
        $card = ['rank' => 91, 'nvMid' => '123', 'productName' => '<mark>상품</mark> "};" \\ test', 'sourceType' => 'AD', 'cardType' => 'AD_CARD', 'mallName' => '몰', 'productUrl' => ['pcUrl' => 'https://example.com/product', 'mobileUrl' => 'https://example.com/mobile']];
        $slots ??= [['slotType' => 'CARD', 'data' => $card], ['slotType' => 'HORIZONTAL_LIST', 'data' => [array_replace($card, ['sourceType' => 'SUPER_POINT', 'cardType' => 'SUPER_POINT_CARD']), array_replace($card, ['nvMid' => '456', 'sourceType' => 'SAS', 'cardType' => 'PRODUCT_CARD']), array_replace($card, ['nvMid' => '789', 'sourceType' => 'AITEMS_FORYOU_AD'])]]];

        return '<script>naver.search.ext.newshopping["shopping"]._INITIAL_STATE='.json_encode(['areaCode' => 'shp_gui', 'initProps' => ['pagedSlot' => [['page' => 1, 'slots' => $slots]]]]).'; other();</script>';
    }

    public function test_json_boundary_slots_ads_duplicates_and_markup(): void
    {
        $result = app(IntegratedShopParser::class)->parse($this->html());
        $this->assertSame('ok', $result['status']);
        $this->assertSame(['123', '123', '456', '789'], array_column($result['items'], 'nvMid'));
        $this->assertSame([1, 2, 3, 4], array_column($result['items'], 'rank'));
        $this->assertSame([null, 1, 2, null], array_column($result['items'], 'organic_rank'));
        $this->assertStringNotContainsString('<mark>', $result['items'][0]['productName']);
        $this->assertSame('ok', app(IntegratedShopParser::class)->parse(str_replace('["shopping"]', '.shopping', $this->html()))['status']);
    }

    public function test_absent_malformed_and_unknown_schema_are_distinct(): void
    {
        $parser = app(IntegratedShopParser::class);
        $this->assertSame('area_absent', $parser->parse('<html>검색결과 없음</html>')['status']);
        $this->assertSame('parse_failed', $parser->parse('newshopping changed')['status']);
        $this->assertSame('parse_failed', $parser->parse($this->html([['slotType' => 'UNKNOWN', 'data' => []]]))['status']);
        $this->assertSame('parse_failed', $parser->parse(substr($this->html(), 0, -30))['status']);
        $this->assertSame('ok', $parser->parse($this->html([]))['status']);
        $this->assertSame('ok', $parser->parse(str_replace('shp_gui', 'shp_dig', $this->html()))['status']);
    }

    public function test_all_pages_preserve_boundaries_and_source_ranks(): void
    {
        $html = $this->html();
        $start = strpos($html, '=') + 1;
        $state = json_decode(substr($html, $start, strpos($html, '; other();') - $start), true, 512, JSON_THROW_ON_ERROR);
        $state['areaCode'] = 'shp_fnd';
        $state['initProps']['pagedSlot'][] = ['page' => 2, 'slots' => $state['initProps']['pagedSlot'][0]['slots']];
        $result = app(IntegratedShopParser::class)->parse('<script>naver.search.ext.newshopping.shopping._INITIAL_STATE='.json_encode($state).';</script>');
        $this->assertSame('ok', $result['status']);
        $this->assertCount(8, $result['items']);
        $this->assertSame([1, 1, 1, 1, 2, 2, 2, 2], array_column($result['items'], 'page'));
        $this->assertSame([1, 2, 3, 4, 1, 2, 3, 4], array_column($result['items'], 'position'));
        $this->assertSame(range(1, 8), array_column($result['items'], 'rank'));
        $this->assertSame([null, 1, 2, null, null, 3, 4, null], array_column($result['items'], 'organic_rank'));
        $this->assertSame(array_fill(0, 8, 91), array_column($result['items'], 'source_rank'));
        $this->assertSame('parse_failed', app(IntegratedShopParser::class)->parse('')['status']);
    }

    public function test_cache_and_device_separation(): void
    {
        Http::fake(['*' => Http::response($this->html())]);
        $collector = app(IntegratedShopCollector::class);
        $this->assertFalse($collector->collect('test', 'pc')['cached']);
        $this->assertTrue($collector->collect('test', 'pc')['cached']);
        $this->assertFalse($collector->collect('test', 'mobile')['cached']);
        Http::assertSentCount(2);
    }

    public function test_block_stops_all_following_requests(): void
    {
        Http::fake(['*' => Http::response('blocked', 429)]);
        $response = $this->postJson('/_integrated-test', ['keywords' => ['one', 'two'], 'target' => ['type' => 'nvMid', 'value' => '123'], 'top' => 5]);
        $response->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(4, 'errors')->assertJsonPath('errors.3.status', 'blocked');
        Http::assertSentCount(1);
        $this->assertSame('blocked', app(IntegratedShopCollector::class)->collect('three', 'pc')['status']);
        Http::assertSentCount(1);
    }

    public function test_security_page_stops_and_normal_script_reference_does_not(): void
    {
        Http::fake(['*' => Http::sequence()->push('<html><h1>보안 확인</h1></html>')->push($this->html().'<script src="captcha.js"></script>')]);
        $this->assertSame('blocked', app(IntegratedShopCollector::class)->collect('one', 'pc')['status']);
        Cache::store('file')->flush();
        $this->assertSame('ok', app(IntegratedShopCollector::class)->collect('two', 'pc')['status']);
    }

    public function test_busy_never_sends_and_failed_fetch_never_becomes_zero_rank(): void
    {
        $lock = Cache::store('file')->lock('integrated-shop:collector', 30);
        $lock->get();
        $this->assertSame('busy', app(IntegratedShopCollector::class)->collect('one', 'pc')['status']);
        Http::assertNothingSent();
        $lock->release();
        Http::fake(['*' => Http::response('error', 500)]);
        $this->postJson('/_integrated-test', ['keywords' => ['one'], 'target' => ['type' => 'nvMid', 'value' => '123']])->assertOk()->assertJsonPath('data.0.pc.rank', null)->assertJsonPath('data.0.pc.status', 'fetch_failed')->assertJsonPath('data.0.pc.received_count', null)->assertJsonPath('data.0.pc.rank_status', 'collection_failed')->assertJsonPath('data.0.pc.rank_label', null);
    }

    public function test_successful_absence_reports_actual_count(): void
    {
        Http::fake(['*' => Http::response($this->html())]);
        $this->postJson('/_integrated-test', ['keywords' => ['absent'], 'target' => ['type' => 'nvMid', 'value' => 'missing']])->assertOk()->assertJsonPath('data.0.mobile.received_count', 4)->assertJsonPath('data.0.mobile.rank_status', 'outside')->assertJsonPath('data.0.mobile.rank_label', '4위 밖')->assertJsonCount(0, 'errors');
    }

    public function test_top_and_organic_matching_and_validation(): void
    {
        Http::fake(['*' => Http::response($this->html())]);
        $input = ['keywords' => ['one'], 'target' => ['type' => 'nvMid', 'value' => '456'], 'top' => 2, 'rank_by' => 'organic_rank'];
        $this->postJson('/_integrated-test', $input)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.pc.rank', 3)->assertJsonPath('data.0.pc.organic_rank', 2);
        $input['rank_by'] = 'rank';
        $this->postJson('/_integrated-test', $input)->assertJsonCount(0, 'data');
        $input['keywords'] = range(1, 6);
        $this->postJson('/_integrated-test', $input)->assertUnprocessable();
        $input['keywords'] = ['one'];
        $input['target']['type'] = 'url';
        $this->postJson('/_integrated-test', $input)->assertUnprocessable();
    }
}
