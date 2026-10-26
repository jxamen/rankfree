<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShopProductSummaryBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_repeatable_and_removes_orphans(): void
    {
        DB::table('shop_products')->insert(['product_key' => 'pd1', 'title' => 'Product', 'is_ad' => false]);
        foreach ([202609, 202610] as $month) {
            DB::table('keyword_shop_ranks')->insert(['product_key' => 'pd1', 'keyword' => 'same', 'rnk' => 2, 'is_ad' => false, 'collected_month' => $month, 'collected_at' => now()]);
        }
        $this->artisan('shop-products:refresh-summaries')->assertSuccessful();
        $this->artisan('shop-products:refresh-summaries')->assertSuccessful();
        $this->assertDatabaseHas('shop_product_summaries', ['product_key' => 'pd1', 'collected_month' => 0, 'kw_cnt' => 1]);
        $this->assertEquals(3, DB::table('shop_product_summaries')->count());
        DB::table('shop_products')->delete();
        $this->artisan('shop-products:refresh-summaries')->assertSuccessful();
        $this->assertEquals(0, DB::table('shop_product_summaries')->count());
    }
}
