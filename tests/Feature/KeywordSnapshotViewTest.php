<?php

namespace Tests\Feature;

use App\Domain\Blog\BlogIndexAnalyzer;
use App\Domain\Keyword\KeywordAnalysisPresenter;
use App\Domain\Keyword\NaverAutocompleteService;
use App\Domain\Keyword\NaverContentVolumeService;
use App\Domain\Keyword\NaverDataLabService;
use App\Domain\Keyword\NaverKeywordService;
use App\Domain\SearchAdWeb\SearchAdWebClient;
use App\Models\KeywordSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 키워드 분석 — 수집본은 전부 캐시: fresh=1(다시 분석) 전까지 항상 저장본만 렌더(신규·구버전 포맷 모두). */
class KeywordSnapshotViewTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'u', 'email' => 'u@rf.kr', 'password' => 'x1234567']);
    }

    /** 외부 API 서비스 전부 목킹 — 스냅샷 경로는 호출 0회, 폴백 경로는 지정 응답. */
    private function mockServices(bool $expectAnalyze): void
    {
        $light = $this->mock(NaverKeywordService::class);
        $expectAnalyze
            ? $light->shouldReceive('analyze')->once()->andReturnNull()
            : $light->shouldNotReceive('analyze');
        $this->mock(NaverAutocompleteService::class)->shouldReceive('suggest')->andReturn([]);
        $this->mock(SearchAdWebClient::class)->shouldReceive('keywordDetail')->andReturn(['error' => 'x']);
        $this->mock(NaverContentVolumeService::class)->shouldIgnoreMissing();
        $this->mock(NaverDataLabService::class)->shouldIgnoreMissing();
        $this->mock(BlogIndexAnalyzer::class)->shouldIgnoreMissing();
    }

    public function test_view_renders_new_format_snapshot_without_reanalysis(): void
    {
        $user = $this->user();
        $vm = KeywordAnalysisPresenter::build('삼성티비리모컨', [
            'monthly_pc' => 1000, 'monthly_mobile' => 2130, 'monthly_total' => 3130, 'comp_idx' => '중간', 'related' => [],
        ], null, null);
        KeywordSearch::create([
            'user_id' => $user->id, 'keyword' => '삼성티비리모컨', 'monthly_total' => 3130,
            'snapshot' => ['vm' => $vm, 'saturation' => null, 'popular' => [], 'weekday' => null, 'autocomplete' => []],
        ]);
        $this->mockServices(expectAnalyze: false); // 스냅샷 열람은 재분석 없음

        $this->actingAs($user)->get('/console/keyword?keyword='.urlencode('삼성티비리모컨').'&view=1')
            ->assertOk()->assertSee('삼성티비리모컨')->assertSee('3,130');
    }

    /** 구버전(원시 base+detail) 스냅샷도 재분석 없이 저장 데이터로 즉시 렌더된다. */
    public function test_view_with_legacy_snapshot_renders_without_reanalysis(): void
    {
        $user = $this->user();
        // 구버전 스냅샷 — vm 키 없이 원시 base+detail 포맷 (대량 분석 등 이전 저장분)
        KeywordSearch::create([
            'user_id' => $user->id, 'keyword' => '삼성티비리모컨', 'monthly_total' => 3130,
            'snapshot' => ['keyword' => '삼성티비리모컨', 'monthly_pc' => 1000, 'monthly_mobile' => 2130, 'monthly_total' => 3130, 'comp_idx' => '중간', 'related' => [], 'grade' => 'C', 'detail' => []],
        ]);
        $this->mockServices(expectAnalyze: false); // 저장 데이터로 렌더 — 재분석(네트워크) 없어야 함

        $this->actingAs($user)->get('/console/keyword?keyword='.urlencode('삼성티비리모컨').'&view=1')
            ->assertOk()
            ->assertSee('삼성티비리모컨')
            ->assertSee('3,130')
            ->assertSee('저장된 분석 결과입니다'); // 스냅샷 열람 배너(재수집·과금 없음)
    }

    /** 리스트 클릭(view=1)은 항상 캐시, 검색 폼으로 다시 검색(view 없음)하면 재수집. */
    public function test_view_caches_and_form_search_reanalyzes(): void
    {
        $user = $this->user();
        $vm = KeywordAnalysisPresenter::build('삼성티비리모컨', [
            'monthly_pc' => 1000, 'monthly_mobile' => 2130, 'monthly_total' => 3130, 'comp_idx' => '중간', 'related' => [],
        ], null, null);
        KeywordSearch::create([
            'user_id' => $user->id, 'keyword' => '삼성티비리모컨', 'monthly_total' => 3130,
            'snapshot' => ['vm' => $vm, 'saturation' => null, 'popular' => [], 'weekday' => null, 'autocomplete' => []],
        ]);
        // analyze 는 폼 재검색에서 정확히 1회만 호출되어야 한다 (view=1 캐시 열람은 0회)
        $this->mockServices(expectAnalyze: true);

        // 1) 리스트 클릭(view=1) — 캐시 렌더, 재수집 없음
        $this->actingAs($user)->get('/console/keyword?keyword='.urlencode('삼성티비리모컨').'&view=1')
            ->assertOk()->assertSee('저장된 분석 결과입니다')->assertSee('3,130');

        // 2) 검색 폼(view 없음) — 다시 검색 = 재수집(analyze 1회 소비. 목킹 null → 조회 실패 안내)
        $this->actingAs($user)->get('/console/keyword?keyword='.urlencode('삼성티비리모컨'))
            ->assertOk()->assertSee('데이터를 조회하지 못했습니다');
    }
}
