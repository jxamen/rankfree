<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopProductInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 상품 태그(seller_tags) 재수집 — 2026-08-10.
 *
 * 네이버가 상품 상세 상태 JSON 구조를 바꾸자(simpleProductForDetailPage 의 .A 래퍼 소실)
 * 확장이 **제목만 뽑고 태그는 빈 채로** 저장했다. 그런데
 *  ① 수집 큐가 product_title 이 비었는지만 봐서 이런 건이 큐에서 영영 빠졌고,
 *  ② 저장이 seller_tags 를 무조건 덮어써 이미 모아둔 태그까지 지웠다.
 * 결과적으로 확장을 고쳐도 API 응답의 seller_tags 가 계속 빈 배열이었다.
 */
class ShopKeywordTagRecollectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => '테스터', 'email' => 't@rankfree.kr', 'password' => 'secret1234',
            'api_scopes' => ['shop_keyword']]);
        [, $plain] = ExtToken::issue($this->user);
        $this->headers = ['Authorization' => 'Bearer '.$plain];
    }

    private function analysis(): ShopKeywordAnalysis
    {
        return ShopKeywordAnalysis::create([
            'user_id' => $this->user->id,
            'core_keyword' => '강아지사료',
            'product_url' => 'https://smartstore.naver.com/s/products/111',
            'product_id' => '111',
            'product_title' => '내 상품',   // 제목은 이미 채워졌다 — 예전엔 이러면 큐에서 빠졌다
        ]);
    }

    private function queueIds(): array
    {
        return collect($this->withHeaders($this->headers)
            ->getJson('/api/ext/shop-keyword/product-queue?limit=20')
            ->assertOk()->json('data.items'))->pluck('analysis_id')->all();
    }

    public function test_제목은_있는데_태그가_비면_다시_수집_큐에_들어온다(): void
    {
        $a = $this->analysis();
        ShopProductInfo::create([
            'user_id' => $this->user->id, 'channel_product_id' => '111',
            'title' => '내 상품', 'seller_tags' => [],
            'collected_at' => now()->subDays(3),      // 깨진 확장이 3일 전에 저장
        ]);

        $this->assertContains($a->id, $this->queueIds());
    }

    public function test_태그가_있으면_큐에_들어오지_않는다(): void
    {
        $a = $this->analysis();
        ShopProductInfo::create([
            'user_id' => $this->user->id, 'channel_product_id' => '111',
            'title' => '내 상품', 'seller_tags' => ['킹크렙포크', '부모님선물'],
            'collected_at' => now()->subDays(3),
        ]);

        $this->assertNotContains($a->id, $this->queueIds());
    }

    /** 태그가 원래 없는 상품에서 무한 재수집이 돌지 않아야 한다 — 24시간에 한 번만. */
    public function test_방금_수집했으면_태그가_비어도_다시_넣지_않는다(): void
    {
        $a = $this->analysis();
        ShopProductInfo::create([
            'user_id' => $this->user->id, 'channel_product_id' => '111',
            'title' => '내 상품', 'seller_tags' => [],
            'collected_at' => now()->subHour(),
        ]);

        $this->assertNotContains($a->id, $this->queueIds());
    }

    public function test_상품정보가_아예_없으면_큐에_들어온다(): void
    {
        $this->assertContains($this->analysis()->id, $this->queueIds());
    }

    /** 핵심 회귀 — 태그가 빈 수집 결과가 기존 태그를 지우면 안 된다. */
    public function test_빈_태그로_들어와도_기존_태그를_지우지_않는다(): void
    {
        $a = $this->analysis();
        ShopProductInfo::create([
            'user_id' => $this->user->id, 'channel_product_id' => '111',
            'title' => '내 상품', 'seller_tags' => ['킹크렙포크', '부모님선물'],
            'collected_at' => now()->subDays(3),
        ]);

        $this->withHeaders($this->headers)->postJson('/api/ext/shop-keyword/'.$a->id.'/product-info', [
            'info' => [
                'channel_product_id' => '111',
                'title' => '내 상품(갱신)',
                'seller_tags' => [],          // 깨진 확장이 보내는 payload
            ],
        ])->assertOk();

        $info = ShopProductInfo::where('channel_product_id', '111')->first();
        $this->assertSame(['킹크렙포크', '부모님선물'], $info->seller_tags, '빈 태그가 기존 태그를 덮어썼다');
        $this->assertSame('내 상품(갱신)', $info->title, '제목은 갱신되어야 한다');
    }

    public function test_태그가_들어오면_정상적으로_갱신된다(): void
    {
        $a = $this->analysis();
        ShopProductInfo::create([
            'user_id' => $this->user->id, 'channel_product_id' => '111',
            'title' => '내 상품', 'seller_tags' => ['옛태그'],
            'collected_at' => now()->subDays(3),
        ]);

        $this->withHeaders($this->headers)->postJson('/api/ext/shop-keyword/'.$a->id.'/product-info', [
            'info' => ['channel_product_id' => '111', 'title' => '내 상품', 'seller_tags' => ['새태그', '또다른태그']],
        ])->assertOk();

        $this->assertSame(['새태그', '또다른태그'],
            ShopProductInfo::where('channel_product_id', '111')->first()->seller_tags);
    }
}
