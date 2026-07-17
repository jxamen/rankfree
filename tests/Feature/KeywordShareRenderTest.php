<?php

namespace Tests\Feature;

use App\Domain\Keyword\KeywordAnalysisPresenter;
use App\Models\KeywordSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 키워드 분석 본문 partial(콘솔/공개 공용) + 공개 공유 뷰 + 공유 토큰 검증. */
class KeywordShareRenderTest extends TestCase
{
    use RefreshDatabase;

    private function vm(): array
    {
        // shopTotal 지정 → 정보성/상업성 도넛 노출. detail=null → 상세(성별/트렌드)는 없음.
        return KeywordAnalysisPresenter::build('견과류', [
            'monthly_pc' => 1000, 'monthly_mobile' => 2000, 'monthly_total' => 3000, 'comp_idx' => '중간', 'related' => [],
        ], null, 5000);
    }

    public function test_public_keyword_body_renders_without_console_links(): void
    {
        $html = view('partials._keyword_body', [
            'vm' => $this->vm(), 'saturation' => null, 'popular' => [], 'weekday' => null,
            'autocomplete' => ['견과류 선물세트'], 'public' => true, 'shareUrl' => null,
        ])->render();

        $this->assertStringContainsString('견과류', $html);
        // 공개 뷰: 콘솔 전용 링크/섹션배치 AJAX/공유버튼 없음
        $this->assertStringNotContainsString('console.keyword.sections', $html);
        $this->assertStringNotContainsString('rfCopyShare', $html);
        $this->assertStringNotContainsString('/console/keyword?keyword=', $html);
    }

    public function test_public_share_page_has_header_no_sidebar(): void
    {
        $html = view('keyword.share', [
            'vm' => $this->vm(), 'saturation' => null, 'popular' => [], 'weekday' => null,
        ])->render();

        $this->assertStringContainsString('견과류', $html);
        $this->assertStringContainsString('키워드 분석 리포트', $html);
        $this->assertStringNotContainsString('id="rf-sidebar"', $html);
    }

    public function test_share_page_meta_description_is_real_summary(): void
    {
        $html = view('keyword.share', [
            'vm' => $this->vm(), 'saturation' => null, 'popular' => [], 'weekday' => null,
        ])->render();

        // meta/og/twitter description 이 고정 문구가 아닌 AEO 요약 답변(실측 수치 문장)
        $expected = KeywordAnalysisPresenter::metaDescription($this->vm());
        $this->assertStringContainsString('월 약 3,000회 검색되는 키워드', $expected);
        $this->assertStringContainsString('<meta name="description" content="'.e($expected).'">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="'.e($expected).'">', $html);
        $this->assertStringContainsString('<meta name="twitter:description" content="'.e($expected).'">', $html);
        $this->assertStringNotContainsString('포화도까지 무료 분석 리포트.">', $html);
    }

    public function test_console_keyword_body_has_console_links(): void
    {
        $html = view('partials._keyword_body', [
            'vm' => $this->vm(), 'saturation' => null, 'popular' => [], 'weekday' => null,
            'autocomplete' => ['견과류 선물세트'], 'public' => false, 'shareUrl' => 'http://localhost/k/ABC123',
        ])->render();

        $this->assertStringContainsString('견과류', $html);
        // 콘솔 뷰: 자동완성 콘솔 링크 노출
        $this->assertStringContainsString('견과류 선물세트', $html);
    }

    public function test_share_token_generated_and_stable(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
        $rec = KeywordSearch::create(['user_id' => $user->id, 'keyword' => '견과류', 'monthly_total' => 3000]);

        $t1 = $rec->shareToken();
        $this->assertNotEmpty($t1);
        $this->assertSame($t1, $rec->fresh()->shareToken());
    }

    public function test_bad_keyword_token_404(): void
    {
        $this->get('/k/nope-nope-nope')->assertNotFound();
    }
}
