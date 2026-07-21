<?php

namespace Tests\Feature;

use App\Domain\Place\PlaceSeoAnalyzer;
use App\Domain\Place\RankSlotService;
use App\Models\PlaceStoreAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaceStoreConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_saved_place_store_analyses(): void
    {
        $user = User::factory()->create();
        $user->placeStoreAnalyses()->create([
            'place_id' => '19137',
            'name' => '전전북삼계탕 본점',
            'keyword' => '논현삼계탕',
            'cat' => 'restaurant',
            'rank' => 1,
            'n1' => 72,
            'n2' => 88,
            'n3' => 100,
            'detail' => [],
        ]);

        $this->actingAs($user)
            ->get(route('console.place-store'))
            ->assertOk()
            ->assertSee('플레이스 개별 분석')
            ->assertSee('전전북삼계탕 본점')
            ->assertSee('논현삼계탕');
    }

    public function test_store_runs_single_analysis_and_saves_result(): void
    {
        $user = User::factory()->create();

        $this->mock(RankSlotService::class, function ($mock) {
            $mock->shouldReceive('resolvePlace')->once()->with('https://map.naver.com/p/place/19137')->andReturn([
                'place_id' => '19137',
                'place_name' => '전전북삼계탕 본점',
                'place_url' => 'https://m.place.naver.com/restaurant/19137',
                'category' => 'restaurant',
            ]);
        });

        $this->mock(PlaceSeoAnalyzer::class, function ($mock) {
            $mock->shouldReceive('analyzeOne')->once()->with('논현삼계탕', 'restaurant', '19137')->andReturn([
                'rnk' => 1,
                'name' => '전전북삼계탕 본점',
                'n1' => 71.5,
                'n2' => 88.2,
                'n3' => 100.0,
                'tier' => 'best',
                'd1' => 94,
                'd2' => 83,
                'd3' => null,
                'd4' => 90,
                'd5' => 75,
                'd6' => 80,
                'd7' => 91,
                'd8' => 72,
                'd9' => 65,
                'd10' => 70,
                'visitor_cnt' => 1751,
                'blog_cnt' => 604,
                'save_cnt' => 120,
                'category' => '백숙,삼계탕',
                'benchmark' => ['total' => 89, 'top_count' => 10, 'visitor_avg' => 670, 'blog_avg' => 376],
                'competitors' => [['rnk' => 1, 'place_id' => '19137', 'name' => '전전북삼계탕 본점']],
                'kc' => ['L' => 1, 'B' => 1, 'T' => 0.8, 'M' => 0.6, 'region' => '논현', 'core' => '논현', 'bizterm' => '삼계탕'],
                'seo' => [['label' => '대표 키워드', 'raw' => '5개', 'grade' => 1, 'w' => 1.5, 'avail' => 1]],
                'rep_keywords' => ['삼계탕', '전복'],
                'review_kw' => ['menus' => [['l' => '삼계탕', 'c' => 405]]],
                'review_quality' => ['ctx' => ['맛' => 30]],
            ]);
        });

        $response = $this->actingAs($user)->post(route('console.place-store.store'), [
            'place' => 'https://map.naver.com/p/place/19137',
            'keyword' => '논현삼계탕',
        ]);

        $analysis = PlaceStoreAnalysis::firstOrFail();
        $response->assertRedirect(route('console.place-store.show', $analysis));
        $this->assertDatabaseHas('place_store_analyses', [
            'user_id' => $user->id,
            'place_id' => '19137',
            'keyword' => '논현삼계탕',
            'rank' => 1,
        ]);
        $this->assertSame(670, $analysis->detail['benchmark']['visitor_avg']);
    }

    public function test_show_rejects_other_users_analysis(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $analysis = $owner->placeStoreAnalyses()->create([
            'place_id' => '19137',
            'name' => '전전북삼계탕 본점',
            'keyword' => '논현삼계탕',
            'detail' => [],
        ]);

        $this->actingAs($other)
            ->get(route('console.place-store.show', $analysis))
            ->assertForbidden();
    }
}
