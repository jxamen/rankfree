<?php

namespace Tests\Feature;

use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordAnalysisItem;
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
            'rankfree.shopping.exposure.max_combos' => 100,
            'rankfree.shopping.exposure.top' => 5,
            'rankfree.shopping.exposure.batch_size' => 400,   // 한 번의 check() 로 전부
            'rankfree.shopping.exposure.suffixes' => ['추천', '인기', '무료배송'],
            'rankfree.shopping.exposure.negatives' => ['과다복용'],
            'rankfree.searchad.api_key' => '', 'rankfree.searchad.accounts' => [],
        ]);

        // 모바일 검색 가격비교: 광고 1 + 오가닉 3(내 상품 111 = 오가닉 1위). 브랜드=판매몰, 속성=상품명 빈출어(고함량·리포좀)
        $slots = [
            ['sourceType' => 'AD', 'rank' => 9, 'channelProductId' => '999', 'productName' => '광고 비타민c', 'mallName' => '광고몰'],
            ['sourceType' => 'SUPER_POINT', 'rank' => 1, 'channelProductId' => '111', 'productName' => '종근당 비타민c 고함량 1000', 'mallName' => '종근당'],
            ['sourceType' => 'SAS', 'rank' => 2, 'channelProductId' => '222', 'productName' => '고려은단 비타민c 고함량 리포좀', 'mallName' => '고려은단'],
            ['sourceType' => 'SAS', 'rank' => 3, 'channelProductId' => '333', 'productName' => '닥터가 비타민c 리포좀 3000', 'mallName' => '닥터가 공식스토어'],
        ];
        $mobileHtml = '<html><script>naver.search.ext.newshopping["shopping"]._INITIAL_STATE = '
            .json_encode(['initProps' => ['pagedSlot' => [['slots' => array_map(fn ($s) => ['slotType' => 'CARD', 'data' => $s + ['productUrl' => ['mobileUrl' => 'x']]], $slots)]]]], JSON_UNESCAPED_UNICODE).';</script></html>';

        Http::fake([
            'ac.search.naver.com/*' => Http::response(['items' => [[['비타민c1000'], ['비타민c 고함량'], ['비타민c과다복용']]]], 200),
            'm.search.naver.com/*' => Http::response($mobileHtml, 200),
            '*' => Http::response('', 200),
        ]);
    }

    private function store(User $user, array $override = []): ShopKeywordAnalysis
    {
        $this->actingAs($user)->post(route('console.shop-keyword.store'), array_merge([
            'core_keyword' => '비타민c',
            'product' => 'https://smartstore.naver.com/x/products/111',
            'threshold' => 5,
        ], $override));

        return ShopKeywordAnalysis::latest('id')->first();
    }

    private function runChecks(User $user, ShopKeywordAnalysis $a): void
    {
        for ($i = 0; $i < 10; $i++) {
            $r = $this->actingAs($user)->post(route('console.shop-keyword.check', $a));
            if ((int) $r->json('remaining') <= 0) {
                break;
            }
        }
    }

    public function test_index_renders(): void
    {
        $this->actingAs(User::factory()->create())->get(route('console.shop-keyword'))->assertOk()->assertSee('쇼핑 노출 키워드');
    }

    public function test_auto_extracts_brand_and_attribute_and_builds_combos(): void
    {
        $a = $this->store(User::factory()->create());
        $this->assertSame('checking', $a->status);

        // 브랜드(판매몰명)·속성(상품명 빈출어) 자동 추출
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'brand', 'keyword' => '종근당']);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'brand', 'keyword' => '닥터가']); // 공식스토어 접미 제거
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'attribute', 'keyword' => '고함량']);
        // 조합: 브랜드+핵심, 핵심+속성
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '종근당 비타민c']);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '비타민c 고함량']);
    }

    public function test_exposure_uses_mobile_organic_rank_excluding_ads(): void
    {
        $u = User::factory()->create();
        $a = $this->store($u);
        $this->runChecks($u, $a);
        $a->refresh();

        $this->assertSame('done', $a->status);
        $this->assertGreaterThan(0, $a->exposed_count);
        // 상품 111 = 광고 제외 오가닉 1위
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '종근당 비타민c', 'rank' => 1]);
    }

    public function test_suffixes_are_two_word_only_not_stacked(): void
    {
        $a = $this->store(User::factory()->create());
        // config 어미 → 2단어 조합
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '비타민c 추천']);
        // 어미·수식어가 3단어 이상으로 쌓인 조합은 없어야 한다(쓰레기 방지)
        foreach ($a->combos()->pluck('keyword') as $kw) {
            $this->assertStringNotContainsString('추천 인기', $kw);
            $this->assertStringNotContainsString('인기 무료배송', $kw);
        }
    }

    public function test_negative_words_excluded(): void
    {
        $a = $this->store(User::factory()->create());
        // 자동완성 "비타민c과다복용" → 과다복용 은 부정어라 토큰·조합 어디에도 없어야
        foreach ($a->items()->pluck('keyword') as $kw) {
            $this->assertStringNotContainsString('과다복용', $kw);
        }
    }

    public function test_delete_token_cascades_to_its_combos(): void
    {
        $u = User::factory()->create();
        $a = $this->store($u);

        $brand = ShopKeywordAnalysisItem::where('analysis_id', $a->id)->where('kind', 'token')->where('keyword', '종근당')->first();
        $this->assertNotNull($brand);
        $this->assertGreaterThan(0, $a->combos()->where('keyword', 'like', '%종근당%')->count());

        $this->actingAs($u)->delete(route('console.shop-keyword.item', [$a, $brand]))->assertOk();

        // 토큰 + 그 단어가 든 조합이 모두 사라짐
        $this->assertDatabaseMissing('shop_keyword_analysis_items', ['id' => $brand->id]);
        $this->assertSame(0, $a->combos()->where('keyword', 'like', '%종근당%')->count());
    }

    public function test_delete_combo_removes_only_that_combo(): void
    {
        $u = User::factory()->create();
        $a = $this->store($u);
        $combo = $a->combos()->first();
        $before = $a->combos()->count();

        $this->actingAs($u)->delete(route('console.shop-keyword.item', [$a, $combo]))->assertOk();

        $this->assertDatabaseMissing('shop_keyword_analysis_items', ['id' => $combo->id]);
        $this->assertSame($before - 1, $a->fresh()->combos()->count());
    }

    public function test_combos_contain_core_keyword(): void
    {
        $a = $this->store(User::factory()->create());
        foreach ($a->combos()->pluck('keyword') as $kw) {
            $this->assertStringContainsStringIgnoringCase('비타민c', str_replace(' ', '', $kw));
        }
    }

    public function test_ownership_enforced(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $a = ShopKeywordAnalysis::create(['user_id' => $owner->id, 'core_keyword' => '비타민c', 'threshold' => 5, 'status' => 'done']);
        $this->actingAs($other)->get(route('console.shop-keyword.show', $a))->assertForbidden();
        $this->actingAs($other)->post(route('console.shop-keyword.check', $a))->assertForbidden();
    }
}
