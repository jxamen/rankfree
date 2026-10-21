<?php

namespace Tests\Feature;

use App\Domain\Shopping\NaverShopExposureService;
use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use App\Domain\Shopping\ShopKeywordShortLinkService;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BoostFinalShuffleTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $count = 12, int $days = 3, ?int $target = null): array
    {
        Http::fake();
        $a = ShopKeywordAnalysis::create(['user_id' => User::factory()->create()->id,
            'core_keyword' => '시험', 'product_id' => '1234567', 'status' => 'checking',
            'threshold' => 5, 'order_days' => $days, 'keyword_target' => $target,
            'combo_count' => $count, 'check_method' => 'search']);
        $links = app(ShopKeywordShortLinkService::class)->ensureInitialLinks($a);
        $a->shortLinks()->update(['hit_count' => 7, 'cursor' => 2]);
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = $a->items()->create(['kind' => 'combo', 'source' => 'test', 'keyword' => '시험 키워드 '.$n]);
        }
        $this->mock(NaverShopExposureService::class, function ($mock) {
            $mock->shouldReceive('rankFromHtml')->andReturnUsing(fn ($html) => ['rank' => (int) $html]);
            $mock->shouldReceive('exposureBySlotApi')->andReturn(['rank' => 3]);
        });
        return [$a, $items, $links];
    }

    private function assignments(ShopKeywordAnalysis $a): array
    {
        return $a->shortLinks()->orderBy('group_no')->pluck('keywords')->all();
    }

    public function test_last_browser_result_shuffles_all_groups_once_and_preserves_addresses_and_hits(): void
    {
        [$a, $items, $links] = $this->fixture();
        [$other] = $this->fixture(2, 1);
        $otherBefore = $other->shortLinks()->get()->toArray();
        $analyzer = app(ShopKeywordExposureAnalyzer::class);
        foreach (array_slice($items, 0, 11) as $item) $analyzer->applyHtml($a, $item, '3');
        $sequential = [[], [], []];
        foreach ($items as $n => $item) $sequential[$n % 3][] = $item->keyword;
        $before = $this->assignments($a);
        $this->assertSame(array_slice($sequential[0], 0, 4), $before[0]);
        $this->assertSame('checking', $a->fresh()->status);
        $result = $analyzer->applyHtml($a, $items[11], '3');
        $after = $this->assignments($a);
        $this->assertSame('done', $result['status']);
        $this->assertNotSame($sequential, $after, 'Completion must replace the sequential assignment');
        $this->assertSame([4, 4, 4], array_map('count', $after));
        $this->assertEqualsCanonicalizing(array_map(fn ($i) => $i->keyword, $items), array_merge(...$after));
        $saved = $a->shortLinks()->orderBy('group_no')->get();
        $this->assertSame($links->pluck('token')->all(), $saved->pluck('token')->all());
        $this->assertSame($links->pluck('domain')->all(), $saved->pluck('domain')->all());
        $this->assertSame([7, 7, 7], $saved->pluck('hit_count')->all());
        $this->assertSame([0, 0, 0], $saved->pluck('cursor')->all());
        $this->assertTrue($analyzer->applyHtml($a, $items[11], '3')['skipped']);
        $analyzer->progress($a->fresh());
        $this->assertSame($after, $this->assignments($a));
        $this->assertSame($otherBefore, $other->shortLinks()->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_target_completion_shuffles_before_unchecked_items_and_late_saves_do_not_reshuffle(): void
    {
        [$a, $items] = $this->fixture(10, 2, 6);
        $analyzer = app(ShopKeywordExposureAnalyzer::class);
        foreach (array_slice($items, 0, 6) as $item) $result = $analyzer->applyHtml($a, $item, '5');
        $sequential = [[$items[0]->keyword, $items[2]->keyword, $items[4]->keyword],
            [$items[1]->keyword, $items[3]->keyword, $items[5]->keyword]];
        $after = $this->assignments($a);
        $this->assertSame('target_reached', $result['status']);
        $this->assertNotSame($sequential, $after);
        $this->assertSame([3, 3], array_map('count', $after));
        $this->assertTrue($analyzer->applyHtml($a, $items[6], '5')['skipped']);
        $this->assertNull($items[6]->fresh()->rank);
        $this->assertSame($after, $this->assignments($a));
        $this->assertCount(6, array_unique(array_merge(...$after)));
    }

    public function test_partial_pause_and_resume_only_shuffle_at_completion(): void
    {
        [$a, $items] = $this->fixture(6, 2);
        $analyzer = app(ShopKeywordExposureAnalyzer::class);
        foreach (array_slice($items, 0, 5) as $item) $analyzer->applyHtml($a, $item, '3');
        $before = $this->assignments($a);
        $a->update(['status' => 'paused']);
        $this->assertTrue($analyzer->applyHtml($a, $items[5], '3')['skipped']);
        $this->assertSame($before, $this->assignments($a));
        $a->update(['status' => 'checking']);
        $analyzer->applyHtml($a, $items[5], '3');
        $before[1][] = $items[5]->keyword;
        $this->assertNotSame($before, $this->assignments($a));
        $this->assertSame('done', $a->fresh()->status);
    }

    public function test_sparse_or_empty_results_keep_period_link_count_and_complete(): void
    {
        foreach ([0, 1, 2] as $eligible) {
            [$a, $items, $links] = $this->fixture(4, 5);
            foreach ($items as $n => $item) app(ShopKeywordExposureAnalyzer::class)->applyHtml($a, $item, $n < $eligible ? '3' : '6');
            $after = $a->shortLinks()->orderBy('group_no')->get();
            $this->assertSame('done', $a->fresh()->status);
            $this->assertSame($links->pluck('token')->all(), $after->pluck('token')->all());
            $this->assertCount($eligible, array_unique($after->pluck('keywords')->flatten()->all()));
            $sizes = $after->pluck('keywords')->map(fn ($words) => count($words))->all();
            $this->assertLessThanOrEqual(1, max($sizes) - min($sizes));
            if ($eligible === 2) $this->assertNotSame([[$items[0]->keyword], [$items[1]->keyword], [], [], []], $this->assignments($a));
        }
    }

    public function test_server_batch_also_shuffles_once_at_completion(): void
    {
        [$a, $items] = $this->fixture(6, 2);
        $a->update(['check_method' => 'api']);
        config(['rankfree.shopping.exposure.fetch_delay_ms' => 0, 'rankfree.shopping.exposure.batch_sec' => 30]);
        $analyzer = app(ShopKeywordExposureAnalyzer::class);
        $analyzer->checkBatch($a, 5);
        $before = $this->assignments($a);
        $this->assertSame('checking', $a->fresh()->status);
        $result = $analyzer->checkBatch($a, 5);
        $before[1][] = $items[5]->keyword;
        $after = $this->assignments($a);
        $this->assertSame('done', $result['status']);
        $this->assertNotSame($before, $after);
        $analyzer->checkBatch($a, 5);
        $this->assertSame($after, $this->assignments($a));
        Http::assertNothingSent();
    }

    public function test_analysis_without_links_completes_without_creating_any(): void
    {
        [$a, $items] = $this->fixture(2, 1);
        $a->shortLinks()->delete();
        foreach ($items as $item) app(ShopKeywordExposureAnalyzer::class)->applyHtml($a, $item, '3');
        $this->assertSame('done', $a->fresh()->status);
        $this->assertSame(0, $a->shortLinks()->count());
    }

    public function test_failed_final_assignment_rolls_back_last_result_and_can_retry(): void
    {
        [$a, $items] = $this->fixture(6, 2);
        $analyzer = app(ShopKeywordExposureAnalyzer::class);
        foreach (array_slice($items, 0, 5) as $item) $analyzer->applyHtml($a, $item, '3');
        $before = $this->assignments($a);
        $this->partialMock(ShopKeywordShortLinkService::class, function ($mock) {
            $mock->shouldReceive('reassignOnCompletion')->once()->andReturnUsing(function ($analysis) {
                (new ShopKeywordShortLinkService)->reassignOnCompletion($analysis);
                throw new \RuntimeException('simulated assignment failure');
            });
        });
        try {
            $analyzer->applyHtml($a, $items[5], '3');
            $this->fail('Expected simulated failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated assignment failure', $e->getMessage());
        }
        $this->assertSame('checking', $a->fresh()->status);
        $this->assertNull($items[5]->fresh()->rank);
        $this->assertSame($before, $this->assignments($a));
        $this->app->instance(ShopKeywordShortLinkService::class, new ShopKeywordShortLinkService);
        $analyzer->applyHtml($a, $items[5], '3');
        $before[1][] = $items[5]->keyword;
        $this->assertSame('done', $a->fresh()->status);
        $this->assertNotSame($before, $this->assignments($a));
    }

    public function test_second_collection_completion_shuffles_the_new_full_pool(): void
    {
        [$a, $items] = $this->fixture(6, 2);
        $analyzer = app(ShopKeywordExposureAnalyzer::class);
        foreach ($items as $item) $analyzer->applyHtml($a, $item, '3');
        $before = $this->assignments($a);
        $a->refresh()->update(['status' => 'checking']);
        $next = $a->items()->create(['kind' => 'combo', 'source' => 'test', 'keyword' => '추가 키워드']);
        $analyzer->applyHtml($a, $next, '3');
        $before[0][] = $next->keyword;
        $after = $this->assignments($a);
        $this->assertSame('done', $a->fresh()->status);
        $this->assertNotSame($before, $after);
        $this->assertSame([4, 3], array_map('count', $after));
        $this->assertCount(7, array_unique(array_merge(...$after)));
    }
}
