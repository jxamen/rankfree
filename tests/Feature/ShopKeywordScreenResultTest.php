<?php

namespace Tests\Feature;

use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordAnalysisItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopKeywordScreenResultTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(): ShopKeywordAnalysis
    {
        $user = User::factory()->create(['role' => 'operator']);
        $this->actingAs($user);
        return ShopKeywordAnalysis::create(['user_id' => $user->id, 'core_keyword' => '가면',
            'product_id' => '111', 'product_url' => 'https://smartstore.naver.com/a/products/111',
            'threshold' => 5, 'status' => 'checking', 'check_method' => 'search', 'combo_count' => 3]);
    }

    private function item(ShopKeywordAnalysis $a, string $keyword, array $extra = []): ShopKeywordAnalysisItem
    {
        return ShopKeywordAnalysisItem::create($extra + ['analysis_id' => $a->id, 'kind' => 'combo',
            'source' => 'combo', 'keyword' => $keyword]);
    }

    public function test_read_failures_skip_to_next_keyword_without_recording_non_exposure(): void
    {
        $a = $this->analysis();
        $one = $this->item($a, '실패');
        $two = $this->item($a, '다음');
        $url = route('admin.shop-keyword.pending', $a);
        $this->getJson($url.'?runner=test&exclude[]='.$one->id)->assertOk()->assertJsonPath('data.items.0.id', $two->id);
        $this->assertNull($one->fresh()->rank);
        $this->getJson($url.'?runner=test&exclude[]='.$one->id.'&exclude[]='.$two->id)->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson($url.'?runner=test')->assertOk()->assertJsonPath('data.items.0.id', $one->id);
    }

    public function test_one_result_updates_one_combination_and_preserves_screen_on_retry(): void
    {
        $a = $this->analysis();
        $one = $this->item($a, '가면 공포');
        $two = $this->item($a, '가면 호러');
        $html = '<script>var _INITIAL_STATE = '.json_encode(['initProps' => ['pagedSlot' => [['slots' => [
            ['data' => ['sourceType' => 'AD', 'channelProductId' => '111', 'rank' => 1]],
            ['data' => ['sourceType' => 'SAS', 'channelProductId' => '111', 'rank' => 5]],
        ]]]]]).';</script>';
        $url = route('admin.shop-keyword.check-html', $a);
        $data = ['item_id' => $one->id, 'html' => $html, 'result_source' => 'integrated', 'runner' => 'one'];
        $this->postJson($url, $data)->assertOk()->assertJsonPath('checked', 1)->assertJsonPath('judged_count', 1)
            ->assertJsonPath('rank', 5)->assertJsonPath('ad', true)->assertJsonPath('result_source', 'integrated')
            ->assertJsonCount(1, 'exposed_items');
        $this->assertNull($two->fresh()->rank);
        $this->postJson($url, array_replace($data, ['result_source' => 'shopping']))->assertOk()
            ->assertJsonPath('skipped', true)->assertJsonPath('checked', 1);
        $this->assertSame('integrated', $one->fresh()->result_source);
        $this->assertSame(1, $a->fresh()->checked_count);
    }

    public function test_pending_snapshot_includes_results_from_other_runner_and_excludes_hidden(): void
    {
        $a = $this->analysis();
        $one = $this->item($a, '가면 공포', ['rank' => 2, 'checked_at' => now()->subMinute(), 'result_source' => 'shopping']);
        $two = $this->item($a, '가면 호러', ['rank' => 1, 'checked_at' => now(), 'result_source' => 'integrated']);
        $this->item($a, '감춘 키워드', ['rank' => 1, 'hidden' => true]);
        $this->item($a, '아직 미확인');
        $a->update(['checked_count' => 103, 'exposed_count' => 66]);
        $this->getJson(route('admin.shop-keyword.pending', $a).'?runner=screen')->assertOk()
            ->assertJsonPath('data.checked', 2)->assertJsonPath('data.exposed', 2)
            ->assertJsonCount(2, 'data.exposed_items')->assertJsonPath('data.exposed_items.0.id', $one->id)
            ->assertJsonPath('data.exposed_items.1.id', $two->id)->assertJsonPath('data.target.product_id', '111');
        $view = $this->get(route('admin.shop-keyword.show', $a))->assertOk();
        $view->assertSee('id="sk-sum-exposed" style="font-size:var(--fs-xl);">2</div>', false)
            ->assertSee('검색 1번 = 조합 1개 판정')->assertSee('통합')->assertSee('쇼핑');
    }

    public function test_invalid_result_screen_is_rejected(): void
    {
        $a = $this->analysis();
        $item = $this->item($a, '가면 공포');
        $this->postJson(route('admin.shop-keyword.check-html', $a), ['item_id' => $item->id,
            'html' => '', 'result_source' => 'unknown'])->assertUnprocessable();
        $this->assertNull($item->fresh()->rank);
    }
    public function test_both_check_html_controllers_store_each_source_and_legacy_default(): void
    {
        foreach (['page', 'extension'] as $path) {
            foreach (['integrated', 'shopping', null] as $source) {
                $a = $this->analysis();
                $user = User::findOrFail($a->user_id);
                $user->update(['api_scopes' => ['shop_keyword']]);
                $item = $this->item($a, '출처 테스트');
                $data = ['item_id' => $item->id, 'html' => '', 'runner' => 'source-test'];
                if ($source !== null) $data['result_source'] = $source;
                $url = route('admin.shop-keyword.check-html', $a);
                if ($path === 'extension') {
                    [, $token] = \App\Models\ExtToken::issue($user);
                    $this->withHeader('Authorization', 'Bearer '.$token);
                    $url = "/api/ext/shop-keyword/{$a->id}/check-html";
                }
                $this->postJson($url, $data)->assertOk()->assertJsonPath('result_source', $source ?? 'integrated');
                $this->assertSame($source ?? 'integrated', $item->fresh()->result_source);
                app(\App\Domain\Shopping\ShopExposureRunLock::class)->release('ip:127.0.0.1', $a->id);
            }
        }
    }

}
