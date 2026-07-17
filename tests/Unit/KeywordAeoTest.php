<?php

namespace Tests\Unit;

use App\Domain\Keyword\KeywordAnalysisPresenter;
use PHPUnit\Framework\TestCase;

/** AEO 요약 답변·FAQ 생성(aeo) — 데이터 기반 결정적 템플릿(22 Phase 2). */
class KeywordAeoTest extends TestCase
{
    private function fullVm(): array
    {
        return [
            'keyword' => '캠핑의자', 'has_volume' => true, 'total' => 52700, 'pc' => 10000, 'mobile' => 42700,
            'comp_idx' => '높음', 'grade' => 'A',
            'season' => ['peak_months' => [6, 7], 'low_months' => [1, 2]],
            'insights' => ['summary' => '이 키워드는 여성(68%) 비중이 높고 25~34세가 검색의 45%를 차지합니다.'],
            'has_demo' => true,
            'gender' => ['female_pct' => 68.0, 'male_pct' => 32.0],
            'age' => [['label' => '25~34', 'pct' => 45.0], ['label' => '35~44', 'pct' => 30.0]],
        ];
    }

    public function test_full_data_builds_summary_and_four_faqs(): void
    {
        $aeo = KeywordAnalysisPresenter::aeo($this->fullVm());

        // 요약 — 검색량·경쟁·타겟(인사이트 문장 재사용)이 한 단락으로
        $this->assertStringContainsString('월 약 52,700회', $aeo['summary']);
        $this->assertStringContainsString("광고 경쟁강도는 '높음'", $aeo['summary']);
        $this->assertStringContainsString('여성(68%)', $aeo['summary']);

        // FAQ — 검색량·시기·타겟·경쟁 4문항
        $this->assertCount(4, $aeo['faq']);
        [$q1, $q2, $q3, $q4] = $aeo['faq'];
        $this->assertStringContainsString('월간 검색량', $q1['q']);
        $this->assertStringContainsString('52,700회', $q1['a']);
        $this->assertStringContainsString('언제', $q2['q']);
        $this->assertStringContainsString('6월·7월', $q2['a']);
        $this->assertStringContainsString('1월·2월', $q2['a']);
        $this->assertStringContainsString('누가', $q3['q']);
        $this->assertStringContainsString('여성 68% · 남성 32%', $q3['a']);
        $this->assertStringContainsString('25~34(45%)', $q3['a']);
        $this->assertStringContainsString('경쟁강도', $q4['q']);
        $this->assertStringContainsString('자체 추정', $q4['a']);
    }

    public function test_meta_description_is_real_summary(): void
    {
        $desc = KeywordAnalysisPresenter::metaDescription($this->fullVm());

        // 고정 홍보 문구가 아닌 실측 수치 문장
        $this->assertStringContainsString('월 약 52,700회', $desc);
        $this->assertStringContainsString("광고 경쟁강도는 '높음'", $desc);
        $this->assertStringNotContainsString('무료 분석 리포트', $desc);
    }

    public function test_meta_description_truncates_at_sentence_boundary(): void
    {
        $vm = $this->fullVm();
        $vm['insights']['summary'] = str_repeat('가', 300).'입니다.'; // 한도 초과 문장

        $desc = KeywordAnalysisPresenter::metaDescription($vm);

        // 검색량·경쟁 문장까지만 담고 문장 경계에서 끊는다
        $this->assertLessThanOrEqual(320, mb_strwidth($desc, 'UTF-8'));
        $this->assertStringContainsString('월 약 52,700회', $desc);
        $this->assertStringEndsWith('입니다.', $desc);
    }

    public function test_minimal_data_degrades_gracefully(): void
    {
        $aeo = KeywordAnalysisPresenter::aeo(['keyword' => '신상키워드']);

        $this->assertStringContainsString('집계 중', $aeo['summary']);
        $this->assertCount(1, $aeo['faq']); // 검색량 문항만(데이터 없는 문항은 생성 안 함)
        $this->assertStringContainsString('집계 중', $aeo['faq'][0]['a']);
    }
}
