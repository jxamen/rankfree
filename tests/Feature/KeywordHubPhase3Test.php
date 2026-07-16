<?php

namespace Tests\Feature;

use App\Domain\Keyword\KeywordAiInsight;
use App\Domain\Keyword\KeywordHubPublisher;
use App\Domain\Keyword\KeywordReportBuilder;
use App\Domain\Seo\RelatedDocsService;
use App\Models\GscStat;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use App\Models\MarketAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 키워드 허브 Phase 3 — AI 인사이트·크로스 카테고리 추천·GSC 발굴/갱신 우선순위. */
class KeywordHubPhase3Test extends TestCase
{
    use RefreshDatabase;

    private function category(): KeywordCategory
    {
        return KeywordCategory::create(['type' => 'shopping', 'name' => '캠핑용품', 'slug' => '캠핑용품', 'is_active' => true]);
    }

    private function vm(string $kw, int $total = 5000): array
    {
        return [
            'keyword' => $kw, 'has_data' => true, 'has_volume' => true,
            'total' => $total, 'pc' => (int) ($total * 0.2), 'mobile' => (int) ($total * 0.8),
            'comp_idx' => '중간', 'grade' => 'C',
        ];
    }

    public function test_ai_insight_uses_gemini_and_degrades_without_keys(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.anthropic.key' => '']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '이 키워드는 캠핑 시즌 수요가 높아 여름 전 콘텐츠 준비가 유리합니다.']]]]],
            ], 200),
        ]);

        $out = app(KeywordAiInsight::class)->write($this->vm('캠핑의자'));
        $this->assertNotNull($out);
        $this->assertSame('gemini', $out['provider']);
        $this->assertStringContainsString('캠핑 시즌', $out['text']);

        // 키가 없으면 조용히 null(문서는 AI 없이도 완결)
        config(['services.gemini.key' => '', 'services.anthropic.key' => '']);
        $this->assertNull(app(KeywordAiInsight::class)->write($this->vm('캠핑의자')));
        // 검색량 없는 vm 은 호출 자체를 안 함
        config(['services.gemini.key' => 'test-key']);
        $this->assertNull(app(KeywordAiInsight::class)->write(['keyword' => 'x', 'has_volume' => false]));
    }

    public function test_publisher_stores_ai_insight_in_snapshot(): void
    {
        $cat = $this->category();
        $c = KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '캠핑의자', 'status' => 'approved']);
        $this->mock(KeywordReportBuilder::class, function ($m) {
            $m->shouldReceive('build')->andReturn(['vm' => $this->vm('캠핑의자'), 'saturation' => null, 'popular' => [], 'weekday' => null, 'autocomplete' => []]);
        });
        $this->mock(KeywordAiInsight::class, function ($m) {
            $m->shouldReceive('write')->once()->andReturn(['text' => 'AI 해석 문장입니다.', 'provider' => 'gemini', 'generated_at' => now()->toIso8601String()]);
        });

        $doc = app(KeywordHubPublisher::class)->publish($c);

        $this->assertSame('AI 해석 문장입니다.', $doc->snapshot['ai_insight']['text']);
    }

    public function test_share_page_shows_stored_ai_insight(): void
    {
        $cat = $this->category();
        $doc = KeywordSearch::create([
            'origin' => 'hub', 'category_id' => $cat->id, 'keyword' => '캠핑의자', 'monthly_total' => 5000,
            'snapshot' => ['ai_insight' => ['text' => '저장된 AI 인사이트 문장.', 'provider' => 'gemini', 'generated_at' => now()->toIso8601String()]],
        ]);
        // 화면 렌더는 전체 키가 필요 — 실제 프레젠터로 vm 구성(수집만 대체)
        $full = \App\Domain\Keyword\KeywordAnalysisPresenter::build('캠핑의자',
            ['keyword' => '캠핑의자', 'monthly_pc' => 1000, 'monthly_mobile' => 4000, 'monthly_total' => 5000, 'comp_idx' => '중간', 'related' => []], null, null);
        $this->mock(KeywordReportBuilder::class, function ($m) use ($full) {
            $m->shouldReceive('build')->andReturn(['vm' => $full, 'saturation' => null, 'popular' => [], 'weekday' => null, 'autocomplete' => []]);
        });

        $html = $this->get($doc->shareUrl())->assertOk()->getContent();

        $this->assertStringContainsString('AI 인사이트', $html);
        $this->assertStringContainsString('저장된 AI 인사이트 문장.', $html);
        $this->assertStringContainsString('AI 생성', $html); // 생성물 표기(과장 방지 고지)
    }

    public function test_market_doc_gets_category_section_via_keyword_match(): void
    {
        $cat = $this->category();
        KeywordSearch::create(['origin' => 'hub', 'category_id' => $cat->id, 'keyword' => '캠핑의자', 'monthly_total' => 52700]);
        KeywordSearch::create(['origin' => 'hub', 'category_id' => $cat->id, 'keyword' => '캠핑테이블', 'monthly_total' => 30000]);
        $u = User::create(['name' => 'u', 'email' => 'p3@rf.kr', 'password' => 'x1234567']);
        $market = MarketAnalysis::create(['user_id' => $u->id, 'keyword' => '캠핑의자', 'snapshot' => ['top_products' => []]]);

        $sections = app(RelatedDocsService::class)->sectionsFor($market);

        // 시장 분석 문서도 같은 키워드의 허브 문서를 통해 카테고리 추천을 받는다(허브 문서 자신 포함)
        $this->assertSame("'캠핑용품' 카테고리 인기 키워드", $sections[0]['title']);
        $titles = array_column($sections[0]['items'], 'title');
        $this->assertContains('캠핑의자 키워드 분석', $titles);
        $this->assertContains('캠핑테이블 키워드 분석', $titles);
    }

    public function test_hub_discover_creates_candidates_from_gsc_queries(): void
    {
        $cat = $this->category();
        config(['rankfree.hub.discover_category' => $cat->slug, 'rankfree.hub.discover_min_impressions' => 30]);
        KeywordSearch::create(['origin' => 'hub', 'keyword' => '이미발행키워드', 'monthly_total' => 1000]);

        $d = now()->subDays(3)->toDateString();
        GscStat::create(['date' => $d, 'dimension' => 'query', 'value' => '캠핑 랜턴', 'clicks' => 12, 'impressions' => 80, 'ctr' => 0.15, 'position' => 8.2]);
        GscStat::create(['date' => now()->subDays(2)->toDateString(), 'dimension' => 'query', 'value' => '캠핑 랜턴', 'clicks' => 8, 'impressions' => 40, 'ctr' => 0.2, 'position' => 7.1]);
        GscStat::create(['date' => $d, 'dimension' => 'query', 'value' => '이미발행키워드', 'clicks' => 5, 'impressions' => 90, 'ctr' => 0.05, 'position' => 4.0]);
        GscStat::create(['date' => $d, 'dimension' => 'query', 'value' => '저노출쿼리', 'clicks' => 0, 'impressions' => 5, 'ctr' => 0, 'position' => 30.0]);

        $this->artisan('hub:discover')->assertSuccessful();

        $c = KeywordCandidate::where('keyword', '캠핑 랜턴')->first();
        $this->assertNotNull($c);
        $this->assertSame('gsc', $c->source);
        $this->assertSame('pending', $c->status);
        $this->assertStringContainsString('GSC 노출 120', (string) $c->note); // 기간 합산
        // 기발행·저노출은 후보로 안 들어온다
        $this->assertDatabaseMissing('keyword_candidates', ['keyword' => '이미발행키워드']);
        $this->assertDatabaseMissing('keyword_candidates', ['keyword' => '저노출쿼리']);
    }

    public function test_hub_refresh_prioritizes_docs_with_gsc_clicks(): void
    {
        $cat = $this->category();
        $old = now()->subDays(60);
        $a = KeywordSearch::create(['origin' => 'hub', 'category_id' => $cat->id, 'keyword' => '의자류', 'monthly_total' => 100, 'refreshed_at' => $old]);
        $b = KeywordSearch::create(['origin' => 'hub', 'category_id' => $cat->id, 'keyword' => '캠핑박스', 'monthly_total' => 100, 'refreshed_at' => $old->copy()->addDay()]);
        // b(더 어린 문서)에만 GSC 클릭 — 오래된 순이라면 a 가 먼저지만, 클릭 우선순위로 b 가 먼저 갱신돼야 함
        GscStat::create(['date' => now()->subDays(5)->toDateString(), 'dimension' => 'page', 'value' => url('/keyword/'.rawurlencode($b->shareSlug())), 'clicks' => 50, 'impressions' => 900, 'ctr' => 0.05, 'position' => 6.0]);

        $this->mock(KeywordReportBuilder::class, function ($m) {
            $m->shouldReceive('build')->andReturnUsing(fn (string $kw) => ['vm' => $this->vm($kw, 7777), 'saturation' => null, 'popular' => [], 'weekday' => null, 'autocomplete' => []]);
        });

        $this->artisan('hub:refresh', ['--limit' => 1])->assertSuccessful();

        $this->assertTrue($b->fresh()->refreshed_at->isToday());
        $this->assertSame(7777, $b->fresh()->monthly_total);
        $this->assertSame(100, $a->fresh()->monthly_total); // a 는 이번 턴에 갱신 안 됨
    }
}
