<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** hub:partition-rotate — sqlite 경로(보존기간 지난 월 DELETE)와 보존 0(파기 안 함) 스모크. */
class HubPartitionRotateTest extends TestCase
{
    use RefreshDatabase;

    private function seedRanks(int $oldMonth, int $curMonth): void
    {
        DB::table('keyword_place_ranks')->insert([
            ['keyword' => '옛키워드', 'cat' => 'place', 'place_id' => 'p1', 'rnk' => 1, 'collected_month' => $oldMonth, 'collected_at' => now()],
            ['keyword' => '현키워드', 'cat' => 'place', 'place_id' => 'p2', 'rnk' => 1, 'collected_month' => $curMonth, 'collected_at' => now()],
        ]);
        DB::table('keyword_shop_ranks')->insert([
            ['keyword' => '옛키워드', 'product_key' => 'k1', 'rnk' => 1, 'collected_month' => $oldMonth, 'collected_at' => now()],
            ['keyword' => '현키워드', 'product_key' => 'k2', 'rnk' => 1, 'collected_month' => $curMonth, 'collected_at' => now()],
        ]);
    }

    public function test_retention_deletes_expired_months_and_keeps_recent(): void
    {
        $old = (int) now()->startOfMonth()->subMonths(14)->format('Ym');
        $cur = (int) now()->format('Ym');
        $this->seedRanks($old, $cur);

        $this->artisan('hub:partition-rotate')->assertOk();

        $this->assertSame(0, DB::table('keyword_place_ranks')->where('collected_month', $old)->count());
        $this->assertSame(1, DB::table('keyword_place_ranks')->where('collected_month', $cur)->count());
        $this->assertSame(0, DB::table('keyword_shop_ranks')->where('collected_month', $old)->count());
        $this->assertSame(1, DB::table('keyword_shop_ranks')->where('collected_month', $cur)->count());
    }

    public function test_retention_zero_keeps_everything(): void
    {
        $old = (int) now()->startOfMonth()->subMonths(30)->format('Ym');
        $cur = (int) now()->format('Ym');
        $this->seedRanks($old, $cur);

        $this->artisan('hub:partition-rotate', ['--retention' => 0])->assertOk();

        $this->assertSame(2, DB::table('keyword_place_ranks')->count());
        $this->assertSame(2, DB::table('keyword_shop_ranks')->count());
    }
}
