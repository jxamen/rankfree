<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ShopSellerInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreBizInfoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
    }

    private const URL = '/api/v1/stores/bizinfo';

    private function headers(array $scopes = ['store_bizinfo'], array $member = ['store_bizinfo'], ?int $limit = null): array
    {
        [, $key] = ApiKey::issue(User::factory()->create(['api_scopes' => $member]), 'Biz info test', $scopes, null, $limit, null);

        return ['Authorization' => 'Bearer '.$key];
    }

    private function product(string $store = 'teststore', string $key = 'pd1'): void
    {
        DB::table('shop_products')->insert(['product_key' => $key, 'store_id' => $store, 'title' => 'Test', 'link' => 'https://smartstore.naver.com/'.$store.'/products/1']);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['store_id' => 'teststore', 'biz_name' => 'Test Company', 'biz_reg_no' => '123-45-67890',
            'representative' => 'Test Representative', 'address' => 'Test Address',
            'observed_at' => now()->subMinute()->toIso8601String(), 'source_url' => 'https://example.com/company', 'public_business_info' => true], $extra);
    }

    public function test_key_and_member_scope_are_required_for_both_methods(): void
    {
        foreach ([[], ['Authorization' => 'Bearer invalid']] as $headers) {
            $this->getJson(self::URL, $headers)->assertUnauthorized();
            $this->postJson(self::URL, $this->payload(), $headers)->assertUnauthorized();
        }
        foreach ([$this->headers(['keyword_products']), $this->headers(member: [])] as $headers) {
            $this->getJson(self::URL, $headers)->assertForbidden();
            $this->postJson(self::URL, $this->payload(), $headers)->assertForbidden();
        }
    }

    public function test_create_duplicate_changed_fields_and_omission_preservation(): void
    {
        $this->product();
        $h = $this->headers();
        $p = $this->payload(['email' => 'office@example.com']);
        $this->postJson(self::URL, $p, $h)->assertOk()->assertJsonPath('data.status', 'updated');
        $before = ShopSellerInfo::first()->getAttributes();
        $this->postJson(self::URL, $p, $h)->assertOk()->assertJsonPath('data.status', 'unchanged')->assertJsonPath('data.changed_fields', []);
        $this->assertSame($before, ShopSellerInfo::first()->getAttributes());
        $this->assertDatabaseCount('shop_seller_infos', 1);
        $this->assertDatabaseCount('shop_seller_info_changes', 1);
        $this->postJson(self::URL, $this->payload(['biz_name' => 'Changed Company', 'observed_at' => now()->toIso8601String()]), $h)
            ->assertOk()->assertJsonPath('data.changed_fields', ['biz_name']);
        $this->assertDatabaseHas('shop_seller_infos', ['email' => 'office@example.com', 'biz_name' => 'Changed Company', 'biz_reg_no' => '1234567890']);
        $audit = json_decode(DB::table('shop_seller_info_changes')->orderByDesc('id')->value('changes'), true);
        $this->assertSame(['biz_name' => ['old' => 'Test Company', 'new' => 'Changed Company']], $audit);
    }

    public function test_existing_real_channel_is_reused_and_stale_payload_cannot_overwrite(): void
    {
        $this->product();
        $h = $this->headers();
        ShopSellerInfo::create(['store_id' => 'teststore', 'channel_uid' => 'real-channel', 'biz_name' => 'Old', 'captured_at' => now()->subDays(50)]);
        $this->postJson(self::URL, $this->payload(), $h)->assertOk()->assertJsonPath('data.status', 'updated');
        $this->assertDatabaseCount('shop_seller_infos', 1);
        $this->assertDatabaseHas('shop_seller_infos', ['channel_uid' => 'real-channel']);
        $this->postJson(self::URL, $this->payload(['observed_at' => now()->subDays(2)->toIso8601String()]), $h)
            ->assertStatus(409)->assertJsonPath('code', 'stale_observation');
        $this->postJson(self::URL, $this->payload(['biz_name' => 'Conflict']), $h)->assertStatus(409);
        $this->assertDatabaseCount('shop_seller_info_changes', 1);
    }

    public function test_list_deduplicates_and_cursor_does_not_skip_after_post(): void
    {
        $this->product('aaa', 'pd1');
        $this->product('aaa', 'pd2');
        $this->product('bbb', 'pd3');
        $h = $this->headers();
        $r = $this->getJson(self::URL.'?limit=1', $h)->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.store_id', 'aaa')->assertJsonPath('data.items.0.reason', 'missing')
            ->assertJsonPath('data.pagination.next_after_store_id', 'aaa');
        $this->postJson(self::URL, $this->payload(['store_id' => 'aaa']), $h)->assertOk();
        $this->getJson(self::URL.'?limit=1&after_store_id=aaa', $h)->assertOk()->assertJsonPath('data.items.0.store_id', 'bbb')
            ->assertJsonPath('data.pagination.has_more', false);
        $this->getJson(self::URL.'?store_id=aaa', $h)->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_stale_incomplete_and_latest_row_selection(): void
    {
        $this->product();
        $h = $this->headers();
        $this->postJson(self::URL, $this->payload(['observed_at' => now()->subDays(40)->toIso8601String()]), $h)->assertOk();
        $this->getJson(self::URL, $h)->assertOk()->assertJsonPath('data.items.0.reason', 'stale');
        $this->getJson(self::URL.'?stale_days=60', $h)->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson(self::URL, $this->payload(), $h)->assertOk()->assertJsonPath('data.status', 'unchanged');
        $this->assertDatabaseCount('shop_seller_info_changes', 1);
        $this->getJson(self::URL, $h)->assertOk()->assertJsonCount(0, 'data.items');
        ShopSellerInfo::create(['store_id' => 'teststore', 'channel_uid' => 'legacy-incomplete', 'captured_at' => now()]);
        $this->getJson(self::URL, $h)->assertOk()->assertJsonPath('data.items.0.reason', 'missing');
    }

    public function test_validation_unknown_store_and_privacy(): void
    {
        $h = $this->headers();
        $this->postJson(self::URL, $this->payload(), $h)->assertNotFound();
        $this->product();
        foreach ([['biz_reg_no' => 'bad'], ['address' => str_repeat('x', 1001)],
            ['email' => 'bad'], ['customer_phone' => '010-1234-5678'], ['customer_phone' => '+82 10 1234 5678'],
            ['source_url' => 'http://example.com'], ['source_url' => 'https://example.com/?token=example'],
            ['source_url' => 'https://user:pass@example.com'], ['public_business_info' => false], ['raw' => ['private' => 'data']],
            ['observed_at' => now()->addDay()->toIso8601String()], ['observed_at' => '2026-01-01'], ['channel_uid' => 'untrusted']] as $bad) {
            $this->postJson(self::URL, $this->payload($bad), $h)->assertUnprocessable();
        }
        foreach (['limit=101', 'stale_days=0', 'after_store_id[]=x', 'store_id=../x'] as $q) {
            $this->getJson(self::URL.'?'.$q, $h)->assertUnprocessable();
        }
        $this->assertDatabaseCount('shop_seller_infos', 0);
        $this->assertDatabaseCount('shop_seller_info_changes', 0);
    }

    public function test_identity_conflict_and_atomic_failure(): void
    {
        $this->product();
        $h = $this->headers();
        DB::table('shop_products')->update(['channel_uid' => 'other-channel']);
        ShopSellerInfo::create(['store_id' => 'other-store', 'channel_uid' => 'other-channel']);
        $this->postJson(self::URL, $this->payload(), $h)->assertStatus(409)->assertJsonPath('code', 'store_identity_conflict');
        $this->assertDatabaseCount('shop_seller_info_changes', 0);
    }

    public function test_daily_limit_and_rate_limit(): void
    {
        $h = $this->headers(limit: 1);
        $this->getJson(self::URL, $h)->assertOk();
        $this->postJson(self::URL, $this->payload(), $h)->assertStatus(429);
        $this->app['cache']->flush();
        $h = $this->headers();
        for ($i = 0; $i < 60; $i++) {
            $this->getJson(self::URL, $h)->assertOk();
        }
        $this->getJson(self::URL, $h)->assertStatus(429);
    }

    public function test_console_checkbox_issue_revoke_and_restore(): void
    {
        $user = User::factory()->create(['api_scopes' => ['store_bizinfo']]);
        $this->actingAs($user)->get('/console/api-keys')->assertOk()->assertSee('value="store_bizinfo"', false);
        $this->post('/console/api-keys', ['name' => 'Collector', 'scopes' => ['store_bizinfo']])->assertSessionDoesntHaveErrors();
        $key = $user->apiKeys()->firstOrFail();
        $headers = ['Authorization' => 'Bearer '.$key->plainKey()];
        $this->getJson(self::URL, $headers)->assertOk();
        $this->patch(route('console.api-keys.update-scopes', $key), [])->assertSessionDoesntHaveErrors();
        $this->assertSame([], $key->fresh()->scopes);
        $this->getJson(self::URL, $headers)->assertForbidden();
        $this->patch(route('console.api-keys.update-scopes', $key), ['scopes' => ['store_bizinfo']])->assertSessionDoesntHaveErrors();
        $this->getJson(self::URL, $headers)->assertOk();
    }

    public function test_audit_failure_rolls_back_business_update(): void
    {
        $this->product();
        $headers = $this->headers();
        $this->withoutExceptionHandling();
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into "shop_seller_info_changes"')) {
                throw new \RuntimeException('Test audit failure');
            }
        });
        try {
            $this->postJson(self::URL, $this->payload(), $headers);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Test audit failure', $e->getMessage());
        }
        $this->assertDatabaseCount('shop_seller_infos', 0);
        $this->assertDatabaseCount('shop_seller_info_changes', 0);
    }

    public function test_store_list_exposes_brand_and_smart_profiles_without_product_fields(): void
    {
        $this->product('helinox', 'pd1');
        $this->product('helinox', 'pd2');
        $this->product('smartshop', 'pd3');
        DB::table('shop_products')->where('product_key', 'pd2')->update(['link' => 'https://brand.naver.com/helinox/products/123?tracking=1', 'mall_name' => 'Helinox']);
        $r = $this->getJson(self::URL, $this->headers())->assertOk()->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.profile_url', 'https://brand.naver.com/helinox/profile')
            ->assertJsonPath('data.items.0.store_name', 'Helinox')
            ->assertJsonPath('data.items.1.store_url', 'https://smartstore.naver.com/smartshop');
        $this->assertArrayNotHasKey('product_key', $r->json('data.items.0'));
        $this->assertArrayNotHasKey('link', $r->json('data.items.0'));
    }

    public function test_addresses_defaults_partial_fields_and_empty_values(): void
    {
        $this->product();
        $h = $this->headers();
        $this->postJson(self::URL, ['profile_url' => 'https://brand.naver.com/teststore/profile', 'biz_name' => 'Public Company'], $h)
            ->assertOk()->assertJsonPath('data.status', 'updated');
        $this->assertDatabaseHas('shop_seller_infos', ['biz_name' => 'Public Company', 'seller_info_url' => 'https://brand.naver.com/teststore/profile']);
        foreach (['https://smartstore.naver.com/teststore', 'https://brand.naver.com/teststore/',
            'https://m.smartstore.naver.com/teststore/products/123?tracking=1', 'https://smartstore.naver.com/teststore/profile'] as $url) {
            $this->postJson(self::URL, ['store_url' => $url, 'biz_name' => null, 'address' => 'Public Address', 'email' => '   '], $h)
                ->assertOk();
        }
        $this->assertDatabaseHas('shop_seller_infos', ['biz_name' => 'Public Company', 'address' => 'Public Address']);
        $this->postJson(self::URL, ['store_id' => 'teststore', 'biz_name' => 'Changed at same second'], $h)->assertOk();
        $this->postJson(self::URL, ['store_id' => 'teststore', 'biz_name' => 'Old', 'observed_at' => now()->subDay()->toIso8601String()], $h)
            ->assertStatus(409)->assertJsonPath('code', 'stale_observation');
        $this->postJson(self::URL, ['profile_url' => 'https://brand.naver.com/unknown/profile', 'biz_name' => 'Company'], $h)
            ->assertNotFound()->assertJsonPath('code', 'store_not_found');
    }

    public function test_batch_preserves_order_and_each_result_without_rolling_back_other_stores(): void
    {
        $this->product();
        $h = $this->headers();
        $item = ['profile_url' => 'https://smartstore.naver.com/teststore/profile', 'biz_reg_no' => '123-45-67890'];
        $this->postJson(self::URL, [$item, $item,
            ['store_url' => 'https://brand.naver.com/missing', 'biz_name' => 'Missing'],
            ['store_id' => 'teststore', 'biz_reg_no' => 'bad'], 'not an object'], $h)
            ->assertOk()->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.items.0.status', 'updated')
            ->assertJsonPath('data.items.1.status', 'unchanged')
            ->assertJsonPath('data.items.2.status', 'not_found')
            ->assertJsonPath('data.items.2.code', 'store_not_found')
            ->assertJsonPath('data.items.3.status', 'invalid')
            ->assertJsonPath('data.items.4.index', 4)
            ->assertJsonPath('data.items.4.status', 'invalid');
        $this->assertDatabaseHas('shop_seller_infos', ['biz_reg_no' => '1234567890']);
        $this->assertDatabaseCount('shop_seller_info_changes', 1);
        $this->postJson(self::URL, array_fill(0, 100, $item), $h)->assertOk()->assertJsonCount(100, 'data.items');
        $this->postJson(self::URL, array_fill(0, 101, $item), $h)->assertUnprocessable();
        $this->postJson(self::URL, [], $h)->assertUnprocessable();
    }

    public function test_malformed_addresses_and_conflicting_identifiers_are_rejected(): void
    {
        $this->product();
        $h = $this->headers();
        foreach (['https://evil.example/teststore', 'https://smartstore.naver.com.evil.example/teststore',
            'https://user:pass@smartstore.naver.com/teststore', 'https://smartstore.naver.com/teststore/../another',
            'https://smartstore.naver.com/teststore%2Fanother', 'https://smartstore.naver.com:8443/teststore',
            'https://smartstore.naver.com/teststore/products/bad', ['not a URL']] as $url) {
            $this->postJson(self::URL, ['profile_url' => $url, 'biz_name' => 'Company'], $h)->assertUnprocessable();
        }
        $this->postJson(self::URL, ['store_id' => 'teststore', 'profile_url' => 'https://brand.naver.com/other/profile', 'biz_name' => 'Company'], $h)->assertUnprocessable();
        $this->postJson(self::URL, ['store_url' => 'https://smartstore.naver.com/other', 'profile_url' => 'https://brand.naver.com/teststore/profile', 'biz_name' => 'Company'], $h)->assertUnprocessable();
        $this->postJson(self::URL, ['biz_name' => 'Company'], $h)->assertUnprocessable();
        $this->assertDatabaseCount('shop_seller_infos', 0);
    }
}
