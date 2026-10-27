<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** 쇼핑 노출 키워드 분석 — 수집 상태를 화면에서 직접 바꾼다(대표님 10-27 16:59 「상태를 바꿀 수 있게해줘」). */
class ShopKeywordStatusChangeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'api_scopes' => ['shop_keyword']]);
    }

    /** 화면에서 만든 분석(확장 수집기가 이어받는 형태) — 조합 순위: 숫자 배열의 각 값(null = 아직 확인 안 함) */
    private function analysis(User $user, string $status, array $ranks, array $extra = []): ShopKeywordAnalysis
    {
        $a = ShopKeywordAnalysis::create(['user_id' => $user->id, 'created_via' => 'admin', 'core_keyword' => '가짜피', 'status' => $status,
            'check_method' => 'search', 'product_id' => '111', 'product_url' => 'https://smartstore.naver.com/x/products/111', 'threshold' => 5, 'combo_count' => count($ranks)] + $extra);
        $a->forceFill(['created_at' => now()->subDays(2), 'collection_source' => '확장 수집기', 'collection_seen_at' => now()->subDays(1)])->save();
        foreach ($ranks as $i => $rank) {
            $item = $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '조합 '.$i]);
            if ($rank !== null) {
                $item->forceFill(['rank' => $rank, 'checked_at' => now()->subDay()])->save();
            }
        }

        return $a;
    }

    private function change(User $user, ShopKeywordAnalysis $a, string $status)
    {
        return $this->actingAs($user)->postJson(route('admin.shop-keyword.status', $a), ['status' => $status]);
    }

    public function test_done_to_collecting_is_picked_up_again_by_the_extension_queue(): void
    {
        $u = $this->admin();
        [, $ext] = ExtToken::issue($u);
        $a = $this->analysis($u, 'done', [0, 3, null]);   // 확인 안 한 조합이 하나 남아 있다
        $this->change($u, $a, 'checking')->assertOk()->assertJsonPath('data.status', 'checking')->assertJsonPath('data.changed', true)->assertJsonPath('data.remaining', 1);
        $fresh = $a->fresh();
        $this->assertSame('checking', $fresh->status);
        $this->assertTrue($fresh->collection_seen_at->gt(now()->subMinute()), '「이어서 확인」처럼 화면 활동 시각을 지금으로 찍는다');
        // 그 사용자의 확장이 대기열을 부르면 이 분석의 미확인 조합을 이어서 가져간다
        $this->withToken($ext)->getJson('/api/ext/shop-keyword/check-queue')->assertOk()->assertJsonPath('data.analysis_id', $a->id);
    }

    public function test_paused_is_not_picked_up_and_collecting_resumes_it(): void
    {
        $u = $this->admin();
        [, $ext] = ExtToken::issue($u);
        $a = $this->analysis($u, 'checking', [null, null]);
        $a->forceFill(['collection_seen_at' => now()])->save();
        $this->change($u, $a, 'paused')->assertOk()->assertJsonPath('data.status', 'paused')->assertJsonPath('data.label', '일시정지');
        $this->withToken($ext)->getJson('/api/ext/shop-keyword/check-queue?watchdog=1')->assertOk()->assertJsonPath('data.items', []);
        $this->change($u, $a, 'checking')->assertOk();
        $this->withToken($ext)->getJson('/api/ext/shop-keyword/check-queue')->assertOk()->assertJsonPath('data.analysis_id', $a->id);
    }

    public function test_status_must_match_the_remaining_combos(): void
    {
        $u = $this->admin();
        $allChecked = $this->analysis($u, 'done', [0, 2]);
        $this->change($u, $allChecked, 'checking')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '확인할 조합이 없어요'));
        $this->change($u, $allChecked, 'paused')->assertStatus(422);
        $this->assertSame('done', $allChecked->fresh()->status);
        $this->change($u, $allChecked, 'done')->assertOk()->assertJsonPath('data.changed', false);

        $open = $this->analysis($u, 'checking', [null, 4, null]);
        $this->change($u, $open, 'done')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '2개 남아'));
        $this->assertSame('checking', $open->fresh()->status);

        // 남은 조합이 없는데 「수집 중」으로 멈춰 있던 것은 완료로 바로잡을 수 있다
        $stuck = $this->analysis($u, 'checking', [1, 0]);
        $this->change($u, $stuck, 'done')->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.label', '완료');
    }

    public function test_ad_ended_and_target_reached_cannot_be_changed(): void
    {
        $u = $this->admin();
        $ended = $this->analysis($u, 'ad_ended', [null]);
        $this->change($u, $ended, 'checking')->assertStatus(409);
        $this->assertSame('ad_ended', $ended->fresh()->status);

        $reached = $this->analysis($u, 'checking', [1, null], ['keyword_target' => 1]);   // 5위 안 키워드가 목표(1)에 이미 도달
        $this->change($u, $reached, 'paused')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '목표 키워드 수에 도달'));
        $this->assertSame('checking', $reached->fresh()->status);
    }

    public function test_only_the_owning_admin_can_change_and_only_the_listed_values(): void
    {
        $owner = $this->admin();
        $a = $this->analysis($owner, 'done', [null]);
        $this->postJson(route('admin.shop-keyword.status', $a), ['status' => 'checking'])->assertUnauthorized();
        $this->change($this->admin(), $a, 'checking')->assertForbidden();   // 다른 관리자
        $this->change(User::factory()->create(['role' => 'user']), $a, 'checking')->assertStatus(403);   // 관리자가 아닌 회원(운영자 화면 접근 자체가 막힌다)
        foreach (['failed', 'pending', 'target_reached', 'ad_ended', ''] as $bad) {
            $this->change($owner, $a, $bad)->assertUnprocessable();
        }
        $this->assertSame('done', $a->fresh()->status);
    }

    public function test_change_is_logged_with_who_and_when_it_was_changed(): void
    {
        Log::spy();
        $u = $this->admin();
        $a = $this->analysis($u, 'done', [null]);
        $this->change($u, $a, 'checking')->assertOk();
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'shop_keyword_status_changed'
            && $context['analysis'] === $a->id && $context['user'] === $u->id && $context['from'] === 'done' && $context['to'] === 'checking')->once();
        $this->assertNotNull($a->fresh()->updated_at);
    }

    public function test_detail_screen_has_the_status_select_for_the_admin_but_the_list_row_does_not(): void
    {
        $u = $this->admin();
        $a = $this->analysis($u, 'done', [0]);
        $html = $this->actingAs($u)->get(route('admin.shop-keyword.show', $a))->assertOk()->getContent();
        $this->assertStringContainsString('id="sk-status"', $html);
        $this->assertStringContainsString(route('admin.shop-keyword.status', $a), $html);
        $this->assertMatchesRegularExpression('/<option value="done" selected>\s*완료\s*<\/option>/', $html);
        $this->assertStringContainsString('수집 중', $html);
        $this->assertStringContainsString('일시정지', $html);
        // 광고가 끝난 분석은 글자만 보여 준다
        $ended = $this->analysis($u, 'ad_ended', [0]);
        $page = $this->actingAs($u)->get(route('admin.shop-keyword.show', $ended))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="sk-status"', $page);
        $this->assertStringContainsString('종료(광고 끝남)', $page);
    }
}
