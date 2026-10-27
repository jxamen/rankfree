<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ExtAuthController;
use App\Http\Controllers\Api\ExtShopKeywordController;
use App\Http\Controllers\Api\ExtShoppingSeoController;
use App\Http\Middleware\AuthenticateExtToken;
use App\Http\Middleware\BlockProbeIps;
use App\Models\User;
use Tests\TestCase;

class ExtThrottleIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(BlockProbeIps::class);
        // No credentials, database writes or real controller side effects are needed.
        $this->mock(AuthenticateExtToken::class, function ($mock) {
            $mock->shouldReceive('handle')->andReturnUsing(function ($request, $next) {
                $user = new User;
                $user->id = 123;
                $request->setUserResolver(fn () => $user);

                return $next($request);
            });
        });
        foreach ([ExtAuthController::class => ['login'], ExtShopKeywordController::class => ['checkQueue', 'productQueue'], ExtShoppingSeoController::class => ['analyze']] as $class => $methods) {
            $this->mock($class, function ($mock) use ($methods) {
                foreach ($methods as $method) {
                    $mock->shouldReceive($method)->andReturnUsing(fn () => response()->json(['ok' => true]));
                }
            });
        }
    }

    public function test_polling_does_not_consume_login_or_shopping_seo_budget(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/ext/shop-keyword/check-queue')->assertOk();
        }
        $this->getJson('/api/ext/shop-keyword/check-queue')->assertTooManyRequests();
        $this->postJson('/api/ext/login')->assertOk()->assertHeader('X-RateLimit-Remaining', '9');
        $this->postJson('/api/ext/shopping-seo')->assertOk()->assertHeader('X-RateLimit-Remaining', '19');
        $this->getJson('/api/ext/shop-keyword/product-queue')->assertOk();
    }

    public function test_login_still_blocks_the_eleventh_attempt_from_same_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/ext/login')->assertOk();
        }
        $this->postJson('/api/ext/login')->assertTooManyRequests()->assertHeader('Retry-After');
        $this->postJson('/api/ext/shopping-seo')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->postJson('/api/ext/login')->assertOk();
    }

    public function test_login_budget_recovers_after_one_minute(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/ext/login')->assertOk();
        }
        $this->postJson('/api/ext/login')->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->postJson('/api/ext/login')->assertOk();
    }

    public function test_shopping_seo_keeps_its_twenty_attempt_limit(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/ext/shopping-seo')->assertOk();
        }
        $this->postJson('/api/ext/shopping-seo')->assertTooManyRequests();
        $this->postJson('/api/ext/login')->assertOk();
    }

    public function test_every_throttled_extension_route_has_a_unique_bucket(): void
    {
        $prefixes = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/ext/')) {
                continue;
            }
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }
                $parts = explode(',', $middleware);
                $this->assertCount(3, $parts, $route->uri());
                $this->assertNotContains($parts[2], $prefixes, $route->uri());
                $prefixes[] = $parts[2];
            }
        }
        $this->assertCount(29, $prefixes);
    }
}
