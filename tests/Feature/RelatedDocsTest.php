<?php

namespace Tests\Feature;

use App\Domain\Seo\RelatedDocsService;
use App\Models\KeywordSearch;
use App\Models\MarketAnalysis;
use App\Models\PlaceRankSlot;
use App\Models\PlaceStoreAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 관련 문서 추천(RelatedDocsService) — 공개 리포트 5종 간 내부 링크 그물(22_KEYWORD_CONTENT_HUB). */
class RelatedDocsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'u@rf.kr'): User
    {
        return User::create(['name' => 'u', 'email' => $email, 'password' => 'x1234567']);
    }

    private function store(User $user, string $placeId, string $name, string $keyword): PlaceStoreAnalysis
    {
        return PlaceStoreAnalysis::create([
            'user_id' => $user->id, 'place_id' => $placeId, 'name' => $name, 'keyword' => $keyword, 'detail' => [],
        ]);
    }

    public function test_store_share_page_recommends_related_docs_across_types(): void
    {
        $user = $this->user();
        $doc = $this->store($user, '100', '제주 오션 호텔', '제주도호텔');
        KeywordSearch::create(['user_id' => $user->id, 'keyword' => '제주도호텔', 'monthly_total' => 120000]);
        MarketAnalysis::create(['user_id' => $user->id, 'keyword' => '제주도 호텔', 'snapshot' => ['top_products' => []]]);
        KeywordSearch::create(['user_id' => $user->id, 'keyword' => '강남맛집', 'monthly_total' => 90000]);

        $html = $this->get($doc->shareUrl())->assertOk()->getContent();

        $this->assertStringContainsString('함께 보면 좋은 분석', $html);
        // 키워드·시장 문서가 크로스 추천되고, 무관 키워드는 추천되지 않는다
        $this->assertStringContainsString('제주도호텔 키워드 분석', $html);
        $this->assertStringContainsString('제주도 호텔 쇼핑 시장 분석', $html);
        $this->assertStringContainsString('월 120,000회 검색', $html);
        $this->assertStringNotContainsString('강남맛집', $html);
    }

    public function test_tracking_slots_are_never_recommended(): void
    {
        $user = $this->user();
        PlaceRankSlot::create([
            'user_id' => $user->id, 'keyword' => '제주도호텔', 'place_id' => '1',
            'place_name' => '제주도호텔 추적매장', 'is_active' => true,
        ]);
        $doc = $this->store($user, '200', '제주도호텔 그랜드', '제주도호텔');

        $html = $this->get($doc->shareUrl())->assertOk()->getContent();

        // 순위 추적 슬롯(비공개)은 이름도, 공유 경로(/place/)도 노출되면 안 된다
        $this->assertStringNotContainsString('추적매장', $html);
        $this->assertStringNotContainsString('href="'.url('/place').'/', $html);
    }

    public function test_same_type_section_falls_back_to_recent_docs(): void
    {
        $user = $this->user();
        $doc = $this->store($user, '300', '역삼 파스타집', '역삼 파스타');
        $other = $this->store($user, '301', '홍대 네일샵', '홍대네일');

        $html = $this->get($doc->shareUrl())->assertOk()->getContent();

        // 용어 매칭이 없으면 같은 타입 섹션은 "최신" 제목으로 최근 문서를 채운다
        $this->assertStringContainsString('최신 플레이스 매장 분석', $html);
        $this->assertStringContainsString('홍대 네일샵 매장 분석', $html);
        $this->assertStringContainsString($other->shareUrl(), $html);
    }

    public function test_duplicate_titles_deduped_and_self_excluded(): void
    {
        $u1 = $this->user('a@rf.kr');
        $u2 = $this->user('b@rf.kr');
        $mine = KeywordSearch::create(['user_id' => $u1->id, 'keyword' => '여름원피스', 'monthly_total' => 1000]);
        KeywordSearch::create(['user_id' => $u2->id, 'keyword' => '여름원피스', 'monthly_total' => 2000]);
        KeywordSearch::create(['user_id' => $u1->id, 'keyword' => '여름원피스 세트', 'monthly_total' => 500]);

        $sections = app(RelatedDocsService::class)->sectionsFor($mine);
        $kw = collect($sections)->firstWhere('title', '함께 보면 좋은 키워드 분석');
        $this->assertNotNull($kw);

        $titles = array_column($kw['items'], 'title');
        $this->assertContains('여름원피스 키워드 분석', $titles);
        $this->assertContains('여름원피스 세트 키워드 분석', $titles);
        // 같은 키워드 문서(타 사용자 중복 저장)는 하나로 합쳐진다
        $this->assertSame(count($titles), count(array_unique($titles)));
        // 자기 자신은 추천에 나오지 않는다
        foreach ($kw['items'] as $it) {
            $this->assertNotSame($mine->shareUrl(), $it['url']);
        }
    }
}
