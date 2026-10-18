<?php
namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopExposureCollectorEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_requires_auth_scope_ownership_and_valid_kind(): void
    {
        Http::fake();
        config(['services.slack.notifications.bot_user_oauth_token' => null]);
        $owner = User::factory()->create(['role' => 'super']);
        $a = ShopKeywordAnalysis::create(['user_id' => $owner->id, 'core_keyword' => '테스트', 'product_url' => 'https://example.com', 'status' => 'checking']);
        $url = '/api/ext/shop-keyword/'.$a->id.'/collector-event';
        $body = ['kind' => '403', 'collector' => 'screen'];
        $this->postJson($url, $body)->assertUnauthorized();
        [, $plain] = ExtToken::issue($owner);
        $headers = ['Authorization' => 'Bearer '.$plain];
        $this->postJson($url, $body, $headers)->assertOk();
        $this->postJson($url, ['kind' => 'arbitrary', 'collector' => 'screen'], $headers)->assertUnprocessable();
        $other = User::factory()->create(['role' => 'super']);
        [, $otherPlain] = ExtToken::issue($other);
        $this->postJson($url, $body, ['Authorization' => 'Bearer '.$otherPlain])->assertForbidden();
        $regular = User::factory()->create();
        [, $regularPlain] = ExtToken::issue($regular);
        $this->postJson($url, $body, ['Authorization' => 'Bearer '.$regularPlain])->assertForbidden();
        Http::assertNothingSent();
    }
}
