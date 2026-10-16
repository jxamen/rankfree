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

    /** 키 자체의 scope 동작을 보는 테스트라 회원 권한(2026-07-26)은 전부 열어 둔다. */
    private function makeUser(string $email = 'tester@rankfree.kr'): User
    {
        return User::create([
            'name' => '테스터', 'email' => $email, 'password' => 'secret1234',
            'api_scopes' => array_keys(\App\Models\ApiKey::SCOPES),
        ]);
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

    public function test_limit_changes_apply_without_resetting_usage_or_rotating_key(): void
    {
        $user = $this->makeUser();
        [$key, $plain] = ApiKey::issue($user, 'Limit test', ['rank'], null, 1, null);
        $headers = ['Authorization' => 'Bearer '.$plain];
        $this->getJson('/api/v1/rank/slots', $headers)->assertOk();

        $this->actingAs($user)->from('/console/api-keys')
            ->patch(route('console.api-keys.update-limit', $key), ['daily_limit' => 2, 'scopes' => ['order']])
            ->assertRedirect('/console/api-keys')->assertSessionHas('status');
        $this->assertSame(1, $key->usedToday());
        $this->assertSame($plain, $key->fresh()->plainKey());
        $this->assertSame(['rank'], $key->fresh()->scopes);
        $this->getJson('/api/v1/rank/slots', $headers)->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->getJson('/api/v1/rank/slots', $headers)->assertStatus(429);

        $this->actingAs($user)->patch(route('console.api-keys.update-limit', $key), ['daily_limit' => 1])->assertRedirect();
        $this->getJson('/api/v1/rank/slots', $headers)->assertStatus(429);
        $this->actingAs($user)->patch(route('console.api-keys.update-limit', $key), ['daily_limit' => ''])->assertRedirect();
        $this->assertNull($key->fresh()->daily_limit);
        $this->getJson('/api/v1/rank/slots', $headers)->assertOk();
        $this->assertSame(3, $key->usedToday());
    }

    public function test_limit_update_rejects_invalid_values_and_missing_field(): void
    {
        $user = $this->makeUser();
        [$key] = ApiKey::issue($user, 'Limit test', ['rank'], null, 100, null);
        foreach ([0, -1, 1000001, '1.5', 'invalid', []] as $value) {
            $this->actingAs($user)->patch(route('console.api-keys.update-limit', $key), ['daily_limit' => $value])
                ->assertSessionHasErrors('daily_limit', null, 'limit_'.$key->id);
            $this->assertSame(100, $key->fresh()->daily_limit);
        }
        $this->patch(route('console.api-keys.update-limit', $key), [])
            ->assertSessionHasErrors('daily_limit', null, 'limit_'.$key->id);
        $this->patch(route('console.api-keys.update-limit', $key), ['daily_limit' => 1000000])->assertRedirect();
        $this->assertSame(1000000, $key->fresh()->daily_limit);
    }

    public function test_limit_update_requires_owner(): void
    {
        [$key] = ApiKey::issue($this->makeUser(), 'Private key', ['rank'], null, 100, null);
        $this->patch(route('console.api-keys.update-limit', $key), ['daily_limit' => 200])->assertRedirect('/login');
        $this->actingAs($this->makeUser('other@example.test'))
            ->patch(route('console.api-keys.update-limit', $key), ['daily_limit' => 200])->assertForbidden();
        $this->assertSame(100, $key->fresh()->daily_limit);
    }

    public function test_owner_updates_scopes_without_changing_other_key_fields(): void
    {
        $user = $this->makeUser();
        [$key, $plain] = ApiKey::issue($user, 'Scope edit', ['keyword'], now()->addMonth(), 100, '127.0.0.1');
        $before = $key->fresh()->getAttributes();
        \Illuminate\Support\Facades\Log::spy();
        $this->actingAs($user)->from('/console/api-keys')
            ->patch(route('console.api-keys.update-scopes', $key), [
                'scopes' => ['rank'], 'daily_limit' => 1, 'allowed_ips' => '*',
                'key_hash' => 'tampered', 'key_prefix' => 'tampered',
                'expires_at' => null, 'is_active' => false,
            ])->assertRedirect('/console/api-keys')->assertSessionHas('status');
        $after = $key->fresh();
        $this->assertSame(['rank'], $after->scopes);
        foreach ($before as $field => $value) {
            if (! in_array($field, ['scopes', 'updated_at'], true)) {
                $this->assertSame($value, $after->getAttributes()[$field], $field);
            }
        }
        $this->assertSame($plain, $after->plainKey());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->once()->with(
            'api_key.scopes_updated', \Mockery::on(fn ($context) =>
                $context['actor_id'] === $user->id && $context['api_key_id'] === $key->id
                && $context['before_scopes'] === ['keyword'] && $context['after_scopes'] === ['rank']
                && isset($context['changed_at']) && count($context) === 5
            )
        );
        $headers = ['Authorization' => 'Bearer '.$plain];
        $this->getJson('/api/v1/rank/slots', $headers)->assertOk();
        $this->getJson('/api/v1/keyword?keyword=test', $headers)->assertForbidden();
        $this->get('/console/api-keys')->assertOk()->assertSee('권한 수정')
            ->assertSee(route('console.api-keys.update-scopes', $key), false);
    }

    public function test_scope_update_requires_owner(): void
    {
        [$key] = ApiKey::issue($this->makeUser(), 'Private', ['rank'], null, null, null);
        $url = route('console.api-keys.update-scopes', $key);
        $this->patch($url, ['scopes' => ['keyword']])->assertRedirect('/login');
        $this->actingAs($this->makeUser('other@example.test'))->patch($url, ['scopes' => ['keyword']])->assertForbidden();
        $this->assertSame(['rank'], $key->fresh()->scopes);
    }

    public function test_scope_validation_errors_reopen_only_the_affected_editor(): void
    {
        $user = $this->makeUser();
        [$key] = ApiKey::issue($user, 'Invalid selection', ['rank'], null, null, null);
        $this->actingAs($user)->from('/console/api-keys')
            ->patch(route('console.api-keys.update-scopes', $key), ['scopes' => []])
            ->assertSessionHasErrors('scopes', null, 'scopes_'.$key->id);
        $errors = session('errors');
        $this->view('console.api-keys', [
            'keys' => collect([$key]), 'allowedScopes' => $user->allowedApiScopes(), 'errors' => $errors,
        ])->assertSee('role="alert"', false)->assertSee('<details class="mt-2"  open ', false);
    }

    public function test_scope_update_rejects_empty_invalid_and_unallowed_scopes(): void
    {
        $user = $this->makeUser();
        $user->update(['api_scopes' => ['rank']]);
        [$key] = ApiKey::issue($user, 'Restricted', ['rank'], null, null, null);
        foreach ([[], ['keyword'], ['unknown'], ['rank', 'keyword'], ['rank', 'rank'], [null], [['rank']], 'rank'] as $scopes) {
            $this->actingAs($user)->from('/console/api-keys')
                ->patch(route('console.api-keys.update-scopes', $key), ['scopes' => $scopes])
                ->assertSessionHasErrors(null, null, 'scopes_'.$key->id);
            $this->assertSame(['rank'], $key->fresh()->scopes);
        }
        $this->patch(route('console.api-keys.update-scopes', $key), [])->assertSessionHasErrors('scopes', null, 'scopes_'.$key->id);
        $user->update(['api_scopes' => []]);
        $this->patch(route('console.api-keys.update-scopes', $key), ['scopes' => ['rank']])
            ->assertSessionHasErrors('scopes.0', null, 'scopes_'.$key->id);
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
