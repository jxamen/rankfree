<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopKeywordShortLinkService;
use App\Models\ShopKeywordAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoostShortUrlTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(): ShopKeywordAnalysis
    {
        $a = ShopKeywordAnalysis::create(['user_id' => User::factory()->create(['role' => 'super'])->id,
            'core_keyword' => '시험', 'status' => 'done', 'threshold' => 4]);
        foreach (range(1, 8) as $n) {
            $a->items()->create(['kind' => 'combo', 'source' => 'test', 'keyword' => '시험 '.$n, 'rank' => $n <= 6 ? 5 : 6]);
        }
        return $a;
    }

    public function test_reassign_twice_changes_assignment_preserving_urls_and_hits(): void
    {
        $a = $this->analysis();
        $service = app(ShopKeywordShortLinkService::class);
        $before = $service->generate($a, 2);
        $a->shortLinks()->update(['hit_count' => 9]);
        foreach (range(1, 2) as $attempt) {
            $after = $service->reassign($a);
            $this->assertNotSame($before->pluck('keywords')->all(), $after->pluck('keywords')->all());
            $this->assertSame($before->pluck('token')->all(), $after->pluck('token')->all());
            $this->assertSame([9, 9], $after->pluck('hit_count')->all());
            $words = $after->pluck('keywords')->flatten()->all();
            $this->assertEqualsCanonicalizing(array_map(fn ($n) => '시험 '.$n, range(1, 6)), $words);
            $this->assertCount(6, array_unique($words));
            $before = $after;
        }
    }
}
