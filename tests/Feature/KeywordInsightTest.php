<?php

namespace Tests\Feature;

use App\Domain\Keyword\KeywordAnalysisPresenter;
use App\Domain\Keyword\KeywordReportBuilder;
use App\Domain\Seo\RelatedDocsService;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 키워드 인사이트 허브(22 Phase 2) — /keywords 페이지·사이트맵 섹션·문서 AEO 강화·카테고리 추천. */
class KeywordInsightTest extends TestCase
{
    use RefreshDatabase;

    private function tree(): array
    {
        $parent = KeywordCategory::create(['type' => 'shopping', 'name' => '아웃도어', 'slug' => '아웃도어', 'is_active' => true]);
        $child = KeywordCategory::create(['type' => 'shopping', 'name' => '캠핑용품', 'slug' => '캠핑용품', 'parent_id' => $parent->id, 'is_active' => true, 'description' => '캠핑 장비 키워드 모음']);
        $inactive = KeywordCategory::create(['type' => 'shopping', 'name' => '비공개카테고리', 'slug' => '비공개', 'is_active' => false]);

        $doc = KeywordSearch::create(['origin' => 'hub', 'category_id' => $child->id, 'keyword' => '캠핑의자', 'monthly_total' => 52700, 'comp_idx' => '높음', 'grade' => 'A']);
        KeywordSearch::create(['origin' => 'hub', 'category_id' => $parent->id, 'keyword' => '등산화', 'monthly_total' => 88000]);

        return [$parent, $child, $inactive, $doc];
    }

    public function test_keywords_index_lists_active_categories_and_top_docs(): void
    {
        [$parent, $child, $inactive] = $this->tree();
        // 사용자 검색 내역(origin=user)은 허브에 노출되지 않는다
        $u = User::create(['name' => 'u', 'email' => 'ki@rf.kr', 'password' => 'x1234567']);
        KeywordSearch::create(['user_id' => $u->id, 'origin' => 'user', 'keyword' => '사용자전용키워드', 'monthly_total' => 999999]);

        $html = $this->get('/keywords')->assertOk()->getContent();

        $this->assertStringContainsString('아웃도어', $html);
        $this->assertStringContainsString('캠핑용품', $html);
        $this->assertStringContainsString('캠핑의자 키워드 분석', $html);
        $this->assertStringNotContainsString('비공개카테고리', $html);
        $this->assertStringNotContainsString('사용자전용키워드', $html);
        $this->assertStringNotContainsString('noindex', $html);
    }

    public function test_category_page_aggregates_children_and_renders_jsonld(): void
    {
        [$parent, $child] = $this->tree();

        // 대분류 — 하위 카테고리 문서까지 합산(2건), 하위 카테고리 배지 노출
        $html = $this->get('/keywords/'.rawurlencode($parent->slug))->assertOk()->getContent();
        $this->assertStringContainsString('캠핑의자 키워드 분석', $html);
        $this->assertStringContainsString('등산화 키워드 분석', $html);
        $this->assertStringContainsString('BreadcrumbList', $html);
        $this->assertStringContainsString('CollectionPage', $html);

        // 소분류 — 자기 문서만 + 형제/설명
        $html = $this->get('/keywords/'.rawurlencode($child->slug))->assertOk()->getContent();
        $this->assertStringContainsString('캠핑의자 키워드 분석', $html);
        $this->assertStringNotContainsString('등산화 키워드 분석', $html);
        $this->assertStringContainsString('캠핑 장비 키워드 모음', $html);

        // 미존재·비활성은 404
        $this->get('/keywords/no-such')->assertNotFound();
        $this->get('/keywords/'.rawurlencode('비공개'))->assertNotFound();
    }

    public function test_sitemap_has_keywords_section_only_when_docs_exist(): void
    {
        // 발행 문서 없음 → 섹션 미노출
        $this->assertStringNotContainsString('sitemap-keywords', $this->get('/sitemap.xml')->assertOk()->getContent());
    }

    public function test_sitemap_keywords_section_lists_category_urls(): void
    {
        [$parent, $child] = $this->tree();

        $index = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/sitemap-keywords.xml', $index);

        $xml = $this->get('/sitemap-keywords.xml')->assertOk()->getContent();
        $this->assertStringContainsString(route('keywords.index'), $xml);
        $this->assertStringContainsString(route('keywords.category', $parent->slug), $xml);
        $this->assertStringContainsString(route('keywords.category', $child->slug), $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_keyword_share_page_has_aeo_faq_breadcrumb_and_cta(): void
    {
        [, $child, , $doc] = $this->tree();

        // 실수집 없이 검증 — 리포트 빌더를 결정적 vm 으로 대체
        $base = ['keyword' => '캠핑의자', 'monthly_pc' => 10000, 'monthly_mobile' => 42700, 'monthly_total' => 52700, 'comp_idx' => '높음', 'related' => []];
        $vm = KeywordAnalysisPresenter::build('캠핑의자', $base, null, null);
        $this->mock(KeywordReportBuilder::class, function ($m) use ($vm) {
            $m->shouldReceive('build')->andReturn(['vm' => $vm, 'saturation' => null, 'popular' => [], 'weekday' => null, 'autocomplete' => []]);
        });

        $html = $this->get($doc->shareUrl())->assertOk()->getContent();

        // AEO 요약 답변 + 출처·기준일(GEO)
        $this->assertStringContainsString('요약 답변', $html);
        $this->assertStringContainsString('월 약 52,700회', $html);
        $this->assertStringContainsString('자체 집계', $html);
        // 가시 FAQ = FAQPage JSON-LD 동일 문항
        $this->assertStringContainsString('자주 묻는 질문', $html);
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $blocks = array_map(fn ($j) => json_decode($j, true), $m[1]);
        $faq = collect($blocks)->first(fn ($b) => ($b['@type'] ?? '') === 'FAQPage');
        $this->assertNotNull($faq);
        $this->assertGreaterThanOrEqual(2, count($faq['mainEntity'])); // 검색량 + 경쟁강도(상세 없음 상태)
        // 브레드크럼 — 홈 > 키워드 인사이트 > 카테고리 > 키워드 (JSON-LD + 가시)
        $crumb = collect($blocks)->first(fn ($b) => ($b['@type'] ?? '') === 'BreadcrumbList');
        $names = array_column($crumb['itemListElement'], 'name');
        $this->assertSame(['홈', '키워드 인사이트', '캠핑용품', '캠핑의자 키워드 분석'], $names);
        $this->assertStringContainsString('키워드 인사이트', $html);
        // 퍼널 CTA
        $this->assertStringContainsString('무료로 시작', $html);
    }

    public function test_related_docs_prefers_same_category_hub_docs(): void
    {
        [, $child, , $doc] = $this->tree();
        KeywordSearch::create(['origin' => 'hub', 'category_id' => $child->id, 'keyword' => '캠핑테이블', 'monthly_total' => 30000]);

        $sections = app(RelatedDocsService::class)->sectionsFor($doc->fresh());

        $this->assertSame("'캠핑용품' 카테고리 인기 키워드", $sections[0]['title']);
        $titles = array_column($sections[0]['items'], 'title');
        $this->assertContains('캠핑테이블 키워드 분석', $titles);
        // 페이지 전체에서 같은 제목이 두 번 추천되지 않는다(섹션 간 중복 제거)
        $all = collect($sections)->flatMap(fn ($s) => array_column($s['items'], 'title'));
        $this->assertSame($all->count(), $all->unique()->count());
    }
}
