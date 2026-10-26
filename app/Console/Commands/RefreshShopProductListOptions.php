<?php

namespace App\Console\Commands;

use App\Domain\Shopping\ShopProductListOptions;
use Illuminate\Console\Command;

class RefreshShopProductListOptions extends Command
{
    protected $signature = 'shop-products:refresh-options';

    protected $description = 'Refresh the persisted mall/month filter options outside HTTP requests';

    public function handle(ShopProductListOptions $options): int
    {
        $options->refresh();
        $this->info('Product list options refreshed.');

        return self::SUCCESS;
    }
}
