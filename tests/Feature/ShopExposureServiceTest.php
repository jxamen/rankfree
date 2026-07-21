<?php

namespace Tests\Feature;

use App\Domain\Shopping\NaverShopExposureService;
use Tests\TestCase;

/** 모바일 검색 가격비교 파싱 — 광고 제외 위치 순위 + 광고 노출 플래그 + 채널/카탈로그 매칭. */
class ShopExposureServiceTest extends TestCase
{
    /** 슬롯 배열로 newshopping _INITIAL_STATE HTML 생성. */
    private function html(array $slots): string
    {
        $slotJson = implode(',', array_map(fn ($s) => json_encode([
            'slotType' => 'CARD',
            'data' => [
                'cardType' => 'CARD',
                'sourceType' => $s['sourceType'],
                'rank' => $s['rank'] ?? 0,
                'nvMid' => $s['nvMid'] ?? 0,
                'channelProductId' => $s['channelProductId'] ?? '',
                'productName' => $s['name'] ?? '상품',
                'mallName' => $s['mall'] ?? '몰',
                'productUrl' => ['mobileUrl' => 'https://m.smartstore.naver.com/main/products/'.($s['channelProductId'] ?? '')],
            ],
        ], JSON_UNESCAPED_UNICODE), $slots));

        return '<html><body><script>naver.search.ext.newshopping["shopping"]._INITIAL_STATE = '
            .'{"initProps":{"pagedSlot":[{"page":1,"pageSize":9,"slots":['.$slotJson.']}]}};</script></body></html>';
    }

    private function svc(): NaverShopExposureService
    {
        return new NaverShopExposureService();
    }

    public function test_rank_is_position_excluding_ads(): void
    {
        $html = $this->html([
            ['sourceType' => 'AD', 'channelProductId' => '999', 'rank' => 5],          // 쇼핑검색광고(제외)
            ['sourceType' => 'AD', 'channelProductId' => '888', 'rank' => 7],          // 광고
            ['sourceType' => 'SUPER_POINT', 'channelProductId' => '777', 'rank' => 1], // 슈퍼적립(광고성 — 제외)
            ['sourceType' => 'SAS', 'channelProductId' => '111', 'rank' => 2],          // 페이지 표기 2위(내 상품)
            ['sourceType' => 'SAS', 'channelProductId' => '222', 'rank' => 3],
        ]);

        $r = $this->svc()->rankFromHtml($html, ['id_kind' => 'channel', 'product_id' => '111']);
        $this->assertTrue($r['found']);
        $this->assertSame(2, $r['rank']);        // 네이버 자체 rank 필드(슈퍼적립이 1을 차지) 그대로
        $this->assertFalse($r['ad']);
        $this->assertSame(2, $r['total']);       // 오가닉(SAS) 2개
    }

    public function test_super_point_is_ad_not_organic(): void
    {
        // 실측('비타민c 유유제약'): 내 상품이 슈퍼적립(SUPER_POINT) 1위로만 노출 → 광고 노출이지 오가닉 순위가 아니다
        $html = $this->html([
            ['sourceType' => 'SUPER_POINT', 'channelProductId' => '111', 'rank' => 1],
            ['sourceType' => 'SAS', 'channelProductId' => '222', 'rank' => 2],
        ]);
        $r = $this->svc()->rankFromHtml($html, ['id_kind' => 'channel', 'product_id' => '111']);
        $this->assertFalse($r['found']);
        $this->assertSame(0, $r['rank']);
        $this->assertTrue($r['ad']);
    }

    public function test_second_organic_rank_falls_back_to_position_without_rank_field(): void
    {
        $html = $this->html([
            ['sourceType' => 'AD', 'channelProductId' => '999', 'rank' => 3],
            ['sourceType' => 'SAS', 'channelProductId' => '222', 'rank' => 0],       // rank 필드 없음 → 문서 위치
            ['sourceType' => 'SAS', 'channelProductId' => '111', 'rank' => 0],
        ]);
        $r = $this->svc()->rankFromHtml($html, ['id_kind' => 'channel', 'product_id' => '111']);
        $this->assertSame(2, $r['rank']);
    }

    public function test_ad_only_product_flagged_not_ranked(): void
    {
        $html = $this->html([
            ['sourceType' => 'AD', 'channelProductId' => '111', 'rank' => 16],       // 내 상품이 광고로만
            ['sourceType' => 'SUPER_POINT', 'channelProductId' => '222', 'rank' => 1],
        ]);
        $r = $this->svc()->rankFromHtml($html, ['id_kind' => 'channel', 'product_id' => '111']);
        $this->assertFalse($r['found']);   // 오가닉 노출 아님
        $this->assertSame(0, $r['rank']);
        $this->assertTrue($r['ad']);       // 광고 노출 중
    }

    public function test_catalog_matches_by_nvmid(): void
    {
        $html = $this->html([
            ['sourceType' => 'AD', 'channelProductId' => '999', 'nvMid' => 111, 'rank' => 4],
            ['sourceType' => 'SAS', 'channelProductId' => '', 'nvMid' => 51929212855, 'rank' => 1], // 가격비교 카탈로그
        ]);
        // 카탈로그는 nvMid 로 매칭(channelProductId 아님)
        $r = $this->svc()->rankFromHtml($html, ['id_kind' => 'nvmid', 'product_id' => '51929212855']);
        $this->assertTrue($r['found']);
        $this->assertSame(1, $r['rank']);
    }

    public function test_no_shopping_block_returns_zero(): void
    {
        $r = $this->svc()->rankFromHtml('<html><body>가격비교 없음</body></html>', ['id_kind' => 'channel', 'product_id' => '111']);
        $this->assertFalse($r['found']);
        $this->assertSame(0, $r['rank']);
    }
}
