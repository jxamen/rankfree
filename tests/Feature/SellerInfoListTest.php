<?php

namespace Tests\Feature;

use App\Models\ShopSellerInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 판매자정보 목록(/admin/seller-infos) — 수집된 사업자 정보만 별도로, 톡톡·스토어명 보강. */
class SellerInfoListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => '관리자', 'email' => 'si@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    public function test_requires_operator(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'plain-si@rf.kr', 'password' => 'x1234567']);
        $this->actingAs($user)->get('/admin/seller-infos')->assertForbidden();
    }

    public function test_lists_seller_info_with_talk_and_store_from_products(): void
    {
        ShopSellerInfo::create([
            'store_id' => 'jsquare', 'channel_uid' => 'ch-1',
            'biz_name' => '제이스퀘어', 'representative' => '홍길동',
            'customer_phone' => '02-123-4567', 'captured_at' => now(),
        ]);
        // 톡톡·스토어명은 상품 마스터에서 store_id 로 보강
        DB::table('shop_products')->insert([
            'product_key' => 'pk-1', 'title' => '상품A', 'mall_name' => '제이스퀘어스토어',
            'store_id' => 'jsquare', 'talk_id' => 'w4n5gg',
            'link' => 'https://smartstore.naver.com/jsquare/products/123',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->actingAs($this->admin())->get('/admin/seller-infos')->assertOk()->getContent();

        $this->assertStringContainsString('제이스퀘어', $html);          // 업체명
        $this->assertStringContainsString('홍길동', $html);              // 대표자명
        $this->assertStringContainsString('02-123-4567', $html);        // 전화번호
        $this->assertStringContainsString('talk.naver.com/ct/w4n5gg', $html);  // 톡톡아이디(상품에서 보강)
        $this->assertStringContainsString('제이스퀘어스토어', $html);     // 스토어명(상품 mall_name)
        $this->assertStringContainsString('smartstore.naver.com/jsquare', $html); // 스토어 링크
    }

    public function test_search_filters_by_company_or_representative(): void
    {
        ShopSellerInfo::create(['channel_uid' => 'a', 'biz_name' => '알파상회', 'representative' => '김하나', 'captured_at' => now()]);
        ShopSellerInfo::create(['channel_uid' => 'b', 'biz_name' => '베타물산', 'representative' => '이두울', 'captured_at' => now()]);

        $html = $this->actingAs($this->admin())->get('/admin/seller-infos?q=알파')->assertOk()->getContent();
        $this->assertStringContainsString('알파상회', $html);
        $this->assertStringNotContainsString('베타물산', $html);
    }

    public function test_store_link_falls_back_to_smartstore_when_no_product(): void
    {
        ShopSellerInfo::create(['channel_uid' => 'c', 'store_id' => 'lonelystore', 'biz_name' => '외톨이', 'captured_at' => now()]);

        $html = $this->actingAs($this->admin())->get('/admin/seller-infos')->assertOk()->getContent();
        // 매칭 상품이 없어도 store_id 로 스마트스토어 홈 링크는 만든다
        $this->assertStringContainsString('smartstore.naver.com/lonelystore', $html);
    }
}
