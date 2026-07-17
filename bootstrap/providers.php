<?php

use App\Providers\ApiV1ServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\SettingsServiceProvider;

return [
    AppServiceProvider::class,
    ApiV1ServiceProvider::class,
    SettingsServiceProvider::class,
    // 이식형 GA4 상세 분석 패키지(packages/ga4-insights) — 다른 앱에선 composer auto-discovery로 등록됨
    Jcurve\Ga4Insights\Ga4InsightsServiceProvider::class,
];
