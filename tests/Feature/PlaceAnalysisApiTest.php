<?php

namespace Tests\Feature;

use App\Domain\Place\PlaceSeoAnalyzer;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlaceAnalysisApiTest extends TestCase
{
    use RefreshDatabase;

    private function headers(array $scopes = ['rank']): array
    {
        $user = User::factory()->create(['api_scopes' => $scopes]);
        [, $key] = ApiKey::issue($user, '분석', $scopes, null, null, null);

        return ['Authorization' => 'Bearer '.$key];
    }

    public function test_same_console_scores_are_returned_without_competitors_and_cached(): void
    {
        Cache::flush();
        $this->mock(PlaceSeoAnalyzer::class)->shouldReceive('analyzeOne')->once()->with('애월 도넛', 'restaurant', '123456')->andReturn([
            'n1' => 80, 'n2' => 70, 'n3' => 75, 'd7' => 90, 'd8' => 85, 'name' => '도넛집', 'rnk' => 4,
            'seo' => [['key' => 'phone', 'avail' => true]], 'review_quality' => ['authority' => ['infl' => 2]],
            'competitors' => [['place_id' => '999999']], 'benchmark' => ['top_count' => 10],
        ]);
        $url = '/api/v1/rank/analysis?'.http_build_query(['place' => 'https://m.place.naver.com/restaurant/123456/home', 'keyword' => '애월 도넛']);
        $headers = $this->headers();
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('cached', false)
            ->assertJsonPath('analysis.n3', 75)->assertJsonPath('analysis.d.d7', 90)->assertJsonPath('analysis.d.d8', 85)
            ->assertJsonPath('analysis.benchmark.top_count', 10)->assertJsonPath('review_sentiment_status', 'not_analyzed')
            ->assertJsonMissingPath('analysis.competitors')->assertDontSee('999999');
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('cached', true);
        $this->assertDatabaseCount('place_rank_slots', 0);
    }

    public function test_failure_is_not_cached_or_returned_as_a_zero_score(): void
    {
        Cache::flush();
        $this->mock(PlaceSeoAnalyzer::class)->shouldReceive('analyzeOne')->twice()->andReturn(null);
        $headers = $this->headers();
        foreach (range(1, 2) as $_) {
            $this->getJson('/api/v1/rank/analysis?place=123456&keyword=test', $headers)
                ->assertStatus(503)->assertJsonMissingPath('analysis');
        }
    }

    public function test_auth_and_inputs_are_checked_before_analysis(): void
    {
        $this->mock(PlaceSeoAnalyzer::class)->shouldNotReceive('analyzeOne');
        $this->getJson('/api/v1/rank/analysis?place=123456&keyword=test')->assertUnauthorized();
        $this->getJson('/api/v1/rank/analysis?place=123456&keyword=test', $this->headers(['keyword']))->assertForbidden();
        $headers = $this->headers();
        foreach (['?place=123456', '?keyword=test&place=http://127.0.0.1', '?place=123456&keyword=test&category=unknown'] as $query) {
            $this->getJson('/api/v1/rank/analysis'.$query, $headers)->assertUnprocessable();
        }
    }
}
