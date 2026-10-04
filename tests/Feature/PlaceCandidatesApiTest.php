<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaceCandidatesApiTest extends TestCase
{
    use RefreshDatabase;

    private function headers(array $scopes = ['rank']): array
    {
        $user = User::factory()->create(['api_scopes' => $scopes]);
        [, $key] = ApiKey::issue($user, '후보 검색', $scopes, null, null, null);

        return ['Authorization' => 'Bearer '.$key];
    }

    private function fakeList(string $query, array $items, int $total): void
    {
        $state = ['PlaceListBusinessesItem:999999' => ['id' => '999999', 'name' => '검색 밖 광고']];
        $refs = [];
        foreach ($items as $i => $item) {
            $key = 'PlaceListBusinessesItem:'.$item['id'].':'.$i;
            $state[$key] = $item;
            $refs[] = ['__ref' => $key];
        }
        $state['ROOT_QUERY']['placeList('.json_encode(['input' => ['query' => $query]]).')'] = ['businesses' => ['total' => $total, 'items' => $refs]];
        Http::preventStrayRequests();
        Http::fake(['pcmap.place.naver.com/place/list*' => Http::response('<script>window.__APOLLO_STATE__ = '.json_encode($state).';</script>')]);
    }

    public function test_region_and_name_return_ordered_distinct_candidates_without_selecting_one(): void
    {
        $one = ['id' => '123456', 'name' => '같은빵집 본점', 'fullAddress' => '서울 마포구 망원동 1', 'x' => '126.9', 'y' => '37.5', 'virtualPhone' => '0507-1234-5678'];
        $this->fakeList('망원동 같은빵집', [$one, $one, ['id' => '123457', 'name' => '같은빵집 2호점']], 2);
        $this->getJson('/api/v1/rank/candidates?'.http_build_query(['region' => '망원동', 'name' => '같은빵집']), $this->headers())
            ->assertOk()->assertJsonPath('query', '망원동 같은빵집')->assertJsonCount(2, 'candidates')
            ->assertJsonPath('candidates.0.place_id', '123456')->assertJsonPath('candidates.1.place_id', '123457')
            ->assertJsonPath('candidates.0.place_url', 'https://m.place.naver.com/place/123456')
            ->assertJsonPath('candidates.0.address', '서울 마포구 망원동 1')->assertJsonPath('candidates.0.latitude', 37.5)
            ->assertJsonPath('candidates.0.phone', '0507-1234-5678')->assertJsonPath('candidates.1.latitude', null)
            ->assertJsonPath('has_more', false)->assertJsonPath('status', 'ok');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('place_rank_slots', 0);
    }

    public function test_combined_keyword_respects_limit(): void
    {
        $headers = $this->headers();
        $this->fakeList('서울 빵집', [['id' => '123456', 'name' => '빵집'], ['id' => '123457', 'name' => '빵집2']], 12);
        $this->getJson('/api/v1/rank/candidates?'.http_build_query(['keyword' => '서울 빵집', 'limit' => 1]), $headers)
            ->assertOk()->assertJsonCount(1, 'candidates')->assertJsonPath('total', 12)->assertJsonPath('has_more', true);
    }

    public function test_empty_search_is_successful_with_zero_matches(): void
    {
        $this->fakeList('없는상호', [], 0);
        $this->getJson('/api/v1/rank/candidates?'.http_build_query(['keyword' => '없는상호']), $this->headers())
            ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('total', 0)->assertJsonPath('candidates', []);
    }

    public function test_upstream_failures_are_not_reported_as_zero_matches(): void
    {
        $headers = $this->headers();
        Http::fakeSequence()->push('', 429)->push('', 500)->push('<html>unexpected</html>', 200);
        foreach ([[429, '', 429], [500, '', 503], [200, '<html>unexpected</html>', 503]] as [$code, $body, $expected]) {
            $this->getJson('/api/v1/rank/candidates?keyword=test', $headers)->assertStatus($expected)->assertJsonPath('total', null);
        }
    }

    public function test_validation_and_auth_do_not_make_upstream_requests(): void
    {
        Http::fake();
        $headers = $this->headers();
        foreach (['', '?region=서울', '?keyword=a&name=b', '?keyword=a&limit=0', '?keyword=a&limit=51', '?name=%20%20'] as $query) {
            $this->getJson('/api/v1/rank/candidates'.$query, $headers)->assertUnprocessable();
        }
        $this->getJson('/api/v1/rank/candidates?keyword=test')->assertUnauthorized();
        $this->getJson('/api/v1/rank/candidates?keyword=test', $this->headers(['keyword']))->assertForbidden();
        Http::assertNothingSent();
    }
}
