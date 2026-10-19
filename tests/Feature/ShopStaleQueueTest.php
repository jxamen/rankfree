<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopExposureRunLock;
use App\Models\{ExtToken, ShopKeywordAnalysis, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ShopStaleQueueTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(User $user, string $via = 'admin'): ShopKeywordAnalysis
    {
        $a = ShopKeywordAnalysis::create(['user_id' => $user->id, 'created_via' => $via,
            'core_keyword' => '아디다스양말', 'status' => 'checking', 'check_method' => 'search',
            'product_id' => '111', 'product_url' => 'https://smartstore.naver.com/x/products/111']);
        $a->forceFill(['created_at' => now()->subMonths(3), 'collection_seen_at' => null])->save();
        $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '아디다스 양말']);

        return $a;
    }

    private function owner(): User
    {
        $u = User::factory()->create(['role' => 'operator', 'api_scopes' => ['shop_keyword']]);
        [, $ext] = ExtToken::issue($u);
        $this->withToken($ext);

        return $u;
    }

    public function test_abandoned_screen_rows_never_claim_ip_or_change_state_in_normal_watchdog_policy_or_exclude_queue(): void
    {
        $u = $this->owner();
        $old = $this->analysis($u);
        $legacy = $this->analysis($u);
        $legacy->forceFill(['collection_source' => '확장 수집기', 'collection_seen_at' => now()->subMonths(2)])->save();
        foreach (['', '?watchdog=1', '?policy_only=1', '?exclude[]='.$old->combos()->first()->id] as $query) {
            $this->getJson('/api/ext/shop-keyword/check-queue'.$query)->assertOk()->assertJsonPath('data.items', []);
            $this->assertNull(Cache::get('shop-exposure:runner:ip:127.0.0.1'));
        }
        $api = $this->analysis($u, 'api');
        $this->getJson('/api/ext/shop-keyword/check-queue')->assertOk()->assertJsonPath('data.analysis_id', $api->id);
        $this->assertSame('checking', $old->fresh()->status);
        $this->assertNull($old->fresh()->collection_seen_at);
        $this->assertSame('checking', $legacy->fresh()->status);
    }

    public function test_recent_screen_activity_and_explicit_resume_make_old_analysis_eligible_but_expire(): void
    {
        $a = $this->analysis($this->owner());
        $a->forceFill(['collection_seen_at' => now()->subMinutes(9)])->save();
        $this->getJson('/api/ext/shop-keyword/check-queue?watchdog=1')->assertOk()->assertJsonPath('data.analysis_id', $a->id);
        $this->travel(601)->seconds();
        $this->getJson('/api/ext/shop-keyword/check-queue?watchdog=1')->assertOk()->assertJsonPath('data.items', []);
        $a->update(['status' => 'paused']);
        $this->actingAs($a->user)->postJson(route('admin.shop-keyword.pause', $a), ['paused' => false])->assertOk();
        $this->getJson('/api/ext/shop-keyword/check-queue?watchdog=1')->assertOk()->assertJsonPath('data.analysis_id', $a->id);
    }

    public function test_both_product_queue_paths_exclude_stale_screens_without_consuming_retry_attempts(): void
    {
        $u = $this->owner();
        $legacy = $this->analysis($u);
        $leased = $this->analysis($u);
        $state = ['state' => 'retry', 'attempts' => 1, 'next_at' => 0];
        $leased->update(['product_collection' => $state]);
        $api = $this->analysis($u, 'api');
        $this->getJson('/api/ext/shop-keyword/product-queue')->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.analysis_id', $api->id);
        $this->assertSame($state, $leased->fresh()->product_collection);
        $this->assertSame('checking', $legacy->fresh()->status);
        $leased->forceFill(['collection_seen_at' => now()])->save();
        $this->getJson('/api/ext/shop-keyword/product-queue')->assertOk()->assertJsonPath('data.items.0.analysis_id', $leased->id);
    }

    public function test_late_stale_upload_cannot_acquire_ip_or_revive_screen_work(): void
    {
        $a = $this->analysis($this->owner());
        $this->postJson('/api/ext/shop-keyword/'.$a->id.'/check-html', [
            'item_id' => $a->combos()->first()->id, 'html' => '',
        ])->assertStatus(409);
        $this->assertNull(Cache::get('shop-exposure:runner:ip:127.0.0.1'));
        $this->assertNull($a->combos()->first()->rank);
        $this->assertSame('checking', $a->fresh()->status);
    }

    public function test_recent_save_alone_is_activity_and_ten_minute_boundary_expires(): void
    {
        $a = $this->analysis($this->owner());
        $a->forceFill(['collection_source' => '확장 수집기'])->save();
        $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '최근 저장',
            'rank' => 0, 'checked_at' => now()->subMinutes(3)]);
        $this->getJson('/api/ext/shop-keyword/check-queue')->assertOk()->assertJsonPath('data.analysis_id', $a->id);
        $a->forceFill(['collection_seen_at' => now()->subMinutes(10)])->save();
        $a->combos()->whereNotNull('rank')->update(['checked_at' => now()->subMinutes(10)]);
        $this->getJson('/api/ext/shop-keyword/check-queue?watchdog=1')->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_result_saving_analysis_can_reclaim_ip_from_a_heartbeat_only_holder(): void
    {
        $u = $this->owner();
        $old = $this->analysis($u, 'api');
        $active = $this->analysis($u, 'api');
        $lock = app(ShopExposureRunLock::class);
        $lock->acquire('ip:127.0.0.1', 'ext:old', $old->id);
        $active->forceFill(['collection_ip' => '127.0.0.1'])->save();
        $active->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '진행',
            'rank' => 0, 'checked_at' => now()]);
        $this->assertTrue($lock->acquire('ip:127.0.0.1', 'ext:active', $active->id)['ok']);
        $this->assertFalse($lock->acquire('ip:127.0.0.1', 'ext:old', $old->id)['ok']);
        $this->travel(121)->seconds();
        $this->assertTrue($lock->acquire('ip:127.0.0.1', 'ext:old', $old->id)['ok']);
    }

    public function test_recent_saves_take_queue_priority_and_protect_ip_after_cache_expiry_across_accounts(): void
    {
        $u = $this->owner();
        $old = $this->analysis($u, 'api');
        $active = $this->analysis($u, 'api');
        $lock = app(ShopExposureRunLock::class);
        $lock->acquire('ip:127.0.0.1', 'page:active', $active->id);
        $active->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '저장됨', 'rank' => 0, 'checked_at' => now()]);
        $this->travel(91)->seconds();
        $this->assertNull(Cache::get('shop-exposure:runner:ip:127.0.0.1'));
        $foreign = $this->analysis(User::factory()->create(), 'api');
        $r = $lock->acquire('ip:127.0.0.1', 'ext:foreign', $foreign->id);
        $this->assertFalse($r['ok']);
        $this->assertSame($active->id, $r['holder']['analysis_id']);
        $this->assertNull($foreign->fresh()->collection_seen_at);
        $this->assertTrue($lock->acquire('ip:192.0.2.2', 'ext:other-ip', $foreign->id)['ok']);
        $this->getJson('/api/ext/shop-keyword/check-queue')->assertOk()->assertJsonPath('data.analysis_id', $active->id);
        $active->update(['status' => 'paused']);
        $this->getJson('/api/ext/shop-keyword/check-queue')->assertOk()->assertJsonPath('data.analysis_id', $old->id);
    }
}
