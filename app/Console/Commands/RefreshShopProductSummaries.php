<?php

namespace App\Console\Commands;

use App\Domain\Shopping\ShopProductSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RefreshShopProductSummaries extends Command
{
    protected $signature = 'shop-products:refresh-summaries';

    protected $description = 'Backfill/reconcile the collected product list index in bounded batches';

    public function handle(ShopProductSummary $summary): int
    {
        $lock = Cache::lock('shop-products:refresh-summaries', 7200);
        if (! $lock->get()) {
            $this->error('Another summary rebuild is running.');

            return self::FAILURE;
        }
        try {
            $count = 0;
            DB::table('shop_products')->select('product_key')->orderBy('product_key')
                ->chunkById(300, function ($products) use ($summary, &$count) {
                    $summary->refresh($products->pluck('product_key')->all());
                    $count += $products->count();
                    if ($count % 30000 === 0) {
                        $this->line((string) $count);
                    }
                }, 'product_key');
            DB::table('shop_product_summaries')->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('shop_products')->whereColumn('shop_products.product_key', 'shop_product_summaries.product_key'))->delete();
            $this->info('Refreshed '.$count.' products.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
