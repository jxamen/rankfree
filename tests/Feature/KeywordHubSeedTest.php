<?php

namespace Tests\Feature;

use App\Domain\Keyword\NaverDataLabShoppingService;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 키워드 허브 — 데이터랩 쇼핑인사이트 수집(hub:shopping-collect) + 플레이스 지역×업종 조합(hub:place-seed). */
class KeywordHubSeedTest extends TestCase
{
    use RefreshDatabase;

    /** 데이터랩 API 페이크 — cid 트리(0→패션잡화→여성신발→운동화)와 분야별 인기검색어. */
    private function fakeDatalab(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'getCategory.naver')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $children = match ((int) ($q['cid'] ?? -1)) {
                    0 => [['cid' => 50000001, 'name' => '패션잡화', 'level' => 1, 'leaf' => false, 'deleted' => false]],
                    50000001 => [['cid' => 50000173, 'name' => '여성신발', 'level' => 2, 'leaf' => false, 'deleted' => false]],
                    50000173 => [['cid' => 50000174, 'name' => '운동화', 'level' => 3, 'leaf' => true, 'deleted' => false]],
                    default => [],
                };

                return Http::response(['cid' => (int) $q['cid'], 'childList' => $children], 200);
            }
            // getCategoryKeywordRank.naver — cid 별 인기검색어
            $cid = (int) ($request['cid'] ?? 0);
            $ranks = $cid === 50000173
                ? [['rank' => 1, 'keyword' => '젤리슈즈'], ['rank' => 2, 'keyword' => '이미발행슈즈']]
                : [['rank' => 1, 'keyword' => '나이키운동화']];

            return Http::response(['returnCode' => 0, 'ranks' => $ranks], 200);
        });
    }

    public function test_datalab_service_parses_children_and_ranks(): void
    {
        $this->fakeDatalab();
        $svc = app(NaverDataLabShoppingService::class);

        $roots = $svc->children(0);
        $this->assertSame([['cid' => 50000001, 'name' => '패션잡화', 'leaf' => false]], $roots);
        $this->assertSame('젤리슈즈', $svc->topKeywords(50000173)[0]['keyword']);
    }

    public function test_shopping_collect_builds_category_tree_and_candidates(): void
    {
        $this->fakeDatalab();
        KeywordSearch::create(['origin' => 'hub', 'keyword' => '이미발행슈즈', 'monthly_total' => 1000]);

        $this->artisan('hub:shopping-collect', ['--pages' => 1, '--delay-ms' => 0])->assertSuccessful();

        // 1·2·3분류 카테고리 동기화(naver_cid 매핑) — 데이터랩 트리를 그대로 3계층으로
        $root = KeywordCategory::where('naver_cid', 50000001)->first();
        $sub = KeywordCategory::where('naver_cid', 50000173)->first();
        $third = KeywordCategory::where('naver_cid', 50000174)->first();
        $this->assertNotNull($root);
        $this->assertNotNull($sub);
        $this->assertNotNull($third, '3분류(운동화)도 카테고리로 연동돼야 한다');
        $this->assertSame('shopping', $root->type);
        $this->assertNull($root->parent_id);
        $this->assertSame($root->id, $sub->parent_id);
        $this->assertSame($sub->id, $third->parent_id);

        // 인기검색어는 각 분류 카테고리의 후보로 — 3분류 키워드는 3분류 카테고리에
        $this->assertDatabaseHas('keyword_candidates', ['category_id' => $sub->id, 'keyword' => '젤리슈즈', 'source' => 'datalab', 'status' => 'pending']);
        $this->assertDatabaseHas('keyword_candidates', ['category_id' => $third->id, 'keyword' => '나이키운동화', 'source' => 'datalab']);
        // 이미 허브 발행된 키워드는 제외
        $this->assertDatabaseMissing('keyword_candidates', ['keyword' => '이미발행슈즈']);

        $note = KeywordCandidate::where('keyword', '젤리슈즈')->value('note');
        $this->assertStringContainsString("데이터랩 '패션잡화 > 여성신발' 인기 #1", (string) $note);

        // 재실행해도 중복 생성 없음
        $before = KeywordCandidate::count();
        $this->artisan('hub:shopping-collect', ['--pages' => 1, '--delay-ms' => 0])->assertSuccessful();
        $this->assertSame($before, KeywordCandidate::count());
    }

    public function test_place_seed_generates_region_combos_with_limit(): void
    {
        $this->artisan('hub:place-seed', ['--category' => 'hospital', '--limit' => 5])->assertSuccessful();

        $cat = KeywordCategory::where('type', 'place')->where('name', '병원·의원')->first();
        $this->assertNotNull($cat);
        $this->assertSame(5, KeywordCandidate::where('category_id', $cat->id)->count());
        // 첫 지역(구 단위 '강남') × 패턴 조합 — '강남 치과' 형태
        $this->assertDatabaseHas('keyword_candidates', ['category_id' => $cat->id, 'keyword' => '강남 치과', 'source' => 'combo', 'status' => 'pending']);

        // 재실행하면 이어서 추가(중복 없음)
        $this->artisan('hub:place-seed', ['--category' => 'hospital', '--limit' => 5])->assertSuccessful();
        $this->assertSame(10, KeywordCandidate::where('category_id', $cat->id)->count());
        $this->assertSame(10, KeywordCandidate::where('category_id', $cat->id)->distinct()->count('keyword'));
    }

    public function test_place_seed_skips_published_hub_keywords(): void
    {
        KeywordSearch::create(['origin' => 'hub', 'keyword' => '강남 치과', 'monthly_total' => 9000]);

        $this->artisan('hub:place-seed', ['--category' => 'hospital', '--limit' => 3])->assertSuccessful();

        $this->assertDatabaseMissing('keyword_candidates', ['keyword' => '강남 치과']);
        $this->assertSame(3, KeywordCandidate::count()); // 발행분 건너뛰고 다음 조합으로 채움
    }

    public function test_place_seed_includes_national_region_pool(): void
    {
        // 전국 행정구역 병합 — 조합 가능 총량이 수십만 규모(업종당 수만)여야 한다
        $this->artisan('hub:place-seed', ['--category' => 'hairshop', '--limit' => 999999])->assertSuccessful();

        $cat = KeywordCategory::where('type', 'place')->where('name', '헤어샵')->first();
        $count = KeywordCandidate::where('category_id', $cat->id)->count();
        $this->assertGreaterThan(20000, $count); // 지역 ~2,900 × 패턴 8
        // 전국 읍면동(regions_kr.php) 조합 포함 확인
        $this->assertDatabaseHas('keyword_candidates', ['category_id' => $cat->id, 'keyword' => '목천읍 미용실']);
    }

    public function test_topkeywords_accumulates_full_pages(): void
    {
        // 20개(꽉 찬 페이지) → 다음 페이지 계속, 5개(마지막) → 중단. 총 45개 누적.
        Http::fake(function ($request) {
            $page = (int) ($request['page'] ?? 1);
            $n = $page <= 2 ? 20 : 5;
            $ranks = [];
            for ($i = 1; $i <= $n; $i++) {
                $ranks[] = ['rank' => ($page - 1) * 20 + $i, 'keyword' => "키워드{$page}_{$i}"];
            }

            return Http::response(['returnCode' => 0, 'ranks' => $ranks], 200);
        });

        $out = app(NaverDataLabShoppingService::class)->topKeywords(50000173, 3);

        $this->assertCount(45, $out);
        $this->assertSame(41, $out[40]['rank']);
    }

    public function test_admin_bulk_all_applies_to_whole_filter(): void
    {
        $admin = User::create(['name' => '관리자', 'email' => 'bulk@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
        $cat = KeywordCategory::create(['type' => 'place', 'name' => '병원·의원', 'slug' => '병원-의원', 'is_active' => true]);
        foreach (['강남 치과', '서초 치과', '송파 치과'] as $kw) {
            KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => $kw, 'source' => 'combo', 'status' => 'pending']);
        }
        $other = KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '데이터랩키워드', 'source' => 'datalab', 'status' => 'pending']);

        $this->actingAs($admin)->post('/admin/keyword-hub/candidates/bulk-all', [
            'action' => 'approve', 'status' => 'pending', 'category' => $cat->id, 'source' => 'combo',
        ])->assertRedirect();

        // 필터(출처=combo)에 걸린 3건 전부 승인, 다른 출처는 그대로
        $this->assertSame(3, KeywordCandidate::where('status', 'approved')->count());
        $this->assertSame('pending', $other->fresh()->status);

        // 검색어 필터 일괄 — '강남'만 거부
        $this->actingAs($admin)->post('/admin/keyword-hub/candidates/bulk-all', [
            'action' => 'reject', 'status' => 'approved', 'q' => '강남',
        ])->assertRedirect();
        $this->assertSame('rejected', KeywordCandidate::where('keyword', '강남 치과')->value('status'));
        $this->assertSame('approved', KeywordCandidate::where('keyword', '서초 치과')->value('status'));
    }
}
