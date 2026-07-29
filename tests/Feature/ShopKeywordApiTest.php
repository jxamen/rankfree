<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 쇼핑 유입키워드 외부 API v1 (scope: shop_keyword) — 2026-07-26.
 * 분석 생성(추출·조합) → 순위 확인(check_method=api 는 확장 없이 서버 완결) → Short URL 그룹 생성.
 */
class ShopKeywordApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rankfree.shopping.exposure.max_combos' => 40,
            'rankfree.shopping.exposure.top' => 5,
            'rankfree.shopping.exposure.batch_size' => 500,
            'rankfree.shopping.exposure.suffixes' => ['추천', '인기'],
            'rankfree.searchad.api_key' => '', 'rankfree.searchad.accounts' => [],
            // api 방식은 openapi 키가 있어야 동작한다(없으면 no_api_keys → blocked)
            'rankfree.shopping.api_keys' => [['id' => 'k1', 'secret' => 's1']],
            'rankfree.secondary_domains' => ['s1.test', 's2.test'],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'ac.search.naver')) {
                return Http::response(['items' => [[['비타민c1000'], ['비타민c 고함량']]]], 200);
            }
            // 내 상품(111)은 '고함량' 이 든 조합에서만 노출 → 노출/미노출이 섞이게
            if (str_contains($url, 'openapi.naver.com/v1/search/shop.json')) {
                $q = (string) ($request->data()['query'] ?? '');
                $mine = ['productId' => '111', 'title' => '종근당 비타민c 고함량 스틱', 'mallName' => '종근당',
                    'link' => 'https://smartstore.naver.com/x/products/111', 'lprice' => '19900', 'image' => ''];
                $other = ['productId' => '222', 'title' => '고려은단 비타민c', 'mallName' => '고려은단',
                    'link' => 'https://smartstore.naver.com/y/products/222', 'lprice' => '25000', 'image' => ''];
                $items = str_contains(mb_strtolower($q), '고함량') ? [$other, $mine] : [$other];

                return Http::response(['total' => count($items), 'items' => $items], 200);
            }

            return Http::response('', 200);
        });

        // 회원별 API 권한(2026-07-26) — 쇼핑 유입키워드 기능을 허용한 계정(rank 는 scope 차단 테스트용)
        $this->user = User::create(['name' => '외부연동', 'email' => 'sk-api@rankfree.kr', 'password' => 'secret1234',
            'api_scopes' => ['shop_keyword', 'rank']]);
        [, $plain] = ApiKey::issue($this->user, '테스트', ['shop_keyword'], null, null, null);
        $this->key = $plain;
    }

    private function api(string $method, string $uri, array $data = [], ?string $key = null)
    {
        return $this->withHeader('Authorization', 'Bearer '.($key ?? $this->key))->json($method, $uri, $data);
    }

    /** 분석 생성 payload — 제목을 함께 넘겨 조합 재료(확장 수집분)를 대신한다. */
    private function createPayload(array $override = []): array
    {
        return array_merge([
            'core_keyword' => '비타민c',
            'product' => 'https://smartstore.naver.com/x/products/111',
            'threshold' => 5,
            'product_info' => [
                'title' => '종근당 비타민c 고함량 스틱',
                'brand' => '종근당',
                'seller_tags' => ['고함량비타민'],
            ],
        ], $override);
    }

    public function test_scope_required(): void
    {
        [, $other] = ApiKey::issue($this->user, '스코프없음', ['rank'], null, null, null);

        $this->api('POST', '/api/v1/shop-keywords', $this->createPayload(), $other)->assertStatus(403);
    }

    /**
     * 확장이 순위 확인을 끝낸 상태를 만든다 — 서버는 API 분석을 자동 확인하지 않으므로(2026-07-29)
     * 테스트가 확장 역할을 대신한다. 앞 3개는 노출(1~3위), 나머지는 순위 밖(0).
     */
    private function completeCheckLikeExtension(ShopKeywordAnalysis $analysis): void
    {
        $combos = $analysis->combos()->orderBy('id')->get();
        foreach ($combos as $i => $c) {
            $c->forceFill(['rank' => $i < 3 ? $i + 1 : 0, 'checked_at' => now()])->save();
        }
        $analysis->forceFill([
            'checked_count' => $combos->count(),
            'exposed_count' => min(3, $combos->count()),
            'status' => 'done',
        ])->save();
    }

    /** 생성 — 조합이 만들어지고 진행 상태를 함께 준다. 소유자는 API 키 회원. */
    public function test_creates_analysis_with_progress(): void
    {
        $res = $this->api('POST', '/api/v1/shop-keywords', $this->createPayload())->assertStatus(201);

        $res->assertJsonPath('analysis.core_keyword', '비타민c')
            // API 분석은 확장이 통합검색 기준으로 확인한다 — check_method 는 search 로 고정
            ->assertJsonPath('analysis.check_method', 'search')
            ->assertJsonPath('analysis.threshold', 5);

        $id = $res->json('analysis.id');
        $analysis = ShopKeywordAnalysis::find($id);
        $this->assertSame($this->user->id, $analysis->user_id);
        $this->assertGreaterThan(0, (int) $res->json('analysis.progress.total'));

        // 제목을 넘겼으므로 제목 단어 기반 조합이 만들어진다(확장 수집분 없이도)
        $this->assertTrue($analysis->combos()->where('keyword', 'like', '%고함량%')->exists());
    }

    /**
     * 서버는 순위를 자동으로 돌리지 않는다(2026-07-29) — 생성만으로는 확인이 진행되지 않고,
     * 요청자 계정의 확장이 확인을 마치면 노출 키워드가 조회된다.
     */
    public function test_server_does_not_auto_check_and_extension_completes(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $id = $this->api('POST', '/api/v1/shop-keywords', $this->createPayload())->json('analysis.id');
        $analysis = ShopKeywordAnalysis::find($id);

        // 서버 자동 확인 잡이 뜨지 않는다
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        $this->assertGreaterThan(0, $analysis->combos()->whereNull('rank')->count(), '확인 전이라 미확인 조합이 남아 있어야 한다');
        $this->assertSame('api', $analysis->created_via);

        // 확장이 확인을 끝내면 노출 키워드가 나온다
        $this->completeCheckLikeExtension($analysis);

        $show = $this->api('GET', "/api/v1/shop-keywords/{$id}")->assertOk();
        $this->assertNotEmpty($show->json('exposed_keywords'));
        $this->assertSame(0, $show->json('analysis.progress.remaining'));
    }

    /** Short URL — 노출 키워드를 그룹 수만큼 라운드로빈 분배(화면과 동일 규칙). */
    public function test_creates_short_link_groups(): void
    {
        $id = $this->api('POST', '/api/v1/shop-keywords', $this->createPayload())->json('analysis.id');
        $this->completeCheckLikeExtension(ShopKeywordAnalysis::find($id));   // 확장이 확인을 끝낸 상태

        $exposed = $this->api('GET', "/api/v1/shop-keywords/{$id}")->json('exposed_keywords');
        $this->assertGreaterThanOrEqual(2, count($exposed), '노출 키워드가 2개 이상이어야 그룹 분배를 검증할 수 있다');

        $res = $this->api('POST', "/api/v1/shop-keywords/{$id}/short-links", ['group_count' => 2])->assertStatus(201);

        $links = $res->json('short_links');
        $this->assertCount(2, $links);
        $this->assertSame([1, 2], array_column($links, 'group_no'));
        // 라운드로빈 — 노출 키워드가 그룹에 나뉘어 전부 배정된다
        $all = array_merge(...array_column($links, 'keywords'));
        $this->assertSame(count($exposed), count($all));
        foreach ($links as $l) {
            $this->assertStringContainsString('/s/', $l['url']);
        }
    }

    /** Short URL 목록 조회 + 재배정 — URL(토큰)은 그대로 두고 키워드만 다시 나눈다. */
    public function test_lists_and_reassigns_short_links(): void
    {
        $id = $this->api('POST', '/api/v1/shop-keywords', $this->createPayload())->json('analysis.id');
        $this->completeCheckLikeExtension(ShopKeywordAnalysis::find($id));   // 확장이 확인을 끝낸 상태

        $created = $this->api('POST', "/api/v1/shop-keywords/{$id}/short-links", ['group_count' => 2])->json('short_links');
        $urls = array_column($created, 'url');

        // 목록 조회 — 발주 시스템이 그대로 가져다 쓴다
        $listed = $this->api('GET', "/api/v1/shop-keywords/{$id}/short-links")->assertOk()->json('short_links');
        $this->assertSame($urls, array_column($listed, 'url'));
        $this->assertArrayHasKey('hit_count', $listed[0]);

        // 재배정 — 주소는 유지되고 키워드 배정만 다시 계산된다
        $re = $this->api('POST', "/api/v1/shop-keywords/{$id}/short-links/reassign")->assertOk()->json('short_links');
        $this->assertSame($urls, array_column($re, 'url'), '재배정은 이미 배포한 URL 을 바꾸지 않는다');
        $exposed = $this->api('GET', "/api/v1/shop-keywords/{$id}")->json('exposed_keywords');
        $this->assertSame(count($exposed), count(array_merge(...array_column($re, 'keywords'))));
    }

    /** 링크가 없으면 재배정할 수 없다. */
    public function test_reassign_requires_existing_links(): void
    {
        $id = $this->api('POST', '/api/v1/shop-keywords', $this->createPayload())->json('analysis.id');

        $this->api('POST', "/api/v1/shop-keywords/{$id}/short-links/reassign")
            ->assertStatus(422)
            ->assertJsonPath('field', 'short_links');
    }

    /** 노출 키워드가 없으면 Short URL 을 만들 수 없다(422 + field). 검색결과에 없는 상품(999)으로 노출 0 을 만든다. */
    public function test_short_links_require_exposed_keywords(): void
    {
        $id = $this->api('POST', '/api/v1/shop-keywords', $this->createPayload([
            'product' => 'https://smartstore.naver.com/x/products/999',
        ]))->json('analysis.id');

        $this->api('POST', "/api/v1/shop-keywords/{$id}/short-links", ['group_count' => 1])
            ->assertStatus(422)
            ->assertJsonPath('field', 'group_count');
    }

    /** 남의 분석은 조회·생성 모두 차단된다. */
    public function test_owner_isolation(): void
    {
        $other = User::create(['name' => '남', 'email' => 'other-sk@rankfree.kr', 'password' => 'secret1234']);
        $mine = ShopKeywordAnalysis::create([
            'user_id' => $other->id, 'core_keyword' => '남의키워드', 'product_url' => 'https://smartstore.naver.com/z/products/999',
            'product_id' => '999', 'threshold' => 5, 'status' => 'done', 'check_method' => 'api',
        ]);

        $this->api('GET', "/api/v1/shop-keywords/{$mine->id}")->assertStatus(403);
        $this->api('POST', "/api/v1/shop-keywords/{$mine->id}/short-links", ['group_count' => 1])->assertStatus(403);
    }

    /** 목록은 내 분석만. */
    public function test_index_lists_only_mine(): void
    {
        $this->api('POST', '/api/v1/shop-keywords', $this->createPayload());
        $other = User::create(['name' => '남', 'email' => 'other2-sk@rankfree.kr', 'password' => 'secret1234']);
        ShopKeywordAnalysis::create([
            'user_id' => $other->id, 'core_keyword' => '남의것', 'product_url' => 'x', 'threshold' => 5,
            'status' => 'done', 'check_method' => 'api',
        ]);

        $res = $this->api('GET', '/api/v1/shop-keywords')->assertOk();
        $this->assertSame(1, $res->json('total'));
        $this->assertSame('비타민c', $res->json('analyses.0.core_keyword'));
    }
}
