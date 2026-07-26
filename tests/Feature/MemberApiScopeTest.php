<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 회원별 API 기능 권한(2026-07-26) — 관리자가 허용한 기능만 키 발급·호출이 된다.
 * 기본은 "허용 없음"(다 열어주지 않는다). 슈퍼관리자만 예외로 전체.
 */
class MemberApiScopeTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $scopes = []): User
    {
        return User::create([
            'name' => '회원', 'email' => 'm'.uniqid().'@rf.kr', 'password' => 'secret1234',
            'api_scopes' => $scopes,
        ]);
    }

    private function admin(): User
    {
        return User::create(['name' => '관리자', 'email' => 'adm'.uniqid().'@rf.kr', 'password' => 'secret1234', 'role' => 'super']);
    }

    /** 기본값 — 아무 권한도 없다(전부 차단). */
    public function test_default_has_no_api_scopes(): void
    {
        $user = User::create(['name' => '신규', 'email' => 'new@rf.kr', 'password' => 'secret1234']);

        $this->assertSame([], $user->allowedApiScopes());
        $this->assertFalse($user->canUseApiScope('order'));
    }

    /** 슈퍼관리자는 전체 기능 사용 가능. */
    public function test_super_admin_has_all_scopes(): void
    {
        $this->assertSame(array_keys(ApiKey::SCOPES), $this->admin()->allowedApiScopes());
    }

    /** 허용되지 않은 기능은 키에 담을 수 없다(발급 차단). */
    public function test_cannot_issue_key_with_disallowed_scope(): void
    {
        $user = $this->member(['rank']);

        $this->actingAs($user)->post(route('console.api-keys.store'), [
            'name' => '주문 연동', 'scopes' => ['order'],
        ])->assertSessionHasErrors('scopes.0');

        $this->assertSame(0, $user->apiKeys()->count());
    }

    /** 허용된 기능만 담으면 발급된다. */
    public function test_issues_key_with_allowed_scope(): void
    {
        $user = $this->member(['rank']);

        $this->actingAs($user)->post(route('console.api-keys.store'), [
            'name' => '순위 연동', 'scopes' => ['rank'],
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(['rank'], $user->apiKeys()->first()->scopes);
    }

    /** 권한이 하나도 없으면 발급 자체가 막히고 화면에 안내가 뜬다. */
    public function test_member_without_scopes_cannot_issue_and_sees_notice(): void
    {
        $user = $this->member([]);

        $this->actingAs($user)->post(route('console.api-keys.store'), [
            'name' => '아무거나', 'scopes' => ['rank'],
        ])->assertSessionHasErrors('scopes');

        $html = $this->actingAs($user)->get(route('console.api-keys'))->assertOk()->getContent();
        $this->assertStringContainsString('사용 가능한 API 기능이 없습니다', $html);
    }

    /** 발급 화면에는 허용된 기능만 보인다. */
    public function test_issue_form_lists_only_allowed_scopes(): void
    {
        $html = $this->actingAs($this->member(['rank']))->get(route('console.api-keys'))->assertOk()->getContent();

        $this->assertStringContainsString('value="rank"', $html);
        $this->assertStringNotContainsString('value="order"', $html);        // 허용 안 된 기능은 선택지에 없음
        $this->assertStringNotContainsString('value="shop_keyword"', $html);
    }

    /** ★ 권한 회수 — 이미 발급된 키도 즉시 차단된다(런타임 검사). */
    public function test_existing_key_blocked_when_scope_revoked(): void
    {
        $user = $this->member(['rank']);
        [, $plain] = ApiKey::issue($user, '순위 연동', ['rank'], null, null, null);

        // 권한이 있을 때는 통과(라우트까지 도달 — 401/403 이 아님)
        $ok = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/rank/slots');
        $this->assertNotSame(403, $ok->status());

        // 관리자가 권한 회수 → 같은 키가 403
        $user->update(['api_scopes' => []]);

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/rank/slots')
            ->assertStatus(403)
            ->assertJsonPath('allowed_scopes', []);
    }

    /** 관리자 화면에서 회원별 기능 권한을 편집한다. */
    public function test_admin_edits_member_api_scopes(): void
    {
        $admin = $this->admin();
        $user = $this->member([]);

        $this->actingAs($admin)->put(route('admin.members.update', $user), [
            'name' => $user->name, 'api_scopes' => ['rank', 'shop_keyword'],
        ])->assertSessionDoesntHaveErrors()->assertRedirect();

        $this->assertSame(['rank', 'shop_keyword'], $user->fresh()->allowedApiScopes());

        // 전부 해제하면 사용 불가로 되돌아간다
        $this->actingAs($admin)->put(route('admin.members.update', $user), ['name' => $user->name])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame([], $user->fresh()->allowedApiScopes());
    }

    /** 관리자 회원 목록에 기능별 체크박스가 노출된다. */
    public function test_admin_member_form_has_scope_checkboxes(): void
    {
        $this->member(['rank']);

        $html = $this->actingAs($this->admin())->get(route('admin.members'))->assertOk()->getContent();
        $this->assertStringContainsString('name="api_scopes[]"', $html);
        $this->assertStringContainsString('API 사용 권한', $html);
    }
}
