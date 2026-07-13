<?php

use App\Providers\ApiV1ServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\SettingsServiceProvider;

return [
    AppServiceProvider::class,
    ApiV1ServiceProvider::class,
    SettingsServiceProvider::class,
];
