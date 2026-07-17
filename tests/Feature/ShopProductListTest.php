<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopSerpStore;
use App\Models\ShopSellerCaptcha;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 수집 상품 목록 — 키워드와 별개로 상품 기준으로 본다(검색·판매처·광고·톡톡 필터·정렬). */
class ShopProductListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'email' => 'sp@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    public function test_lists_products_with_keywords_and_filters(): void
    {
        $store = app(ShopSerpStore::class);
        // 같은 상품이 두 키워드에 걸린다 — 상품은 1행, 노출 키워드는 2개로 보여야 한다
        $store->save('린넨원피스', [
            ['title' => '국내제작 린넨 원피스', 'rank' => 3, 'price' => 39000, 'mallName' => '멜빈트',
                'link' => 'https://smartstore.naver.com/melvint/products/111', 'talkId' => 'w4n5gg', 'storeId' => 'melvint'],
            ['title' => '광고 원피스', 'rank' => 1, 'price' => 50000, 'mallName' => '제이플로우',
                'link' => 'https://cr.shopping.naver.com/adcr?x=z', 'isAd' => true, 'storeId' => 'jflow'],
        ]);
        $store->save('여름원피스', [
            ['title' => '국내제작 린넨 원피스', 'rank' => 7, 'price' => 39000, 'mallName' => '멜빈트',
                'link' => 'https://smartstore.naver.com/melvint/products/111', 'talkId' => 'w4n5gg', 'storeId' => 'melvint'],
        ]);

        $u = $this->admin();

        // 목록 — 상품 2건(중복 제거), 걸린 키워드와 순위가 함께 보인다
        $this->actingAs($u)->get('/admin/shop-products')->assertOk()
            ->assertSee('국내제작 린넨 원피스')
            ->assertSee('린넨원피스')->assertSee('여름원피스')
            ->assertSee('talk.naver.com/ct/w4n5gg');

        // 상품명 검색
        $r = $this->actingAs($u)->get('/admin/shop-products?q='.urlencode('린넨'));
        $r->assertOk()->assertSee('국내제작 린넨 원피스')->assertDontSee('광고 원피스');

        // 광고 제외 / 광고만
        $this->actingAs($u)->get('/admin/shop-products?ad=n')->assertOk()->assertDontSee('광고 원피스');
        $this->actingAs($u)->get('/admin/shop-products?ad=y')->assertOk()->assertSee('광고 원피스');

        // 톡톡 있는 것만 — 광고 상품은 톡톡이 없다
        $this->actingAs($u)->get('/admin/shop-products?talk=y')->assertOk()
            ->assertSee('국내제작 린넨 원피스')->assertDontSee('광고 원피스');

        // 판매처 필터
        $this->actingAs($u)->get('/admin/shop-products?mall='.urlencode('멜빈트'))->assertOk()
            ->assertSee('국내제작 린넨 원피스')->assertDontSee('광고 원피스');

        // 정렬은 500 없이 동작
        foreach (['recent', 'kw', 'price_high', 'price_low', 'title'] as $s) {
            $this->actingAs($u)->get('/admin/shop-products?sort='.$s)->assertOk();
        }
    }

    public function test_shows_recent_seller_captcha_questions_with_image_links(): void
    {
        Storage::fake('local');
        $user = $this->admin();
        $path = 'seller-captchas/channel/captcha.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Storage::disk('local')->put($path, $png);

        $captcha = ShopSellerCaptcha::create([
            'user_id' => $user->id,
            'store_id' => 'melvint',
            'channel_uid' => 'channel',
            'captcha_key' => 'captcha',
            'question' => '영수증에 구매한 상품은 몇 개입니까?',
            'image_disk' => 'local',
            'image_path' => $path,
            'image_mime' => 'image/png',
            'image_bytes' => strlen($png),
            'captured_at' => now(),
        ]);

        $this->actingAs($user)->get('/admin/shop-products')
            ->assertOk()
            ->assertSee('최근 판매자정보 캡차')
            ->assertSee('영수증에 구매한 상품은 몇 개입니까?')
            ->assertSee(route('admin.shop-products.seller-captchas.image', $captcha), false);

        $this->actingAs($user)->get(route('admin.shop-products.seller-captchas.image', $captcha))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }
}
