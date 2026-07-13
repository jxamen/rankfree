<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** 비밀번호 찾기(재설정) + 아이디(이메일) 찾기. */
class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_login_page_has_recovery_links(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('비밀번호 찾기')->assertSee('아이디 찾기');
    }

    public function test_forgot_password_sends_reset_link(): void
    {
        Notification::fake();
        $u = User::create(['name' => 'u', 'email' => 'r@rf.kr', 'password' => 'oldpass123']);

        $this->post('/forgot-password', ['email' => 'r@rf.kr'])->assertSessionHas('status');
        Notification::assertSentTo($u, ResetPassword::class);
    }

    public function test_reset_password_updates_and_allows_login(): void
    {
        $u = User::create(['name' => 'u', 'email' => 'r@rf.kr', 'password' => 'oldpass123']);
        $token = Password::createToken($u);

        $this->post('/reset-password', [
            'token' => $token, 'email' => 'r@rf.kr',
            'password' => 'newpass456', 'password_confirmation' => 'newpass456',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        // 소셜/비번없음 계정도 이 흐름으로 비번을 설정하면 이메일 로그인 가능
        $this->assertTrue(Auth::attempt(['email' => 'r@rf.kr', 'password' => 'newpass456']));
    }

    public function test_find_email_by_verified_phone_masks_result(): void
    {
        User::create(['name' => 'u', 'email' => 'finder@rf.kr', 'phone' => '01033334444', 'password' => 'x', 'provider' => 'google']);

        $res = $this->withSession(['phone_verified' => '01033334444'])
            ->postJson('/find-email', ['phone' => '010-3333-4444'])
            ->assertOk()->assertJson(['ok' => true, 'found' => true, 'provider' => 'google']);

        $this->assertStringContainsString('@rf.kr', $res->json('email'));
        $this->assertStringContainsString('*', $res->json('email')); // 마스킹됨
    }

    public function test_find_email_requires_phone_verification(): void
    {
        User::create(['name' => 'u', 'email' => 'finder@rf.kr', 'phone' => '01033334444', 'password' => 'x']);
        // 세션 인증 없이 → 거부
        $this->postJson('/find-email', ['phone' => '010-3333-4444'])
            ->assertStatus(422)->assertJson(['ok' => false]);
    }

    public function test_find_email_unknown_phone_returns_not_found(): void
    {
        $this->withSession(['phone_verified' => '01099998888'])
            ->postJson('/find-email', ['phone' => '010-9999-8888'])
            ->assertOk()->assertJson(['ok' => true, 'found' => false]);
    }
}
