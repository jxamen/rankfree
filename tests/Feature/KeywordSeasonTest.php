<?php

namespace Tests\Feature;

use App\Domain\Keyword\KeywordAnalysisPresenter;
use Tests\TestCase;

/** 키워드 시즌성 계산 — 성수기/준비월(성수기−2개월)/시즌 강도. */
class KeywordSeasonTest extends TestCase
{
    /** @param array<int,float> $byMonth  월=>비율(%) */
    private function monthRatio(array $byMonth): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[] = ['m' => $m, 'pct' => (float) ($byMonth[$m] ?? 0)];
        }

        return $out;
    }

    public function test_summer_seasonal_peak_and_prep_months(): void
    {
        // 6·7·8월 성수기, 나머지 저조
        $mr = $this->monthRatio([6 => 20, 7 => 22, 8 => 20] + array_fill(1, 12, 4));
        $s = KeywordAnalysisPresenter::season($mr);

        $this->assertNotNull($s);
        $this->assertTrue($s['is_seasonal']);
        $this->assertSame([6, 7, 8], $s['peak_months']);
        // 준비 시작 = 성수기 − 2개월
        $this->assertSame([4, 5, 6], $s['prep_months']);
        $this->assertSame(2, $s['lead_months']);
        $this->assertContains($s['level'], ['moderate', 'strong']);
    }

    public function test_prep_months_wrap_around_new_year(): void
    {
        // 1·2월 성수기 → 준비는 전년 11·12월
        $mr = $this->monthRatio([1 => 24, 2 => 22] + array_fill(1, 12, 4));
        $s = KeywordAnalysisPresenter::season($mr);

        $this->assertSame([1, 2], $s['peak_months']);
        $this->assertSame([11, 12], $s['prep_months']);
    }

    public function test_flat_keyword_is_not_seasonal(): void
    {
        $mr = $this->monthRatio(array_fill(1, 12, 8.33));
        $s = KeywordAnalysisPresenter::season($mr);

        $this->assertNotNull($s);
        $this->assertFalse($s['is_seasonal']);
        $this->assertSame('flat', $s['level']);
    }

    public function test_no_data_returns_null(): void
    {
        $this->assertNull(KeywordAnalysisPresenter::season($this->monthRatio([])));
    }

    /** detailModel 이 season 을 노출하고, 인사이트 시즌 카드와 성수기 표기가 일치한다. */
    public function test_detail_model_exposes_season_consistent_with_insight_cards(): void
    {
        $detail = ['monthly' => []];
        // 6·7·8월에 몰린 12개월 트렌드
        foreach ([1 => 40, 2 => 40, 3 => 60, 4 => 80, 5 => 120, 6 => 300, 7 => 320, 8 => 300, 9 => 120, 10 => 70, 11 => 45, 12 => 40] as $m => $v) {
            $detail['monthly'][] = ['label' => sprintf('2025-%02d', $m), 'total' => $v, 'pc' => 0, 'mobile' => 0];
        }
        $dm = KeywordAnalysisPresenter::detailModel($detail);

        $this->assertNotNull($dm['season']);
        $this->assertTrue($dm['season']['is_seasonal']);
        // 인사이트 시즌 카드의 성수기 값이 season.peak_months 와 같은 월을 가리킨다
        $seasonCard = collect($dm['insights']['cards'])->firstWhere('label', '성수기');
        $this->assertNotNull($seasonCard);
        $peakStr = implode('·', array_map(fn ($m) => $m.'월', $dm['season']['peak_months']));
        $this->assertSame($peakStr, $seasonCard['value']);
    }
}
