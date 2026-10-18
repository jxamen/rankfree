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

    public function test_polling_without_results_cannot_keep_another_runner_waiting_past_five_minutes(): void
    {
        $u = User::factory()->create(['role' => 'operator']);
        $a = $this->analysis($u);
        $b = $this->analysis($u);
        $lock = app(\App\Domain\Shopping\ShopExposureRunLock::class);
        $this->assertTrue($lock->acquire('ip:test', 'page:a', $a->id)['ok']);
        for ($i = 0; $i < 4; $i++) {
            $this->travel(60)->seconds();
            $this->assertTrue($lock->acquire('ip:test', 'page:a', $a->id)['ok']);
            $this->assertFalse($lock->acquire('ip:test', 'page:b', $b->id)['ok']);
        }
        $this->travel(60)->seconds();
        $this->assertTrue($lock->acquire('ip:test', 'page:b', $b->id)['ok']);
    }

    public function test_saved_collection_results_extend_progress_deadline(): void
    {
        $u = User::factory()->create(['role' => 'operator']);
        $a = $this->analysis($u);
        $b = $this->analysis($u);
        $lock = app(\App\Domain\Shopping\ShopExposureRunLock::class);
        $lock->acquire('ip:127.0.0.1', 'page:a', $a->id);
        for ($i = 0; $i < 5; $i++) {
            $this->travel(60)->seconds();
            if ($i === 3) {
                $this->actingAs($u)->postJson(route('admin.shop-keyword.check-html', $a), [
                    'item_id' => $a->combos()->first()->id, 'html' => '', 'runner' => 'a',
                ])->assertOk();
            }
            $this->assertTrue($lock->acquire('ip:127.0.0.1', 'page:a', $a->id)['ok']);
        }
        $this->assertFalse($lock->acquire('ip:127.0.0.1', 'page:b', $b->id)['ok']);
    }

    public function test_progress_on_another_ip_does_not_extend_stalled_ip_lock(): void
    {
        $u = User::factory()->create(['role' => 'operator']);
        $a = $this->analysis($u);
        $b = $this->analysis($u);
        $lock = app(\App\Domain\Shopping\ShopExposureRunLock::class);
        $lock->acquire('ip:one', 'page:a', $a->id);
        for ($i = 0; $i < 4; $i++) {
            $this->travel(60)->seconds();
            $lock->acquire('ip:one', 'page:a', $a->id);
            $lock->acquire('ip:two', 'page:c', $a->id);
            $lock->recordProgress('ip:two', $a->id);
        }
        $this->travel(60)->seconds();
        $this->assertTrue($lock->acquire('ip:one', 'page:b', $b->id)['ok']);
        $this->assertFalse($lock->acquire('ip:two', 'page:b', $b->id)['ok']);
    }

    public function test_same_owner_cannot_replace_another_analysis_lock(): void
    {
        $u = User::factory()->create(['role' => 'operator']);
        $a = $this->analysis($u);
        $b = $this->analysis($u);
        $lock = app(\App\Domain\Shopping\ShopExposureRunLock::class);
        $lock->acquire('server', 'user:1', $a->id);
        $this->assertFalse($lock->acquire('server', 'user:1', $b->id)['ok']);
    }

    public function test_extension_background_queue_yields_to_running_page(): void
    {
        $u = User::factory()->create(['role' => 'operator', 'api_scopes' => ['shop_keyword']]);
        $page = $this->analysis($u);
        $api = $this->analysis($u, 'api');
        [, $plain] = ExtToken::issue($u);

        $this->actingAs($u)->getJson(route('admin.shop-keyword.pending', $page).'?runner=pageA')->assertOk();

        // 확장 큐는 빈 목록(다음 알람에 재시도), 저장 요청은 403(현 확장이 루프를 멈춘다)
        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/ext/shop-keyword/check-queue')
            ->assertOk()->assertJsonPath('data.items', [])->assertJsonPath('data.busy', true);
        $this->withHeader('Authorization', 'Bearer '.$plain)->postJson("/api/ext/shop-keyword/{$api->id}/check-html", ['item_id' => 1, 'html' => ''])
            ->assertStatus(403);
    }
}
