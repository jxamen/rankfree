<?php

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ShopKeywordAutoEndServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register separately: the production console routes contain protected local changes.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) {
            $schedule->command('shop-keyword:watch-browser')->everyMinute()->withoutOverlapping();
            $schedule->command('shop-keyword:auto-end')->timezone('Asia/Seoul')
                ->dailyAt('00:10')->withoutOverlapping()->runInBackground();
        });
    }
}
