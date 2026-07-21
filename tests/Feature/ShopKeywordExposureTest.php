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
            'rankfree.shopping.exposure.batch_size' => 500,
            'rankfree.shopping.exposure.suffixes' => ['추천', '인기', '무료배송'],
            'rankfree.shopping.exposure.negatives' => ['과다복용'],
            'rankfree.searchad.api_key' => '', 'rankfree.searchad.accounts' => [],
        ]);

        // 내 상품 111: mallName=종근당(내 브랜드), 제목에 조합 재료 다수(고함량·1000·리포좀·분말·스틱·고용량)
        $me = ['sourceType' => 'SUPER_POINT', 'rank' => 1, 'channelProductId' => '111', 'mallName' => '종근당', 'discountedSalePrice' => 19900,
            'productName' => '종근당 비타민c 고함량 1000 리포좀 분말 스틱 고용량'];
        $others = [
            ['sourceType' => 'AD', 'rank' => 9, 'channelProductId' => '999', 'productName' => '광고 비타민c', 'mallName' => '광고몰', 'discountedSalePrice' => 9900],
            ['sourceType' => 'SAS', 'rank' => 2, 'channelProductId' => '222', 'productName' => '고려은단 비타민c 고함량 리포좀', 'mallName' => '고려은단', 'discountedSalePrice' => 25000],
            ['sourceType' => 'SAS', 'rank' => 3, 'channelProductId' => '333', 'productName' => '닥터가 비타민c 리포좀 3000', 'mallName' => '닥터가 공식스토어', 'discountedSalePrice' => 39800],
        ];
        $html = fn (array $slots) => '<html><script>naver.search.ext.newshopping["shopping"]._INITIAL_STATE = '
            .json_encode(['initProps' => ['pagedSlot' => [['slots' => array_map(fn ($s) => ['slotType' => 'CARD', 'data' => $s + ['productUrl' => ['mobileUrl' => 'x']]], $slots)]]]], JSON_UNESCAPED_UNICODE).';</script></html>';
        $with = $html(array_merge([$others[0], $me], array_slice($others, 1)));   // 111 포함(제목·"고함량" 조합 노출)
        $without = $html($others);                                                 // 111 없음(그 외 조합 미노출)

        Http::fake(function ($request) use ($with, $without) {
            $url = $request->url();
            if (str_contains($url, 'ac.search.naver')) {
                return Http::response(['items' => [[['비타민c1000'], ['비타민c 고함량'], ['비타민c과다복용']]]], 200);
            }
            if (str_contains($url, 'm.search.naver')) {
                $q = (string) ($request->data()['query'] ?? '');
                $nq = mb_strtolower(str_replace(' ', '', $q));
                // 111 은 core 검색(제목 추출)·"고함량" 든 조합에서만 노출 → 그 외 조합은 미노출
                return Http::response(($nq === '비타민c' || str_contains($nq, '고함량')) ? $with : $without, 200);
            }

            return Http::response('', 200);
        });
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
            if ((int) $this->actingAs($user)->post(route('console.shop-keyword.check', $a))->json('remaining') <= 0) {
                break;
            }
        }
    }

    public function test_index_renders(): void
    {
        $this->actingAs(User::factory()->create())->get(route('console.shop-keyword'))->assertOk()->assertSee('쇼핑 노출 키워드');
    }

    public function test_captures_product_info_and_builds_title_combos(): void
    {
        $a = $this->store(User::factory()->create());

        // 내 상품정보(제목·브랜드·가격) 캡처
        $this->assertSame('종근당 비타민c 고함량 1000 리포좀 분말 스틱 고용량', $a->product_title);
        $this->assertSame('종근당', $a->mall_name);            // 내 브랜드(업체명)
        $this->assertSame(19900, $a->product_price);

        // 제목 단어 조합 + 브랜드 조합
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '비타민c 고함량']);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '종근당 비타민c']);
        // 제목 단어 토큰
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'title', 'keyword' => '고함량']);
        // 경쟁 브랜드는 수집만(참고)
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'competitor_brand', 'keyword' => '고려은단']);
    }

    public function test_reference_sources_not_used_in_combos(): void
    {
        $a = $this->store(User::factory()->create());
        // 참고 소스(자동완성/경쟁브랜드)는 조합(kind=combo)에 as-is 로 안 들어간다
        $this->assertDatabaseMissing('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '고려은단']);
        // 자동완성 원문은 참고 토큰으로만
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'autocomplete']);
    }

    public function test_exposure_rank_from_mobile_organic(): void
    {
        $u = User::factory()->create();
        $a = $this->store($u);
        $this->runChecks($u, $a);
        $a->refresh();

        $this->assertSame('done', $a->status);
        $this->assertGreaterThan(0, $a->exposed_count);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '비타민c 고함량', 'rank' => 1]);
    }

    public function test_negative_words_excluded(): void
    {
        $a = $this->store(User::factory()->create());
        foreach ($a->items()->pluck('keyword') as $kw) {
            $this->assertStringNotContainsString('과다복용', $kw);
        }
    }

    public function test_regenerate_hides_nonexposed_and_adds_new(): void
    {
        // "고함량" 조합만 노출, 나머지 미노출 → regenerate 가 미노출을 감추고 새 조합 추가
        $u = User::factory()->create();
        $a = $this->store($u, ['target_combos' => 30]);
        $this->runChecks($u, $a);
        $a->refresh();
        $this->assertGreaterThan(0, $a->combos()->where('rank', '<=', 0)->count());   // 미노출 존재

        $r = $this->actingAs($u)->post(route('console.shop-keyword.regenerate', $a))->assertOk();
        $this->assertGreaterThan(0, (int) $r->json('added'));
        $a->refresh();
        $this->assertGreaterThan(0, $a->allCombos()->where('hidden', true)->count());   // 미노출 감춰짐
        $this->assertGreaterThan(0, $a->combos()->whereNull('rank')->count());          // 새 미확인 조합
    }

    public function test_delete_token_cascades_and_bans(): void
    {
        $u = User::factory()->create();
        $a = $this->store($u);
        $title = ShopKeywordAnalysisItem::where('analysis_id', $a->id)->where('source', 'title')->where('keyword', '고함량')->first();
        $this->assertNotNull($title);
        $this->assertGreaterThan(0, $a->allCombos()->where('keyword', 'like', '%고함량%')->count());

        $this->actingAs($u)->delete(route('console.shop-keyword.item', [$a, $title]))->assertOk();

        $this->assertSame(0, $a->allCombos()->where('keyword', 'like', '%고함량%')->count());
        $this->assertContains('고함량', (array) $a->fresh()->banned);   // 삭제어 기록
    }

    public function test_ownership_enforced(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $a = ShopKeywordAnalysis::create(['user_id' => $owner->id, 'core_keyword' => '비타민c', 'threshold' => 5, 'status' => 'done']);
        $this->actingAs($other)->get(route('console.shop-keyword.show', $a))->assertForbidden();
        $this->actingAs($other)->post(route('console.shop-keyword.regenerate', $a))->assertForbidden();
    }
}
