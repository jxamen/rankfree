<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * 로그인 유지(remember) 회귀 테스트 — 2026-07-30.
 * 이메일 로그인은 remember 체크박스가 기본 해제, 가입 직후 로그인은 remember 미적용이라
 * 세션(120분) 만료 시 로그아웃되던 문제. 소셜 로그인(Auth::login($user, true))과 동일하게 맞춘다.
 */
class AuthRememberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** 로그인 폼의 "로그인 상태 유지"는 기본 체크 상태여야 한다. */
    public function test_login_form_remember_checked_by_default(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('name="remember" checked', false);
    }

    /** remember 로그인 시 remember 쿠키가 심기고 토큰이 저장된다. */
    public function test_login_with_remember_sets_recaller_cookie(): void
    {
        $user = User::create([
            'name' => '테스터',
            'email' => 'remember@rankfree.kr',
            'phone' => '01011112222',
            'password' => 'secret1234',
        ]);

        $response = $this->post('/login', [
            'email' => 'remember@rankfree.kr',
            'password' => 'secret1234',
            'remember' => 'on',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $response->assertCookieNotExpired(Auth::guard('web')->getRecallerName());
        $this->assertNotEmpty($user->fresh()->remember_token);
    }

    /** 회원가입 직후 로그인도 remember 가 적용되어야 한다. */
    public function test_register_logs_in_with_remember(): void
    {
        $response = $this->withSession(['phone_verified' => '01012345678'])
            ->post('/register', [
                'name' => '신규가입',
                'email' => 'signup@rankfree.kr',
                'phone' => '010-1234-5678',
                'password' => 'secret1234',
                'terms' => ['service' => '1', 'marketing' => '1', 'third_party' => '1'],
            ]);

        $response->assertRedirect();
        $this->assertAuthenticated();
        $response->assertCookieNotExpired(Auth::guard('web')->getRecallerName());
        $this->assertNotEmpty(User::where('email', 'signup@rankfree.kr')->value('remember_token'));
    }
}
