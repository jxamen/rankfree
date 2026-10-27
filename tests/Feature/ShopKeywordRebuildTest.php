<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 「새 조합」은 그 분석의 조합을 전부 지우고 처음부터 다시 만든다 · 핵심어 속 글자는 금지어가 아니다 · 고른 상태를 시스템이 done 으로 되돌리지 않는다
 * (대표님 10-27 17:29~17:32 — 125번 「가짜피」가 금지어 「가짜」 때문에 조합 46개뿐이던 사고).
 */
class ShopKeywordRebuildTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rankfree.shopping.exposure.negatives' => ['가짜', '짝퉁'], 'rankfree.shopping.exposure.max_tokens' => 4]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'api_scopes' => ['shop_keyword']]);
    }

    /** 125번을 닮은 분석 — 핵심어 「가짜피」, 제목 단어 토큰 3개. 조합 순위: 키워드 => 순위(null = 아직 확인 안 함) */
    private function analysis(User $user, string $status, array $combos = [], array $tokens = ['굳은', '진한', '할로윈'], array $extra = []): ShopKeywordAnalysis
    {
        $a = ShopKeywordAnalysis::create(['user_id' => $user->id, 'created_via' => 'admin', 'core_keyword' => '가짜피', 'status' => $status,
            'check_method' => 'search', 'product_id' => '111', 'product_url' => 'https://smartstore.naver.com/x/products/111', 'threshold' => 5,
            'product_title' => '굳은 피 진한 가짜피 할로윈 분장', 'brand' => '스투피드', 'combo_count' => count($combos)] + $extra);
        $a->forceFill(['created_at' => now()->subDays(2), 'collection_source' => '확장 수집기', 'collection_seen_at' => now()->subDays(1)])->save();
        foreach ($tokens as $kw) {
            $a->items()->create(['kind' => 'token', 'source' => 'title', 'keyword' => $kw]);
        }
        foreach ($combos as $kw => $rank) {
            $item = $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => $kw, 'combo_tag' => 'title', 'hidden' => $rank === 'hidden']);
            if ($rank !== null) {
                $item->forceFill(['rank' => $rank === 'hidden' ? 0 : $rank, 'checked_at' => now()->subDay()])->save();
            }
        }

        return $a;
    }

    public function test_core_keyword_containing_a_negative_word_still_gets_core_combos_but_other_negatives_stay_blocked(): void
    {
        $u = $this->admin();
        $a = $this->analysis($u, 'done', [], ['굳은', '진한', '짝퉁']);   // '짝퉁' 은 핵심어 밖의 금지어 — 계속 걸러진다

        $r = app(ShopKeywordExposureAnalyzer::class)->rebuild($a);

        $this->assertGreaterThan(0, $r['added']);
        $titleCombos = $a->combos()->where('combo_tag', 'title')->pluck('keyword')->all();
        $this->assertContains('가짜피 굳은', $titleCombos, '핵심어 「가짜피」 속 「가짜」는 금지어가 아니다');
        $this->assertContains('가짜피 굳은 진한', $titleCombos);
        foreach ($a->combos()->pluck('keyword') as $kw) {
            $this->assertStringNotContainsString('짝퉁', $kw);
        }
    }

    public function test_new_combos_button_deletes_everything_and_rebuilds_from_scratch_keeping_a_json_backup(): void
    {
        Storage::fake('local');
        $u = $this->admin();
        $a = $this->analysis($u, 'done', ['가짜피 굳은' => 1, '가짜피 진한' => 0, '가짜피 숨김' => 'hidden', '가짜피 미확인' => null]);
        $a->forceFill(['checked_count' => 3, 'exposed_count' => 1])->save();

        $r = $this->actingAs($u)->post(route('admin.shop-keyword.regenerate', $a))->assertOk();

        $this->assertGreaterThan(0, (int) $r->json('added'));
        $this->assertNull($r->json('message'));
        // 확인된 것 · 숨긴 것 · 미확인 모두 지우고 처음부터 — 이미 확인한 키워드도 빼지 않고 다시 만든다
        $this->assertSame(0, $a->allCombos()->where('hidden', true)->count());
        $this->assertSame(0, $a->allCombos()->whereNotNull('rank')->count(), '순위는 모두 미확인으로 처음부터');
        $this->assertDatabaseMissing('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'keyword' => '가짜피 숨김']);
        $this->assertDatabaseMissing('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'keyword' => '가짜피 미확인']);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'keyword' => '가짜피 굳은', 'rank' => null, 'hidden' => 0]);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'keyword' => '가짜피 진한', 'rank' => null]);
        // 만든 뒤 수집 중으로 — 바로 돌고, 확장 대기열이 집어 간다
        $fresh = $a->fresh();
        $this->assertSame('checking', $fresh->status);
        $this->assertSame($a->combos()->count(), $fresh->combo_count);
        $this->assertSame(0, $fresh->checked_count);
        $this->assertSame(0, $fresh->exposed_count);
        $this->assertTrue($fresh->collection_seen_at->gt(now()->subMinute()));
        [, $ext] = ExtToken::issue($u);
        $this->withToken($ext)->getJson('/api/ext/shop-keyword/check-queue')->assertOk()->assertJsonPath('data.analysis_id', $a->id);
        // 지우기 전 기존 조합 · 순위를 JSON 으로 백업(막지 않고 저장만)
        $files = Storage::disk('local')->files('shop-keyword-backups');
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('shop-keyword-backups/'.$a->id.'-', $files[0]);
        $backup = json_decode(Storage::disk('local')->get($files[0]), true);
        $this->assertSame(4, $backup['combo_count']);
        $byKeyword = collect($backup['combos'])->keyBy('keyword');
        $this->assertSame(1, $byKeyword['가짜피 굳은']['rank']);
        $this->assertSame(0, $byKeyword['가짜피 진한']['rank']);
        $this->assertTrue((bool) $byKeyword['가짜피 숨김']['hidden']);
        $this->assertNull($byKeyword['가짜피 미확인']['rank']);
    }

    public function test_new_combos_never_blocks_a_stuck_analysis_like_125(): void
    {
        // 조합이 전부 확인됐고(미확인 0) 상태가 done 으로 돌아가 있던 125번 — 누르면 막는 안내 없이 새로 만들어 수집 중이 된다
        Storage::fake('local');
        $u = $this->admin();
        $a = $this->analysis($u, 'done', ['굳은 피' => 0, '진한 피' => 2]);

        $r = $this->actingAs($u)->post(route('admin.shop-keyword.regenerate', $a))->assertOk();

        $this->assertGreaterThan(0, (int) $r->json('added'));
        $this->assertGreaterThan(0, $a->combos()->whereNull('rank')->count());
        $this->assertSame('checking', $a->fresh()->status);
    }

    public function test_rebuild_without_material_deletes_nothing_and_keeps_the_status(): void
    {
        Storage::fake('local');
        $u = $this->admin();
        $a = $this->analysis($u, 'checking', ['굳은 피' => 0], []);   // 재료(토큰 · 제목 · 태그)가 없다
        $a->forceFill(['product_title' => null, 'brand' => null])->save();

        $r = $this->actingAs($u)->post(route('admin.shop-keyword.regenerate', $a))->assertOk();

        $this->assertSame(0, (int) $r->json('added'));
        $this->assertNotEmpty($r->json('message'));
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'keyword' => '굳은 피', 'rank' => 0]);
        $this->assertSame('checking', $a->fresh()->status);
        $this->assertSame([], Storage::disk('local')->files('shop-keyword-backups'));
    }

    public function test_ended_and_target_reached_analyses_are_left_alone(): void
    {
        Storage::fake('local');
        $u = $this->admin();
        $ended = $this->analysis($u, 'ad_ended', ['굳은 피' => 1]);
        $this->actingAs($u)->post(route('admin.shop-keyword.regenerate', $ended))->assertStatus(409);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $ended->id, 'keyword' => '굳은 피', 'rank' => 1]);

        $reached = $this->analysis($u, 'checking', ['굳은 피' => 1], ['굳은'], ['keyword_target' => 1]);
        $r = $this->actingAs($u)->post(route('admin.shop-keyword.regenerate', $reached))->assertOk();
        $this->assertSame(0, (int) $r->json('added'));
        $this->assertNotEmpty($r->json('message'));
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $reached->id, 'keyword' => '굳은 피', 'rank' => 1]);
    }

    public function test_only_the_owner_can_rebuild(): void
    {
        Storage::fake('local');
        $a = $this->analysis($this->admin(), 'done', ['굳은 피' => 0]);

        $this->actingAs($this->admin())->post(route('admin.shop-keyword.regenerate', $a))->assertForbidden();
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $a->id, 'keyword' => '굳은 피']);
    }

    public function test_choosing_collecting_with_nothing_left_to_check_builds_new_combos_and_the_queue_picks_it_up(): void
    {
        Storage::fake('local');
        $u = $this->admin();
        [, $ext] = ExtToken::issue($u);
        $a = $this->analysis($u, 'done', ['굳은 피' => 0, '진한 피' => 2]);   // 미확인 조합이 하나도 없다

        $this->actingAs($u)->postJson(route('admin.shop-keyword.status', $a), ['status' => 'checking'])
            ->assertOk()->assertJsonPath('data.status', 'checking')->assertJsonPath('data.changed', true);

        $this->assertGreaterThan(0, $a->combos()->whereNull('rank')->count());
        $this->assertSame('checking', $a->fresh()->status, '고른 상태가 그대로 유지된다');
        $this->withToken($ext)->getJson('/api/ext/shop-keyword/check-queue')->assertOk()->assertJsonPath('data.analysis_id', $a->id);
    }

    public function test_choosing_collecting_with_combos_left_does_not_rebuild_and_target_reached_is_untouched(): void
    {
        Storage::fake('local');
        $u = $this->admin();
        $left = $this->analysis($u, 'done', ['굳은 피' => 0, '진한 피' => null]);
        $this->actingAs($u)->postJson(route('admin.shop-keyword.status', $left), ['status' => 'checking'])->assertOk()->assertJsonPath('data.rebuilt', 0);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $left->id, 'keyword' => '굳은 피', 'rank' => 0]);

        $reached = $this->analysis($u, 'target_reached', ['굳은 피' => 1], ['굳은'], ['keyword_target' => 1]);
        $this->actingAs($u)->postJson(route('admin.shop-keyword.status', $reached), ['status' => 'checking'])->assertOk()->assertJsonPath('data.rebuilt', 0);
        $this->assertDatabaseHas('shop_keyword_analysis_items', ['analysis_id' => $reached->id, 'keyword' => '굳은 피', 'rank' => 1]);
    }

    public function test_auto_regenerate_with_no_new_combos_never_flips_the_chosen_status_to_done(): void
    {
        $u = $this->admin();
        $analyzer = app(ShopKeywordExposureAnalyzer::class);

        // 새 조합이 0개여도(재료 없음) 고른 수집 중 · 일시정지는 그대로 — 몇 초 뒤 done 으로 되돌아가던 125번 사고
        foreach (['checking', 'paused'] as $status) {
            $a = $this->analysis($u, $status, ['굳은 피' => 0], []);
            $a->forceFill(['product_title' => null, 'brand' => null])->save();
            $this->assertSame(0, $analyzer->regenerate($a)['added']);
            $this->assertSame($status, $a->fresh()->status, $status);
        }
        // 이미 완료였던 건 그대로 완료
        $done = $this->analysis($u, 'done', ['굳은 피' => 0], []);
        $done->forceFill(['product_title' => null, 'brand' => null])->save();
        $analyzer->regenerate($done);
        $this->assertSame('done', $done->fresh()->status);
    }
}
