<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 가입 유입경로(first-touch) + GTM 회원가입 전환 회귀 테스트 — 2026-08-04.
 * 소셜 가입(구글·카카오)이 유입경로 저장도, ?conv=sign_up 표식도 하지 않아
 * 회원관리에 전부 '직접'으로 뜨고 GA4 에서 소셜 가입분이 통째로 빠지던 문제.
 */
class SignupAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * CaptureAttribution 이 심는 first-touch 쿠키 내용(평문 JSON).
     * withCookie() 는 테스트 클라이언트가 알아서 암호화하므로 평문 그대로 넘긴다.
     */
    private function attrCookie(string $referrer = '', array $utm = [], string $landing = '/'): string
    {
        return json_encode(['referrer' => $referrer, 'utm' => $utm, 'landing' => $landing], JSON_UNESCAPED_UNICODE);
    }

    /** 외부 유입(referer) 후 이메일 가입 → 유입경로 저장 + sign_up 전환 표식 */
    public function test_email_signup_saves_attribution_and_conv_marker(): void
    {
        $response = $this->withCookie('rf_attr', $this->attrCookie('https://search.naver.com/search.naver?query=순위', [], 'keyword/여름브라'))
            ->withSession(['phone_verified' => '01012345678'])
            ->post('/register', [
                'name' => '이메일가입',
                'email' => 'attr-email@rankfree.kr',
                'phone' => '010-1234-5678',
                'password' => 'secret1234',
                'terms' => ['service' => '1', 'marketing' => '1', 'third_party' => '1'],
            ]);

        $response->assertRedirect(route('console.dashboard', ['conv' => 'sign_up']));

        $user = User::where('email', 'attr-email@rankfree.kr')->firstOrFail();
        $this->assertSame('https://search.naver.com/search.naver?query=순위', $user->signup_referrer);
        $this->assertSame('keyword/여름브라', $user->signup_landing);
    }

    /** utm 유입 후 이메일 가입 → utm 이 그대로 저장된다 */
    public function test_email_signup_saves_utm(): void
    {
        $this->withCookie('rf_attr', $this->attrCookie('', ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'summer']))
            ->withSession(['phone_verified' => '01012345679'])
            ->post('/register', [
                'name' => 'utm가입',
                'email' => 'attr-utm@rankfree.kr',
                'phone' => '010-1234-5679',
                'password' => 'secret1234',
                'terms' => ['service' => '1', 'marketing' => '1', 'third_party' => '1'],
            ]);

        $utm = (array) User::where('email', 'attr-utm@rankfree.kr')->firstOrFail()->signup_utm;
        $this->assertSame('google', $utm['source'] ?? null);
        $this->assertSame('cpc', $utm['medium'] ?? null);
    }

    /** 소셜 가입도 이메일 가입과 동일하게 유입경로 저장 + ?conv=sign_up 을 달아야 한다 */
    public function test_social_signup_saves_attribution_and_conv_marker(): void
    {
        $response = $this->withCookie('rf_attr', $this->attrCookie('', ['source' => 'google', 'medium' => 'cpc']))
            ->withSession([
                'phone_verified' => '01055556666',
                'social_signup' => [
                    'provider' => 'google',
                    'provider_id' => 'g-12345',
                    'email' => 'attr-social@rankfree.kr',
                    'name' => '소셜가입',
                ],
            ])
            ->post('/auth/complete', [
                'name' => '소셜가입',
                'phone' => '010-5555-6666',
                'terms' => ['service' => '1', 'marketing' => '1', 'third_party' => '1'],
            ]);

        $response->assertRedirect(route('console.dashboard', ['conv' => 'sign_up']));

        $user = User::where('email', 'attr-social@rankfree.kr')->firstOrFail();
        $this->assertSame('google', ((array) $user->signup_utm)['source'] ?? null);
        $this->assertSame('cpc', ((array) $user->signup_utm)['medium'] ?? null);
    }

    /** 소셜 인증 왕복(accounts.google.com 등)은 유입원이 아니므로 first-touch 로 기록하지 않는다 */
    public function test_social_auth_host_referer_is_not_captured_as_inflow(): void
    {
        $this->get('/', ['referer' => 'https://kauth.kakao.com/oauth/authorize'])
            ->assertOk()
            ->assertCookieMissing('rf_attr');

        $this->get('/', ['referer' => 'https://search.naver.com/'])
            ->assertOk()
            ->assertCookie('rf_attr');
    }

    /** 로그인·가입 페이지에도 GTM(커스텀 head)이 실려야 한다 — 광고 랜딩이 /register 인 경우 gclid 유실 방지 */
    public function test_auth_pages_render_custom_head(): void
    {
        \App\Models\AppSetting::write('custom.head_html', '<script>window.__gtm_probe=1</script>');
        \Illuminate\Support\Facades\Cache::forget(\App\Models\AppSetting::CUSTOM_HEAD_CACHE);

        $this->get('/login')->assertOk()->assertSee('__gtm_probe', false);
        $this->get('/register')->assertOk()->assertSee('__gtm_probe', false);
    }
}
