<?php

namespace Tests\Feature;

use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\PlaceRankSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SEO/AEO/GEO — canonical·OG·JSON-LD·noindex·sitemap·robots·llms.txt. */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    /** 페이지 HTML에서 JSON-LD 블록들을 파싱해 반환. */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(fn ($j) => json_decode($j, true), $m[1]);
    }

    public function test_home_has_full_seo_head_and_faq(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // 기본 메타 + canonical + OG + 트위터 + 파비콘
        $this->assertStringContainsString('<link rel="canonical"', $html);
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringContainsString('og:site_name" content="랭크프리"', $html);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
        $this->assertStringContainsString('favicon.svg', $html);

        // JSON-LD — 파싱 가능해야 하고 @graph 에 4종 스키마
        $blocks = $this->jsonLd($html);
        $this->assertNotEmpty($blocks, 'JSON-LD 블록 없음');
        $this->assertNotNull($blocks[0], 'JSON-LD 가 유효한 JSON 이 아님');
        $types = array_column($blocks[0]['@graph'] ?? [], '@type');
        foreach (['Organization', 'WebSite', 'SoftwareApplication', 'FAQPage'] as $t) {
            $this->assertContains($t, $types, "$t 스키마 누락");
        }

        // AEO — 보이는 FAQ 섹션이 JSON-LD 와 동일 문항
        $this->assertStringContainsString('자주 묻는 질문', $html);
        $this->assertStringContainsString('랭크프리는 무료인가요?', $html);
        $faq = collect($blocks[0]['@graph'])->firstWhere('@type', 'FAQPage');
        $this->assertCount(6, $faq['mainEntity']);
        $this->assertSame('랭크프리는 무료인가요?', $faq['mainEntity'][0]['name']);

        // 색인 허용(홈에 noindex 없어야)
        $this->assertStringNotContainsString('noindex', $html);
    }

    public function test_share_report_is_noindex_with_og(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'seo@rf.kr', 'password' => 'x1234567']);
        $slot = PlaceRankSlot::create([
            'user_id' => $user->id, 'keyword' => '강남 맛집', 'place_id' => '111', 'place_name' => '테스트매장',
            'is_active' => true, 'share_token' => 'seotesttoken123',
        ]);

        // 구 토큰 URL 은 SEO 슬러그로 301
        $this->get('/r/seotesttoken123')->assertStatus(301)->assertRedirect($slot->shareUrl());

        // 정규 슬러그 URL(/place/테스트매장)은 noindex 리포트로 열림
        $html = $this->get($slot->shareUrl())->assertOk()->getContent();
        $this->assertStringContainsString('noindex, nofollow', $html);
        $this->assertStringContainsString('property="og:title"', $html);   // 카톡 미리보기 유지
        $this->assertStringContainsString('og-image.png', $html);
    }

    public function test_community_post_has_jsonld_and_canonical(): void
    {
        $cat = CommunityCategory::create(['slug' => 'free', 'name' => '자유게시판', 'is_active' => true, 'sort_order' => 1]);
        $user = User::create(['name' => '글쓴이', 'email' => 'w@rf.kr', 'password' => 'x1234567']);
        $post = CommunityPost::create([
            'category_id' => $cat->id, 'author_type' => 'user', 'user_id' => $user->id,
            'title' => 'SEO 테스트 글', 'body' => '<p>본문 내용입니다.</p>', 'views' => 3, 'likes_count' => 1, 'comments_count' => 0,
        ]);

        $html = $this->get('/community/post/'.$post->id)->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="'.route('community.show', $post).'"', $html);
        $this->assertStringContainsString('og:type" content="article"', $html);

        $blocks = $this->jsonLd($html);
        $forum = collect($blocks)->first(fn ($b) => ($b['@type'] ?? '') === 'DiscussionForumPosting');
        $this->assertNotNull($forum, 'DiscussionForumPosting JSON-LD 누락');
        $this->assertSame('SEO 테스트 글', $forum['headline']);
        $this->assertSame('글쓴이', $forum['author']['name']);
    }

    public function test_community_category_canonical_and_title(): void
    {
        CommunityCategory::create(['slug' => 'qna', 'name' => '질문답변', 'description' => '서로 묻고 답하는 공간', 'is_active' => true, 'sort_order' => 1]);

        $html = $this->get('/community?cat=qna')->assertOk()->getContent();
        $this->assertStringContainsString('<title>질문답변 · 커뮤니티 · 랭크프리</title>', $html);
        $this->assertStringContainsString('canonical" href="'.route('community', ['cat' => 'qna']).'"', $html);
    }

    public function test_auth_pages_seo(): void
    {
        // 로그인 — 색인 허용 + canonical + og
        $login = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('rel="canonical"', $login);
        $this->assertStringContainsString('property="og:title"', $login);
        $this->assertStringNotContainsString('noindex', $login);
        // 계정 유틸 — noindex
        $this->assertStringContainsString('noindex', $this->get('/find-email')->assertOk()->getContent());
        $this->assertStringContainsString('noindex', $this->get('/forgot-password')->assertOk()->getContent());
    }

    public function test_sitemap_xml(): void
    {
        CommunityCategory::create(['slug' => 'tips', 'name' => '꿀팁', 'is_active' => true, 'sort_order' => 1]);

        // /sitemap.xml = 사이트맵 인덱스(자식 사이트맵 목록)
        $res = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringContainsString('application/xml', $res->headers->get('content-type'));
        $index = $res->getContent();
        $this->assertStringContainsString('<sitemapindex', $index);
        $this->assertStringContainsString(url('/sitemap-pages.xml'), $index);
        $this->assertNotFalse(simplexml_load_string($index));

        // 정적/카테고리 URL 은 pages 섹션에
        $pages = $this->get('/sitemap-pages.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<urlset', $pages);
        $this->assertStringContainsString(route('home'), $pages);
        $this->assertStringContainsString(route('community', ['cat' => 'tips']), $pages);
        $this->assertNotFalse(simplexml_load_string($pages));
    }

    /** 회귀: keyword 섹션이 허브 발행 문서(origin=hub)를 포함하고, 불량 updated_at 에도 500 나지 않아야 한다. */
    public function test_sitemap_keyword_section_includes_hub_docs_and_survives_bad_date(): void
    {
        $ok = \App\Models\KeywordSearch::create(['keyword' => '강릉 맛집', 'origin' => 'hub', 'user_id' => null]);
        $bad = \App\Models\KeywordSearch::create(['keyword' => '제주 호텔', 'origin' => 'hub', 'user_id' => null]);
        // 불량 날짜(MariaDB zero-date 재현) — cast 우회해 raw 저장
        \Illuminate\Support\Facades\DB::table('keyword_searches')->where('id', $bad->id)->update(['updated_at' => '0000-00-00 00:00:00']);

        $xml = $this->get('/sitemap-keyword.xml')->assertOk()->getContent();
        $this->assertNotFalse(simplexml_load_string($xml), '유효 XML 이어야(500/파손 아님)');
        // 허브 문서 슬러그가 포함(퍼센트 인코딩)
        $this->assertStringContainsString('/keyword/'.rawurlencode($ok->slug), $xml);
        $this->assertStringContainsString('/keyword/'.rawurlencode($bad->slug), $xml);
        // 불량 날짜 행은 lastmod 없이(0000-00-00 미출력) 정상 렌더
        $this->assertStringNotContainsString('0000-00-00', $xml);
    }

    /** 회귀: 캐시 저장이 실패해도(예: DB max_allowed_packet 초과) 사이트맵은 500 나지 않고 XML 을 서빙한다. */
    public function test_sitemap_survives_cache_write_failure(): void
    {
        CommunityCategory::create(['slug' => 'tips', 'name' => '꿀팁', 'is_active' => true, 'sort_order' => 1]);
        // 캐시 put 이 항상 던지도록 목킹(패킷 초과 재현)
        \Illuminate\Support\Facades\Cache::shouldReceive('get')->andReturn(null);
        \Illuminate\Support\Facades\Cache::shouldReceive('put')->andThrow(new \RuntimeException('packet too big'));

        $this->get('/sitemap.xml')->assertOk();               // 인덱스 500 아님
        $pages = $this->get('/sitemap-pages.xml')->assertOk()->getContent();  // 섹션 500 아님
        $this->assertStringContainsString('<urlset', $pages);
    }

    public function test_post_title_with_script_tag_cannot_break_jsonld(): void
    {
        $cat = CommunityCategory::create(['slug' => 'free', 'name' => '자유', 'is_active' => true, 'sort_order' => 1]);
        $user = User::create(['name' => 'u', 'email' => 'xss@rf.kr', 'password' => 'x1234567']);
        $post = CommunityPost::create([
            'category_id' => $cat->id, 'author_type' => 'user', 'user_id' => $user->id,
            'title' => '제목</script><script>alert(1)</script>', 'body' => '<p>x</p>',
        ]);

        $html = $this->get('/community/post/'.$post->id)->assertOk()->getContent();
        // JSON-LD 블록 원문에 닫는 </script> 조기 등장(탈출) 금지 — JSON_HEX_TAG 로 < 이스케이프돼야 함
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        foreach ($m[1] as $block) {
            $this->assertStringNotContainsString('</script', $block, 'JSON-LD 내부에서 스크립트 탈출 발생');
            $this->assertNotNull(json_decode($block), 'JSON-LD 파손');
        }
    }

    public function test_persona_post_has_no_forum_jsonld(): void
    {
        $cat = CommunityCategory::create(['slug' => 'free', 'name' => '자유', 'is_active' => true, 'sort_order' => 1]);
        $persona = \App\Models\Persona::create(['nickname' => '가상이', 'is_active' => true]);
        $post = CommunityPost::create([
            'category_id' => $cat->id, 'author_type' => 'persona', 'persona_id' => $persona->id,
            'title' => '페르소나 글', 'body' => '<p>내용</p>',
        ]);

        $html = $this->get('/community/post/'.$post->id)->assertOk()->getContent();
        // 구글 포럼 마크업은 실사용자 UGC 전용 — 페르소나 글엔 미출력, 빵부스러기는 유지
        $this->assertStringNotContainsString('DiscussionForumPosting', $html);
        $this->assertStringContainsString('BreadcrumbList', $html);
    }

    public function test_support_page_and_rss_feed(): void
    {
        $html = $this->get('/support')->assertOk()->getContent();
        $this->assertStringContainsString('상담 신청하기', $html);
        $this->assertStringContainsString(route('lead.store'), $html);

        $cat = CommunityCategory::create(['slug' => 'free', 'name' => '자유', 'is_active' => true, 'sort_order' => 1]);
        $user = User::create(['name' => 'u', 'email' => 'rss@rf.kr', 'password' => 'x1234567']);
        CommunityPost::create(['category_id' => $cat->id, 'author_type' => 'user', 'user_id' => $user->id, 'title' => 'RSS 테스트', 'body' => '<p>x</p>']);

        $rss = $this->get('/community/feed')->assertOk();
        $this->assertStringContainsString('application/rss+xml', $rss->headers->get('content-type'));
        $this->assertStringContainsString('RSS 테스트', $rss->getContent());
        $this->assertNotFalse(simplexml_load_string($rss->getContent()));
    }

    public function test_sitemap_excludes_redirects_includes_support(): void
    {
        $pages = $this->get('/sitemap-pages.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/rank-check', $pages); // 302 리다이렉트 — 제외
        $this->assertStringContainsString(route('support'), $pages);
    }

    public function test_sitemap_excludes_tracking_slots(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'track@rf.kr', 'password' => 'x1234567']);
        PlaceRankSlot::create(['user_id' => $user->id, 'keyword' => '강남 맛집', 'place_id' => '1', 'place_name' => '추적매장', 'is_active' => true]);

        // 순위 추적 슬롯(/place·/shopping)·경쟁분석(/compete)은 사이트맵에 노출 금지
        $index = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/sitemap-place.xml', $index);
        $this->assertStringNotContainsString('/sitemap-shopping.xml', $index);
        $this->assertStringNotContainsString('/sitemap-compete.xml', $index);
        $this->get('/sitemap-place.xml')->assertNotFound();
        $this->get('/sitemap-compete.xml')->assertNotFound();
    }

    public function test_robots_and_llms_txt_files(): void
    {
        // robots.txt·ai.txt 는 ASCII 정적 파일(한글 없음 → 서버 charset 설정 무관, 모지바케 불가)
        $robots = file_get_contents(public_path('robots.txt'));
        $this->assertTrue(mb_check_encoding($robots, 'ASCII'), 'robots.txt 는 ASCII 여야 함(모지바케 방지)');
        $this->assertStringContainsString('Disallow: /console', $robots);
        $this->assertStringContainsString('Disallow: /admin', $robots);
        $this->assertStringContainsString('Sitemap: https://rankfree.kr/sitemap.xml', $robots);
        $this->assertStringContainsString('User-agent: GPTBot', $robots);
        $this->assertStringContainsString('User-agent: ClaudeBot', $robots);
        $this->assertStringContainsString('/ai.txt', $robots);

        $ai = file_get_contents(public_path('ai.txt'));
        $this->assertTrue(mb_check_encoding($ai, 'ASCII'), 'ai.txt 는 ASCII 여야 함(모지바케 방지)');
        $this->assertStringContainsString('Disallow: /console', $ai);
        $this->assertStringContainsString('rankfree.kr/llms.txt', $ai);
        $this->assertStringContainsString('rankfree.kr/sitemap.xml', $ai);

        // llms.txt(한글 콘텐츠) 는 라우트로 charset=utf-8 보장 서빙 — /llms.txt, /llm.txt 동일
        foreach (['/llms.txt', '/llm.txt'] as $u) {
            $res = $this->get($u)->assertOk();
            $this->assertStringContainsString('charset=UTF-8', (string) $res->headers->get('content-type'));
            $res->assertSee('# 랭크프리')->assertSee('자체 수집·분석 기반 추정치')
                ->assertSee('/keyword/')->assertSee('rankfree.kr/ai.txt');
        }

        // 파비콘·OG 이미지 실파일 존재
        foreach (['favicon.svg', 'favicon-32.png', 'apple-touch-icon.png', 'og-image.png'] as $f) {
            $this->assertFileExists(public_path($f));
            $this->assertGreaterThan(0, filesize(public_path($f)), "$f 가 빈 파일");
        }
    }
}
