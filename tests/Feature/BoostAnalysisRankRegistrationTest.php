<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopAnalysisRankRegistration;
use App\Domain\Shopping\ShopRankSlotService;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopRankSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BoostAnalysisRankRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(bool $optIn = true): ShopKeywordAnalysis
    {
        Http::preventStrayRequests();
        $a = ShopKeywordAnalysis::create(['user_id' => User::factory()->create()->id,
            'core_keyword' => '시험 핵심', 'product_url' => 'https://smartstore.naver.com/test/products/1234567',
            'product_id' => '1234567', 'status' => 'done', 'threshold' => 5]);
        if ($optIn) {
            $a->setBoostMeta(['source_order' => 'boosting:999999999']);
            $a->save();
        }
        foreach (['시험 키워드 1', '시험 키워드 2'] as $keyword) {
            $a->items()->create(['kind' => 'combo', 'source' => 'test', 'keyword' => $keyword, 'rank' => 2, 'checked_at' => now()]);
        }
        return $a;
    }

    public function test_registers_then_skips_duplicates_without_searching(): void
    {
        $a = $this->analysis();
        $service = app(ShopAnalysisRankRegistration::class);
        $this->assertSame(3, $service->sync($a)['registered']);
        $this->assertSame(3, $service->sync($a)['skipped']);
        $this->assertSame(3, ShopRankSlot::count());
        $this->assertSame(0, $a->fresh()->boostMeta()['rank_registration']['failed']);
        Http::assertNothingSent();
    }

    public function test_collector_state_replacement_keeps_registration_record(): void
    {
        $a = $this->analysis();
        app(ShopAnalysisRankRegistration::class)->sync($a);
        $a->refresh()->update(['product_collection' => ['state' => 'completed']]);
        $this->assertSame(3, $a->fresh()->boostMeta()['rank_registration']['registered']);
    }

    public function test_failure_keeps_analysis_and_scheduled_retry_recovers(): void
    {
        $a = $this->analysis();
        $this->mock(ShopRankSlotService::class)->shouldReceive('ensureTracked')->andThrow(new \RuntimeException('simulated'));
        $this->assertSame(3, app(ShopAnalysisRankRegistration::class)->sync($a)['failed']);
        $this->assertSame('done', $a->fresh()->status);
        $this->app->forgetInstance(ShopRankSlotService::class);
        $this->artisan('shop-keyword:rank-sync')->assertSuccessful();
        $this->assertSame(3, ShopRankSlot::count());
    }

    public function test_historical_analysis_is_not_backfilled(): void
    {
        $this->analysis(false);
        $this->artisan('shop-keyword:rank-sync')->assertSuccessful();
        $this->assertSame(0, ShopRankSlot::count());
    }
}
