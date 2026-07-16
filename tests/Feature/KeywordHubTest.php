<?php

namespace Tests\Feature;

use App\Domain\Keyword\KeywordHubCollector;
use App\Domain\Keyword\KeywordHubPublisher;
use App\Domain\Keyword\KeywordReportBuilder;
use App\Domain\Keyword\NaverAutocompleteService;
use App\Domain\Keyword\NaverKeywordService;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 키워드 콘텐츠 허브(22 Phase 1) — 수집(필터)·발행(thin 보류)·크론·관리자 승인 큐. */
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
                    ['keyword' => '저볼륨키워드', 'monthly_total' => 10, 'comp_idx' => '낮음'],
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
        // 최소 검색량(기본 1,000) 미만 연관어는 자동 제외
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
        $cat = $this->category();
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

    public function test_publisher_holds_thin_candidates(): void
    {
        $this->mockBuilder([]); // 모든 키워드 vm=null (데이터 없음)
        $cat = $this->category();
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
        $cat = $this->category();
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

        // 화면 렌더 — 승인 탭에 후보 표시
        $this->actingAs($admin)->get('/admin/keyword-hub?status=approved')
            ->assertOk()->assertSee('캠핑의자')->assertSee('후보 큐');
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
}
