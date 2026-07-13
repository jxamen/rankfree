<?php

namespace Tests\Feature;

use App\Models\MarketingLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/** 마케팅 리드(상담 문의) 접수·조회. 공개 접수 + 슈퍼어드민 관리. */
class MarketingLeadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class); // 접수 스로틀은 이 테스트 관심사 아님
    }

    private function user(string $email = 'u@rf.kr', string $role = 'user'): User
    {
        return User::create(['name' => 'u', 'email' => $email, 'password' => 'x1234567', 'role' => $role]);
    }

    public function test_public_guest_can_submit_lead(): void
    {
        $res = $this->postJson('/lead', [
            'name' => '홍길동', 'phone' => '010-1234-5678',
            'keyword' => '수영복', 'source' => 'market_seasonal', 'interest' => '순위 상승 프로그램',
            'peak_months' => '6,7,8', 'prep_months' => '4,5,6', 'strength' => '뚜렷함',
            'message' => '여름 전에 올리고 싶어요',
        ]);

        $res->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('marketing_leads', [
            'name' => '홍길동', 'phone' => '010-1234-5678', 'source' => 'market_seasonal',
            'interest' => '순위 상승 프로그램', 'status' => 'new', 'user_id' => null,
        ]);
        $lead = MarketingLead::first();
        $this->assertSame([6, 7, 8], $lead->meta['peak_months']);
        $this->assertSame([4, 5, 6], $lead->meta['prep_months']);
        $this->assertTrue($lead->meta['is_public']);
    }

    public function test_name_and_phone_required(): void
    {
        $this->postJson('/lead', ['keyword' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'phone']);
    }

    public function test_invalid_phone_rejected(): void
    {
        $this->postJson('/lead', ['name' => '홍길동', 'phone' => '아무말전화'])
            ->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    public function test_honeypot_silently_drops_bot(): void
    {
        $this->postJson('/lead', ['name' => 'bot', 'phone' => '010-0000-0000', 'company' => 'AcmeBot'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseCount('marketing_leads', 0);
    }

    public function test_authenticated_user_is_attached(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson('/lead', ['name' => '회원', 'phone' => '01011112222'])
            ->assertOk();
        $this->assertSame($user->id, MarketingLead::first()->user_id);
    }

    public function test_unknown_source_normalized_to_other(): void
    {
        $this->postJson('/lead', ['name' => 'x', 'phone' => '01011112222', 'source' => 'evil<script>'])
            ->assertOk();
        $this->assertSame('other', MarketingLead::first()->source);
    }

    public function test_admin_listing_requires_super_admin(): void
    {
        $lead = MarketingLead::create(['name' => '리드', 'phone' => '01011112222', 'source' => 'market', 'status' => 'new']);

        // 일반 회원 — 403
        $this->actingAs($this->user())->get('/console/leads')->assertForbidden();

        // 슈퍼어드민 — 목록에 노출
        $this->actingAs($this->user('super@rf.kr', 'super'))->get('/console/leads')
            ->assertOk()->assertSee('리드')->assertSee('01011112222');
    }

    public function test_super_admin_can_update_status(): void
    {
        $lead = MarketingLead::create(['name' => '리드', 'phone' => '01011112222', 'source' => 'market', 'status' => 'new']);
        $this->actingAs($this->user('super@rf.kr', 'super'))
            ->put("/console/leads/{$lead->id}/status", ['status' => 'contacted'])
            ->assertRedirect();
        $this->assertSame('contacted', $lead->fresh()->status);
    }
}
