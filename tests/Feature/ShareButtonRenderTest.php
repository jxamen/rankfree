<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 공유 버튼(공개 링크 복사) + 공개 공유 페이지(비로그인) 검증. */
class ShareButtonRenderTest extends TestCase
{
    use RefreshDatabase;

    private function market(User $user)
    {
        return $user->marketAnalyses()->create([
            'keyword' => '게이밍 헤드셋', 'total_count' => 100, 'item_count' => 10, 'include_ads' => false,
            'sales_6m' => 1, 'revenue_6m' => 1, 'avg_price' => 1, 'median_price' => 1, 'top10_share' => 1,
            'snapshot' => ['top_products' => []],
        ]);
    }

    private function product(User $user)
    {
        return $user->productAnalyses()->create([
            'origin_product_no' => 123, 'name' => '테스트 상품', 'url' => 'https://smartstore.naver.com/x/products/123',
            'store' => 'x', 'total_reviews' => 10, 'analyzed_reviews' => 10, 'avg_score' => 4.5, 'repurchase_pct' => 10,
            'recent_7d' => 1, 'recent_1m' => 2, 'recent_3m' => 3, 'snapshot' => [],
        ]);
    }

    public function test_console_market_share_button_copies_public_url(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
        $m = $this->market($user);

        $res = $this->actingAs($user)->get('/console/market/'.$m->id);
        $res->assertOk()->assertSee('🔗 공유', false);
        // 공유 버튼이 공개 링크(/m/{token})를 복사하도록 렌더.
        // @js()가 URL 슬래시를 \/ 로 이스케이프하므로 토큰 자체(영숫자)의 노출로 검증한다.
        $m->refresh();
        $this->assertNotNull($m->share_token);
        $res->assertSee($m->share_token, false);
    }

    public function test_market_public_page_opens_without_login(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
        $m = $this->market($user);
        $token = $m->shareToken();

        // 로그인 없이 접근 가능 + 키워드 표시 + 콘솔 사이드바 없음
        $res = $this->get('/m/'.$token);
        $res->assertOk()->assertSee('게이밍 헤드셋')->assertSee('시장 분석 리포트');
        $res->assertDontSee('id="rf-sidebar"', false);
    }

    public function test_product_public_page_opens_without_login(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
        $p = $this->product($user);
        $token = $p->shareToken();

        $res = $this->get('/p/'.$token);
        $res->assertOk()->assertSee('테스트 상품')->assertSee('상품 리뷰 분석 리포트');
        $res->assertDontSee('id="rf-sidebar"', false);
    }

    public function test_bad_token_404(): void
    {
        $this->get('/m/nope')->assertNotFound();
        $this->get('/p/nope')->assertNotFound();
    }
}
