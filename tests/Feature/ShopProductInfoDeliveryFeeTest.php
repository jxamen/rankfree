<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\ShopProductInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 쇼핑 상품정보 배송비 수집(2026-09-10).
 *
 * 배송비는 0(무료)과 null(아직 못 뽑음)을 반드시 구분한다 — 한 값으로 뭉치면
 * 무료배송 상품을 영원히 다시 수집하게 되고, 구버전 확장이 보낸 빈 값이 기존 배송비를 지운다.
 */
class ShopProductInfoDeliveryFeeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rankfree.extension.min_product_version' => '0.4.1']);

        $this->user = User::create([
            'name' => '배송비테스터', 'email' => 'fee@rankfree.kr', 'password' => 'secret1234',
            'api_scopes' => ['shop_keyword'],
        ]);
        [, $plain] = ExtToken::issue($this->user);
        $this->token = $plain;
    }

    /** @param  array<string,mixed>  $extra */
    private function send(array $extra): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'X-Ext-Version' => '0.4.1'])
            ->postJson('/api/ext/product-infos', array_merge([
                'channel_product_id' => '9102006636',
                'title' => '우아미가구 무라노 포세린 세라믹식탁',
                'price' => 439000,
            ], $extra));
    }

    public function test_배송비를_저장한다(): void
    {
        $this->send(['delivery_fee' => 3000])->assertOk();

        $this->assertSame(3000, ShopProductInfo::first()->delivery_fee);
    }

    public function test_무료배송은_0으로_저장된다(): void
    {
        $this->send(['delivery_fee' => 0])->assertOk();

        // 0 과 null 을 구분해야 "무료배송"과 "아직 못 뽑음"이 갈린다
        $this->assertSame(0, ShopProductInfo::first()->delivery_fee);
    }

    public function test_배송비를_못_뽑은_수집은_기존_값을_지우지_않는다(): void
    {
        $this->send(['delivery_fee' => 2500])->assertOk();

        // 상태 JSON 에 배송 노드가 없으면 확장은 null 을 보낸다 — 덮으면 안 된다
        $this->send(['delivery_fee' => null])->assertOk();
        $this->assertSame(2500, ShopProductInfo::first()->delivery_fee);

        // 구버전 확장은 키 자체를 안 보낸다 — 이것도 마찬가지
        $this->send([])->assertOk();
        $this->assertSame(2500, ShopProductInfo::first()->delivery_fee);
    }

    public function test_수집_전에는_null_이다(): void
    {
        $this->send([])->assertOk();

        $this->assertNull(ShopProductInfo::first()->delivery_fee);
    }
}
