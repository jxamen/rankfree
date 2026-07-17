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

    /** 허브(/keywords)는 검색 진입점 — 카테고리 나열은 타입 홈으로 이관됐다. */
    public function test_keywords_index_is_search_entry_with_type_links(): void
    {
        $this->tree();
        // 사용자 검색 내역(origin=user)은 허브에 노출되지 않는다
        $u = User::create(['name' => 'u', 'email' => 'ki@rf.kr', 'password' => 'x1234567']);
        KeywordSearch::create(['user_id' => $u->id, 'origin' => 'user', 'keyword' => '사용자전용키워드', 'monthly_total' => 999999]);

        $html = $this->get('/keywords')->assertOk()->getContent();

        // 검색 폼 + 타입 메뉴 링크 + 사이트링크 검색창 신호
        $this->assertStringContainsString('action="'.route('keywords.search').'"', $html);
        $this->assertStringContainsString(route('keywords.type', 'place'), $html);
        $this->assertStringContainsString(route('keywords.type', 'shopping'), $html);
        $this->assertStringContainsString('SearchAction', $html);
        // 인기 리포트는 유지, 카테고리 덤프(소분류 나열)는 제거
        $this->assertStringContainsString('캠핑의자 키워드 분석', $html);
        $this->assertStringNotContainsString('캠핑 장비 키워드 모음', $html);
        $this->assertStringNotContainsString('사용자전용키워드', $html);
        $this->assertStringNotContainsString('noindex', $html);
    }

    /** 타입 홈 = 카테고리 메뉴. 타입별로 나열 방식이 다르고 서로 섞이지 않는다. */
    public function test_type_home_lists_categories_by_type(): void
    {
        [$parent, $child] = $this->tree();
        $place = KeywordCategory::create(['type' => 'place', 'name' => '맛집·음식점', 'slug' => '맛집-음식점', 'is_active' => true]);
        KeywordSearch::create(['origin' => 'hub', 'category_id' => $place->id, 'keyword' => '강남역 맛집', 'region' => '강남역', 'region_type' => 'hotplace', 'monthly_total' => 74100]);

        $shopping = $this->get('/keywords/shopping')->assertOk()->getContent();
        $this->assertStringContainsString('아웃도어', $shopping);
        $this->assertStringContainsString('캠핑용품', $shopping);   // 소분류 인덱스
        $this->assertStringNotContainsString('비공개카테고리', $shopping);
        $this->assertStringNotContainsString('맛집·음식점', $shopping);  // 타입이 섞이지 않는다
        $this->assertStringNotContainsString('noindex', $shopping);

        $placeHtml = $this->get('/keywords/place')->assertOk()->getContent();
        $this->assertStringContainsString('맛집·음식점', $placeHtml);       // 업종 셀렉트 option
        $this->assertStringContainsString('서울 (1)', $placeHtml);          // 지역 셀렉트 1단계(시/도)
        $this->assertStringNotContainsString('아웃도어', $placeHtml);
    }

    /** 플레이스 지역 드릴다운 — 시/도 → 시/군/구 → 동·상권, 고른 지역의 키워드가 나열된다. */
    public function test_place_region_drilldown(): void
    {
        $cat = KeywordCategory::create(['type' => 'place', 'name' => '맛집·음식점', 'slug' => '맛집-음식점', 'is_active' => true]);
        $hair = KeywordCategory::create(['type' => 'place', 'name' => '헤어샵', 'slug' => '헤어샵', 'is_active' => true]);
        $mk = fn ($catId, $kw, $region, $rt, $total) => KeywordSearch::create([
            'origin' => 'hub', 'category_id' => $catId, 'keyword' => $kw,
            'region' => $region, 'region_type' => $rt, 'monthly_total' => $total,
        ]);
        $mk($cat->id, '강남역 맛집', '강남역', 'hotplace', 74100);   // 서울 > 강남구
        $mk($cat->id, '역삼동 맛집', '역삼동', 'dong', 12000);       // 서울 > 강남구
        $mk($cat->id, '홍대 맛집', '홍대', 'hotplace', 40000);       // 서울 > 마포구
        $mk($cat->id, '가경동 맛집', '가경동', 'dong', 10060);       // 충북 > 청주시
        $mk($hair->id, '강남역 미용실', '강남역', 'hotplace', 3000);

        // 목록은 게시판형 표 — 키워드 자체가 링크 텍스트
        $row = fn (string $kw) => '>'.$kw.'</a>';

        // 1단계 — 시/도 셀렉트(서울 4 · 충북 1). 고르기 전 첫 진입은 최근 발행 문서를 보여준다(최대 30건)
        $lv1 = $this->get('/keywords/place')->assertOk()->getContent();
        $this->assertStringContainsString('서울 (4)', $lv1);
        $this->assertStringContainsString('충북 (1)', $lv1);
        $this->assertStringContainsString('최근 발행 키워드', $lv1);
        $this->assertStringContainsString($row('강남역 맛집'), $lv1);   // 지역 미선택이어도 최근 문서는 노출

        // 2단계 — 서울 선택 시 시/군/구(강남구 3 · 마포구 1) + 서울 키워드만 목록에
        $lv2 = $this->get('/keywords/place?sido=서울')->assertOk()->getContent();
        $this->assertStringContainsString('강남구 (3)', $lv2);
        $this->assertStringContainsString('마포구 (1)', $lv2);
        $this->assertStringContainsString($row('강남역 맛집'), $lv2);
        $this->assertStringNotContainsString($row('가경동 맛집'), $lv2);   // 충북 문서 제외

        // 3단계 — 강남구 선택 시 동·상권(강남역·역삼동)
        $lv3 = $this->get('/keywords/place?sido=서울&sgg=강남구')->assertOk()->getContent();
        $this->assertStringContainsString('강남역 (2)', $lv3);
        $this->assertStringContainsString($row('역삼동 맛집'), $lv3);
        $this->assertStringNotContainsString($row('홍대 맛집'), $lv3);     // 마포구 제외

        // 최종 — 지역 선택 시 그 지역 키워드가 쭉
        $leaf = $this->get('/keywords/place?sido=서울&sgg=강남구&region=강남역')->assertOk()->getContent();
        $this->assertStringContainsString($row('강남역 맛집'), $leaf);
        $this->assertStringContainsString($row('강남역 미용실'), $leaf);
        $this->assertStringNotContainsString($row('역삼동 맛집'), $leaf);

        // 업종 + 지역 조합 — 헤어샵으로 좁히면 맛집 문서는 빠진다
        $byCat = $this->get('/keywords/place?cat=헤어샵&sido=서울&sgg=강남구')->assertOk()->getContent();
        $this->assertStringContainsString($row('강남역 미용실'), $byCat);
        $this->assertStringNotContainsString($row('강남역 맛집'), $byCat);

        // 셀렉트 줄 우측 검색 — 지역 선택 없이 검색어만으로도 목록이 나온다
        $bySearch = $this->get('/keywords/place?q=홍대')->assertOk()->getContent();
        $this->assertStringContainsString($row('홍대 맛집'), $bySearch);
        $this->assertStringNotContainsString($row('가경동 맛집'), $bySearch);

        // 없는 지역은 조용히 1단계로(오류 아님)
        $this->get('/keywords/place?sido=없는지역')->assertOk();
    }

    /** 라우트 순서 회귀 — 고정 경로가 {slug} 에 먹히면 안 되고, 한글 슬러그는 카테고리로 가야 한다. */
    public function test_static_routes_win_over_category_slug(): void
    {
        [$parent, $child] = $this->tree();

        $this->get('/keywords/place')->assertOk();
        $this->get('/keywords/shopping')->assertOk();
        $this->get('/keywords/search?q=캠핑')->assertOk();
        $this->get('/keywords/'.rawurlencode('캠핑용품'))->assertOk();   // 한글 슬러그는 {type} 을 통과해 카테고리로

        // 예약어는 슬러그로 만들어지지 않는다(-cat 접미)
        $this->assertSame('place-cat', KeywordCategory::makeSlug('Place'));
        $this->assertSame('search-cat', KeywordCategory::makeSlug('search'));
    }

    /** 검색 결과 — 색인 정책·비공개 가드·타입 필터·카테고리 깔때기. */
    public function test_search_results_policy_and_privacy(): void
    {
        [$parent, $child] = $this->tree();
        $u = User::create(['name' => 'u', 'email' => 'sq@rf.kr', 'password' => 'x1234567']);
        KeywordSearch::create(['user_id' => $u->id, 'origin' => 'user', 'keyword' => '캠핑 사용자전용', 'monthly_total' => 999999]);

        $html = $this->get('/keywords/search?q=캠핑')->assertOk()->getContent();

        // 색인 정책: noindex, follow + 정규화 자기참조 canonical
        $this->assertStringContainsString('content="noindex, follow"', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('keywords.search', ['q' => '캠핑']).'"', $html);
        // ★ 타 사용자 검색 내역 미노출(21 비공개 원칙)
        $this->assertStringNotContainsString('사용자전용', $html);
        // 매칭 카테고리(색인 자산)로 되돌리는 링크가 문서 카드보다 앞
        $this->assertStringContainsString(route('keywords.category', '캠핑용품'), $html);
        $this->assertLessThan(
            strpos($html, '캠핑의자 키워드 분석'),
            strpos($html, route('keywords.category', '캠핑용품')),
        );

        // utm 은 canonical 에 실리지 않는다(리다이렉트 없이 200)
        $utm = $this->get('/keywords/search?q=캠핑&utm_source=x')->assertOk()->getContent();
        $this->assertStringNotContainsString('utm_source', substr($utm, 0, strpos($utm, '</head>')));

        // 타입 필터 — 플레이스로 좁히면 쇼핑 문서는 안 나온다
        $place = $this->get('/keywords/search?q=캠핑&type=place')->assertOk()->getContent();
        $this->assertStringNotContainsString('캠핑의자 키워드 분석', $place);
    }

    /** 자동완성 API — 발행 문서만, 최소 길이, 색인 차단 헤더. */
    public function test_suggest_api(): void
    {
        [$parent, $child] = $this->tree();
        $u = User::create(['name' => 'u', 'email' => 'sg@rf.kr', 'password' => 'x1234567']);
        KeywordSearch::create(['user_id' => $u->id, 'origin' => 'user', 'keyword' => '캠핑 사용자전용', 'monthly_total' => 999999]);

        $res = $this->getJson('/api/keywords/suggest?q=캠핑')->assertOk();
        $res->assertHeader('X-Robots-Tag', 'noindex');
        $keywords = array_column($res->json('keywords'), 'keyword');
        $this->assertContains('캠핑의자', $keywords);
        $this->assertNotContains('캠핑 사용자전용', $keywords);   // ★ origin=user 미노출
        $this->assertSame('캠핑용품', $res->json('categories.0.name'));

        // 최소 2자 미만은 빈 결과
        $this->assertSame([], $this->getJson('/api/keywords/suggest?q=캠')->assertOk()->json('keywords'));
    }

    /** 카테고리 레거시 ?q= 는 색인 제외(내용이 달라진다). */
    public function test_category_query_variant_is_noindex(): void
    {
        [, $child] = $this->tree();

        $this->assertStringContainsString('content="noindex, follow"',
            $this->get('/keywords/'.rawurlencode($child->slug).'?q=의자')->assertOk()->getContent());
        $this->assertStringNotContainsString('noindex',
            $this->get('/keywords/'.rawurlencode($child->slug))->assertOk()->getContent());
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

        // 타입 홈 — 문서 있는 타입만 노출(쇼핑만 발행된 상태), 검색·자동완성은 절대 미포함
        $this->assertStringContainsString(route('keywords.type', 'shopping'), $xml);
        $this->assertStringNotContainsString(route('keywords.type', 'place'), $xml);
        $this->assertStringNotContainsString('/keywords/search', $xml);
        $this->assertStringNotContainsString('suggest', $xml);
    }

    /** 발행 문서 없는 타입 홈은 색인 대상이 아니다(빈 목록·도어웨이 방지). */
    public function test_empty_type_home_is_noindex(): void
    {
        $this->tree();   // 쇼핑만 발행
        KeywordCategory::create(['type' => 'place', 'name' => '맛집·음식점', 'slug' => '맛집-음식점', 'is_active' => true]);

        $this->assertStringContainsString('content="noindex, follow"', $this->get('/keywords/place')->assertOk()->getContent());
        $this->assertStringNotContainsString('noindex', $this->get('/keywords/shopping')->assertOk()->getContent());
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
        $this->assertSame(['홈', '키워드 인사이트', '쇼핑', '캠핑용품', '캠핑의자 키워드 분석'], $names);
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
