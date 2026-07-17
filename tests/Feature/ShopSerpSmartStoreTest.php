<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopSerpStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 쇼핑 수집은 스마트스토어(brand 포함) 상품만 저장한다 — 외부몰은 톡톡·판매자 정보가 없다. */
class ShopSerpSmartStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_smartstore_products_are_saved(): void
    {
        $n = app(ShopSerpStore::class)->save('린넨원피스', [
            ['title' => '스마트스토어 원피스', 'rank' => 1, 'price' => 10000, 'mallName' => '멜빈트',
                'link' => 'https://smartstore.naver.com/melvint/products/111'],
            ['title' => '브랜드스토어 원피스', 'rank' => 2, 'price' => 20000, 'mallName' => '타미',
                'link' => 'https://brand.naver.com/tommy/products/222'],
            ['title' => '외부몰 원피스', 'rank' => 3, 'price' => 30000, 'mallName' => '현대Hmall',
                'link' => 'https://cr.shopping.naver.com/adcr?x=abc'],
            ['title' => '광고인데 스토어핸들 있음', 'rank' => 4, 'price' => 40000, 'mallName' => '제이플로우',
                'link' => 'https://cr.shopping.naver.com/adcr?x=zzz', 'isAd' => true, 'storeId' => 'jflow'],
        ]);

        $this->assertSame(3, $n, '스마트스토어 2건 + storeId 있는 광고 1건만 저장');
        $titles = DB::table('shop_products')->pluck('title')->all();
        $this->assertContains('스마트스토어 원피스', $titles);
        $this->assertContains('브랜드스토어 원피스', $titles);
        $this->assertNotContains('외부몰 원피스', $titles, '외부몰은 저장하지 않는다');
        // 원본 순위는 그대로 — 외부몰이 빠져 번호가 띄엄띄엄해진다
        $this->assertSame([1, 2, 4], DB::table('keyword_shop_ranks')->orderBy('rnk')->pluck('rnk')->all());
    }
}
