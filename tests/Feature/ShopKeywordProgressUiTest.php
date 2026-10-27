<?php

namespace Tests\Feature;

use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 상세 화면 — 이름 · 진행 줄 문구 · 5위 숫자 실시간 · 동시 n개 표시 · 관리 요청 한도(대표님 10-27 17:49 · 17:56). */
class ShopKeywordProgressUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'api_scopes' => ['shop_keyword']]);
    }

    private function analysis(User $user, string $status = 'checking'): ShopKeywordAnalysis
    {
        $a = ShopKeywordAnalysis::create(['user_id' => $user->id, 'created_via' => 'admin', 'core_keyword' => '가짜피', 'status' => $status,
            'check_method' => 'search', 'product_id' => '111', 'product_url' => 'https://smartstore.naver.com/x/products/111', 'threshold' => 5,
            'product_title' => '굳은 피 진한 가짜피 할로윈', 'brand' => '스투피드', 'combo_count' => 3]);
        $a->forceFill(['collection_source' => '확장 수집기', 'collection_seen_at' => now()])->save();
        foreach (['가짜피 굳은' => 2, '가짜피 진한' => 0, '가짜피 할로윈' => null] as $kw => $rank) {
            $item = $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => $kw]);
            if ($rank !== null) {
                $item->forceFill(['rank' => $rank, 'checked_at' => now()])->save();
            }
        }

        return $a;
    }

    public function test_setting_label_is_renamed_to_concurrent_search_count(): void
    {
        $u = $this->admin();
        $html = $this->actingAs($u)->get(route('admin.shop-keyword.show', $this->analysis($u)))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<label for="sk-parallel-tabs">동시 검색 수<\/label>/', $html);
        $this->assertStringNotContainsString('동시 검색 탭 수', $html);
    }

    public function test_progress_line_follows_the_real_collection_mode_and_shows_live_concurrency(): void
    {
        $u = $this->admin();
        $a = $this->analysis($u);
        $html = $this->actingAs($u)->get(route('admin.shop-keyword.show', $a))->assertOk()->getContent();

        // 「브라우저에서 순위 확인 중(확장)」 고정 문구 대신 실제 방식대로
        $this->assertStringNotContainsString('브라우저에서 순위 확인 중(확장)', $html);
        $this->assertStringContainsString('탭 없이 확장이 바로 확인 중', $html);
        $this->assertStringContainsString('브라우저 탭으로 확인 중', $html);
        // 확장이 지금 처리 중인 개수를 실시간으로
        $this->assertStringContainsString('id="sk-prog-active"', $html);
        $this->assertStringContainsString("extCall('shopSerpStatus'", $html);
        $this->assertStringContainsString("' · 지금 동시 ' + r.active + '개 확인 중'", $html);
    }

    public function test_header_top5_number_is_live_and_matches_the_card(): void
    {
        $u = $this->admin();
        $a = $this->analysis($u);
        $html = $this->actingAs($u)->get(route('admin.shop-keyword.show', $a))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/5위 이내 <span class="font-mono"><span id="sk-top5">1<\/span> \//', $html);
        $this->assertStringContainsString("document.getElementById('sk-top5')", $html);
        // 진행 응답이 같은 5위 숫자를 싣는다 — 카드(상위 노출)와 윗줄이 같은 값으로 갱신된다
        $this->actingAs($u)->getJson(route('admin.shop-keyword.pending', $a).'?policy_only=1')->assertOk()
            ->assertJsonPath('data.top5', 1)->assertJsonPath('data.exposed', 1);
    }

    public function test_combo_tag_label_for_related_terms_exists(): void
    {
        $u = $this->admin();
        $html = $this->actingAs($u)->get(route('admin.shop-keyword.show', $this->analysis($u)))->assertOk()->getContent();
        $this->assertStringContainsString('"related":"', $html);   // @json 이 한글을  로 쓴다 — 라벨이 패턴 카드 목록(tagLabels)에 있는지만 본다
    }

    public function test_management_requests_are_not_blocked_by_the_collection_traffic_counter(): void
    {
        // 이름 없는 throttle 은 사용자별 카운터 하나를 공유한다 — 수집 중 pending/check-html 호출이 쌓이면 설정 저장이 429 가 되던 문제
        Storage::fake('local');
        $u = $this->admin();
        $a = $this->analysis($u);
        for ($i = 0; $i < 40; $i++) {
            $this->actingAs($u)->getJson(route('admin.shop-keyword.pending', $a).'?policy_only=1');
        }

        $this->actingAs($u)->postJson(route('admin.shop-keyword.interval', $a), ['seconds' => 3.5])->assertOk();
        $this->actingAs($u)->postJson(route('admin.shop-keyword.status', $a), ['status' => 'checking'])->assertOk();
        $this->actingAs($u)->postJson(route('admin.shop-keyword.regenerate', $a))->assertOk();
        $this->actingAs($u)->postJson(route('admin.shop-keyword.pause', $a), ['paused' => false])->assertOk();
    }
}
