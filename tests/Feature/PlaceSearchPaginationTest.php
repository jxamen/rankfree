<?php

namespace Tests\Feature;

use App\Domain\Place\PlaceRankChecker;
use Tests\TestCase;

class PlaceSearchPaginationTest extends TestCase
{
    public function test_actual_result_counts_and_300_cap(): void
    {
        config(['rankfree.place.page_delay' => 0, 'rankfree.place.max_pages' => 6]);
        foreach ([0, 5, 10, 100, 300, 430] as $total) {
            $checker = new class($total) extends PlaceRankChecker
            {
                public int $calls = 0;

                public function __construct(private int $total)
                {
                    parent::__construct();
                }

                protected function pcmapPost(string $jsonData, string $keyword, string $type, string $x, string $y, string $ts, string $cookie = ''): array
                {
                    $this->calls++;
                    $start = json_decode($jsonData, true)[0]['variables']['input']['start'];
                    $items = [];
                    for ($i = $start; $i < $start + 50 && $i <= $this->total; $i++) {
                        $items[] = ['id' => (string) (100000 + $i), 'name' => '매장'.$i];
                    }

                    return ['code' => 200, 'data' => [['data' => ['businesses' => ['total' => $this->total, 'items' => $items]]]]];
                }
            };
            $result = $checker->serpFetch('카페', 'place', null, 300);
            $this->assertCount(min(300, $total), $result['items']);
            $this->assertSame(max(1, min(6, (int) ceil($total / 50))), $checker->calls);
        }
    }

    public function test_upstream_failure_is_not_empty_success(): void
    {
        $checker = new class extends PlaceRankChecker
        {
            protected function pcmapPost(string $jsonData, string $keyword, string $type, string $x, string $y, string $ts, string $cookie = ''): array
            {
                return ['code' => 200, 'data' => [['errors' => [['message' => 'unavailable']]]]];
            }
        };
        $this->assertTrue($checker->serpFetch('카페', 'place', null, 300)['failed']);
    }
}
