<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 크롬 확장 API(로그인 게이트 + 키워드 분석) 흐름 검증. */
class ExtApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => '테스터',
            'email' => 'tester@rankfree.kr',
            'password' => 'secret1234',
        ]);
    }

    public function test_login_issues_token(): void
    {
        $this->makeUser();

        $res = $this->postJson('/api/ext/login', [
            'email' => 'tester@rankfree.kr',
            'password' => 'secret1234',
            'device_name' => 'chrome-extension',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']]);

        $this->assertDatabaseCount('ext_tokens', 1);
        // DB에는 해시만 저장된다
        $this->assertNotSame($res->json('token'), ExtToken::first()->token);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $this->makeUser();

        $this->postJson('/api/ext/login', [
            'email' => 'tester@rankfree.kr',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_me_requires_valid_token(): void
    {
        $this->getJson('/api/ext/me')->assertStatus(401);

        $this->getJson('/api/ext/me', ['Authorization' => 'Bearer nope'])
            ->assertStatus(401);
    }

    public function test_me_returns_user_with_token(): void
    {
        $user = $this->makeUser();
        [, $plain] = ExtToken::issue($user);

        $this->getJson('/api/ext/me', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()
            ->assertJsonPath('user.email', 'tester@rankfree.kr');
    }

    public function test_keyword_analysis_returns_null_without_credentials(): void
    {
        config(['rankfree.searchad.api_key' => '']);

        $user = $this->makeUser();
        [, $plain] = ExtToken::issue($user);

        $this->getJson('/api/ext/keyword-analysis?keyword=고구마', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_keyword_analysis_requires_keyword(): void
    {
        $user = $this->makeUser();
        [, $plain] = ExtToken::issue($user);

        $this->getJson('/api/ext/keyword-analysis', ['Authorization' => 'Bearer '.$plain])
            ->assertStatus(422);
    }

    public function test_logout_revokes_token(): void
    {
        $user = $this->makeUser();
        [, $plain] = ExtToken::issue($user);

        $this->postJson('/api/ext/logout', [], ['Authorization' => 'Bearer '.$plain])
            ->assertOk();

        $this->assertDatabaseCount('ext_tokens', 0);
        $this->getJson('/api/ext/me', ['Authorization' => 'Bearer '.$plain])
            ->assertStatus(401);
    }
}
