<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use App\Services\ShopExposureBlockAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 수집 방식(「탭 없이 확장이 통합검색 바로 분석」) 선택이 저장해도 풀리던 것 — 내 계정(서버)에도 저장해 유지한다(대표님 10-27 17:21). */
class ShopCollectionModePersistTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'api_scopes' => ['shop_keyword']]);
    }

    private function analysis(User $user, string $via = 'admin'): ShopKeywordAnalysis
    {
        $a = ShopKeywordAnalysis::create(['user_id' => $user->id, 'created_via' => $via, 'core_keyword' => '가짜피', 'status' => 'checking',
            'check_method' => 'search', 'product_id' => '111', 'product_url' => 'https://smartstore.naver.com/x/products/111', 'threshold' => 5]);
        $a->items()->create(['kind' => 'combo', 'source' => 'combo', 'keyword' => '조합']);

        return $a;
    }

    private function save(User $user, ShopKeywordAnalysis $a, array $extra = [])
    {
        return $this->actingAs($user)->postJson(route('admin.shop-keyword.interval', $a), ['seconds' => 3.5] + $extra);
    }

    public function test_the_chosen_mode_is_saved_per_user_and_survives_other_users_and_defaults(): void
    {
        $u = $this->admin();
        $other = $this->admin();
        $a = $this->analysis($u);
        $this->assertNull(ShopExposureBlockAlert::savedCollectionMode($u->id));
        $this->assertSame('tab', ShopExposureBlockAlert::collectionModeFor($u->id));   // 고른 적이 없으면 전체 기본값

        $this->save($u, $a, ['collection_mode' => 'fetch'])->assertOk();
        $this->assertSame('fetch', ShopExposureBlockAlert::savedCollectionMode($u->id));
        $this->assertSame('fetch', ShopExposureBlockAlert::collectionModeFor($u->id));
        $this->assertSame('tab', ShopExposureBlockAlert::collectionModeFor($other->id), '다른 사용자는 영향이 없다');
        $this->assertSame('tab', ShopExposureBlockAlert::collectionMode(), '전체 기본값은 그대로다');

        // 방식을 안 보낸 저장(주문 목표만 저장 등)은 고른 값을 지우지 않는다
        $this->save($u, $a)->assertOk();
        $this->assertSame('fetch', ShopExposureBlockAlert::savedCollectionMode($u->id));
        // 다시 고르면 그 값이 유지된다
        $this->save($u, $a, ['collection_mode' => 'tab'])->assertOk();
        $this->assertSame('tab', ShopExposureBlockAlert::savedCollectionMode($u->id));
        $this->save($u, $a, ['collection_mode' => 'fetch'])->assertOk();
        $this->assertSame('fetch', ShopExposureBlockAlert::collectionModeFor($u->id));
    }

    public function test_invalid_mode_is_rejected_and_the_saved_value_is_kept(): void
    {
        $u = $this->admin();
        $a = $this->analysis($u);
        $this->save($u, $a, ['collection_mode' => 'fetch'])->assertOk();
        $this->save($u, $a, ['collection_mode' => 'server'])->assertUnprocessable();
        $this->assertSame('fetch', ShopExposureBlockAlert::savedCollectionMode($u->id));
    }

    public function test_extension_policy_and_queue_follow_the_users_choice_so_a_fresh_extension_starts_with_it(): void
    {
        $u = $this->admin();
        $a = $this->analysis($u, 'api');   // 확장 대기열이 바로 집어 가는 분석
        $other = $this->admin();
        $this->save($u, $a, ['collection_mode' => 'fetch'])->assertOk();

        [, $ext] = ExtToken::issue($u);
        $this->withToken($ext)->getJson('/api/ext/shop-keyword/interval-policy')->assertOk()->assertJsonPath('data.collection_mode', 'fetch');
        $this->withToken($ext)->getJson('/api/ext/shop-keyword/check-queue')->assertOk()->assertJsonPath('data.analysis_id', $a->id)->assertJsonPath('data.collection_mode', 'fetch');

        [, $otherExt] = ExtToken::issue($other);
        $this->withToken($otherExt)->getJson('/api/ext/shop-keyword/interval-policy')->assertOk()->assertJsonPath('data.collection_mode', 'tab');
    }

    public function test_detail_screen_shows_the_saved_mode_and_loads_it_ahead_of_the_extension_storage(): void
    {
        $u = $this->admin();
        $a = $this->analysis($u);
        $html = $this->actingAs($u)->get(route('admin.shop-keyword.show', $a))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="tab" selected>/', $html);
        $this->assertStringContainsString('const savedMode = null;', $html);

        $this->save($u, $a, ['collection_mode' => 'fetch'])->assertOk();
        $html = $this->actingAs($u)->get(route('admin.shop-keyword.show', $a))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="fetch" selected>/', $html);
        $this->assertMatchesRegularExpression('/const savedMode = [^;\n]*fetch[^;\n]*;/', $html);   // Js::from 이 따옴표를 이스케이프해 정확한 글자는 맞추지 않는다
        // 저장된 선택이 확장 저장소 · 브라우저 값보다 우선한다
        $this->assertStringContainsString('if (savedMode && browserSettings.collection_mode !== savedMode) browserSettings = {...browserSettings, collection_mode: savedMode};', $html);
        // 저장은 서버(계정)에 먼저, 그다음 확장 — 확장이 거절(옛 버전)해도 고른 값은 남는다
        $this->assertLessThan(strpos($html, 'await persistBrowserSettings({interval_ms:Math.round(Number(intervalInput.value)'), strpos($html, 'collection_mode:mode});'));
        // 확장이 그 방식을 못 받으면 업데이트 안내
        $this->assertStringContainsString('확장을 0.6.9.7 로 업데이트하세요', $html);
    }
}
