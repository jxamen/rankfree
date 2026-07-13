<?php

namespace Tests\Unit;

use App\Domain\Keyword\KeywordAnalysisPresenter as P;
use PHPUnit\Framework\TestCase;

class KeywordAnalysisPresenterTest extends TestCase
{
    /** 콘텐츠 포화 지수 — 통합 발행량(누적)÷월검색량 배율을 0~100% 포화도(블랙키위식)로 정규화. */
    public function test_saturation(): void
    {
        // 발행 300만/30만, 검색 10만 → 배율 30·3 → pct = 30/45=66.7%(매우높음)·3/18=16.7%(보통)
        $s = P::saturation(['blog' => 3_000_000, 'cafe' => 300_000, 'total' => 3_300_000], 100_000);
        $this->assertSame(66.7, $s['blog']['pct']);
        $this->assertSame('매우 높음', $s['blog']['label']);   // pct >= 50
        $this->assertSame(16.7, $s['cafe']['pct']);
        $this->assertSame('보통', $s['cafe']['label']);        // 10~29.9
        $this->assertSame(3_300_000, $s['total']['volume']);

        // 등급 경계: 배율 7 → 31.8%(높음), 배율 1 → 6.3%(낮음)
        $this->assertSame('높음', P::saturation(['blog' => 700_000, 'cafe' => 0, 'total' => 700_000], 100_000)['blog']['label']);
        $this->assertSame('낮음', P::saturation(['blog' => 100_000, 'cafe' => 0, 'total' => 100_000], 100_000)['blog']['label']);

        $this->assertNull(P::saturation(null, 1000));
        $this->assertNull(P::saturation(['blog' => 1, 'cafe' => 1, 'total' => 2], 0));
    }

    /** 검색광고 userStat 14버킷(성별 f×7, m×7) 합성. */
    private function buckets(): array
    {
        $ages = ['0-12', '13-19', '20-24', '25-29', '30-39', '40-49', '50-'];
        $f = [10, 50, 200, 150, 100, 40, 10];  // 여성 합 560
        $m = [5, 20, 60, 40, 50, 30, 15];       // 남성 합 220
        $b = [];
        foreach ($ages as $i => $a) {
            $b[] = ['gender' => 'f', 'age' => $a, 'pc' => 0, 'mobile' => 0, 'total' => $f[$i]];
        }
        foreach ($ages as $i => $a) {
            $b[] = ['gender' => 'm', 'age' => $a, 'pc' => 0, 'mobile' => 0, 'total' => $m[$i]];
        }

        return $b;
    }

    /** 이슈성(시의성) — (최고월−중앙값)÷연간합. 꾸준=0 수렴, 단발 급등=높음. */
    public function test_issue_score(): void
    {
        $flat = array_map(fn ($v) => ['total' => $v], [100, 100, 100, 100, 100, 100]);
        $this->assertSame('매우 낮음', P::issueScore($flat)['label']);   // pct 0

        // 11개월 저조 + 1개월 급등 → 매우 높음(≈80%)
        $spike = array_map(fn ($v) => ['total' => $v], [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 500]);
        $s = P::issueScore($spike);
        $this->assertGreaterThan(30, $s['pct']);
        $this->assertSame('매우 높음', $s['label']);

        $this->assertNull(P::issueScore([['total' => 100], ['total' => 50]]));   // 4개월 미만
    }

    /** 디바이스 통계 — userStat 버킷 pc·mobile 을 전체/성별/연령으로 집계. */
    public function test_device_stats(): void
    {
        $b = [
            ['gender' => 'f', 'age' => '20-24', 'pc' => 30, 'mobile' => 70, 'total' => 100],
            ['gender' => 'm', 'age' => '20-24', 'pc' => 60, 'mobile' => 40, 'total' => 100],
            ['gender' => 'f', 'age' => '30-39', 'pc' => 10, 'mobile' => 90, 'total' => 100],
        ];
        $d = P::deviceStats(['buckets' => $b]);
        $this->assertTrue($d['has']);
        // 전체 pc 100 / mobile 200 → pc 33.3%
        $this->assertEqualsWithDelta(33.3, $d['total']['pc_pct'], 0.1);
        // 성별: 여성(pc 40/mo 160)=20% · 남성(pc 60/mo 40)=60%
        $this->assertSame('여성', $d['by_gender'][0]['label']);
        $this->assertEqualsWithDelta(20.0, $d['by_gender'][0]['pc_pct'], 0.1);
        $this->assertEqualsWithDelta(60.0, $d['by_gender'][1]['pc_pct'], 0.1);
        // 연령 AGE_ORDER 정렬 → 20-24 먼저
        $this->assertSame('20대 초', $d['by_age'][0]['label']);
        $this->assertFalse(P::deviceStats([])['has']);
    }

    /** 연관 키워드 — 입력어 토큰(공백 분리)을 포함하는 것만, 제한 없이. */
    public function test_related_filters_by_input_tokens(): void
    {
        $base = ['related' => [
            ['keyword' => '강남 카페', 'monthly_total' => 1000, 'comp_idx' => '중간'],
            ['keyword' => '역삼 맛집', 'monthly_total' => 900, 'comp_idx' => '높음'],
            ['keyword' => '분위기 좋은 술집', 'monthly_total' => 800, 'comp_idx' => '낮음'],
        ]];
        $kws = array_column(P::related($base, '강남 맛집'), 'keyword');
        $this->assertContains('강남 카페', $kws);      // '강남' 포함
        $this->assertContains('역삼 맛집', $kws);      // '맛집' 포함
        $this->assertNotContains('분위기 좋은 술집', $kws);  // 토큰 없음
        // 입력어 없으면 필터 없이 전체
        $this->assertCount(3, P::related($base, ''));
    }

    public function test_grade_thresholds(): void
    {
        $this->assertSame('S', P::grade(150000));
        $this->assertSame('A', P::grade(30000));
        $this->assertSame('D', P::grade(1500));
        $this->assertSame('F', P::grade(10));
    }

    public function test_commercial_from_competition(): void
    {
        $hi = P::commercial('높음');
        $this->assertTrue($hi['is_commercial']);
        $this->assertSame(70, $hi['commercial_pct']);
        $this->assertSame(30, $hi['info_pct']);
        $this->assertSame('비상업 키워드', P::commercial('낮음')['label']);
        $this->assertNull(P::commercial(null)['commercial_pct']);
    }

    /** 상업성 — 경쟁강도와 쇼핑 상품 수(구매 의도) 중 강한 신호로 판정. */
    public function test_commercial_with_shopping_signal(): void
    {
        // 제품 키워드: 경쟁 중간(45)이라도 쇼핑 상품 많으면 상업성 높음(max)
        $c = P::commercial('중간', 800_000);
        $this->assertSame(88, $c['commercial_pct']);
        $this->assertTrue($c['is_commercial']);
        // 지역/서비스: 쇼핑 상품 없어도 경쟁 높으면 상업성 유지
        $this->assertSame(70, P::commercial('높음', 0)['commercial_pct']);
        // 쇼핑 신호만(경쟁 미상)
        $this->assertSame(74, P::commercial(null, 150_000)['commercial_pct']);
        // 둘 다 없으면 판정 불가
        $this->assertNull(P::commercial(null, null)['commercial_pct']);
    }

    public function test_forecast_is_prorated(): void
    {
        $f = P::forecast(129000);
        $this->assertSame(129000, $f['monthly']);
        $this->assertSame(30100, $f['weekly']);
        $this->assertSame(4300, $f['daily']);
    }

    public function test_month_ratio_sums_to_100(): void
    {
        $detail = ['monthly' => [
            ['label' => '2025-07', 'total' => 1000],
            ['label' => '2025-08', 'total' => 500],
            ['label' => '2025-09', 'total' => 500],
        ]];
        $mr = P::monthRatio($detail);
        $this->assertCount(12, $mr);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($mr, 'pct')), 0.3);
        $byMonth = collect($mr)->keyBy('m');
        $this->assertGreaterThan($byMonth[8]['pct'], $byMonth[7]['pct']);
    }

    /** 성별·연령·피라미드는 userStat 버킷(트렌드 아님)에서 조합된다. */
    public function test_demographics_from_buckets(): void
    {
        $d = P::demographics(['buckets' => $this->buckets()]);

        $this->assertTrue($d['has']);
        // 여성 560 / 전체 780 = 71.8%
        $this->assertEqualsWithDelta(71.8, $d['gender']['female_pct'], 0.1);
        $this->assertEqualsWithDelta(28.2, $d['gender']['male_pct'], 0.1);
        // 연령 7밴드, AGE_ORDER 정렬 → 첫 밴드 0-12, 라벨 매핑
        $this->assertCount(7, $d['age']);
        $this->assertSame('0-12', $d['age'][0]['code']);
        $this->assertSame('~12세', $d['age'][0]['label']);
        $this->assertSame('50대+', $d['age'][6]['label']);
        // 20-24 연령 비율 = (200+60)/780 = 33.3%
        $band = collect($d['age'])->firstWhere('code', '20-24');
        $this->assertEqualsWithDelta(33.3, $band['pct'], 0.1);
        // 피라미드: 20-24 여 200 / 남 60
        $this->assertCount(7, $d['pyramid']);
        $prow = collect($d['pyramid'])->firstWhere('label', '20대 초');
        $this->assertSame(200, $prow['female']);
        $this->assertSame(60, $prow['male']);
    }

    /** buckets 없으면 사전집계로 폴백(피라미드 없음). */
    public function test_demographics_fallback_without_buckets(): void
    {
        $d = P::demographics([
            'gender' => ['female' => 76, 'male' => 24, 'female_pct' => 76.0, 'male_pct' => 24.0],
            'age' => [['age' => '30-39', 'total' => 40, 'pct' => 40.0], ['age' => '20-24', 'total' => 60, 'pct' => 60.0]],
        ]);
        $this->assertTrue($d['has']);
        $this->assertSame([], $d['pyramid']);
        $this->assertSame('20-24', $d['age'][0]['code']); // AGE_ORDER 정렬
        $this->assertSame(76.0, $d['gender']['female_pct']);
    }

    public function test_build_assembles_view_model(): void
    {
        $base = [
            'keyword' => '강남 맛집', 'monthly_pc' => 37800, 'monthly_mobile' => 91200,
            'monthly_total' => 129000, 'comp_idx' => '높음',
            'related' => [['keyword' => '강남 맛집 추천', 'monthly_pc' => 500, 'monthly_mobile' => 1000, 'monthly_total' => 1500, 'comp_idx' => '중간']],
        ];
        $detail = [
            'buckets' => $this->buckets(),
            'monthly' => [['label' => '2025-07', 'total' => 1000], ['label' => '2025-08', 'total' => 800]],
        ];

        $vm = P::build('강남 맛집', $base, $detail);

        $this->assertTrue($vm['has_data']);
        $this->assertTrue($vm['has_demo']);
        $this->assertSame(129000, $vm['total']);
        $this->assertSame('S', $vm['grade']);
        $this->assertSame('상업 키워드', $vm['commercial']['label']);
        $this->assertSame('강남 맛집 추천', $vm['related'][0]['keyword']);
        $this->assertCount(2, $vm['trend']);
        $this->assertSame('2025-07', $vm['trend'][0]['label']);
        $this->assertCount(7, $vm['pyramid']);
        $this->assertSame('0-12', $vm['age'][0]['code']);
    }

    public function test_build_without_data(): void
    {
        $vm = P::build('없는키워드', null, null);
        $this->assertFalse($vm['has_data']);
        $this->assertFalse($vm['has_demo']);
        $this->assertSame('F', $vm['grade']);
    }
}
