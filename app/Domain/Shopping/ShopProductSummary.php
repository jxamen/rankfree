<?php

namespace App\Domain\Shopping;

use Illuminate\Support\Facades\DB;

/** Derived list index. Source tables and their monthly partitions stay unchanged. */
class ShopProductSummary
{
    public function refresh(array $keys): void
    {
        $keys = array_values(array_unique($keys));
        sort($keys);
        foreach (array_chunk($keys, 300) as $chunk) {
            DB::transaction(function () use ($chunk) {
                // Serialize competing refreshes of the same products; read source after locking.
                $products = DB::table('shop_products')->whereIn('product_key', $chunk)
                    ->orderBy('product_key')->lockForUpdate()->get()->keyBy('product_key');
                $base = DB::table('keyword_shop_ranks')->whereIn('product_key', $chunk);
                $select = 'product_key, COUNT(DISTINCT keyword) as kw_cnt, MIN(rnk) as best_rnk, MAX(collected_at) as last_at';
                $all = (clone $base)->selectRaw('0 as collected_month, '.$select)->groupBy('product_key')->get();
                $months = (clone $base)->selectRaw('collected_month, '.$select)->groupBy('collected_month', 'product_key')->get();
                $rows = [];
                foreach ($all->concat($months) as $a) {
                    $p = $products->get($a->product_key);
                    if (! $p) {
                        continue;
                    }
                    $rows[] = array_merge((array) $a, [
                        'title' => $p->title, 'price' => $p->price, 'mall_name' => $p->mall_name,
                        'is_ad' => $p->is_ad, 'has_talk' => $p->talk_id !== null && $p->talk_id !== '',
                    ]);
                }
                DB::table('shop_product_summaries')->whereIn('product_key', $chunk)->delete();
                foreach (array_chunk($rows, 300) as $batch) {
                    DB::table('shop_product_summaries')->insert($batch);
                }
            }, 3);
        }
    }
}
