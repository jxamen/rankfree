<?php

namespace Tests\Feature;

use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopKeywordExposureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'rankfree.shopping.api_keys' => [['id' => 'a', 'secret' => 'b']],
            'rankfree.shopping.max_pages' => 1,
            'rankfree.shopping.page_delay_ms' => 0,
            'rankfree.shopping.exposure.max_combos' => 80,
            'rankfree.shopping.exposure.top' => 5,
            'rankfree.shopping.exposure.batch_size' => 300,   // 한 번의 check() 로 전부 체크(테스트 편의)
            // 검색광고 계정 미설정 → keywordstool analyze/volumes 는 조용히 스킵(외부호출 없음)
            'rankfree.searchad.api_key' => '', 'rankfree.searchad.accounts' => [],
        ]);

        Http::fake([
            'ac.search.naver.com/*' => Http::response(['items' => [[['비타민c1000'], ['비타민c 고함량']]]], 200),
            'search.naver.com/*' => Http::response('', 200),
            'm.search.naver.com/*' => Http::response('', 200),
            // shop.json: 상품 111 을 3위에 노출(모든 쿼리 공통)
            'openapi.naver.com/*' => Http::response(['total' => 100, 'items' => [
                ['productId' => '999', 'title' => 'A', 'mallName' => 'm', 'lprice' => '1', 'link' => 'x', 'image' => ''],
                ['productId' => '000', 'title' => 'B', 'mallName' => 'm', 'lprice' => '1', 'link' => 'x', 'image' => ''],
                ['productId' => '111', 'title' => '내 비타민', 'mallName' => '내몰', 'lprice' => '19900', 'link' => 'http://x/111', 'image' => ''],
            ]], 200),
        ]);
    }

    private function store(User $user, array $override = []): ShopKeywordAnalysis
    {
        $brandHtml = '<ul class="basicTypeFilter_finder_tit_list__x"><li data-shp-contents-id="종근당" data-shp-contents-type="브랜드"><span>종근당</span></li></ul>';
        $attrHtml = '<div class="product_detail_box__x">'
            .'<a data-shp-contents-id="1개월분" data-shp-contents-type="제품용량_M(속성)">제품용량 : 1개월분</a>'
            .'<a data-shp-contents-id="1000mg" data-shp-contents-type="함량_M(속성)">함량 : 1000mg</a></div>';

        $this->actingAs($user)->post(route('console.shop-keyword.store'), array_merge([
            'core_keyword' => '비타민c',
            'product' => 'https://smartstore.naver.com/x/products/111',
            'filter_html' => $brandHtml.$attrHtml,
            'threshold' => 5,
        ], $override));

        return ShopKeywordAnalysis::latest('id')->first();
    }

    public function test_index_renders(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('console.shop-keyword'))->assertOk()->assertSee('쇼핑 노출 키워드');
    }

    public function test_store_generates_combos_pending_check(): void
    {
        $user = User::factory()->create();
        $analysis = $this->store($user);

        $this->assertNotNull($analysis);
        $this->assertSame('checking', $analysis->status);       // 아직 순위 미확인
        $this->assertSame(0, $analysis->exposed_count);
        $this->assertGreaterThan(0, $analysis->combo_count);

        // 2단어(브랜드×핵심, 핵심×속성) + 3단어 롱테일 조합이 생성된다
        foreach (['종근당 비타민c', '비타민c 1개월분', '비타민c 1000mg', '종근당 비타민c 1000mg', '비타민c 1개월분 1000mg'] as $kw) {
            $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $analysis->id, 'kind' => 'combo', 'keyword' => $kw, 'rank' => null]);
        }
        // 토큰 저장
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $analysis->id, 'kind' => 'token', 'source' => 'brand', 'keyword' => '종근당']);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $analysis->id, 'kind' => 'token', 'source' => 'attribute', 'keyword' => '1개월분']);
    }

    public function test_check_batch_detects_exposure(): void
    {
        $user = User::factory()->create();
        $analysis = $this->store($user);

        // 폴링 — remaining 0 까지
        $remaining = null;
        for ($i = 0; $i < 10; $i++) {
            $r = $this->actingAs($user)->post(route('console.shop-keyword.check', $analysis))->assertOk();
            $remaining = $r->json('remaining');
            if ($remaining <= 0) {
                break;
            }
        }

        $analysis->refresh();
        $this->assertSame(0, $remaining);
        $this->assertSame('done', $analysis->status);
        $this->assertSame($analysis->combo_count, $analysis->checked_count);
        // 상품이 3위(≤5)로 잡히므로 확인된 조합 전부 노출
        $this->assertGreaterThan(0, $analysis->exposed_count);
        $this->assertSame($analysis->checked_count, $analysis->exposed_count);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $analysis->id, 'kind' => 'combo', 'keyword' => '종근당 비타민c', 'rank' => 3]);
    }

    public function test_target_combos_select_controls_count(): void
    {
        $user = User::factory()->create();
        $analysis = $this->store($user, ['target_combos' => 30]);
        $this->assertLessThanOrEqual(30, $analysis->combo_count);
    }

    public function test_suffixes_expand_combos(): void
    {
        config(['rankfree.shopping.exposure.suffixes' => ['추천', '인기', '무료배송']]);
        $user = User::factory()->create();

        $analysis = $this->store($user, ['suffixes' => '가성비, 정품', 'filter_html' => null]);

        foreach (['비타민c 추천', '비타민c 인기', '비타민c 무료배송', '비타민c 가성비', '비타민c 정품'] as $kw) {
            $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $analysis->id, 'kind' => 'combo', 'keyword' => $kw]);
        }
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $analysis->id, 'kind' => 'token', 'source' => 'suffix', 'keyword' => '가성비']);
    }

    public function test_modifiers_from_extracted_expand_sparse_products(): void
    {
        // 속성·브랜드가 전혀 없는 상품 — 자동완성 "비타민c 고함량"에서 "고함량" 수식어를 파생해 롱테일 조합
        $user = User::factory()->create();
        $analysis = $this->store($user, ['filter_html' => null, 'suffixes' => '']);

        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $analysis->id, 'kind' => 'token', 'source' => 'modifier', 'keyword' => '고함량']);
    }

    public function test_combos_must_contain_core_keyword(): void
    {
        $user = User::factory()->create();
        $analysis = $this->store($user, ['filter_html' => null]);

        foreach ($analysis->combos()->pluck('keyword') as $kw) {
            $this->assertStringContainsStringIgnoringCase('비타민c', str_replace(' ', '', $kw));
        }
    }

    public function test_show_requires_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $analysis = ShopKeywordAnalysis::create([
            'user_id' => $owner->id, 'core_keyword' => '비타민c', 'threshold' => 5, 'status' => 'done',
        ]);
        $this->actingAs($other)->get(route('console.shop-keyword.show', $analysis))->assertForbidden();
        $this->actingAs($other)->post(route('console.shop-keyword.check', $analysis))->assertForbidden();
    }
}
