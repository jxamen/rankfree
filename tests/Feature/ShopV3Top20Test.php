<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopRankSlotService;
use App\Models\ShopRankJob;
use App\Models\ShopRankSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopV3Top20Test extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['worker_id' => 'v3-worker', 'source' => 'naver-v3', 'page' => 1,
            'products' => array_map(fn ($i) => ['nvMid' => (string) $i, 'title' => 'Product '.$i,
                'price' => 1000, 'mallName' => 'Mall', 'rank' => $i, 'isAd' => $i === 1], range(1, 20))];
    }

    private function job(string $id): array
    {
        $slot = ShopRankSlot::create(['user_id' => User::factory()->create()->id, 'keyword' => 'test',
            'target_type' => 'product', 'product_id' => $id, 'product_url' => 'https://search.shopping.naver.com/catalog/'.$id, 'is_active' => true]);
        $job = app(ShopRankSlotService::class)->enqueue($slot);
        ShopRankJob::claim('v3-worker', 1);

        return [$slot, $job];
    }

    public function test_top20_found_records_organic_rank(): void
    {
        [$slot,$job] = $this->job('3');
        $this->postJson('/api/ext/shop-rank/'.$job->id.'/result', $this->payload())->assertOk()->assertJsonPath('data.rank', 2);
        $this->assertDatabaseHas('shop_rank_records', ['slot_id' => $slot->id, 'rank' => 2]);
    }

    public function test_top20_missing_never_records_zero_or_overwrites_valid_rank(): void
    {
        [$slot,$job] = $this->job('999');
        $slot->update(['last_rank' => 7]);
        $this->postJson('/api/ext/shop-rank/'.$job->id.'/result', $this->payload())->assertOk()->assertJsonPath('data.partial', true);
        $this->assertDatabaseCount('shop_rank_records', 0);
        $this->assertSame(7, (int) $slot->fresh()->last_rank);
    }

    public function test_empty_wrong_page_and_wrong_order_are_rejected(): void
    {
        [$slot,$job] = $this->job('3');
        $url = '/api/ext/shop-rank/'.$job->id.'/result';
        $p = $this->payload();
        $p['products'] = [];
        $this->postJson($url, $p)->assertStatus(422);
        $p = $this->payload();
        $p['page'] = 2;
        $this->postJson($url, $p)->assertStatus(422);
        $p = $this->payload();
        $p['products'][0]['rank'] = 2;
        $this->postJson($url, $p)->assertStatus(422);
        $this->assertDatabaseCount('shop_rank_records',0);
    }
}
