<?php

namespace Tests\Feature;

use App\Domain\Keyword\HubAutoRun;
use App\Domain\Keyword\KeywordHubCollectionControl;
use App\Domain\Keyword\KeywordHubCollector;
use App\Domain\Keyword\KeywordHubPublisher;
use App\Domain\Keyword\KeywordReportBuilder;
use App\Domain\Keyword\NaverAutocompleteService;
use App\Domain\Keyword\NaverKeywordService;
use App\Jobs\KeywordHubCollectCategoryJob;
use App\Jobs\KeywordHubCollectShoppingRootJob;
use App\Jobs\KeywordHubPublishCandidateJob;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordHubRun;
use App\Models\KeywordSearch;
use App\Models\MarketAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** 키워드 자동 분석 — 후보 수집·플레이스 키워드 분석·쇼핑 시장 분석·병렬 큐. */
class KeywordHubTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => '관리자', 'email' => 'hub-admin@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    private function category(array $attrs = []): KeywordCategory
    {
        return KeywordCategory::create($attrs + [
            'type' => 'shopping', 'name' => '캠핑용품', 'slug' => '캠핑용품',
            'seed_keywords' => ['캠핑의자'], 'is_active' => true,
        ]);
    }

    private function mockAnalyze(): void
    {
        $this->mock(NaverKeywordService::class, function ($m) {
            $m->shouldReceive('analyze')->andReturn([
                'keyword' => '캠핑의자', 'monthly_pc' => 1000, 'monthly_mobile' => 4000, 'monthly_total' => 5000,
                'comp_idx' => '높음',
                'related' => [
                    ['keyword' => '접이식 캠핑의자', 'monthly_total' => 3000, 'comp_idx' => '중간'],
                    ['keyword' => '저볼륨키워드', 'monthly_total' => 5, 'comp_idx' => '낮음'],
                ],
            ]);
        });
        $this->mock(NaverAutocompleteService::class, function ($m) {
            $m->shouldReceive('suggest')->andReturn(['캠핑의자 경량']);
        });
    }

    public function test_collector_creates_candidates_with_volume_filter(): void
    {
        $this->mockAnalyze();
        $cat = $this->category();

        $stats = app(KeywordHubCollector::class)->collect($cat);

        $this->assertSame(1, $stats['seeds']);
        // 시드(볼륨 포함) + 연관(임계 통과) + 자동완성(볼륨 미상) 은 pending 후보로
        $this->assertDatabaseHas('keyword_candidates', ['keyword' => '캠핑의자', 'source' => 'seed', 'monthly_total' => 5000, 'status' => 'pending']);
        $this->assertDatabaseHas('keyword_candidates', ['keyword' => '접이식 캠핑의자', 'source' => 'related', 'monthly_total' => 3000]);
        $this->assertDatabaseHas('keyword_candidates', ['keyword' => '캠핑의자 경량', 'source' => 'autocomplete', 'monthly_total' => null]);
        // 최소 검색량(기본 10회) 미만 연관어는 자동 제외
        $this->assertDatabaseMissing('keyword_candidates', ['keyword' => '저볼륨키워드']);
        $this->assertNotNull($cat->fresh()->collected_at);
    }

    public function test_collector_skips_published_hub_keywords_and_keeps_status_on_recollect(): void
    {
        $this->mockAnalyze();
        $cat = $this->category();
        // 이미 허브로 발행된 키워드(user_id NULL 시스템 소유)는 후보로 다시 안 들어온다
        KeywordSearch::create(['origin' => 'hub', 'keyword' => '접이식 캠핑의자', 'monthly_total' => 3000]);
        // 이미 승인된 후보는 재수집해도 상태가 유지된다
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '캠핑의자 경량', 'source' => 'autocomplete', 'status' => 'approved']);

        app(KeywordHubCollector::class)->collect($cat);

        $this->assertDatabaseMissing('keyword_candidates', ['keyword' => '접이식 캠핑의자']);
        $this->assertDatabaseHas('keyword_candidates', ['keyword' => '캠핑의자 경량', 'status' => 'approved']);
    }

    private function mockBuilder(?array $vmByKeyword = null): void
    {
        $this->mock(KeywordReportBuilder::class, function ($m) use ($vmByKeyword) {
            $m->shouldReceive('build')->andReturnUsing(function (string $kw) use ($vmByKeyword) {
                $vm = $vmByKeyword[$kw] ?? null;

                return ['vm' => $vm, 'saturation' => null, 'popular' => [], 'weekday' => null, 'autocomplete' => []];
            });
        });
    }

    private function vm(string $kw, int $total): array
    {
        return [
            'keyword' => $kw, 'has_data' => true, 'has_volume' => true,
            'total' => $total, 'pc' => (int) ($total * 0.2), 'mobile' => (int) ($total * 0.8),
            'comp_idx' => '중간', 'grade' => 'C',
        ];
    }

    public function test_publisher_creates_system_owned_hub_doc(): void
    {
        $this->mockBuilder(['캠핑의자' => $this->vm('캠핑의자', 5000)]);
        $cat = $this->category(['type' => 'place', 'name' => '지역맛집', 'slug' => '지역맛집']);
        $c = KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '캠핑의자', 'source' => 'seed', 'monthly_total' => 5000, 'status' => 'approved']);

        $doc = app(KeywordHubPublisher::class)->publish($c);

        $this->assertNotNull($doc);
        $this->assertNull($doc->user_id);
        $this->assertSame('hub', $doc->origin);
        $this->assertSame($cat->id, $doc->category_id);
        $this->assertSame(5000, $doc->monthly_total);
        $this->assertNotNull($doc->refreshed_at);
        $this->assertSame('캠핑의자', $doc->slug); // 공유 슬러그 자동 부여 → /keyword/캠핑의자
        $this->assertSame('캠핑의자', $doc->snapshot['vm']['keyword']);
        $this->assertSame('published', $c->fresh()->status);

        // 같은 키워드 재발행은 새 문서를 만들지 않고 갱신한다
        $c->update(['status' => 'approved']);
        app(KeywordHubPublisher::class)->publish($c);
        $this->assertSame(1, KeywordSearch::where('origin', 'hub')->where('keyword', '캠핑의자')->count());
    }

    public function test_publisher_creates_shopping_market_analysis_for_shopping_candidate(): void
    {
        $cat = $this->category(['type' => 'shopping', 'name' => '침구', 'slug' => '침구']);
        $candidate = KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '여름이불', 'source' => 'datalab', 'monthly_total' => 12000, 'status' => 'approved']);
        $sourceUser = User::create(['name' => 'u', 'email' => 'market-source@rf.kr', 'password' => 'x1234567']);
        MarketAnalysis::create([
            'user_id' => $sourceUser->id,
            'keyword' => '여름이불',
            'total_count' => 1000,
            'item_count' => 80,
            'sales_6m' => 500,
            'revenue_6m' => 15000000,
            'avg_price' => 30000,
            'median_price' => 28000,
            'top10_share' => 42.5,
            'monthly_search' => 12000,
            'comp_idx' => '중간',
            'snapshot' => ['top_products' => [['title' => '시원한 여름이불', 'price' => 30000, 'purchase6m' => 50]]],
        ]);

        $doc = app(KeywordHubPublisher::class)->publish($candidate);

        $this->assertInstanceOf(MarketAnalysis::class, $doc);
        $this->assertStringContainsString('/market/', $doc->shareUrl());
        $this->assertDatabaseHas('keyword_candidates', ['keyword' => '여름이불', 'status' => 'published']);
        $this->assertDatabaseMissing('keyword_searches', ['origin' => 'hub', 'keyword' => '여름이불']);
    }

    /** 쇼핑 시장 분석은 확장 플로 수집 데이터로만 — 소스 없으면 서버 생성 없이 보류한다(2026-07-22 확정). */
    public function test_publisher_rejects_shopping_candidate_without_extension_source(): void
    {
        $cat = $this->category(['type' => 'shopping', 'name' => '침구', 'slug' => '침구']);
        $c = KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '여름이불', 'source' => 'datalab', 'monthly_total' => 12000, 'status' => 'approved']);

        $doc = app(KeywordHubPublisher::class)->publish($c);

        $this->assertNull($doc);
        $this->assertSame('rejected', $c->fresh()->status);
        $this->assertStringContainsString('확장 수집 데이터 필요', (string) $c->fresh()->note);
        $this->assertSame(0, MarketAnalysis::count());
    }

    public function test_publisher_holds_thin_candidates(): void
    {
        $this->mockBuilder([]); // 모든 키워드 vm=null (데이터 없음)
        // 쇼핑은 확장 소스 없으면 builder 전에 보류되므로, thin-content 게이트는 place 경로로 검증한다
        $cat = $this->category(['type' => 'place', 'name' => '지역맛집', 'slug' => '지역맛집']);
        $c = KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '캠핑의자', 'source' => 'seed', 'status' => 'approved']);

        $doc = app(KeywordHubPublisher::class)->publish($c);

        $this->assertNull($doc);
        $this->assertSame('rejected', $c->fresh()->status);
        $this->assertStringContainsString('데이터 부족', (string) $c->fresh()->note);
        $this->assertSame(0, KeywordSearch::where('origin', 'hub')->count());
    }

    public function test_hub_publish_command_respects_limit_and_volume_order(): void
    {
        $this->mockBuilder([
            '캠핑의자' => $this->vm('캠핑의자', 5000),
            '캠핑테이블' => $this->vm('캠핑테이블', 2000),
        ]);
        $cat = $this->category(['type' => 'place', 'name' => '지역맛집', 'slug' => '지역맛집']);
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '캠핑테이블', 'monthly_total' => 2000, 'status' => 'approved']);
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '캠핑의자', 'monthly_total' => 5000, 'status' => 'approved']);

        $this->artisan('hub:publish', ['--limit' => 1])->assertSuccessful();

        // 검색량 큰 순 — 캠핑의자만 발행
        $this->assertDatabaseHas('keyword_searches', ['origin' => 'hub', 'keyword' => '캠핑의자']);
        $this->assertDatabaseMissing('keyword_searches', ['origin' => 'hub', 'keyword' => '캠핑테이블']);
        $this->assertDatabaseHas('keyword_candidates', ['keyword' => '캠핑테이블', 'status' => 'approved']);
    }

    public function test_admin_page_requires_operator(): void
    {
        $user = User::create(['name' => 'u', 'email' => 'plain@rf.kr', 'password' => 'x1234567']);
        $this->actingAs($user)->get('/admin/keyword-hub')->assertForbidden();
    }

    public function test_admin_can_create_category_and_bulk_approve(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/keyword-hub/categories', [
            'type' => 'shopping', 'name' => '캠핑/아웃도어', 'seed_keywords' => "캠핑의자, 캠핑테이블\n캠핑랜턴",
        ])->assertRedirect();

        $cat = KeywordCategory::where('name', '캠핑/아웃도어')->first();
        $this->assertNotNull($cat);
        $this->assertSame('캠핑-아웃도어', $cat->slug);
        $this->assertSame(['캠핑의자', '캠핑테이블', '캠핑랜턴'], $cat->seedList());

        $c1 = KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '캠핑의자', 'monthly_total' => 5000]);
        $c2 = KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '캠핑테이블', 'monthly_total' => 2000]);

        $this->actingAs($admin)->post('/admin/keyword-hub/candidates/bulk', [
            'action' => 'approve', 'ids' => [$c1->id, $c2->id],
        ])->assertRedirect();

        $this->assertSame('approved', $c1->fresh()->status);
        $this->assertSame('approved', $c2->fresh()->status);

        // 화면 렌더 — 후보 큐는 별도 관리 페이지(candidates)의 승인 탭에 표시(허브 첫 화면은 발행 전용)
        $this->actingAs($admin)->get('/admin/keyword-hub/candidates?status=approved&type=shopping')
            ->assertOk()->assertSee('캠핑의자')->assertSee('후보 큐');
        $this->actingAs($admin)->get('/admin/keyword-hub')
            ->assertOk()->assertDontSee('후보 큐')->assertSee('키워드 자동 분석');
    }

    public function test_admin_page_shows_source_counts_and_filters_by_source(): void
    {
        $admin = $this->admin();
        // seed_keywords는 비워 카테고리 시드 표시와 후보를 분리(혼란 방지)
        $cat = $this->category(['type' => 'place', 'name' => '지역맛집', 'slug' => '지역맛집', 'seed_keywords' => []]);
        // 시딩(지역조합=combo)과 다른 출처(autocomplete)를 섞어 둔다
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '강남 맛집', 'source' => 'combo', 'status' => 'pending', 'monthly_total' => 8000]);
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '성수동 맛집', 'source' => 'combo', 'status' => 'pending', 'monthly_total' => null]);
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '자동완성후보어', 'source' => 'autocomplete', 'status' => 'pending', 'monthly_total' => 5000]);

        // 후보 현황에 출처별 카운트(지역조합)가 보이고, 후보 큐에 combo 결과가 표시된다
        $this->actingAs($admin)->get('/admin/keyword-hub/candidates')
            ->assertOk()->assertSee('전체 출처')->assertSee('지역조합')
            ->assertSee('강남 맛집')->assertSee('성수동 맛집')->assertSee('자동완성후보어');

        // source=combo 필터 — 지역조합만 남고 다른 출처(autocomplete)는 후보 큐에서 빠진다
        $this->actingAs($admin)->get('/admin/keyword-hub/candidates?source=combo')
            ->assertOk()->assertSee('강남 맛집')->assertDontSee('자동완성후보어');
    }

    public function test_candidate_page_separates_place_and_shopping_candidates(): void
    {
        $admin = $this->admin();
        $place = $this->category(['type' => 'place', 'name' => '맛집', 'slug' => '맛집', 'seed_keywords' => []]);
        $shopping = $this->category(['type' => 'shopping', 'name' => '침구', 'slug' => '침구', 'seed_keywords' => []]);

        KeywordCandidate::create(['category_id' => $place->id, 'keyword' => '강남 맛집', 'source' => 'combo', 'region' => '강남', 'status' => 'pending']);
        KeywordCandidate::create(['category_id' => $shopping->id, 'keyword' => '여름이불', 'source' => 'datalab', 'status' => 'pending']);

        $this->actingAs($admin)->get('/admin/keyword-hub/candidates?status=pending')
            ->assertOk()
            ->assertSee('플레이스 후보 큐')
            ->assertSee('강남 맛집')
            ->assertDontSee('여름이불');

        $this->actingAs($admin)->get('/admin/keyword-hub/candidates?status=pending&type=shopping')
            ->assertOk()
            ->assertSee('쇼핑 후보 큐')
            ->assertSee('여름이불')
            ->assertDontSee('강남 맛집')
            ->assertDontSee('전체 지역');
    }

    public function test_published_category_cards_open_document_lists_with_analysis_links(): void
    {
        $admin = $this->admin();
        $system = User::create([
            'name' => 'hub',
            'email' => config('rankfree.hub.system_user_email', 'hub-system@rankfree.kr'),
            'password' => 'x1234567',
            'role' => 'super',
        ]);

        $placeRoot = KeywordCategory::create(['type' => 'place', 'name' => '플레이스 맛집', 'slug' => '플레이스-맛집', 'is_active' => true]);
        $placeChild = KeywordCategory::create(['type' => 'place', 'parent_id' => $placeRoot->id, 'name' => '한식', 'slug' => '한식', 'is_active' => true]);
        $shopRoot = KeywordCategory::create(['type' => 'shopping', 'name' => '생활건강', 'slug' => '생활건강', 'naver_cid' => 50000008, 'is_active' => true]);
        $shopChild = KeywordCategory::create(['type' => 'shopping', 'parent_id' => $shopRoot->id, 'name' => '침구', 'slug' => '침구', 'naver_cid' => 50000100, 'is_active' => true]);

        $keywordDoc = KeywordSearch::create([
            'origin' => 'hub',
            'category_id' => $placeChild->id,
            'keyword' => '강남 한식',
            'region' => '강남',
            'monthly_total' => 8000,
        ]);
        $marketDoc = MarketAnalysis::create([
            'user_id' => $system->id,
            'category_id' => $shopChild->id,
            'keyword' => '여름이불',
            'total_count' => 1000,
            'item_count' => 80,
            'monthly_search' => 5000,
            'revenue_6m' => 1200000,
            'snapshot' => ['top_products' => []],
        ]);
        KeywordSearch::create([
            'origin' => 'hub',
            'category_id' => $shopChild->id,
            'keyword' => '여름이불',
            'monthly_total' => 5000,
        ]);

        $this->actingAs($admin)->get('/admin/keyword-hub')
            ->assertOk()
            ->assertSee(route('admin.keyword-hub.published-all', ['type' => 'place']), false)
            ->assertSee(route('admin.keyword-hub.published-all', ['type' => 'shopping']), false)
            ->assertSee(route('admin.keyword-hub.published', ['type' => 'place', 'category' => $placeRoot->id]), false)
            ->assertSee(route('admin.keyword-hub.published', ['type' => 'shopping', 'category' => $shopRoot->id]), false);

        $this->actingAs($admin)->get('/admin/keyword-hub/published/place/'.$placeRoot->id)
            ->assertOk()
            ->assertSee('강남 한식')
            ->assertSee($keywordDoc->shareUrl(), false)
            ->assertDontSee('여름이불');

        $this->actingAs($admin)->get('/admin/keyword-hub/published/place')
            ->assertOk()
            ->assertSee('강남 한식')
            ->assertDontSee('여름이불');

        $this->actingAs($admin)->get('/admin/keyword-hub/published/shopping/'.$shopRoot->id)
            ->assertOk()
            ->assertSee('여름이불')
            ->assertSee($marketDoc->shareUrl(), false)
            ->assertDontSee('강남 한식');

        $this->actingAs($admin)->get('/admin/keyword-hub/published/shopping')
            ->assertOk()
            ->assertSee('여름이불')
            ->assertSee($marketDoc->shareUrl(), false);
    }

    public function test_published_candidate_keywords_link_to_analysis_documents(): void
    {
        $admin = $this->admin();
        $system = User::create([
            'name' => 'hub',
            'email' => config('rankfree.hub.system_user_email', 'hub-system@rankfree.kr'),
            'password' => 'x1234567',
            'role' => 'super',
        ]);

        $place = $this->category(['type' => 'place', 'name' => '맛집', 'slug' => '맛집', 'seed_keywords' => []]);
        $shopping = $this->category(['type' => 'shopping', 'name' => '침구', 'slug' => '침구', 'seed_keywords' => []]);

        KeywordCandidate::create(['category_id' => $place->id, 'keyword' => '강남 맛집', 'source' => 'combo', 'region' => '강남', 'status' => 'published']);
        KeywordCandidate::create(['category_id' => $shopping->id, 'keyword' => '여름이불', 'source' => 'datalab', 'status' => 'published']);

        $keywordDoc = KeywordSearch::create([
            'origin' => 'hub',
            'category_id' => $place->id,
            'keyword' => '강남 맛집',
            'monthly_total' => 8000,
        ]);
        $marketDoc = MarketAnalysis::create([
            'user_id' => $system->id,
            'category_id' => $shopping->id,
            'keyword' => '여름이불',
            'total_count' => 1000,
            'item_count' => 80,
            'monthly_search' => 5000,
            'snapshot' => ['top_products' => []],
        ]);
        $shoppingKeywordDoc = KeywordSearch::create([
            'origin' => 'hub',
            'category_id' => $shopping->id,
            'keyword' => '여름이불',
            'monthly_total' => 5000,
        ]);

        $this->actingAs($admin)->get('/admin/keyword-hub/candidates?status=published&type=place')
            ->assertOk()
            ->assertSee('강남 맛집')
            ->assertSee('키워드분석')
            ->assertSee($keywordDoc->shareUrl(), false);

        $this->actingAs($admin)->get('/admin/keyword-hub/candidates?status=published&type=shopping')
            ->assertOk()
            ->assertSee('여름이불')
            ->assertSee('키워드분석')
            ->assertSee('쇼핑시장분석')
            ->assertSee($shoppingKeywordDoc->shareUrl(), false)
            ->assertSee($marketDoc->shareUrl(), false);
    }

    public function test_publish_batch_publishes_one_and_reports_remaining(): void
    {
        $admin = $this->admin();
        $cat = $this->category();
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '캠핑의자', 'monthly_total' => 5000, 'status' => 'approved']);
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '캠핑테이블', 'monthly_total' => 2000, 'status' => 'approved']);

        $this->mock(KeywordHubPublisher::class, function ($m) use ($cat) {
            $m->shouldReceive('publish')->once()->andReturnUsing(function ($c) use ($cat) {
                $c->update(['status' => 'published']);

                return KeywordSearch::create(['origin' => 'hub', 'keyword' => $c->keyword, 'category_id' => $cat->id]);
            });
        });

        // 검색량 큰 순으로 1건만 발행하고 남은 수를 돌려준다(연속 발행 루프의 종료 조건)
        $this->actingAs($admin)->postJson('/admin/keyword-hub/publish-batch')
            ->assertOk()
            ->assertJsonPath('data.published', 1)
            ->assertJsonPath('data.remaining', 1)
            ->assertJsonPath('data.items.0.keyword', '캠핑의자')
            ->assertJsonPath('data.items.0.ok', true);

        // 쌓인 키워드가 소진되면 published=0 · remaining=0 (루프 정지 신호)
        KeywordCandidate::query()->update(['status' => 'published']);
        $this->actingAs($admin)->postJson('/admin/keyword-hub/publish-batch')
            ->assertOk()
            ->assertJsonPath('data.published', 0)
            ->assertJsonPath('data.remaining', 0);
    }

    public function test_publish_batch_runs_accumulated_pending_filtered_by_type(): void
    {
        $admin = $this->admin();
        // 승인 절차 없이 pending 으로 쌓인 키워드 — 쇼핑/플레이스 각각
        $shop = $this->category(['type' => 'shopping', 'name' => '캠핑용품', 'slug' => '캠핑용품', 'seed_keywords' => []]);
        $place = $this->category(['type' => 'place', 'name' => '지역맛집', 'slug' => '지역맛집', 'seed_keywords' => []]);
        KeywordCandidate::create(['category_id' => $shop->id, 'keyword' => '캠핑의자', 'monthly_total' => 5000, 'status' => 'pending']);
        KeywordCandidate::create(['category_id' => $place->id, 'keyword' => '강남 맛집', 'monthly_total' => 8000, 'status' => 'pending']);

        $this->mock(KeywordHubPublisher::class, function ($m) {
            $m->shouldReceive('publish')->andReturnUsing(function ($c) {
                $c->update(['status' => 'published']);

                return KeywordSearch::create(['origin' => 'hub', 'keyword' => $c->keyword, 'category_id' => $c->category_id]);
            });
        });

        // 쇼핑 선택 → 쇼핑 카테고리의 pending 을 승인 없이 발행, 플레이스는 건드리지 않는다
        $this->actingAs($admin)->postJson('/admin/keyword-hub/publish-batch', ['type' => 'shopping'])
            ->assertOk()
            ->assertJsonPath('data.published', 1)
            ->assertJsonPath('data.items.0.keyword', '캠핑의자')
            ->assertJsonPath('data.remaining', 0); // 쇼핑 pending 1개뿐 → 처리 후 0

        $this->assertDatabaseHas('keyword_candidates', ['keyword' => '캠핑의자', 'status' => 'published']);
        $this->assertDatabaseHas('keyword_candidates', ['keyword' => '강남 맛집', 'status' => 'pending']); // 타입 격리

        // 플레이스 선택 → 플레이스 pending 발행
        $this->actingAs($admin)->postJson('/admin/keyword-hub/publish-batch', ['type' => 'place'])
            ->assertOk()
            ->assertJsonPath('data.published', 1)
            ->assertJsonPath('data.items.0.keyword', '강남 맛집')
            ->assertJsonPath('data.remaining', 0);
    }

    public function test_auto_toggle_starts_and_stops_with_type(): void
    {
        $admin = $this->admin();
        $shop = $this->category(['seed_keywords' => []]);
        KeywordCandidate::create(['category_id' => $shop->id, 'keyword' => '캠핑의자', 'monthly_total' => 5000, 'status' => 'pending']);

        // 시작 — 유형 미지정이면 쇼핑+플레이스 전체를 동시에 처리한다
        $this->actingAs($admin)->postJson('/admin/keyword-hub/auto', ['on' => 1])
            ->assertOk()
            ->assertJsonPath('data.running', true)
            ->assertJsonPath('data.type', null)
            ->assertJsonPath('data.remaining', 1)
            ->assertJsonPath('data.by_type.place.remaining', 0)
            ->assertJsonPath('data.by_type.shopping.remaining', 1);
        $this->assertTrue(HubAutoRun::isRunning());

        // 상태 폴링
        $this->actingAs($admin)->getJson('/admin/keyword-hub/auto-status')
            ->assertOk()->assertJsonPath('data.running', true)->assertJsonPath('data.type', null);

        // 중단
        $this->actingAs($admin)->postJson('/admin/keyword-hub/auto', ['on' => 0])
            ->assertOk()->assertJsonPath('data.running', false);
        $this->assertFalse(HubAutoRun::isRunning());
    }

    public function test_auto_run_progress_tracks_place_and_shopping_separately(): void
    {
        $shop = $this->category(['seed_keywords' => []]);
        $place = $this->category(['type' => 'place', 'name' => '지역맛집', 'slug' => '지역맛집', 'seed_keywords' => []]);
        $shopCandidate = KeywordCandidate::create(['category_id' => $shop->id, 'keyword' => '캠핑의자', 'monthly_total' => 5000, 'status' => 'pending']);
        $placeCandidate = KeywordCandidate::create(['category_id' => $place->id, 'keyword' => '강남 맛집', 'monthly_total' => 8000, 'status' => 'pending']);

        HubAutoRun::start(null);
        $state = HubAutoRun::state();
        $this->assertSame(1, $state['place_remaining']);
        $this->assertSame(1, $state['shopping_remaining']);

        $placeCandidate->update(['status' => 'published']);
        $state = HubAutoRun::progress(1, 0, 'place');
        $this->assertSame(1, $state['place_done']);
        $this->assertSame(0, $state['shopping_done']);
        $this->assertSame(0, $state['place_remaining']);
        $this->assertSame(1, $state['shopping_remaining']);

        $shopCandidate->update(['status' => 'rejected']);
        $state = HubAutoRun::progress(0, 1, 'shopping');
        $this->assertSame(1, $state['place_done']);
        $this->assertSame(1, $state['shopping_held']);
        $this->assertSame(0, $state['remaining']);
        $this->assertFalse($state['running']);
    }

    public function test_hub_auto_publish_command_drains_pending_by_type_when_on(): void
    {
        $shop = $this->category(['seed_keywords' => []]);
        $place = $this->category(['type' => 'place', 'name' => '지역맛집', 'slug' => '지역맛집', 'seed_keywords' => []]);
        KeywordCandidate::create(['category_id' => $shop->id, 'keyword' => '캠핑의자', 'monthly_total' => 5000, 'status' => 'pending']);
        KeywordCandidate::create(['category_id' => $place->id, 'keyword' => '강남 맛집', 'monthly_total' => 8000, 'status' => 'pending']);

        $this->mock(KeywordHubPublisher::class, function ($m) {
            $m->shouldReceive('publish')->andReturnUsing(function ($c) {
                $c->update(['status' => 'published']);

                return KeywordSearch::create(['origin' => 'hub', 'keyword' => $c->keyword, 'category_id' => $c->category_id]);
            });
        });

        // 꺼져 있으면 아무 것도 안 한다
        $this->artisan('hub:auto-publish')->assertSuccessful();
        $this->assertDatabaseHas('keyword_candidates', ['keyword' => '캠핑의자', 'status' => 'pending']);

        // 쇼핑으로 켜고 실행 → 큐 워커가 실제 분석을 병렬 처리한다.
        Queue::fake();
        HubAutoRun::start('shopping');
        $this->artisan('hub:auto-publish')->assertSuccessful();

        Queue::assertPushed(KeywordHubPublishCandidateJob::class, 1);
        $this->assertDatabaseHas('keyword_candidates', ['keyword' => '캠핑의자', 'status' => 'pending']);
        $this->assertDatabaseHas('keyword_candidates', ['keyword' => '강남 맛집', 'status' => 'pending']);
        $s = HubAutoRun::state();
        $this->assertSame(0, $s['done']);
        $this->assertSame(1, $s['remaining']);
        $this->assertSame(0, $s['place_remaining']);
        $this->assertSame(1, $s['shopping_remaining']);
        $this->assertTrue($s['running']);
    }

    public function test_admin_seed_list_hides_datalab_tree_categories(): void
    {
        $admin = $this->admin();
        $manual = $this->category(); // 수동 카테고리(naver_cid 없음)
        $datalab = KeywordCategory::create([
            'type' => 'shopping', 'name' => '패션잡화', 'slug' => '패션잡화',
            'naver_cid' => 50000001, 'is_active' => true,
        ]);

        $res = $this->actingAs($admin)->get('/admin/keyword-hub/candidates')->assertOk();

        // 카테고리 수정 폼은 후보 화면에서 제거했다. 데이터랩 분류도 카드로 펼치지 않는다.
        $res->assertDontSee('keyword-hub/categories/'.$manual->id, false);
        $res->assertDontSee('keyword-hub/categories/'.$datalab->id, false);
    }

    public function test_admin_collect_rotation_skips_datalab_categories(): void
    {
        $admin = $this->admin();
        // 데이터랩 분류만 있는 상태 — 자동 로테이션 대상이 아니므로 '수집할 카테고리 없음' 안내
        KeywordCategory::create([
            'type' => 'shopping', 'name' => '패션잡화', 'slug' => '패션잡화',
            'naver_cid' => 50000001, 'is_active' => true,
        ]);

        $this->actingAs($admin)->post('/admin/keyword-hub/collect')
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, '수집할 카테고리가 없습니다'));
    }

    public function test_admin_collect_and_publish_actions(): void
    {
        $admin = $this->admin();
        $cat = $this->category();

        $this->mock(KeywordHubCollector::class, function ($m) {
            $m->shouldReceive('collect')->once()
                ->andReturn(['seeds' => 1, 'created' => 3, 'updated' => 0, 'filtered' => 1]);
        });
        $this->actingAs($admin)->post('/admin/keyword-hub/collect', ['category_id' => $cat->id])
            ->assertRedirect()->assertSessionHas('status');

        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '캠핑의자', 'monthly_total' => 5000, 'status' => 'approved']);
        $this->mock(KeywordHubPublisher::class, function ($m) {
            $m->shouldReceive('publish')->once()->andReturn(new KeywordSearch);
        });
        $this->actingAs($admin)->post('/admin/keyword-hub/publish', ['limit' => 3])
            ->assertRedirect()->assertSessionHas('status');
    }

    public function test_admin_can_queue_parallel_collection_run(): void
    {
        Queue::fake();
        $admin = $this->admin();

        $placeA = KeywordCategory::create([
            'type' => 'place',
            'name' => 'place food',
            'slug' => 'place-food',
            'seed_keywords' => ['강남 맛집'],
            'is_active' => true,
        ]);
        $placeB = KeywordCategory::create([
            'type' => 'place',
            'name' => 'place cafe',
            'slug' => 'place-cafe',
            'seed_keywords' => ['성수 카페'],
            'is_active' => true,
        ]);
        KeywordCategory::create([
            'type' => 'shopping',
            'name' => 'fashion',
            'slug' => 'fashion',
            'naver_cid' => 50000000,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post('/admin/keyword-hub/collect-batch', [
            'collect_place' => 1,
            'collect_shopping' => 1,
            'place_limit' => 2,
            'shopping_pages' => 1,
            'shopping_depth' => 2,
            'shopping_delay_ms' => 0,
        ])->assertRedirect()->assertSessionHas('status');

        $run = KeywordHubRun::latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('both', $run->type);
        $this->assertSame(3, $run->total_jobs);
        $this->assertDatabaseHas('keyword_hub_run_items', ['run_id' => $run->id, 'type' => 'place', 'target_id' => (string) $placeA->id]);
        $this->assertDatabaseHas('keyword_hub_run_items', ['run_id' => $run->id, 'type' => 'place', 'target_id' => (string) $placeB->id]);
        $this->assertDatabaseHas('keyword_hub_run_items', ['run_id' => $run->id, 'type' => 'shopping', 'target_id' => '50000000']);

        Queue::assertPushed(KeywordHubCollectCategoryJob::class, 2);
        Queue::assertPushed(KeywordHubCollectShoppingRootJob::class, 1);
    }

    public function test_collection_status_returns_latest_runs(): void
    {
        $admin = $this->admin();
        $run = KeywordHubRun::create([
            'type' => 'both',
            'status' => 'running',
            'total_jobs' => 2,
            'finished_jobs' => 1,
            'created_candidates' => 7,
        ]);
        $run->items()->create([
            'type' => 'place',
            'target_type' => 'category',
            'target_id' => '1',
            'label' => 'place food',
            'status' => 'completed',
            'created_candidates' => 7,
        ]);

        $this->actingAs($admin)->getJson('/admin/keyword-hub/collect-status')
            ->assertOk()
            ->assertJsonPath('data.0.id', $run->id)
            ->assertJsonPath('data.0.status', 'running')
            ->assertJsonPath('data.0.progress', 50)
            ->assertJsonPath('data.0.items.0.label', 'place food');
    }

    public function test_admin_can_toggle_collection_processing(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/admin/keyword-hub/collect-control', ['enabled' => 0])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.updated_by', $admin->email);
        $this->assertFalse(KeywordHubCollectionControl::enabled());

        $this->actingAs($admin)->postJson('/admin/keyword-hub/collect-control', ['enabled' => 1])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);
        $this->assertTrue(KeywordHubCollectionControl::enabled());
    }

    public function test_collection_job_waits_when_admin_control_is_off(): void
    {
        $cat = KeywordCategory::create([
            'type' => 'place',
            'name' => 'place food',
            'slug' => 'place-food-off',
            'seed_keywords' => ['강남 맛집'],
            'is_active' => true,
        ]);
        $run = KeywordHubRun::create(['type' => 'place', 'status' => 'queued', 'total_jobs' => 1]);
        $item = $run->items()->create([
            'type' => 'place',
            'target_type' => 'category',
            'target_id' => (string) $cat->id,
            'label' => $cat->name,
            'status' => 'queued',
        ]);

        KeywordHubCollectionControl::set(false, 'test');
        $this->mock(KeywordHubCollector::class, function ($m) {
            $m->shouldReceive('collect')->never();
        });

        (new KeywordHubCollectCategoryJob($item->id))->handle(app(KeywordHubCollector::class));

        $this->assertDatabaseHas('keyword_hub_run_items', [
            'id' => $item->id,
            'status' => 'queued',
            'note' => '관리자 OFF 상태로 대기 중',
        ]);
    }
}
