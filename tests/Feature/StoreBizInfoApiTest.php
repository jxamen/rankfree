<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ShopSellerInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        // Only the store identity and the public-info declaration still reject a whole request; bad business values just drop that field.
        foreach ([['public_business_info' => false], ['store_id' => 'bad id!'], ['store_id' => ['x']]] as $bad) {
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

    public function test_bad_values_drop_only_that_field_and_the_rest_is_saved(): void
    {
        $this->product();
        $h = $this->headers();
        $r = $this->postJson(self::URL, $this->payload(['biz_reg_no' => 'bad', 'email' => 'bad', 'address' => str_repeat('x', 1001),
            'customer_phone' => '010-1234-5678', 'source_url' => 'http://example.com',
            'observed_at' => now()->addDay()->toIso8601String(), 'raw' => ['private' => 'data'], 'channel_uid' => 'untrusted']), $h)
            ->assertOk()->assertJsonPath('data.status', 'updated')
            ->assertJsonPath('data.captured_at', now()->toIso8601String());
        $skipped = collect($r->json('data.skipped_fields'))->pluck('reason', 'field')->sortKeys()->all();
        $this->assertSame(['address' => 'too_long', 'biz_reg_no' => 'invalid_format', 'channel_uid' => 'unknown_field',
            'email' => 'invalid_format', 'observed_at' => 'invalid_format', 'raw' => 'unknown_field', 'source_url' => 'invalid_format'], $skipped);
        $this->assertEqualsCanonicalizing(['biz_name', 'customer_phone', 'representative'], $r->json('data.changed_fields'));
        $row = ShopSellerInfo::firstOrFail();
        // A mobile-looking customer number is stored as given; the dropped fields stay empty; a bad source falls back to the store profile.
        $this->assertSame('010-1234-5678', $row->customer_phone);
        $this->assertSame('Test Company', $row->biz_name);
        $this->assertNull($row->biz_reg_no);
        $this->assertNull($row->email);
        $this->assertNull($row->address);
        $this->assertSame('https://smartstore.naver.com/teststore/profile', $row->seller_info_url);
        $this->assertNotSame('untrusted', $row->channel_uid);
    }

    public function test_phone_is_stored_as_given_and_business_number_is_cleaned_to_digits(): void
    {
        $this->product();
        $h = $this->headers();
        $this->postJson(self::URL, ['store_id' => 'teststore', 'customer_phone' => '1588-1234 (평일 09~18시)', 'biz_reg_no' => '123 456 7890'], $h)
            ->assertOk()->assertJsonPath('data.skipped_fields', []);
        $this->assertDatabaseHas('shop_seller_infos', ['customer_phone' => '1588-1234 (평일 09~18시)', 'biz_reg_no' => '1234567890']);
        $this->postJson(self::URL, ['store_id' => 'teststore', 'biz_reg_no' => '123.45.67890'], $h)->assertOk()->assertJsonPath('data.status', 'unchanged');
        $this->postJson(self::URL, ['store_id' => 'teststore', 'biz_reg_no' => 9876543210, 'biz_name' => 'Number type'], $h)
            ->assertOk()->assertJsonPath('data.changed_fields', ['biz_name', 'biz_reg_no']);
        $this->assertDatabaseHas('shop_seller_infos', ['biz_reg_no' => '9876543210']);
        // Nine digits are not a business number: that field alone is dropped.
        $this->postJson(self::URL, ['store_id' => 'teststore', 'biz_reg_no' => '123-45-6789', 'biz_name' => 'Still saved'], $h)
            ->assertOk()->assertJsonPath('data.skipped_fields.0.field', 'biz_reg_no')->assertJsonPath('data.skipped_fields.0.reason', 'invalid_format');
        $this->assertDatabaseHas('shop_seller_infos', ['biz_reg_no' => '9876543210', 'biz_name' => 'Still saved']);
    }

    public function test_request_with_no_valid_field_stays_invalid_and_is_logged_without_values(): void
    {
        $this->product('___abouttime_', 'pd9');
        $h = $this->headers();
        Log::spy();
        $secret = 'secret-value-should-not-be-logged';
        $this->postJson(self::URL, ['store_id' => '___abouttime_', 'biz_reg_no' => $secret, 'email' => $secret, 'weird' => $secret], $h)
            ->assertUnprocessable()->assertJsonPath('errors.biz_reg_no.0', 'invalid_format')->assertJsonPath('errors.weird.0', 'unknown_field');
        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) use ($secret) {
            return $message === 'shop_bizinfo_invalid' && $context['store_id'] === '___abouttime_' && $context['index'] === null
                && isset($context['fields']['biz_reg_no'], $context['fields']['email']) && ! str_contains(json_encode($context), $secret);
        });
        // The same request inside an array is reported per item and logged with its index.
        $this->postJson(self::URL, [['store_id' => '___abouttime_', 'biz_name' => 'Saved'], ['store_id' => '___abouttime_', 'email' => $secret]], $h)
            ->assertOk()->assertJsonPath('data.items.0.status', 'updated')->assertJsonPath('data.items.1.status', 'invalid');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'shop_bizinfo_invalid' && $context['index'] === 1 && ! str_contains(json_encode($context), $secret))->once();
        $this->assertDatabaseCount('shop_seller_infos', 1);
    }

    public function test_dropped_fields_are_logged_by_name_and_reason_only(): void
    {
        $this->product();
        $h = $this->headers();
        Log::spy();
        $this->postJson(self::URL, ['store_id' => 'teststore', 'biz_name' => 'Company', 'email' => 'secret@@bad'], $h)->assertOk();
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'shop_bizinfo_skipped'
            && $context['store_id'] === 'teststore' && $context['fields'] === ['email' => 'invalid_format'] && ! str_contains(json_encode($context), 'secret'))->once();
    }

    public function test_status_only_marks_store_suspended_records_message_and_hides_it_from_the_list(): void
    {
        $this->product('teststore', 'pd1');
        $this->product('otherstore', 'pd2');
        $h = $this->headers();
        $message = '판매자의 사정에 따라 운영이 중지되었습니다.';
        $r = $this->postJson(self::URL, ['profile_url' => 'https://smartstore.naver.com/teststore/profile', 'status' => 'suspended', 'message' => $message], $h)
            ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.message', $message)->assertJsonPath('data.suspended_at', now()->toIso8601String())
            ->assertJsonPath('data.released_at', null)->assertJsonPath('data.skipped_fields', []);
        $this->assertArrayNotHasKey('changed_fields', $r->json('data'));
        $this->assertDatabaseHas('shop_store_statuses', ['store_id' => 'teststore', 'status' => 'suspended', 'suspended_message' => $message]);
        // No business row is created for a store that only reported its status.
        $this->assertDatabaseCount('shop_seller_infos', 0);
        $this->assertDatabaseCount('shop_seller_info_changes', 0);
        $list = $this->getJson(self::URL, $h)->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.store_id', 'otherstore');
        $this->assertSame(1, count($list->json('data.items')));
        $this->getJson(self::URL.'?store_id=teststore', $h)->assertOk()->assertJsonCount(0, 'data.items');
        // The cursor still moves over the remaining stores.
        $this->getJson(self::URL.'?limit=1', $h)->assertOk()->assertJsonPath('data.items.0.store_id', 'otherstore')->assertJsonPath('data.pagination.has_more', false);
    }

    public function test_same_report_is_unchanged_a_new_message_is_a_change_and_the_time_is_kept(): void
    {
        $this->product();
        $h = $this->headers();
        $body = ['store_id' => 'teststore', 'status' => 'suspended', 'message' => 'first text'];
        $this->postJson(self::URL, $body, $h)->assertOk()->assertJsonPath('data.changed', true);
        $firstAt = DB::table('shop_store_statuses')->value('suspended_at');
        $this->travel(5)->minutes();
        $this->postJson(self::URL, $body, $h)->assertOk()->assertJsonPath('data.changed', false)->assertJsonPath('data.message', 'first text');
        // A report without a message keeps the stored text; a different text replaces it.
        $this->postJson(self::URL, ['store_id' => 'teststore', 'status' => 'suspended'], $h)->assertOk()->assertJsonPath('data.changed', false)->assertJsonPath('data.message', 'first text');
        $this->postJson(self::URL, ['store_id' => 'teststore', 'status' => 'suspended', 'message' => '   '], $h)->assertOk()->assertJsonPath('data.message', 'first text');
        $this->postJson(self::URL, ['store_id' => 'teststore', 'status' => 'suspended', 'message' => 'second text'], $h)
            ->assertOk()->assertJsonPath('data.changed', true)->assertJsonPath('data.message', 'second text');
        $this->assertSame($firstAt, DB::table('shop_store_statuses')->value('suspended_at'));
        $this->assertDatabaseCount('shop_store_statuses', 1);
    }

    public function test_active_releases_the_store_keeps_the_record_and_it_returns_to_the_list(): void
    {
        $this->product();
        $h = $this->headers();
        // Releasing a store that was never marked does nothing and creates no row.
        $this->postJson(self::URL, ['store_id' => 'teststore', 'status' => 'active'], $h)->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.changed', false);
        $this->assertDatabaseCount('shop_store_statuses', 0);
        $this->postJson(self::URL, ['store_id' => 'teststore', 'status' => 'suspended', 'message' => 'closed'], $h)->assertOk();
        $this->getJson(self::URL, $h)->assertOk()->assertJsonCount(0, 'data.items');
        $this->travel(1)->hours();
        $this->postJson(self::URL, ['store_id' => 'teststore', 'status' => 'active'], $h)->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.changed', true)->assertJsonPath('data.message', 'closed')
            ->assertJsonPath('data.released_at', now()->toIso8601String());
        $this->assertDatabaseHas('shop_store_statuses', ['store_id' => 'teststore', 'status' => 'active', 'suspended_message' => 'closed']);
        $this->getJson(self::URL, $h)->assertOk()->assertJsonPath('data.items.0.store_id', 'teststore');
        $this->postJson(self::URL, ['store_id' => 'teststore', 'status' => 'active'], $h)->assertOk()->assertJsonPath('data.changed', false);
        // A later suspension starts a new record and drops the old text.
        $this->postJson(self::URL, ['store_id' => 'teststore', 'status' => 'suspended'], $h)->assertOk()->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.message', null)->assertJsonPath('data.released_at', null);
    }

    public function test_status_can_come_with_business_fields_and_both_are_saved(): void
    {
        $this->product();
        $h = $this->headers();
        $this->postJson(self::URL, $this->payload(['status' => 'suspended', 'message' => 'closed']), $h)->assertOk()
            ->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.info_status', 'updated')
            ->assertJsonPath('data.changed_fields', ['biz_name', 'representative', 'biz_reg_no', 'address'])
            ->assertJsonPath('data.message', 'closed');
        $this->assertDatabaseHas('shop_seller_infos', ['store_id' => 'teststore', 'biz_name' => 'Test Company']);
        $this->assertDatabaseHas('shop_store_statuses', ['store_id' => 'teststore', 'status' => 'suspended']);
        // A conflicting business report (409) saves nothing, not even the status.
        $this->postJson(self::URL, $this->payload(['status' => 'active', 'observed_at' => now()->subDay()->toIso8601String()]), $h)
            ->assertStatus(409)->assertJsonPath('code', 'stale_observation');
        $this->assertDatabaseHas('shop_store_statuses', ['store_id' => 'teststore', 'status' => 'suspended']);
    }

    public function test_status_is_never_blocked_by_observation_time_and_array_results_are_per_item(): void
    {
        $this->product('aaa', 'pd1');
        $this->product('bbb', 'pd2');
        $h = $this->headers();
        $this->postJson(self::URL, ['store_id' => 'aaa', 'status' => 'suspended', 'observed_at' => now()->subDays(3)->toIso8601String()], $h)->assertOk();
        $this->postJson(self::URL, ['store_id' => 'aaa', 'status' => 'suspended', 'observed_at' => now()->subDays(9)->toIso8601String()], $h)->assertOk();
        $this->postJson(self::URL, [
            ['store_url' => 'https://brand.naver.com/aaa', 'status' => 'active'],
            ['profile_url' => 'https://smartstore.naver.com/bbb/profile', 'status' => 'suspended', 'message' => 'closed', 'unknown_note' => 'x'],
            ['store_id' => 'zzz', 'status' => 'suspended'],
            ['store_id' => 'bbb', 'status' => 'closed'],
            ['store_id' => 'bbb', 'message' => 'no status'],
            ['store_id' => 'bbb', 'status' => 'suspended', 'message' => str_repeat('x', 2001)],
        ], $h)->assertOk()->assertJsonCount(6, 'data.items')
            ->assertJsonPath('data.items.0.status', 'active')->assertJsonPath('data.items.0.changed', true)
            ->assertJsonPath('data.items.1.status', 'suspended')->assertJsonPath('data.items.1.skipped_fields.0.field', 'unknown_note')
            ->assertJsonPath('data.items.2.status', 'not_found')->assertJsonPath('data.items.2.code', 'store_not_found')
            ->assertJsonPath('data.items.3.status', 'invalid')->assertJsonPath('data.items.4.status', 'invalid')
            // Too long message: that text is dropped, the suspension itself is still recorded.
            ->assertJsonPath('data.items.5.status', 'suspended')->assertJsonPath('data.items.5.skipped_fields.0.reason', 'too_long')
            ->assertJsonPath('data.items.5.message', 'closed');
        $this->assertDatabaseHas('shop_store_statuses', ['store_id' => 'bbb', 'status' => 'suspended', 'suspended_message' => 'closed']);
        $this->assertDatabaseHas('shop_store_statuses', ['store_id' => 'aaa', 'status' => 'active']);
        $this->postJson(self::URL, ['store_id' => 'zzz', 'status' => 'suspended'], $h)->assertNotFound()->assertJsonPath('code', 'store_not_found');
        $this->postJson(self::URL, ['store_id' => 'aaa', 'status' => null], $h)->assertUnprocessable();
    }
}
