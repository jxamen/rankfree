<?php

namespace Tests\Feature;

use App\Domain\Keyword\KeywordHubPublisher;
use App\Models\ExtToken;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\MarketAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 확장 벌크 수집 → 시장분석 생성 → 허브 발행 복제 — "쇼핑 시장 분석은 확장 플로만"의 대량 경로 전 구간.
 * 확장 v0.3.7+ 가 purchase6m(6개월 구매건수)·revenue6m(카탈로그 보강)을 보내면 서버가 C1 산식으로
 * MarketAnalysis 를 만들고, 쇼핑 후보 발행이 그 문서를 복제한다.
 */
class ExtBulkMarketAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function superToken(): array
    {
        $u = User::create(['name' => 'a', 'email' => 'bulk@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
        [, $plain] = ExtToken::issue($u);

        return [$u, $plain];
    }

    private function payload(): array
    {
        return [
            'keyword' => '여름원피스',
            'total' => 5000,
            'related_tags' => ['롱원피스', '린넨원피스'],
            'products' => [
                ['title' => 'A 원피스', 'rank' => 1, 'price' => 30000, 'mallName' => 'A몰', 'purchase6m' => 100,
                    'mallGrade' => '빅파워', 'category' => '패션의류 > 원피스'],
                // 가격비교(카탈로그) — 보강 매출이 구매수×가격보다 우선
                ['title' => 'B 카탈로그', 'rank' => 2, 'price' => 20000, 'mallName' => 'B몰', 'purchase6m' => 50,
                    'revenue6m' => 1500000, 'isCatalog' => true, 'mallCount' => 4, 'sellerCount' => 3,
                    'mallGrade' => '가격비교', 'category' => '패션의류 > 원피스'],
                // 광고 — 시장 계산에서 제외(C1 오가닉 기준)
                ['title' => '광고 원피스', 'rank' => 3, 'price' => 99000, 'mallName' => '광고몰', 'purchase6m' => 999, 'isAd' => true],
            ],
        ];
    }

    public function test_bulk_serp_with_purchase_builds_market_analysis(): void
    {
        [$u, $plain] = $this->superToken();

        $r = $this->postJson('/api/ext/keyword-shop-serp', $this->payload(), ['Authorization' => 'Bearer '.$plain])->assertOk();

        $m = MarketAnalysis::where('user_id', $u->id)->where('keyword', '여름원피스')->first();
        $this->assertNotNull($m, '구매건수 실린 수집이면 시장분석이 생성돼야 한다');
        $this->assertSame($m->id, $r->json('data.market_id'));
        $this->assertSame(150, $m->sales_6m);                       // 100 + 50 (광고 999 제외)
        $this->assertSame(100 * 30000 + 1500000, $m->revenue_6m);   // 보강 매출 우선
        $this->assertSame(25000, $m->avg_price);
        $this->assertSame(2, $m->item_count);
        $this->assertSame('패션의류 > 원피스', $m->snapshot['top_product_category']);
        $this->assertCount(2, $m->snapshot['top_products']);
        $this->assertSame('ext_bulk_serp', $m->snapshot['generated_by']);

        // 재수집 시 같은 문서 갱신(중복 생성 없음)
        $this->postJson('/api/ext/keyword-shop-serp', $this->payload(), ['Authorization' => 'Bearer '.$plain])->assertOk();
        $this->assertSame(1, MarketAnalysis::where('user_id', $u->id)->where('keyword', '여름원피스')->count());
    }

    public function test_legacy_payload_without_purchase_does_not_build_market(): void
    {
        [, $plain] = $this->superToken();
        $p = $this->payload();
        foreach ($p['products'] as &$row) {
            unset($row['purchase6m'], $row['revenue6m']);
        }

        $this->postJson('/api/ext/keyword-shop-serp', $p, ['Authorization' => 'Bearer '.$plain])->assertOk();

        $this->assertSame(0, MarketAnalysis::count(), '구버전 확장(purchase6m 없음) 수집은 껍데기 시장분석을 만들지 않는다');
    }

    public function test_hub_publish_copies_bulk_market_analysis(): void
    {
        [, $plain] = $this->superToken();
        $this->postJson('/api/ext/keyword-shop-serp', $this->payload(), ['Authorization' => 'Bearer '.$plain])->assertOk();

        $cat = KeywordCategory::create(['type' => 'shopping', 'name' => '패션의류', 'slug' => '패션의류', 'is_active' => true]);
        $c = KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '여름원피스', 'source' => 'datalab', 'monthly_total' => 8000, 'status' => 'approved']);

        $doc = app(KeywordHubPublisher::class)->publish($c);

        $this->assertInstanceOf(MarketAnalysis::class, $doc, '확장 수집산 시장분석이 있으면 쇼핑 후보가 발행돼야 한다');
        $this->assertSame(150, $doc->sales_6m);
        $this->assertSame('published', $c->fresh()->status);
        $this->assertStringContainsString('/market/', $doc->shareUrl());
    }
}
