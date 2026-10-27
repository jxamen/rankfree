<?php

namespace Tests\Feature;

use App\Domain\Shopping\IntegratedShopCollector;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegratedShopApiAuthTest extends TestCase
{
    use RefreshDatabase;

    private function headers(array $scopes = ['integrated_shop_rank'], array $userScopes = ['integrated_shop_rank'], ?int $limit = null): array
    {
        $user = User::factory()->create(['api_scopes' => $userScopes]);
        [, $plain] = ApiKey::issue($user, 'Integrated test', $scopes, null, $limit, null);

        return ['Authorization' => 'Bearer '.$plain];
    }

    private function input(): array
    {
        return ['keywords' => ['캠핑의자'], 'target' => ['type' => 'nvMid', 'value' => '123']];
    }

    public function test_key_and_member_permissions_apply_before_collection(): void
    {
        Http::preventStrayRequests();
        $url = '/api/v1/integrated-shop-rank';
        $this->postJson($url, $this->input())->assertUnauthorized();
        $this->postJson($url, $this->input(), $this->headers(['rank']))->assertForbidden();
        $this->postJson($url, $this->input(), $this->headers(['integrated_shop_rank'], []))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_authorized_endpoint_and_daily_limit(): void
    {
        $this->mock(IntegratedShopCollector::class, function ($mock) {
            $mock->shouldReceive('collect')->once()->with('캠핑의자', 'mobile')->andReturn(['status' => 'ok', 'area_code' => 'shp_gui', 'cached' => false, 'fetched_at' => now()->toIso8601String(), 'items' => [['nvMid' => '123', 'rank' => 1, 'organic_rank' => 1, 'is_ad' => false]]]);
        });
        $headers = $this->headers(limit: 1);
        $this->postJson('/api/v1/integrated-shop-rank', $this->input(), $headers)->assertOk()->assertJsonMissingPath('data.0.pc')->assertJsonPath('data.0.mobile.rank', 1);
        $this->postJson('/api/v1/integrated-shop-rank', $this->input(), $headers)->assertStatus(429);
    }

    public function test_console_scope_checkbox_and_documentation(): void
    {
        $user = User::factory()->create(['api_scopes' => ['integrated_shop_rank']]);
        $this->actingAs($user)->get('/console/api-keys')->assertOk()->assertSee('value="integrated_shop_rank"', false)->assertSee('통합검색 쇼핑 순위');
        $this->get('/developers')->assertOk()->assertSee('/api/v1/integrated-shop-rank')->assertSee('parse_failed')->assertSee('organic_rank');
    }
}
