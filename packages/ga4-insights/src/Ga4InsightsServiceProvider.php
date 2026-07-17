<?php

namespace Jcurve\Ga4Insights;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Jcurve\Ga4Insights\Contracts\Ga4Credentials;
use Jcurve\Ga4Insights\Http\Ga4DashboardController;
use Jcurve\Ga4Insights\Support\ConfigGa4Credentials;

class Ga4InsightsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ga4-insights.php', 'ga4-insights');

        // 자격증명 — 앱이 자체 impl 을 먼저 바인딩하면 그걸 쓰고, 없으면 config 기반 기본 impl.
        $this->app->bindIf(Ga4Credentials::class, ConfigGa4Credentials::class);

        $this->app->singleton(Ga4Client::class, fn ($app) => new Ga4Client($app->make(Ga4Credentials::class)));
        $this->app->singleton(Ga4Reporter::class, fn ($app) => new Ga4Reporter(
            $app->make(Ga4Client::class),
            $app->make(Ga4Credentials::class),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ga4-insights');

        if (config('ga4-insights.route.enabled', true)) {
            $this->registerRoutes();
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/ga4-insights.php' => config_path('ga4-insights.php')], 'ga4-insights-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/ga4-insights')], 'ga4-insights-views');
        }
    }

    private function registerRoutes(): void
    {
        $cfg = config('ga4-insights.route');
        $name = $cfg['name'] ?? 'ga4-insights';
        Route::group([
            'prefix' => $cfg['prefix'] ?? 'ga4-insights',
            'middleware' => $cfg['middleware'] ?? ['web'],
        ], function () use ($name) {
            Route::get('/', [Ga4DashboardController::class, 'index'])->name($name);
            Route::post('/refresh', [Ga4DashboardController::class, 'refresh'])->name($name.'.refresh');
        });
    }
}
