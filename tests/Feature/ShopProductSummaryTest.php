<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopSerpStore;
use App\Http\Controllers\Admin\ShopProductController;
use App\Jobs\RefreshShopProductSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopProductSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $id, array $extra = []): array
    {
        return array_merge(['title' => 'Product '.$id, 'rank' => $id, 'price' => $id * 100,
            'mallName' => 'Mall '.$id, 'link' => 'https://smartstore.naver.com/store/products/'.$id], $extra);
    }

    private function data(array $filters = []): array
    {
        return app(ShopProductController::class)->index(Request::create('/admin/shop-products', 'GET', $filters))->getData();
    }

    public function test_cross_month_distinct_replacement_and_deletion(): void
    {
        $store = app(ShopSerpStore::class);
        $this->travelTo(now()->setDate(2026, 9, 10));
        $store->save('one', [$this->product(1, ['rank' => 2])]);
        $this->travelTo(now()->setDate(2026, 10, 10));
        $store->save('one', [$this->product(1, ['rank' => 5])]);
        $store->save('two', [$this->product(1, ['rank' => 7])]);
        $all = DB::table('shop_product_summaries')->where('collected_month', 0)->first();
        $this->assertEquals(2, $all->kw_cnt);
        $this->assertEquals(2, $all->best_rnk);
        $this->assertEquals(5, $this->data(['month' => 202610])['items'][0]->best_rnk);
        $store->save('one', [$this->product(2)]);
        $this->assertEquals(7, DB::table('shop_product_summaries')->where('collected_month', 202610)->where('product_key', 'pd1')->value('best_rnk'));
        $store->deleteMonth('two', 202610);
        $this->assertDatabaseMissing('shop_product_summaries', ['product_key' => 'pd1', 'collected_month' => 202610]);
        $this->assertDatabaseHas('shop_product_summaries', ['product_key' => 'pd1', 'collected_month' => 0, 'kw_cnt' => 1]);
        $store->dropMonth(202609);
        $this->assertDatabaseMissing('shop_product_summaries', ['product_key' => 'pd1']);
    }

    public function test_sorts_filters_and_no_request_time_aggregate_or_count(): void
    {
        $store = app(ShopSerpStore::class);
        $store->save('one', [$this->product(1, ['title' => 'A 100%_']), $this->product(2, ['title' => 'B', 'isAd' => true, 'talkId' => 'talk'])]);
        $store->save('two', [$this->product(2, ['title' => 'B', 'isAd' => true, 'talkId' => 'talk'])]);
        foreach (['kw' => 'pd2', 'price_high' => 'pd2', 'price_low' => 'pd1', 'title' => 'pd1'] as $sort => $key) {
            $this->assertSame($key, $this->data(['sort' => $sort])['items'][0]->product_key);
        }
        foreach ([['ad' => 'y'], ['talk' => 'y'], ['mall' => 'Mall 2'], ['q' => 'B']] as $filters) {
            $this->assertSame(['pd2'], collect($this->data($filters)['items']->items())->pluck('product_key')->all());
        }
        $this->assertSame('pd1', $this->data(['ad' => 'n'])['items'][0]->product_key);
        $this->assertCount(0, $this->data(['q' => 'absent'])['items']);
        DB::enableQueryLog();
        $this->data();
        foreach (DB::getQueryLog() as $query) {
            $sql = strtolower($query['query']);
            $this->assertStringNotContainsString('count(', $sql);
            $this->assertStringNotContainsString('group by', $sql);
            if (str_contains($sql, 'keyword_shop_ranks')) {
                $this->assertStringContainsString('product_key', $sql);
                $this->assertStringContainsString(' in ', $sql);
            }
        }
        DB::disableQueryLog();
    }

    public function test_page_uses_101_rows_and_keeps_month_predicate(): void
    {
        app(ShopSerpStore::class)->save('one', array_map(fn ($id) => $this->product($id), range(1, 102)));
        DB::enableQueryLog();
        $items = $this->data(['month' => (int) now()->format('Ym')])['items'];
        $this->assertCount(100, $items);
        $this->assertTrue($items->hasMorePages());
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], 'keyword_shop_ranks')) {
                $this->assertStringContainsString('collected_month', $query['query']);
            }
        }
        DB::disableQueryLog();
    }

    public function test_rebuild_repairs_stale_rows_and_is_repeatable(): void
    {
        app(ShopSerpStore::class)->save('one', [$this->product(1)]);
        DB::table('shop_product_summaries')->delete();
        $this->artisan('shop-products:refresh-summaries')->assertSuccessful();
        $this->artisan('shop-products:refresh-summaries')->assertSuccessful();
        $this->assertEquals(2, DB::table('shop_product_summaries')->count());
        DB::table('keyword_shop_ranks')->delete();
        $this->artisan('shop-products:refresh-summaries')->assertSuccessful();
        $this->assertEquals(0, DB::table('shop_product_summaries')->count());
    }

    public function test_collection_queues_bounded_refreshes_after_commit(): void
    {
        Queue::fake();
        app(ShopSerpStore::class)->save('batch', array_map(fn ($id) => $this->product($id), range(1, 650)));
        Queue::assertPushed(RefreshShopProductSummary::class, 3);
        $total = 0;
        foreach (Queue::pushed(RefreshShopProductSummary::class) as $job) {
            $this->assertLessThanOrEqual(300, count($job->keys));
            $this->assertTrue($job->afterCommit);
            $total += count($job->keys);
        }
        $this->assertEquals(650, $total);
        $this->assertEquals(0, DB::table('shop_product_summaries')->count());
    }
}
