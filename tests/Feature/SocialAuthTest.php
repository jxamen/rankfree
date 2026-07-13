<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

/** 소셜 로그인(google/naver/kakao) + 가입 시 전화번호 SMS 인증 필수. */
class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.aligo.user_id' => null]); // 미설정 → dev_code 노출(발송 생략)
    }

    private function mockSocialite(string $provider, ?string $email, string $id = '9001'): void
    {
        $su = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
        $su->shouldReceive('getId')->andReturn($id);
        $su->shouldReceive('getEmail')->andReturn($email);
        $su->shouldReceive('getName')->andReturn('테스트회원');
        $su->shouldReceive('getNickname')->andReturn('테스트회원');
        $driver = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $driver->shouldReceive('user')->andReturn($su);
        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    public function test_login_and_register_show_social_buttons(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Google로 계속하기')->assertSee('네이버로 계속하기')->assertSee('카카오로 계속하기');
        $this->get('/register')->assertOk()
            ->assertSee('인증번호 받기')->assertSee('Google로 계속하기');
    }

    public function test_register_requires_phone_verification(): void
    {
        $this->post('/register', [
            'name' => '홍길동', 'email' => 'a@rf.kr', 'phone' => '010-1234-5678', 'password' => 'password123',
        ])->assertSessionHasErrors('phone');
        $this->assertDatabaseMissing('users', ['email' => 'a@rf.kr']);
    }

    public function test_register_succeeds_with_verified_phone(): void
    {
        $this->withSession(['phone_verified' => '01012345678'])->post('/register', [
            'name' => '홍길동', 'email' => 'a@rf.kr', 'phone' => '010-1234-5678', 'password' => 'password123',
        ])->assertRedirect(route('console.dashboard'));

        $this->assertDatabaseHas('users', ['email' => 'a@rf.kr', 'phone' => '01012345678']);
        $this->assertNotNull(User::where('email', 'a@rf.kr')->first()->phone_verified_at);
    }

    public function test_phone_send_and_verify_flow(): void
    {
        $res = $this->postJson('/phone/send-code', ['phone' => '010-1234-5678'])->assertOk();
        $res->assertJson(['ok' => true]);
        $code = $res->json('dev_code');
        $this->assertNotEmpty($code);

        $this->postJson('/phone/verify-code', ['phone' => '010-1234-5678', 'code' => $code])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('01012345678', session('phone_verified'));

        // 틀린 코드는 실패
        $this->postJson('/phone/verify-code', ['phone' => '010-9999-8888', 'code' => '000000'])
            ->assertStatus(422)->assertJson(['ok' => false]);
    }

    public function test_social_callback_links_existing_email(): void
    {
        $u = User::create(['name' => '기존', 'email' => 'exist@rf.kr', 'password' => 'x', 'role' => 'user']);
        $this->mockSocialite('naver', 'exist@rf.kr');

        $this->get('/auth/naver/callback')->assertRedirect(route('console.dashboard'));
        $this->assertAuthenticatedAs($u->fresh());
        $this->assertSame('naver', $u->fresh()->provider);
    }

    public function test_social_callback_new_user_goes_to_complete(): void
    {
        $this->mockSocialite('kakao', 'brand@new.kr');
        $this->get('/auth/kakao/callback')->assertRedirect(route('social.complete'));
        $this->assertSame('kakao', session('social_signup.provider'));
        $this->assertGuest();
    }

    public function test_social_complete_creates_user_with_verified_phone(): void
    {
        $this->withSession([
            'social_signup' => ['provider' => 'google', 'provider_id' => 'g-1', 'email' => 'g@rf.kr', 'name' => '구글회원'],
            'phone_verified' => '01055556666',
        ])->post('/auth/complete', [
            'name' => '구글회원', 'email' => 'g@rf.kr', 'phone' => '010-5555-6666',
        ])->assertRedirect(route('console.dashboard'));

        $this->assertDatabaseHas('users', ['email' => 'g@rf.kr', 'phone' => '01055556666', 'provider' => 'google']);
    }

    public function test_invalid_provider_is_404(): void
    {
        $this->get('/auth/facebook/redirect')->assertNotFound();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
