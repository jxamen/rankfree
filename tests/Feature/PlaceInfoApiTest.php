<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaceInfoApiTest extends TestCase
{
    use RefreshDatabase;

    private function headers(array $scopes = ['rank']): array
    {
        $user = User::factory()->create(['api_scopes' => $scopes]);
        [, $key] = ApiKey::issue($user, '플레이스 조회', $scopes, null, null, null);

        return ['Authorization' => 'Bearer '.$key];
    }

    private function html(array $state): string
    {
        return '<script>window.__APOLLO_STATE__ = '.json_encode($state, JSON_UNESCAPED_UNICODE).';</script>';
    }

    private function home(): array
    {
        return ['PlaceDetailBase:123456' => [
            'name' => '테스트 식당', 'category' => '한식', 'phone' => '02-123-4567',
            'address' => '서울 강남구 역삼동', 'roadAddress' => '서울 강남구 테헤란로 1',
            'description' => '매장 소개', 'road' => '1번 출구',
            'coordinate' => ['x' => '127.01', 'y' => '37.5'],
            'visitorReviewsTotal' => 20, 'cafeBlogReviewsTotal' => 5,
        ]];
    }

    private function fake(array $home, array $menu = ['ROOT_QUERY' => []], array $review = ['ROOT_QUERY' => []]): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/home' => Http::response($this->html($home)),
            '*/menu' => Http::response($this->html($menu)),
            '*/review/visitor?reviewSort=recent' => Http::response($this->html($review)),
        ]);
    }

    public function test_returns_business_menus_recent_reviews_and_coordinates_without_creating_slot(): void
    {
        $this->fake($this->home(), [
            'Menu:1' => ['id' => '1', 'name' => '정식', 'price' => '12,000', 'description' => '대표메뉴', 'images' => []],
        ], [
            'VisitorReview:old' => ['reviewId' => 'old', 'body' => '지난 후기', 'created' => '2026-08-01'],
            'VisitorReview:new' => ['reviewId' => 'new', 'body' => '맛있어요', 'created' => '2026-08-02', 'author' => ['__ref' => 'Author:1']],
            'Author:1' => ['nickname' => '방문자', 'internalId' => 'do-not-expose'],
        ]);

        $this->getJson('/api/v1/rank/place?place=123456&review_limit=1', $this->headers())
            ->assertOk()->assertJsonPath('place.name', '테스트 식당')
            ->assertJsonPath('place.location.latitude', 37.5)
            ->assertJsonPath('place.location.longitude', 127.01)
            ->assertJsonPath('place.menus.0.price', '12,000')
            ->assertJsonCount(1, 'place.recent_reviews')
            ->assertJsonPath('place.recent_reviews.0.id', 'new')
            ->assertJsonPath('place.recent_reviews.0.author', '방문자')
            ->assertJsonPath('partial', false)->assertDontSee('internalId');
        Http::assertSentCount(3);
        $this->assertDatabaseCount('place_rank_slots', 0);
    }

    public function test_category_url_uses_category_path(): void
    {
        $this->fake($this->home());
        $url = '/api/v1/rank/place?'.http_build_query(['place' => 'https://m.place.naver.com/restaurant/123456/home']);
        $this->getJson($url, $this->headers())->assertOk();
        Http::assertSent(fn ($request) => $request->url() === 'https://m.place.naver.com/restaurant/123456/home');
    }

    public function test_current_naver_menu_price_and_root_review_order_are_preserved(): void
    {
        $home = $this->home();
        $home['ROOT_QUERY']['placeDetail({"input":{"id":"123456","deviceType":"pc"}})'] = [
            'description' => '실제 홈 구조의 소개', 'newBusinessHours' => [['name' => '매일', 'businessHours' => '10:00~20:00']],
        ];
        $this->fake($home, [
            'PlaceMenuItem:m1' => ['id' => 'm1', 'name' => '음료', 'price' => ['displayText' => '5,100~6,700원']],
        ], [
            'ROOT_QUERY' => [
                'visitorReviews({"input":{"businessId":"123456","sort":"recent","includeContent":true}})' => [
                    'items' => [['__ref' => 'VisitorReview:new:true'], ['__ref' => 'VisitorReview:old:true']],
                ],
            ],
            'VisitorReview:old:true' => ['reviewId' => 'old', 'body' => '이전', 'created' => '9.30.수'],
            'VisitorReview:new:true' => ['reviewId' => 'new', 'body' => '최근', 'created' => '10.4.일'],
            'VisitorReview:new' => ['reviewId' => 'new', 'body' => null, 'created' => '10.4.일'],
        ]);
        $this->getJson('/api/v1/rank/place?place=123456', $this->headers())->assertOk()
            ->assertJsonPath('place.description', '실제 홈 구조의 소개')
            ->assertJsonPath('place.business_hours.0.name', '매일')
            ->assertJsonPath('place.menus.0.price', '5,100~6,700원')
            ->assertJsonPath('place.recent_reviews.0.body', '최근')
            ->assertJsonCount(2, 'place.recent_reviews');
    }

    public function test_partial_failure_keeps_available_business_data(): void
    {
        Http::fake([
            '*/home' => Http::response($this->html($this->home())),
            '*/menu' => Http::response('', 429),
            '*/review/*' => Http::failedConnection(),
        ]);
        $this->getJson('/api/v1/rank/place?place=123456', $this->headers())
            ->assertOk()->assertJsonPath('partial', true)
            ->assertJsonPath('status.menus', 'blocked')
            ->assertJsonPath('status.recent_reviews', 'unavailable')
            ->assertJsonPath('place.name', '테스트 식당');
    }

    public function test_blocked_home_returns_429(): void
    {
        Http::fake(['*' => Http::response('', 429)]);
        $this->getJson('/api/v1/rank/place?place=123456', $this->headers())
            ->assertStatus(429)->assertJsonPath('status.business', 'blocked');
    }

    public function test_wrong_place_is_not_returned_as_success(): void
    {
        $this->fake(['PlaceDetailBase:999999' => ['name' => '다른 업체']]);
        $this->getJson('/api/v1/rank/place?place=123456', $this->headers())
            ->assertStatus(503)->assertJsonPath('place.name', null);
    }

    public function test_missing_coordinates_are_null_and_empty_lists_are_arrays(): void
    {
        $this->fake(['PlaceDetailBase:123456' => ['name' => '매장']]);
        $this->getJson('/api/v1/rank/place?place=123456', $this->headers())
            ->assertOk()->assertJsonPath('place.location.latitude', null)
            ->assertJsonPath('place.location.longitude', null)
            ->assertJsonPath('place.menus', [])->assertJsonPath('place.recent_reviews', []);
    }

    public function test_invalid_inputs_do_not_fetch_network(): void
    {
        Http::fake();
        $headers = $this->headers();
        foreach (['', '?place=업체명', '?place=http://127.0.0.1', '?place=123456&review_limit=51', '?place=123456&review_limit=0'] as $query) {
            $this->getJson('/api/v1/rank/place'.$query, $headers)->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_requires_api_key_and_rank_scope(): void
    {
        Http::fake();
        $this->getJson('/api/v1/rank/place?place=123456')->assertUnauthorized();
        $this->getJson('/api/v1/rank/place?place=123456', $this->headers(['keyword']))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_developer_documentation_contains_place_endpoint(): void
    {
        $this->withoutVite()->get('/developers')->assertOk()->assertSee('/rank/place')->assertSee('recent_reviews');
    }
}
