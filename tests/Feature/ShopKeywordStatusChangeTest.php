<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** 쇼핑 노출 키워드 분석 — 수집 상태를 화면에서 고른 값 그대로 저장한다(대표님 10-27 16:59 「상태를 바꿀 수 있게해줘」 · 17:12 「그냥 저장만 시키게」). */
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
        $this->change($u, $a, 'checking')->assertOk()->assertJsonPath('data.status', 'checking')->assertJsonPath('data.changed', true);
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

    public function test_chosen_status_is_saved_as_is_regardless_of_remaining_combos(): void
    {
        $u = $this->admin();
        // 모든 조합을 확인한 분석(125번 같은 경우)도 「수집 중」으로 그대로 저장된다(확인할 조합이 생기기 전까지 확장이 집어 갈 것이 없을 뿐)
        $allChecked = $this->analysis($u, 'done', [0, 2]);
        $this->change($u, $allChecked, 'checking')->assertOk()->assertJsonPath('data.status', 'checking')->assertJsonPath('data.changed', true);
        $this->assertSame('checking', $allChecked->fresh()->status);
        $this->change($u, $allChecked, 'paused')->assertOk()->assertJsonPath('data.label', '일시정지');
        $this->assertSame('paused', $allChecked->fresh()->status);
        $this->change($u, $allChecked, 'done')->assertOk();
        $this->assertSame('done', $allChecked->fresh()->status);
        $this->change($u, $allChecked, 'done')->assertOk()->assertJsonPath('data.changed', false);

        // 확인할 조합이 남아 있어도 「완료」로 저장된다
        $open = $this->analysis($u, 'checking', [null, 4, null]);
        $this->change($u, $open, 'done')->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertSame('done', $open->fresh()->status);
        // 조합 · 순위는 건드리지 않는다
        $this->assertSame(3, $open->allCombos()->count());
        $this->assertSame(2, $open->allCombos()->whereNull('rank')->count());
    }

    public function test_ad_ended_and_target_reached_are_saved_too(): void
    {
        $u = $this->admin();
        [, $ext] = ExtToken::issue($u);
        // 광고 끝남으로 멈춘 분석도 모델 이벤트(되살리기 방지)에 막히지 않고 고른 값이 저장된다
        $ended = $this->analysis($u, 'ad_ended', [null]);
        $this->change($u, $ended, 'checking')->assertOk()->assertJsonPath('data.status', 'checking');
        $this->assertSame('checking', $ended->fresh()->status);
        $this->withToken($ext)->getJson('/api/ext/shop-keyword/check-queue')->assertOk()->assertJsonPath('data.analysis_id', $ended->id);

        $reached = $this->analysis($u, 'target_reached', [1, null], ['keyword_target' => 1]);   // 5위 안 키워드가 목표(1)에 이미 도달
        $this->change($u, $reached, 'paused')->assertOk()->assertJsonPath('data.status', 'paused');
        $this->assertSame('paused', $reached->fresh()->status);
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
        // 광고가 끝난 분석도 고를 수 있다(현재 값은 맨 위 비활성 항목으로 보인다)
        $ended = $this->analysis($u, 'ad_ended', [0]);
        $page = $this->actingAs($u)->get(route('admin.shop-keyword.show', $ended))->assertOk()->getContent();
        $this->assertStringContainsString('id="sk-status"', $page);
        $this->assertMatchesRegularExpression('/<option value="" selected disabled>\s*종료\(광고 끝남\)\s*<\/option>/', $page);
    }

    private function page(User $u, ShopKeywordAnalysis $a): string
    {
        return $this->actingAs($u)->get(route('admin.shop-keyword.show', $a))->assertOk()->getContent();
    }

    public function test_run_button_is_always_shown_while_collecting_even_with_nothing_left_to_check(): void
    {
        $u = $this->admin();
        // 125번 같은 경우 — 수집 중인데 확인할 조합이 없다(진행 카드가 없다). 그래도 「이어서 수집」은 늘 보인다
        $a = $this->analysis($u, 'checking', [0, 2]);
        $html = $this->page($u, $a);
        $this->assertMatchesRegularExpression('/<button[^>]*id="sk-run"[^>]*>\s*이어서 수집\s*<\/button>/', $html);
        $this->assertStringNotContainsString('id="sk-stop"', $html, '진행 카드(중단/이어서)는 확인할 조합이 있을 때만 있다');
        // 눌렀을 때: 진행 카드가 없으면 막지 않고 안내만, 있으면 기존 「이어서」(sk-stop)로 재개
        $this->assertStringContainsString("document.getElementById('sk-run')", $html);
        $this->assertStringContainsString("runNote.textContent = '확인할 조합이 없어요'", $html);
        $this->assertStringContainsString('stopBtn.click()', $html);
    }

    public function test_run_button_says_start_when_paused_and_keeps_the_existing_resume_when_combos_remain(): void
    {
        $u = $this->admin();
        $paused = $this->analysis($u, 'paused', [0, null]);
        $html = $this->page($u, $paused);
        $this->assertMatchesRegularExpression('/<button[^>]*id="sk-run"[^>]*>\s*수집 시작\s*<\/button>/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*id="sk-stop"[^>]*>\s*이어서\s*<\/button>/', $html);   // 기존 진행 카드의 「이어서」는 그대로
        $pausedEmpty = $this->analysis($u, 'paused', [0, 3]);
        $this->assertMatchesRegularExpression('/<button[^>]*id="sk-run"[^>]*>\s*수집 시작\s*<\/button>/', $this->page($u, $pausedEmpty));
    }

    public function test_run_button_is_hidden_for_the_other_statuses(): void
    {
        $u = $this->admin();
        foreach (['done', 'ad_ended', 'target_reached', 'failed', 'pending'] as $status) {
            $a = $this->analysis($u, $status, [0, 2]);
            $this->assertStringNotContainsString('id="sk-run"', $this->page($u, $a), $status);
        }
    }
}
