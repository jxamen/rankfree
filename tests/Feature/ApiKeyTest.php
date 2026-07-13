<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 외부 API 키 — 발급·scope·만료·허용 IP·일일 한도 검증. */
class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email = 'tester@rankfree.kr'): User
    {
        return User::create(['name' => '테스터', 'email' => $email, 'password' => 'secret1234']);
    }

    public function test_console_page_renders(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get('/console/api-keys')
            ->assertOk()
            ->assertSee('API 키')
            ->assertSee('키 발급');
    }

    public function test_console_issues_key_once(): void
    {
        $user = $this->makeUser();

        $res = $this->actingAs($user)->post('/console/api-keys', [
            'name' => '테스트 연동',
            'scopes' => ['rank', 'keyword'],
            'daily_limit' => 100,
            'allowed_ips' => '',
        ]);

        $res->assertRedirect()->assertSessionHas('newApiKey');
        $this->assertDatabaseCount('api_keys', 1);
        $plain = session('newApiKey');
        $this->assertStringStartsWith('rk_', $plain);
        // DB에는 해시만 저장
        $this->assertNotSame($plain, ApiKey::first()->key_hash);
    }

    public function test_rank_scope_allows_rank_endpoint(): void
    {
        $user = $this->makeUser();
        [, $plain] = ApiKey::issue($user, '연동', ['rank'], null, null, null);

        $this->getJson('/api/v1/rank/slots', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()
            ->assertJsonStructure(['used', 'limit', 'slots']);
    }

    public function test_missing_scope_is_forbidden(): void
    {
        $user = $this->makeUser();
        [, $plain] = ApiKey::issue($user, '키워드 전용', ['keyword'], null, null, null);

        $this->getJson('/api/v1/rank/slots', ['Authorization' => 'Bearer '.$plain])
            ->assertForbidden();
    }

    public function test_expired_key_is_rejected(): void
    {
        $user = $this->makeUser();
        [, $plain] = ApiKey::issue($user, '만료 키', ['rank'], now()->subDay(), null, null);

        $this->getJson('/api/v1/rank/slots', ['Authorization' => 'Bearer '.$plain])
            ->assertUnauthorized();
    }

    public function test_inactive_key_is_rejected(): void
    {
        $user = $this->makeUser();
        [$key, $plain] = ApiKey::issue($user, '중지 키', ['rank'], null, null, null);
        $key->update(['is_active' => false]);

        $this->getJson('/api/v1/rank/slots', ['Authorization' => 'Bearer '.$plain])
            ->assertUnauthorized();
    }

    public function test_disallowed_ip_is_forbidden(): void
    {
        $user = $this->makeUser();
        // 테스트 요청 IP 는 127.0.0.1 — 다른 IP 만 허용
        [, $plain] = ApiKey::issue($user, 'IP 제한 키', ['rank'], null, null, '10.9.9.9');

        $this->getJson('/api/v1/rank/slots', ['Authorization' => 'Bearer '.$plain])
            ->assertForbidden();
    }

    public function test_wildcard_ip_is_allowed(): void
    {
        $user = $this->makeUser();
        [, $plain] = ApiKey::issue($user, '와일드카드 키', ['rank'], null, null, '127.0.0.*');

        $this->getJson('/api/v1/rank/slots', ['Authorization' => 'Bearer '.$plain])
            ->assertOk();
    }

    public function test_daily_limit_returns_429(): void
    {
        $user = $this->makeUser();
        [, $plain] = ApiKey::issue($user, '한도 1', ['rank'], null, 1, null);

        $this->getJson('/api/v1/rank/slots', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '1');
        $this->getJson('/api/v1/rank/slots', ['Authorization' => 'Bearer '.$plain])
            ->assertStatus(429);
    }

    public function test_no_key_is_unauthorized(): void
    {
        $this->getJson('/api/v1/rank/slots')->assertUnauthorized();
        $this->getJson('/api/v1/keyword?keyword=x')->assertUnauthorized();
        $this->getJson('/api/v1/keyword/detail?keyword=x')->assertUnauthorized();
        $this->getJson('/api/v1/compete/tracks')->assertUnauthorized();
    }

    /** 키워드 경량/상세는 scope 가 분리되어 서로의 엔드포인트를 호출할 수 없다. */
    public function test_keyword_light_and_detail_scopes_are_separate(): void
    {
        $user = $this->makeUser();
        [, $light] = ApiKey::issue($user, '경량', ['keyword'], null, null, null);
        [, $detail] = ApiKey::issue($user, '상세', ['keyword_detail'], null, null, null);

        // 경량 키 → 상세 호출 거부
        $this->getJson('/api/v1/keyword/detail?keyword=테스트', ['Authorization' => 'Bearer '.$light])
            ->assertForbidden();
        // 상세 키 → 경량 호출 거부
        $this->getJson('/api/v1/keyword?keyword=테스트', ['Authorization' => 'Bearer '.$detail])
            ->assertForbidden();
        // 경량 키 → 경량 호출 통과 (테스트 환경엔 자격증명이 없어 data null 200)
        $this->getJson('/api/v1/keyword?keyword=테스트', ['Authorization' => 'Bearer '.$light])
            ->assertOk();
        // 상세 키 → 상세 호출은 scope 통과 후 소스 세션 부재로 503 (라우팅·권한 검증)
        $this->getJson('/api/v1/keyword/detail?keyword=테스트', ['Authorization' => 'Bearer '.$detail])
            ->assertStatus(503);
    }
}
