<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KeywordProductsOptionalTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $scopes = ['keyword', 'keyword_products']): User
    {
        return User::factory()->create(['api_scopes' => $scopes]);
    }

    private function rows(): void
    {
        foreach (['p1', 'p2', 'p3'] as $key) {
            DB::table('shop_products')->insert(['product_key' => $key, 'title' => $key]);
        }
        foreach ([
            ['B', 'p1', 2, 202610, '2026-10-26 10:00:00'],
            ['A', 'p2', 5, 202610, '2026-10-26 10:00:00'],
            ['A', 'p1', 1, 202610, '2026-10-26 10:00:00'],
            ['Z', 'p3', 9, 202610, '2026-10-27 10:00:00'],
            ['old', 'p1', 1, 202609, '2026-09-27 10:00:00'],
            ['orphan', 'missing', 1, 202610, '2026-10-28 10:00:00'],
            ['0', 'p1', 1, 202608, '2026-08-27 10:00:00'],
        ] as [$keyword, $key, $rank, $month, $at]) {
            DB::table('keyword_shop_ranks')->insert(['keyword' => $keyword, 'product_key' => $key, 'rnk' => $rank, 'collected_month' => $month, 'collected_at' => $at]);
        }
    }

    public function test_all_keywords_latest_month_order_exact_join_total_and_pages(): void
    {
        $this->rows();
        [, $plain] = ApiKey::issue($this->member(), 'test', ['keyword_products'], null, null, null);
        $headers = ['Authorization' => 'Bearer '.$plain];
        foreach (['', '&keyword=', '&keyword=%20%20'] as $suffix) {
            $response = $this->getJson('/api/v1/keyword/products?limit=2'.$suffix, $headers)->assertOk()
                ->assertJsonPath('data.keyword', null)->assertJsonPath('data.month', 202610)
                ->assertJsonPath('data.available_months', [202610, 202609, 202608])
                ->assertJsonPath('data.collected_at', '2026-10-27 10:00:00')
                ->assertJsonPath('data.pagination.total', 4)->assertJsonPath('data.pagination.last_page', 2)
                ->assertJsonPath('data.pagination.has_more', true);
            $this->assertSame(['Z', 'A'], array_column($response->json('data.items'), 'keyword'));
            $this->assertSame([9, 1], array_column($response->json('data.items'), 'rnk'));
        }
        $this->getJson('/api/v1/keyword/products?limit=2&page=2', $headers)->assertOk()
            ->assertJsonPath('data.items.0.keyword', 'A')->assertJsonPath('data.items.0.rnk', 5)
            ->assertJsonPath('data.items.1.keyword', 'B')->assertJsonPath('data.pagination.has_more', false);
        $this->getJson('/api/v1/keyword/products?page=99', $headers)->assertOk()
            ->assertJsonPath('data.items', [])->assertJsonPath('data.status', 'collected');
        $this->getJson('/api/v1/keyword/products?month=202609', $headers)->assertOk()
            ->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.keyword', 'old');
        $this->getJson('/api/v1/keyword/products?keyword=0', $headers)->assertOk()
            ->assertJsonPath('data.keyword', '0')->assertJsonPath('data.month', 202608);
        $this->getJson('/api/v1/keyword/products?month=202601', $headers)->assertOk()
            ->assertJsonPath('data.status', 'not_collected')->assertJsonPath('data.collected_at', null);
    }

    public function test_empty_database_and_maximum_limit(): void
    {
        [, $plain] = ApiKey::issue($this->member(), 'test', ['keyword_products'], null, null, null);
        $h = ['Authorization' => 'Bearer '.$plain];
        $this->getJson('/api/v1/keyword/products', $h)->assertOk()->assertJsonPath('data.month', null)
            ->assertJsonPath('data.items', [])->assertJsonPath('data.pagination.total', 0);
        DB::table('shop_products')->insert(['product_key' => 'p', 'title' => 'p']);
        for ($i = 1; $i <= 101; $i++) {
            DB::table('keyword_shop_ranks')->insert(['keyword' => 'A', 'product_key' => 'p', 'rnk' => $i, 'collected_month' => 202610]);
        }
        $this->getJson('/api/v1/keyword/products?limit=100', $h)->assertOk()->assertJsonCount(100, 'data.items')
            ->assertJsonPath('data.pagination.total', 101)->assertJsonPath('data.pagination.has_more', true);
        $this->getJson('/api/v1/keyword/products?limit=100&page=2', $h)->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_console_create_edit_and_member_revocation_use_independent_scope(): void
    {
        $user = $this->member();
        $this->actingAs($user)->get('/console/api-keys')->assertOk()->assertSee('value="keyword_products"', false)->assertSee('수집 상품 목록');
        $this->post('/console/api-keys', ['name' => 'Products', 'scopes' => ['keyword', 'keyword_products']])
            ->assertRedirect()->assertSessionHas('newApiKey');
        $key = $user->apiKeys()->firstOrFail();
        $h = ['Authorization' => 'Bearer '.$key->plainKey()];
        $this->getJson('/api/v1/keyword/products', $h)->assertOk();
        $this->patch(route('console.api-keys.update-scopes', $key), ['scopes' => ['keyword']])->assertSessionDoesntHaveErrors();
        $this->getJson('/api/v1/keyword/products', $h)->assertForbidden();
        $this->patch(route('console.api-keys.update-scopes', $key), ['scopes' => ['keyword_products']])->assertSessionDoesntHaveErrors();
        $this->getJson('/api/v1/keyword/products', $h)->assertOk();
        $this->patch(route('console.api-keys.update-scopes', $key), [])->assertSessionDoesntHaveErrors();
        $this->getJson('/api/v1/keyword/products', $h)->assertForbidden();
        $this->patch(route('console.api-keys.update-scopes', $key), ['scopes' => ['keyword_products']])->assertSessionDoesntHaveErrors();
        $this->getJson('/api/v1/keyword/products', $h)->assertOk();
        $user->update(['api_scopes' => ['keyword']]);
        $this->getJson('/api/v1/keyword/products', $h)->assertForbidden();
        $this->patch(route('console.api-keys.update-scopes', $key), ['scopes' => ['keyword_products']])
            ->assertSessionHasErrors('scopes.0', null, 'scopes_'.$key->id);
        $admin = User::factory()->create(['role' => 'super']);
        $this->actingAs($admin)->get('/admin/members')->assertOk()->assertSee('수집 상품 목록')->assertSee('value="keyword_products"', false);
    }

    public function test_backfill_preserves_existing_access_and_does_not_regrant_revoked_scope(): void
    {
        $old = $this->member(['keyword', 'rank']);
        $no = $this->member(['rank']);
        [$key, $plain] = ApiKey::issue($old, 'old', ['keyword'], null, 100, null);
        [$other] = ApiKey::issue($no, 'other', ['rank'], null, null, null);
        $this->artisan('api-keys:backfill-keyword-products')->assertSuccessful();
        $this->assertSame(['keyword', 'rank', 'keyword_products'], $old->fresh()->api_scopes);
        $this->assertSame(['keyword', 'keyword_products'], $key->fresh()->scopes);
        $this->assertSame($plain, $key->fresh()->plainKey());
        $this->assertSame(100, $key->fresh()->daily_limit);
        $this->assertSame(['rank'], $no->fresh()->api_scopes);
        $this->assertSame(['rank'], $other->fresh()->scopes);
        $this->getJson('/api/v1/keyword/products', ['Authorization' => 'Bearer '.$plain])->assertOk();
        $old->refresh()->update(['api_scopes' => ['keyword']]);
        $key->refresh()->update(['scopes' => ['keyword']]);
        $this->artisan('api-keys:backfill-keyword-products')->assertSuccessful();
        $this->assertSame(['keyword'], $old->fresh()->api_scopes);
        $this->assertSame(['keyword'], $key->fresh()->scopes);
        $this->getJson('/api/v1/keyword/products', ['Authorization' => 'Bearer '.$plain])->assertForbidden();
    }
}
