<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordAnalysisItem;
use App\Models\ShopKeywordShortLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopKeywordExposureTest extends TestCase
{
    use RefreshDatabase;

    /** 111 포함 m.search HTML(확장 화면단 체크 테스트에서 재사용). */
    private string $htmlWith = '';

    private string $htmlWithout = '';

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
        // SAS=오가닉 가격비교(SUPER_POINT 는 슈퍼적립 광고성 슬롯이라 오가닉이 아님)
        $me = ['sourceType' => 'SAS', 'rank' => 1, 'channelProductId' => '111', 'mallName' => '종근당', 'discountedSalePrice' => 19900,
            'productName' => '종근당 비타민c 고함량 1000 리포좀 분말 스틱 고용량'];
        $others = [
            ['sourceType' => 'AD', 'rank' => 9, 'channelProductId' => '999', 'productName' => '광고 비타민c', 'mallName' => '광고몰', 'discountedSalePrice' => 9900],
            ['sourceType' => 'SAS', 'rank' => 2, 'channelProductId' => '222', 'productName' => '고려은단 비타민c 고함량 리포좀', 'mallName' => '고려은단', 'discountedSalePrice' => 25000],
            ['sourceType' => 'SAS', 'rank' => 3, 'channelProductId' => '333', 'productName' => '닥터가 비타민c 리포좀 3000', 'mallName' => '닥터가 공식스토어', 'discountedSalePrice' => 39800],
        ];
        $html = fn (array $slots) => '<html><script>naver.search.ext.newshopping["shopping"]._INITIAL_STATE = '
            .json_encode(['initProps' => ['pagedSlot' => [['slots' => array_map(fn ($s) => ['slotType' => 'CARD', 'data' => $s + ['productUrl' => ['mobileUrl' => 'x']]], $slots)]]]], JSON_UNESCAPED_UNICODE).';</script></html>';
        $with = $this->htmlWith = $html(array_merge([$others[0], $me], array_slice($others, 1)));   // 111 포함(제목·"고함량" 조합 노출)
        $without = $this->htmlWithout = $html($others);                                            // 111 없음(그 외 조합 미노출)

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

    /** @return array<string, string> */
    private function shortRedirectParams(string $token): array
    {
        $location = (string) $this->get(route('shop-keyword.short', $token))
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith('https://m.search.naver.com/search.naver?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        return $params;
    }

    public function test_index_renders(): void
    {
        $this->actingAs(User::factory()->create())->get(route('console.shop-keyword'))->assertOk()->assertSee('쇼핑 노출 키워드');
    }

    public function test_index_shows_exposed_keyword_and_url_totals(): void
    {
        $u = User::factory()->create();
        $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5,
            'product_url' => 'https://smartstore.naver.com/x/products/1', 'status' => 'done']);
        $b = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5,
            'product_url' => 'https://smartstore.naver.com/x/products/2', 'status' => 'done']);
        // 같은 키워드가 두 분석에서 노출 → 중복 제거로 1. 6위 밖(rank 9)은 제외.
        ShopKeywordAnalysisItem::create(['analysis_id' => $a->id, 'kind' => 'combo', 'source' => 'combo', 'keyword' => '비타민c 고함량', 'rank' => 3, 'checked_at' => now()]);
        ShopKeywordAnalysisItem::create(['analysis_id' => $b->id, 'kind' => 'combo', 'source' => 'combo', 'keyword' => '비타민c 고함량', 'rank' => 2, 'checked_at' => now()]);
        ShopKeywordAnalysisItem::create(['analysis_id' => $b->id, 'kind' => 'combo', 'source' => 'combo', 'keyword' => '비타민c 600정', 'rank' => 9, 'checked_at' => now()]);
        ShopKeywordShortLink::create(['analysis_id' => $a->id, 'token' => 'abc123abc12', 'group_no' => 1, 'group_count' => 2, 'keywords' => ['비타민c 고함량']]);
        ShopKeywordShortLink::create(['analysis_id' => $a->id, 'token' => 'def456def45', 'group_no' => 2, 'group_count' => 2, 'keywords' => ['비타민c 1000']]);

        $r = $this->actingAs($u)->get(route('console.shop-keyword'))->assertOk();
        $r->assertDontSee('상위 5위 이내 노출 키워드');
        $r->assertDontSee('단축 URL 수');
        $r->assertSee('단축 URL 2');
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
        $a = $this->store($u);
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

    public function test_extension_product_info_feeds_title_and_seller_tag_combos(): void
    {
        $user = User::factory()->create();
        [, $plain] = ExtToken::issue($user);

        // 확장이 상품 페이지에서 수집한 상품정보 업로드
        $this->withHeader('Authorization', 'Bearer '.$plain)->postJson('/api/ext/product-infos', [
            'channel_product_id' => '138663861',
            'title' => '고려은단 비타민C 1000 600정 X 1개 (20개월분)',
            'brand' => '고려은단비타민C1000',
            'mall_name' => '고려은단비타민C',
            'price' => 50000,
            'seller_tags' => ['메가도스비타민c', '비타민c1000', '고함량비타민'],
        ])->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('shop_product_infos', ['user_id' => $user->id, 'channel_product_id' => '138663861']);

        // 이 상품으로 분석 → 저장된 제목·SEO태그가 조합 재료로 사용됨(모바일 검색에 없어도)
        $a = $this->store($user, ['product' => 'https://brand.naver.com/koreaeundan/products/138663861']);

        $this->assertSame('고려은단 비타민C 1000 600정 X 1개 (20개월분)', $a->product_title);
        $this->assertSame(50000, $a->product_price);
        // 수량·기간 토큰도 제목 단어로 추출
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'title', 'keyword' => '600정']);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'title', 'keyword' => '20개월분']);
        // seller_tag 통짜 조합(핵심 미포함도) + 제목단어 조합
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '메가도스비타민c']);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '비타민c 600정']);
        // 제목 연속 구절 조합(붙어있는 그대로, X 포함)
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '600정 X 1개']);
        // seller_tag 토큰
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'seller_tag', 'keyword' => '메가도스비타민c']);
    }

    public function test_extension_check_html_sets_rank_and_clears_blocked(): void
    {
        $u = User::factory()->create();
        $a = $this->store($u);
        $a->update(['status' => 'blocked']);   // 서버 fetch 가 한도에 걸렸던 상태

        // pending 배치 — 미확인 조합 id·keyword 목록
        $p = $this->actingAs($u)->get(route('console.shop-keyword.pending', $a))->assertOk()->json('data');
        $this->assertNotEmpty($p['items']);
        $item = $p['items'][0];

        // 확장(브라우저)이 가져온 HTML 로 판정 — 111 포함 → 오가닉 1위(광고 제외)
        $r = $this->actingAs($u)->postJson(route('console.shop-keyword.check-html', $a), [
            'item_id' => $item['id'], 'html' => $this->htmlWith,
        ])->assertOk();
        $this->assertSame(1, (int) $r->json('rank'));
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['id' => $item['id'], 'rank' => 1]);
        $this->assertContains($r->json('status'), ['checking', 'done']);   // blocked 해제

        // 빈 HTML(가격비교 미노출) → rank 0
        $p2 = $this->actingAs($u)->get(route('console.shop-keyword.pending', $a))->json('data');
        if (! empty($p2['items'])) {
            $r2 = $this->actingAs($u)->postJson(route('console.shop-keyword.check-html', $a), [
                'item_id' => $p2['items'][0]['id'], 'html' => '',
            ])->assertOk();
            $this->assertSame(0, (int) $r2->json('rank'));
        }
    }

    public function test_pause_persists_and_survives_late_check_and_reload(): void
    {
        $u = User::factory()->create();
        $a = $this->store($u);
        $this->assertSame('checking', $a->status);

        // "중단" — 서버에 paused 저장
        $this->actingAs($u)->postJson(route('console.shop-keyword.pause', $a), ['paused' => true])->assertOk();
        $this->assertSame('paused', $a->refresh()->status);

        // 새로고침(show) — 중단 상태로 렌더(자동 재시작 안 함: paused=true + 중단 라벨)
        $this->actingAs($u)->get(route('console.shop-keyword.show', $a))
            ->assertOk()
            ->assertSee('순위 확인 중단됨')
            ->assertSee('paused: true', false);

        // 중단 직후 늦게 도착한 확장 판정 1건이 paused 를 checking 으로 덮어쓰지 않는다
        $p = $this->actingAs($u)->get(route('console.shop-keyword.pending', $a))->json('data');
        $this->actingAs($u)->postJson(route('console.shop-keyword.check-html', $a), [
            'item_id' => $p['items'][0]['id'], 'html' => $this->htmlWith,
        ])->assertOk();
        $this->assertSame('paused', $a->refresh()->status);

        // "이어서 확인" — checking 으로 복귀
        $this->actingAs($u)->postJson(route('console.shop-keyword.pause', $a), ['paused' => false])->assertOk();
        $this->assertSame('checking', $a->refresh()->status);

        // 남이 내 분석을 중단시킬 수 없다
        $this->actingAs(User::factory()->create())
            ->postJson(route('console.shop-keyword.pause', $a), ['paused' => true])->assertForbidden();
    }

    public function test_check_html_backfills_product_title_from_matched_slot(): void
    {
        $u = User::factory()->create();
        $a = ShopKeywordAnalysis::create([
            'user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5,
            'product_url' => 'https://smartstore.naver.com/x/products/111', 'product_id' => '111',
            'combo_count' => 1, 'status' => 'checking',
        ]);
        $item = ShopKeywordAnalysisItem::create(['analysis_id' => $a->id, 'kind' => 'combo', 'source' => 'combo', 'keyword' => '비타민c 고함량']);

        $this->actingAs($u)->postJson(route('console.shop-keyword.check-html', $a), [
            'item_id' => $item->id, 'html' => $this->htmlWith,
        ])->assertOk();

        $a->refresh();
        $this->assertSame('종근당 비타민c 고함량 1000 리포좀 분말 스틱 고용량', $a->product_title);
        $this->assertSame('종근당', $a->mall_name);
        $this->assertSame(19900, $a->product_price);
    }

    public function test_supplement_merges_together_and_competitor_tokens(): void
    {
        $u = User::factory()->create();
        $a = ShopKeywordAnalysis::create([
            'user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5,
            'product_url' => 'https://smartstore.naver.com/x/products/111', 'product_id' => '111', 'status' => 'done',
        ]);

        $r = $this->actingAs($u)->postJson(route('console.shop-keyword.supplement', $a), [
            'mshop_html' => $this->htmlWith,
            'related' => ['비타민c 효능', '비타민c 하루권장량', '메가도스'],
        ])->assertOk();
        $this->assertGreaterThan(0, (int) $r->json('data.added'));
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'together', 'keyword' => '비타민c 효능']);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'competitor_brand', 'keyword' => '고려은단']);

        // 반복 병합 방지(10분 가드) — 두 번째 호출은 skipped
        $r2 = $this->actingAs($u)->postJson(route('console.shop-keyword.supplement', $a), ['related' => ['다른키워드']])->assertOk();
        $this->assertTrue((bool) $r2->json('data.skipped'));
    }

    public function test_product_info_refresh_backfills_and_adds_tokens(): void
    {
        $u = User::factory()->create();
        \App\Models\ShopProductInfo::create([
            'user_id' => $u->id, 'channel_product_id' => '555',
            'title' => '고려은단 비타민C 1000 600정', 'brand' => '고려은단', 'price' => 50000,
            'seller_tags' => ['메가도스비타민c'], 'collected_at' => now(),
        ]);
        $a = ShopKeywordAnalysis::create([
            'user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5,
            'product_url' => 'https://brand.naver.com/koreaeundan/products/555', 'product_id' => '555', 'status' => 'done',
        ]);

        $r = $this->actingAs($u)->postJson(route('console.shop-keyword.product-info', $a))->assertOk();
        $this->assertTrue((bool) $r->json('data.found'));
        $a->refresh();
        $this->assertSame('고려은단 비타민C 1000 600정', $a->product_title);
        $this->assertSame('고려은단', $a->mall_name);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'seller_tag', 'keyword' => '메가도스비타민c']);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'title', 'keyword' => '600정']);
    }

    public function test_product_info_accepts_extension_payload_directly(): void
    {
        // 확장이 payload 를 페이지로 돌려주는 경로 — ShopProductInfo 없이도 저장·백필된다
        $u = User::factory()->create();
        $a = ShopKeywordAnalysis::create([
            'user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5,
            'product_url' => 'https://brand.naver.com/koreaeundan/products/777', 'product_id' => '777', 'status' => 'done',
        ]);

        $r = $this->actingAs($u)->postJson(route('console.shop-keyword.product-info', $a), [
            'info' => [
                'channel_product_id' => '777',
                'title' => '고려은단 비타민C 1000 이지 120정',
                'brand' => '고려은단',
                'price' => 25000,
                'seller_tags' => ['이지비타민'],
            ],
        ])->assertOk();
        $this->assertTrue((bool) $r->json('data.found'));
        $this->assertDatabaseHas('shop_product_infos', ['user_id' => $u->id, 'channel_product_id' => '777']);
        $this->assertSame('고려은단 비타민C 1000 이지 120정', $a->fresh()->product_title);

        // 새 재료(제목)가 오면 조합이 자동 재편성되고, 제목 구절이 그대로 조합에 들어간다
        $this->assertTrue((bool) $r->json('data.regenerated'));
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'combo_tag' => 'phrase', 'keyword' => '고려은단 비타민C 1000']);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'combo_tag' => 'tag', 'keyword' => '이지비타민']);

        // 다른 상품 payload 는 무시(오염 방지)
        $b = ShopKeywordAnalysis::create([
            'user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5,
            'product_url' => 'https://brand.naver.com/x/products/888', 'product_id' => '888', 'status' => 'done',
        ]);
        $this->actingAs($u)->postJson(route('console.shop-keyword.product-info', $b), [
            'info' => ['channel_product_id' => '777', 'title' => '엉뚱한 상품'],
        ])->assertOk();
        $this->assertNull($b->fresh()->product_title);
    }

    public function test_low_yield_tiers_removed_from_generation(): void
    {
        // 어미(suffix)·속성(attr) 티어는 패턴 실측(1/20·0/298)으로 생성에서 제거됐다
        $a = $this->store(User::factory()->create());
        $this->assertDatabaseMissing('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '비타민c 추천']);
        $this->assertSame(0, $a->allCombos()->whereIn('combo_tag', ['attr', 'suffix'])->count());
        // 속성·경쟁브랜드는 참고 토큰으로는 남는다
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'competitor_brand']);
    }

    public function test_regenerate_skips_pattern_failing_tags(): void
    {
        $u = User::factory()->create();
        $a = ShopKeywordAnalysis::create([
            'user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5,
            'product_url' => 'https://smartstore.naver.com/x/products/111', 'product_id' => '111',
            'product_title' => '종근당 비타민c 고함량 리포좀', 'mall_name' => '종근당', 'status' => 'done',
        ]);
        foreach ([['title', '고함량'], ['title', '리포좀'], ['seller_tag', '메가도스비타민c']] as [$src, $kw]) {
            ShopKeywordAnalysisItem::create(['analysis_id' => $a->id, 'kind' => 'token', 'source' => $src, 'keyword' => $kw]);
        }
        // 'tag' 유형을 12건 확인했는데 전부 미노출 → 패턴상 실패 유형으로 학습된다
        for ($i = 0; $i < 12; $i++) {
            ShopKeywordAnalysisItem::create(['analysis_id' => $a->id, 'kind' => 'combo', 'source' => 'combo',
                'keyword' => '실패태그'.$i, 'combo_tag' => 'tag', 'rank' => 0, 'checked_at' => now()]);
        }

        $this->actingAs($u)->post(route('console.shop-keyword.regenerate', $a))->assertOk();

        $this->assertGreaterThan(0, $a->combos()->whereNull('rank')->count());                                  // 새 조합은 생성
        $this->assertSame(0, $a->combos()->whereNull('rank')->where('combo_tag', 'tag')->count());              // 실패 유형은 스킵
        $this->assertGreaterThan(0, $a->combos()->whereNull('rank')->where('combo_tag', 'title')->count());     // 제목 유형은 유지
    }

    public function test_tail_combos_title_and_brand_with_attr_suffix(): void
    {
        // 속성·어미는 단독이 아니라 제목/브랜드 조합에 1개씩 덧붙는 tail 로만 생성된다(사용자 요청 4종)
        $u = User::factory()->create();
        $a = ShopKeywordAnalysis::create([
            'user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5,
            'product_url' => 'https://smartstore.naver.com/x/products/111', 'product_id' => '111',
            'product_title' => '종근당 비타민c 고함량 리포좀', 'mall_name' => '종근당', 'status' => 'done',
        ]);
        foreach ([['title', '고함량'], ['attribute', '분말'], ['suffix', '추천']] as [$src, $kw]) {
            ShopKeywordAnalysisItem::create(['analysis_id' => $a->id, 'kind' => 'token', 'source' => $src, 'keyword' => $kw]);
        }

        $this->actingAs($u)->post(route('console.shop-keyword.regenerate', $a))->assertOk();

        foreach ([
            ['비타민c 고함량 분말', 'title_attr'],
            ['비타민c 고함량 추천', 'title_suffix'],
            ['종근당 비타민c 분말', 'brand_attr'],
            ['종근당 비타민c 추천', 'brand_suffix'],
        ] as [$kw, $tag]) {
            $this->assertDatabaseHas('shop_keyword_analysis_items',
                ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => $kw, 'combo_tag' => $tag]);
        }
        // 실패 단독형(핵심+어미 2단어)은 여전히 만들지 않는다
        $this->assertDatabaseMissing('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'kind' => 'combo', 'keyword' => '비타민c 추천']);
    }

    public function test_short_links_assign_exposed_keywords_round_robin_and_rotate_in_order(): void
    {
        $u = User::factory()->create();
        $a = ShopKeywordAnalysis::create([
            'user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5,
            'product_url' => 'https://smartstore.naver.com/x/products/111', 'status' => 'done',
        ]);
        foreach ([
            ['비타민c 고함량', 1],
            ['비타민c 리포좀', 2],
            ['비타민c 1000', 3],
            ['비타민c 분말', 4],
            ['비타민c 스틱', 5],
        ] as [$kw, $rank]) {
            ShopKeywordAnalysisItem::create([
                'analysis_id' => $a->id, 'kind' => 'combo', 'source' => 'combo',
                'keyword' => $kw, 'rank' => $rank, 'checked_at' => now()->addSeconds($rank),
            ]);
        }
        ShopKeywordAnalysisItem::create(['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'together', 'keyword' => '비타민c 효능']);
        ShopKeywordAnalysisItem::create(['analysis_id' => $a->id, 'kind' => 'token', 'source' => 'keyword_rec', 'keyword' => '비타민c 하루권장량']);

        $this->actingAs($u)
            ->post(route('console.shop-keyword.short-links.store', $a), ['group_count' => 2])
            ->assertRedirect(route('console.shop-keyword.show', $a));

        $links = ShopKeywordShortLink::where('analysis_id', $a->id)->orderBy('group_no')->get();
        $this->assertCount(2, $links);
        $this->assertSame(['비타민c 고함량', '비타민c 1000', '비타민c 스틱'], $links[0]->keywords);
        $this->assertSame(['비타민c 리포좀', '비타민c 분말'], $links[1]->keywords);

        $first = $this->shortRedirectParams($links[0]->token);
        $second = $this->shortRedirectParams($links[0]->token);
        $third = $this->shortRedirectParams($links[1]->token);

        $this->assertSame('비타민c 고함량', $first['query']);
        $this->assertSame('비타민c 1000', $second['query']);
        $this->assertSame('비타민c 리포좀', $third['query']);
        $this->assertContains($first['acq'], ['비타민c 효능', '비타민c 하루권장량']);
        $this->assertContains($second['acq'], ['비타민c 효능', '비타민c 하루권장량']);
        $this->assertSame('mtp_sug.top', $first['sm']);
        $this->assertSame('m', $first['where']);
        $this->assertSame('9', $first['acr']);
        $this->assertSame('0', $first['qdt']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $first['ackey']);
        $this->assertSame(2, $links[0]->fresh()->hit_count);
        $this->assertSame(1, $links[1]->fresh()->hit_count);
    }

    public function test_short_link_count_cannot_exceed_exposed_keyword_count(): void
    {
        $u = User::factory()->create();
        $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5, 'status' => 'done']);
        ShopKeywordAnalysisItem::create([
            'analysis_id' => $a->id, 'kind' => 'combo', 'source' => 'combo',
            'keyword' => '비타민c 고함량', 'rank' => 1, 'checked_at' => now(),
        ]);

        $this->actingAs($u)
            ->from(route('console.shop-keyword.show', $a))
            ->post(route('console.shop-keyword.short-links.store', $a), ['group_count' => 2])
            ->assertRedirect(route('console.shop-keyword.show', $a))
            ->assertSessionHasErrors('group_count');

        $this->assertSame(0, ShopKeywordShortLink::where('analysis_id', $a->id)->count());
    }

    public function test_recheck_exposed_resets_only_exposed(): void
    {
        // 광고 판별 개선(슈퍼적립) 전에 오가닉으로 오판된 노출 기록 정정 — 노출분만 미확인으로 리셋
        $u = User::factory()->create();
        $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5, 'status' => 'done', 'combo_count' => 3]);
        $mk = fn ($kw, $rank, $ad = false) => ShopKeywordAnalysisItem::create(['analysis_id' => $a->id, 'kind' => 'combo',
            'source' => 'combo', 'keyword' => $kw, 'rank' => $rank, 'ad_exposed' => $ad, 'checked_at' => now()]);
        $exposedItem = $mk('비타민c 고함량', 1, true);
        $outItem = $mk('비타민c 분말', 0);
        $farItem = $mk('비타민c 고용량', 9);

        $r = $this->actingAs($u)->postJson(route('console.shop-keyword.recheck-exposed', $a))->assertOk();

        $this->assertSame(1, (int) $r->json('data.reset'));
        $this->assertNull($exposedItem->fresh()->rank);            // 노출분만 리셋
        $this->assertFalse((bool) $exposedItem->fresh()->ad_exposed);
        $this->assertSame(0, $outItem->fresh()->rank);             // 미노출 유지
        $this->assertSame(9, $farItem->fresh()->rank);             // 순위 밖 유지
        $this->assertSame('checking', $a->fresh()->status);

        $other = User::factory()->create();
        $this->actingAs($other)->postJson(route('console.shop-keyword.recheck-exposed', $a))->assertForbidden();
    }

    public function test_ownership_enforced(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $a = ShopKeywordAnalysis::create(['user_id' => $owner->id, 'core_keyword' => '비타민c', 'threshold' => 5, 'status' => 'done']);
        $this->actingAs($other)->get(route('console.shop-keyword.show', $a))->assertForbidden();
        $this->actingAs($other)->post(route('console.shop-keyword.regenerate', $a))->assertForbidden();
        $this->actingAs($other)->get(route('console.shop-keyword.pending', $a))->assertForbidden();
        $this->actingAs($other)->postJson(route('console.shop-keyword.check-html', $a), ['item_id' => 1])->assertForbidden();
        $this->actingAs($other)->postJson(route('console.shop-keyword.supplement', $a))->assertForbidden();
        $this->actingAs($other)->post(route('console.shop-keyword.short-links.store', $a), ['group_count' => 1])->assertForbidden();
    }
}
