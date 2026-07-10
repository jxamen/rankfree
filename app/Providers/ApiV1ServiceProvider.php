<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** rankfree API v1 라우트 로더 — routes/api.php(확장 세션 소유) 무접촉으로 추가 API 등록. */
class ApiV1ServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(base_path('routes/apiv1.php'));
    }
}
