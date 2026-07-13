<?php

namespace Tests\Feature;

use App\Domain\Place\PlaceRankChecker;
use App\Domain\Shopping\NaverShoppingRankService;
use App\Models\User;
use App\Support\Turnstile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 무료 순위조회 — 비회원 허용 + Turnstile 봇 차단(회원 skip). 플레이스·쇼핑 2탭. */
class RankCheckGuestTest extends TestCase
{
    use RefreshDatabase;

    private function placeResult(): array
    {
        return [
            'blocked' => false, 'found' => true, 'rank' => 7, 'list_total' => 300, 'category' => 'place',
            'place_id' => '123', 'place_name' => '라온헤어', 'review_count' => 100, 'blog_review_count' => 10,
            'save_count' => 50, 'review_score' => null, 'tags' => [],
        ];
    }

    public function test_turnstile_helper_skips_when_unconfigured_and_blocks_missing_token_when_configured(): void
    {
        config(['services.turnstile.secret' => null]);
        $this->assertTrue(Turnstile::verify(null, '127.0.0.1'));   // 미설정 → 통과

        config(['services.turnstile.secret' => 'dummy-secret']);
        $this->assertFalse(Turnstile::verify(null, '127.0.0.1'));  // 설정+토큰없음 → 차단
    }

    public function test_guest_place_check_works_when_turnstile_unconfigured(): void
    {
        config(['services.turnstile.secret' => null]);
        $this->mock(PlaceRankChecker::class)->shouldReceive('check')->once()->andReturn($this->placeResult());

        $this->post('/rank-check', ['keyword' => '강남 미용실', 'place' => '라온헤어'])
            ->assertOk()
            ->assertSee('쇼핑')   // 결과 페이지 렌더
            ->assertSee('7', false);
        $this->assertDatabaseCount('place_rank_lookups', 1);
    }

    public function test_guest_place_check_blocked_without_turnstile_token_when_configured(): void
    {
        config(['services.turnstile.secret' => 'dummy-secret']);
        $this->mock(PlaceRankChecker::class)->shouldNotReceive('check'); // 봇 차단 → 조회 미실행

        $this->from('/')->post('/rank-check', ['keyword' => '강남 미용실', 'place' => '라온헤어'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('captcha');
        $this->assertDatabaseCount('place_rank_lookups', 0);
    }

    public function test_member_skips_turnstile(): void
    {
        config(['services.turnstile.secret' => 'dummy-secret']);
        $user = User::create(['name' => 'u', 'email' => 'm@rf.kr', 'password' => 'x1234567']);
        $this->mock(PlaceRankChecker::class)->shouldReceive('check')->once()->andReturn($this->placeResult());

        $this->actingAs($user)->post('/rank-check', ['keyword' => '강남 미용실', 'place' => '라온헤어'])
            ->assertOk();
    }

    public function test_guest_shop_check_works_when_unconfigured(): void
    {
        config(['services.turnstile.secret' => null]);
        $mock = $this->mock(NaverShoppingRankService::class);
        $mock->shouldReceive('resolveTarget')->once()->andReturn(['type' => 'mall', 'product_id' => '', 'mall_name' => '테스트몰', 'url' => '']);
        $mock->shouldReceive('checkRank')->once()->andReturn([
            'blocked' => false, 'found' => true, 'rank' => 3, 'total' => 800,
            'product_id' => '', 'title' => '테스트 상품', 'mall_name' => '테스트몰', 'price' => 15900, 'link' => 'https://x', 'image' => '',
        ]);

        $this->post('/shop-check', ['keyword' => '캠핑 의자', 'target' => '테스트몰'])
            ->assertOk()
            ->assertSee('쇼핑 순위 조회 결과')
            ->assertSee('3', false);
    }

    public function test_old_get_rank_check_redirects_home(): void
    {
        $this->get('/rank-check')->assertRedirect('/#hero-form');
    }
}
