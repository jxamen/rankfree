<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ShopCaptchaEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_remote_rule_is_authenticated_and_clamped_data(): void
    {
        $u = User::factory()->create(['role' => 'operator', 'api_scopes' => ['shop_keyword']]);
        [, $token] = ExtToken::issue($u);
        $this->getJson('/api/ext/shop-keyword/interval-policy')->assertUnauthorized();
        foreach (['50' => 200, '1000' => 1000, '90000' => 4000] as $configured => $expected) {
            \App\Models\AppSetting::write('shop.captcha.max_page_text_chars', (string) $configured);
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/ext/shop-keyword/interval-policy')
                ->assertOk()->assertJsonPath('data.captcha_rules.max_page_text_chars', $expected);
        }
    }

    public function test_browser_and_api_log_only_bounded_evidence_and_reject_raw_urls_or_text(): void
    {
        Cache::flush();
        config(['services.slack.notifications.bot_user_oauth_token' => null]);
        Log::spy();
        $u = User::factory()->create(['role' => 'operator', 'api_scopes' => ['shop_keyword']]);
        $a = ShopKeywordAnalysis::create(['user_id' => $u->id, 'core_keyword' => 'test', 'status' => 'checking']);
        [, $token] = ExtToken::issue($u);
        $evidence = ['version' => 'visible-v1', 'signal' => 'visible-challenge', 'url_pattern' => 'naver-captcha-page', 'visible' => true];
        foreach ([route('admin.shop-keyword.collector-event', $a), "/api/ext/shop-keyword/{$a->id}/collector-event"] as $url) {
            $this->actingAs($u)->withHeader('Authorization', 'Bearer '.$token)
                ->postJson($url, ['kind' => '보안문자', 'evidence' => $evidence])->assertOk();
            foreach ([['url' => 'https://nid.naver.com/?secret=private'], ['signal' => 'raw personal text'], ['visible' => 'arbitrary']] as $invalid) {
                $this->postJson($url, ['kind' => '보안문자', 'evidence' => array_replace($evidence, $invalid)])->assertUnprocessable();
            }
        }
        Log::shouldHaveReceived('info')->with('shop_exposure_block_event', \Mockery::on(fn ($e) => $e['evidence'] === $evidence))->twice();
    }
}
