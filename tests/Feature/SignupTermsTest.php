<?php

namespace Tests\Feature;

use App\Domain\Member\SignupTerms;
use App\Models\MemberGrade;
use App\Models\User;
use App\Support\PhoneVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 회원가입 약관(2026-07-27) — 환경설정에서 항목·본문·필수여부를 관리하고,
 * 가입 시 필수 항목 동의가 없으면 계정이 만들어지지 않는다. 동의 이력은 회원에 기록된다.
 */
class SignupTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MemberGrade::create(['name' => '무료', 'slug' => 'free', 'is_paid' => false]);
    }

    /** 가입 폼 입력값(전화 인증 완료 상태로) */
    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => '홍길동',
            'email' => 'signup'.uniqid().'@rf.kr',
            'phone' => '01012345678',
            'password' => 'secret1234',
        ], $override);
    }

    /** 전화 인증 완료 상태(세션 phone_verified) — 가입 처리의 최종 확인을 통과시킨다. */
    private function verifyPhone(string $phone = '01012345678'): void
    {
        $this->withSession(['phone_verified' => PhoneVerification::normalize($phone)]);
    }

    /** 기본 약관 3종 — 이용약관·마케팅 수신·제3자 제공이 모두 필수로 제공된다. */
    public function test_default_terms_include_marketing_and_third_party(): void
    {
        $keys = array_column(SignupTerms::active(), 'key');

        $this->assertSame(['service', 'marketing', 'third_party'], $keys);
        $this->assertSame(['service', 'marketing', 'third_party'], SignupTerms::requiredKeys());

        // 마케팅 항목에 수신 수단이 명시돼야 한다
        $marketing = collect(SignupTerms::active())->firstWhere('key', 'marketing');
        foreach (['문자', '카카오톡', '알림톡', '이메일', '전화'] as $ch) {
            $this->assertStringContainsString($ch, $marketing['body']);
        }
    }

    /** 필수 약관에 동의하지 않으면 가입이 막힌다. */
    public function test_registration_blocked_without_required_agreement(): void
    {
        $this->verifyPhone();

        $this->post(route('register'), $this->payload([
            'terms' => ['service' => '1'],   // 마케팅·제3자 미동의
        ]))->assertSessionHasErrors(['terms.marketing', 'terms.third_party']);

        $this->assertSame(0, User::count());
    }

    /** 전부 동의하면 가입되고 동의 이력이 기록된다. */
    public function test_registration_records_agreements(): void
    {
        $this->verifyPhone();
        $email = 'agreed@rf.kr';

        $this->post(route('register'), $this->payload([
            'email' => $email,
            'terms' => ['service' => '1', 'marketing' => '1', 'third_party' => '1'],
        ]))->assertRedirect(route('console.dashboard', ['conv' => 'sign_up']));   // GTM 가입 전환 표식

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user);

        $agreed = (array) $user->term_agreements;
        $this->assertSame(['service', 'marketing', 'third_party'], array_keys($agreed));
        $this->assertNotEmpty($agreed['marketing']['agreed_at']);
        $this->assertTrue($agreed['third_party']['required']);
        $this->assertStringContainsString('마케팅', $agreed['marketing']['title']);
    }

    /** 선택 항목은 동의하지 않아도 가입된다(필수/선택은 환경설정으로 전환). */
    public function test_optional_term_does_not_block_registration(): void
    {
        SignupTerms::save([
            ['key' => 'service', 'title' => '이용약관 동의', 'body' => '본문', 'required' => true, 'is_active' => true],
            ['key' => 'marketing', 'title' => '마케팅 수신 동의', 'body' => '본문', 'required' => false, 'is_active' => true],
        ]);
        $this->verifyPhone();
        $email = 'optional@rf.kr';

        $this->post(route('register'), $this->payload([
            'email' => $email,
            'terms' => ['service' => '1'],
        ]))->assertSessionDoesntHaveErrors()->assertRedirect(route('console.dashboard', ['conv' => 'sign_up']));

        $user = User::where('email', $email)->first();
        $this->assertArrayNotHasKey('marketing', (array) $user->term_agreements);
    }

    /** 비활성 항목은 가입 화면에 나오지 않고 검증에도 쓰이지 않는다. */
    public function test_inactive_term_is_ignored(): void
    {
        SignupTerms::save([
            ['key' => 'service', 'title' => '이용약관 동의', 'body' => '본문', 'required' => true, 'is_active' => true],
            ['key' => 'legacy', 'title' => '옛 약관', 'body' => '본문', 'required' => true, 'is_active' => false],
        ]);

        $this->assertSame(['service'], SignupTerms::requiredKeys());

        $html = $this->get(route('register'))->assertOk()->getContent();
        $this->assertStringContainsString('name="terms[service]"', $html);
        $this->assertStringNotContainsString('name="terms[legacy]"', $html);
    }

    /** 가입 화면에 약관 제목·본문·필수 표시가 렌더된다. */
    public function test_register_page_renders_terms(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertStringContainsString('전체 동의', $html);
        $this->assertStringContainsString('마케팅·이벤트 정보 수신 동의', $html);
        $this->assertStringContainsString('제3자 마케팅 정보 제공 동의', $html);
        $this->assertStringContainsString('카카오톡', $html);        // 본문의 수신 수단
    }

    /** 관리자 환경설정에서 약관을 저장하면 가입 화면에 반영된다. */
    public function test_admin_saves_terms_and_signup_reflects(): void
    {
        $admin = User::create(['name' => '관리자', 'email' => 'adm-terms@rf.kr', 'password' => 'secret1234', 'role' => 'super']);

        $this->actingAs($admin)->post(route('admin.settings.terms'), [
            'terms' => [
                ['key' => 'service', 'title' => '이용약관 동의', 'body' => '새 본문', 'required' => '1', 'is_active' => '1'],
                ['key' => 'marketing', 'title' => '마케팅 수신(문자·카카오톡·알림톡·이메일·전화)', 'body' => '수신 본문', 'required' => '1', 'is_active' => '1'],
                ['key' => '', 'title' => '빈 항목', 'body' => '무시', 'required' => '1', 'is_active' => '1'],   // 키 없으면 버림
            ],
        ])->assertSessionDoesntHaveErrors()->assertRedirect();

        $this->assertSame(['service', 'marketing'], array_column(SignupTerms::all(), 'key'));

        // 가입 화면은 비로그인(guest)만 접근 가능 — 관리자 세션을 끊고 확인
        auth()->logout();
        $html = $this->get(route('register'))->assertOk()->getContent();
        $this->assertStringContainsString('마케팅 수신(문자·카카오톡·알림톡·이메일·전화)', $html);
    }
}
