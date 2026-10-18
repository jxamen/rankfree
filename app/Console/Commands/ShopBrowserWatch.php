<?php
namespace App\Console\Commands;

use App\Domain\Shopping\ShopBrowserWatchdog;
use Illuminate\Console\Command;

class ShopBrowserWatch extends Command
{
    protected $signature = 'shop-keyword:watch-browser';
    protected $description = 'Resume browser analyses on the server after 60 seconds without a response';
    public function handle(ShopBrowserWatchdog $watchdog): int
    {
        $this->info('Transferred: '.$watchdog->scan());
        return self::SUCCESS;
    }
}
