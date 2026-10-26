<?php

namespace App\Domain\Shopping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopProductListOptions
{
    private function malls(): array
    {
        return DB::table('shop_products')->whereNotNull('mall_name')->selectRaw('mall_name, count(*) as c')
            ->groupBy('mall_name')->orderByDesc('c')->limit(50)->pluck('c', 'mall_name')->all();
    }

    private function months(): array
    {
        return DB::table('shop_product_summaries')->where('collected_month', '>', 0)
            ->distinct()->orderByDesc('collected_month')->pluck('collected_month')->all();
    }

    public function refresh(): void
    {
        DB::table('shop_product_list_options')->upsert([
            ['name' => 'malls', 'value' => json_encode($this->malls(), JSON_THROW_ON_ERROR), 'updated_at' => now()],
            ['name' => 'months', 'value' => json_encode($this->months(), JSON_THROW_ON_ERROR), 'updated_at' => now()],
        ], ['name'], ['value', 'updated_at']);
    }

    public function read(): array
    {
        // During rolling deployment the migration/first snapshot may not yet exist.
        if (Schema::hasTable('shop_product_list_options')) {
            $rows = DB::table('shop_product_list_options')->pluck('value', 'name');
            if ($rows->has('malls') && $rows->has('months')) {
                return ['malls' => json_decode($rows['malls'], true, 512, JSON_THROW_ON_ERROR),
                    'months' => json_decode($rows['months'], true, 512, JSON_THROW_ON_ERROR)];
            }
        }

        return [
            'malls' => Cache::flexible('shop_products:malls', [300, 86400], fn () => $this->malls()),
            'months' => Cache::flexible('shop_products:months', [300, 86400], fn () => $this->months()),
        ];
    }
}
