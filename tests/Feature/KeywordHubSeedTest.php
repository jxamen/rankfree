<?php

namespace Tests\Feature;

use App\Domain\Keyword\NaverDataLabShoppingService;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
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

        // 1·2분류 카테고리 동기화(naver_cid 매핑)
        $root = KeywordCategory::where('naver_cid', 50000001)->first();
        $sub = KeywordCategory::where('naver_cid', 50000173)->first();
        $this->assertNotNull($root);
        $this->assertNotNull($sub);
        $this->assertSame('shopping', $root->type);
        $this->assertNull($root->parent_id);
        $this->assertSame($root->id, $sub->parent_id);

        // 2분류 인기검색어 + 3분류(운동화) 인기검색어 모두 2분류 카테고리 후보로
        $this->assertDatabaseHas('keyword_candidates', ['category_id' => $sub->id, 'keyword' => '젤리슈즈', 'source' => 'datalab', 'status' => 'pending']);
        $this->assertDatabaseHas('keyword_candidates', ['category_id' => $sub->id, 'keyword' => '나이키운동화', 'source' => 'datalab']);
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
}
