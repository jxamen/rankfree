<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 크롬 확장 — 쇼핑 순위체크(패널) 1회성 판정 API.
 *
 * 확장은 목록 수집만 하고 매칭·순위 계산은 서버가 한다(순위추적 워커와 같은 판정기).
 * 슬롯·큐를 만들지 않으므로 기록에 남지 않는다.
 */
class ExtShopRankCheckTest extends TestCase
{
    use RefreshDatabase;

    private function authed(): array
    {
        $user = User::create(['name' => '테스터', 'email' => 'tester@rankfree.kr', 'password' => 'secret1234']);
        [, $plain] = ExtToken::issue($user);

        return ['Authorization' => 'Bearer '.$plain];
    }

    /** 광고 1개 + 오가닉 3개 — 대상은 3번째 오가닉. */
    private function products(): array
    {
        return [
            ['isAd' => true, 'channelProductId' => '999', 'mallName' => '광고몰', 'title' => '광고 상품'],
            ['isAd' => false, 'channelProductId' => '111', 'mallName' => '가몰', 'title' => 'A'],
            ['isAd' => false, 'channelProductId' => '222', 'mallName' => '나몰', 'title' => 'B'],
            ['isAd' => false, 'channelProductId' => '333', 'mallName' => '내몰', 'title' => '내 상품', 'price' => 19900],
        ];
    }

    public function test_상품URL로_광고를_제외한_오가닉_순위를_돌려준다(): void
    {
        $res = $this->withHeaders($this->authed())->postJson('/api/ext/shop-rank/check', [
            'keyword' => '강아지사료',
            'target' => 'https://smartstore.naver.com/mystore/products/333',
            'products' => $this->products(),
            'total' => 1234,
        ])->assertOk();

        $res->assertJson(['ok' => true, 'data' => ['found' => true, 'rank' => 3, 'ad' => false]]);
        $this->assertSame('강아지사료', $res->json('data.keyword'));
        // 1회성 조회다 — 슬롯·작업·기록을 만들지 않는다
        $this->assertDatabaseCount('shop_rank_slots', 0);
        $this->assertDatabaseCount('shop_rank_jobs', 0);
    }

    public function test_목록에_없으면_미노출로_돌려준다(): void
    {
        $this->withHeaders($this->authed())->postJson('/api/ext/shop-rank/check', [
            'keyword' => '강아지사료',
            'target' => 'https://smartstore.naver.com/mystore/products/777',
            'products' => $this->products(),
        ])->assertOk()->assertJson(['ok' => true, 'data' => ['found' => false, 'rank' => 0]]);
    }

    public function test_업체명만_넣어도_찾는다(): void
    {
        $this->withHeaders($this->authed())->postJson('/api/ext/shop-rank/check', [
            'keyword' => '강아지사료',
            'target' => '내몰',
            'products' => $this->products(),
        ])->assertOk()->assertJson(['ok' => true, 'data' => ['found' => true, 'rank' => 3]]);
    }

    /** 네이버가 아닌 URL 은 업체명으로 흘려보내지 않는다 — 조용히 '미노출'이 되면 오해한다. */
    public function test_네이버가_아닌_URL은_422(): void
    {
        $this->withHeaders($this->authed())->postJson('/api/ext/shop-rank/check', [
            'keyword' => '강아지사료',
            'target' => 'https://example.com/no-product',
            'products' => $this->products(),
        ])->assertStatus(422);
    }

    public function test_로그인_없이는_거부한다(): void
    {
        $this->postJson('/api/ext/shop-rank/check', [
            'keyword' => '강아지사료',
            'target' => '내몰',
            'products' => $this->products(),
        ])->assertUnauthorized();
    }

    /** 조기중단 힌트용 대상 해석 — 확장이 URL 파싱을 따로 갖지 않게 서버가 해석해 준다. */
    public function test_대상_해석은_상품번호와_업체명을_돌려준다(): void
    {
        $h = $this->authed();

        $this->withHeaders($h)->postJson('/api/ext/shop-rank/resolve', [
            'target' => 'https://brand.naver.com/dermadog/products/4231683592',
        ])->assertOk()->assertJson(['ok' => true, 'data' => [
            'type' => 'product', 'product_id' => '4231683592', 'id_kind' => 'channel',
        ]]);

        $this->withHeaders($h)->postJson('/api/ext/shop-rank/resolve', ['target' => '내몰'])
            ->assertOk()->assertJson(['ok' => true, 'data' => ['type' => 'mall', 'mall_name' => '내몰']]);
    }
}
