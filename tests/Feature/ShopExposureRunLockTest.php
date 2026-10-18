<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordAnalysisItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 쇼핑 노출 순위 수집 IP별 잠금 — 같은 IP 에서는 하나만, 다른 IP 끼리는 동시에(2026-09-29). */
class ShopExposureRunLockTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(User $u, string $via = 'admin'): ShopKeywordAnalysis
    {
        $a = ShopKeywordAnalysis::create([
            'user_id' => $u->id, 'core_keyword' => '비타민c', 'threshold' => 5, 'created_via' => $via,
            'product_url' => 'https://smartstore.naver.com/x/products/111', 'status' => 'checking',
        ]);
        ShopKeywordAnalysisItem::create(['analysis_id' => $a->id, 'kind' => 'combo', 'source' => 'combo', 'keyword' => '비타민c 고함량']);

        return $a;
    }

    public function test_same_analysis_transfers_to_new_runner_and_fences_old_runner(): void
    {
        $u = User::factory()->create(['role' => 'operator']);
        $a = $this->analysis($u);
        $lock = app(\App\Domain\Shopping\ShopExposureRunLock::class);
        $this->assertTrue($lock->acquire('ip:192.0.2.1', 'page:old', $a->id)['ok']);
        $this->travel(30)->seconds();
        $this->assertTrue($lock->acquire('ip:192.0.2.1', 'ext:new', $a->id)['ok']);
        $this->assertTrue($lock->acquire('ip:192.0.2.1', 'page:old', $a->id)['taken_over']);
        $lock->release('ip:192.0.2.1', $a->id);
        $this->travel(91)->seconds();
        $this->assertTrue($lock->acquire('ip:192.0.2.1', 'page:old', $a->id)['taken_over']);
        $this->assertTrue($lock->acquire('ip:192.0.2.1', 'ext:new', $a->id)['ok']);
        $holder = \Illuminate\Support\Facades\Cache::get('shop-exposure:runner:ip:192.0.2.1');
        $this->assertSame('ext:new', $holder['owner']);
        $this->assertSame(now()->timestamp, $holder['at']);
    }

    public function test_waiting_runner_does_not_refresh_or_acquire_lock(): void
    {
        $a = $this->analysis(User::factory()->create());
        $lock = app(\App\Domain\Shopping\ShopExposureRunLock::class);
        $scope = 'ip:192.0.2.1';
        $lock->acquire($scope, 'page:waiting', $a->id);
        $seen = $a->refresh()->collection_seen_at->timestamp;
        $this->travel(30)->seconds();
        $this->assertTrue($lock->acquire($scope, 'page:waiting', $a->id, '', false)['ok']);
        $this->assertSame($seen, $a->refresh()->collection_seen_at->timestamp);
        $this->travel(61)->seconds();
        $this->assertNull(\Illuminate\Support\Facades\Cache::get('shop-exposure:runner:'.$scope));
        $this->assertTrue($lock->acquire($scope, 'page:waiting', $a->id, '', false)['ok']);
        $this->assertNull(\Illuminate\Support\Facades\Cache::get('shop-exposure:runner:'.$scope));
    }

    public function test_stopped_or_stale_holder_yields_but_active_other_analysis_does_not(): void
    {
        $u = User::factory()->create(['role' => 'operator']);
        $a = $this->analysis($u);
        $b = $this->analysis($u);
        $lock = app(\App\Domain\Shopping\ShopExposureRunLock::class);
        $key = 'shop-exposure:runner:ip:192.0.2.1';
        foreach (['done', 'target_reached', 'ad_ended', 'paused', 'checking'] as $status) {
            ShopKeywordAnalysis::whereKey($a->id)->update(['status' => $status, 'collection_seen_at' => now()]);
            \Illuminate\Support\Facades\Cache::put($key, ['owner' => 'same-token', 'analysis_id' => $a->id, 'at' => now()->timestamp], 600);
            $r = $lock->acquire('ip:192.0.2.1', 'same-token', $b->id);
            $this->assertSame($status !== 'checking', $r['ok'], $status);
        }
        ShopKeywordAnalysis::whereKey($a->id)->update(['collection_seen_at' => now()->subSeconds(91)]);
        \Illuminate\Support\Facades\Cache::put($key, ['owner' => 'old-worker', 'analysis_id' => $a->id, 'at' => now()->subSeconds(91)->timestamp], 600);
        $this->assertTrue($lock->acquire('ip:192.0.2.1', 'new-worker', $b->id)['ok']);
    }

    public function test_second_runner_waits_until_first_releases_or_expires(): void
    {
        $u = User::factory()->create(['role' => 'operator']);
        $a = $this->analysis($u);
        $b = $this->analysis($u);

        $this->actingAs($u)->getJson(route('admin.shop-keyword.pending', $a).'?runner=pageA')->assertOk();
        // 다른 페이지(다른 분석이라도) — 줄 선다
        $this->actingAs($u)->getJson(route('admin.shop-keyword.pending', $b).'?runner=pageB')
            ->assertStatus(423)->assertJson(['busy' => true, 'analysis_id' => $a->id]);
        $this->actingAs($u)->postJson(route('admin.shop-keyword.check-html', $b), ['item_id' => 1, 'html' => '', 'runner' => 'pageB'])->assertStatus(423);
        // 다른 IP(다른 사무실·다른 계정)는 동시에 돌아도 된다 — 네이버 차단이 IP 단위
        $this->actingAs($u)->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->getJson(route('admin.shop-keyword.pending', $b).'?runner=pageC')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        // 같은 주인은 계속 돈다(갱신)
        $this->actingAs($u)->getJson(route('admin.shop-keyword.pending', $a).'?runner=pageA')->assertOk();

        // 창이 닫혀 갱신이 끊기면 90초 뒤 저절로 풀린다
        $this->travel(91)->seconds();
        $this->actingAs($u)->getJson(route('admin.shop-keyword.pending', $b).'?runner=pageB')->assertOk();

        // 중단을 누르면 바로 풀린다
        $this->actingAs($u)->postJson(route('admin.shop-keyword.pause', $b), ['paused' => true])->assertOk();
        $this->actingAs($u)->getJson(route('admin.shop-keyword.pending', $a).'?runner=pageA')->assertOk();
    }

    public function test_extension_skips_unread_analysis_and_releases_its_lease_for_next(): void
    {
        $u = User::factory()->create(['role' => 'operator', 'api_scopes' => ['shop_keyword']]);
        $a = $this->analysis($u, 'api');
        $b = $this->analysis($u, 'api');
        [, $plain] = ExtToken::issue($u);
        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/ext/shop-keyword/check-queue?runner=queue')
            ->assertOk()->assertJsonPath('data.analysis_id', $a->id);
        $this->getJson('/api/ext/shop-keyword/check-queue?runner=queue&exclude[]='.$a->combos()->first()->id)
            ->assertOk()->assertJsonPath('data.analysis_id', $b->id);
        $this->assertNull($a->combos()->first()->rank);
    }

    public function test_extension_background_queue_takes_over_same_analysis(): void
    {
        $u = User::factory()->create(['role' => 'operator', 'api_scopes' => ['shop_keyword']]);
        $page = $this->analysis($u);
        $api = $page;
        $page->update(['created_via' => 'api']);
        [, $plain] = ExtToken::issue($u);

        $this->actingAs($u)->getJson(route('admin.shop-keyword.pending', $page).'?runner=pageA')->assertOk();

        // 같은 분석은 확장이 넘겨받고 이전 페이지는 조용히 멈춘다.
        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/ext/shop-keyword/check-queue')
            ->assertOk()->assertJsonPath('data.analysis_id', $api->id);
        $this->actingAs($u)->getJson(route('admin.shop-keyword.pending', $page).'?runner=pageA')
            ->assertStatus(423)->assertJson(['taken_over' => true, 'message' => '다른 창/확장이 이어 받았어요']);
        $item = $page->combos()->first();
        $this->actingAs($u)->postJson(route('admin.shop-keyword.check-html', $page), ['item_id' => $item->id, 'html' => '', 'runner' => 'pageA'])
            ->assertStatus(423)->assertJson(['taken_over' => true]);
        $this->assertNull($item->refresh()->rank);
        $this->withHeader('Authorization', 'Bearer '.$plain)->postJson("/api/ext/shop-keyword/{$api->id}/check-html", ['item_id' => 1, 'html' => ''])
            ->assertOk();
    }
}
