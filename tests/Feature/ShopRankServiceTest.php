<?php

namespace Tests\Feature;

use App\Domain\Shopping\NaverShoppingRankService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopRankServiceTest extends TestCase
{
    private function svc(): NaverShoppingRankService
    {
        return app(NaverShoppingRankService::class);
    }

    public function test_resolve_target_parses_urls_and_mall(): void
    {
        $s = $this->svc();

        $this->assertSame('1234567', $s->resolveTarget('https://smartstore.naver.com/mystore/products/1234567')['product_id']);
        $this->assertSame('9876543', $s->resolveTarget('https://brand.naver.com/foo/products/9876543?x=1')['product_id']);
        $this->assertSame('12345678', $s->resolveTarget('https://search.shopping.naver.com/catalog/12345678')['product_id']);

        $mall = $s->resolveTarget('멋진스토어');
        $this->assertSame('mall', $mall['type']);
        $this->assertSame('멋진스토어', $mall['mall_name']);
        $this->assertSame('', $mall['product_id']);
    }

    public function test_check_rank_finds_by_product_id(): void
    {
        config(['rankfree.shopping.api_keys' => [['id' => 'a', 'secret' => 'b']],
            'rankfree.shopping.display' => 3, 'rankfree.shopping.max_pages' => 1, 'rankfree.shopping.page_delay_ms' => 0]);

        Http::fake(['*/v1/search/shop.json*' => Http::response(['total' => 500, 'items' => [
            ['productId' => '111', 'title' => 'A', 'mallName' => '몰1', 'lprice' => '1000', 'link' => 'http://x/111', 'image' => ''],
            ['productId' => '222', 'title' => 'B <b>세일</b>', 'mallName' => '몰2', 'lprice' => '2000', 'link' => 'http://x/222', 'image' => ''],
        ]], 200)]);

        $res = $this->svc()->checkRank('강아지 사료', ['type' => 'product', 'product_id' => '222', 'mall_name' => '', 'url' => '']);

        $this->assertTrue($res['found']);
        $this->assertSame(2, $res['rank']);
        $this->assertSame('B 세일', $res['title']);   // 태그 제거
        $this->assertSame(2000, $res['price']);
        $this->assertSame(500, $res['total']);
    }

    public function test_check_rank_finds_by_mall_name(): void
    {
        config(['rankfree.shopping.api_keys' => [['id' => 'a', 'secret' => 'b']],
            'rankfree.shopping.display' => 3, 'rankfree.shopping.max_pages' => 1, 'rankfree.shopping.page_delay_ms' => 0]);

        Http::fake(['*/v1/search/shop.json*' => Http::response(['total' => 10, 'items' => [
            ['productId' => '111', 'title' => 'A', 'mallName' => '다른몰', 'lprice' => '1000', 'link' => 'http://x/111', 'image' => ''],
            ['productId' => '222', 'title' => 'B', 'mallName' => '멋진스토어', 'lprice' => '2000', 'link' => 'http://x/222', 'image' => ''],
        ]], 200)]);

        $res = $this->svc()->checkRank('사료', ['type' => 'mall', 'product_id' => '', 'mall_name' => '멋진스토어', 'url' => '']);

        $this->assertTrue($res['found']);
        $this->assertSame(2, $res['rank']);
    }

    public function test_check_rank_rotates_key_on_429(): void
    {
        config(['rankfree.shopping.api_keys' => [['id' => 'a', 'secret' => 'b'], ['id' => 'c', 'secret' => 'd']],
            'rankfree.shopping.display' => 2, 'rankfree.shopping.max_pages' => 1, 'rankfree.shopping.page_delay_ms' => 0]);

        // 첫 키(a)는 429, 두 번째 키(c)는 정상 응답
        Http::fake(function ($request) {
            if ($request->header('X-Naver-Client-Id')[0] === 'a') {
                return Http::response(['errorMessage' => 'rate'], 429);
            }

            return Http::response(['total' => 3, 'items' => [
                ['productId' => '999', 'title' => 'Z', 'mallName' => 'm', 'lprice' => '500', 'link' => 'http://x/999', 'image' => ''],
            ]], 200);
        });

        $res = $this->svc()->checkRank('키워드', ['type' => 'product', 'product_id' => '999', 'mall_name' => '', 'url' => '']);

        $this->assertTrue($res['blocked']);   // 첫 키 막힘 감지
        $this->assertTrue($res['found']);      // 두 번째 키로 발견
        $this->assertSame(1, $res['rank']);
    }

    public function test_check_rank_not_found_returns_zero(): void
    {
        config(['rankfree.shopping.api_keys' => [['id' => 'a', 'secret' => 'b']],
            'rankfree.shopping.display' => 2, 'rankfree.shopping.max_pages' => 1, 'rankfree.shopping.page_delay_ms' => 0]);

        Http::fake(['*/v1/search/shop.json*' => Http::response(['total' => 2, 'items' => [
            ['productId' => '111', 'title' => 'A', 'mallName' => 'm', 'lprice' => '1', 'link' => 'x', 'image' => ''],
        ]], 200)]);

        $res = $this->svc()->checkRank('키워드', ['type' => 'product', 'product_id' => '000', 'mall_name' => '', 'url' => '']);

        $this->assertFalse($res['found']);
        $this->assertSame(0, $res['rank']);
    }
}
