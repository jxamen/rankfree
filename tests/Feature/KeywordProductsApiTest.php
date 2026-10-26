<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopSerpStore;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KeywordProductsApiTest extends TestCase
{
    use RefreshDatabase;

    private function headers(array $scopes = ['keyword'], array $userScopes = ['keyword'], ?int $limit = null): array
    {
        $user = User::factory()->create(['api_scopes' => $userScopes]);
        [, $plain] = ApiKey::issue($user, 'Products test', $scopes, null, $limit, null);

        return ['Authorization' => 'Bearer '.$plain];
    }

    private function seedProducts(): void
    {
        foreach ([1, 2, 3] as $i) {
            DB::table('shop_products')->insert([
                'product_key' => 'pd'.$i, 'title' => '상품 '.$i, 'price' => 10000 * $i,
                'mall_name' => '예시몰', 'link' => 'https://smartstore.naver.com/example/products/'.$i,
                'talk_id' => 'example',
            ]);
            DB::table('keyword_shop_ranks')->insert([
                'keyword' => '경량패딩', 'product_key' => 'pd'.$i, 'rnk' => 4 - $i,
                'is_ad' => $i === 3, 'collected_month' => 202610, 'collected_at' => '2026-10-26 17:00:00',
            ]);
        }
        DB::table('keyword_shop_ranks')->insert([
            'keyword' => '경량패딩', 'product_key' => 'pd1', 'rnk' => 8,
            'collected_month' => 202609, 'collected_at' => '2026-09-20 12:00:00',
        ]);
    }

    public function test_latest_products_match_admin_store_and_pagination(): void
    {
        $this->seedProducts();
        $headers = $this->headers();
        $expected = app(ShopSerpStore::class)->items('경량패딩')->take(2)->values();
        $response = $this->getJson('/api/v1/keyword/products?keyword='.urlencode(' 경량패딩 ').'&limit=2', $headers)
            ->assertOk()->assertJsonPath('data.month', 202610)
            ->assertJsonPath('data.status', 'collected')->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.has_more', true)->assertJsonPath('data.available_months', [202610, 202609]);
        $this->assertSame(json_decode($expected->toJson(), true), $response->json('data.items'));
        $this->getJson('/api/v1/keyword/products?keyword='.urlencode('경량패딩').'&limit=2&page=2', $headers)
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.rnk', 3)
            ->assertJsonPath('data.collected_at', '2026-10-26 17:00:00')->assertJsonPath('data.pagination.has_more', false);
        $this->getJson('/api/v1/keyword/products?keyword='.urlencode('경량패딩').'&page=99', $headers)
            ->assertOk()->assertJsonPath('data.items', [])->assertJsonPath('data.status', 'collected')
            ->assertJsonPath('data.collected_at', '2026-10-26 17:00:00');
    }

    public function test_selected_month_and_missing_month(): void
    {
        $this->seedProducts();
        $headers = $this->headers();
        $this->getJson('/api/v1/keyword/products?keyword='.urlencode('경량패딩').'&month=202609', $headers)
            ->assertOk()->assertJsonPath('data.month', 202609)->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.rnk', 8)->assertJsonPath('data.collected_at', '2026-09-20 12:00:00');
        $this->getJson('/api/v1/keyword/products?keyword='.urlencode('경량패딩').'&month=202608', $headers)
            ->assertOk()->assertJsonPath('data.status', 'not_collected')->assertJsonPath('data.items', [])
            ->assertJsonPath('data.collected_at', null);
    }

    public function test_missing_and_invalid_key_are_unauthorized(): void
    {
        $this->getJson('/api/v1/keyword/products?keyword=test')->assertUnauthorized();
        $this->getJson('/api/v1/keyword/products?keyword=test', ['X-API-KEY' => 'invalid'])->assertUnauthorized();
    }

    public function test_missing_key_scope_is_forbidden(): void
    {
        $this->getJson('/api/v1/keyword/products?keyword=test', $this->headers(['rank']))->assertForbidden();
    }

    public function test_revoked_member_scope_is_forbidden(): void
    {
        $this->getJson('/api/v1/keyword/products?keyword=test', $this->headers(['keyword'], []))->assertForbidden();
    }

    public function test_unknown_keyword_returns_empty_success(): void
    {
        $this->getJson('/api/v1/keyword/products?keyword=unknown', $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'not_collected')->assertJsonPath('data.items', [])
            ->assertJsonPath('data.month', null)->assertJsonPath('data.available_months', [])
            ->assertJsonPath('data.collected_at', null)->assertJsonPath('data.pagination.total', 0);
    }

    public function test_invalid_parameters_return_validation_errors(): void
    {
        $headers = $this->headers();
        foreach (['', 'keyword=', 'keyword[]=test', 'keyword=test&month=202613', 'keyword=test&month=20260230',
            'keyword=test&page=0', 'keyword=test&limit=101', 'keyword=test&limit=0'] as $query) {
            $this->getJson('/api/v1/keyword/products?'.$query, $headers)->assertUnprocessable();
        }
    }

    public function test_daily_quota_is_enforced(): void
    {
        $headers = $this->headers(limit: 1);
        $this->getJson('/api/v1/keyword/products?keyword=test', $headers)->assertOk();
        $this->getJson('/api/v1/keyword/products?keyword=test', $headers)->assertStatus(429);
    }

    public function test_rate_limit_is_enforced(): void
    {
        $headers = $this->headers();
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/keyword/products?keyword=test', $headers)->assertOk();
        }
        $this->getJson('/api/v1/keyword/products?keyword=test', $headers)->assertStatus(429);
    }
}
