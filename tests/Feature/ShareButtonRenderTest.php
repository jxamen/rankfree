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
        // 공유 버튼이 SEO 슬러그 공개 링크(/market/{slug})를 복사하도록 렌더.
        // @js()가 슬래시를 \/ 로, 한글을 \uXXXX 로 이스케이프하므로 경로 접두로 검증한다.
        $res->assertSee('\/market\/', false);
        $this->assertNotNull($m->fresh()->slug);
    }

    public function test_market_public_page_opens_without_login(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
        $m = $this->market($user);

        // 구 토큰 URL → 슬러그로 301
        $this->get('/m/'.$m->shareToken())->assertStatus(301)->assertRedirect($m->shareUrl());

        // 로그인 없이 접근 가능(슬러그) + 키워드 표시 + 콘솔 사이드바 없음
        $res = $this->get($m->shareUrl());
        $res->assertOk()->assertSee('게이밍 헤드셋')->assertSee('시장 분석 리포트');
        $res->assertDontSee('id="rf-sidebar"', false);
    }

    public function test_product_public_page_opens_without_login(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
        $p = $this->product($user);

        $this->get('/p/'.$p->shareToken())->assertStatus(301)->assertRedirect($p->shareUrl());

        $res = $this->get($p->shareUrl());
        $res->assertOk()->assertSee('테스트 상품')->assertSee('상품 리뷰 분석 리포트');
        $res->assertDontSee('id="rf-sidebar"', false);
    }

    public function test_bad_token_404(): void
    {
        $this->get('/m/nope')->assertNotFound();
        $this->get('/p/nope')->assertNotFound();
        $this->get('/market/no-such-slug')->assertNotFound();
        $this->get('/product/no-such-slug')->assertNotFound();
    }

    /** 공개 1회성 분석 리포트 — 색인 허용(noindex 없음) + SEO/AEO/GEO 구조화 데이터. */
    public function test_public_analysis_report_is_indexable_with_structured_data(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'idx@rf.kr', 'password' => 'x1234567']);
        $m = $this->market($user);

        $html = $this->get($m->shareUrl())->assertOk()->getContent();

        // 색인 허용 + og article + 페이지 h1
        $this->assertStringNotContainsString('noindex', $html);
        $this->assertStringContainsString('og:type" content="article"', $html);
        $this->assertStringContainsString('<h1', $html);

        // 구조화 데이터: BreadcrumbList + Article + FAQPage(모두 유효 JSON · 스크립트 탈출 없음)
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $mm);
        $types = [];
        foreach ($mm[1] as $block) {
            $this->assertStringNotContainsString('</script', $block, 'JSON-LD 스크립트 탈출');
            $j = json_decode($block, true);
            $this->assertNotNull($j, 'JSON-LD 파손');
            $types[] = $j['@type'] ?? '';
        }
        foreach (['BreadcrumbList', 'Article', 'FAQPage'] as $t) {
            $this->assertContains($t, $types, "$t 구조화 데이터 누락");
        }
    }
}
