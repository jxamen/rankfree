<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopSerpStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 쇼핑 수집은 스마트스토어(brand 포함) 상품만 저장한다.
 * 가격비교(카탈로그)는 판매처 묶음이라 저장하지 않고, 확장이 그 안의 '리뷰 있는 스마트스토어'로 바꿔 보낸다.
 * 자사몰·쿠팡·윈도(백화점/아울렛)는 톡톡·판매자 정보가 없어 분석에 못 쓴다.
 */
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
            // ── 아래는 모두 저장되면 안 된다 ──
            ['title' => '가격비교 원피스', 'rank' => 3, 'price' => 30000, 'mallName' => '네이버',
                'link' => 'https://search.shopping.naver.com/catalog/59726174371', 'isCatalog' => true],
            ['title' => '윈도 백화점 원피스', 'rank' => 4, 'price' => 40000, 'mallName' => '롯데백화점',
                'link' => 'https://shopping.naver.com/window-products/department/13486047963'],
            ['title' => '쿠팡 원피스', 'rank' => 5, 'price' => 50000, 'mallName' => '쿠팡',
                'link' => 'https://www.coupang.com/vp/products/123'],
            ['title' => '자사몰 원피스', 'rank' => 6, 'price' => 60000, 'mallName' => '자사몰',
                'link' => 'https://mystore.co.kr/product/7'],
            // 광고라 링크가 리다이렉트지만 스토어 핸들이 있으면 스마트스토어로 인정
            ['title' => '광고 스마트스토어 원피스', 'rank' => 7, 'price' => 70000, 'mallName' => '제이플로우',
                'link' => 'https://cr.shopping.naver.com/adcr?x=zzz', 'isAd' => true, 'storeId' => 'jflow'],
            // 윈도 상품에 storeId 가 실려와도 도메인이 분명하면 거부한다
            ['title' => '윈도인데 storeId 있음', 'rank' => 8, 'price' => 80000, 'mallName' => '신세계',
                'link' => 'https://shopping.naver.com/window-products/department/999', 'storeId' => 'sinsegae'],
        ]);

        $this->assertSame(3, $n, '스마트스토어 2건 + 광고(스토어핸들) 1건만 저장');
        $titles = DB::table('shop_products')->pluck('title')->all();
        $this->assertContains('스마트스토어 원피스', $titles);
        $this->assertContains('브랜드스토어 원피스', $titles);
        $this->assertContains('광고 스마트스토어 원피스', $titles);
        foreach (['가격비교 원피스', '윈도 백화점 원피스', '쿠팡 원피스', '자사몰 원피스', '윈도인데 storeId 있음'] as $t) {
            $this->assertNotContains($t, $titles, "{$t} 는 저장하면 안 된다");
        }
        // 원본 순위 유지 — 빠진 자리는 번호가 비어 띄엄띄엄해진다
        $this->assertSame([1, 2, 7], DB::table('keyword_shop_ranks')->orderBy('rnk')->pluck('rnk')->all());
    }
}
