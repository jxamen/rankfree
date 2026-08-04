<?php

namespace Tests\Feature;

use App\Domain\Shopping\NaverShopExposureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 쇼핑 노출 판정 — ns-portal slot API 경로 (2026-08-04).
 * openapi shop.json 종료(2026-07-31)로 서버 판정이 끊긴 자리를 대체한다.
 * 광고(SUPER_POINT)는 오가닉 순위에서 제외하고, 20위 상한 밖은 미노출로 본다.
 */
class ShopSlotExposureTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://ns-portal.shopping.naver.com/*';

    /** 실제 응답과 같은 모양(slots[].data)의 가짜 응답 */
    private function fakeSlots(array $rows): array
    {
        $slots = [];
        foreach ($rows as $r) {
            $slots[] = ['slotType' => 'CARD', 'data' => $r];
        }

        return ['data' => [['page' => 1, 'pageSize' => count($slots), 'slots' => $slots]]];
    }

    private function svc(): NaverShopExposureService
    {
        return app(NaverShopExposureService::class);
    }

    /** 광고(SUPER_POINT)는 오가닉 순위에서 빠지고, 대상 상품의 오가닉 순위를 돌려준다 */
    public function test_organic_rank_excludes_ad_slot(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fakeSlots([
            ['sourceType' => 'SUPER_POINT', 'channelProductId' => '111', 'nvMid' => '911', 'mallName' => '광고몰', 'productName' => '광고상품', 'rank' => 1],
            ['sourceType' => 'SAS', 'channelProductId' => '222', 'nvMid' => '922', 'mallName' => '가몰', 'productName' => '가상품', 'rank' => 1],
            ['sourceType' => 'SAS', 'channelProductId' => '333', 'nvMid' => '933', 'mallName' => '나몰', 'productName' => '나상품', 'rank' => 2],
        ]))]);

        $r = $this->svc()->exposureBySlotApi('여름브라', ['id_kind' => 'channel', 'product_id' => '333']);

        $this->assertTrue($r['found']);
        $this->assertSame(2, $r['rank']);
        $this->assertFalse($r['ad']);
        $this->assertSame(2, $r['total']);       // 오가닉만 집계(광고 제외)
        $this->assertFalse($r['blocked']);
    }

    /** 같은 상품이 광고로도 노출되면 ad=true 로 표시된다 */
    public function test_ad_exposure_flagged(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fakeSlots([
            ['sourceType' => 'SUPER_POINT', 'channelProductId' => '222', 'nvMid' => '922', 'mallName' => '가몰', 'productName' => '가상품', 'rank' => 1],
            ['sourceType' => 'SAS', 'channelProductId' => '222', 'nvMid' => '922', 'mallName' => '가몰', 'productName' => '가상품', 'rank' => 1],
        ]))]);

        $r = $this->svc()->exposureBySlotApi('여름브라', ['id_kind' => 'channel', 'product_id' => '222']);

        $this->assertTrue($r['found']);
        $this->assertTrue($r['ad']);
    }

    /** 결과에 없으면 미노출(rank=0)이며 차단이 아니다 — 미확인으로 남기지 않는다 */
    public function test_not_found_is_zero_not_blocked(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fakeSlots([
            ['sourceType' => 'SAS', 'channelProductId' => '222', 'nvMid' => '922', 'mallName' => '가몰', 'productName' => '가상품', 'rank' => 1],
        ]))]);

        $r = $this->svc()->exposureBySlotApi('여름브라', ['id_kind' => 'channel', 'product_id' => '999']);

        $this->assertFalse($r['found']);
        $this->assertSame(0, $r['rank']);
        $this->assertFalse($r['blocked']);
    }

    /** nvMid 대상(가격비교 카탈로그)도 매칭된다 */
    public function test_matches_by_nvmid(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fakeSlots([
            ['sourceType' => 'SAS', 'channelProductId' => '222', 'nvMid' => '922', 'mallName' => '가몰', 'productName' => '가상품', 'rank' => 1],
        ]))]);

        $r = $this->svc()->exposureBySlotApi('여름브라', ['id_kind' => 'nvmid', 'product_id' => '922']);
        $this->assertTrue($r['found']);
    }

    /** 418/429 는 차단으로 표시해 미노출 오기록을 막는다 */
    public function test_http_block_marks_blocked(): void
    {
        Http::fake([self::ENDPOINT => Http::response('blocked', 418)]);

        $r = $this->svc()->exposureBySlotApi('여름브라', ['id_kind' => 'channel', 'product_id' => '222']);

        $this->assertTrue($r['blocked']);
        $this->assertFalse($r['found']);
        $this->assertSame('http_418', $r['error']);
    }

    /** 빠른 확인(api)은 20위까지만이라 threshold 가 그보다 크면 생성을 막는다 */
    public function test_threshold_above_slot_max_rejected_for_api_method(): void
    {
        $admin = \App\Models\User::create([
            'name' => '운영자', 'email' => 'slotop@rankfree.kr', 'phone' => '01044443333',
            'password' => 'secret1234', 'role' => 'super',
        ]);

        $this->actingAs($admin)->post(route('admin.shop-keyword.store'), [
            'core_keyword' => '여름브라',
            'product' => 'https://smartstore.naver.com/x/products/123',
            'threshold' => NaverShopExposureService::SLOT_MAX + 1,
            'check_method' => 'api',
        ])->assertSessionHasErrors('threshold');
    }
}
