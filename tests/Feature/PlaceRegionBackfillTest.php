<?php

namespace Tests\Feature;

use App\Domain\Keyword\KeywordAiInsight;
use App\Domain\Keyword\KeywordHubPublisher;
use App\Domain\Keyword\KeywordReportBuilder;
use App\Domain\Keyword\PlaceKeywordRegions;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 플레이스 region 백필(22) — region 컬럼 도입 전 발행분의 지역이 비어 카테고리 허브 지역 배지가
 * 실제보다 적게 나오던 버그(운영 실측: '강남역 맛집' 등 14건 중 4건만 region 보유 → 배지 4).
 */
class PlaceRegionBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function placeCat(string $name = '맛집·음식점'): KeywordCategory
    {
        return KeywordCategory::create([
            'type' => 'place', 'name' => $name, 'slug' => KeywordCategory::makeSlug($name), 'is_active' => true,
        ]);
    }

    /** region 컬럼 도입 전 상태 재현 — region/region_type 이 NULL 인 행. */
    private function legacyDoc(int $catId, string $keyword, int $total = 1000): int
    {
        $id = DB::table('keyword_searches')->insertGetId([
            'origin' => 'hub', 'category_id' => $catId, 'keyword' => $keyword, 'slug' => KeywordCategory::makeSlug($keyword),
            'monthly_total' => $total, 'monthly_pc' => 100, 'monthly_mobile' => 900,
            'region' => null, 'region_type' => null,
            'created_at' => now()->subMonth(), 'updated_at' => now()->subMonth(),
        ]);

        return $id;
    }

    public function test_resolves_region_and_type_from_keyword(): void
    {
        $r = app(PlaceKeywordRegions::class);

        $this->assertSame(['region' => '강남역', 'region_type' => 'hotplace'], $r->resolve('강남역 맛집', '맛집·음식점'));
        $this->assertSame(['region' => '망원동', 'region_type' => 'hotplace'], $r->resolve('망원동 점심맛집', '맛집·음식점'));
        // 전국 행정구역(regions_kr) 병합분 — 읍면동
        $this->assertSame(['region' => '목천읍', 'region_type' => 'dong'], $r->resolve('목천읍 미용실', '헤어샵'));
        // 공백 있는 지역명(긴 지역 우선 매칭)
        $this->assertSame(['region' => '전주 한옥마을', 'region_type' => 'travel'], $r->resolve('전주 한옥마을 맛집', '맛집·음식점'));
        // 업종 패턴이 아니면 지역으로 인정하지 않는다(오탐 방지)
        $this->assertNull($r->resolve('강남 스타일', '맛집·음식점'));
        // 카테고리에 없는 패턴(치과는 병원 패턴) → 맛집 카테고리에선 매칭 안 됨
        $this->assertNull($r->resolve('강남 치과', '맛집·음식점'));
        $this->assertSame(['region' => '강남', 'region_type' => 'district'], $r->resolve('강남 치과', '병원·의원'));
        // 단일 토큰·미지 지역
        $this->assertNull($r->resolve('맛집', '맛집·음식점'));
        $this->assertNull($r->resolve('없는지역명xyz 맛집', '맛집·음식점'));
    }

    public function test_backfill_command_fills_legacy_rows_and_fixes_region_counts(): void
    {
        $cat = $this->placeCat();
        // 운영 상황 재현: 같은 지역인데 일부만 region 보유
        foreach (['강남역 맛집', '강남역 술집', '강남역 고기집'] as $kw) {
            $this->legacyDoc($cat->id, $kw);
        }
        KeywordSearch::create([
            'origin' => 'hub', 'category_id' => $cat->id, 'keyword' => '강남역 국밥',
            'region' => '강남역', 'region_type' => 'hotplace', 'monthly_total' => 500,
        ]);
        // 후보(구 시딩분)도 비어 있음
        DB::table('keyword_candidates')->insert([
            'category_id' => $cat->id, 'keyword' => '강남역 파스타', 'source' => 'combo', 'status' => 'pending',
            'region' => null, 'region_type' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 지역으로 해석 불가(수집 경로가 다른 키워드) — 건드리지 않는다
        $this->legacyDoc($cat->id, '혼밥 트렌드');

        $this->artisan('hub:backfill-region')->assertSuccessful();

        // 지역 배지 집계(컨트롤러와 동일 쿼리)가 실제 문서 수와 일치
        $count = KeywordSearch::where('origin', 'hub')->where('category_id', $cat->id)->where('region', '강남역')->count();
        $this->assertSame(4, $count);
        $this->assertSame('hotplace', KeywordSearch::where('keyword', '강남역 술집')->value('region_type'));
        $this->assertSame('강남역', KeywordCandidate::where('keyword', '강남역 파스타')->value('region'));
        // 미매칭은 NULL 유지
        $this->assertNull(KeywordSearch::where('keyword', '혼밥 트렌드')->value('region'));
    }

    public function test_backfill_does_not_touch_timestamps_or_filled_rows(): void
    {
        $cat = $this->placeCat();
        $id = $this->legacyDoc($cat->id, '홍대 맛집');
        $before = DB::table('keyword_searches')->where('id', $id)->value('updated_at');

        $this->artisan('hub:backfill-region')->assertSuccessful();

        // 사이트맵 lastmod 에 거짓 신호를 주지 않도록 updated_at 은 그대로
        $this->assertSame($before, DB::table('keyword_searches')->where('id', $id)->value('updated_at'));
        $this->assertSame('홍대', DB::table('keyword_searches')->where('id', $id)->value('region'));

        // 재실행 안전 — 이미 채워진 행은 재처리 대상이 아님(미매칭 0, 채움 0)
        $this->artisan('hub:backfill-region')
            ->expectsOutputToContain('후보 0 · 문서 0 채움')
            ->assertSuccessful();
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $cat = $this->placeCat();
        $this->legacyDoc($cat->id, '연남동 맛집');

        $this->artisan('hub:backfill-region', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull(KeywordSearch::where('keyword', '연남동 맛집')->value('region'));
    }

    public function test_publisher_derives_region_when_candidate_lacks_it(): void
    {
        $cat = $this->placeCat();
        $id = DB::table('keyword_candidates')->insertGetId([
            'category_id' => $cat->id, 'keyword' => '합정 맛집', 'source' => 'combo', 'status' => 'approved',
            'region' => null, 'region_type' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->mock(KeywordReportBuilder::class, function ($m) {
            $m->shouldReceive('build')->andReturn(['vm' => [
                'keyword' => '합정 맛집', 'has_data' => true, 'has_volume' => true,
                'total' => 8000, 'pc' => 1000, 'mobile' => 7000, 'comp_idx' => '높음', 'grade' => 'B',
            ], 'saturation' => null, 'popular' => [], 'weekday' => null, 'autocomplete' => []]);
        });
        $this->mock(KeywordAiInsight::class, fn ($m) => $m->shouldReceive('write')->andReturn(null));

        $doc = app(KeywordHubPublisher::class)->publish(KeywordCandidate::find($id));

        // 구 후보(region NULL)로 발행해도 문서·후보 모두 지역이 채워진다
        $this->assertSame('합정', $doc->region);
        $this->assertSame('hotplace', $doc->region_type);
        $this->assertSame('합정', KeywordCandidate::find($id)->region);
    }
}
