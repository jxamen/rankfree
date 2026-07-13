<?php

namespace App\Http\Controllers;

use App\Models\MemberGrade;
use App\Models\User;
use App\Support\PhoneVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * 소셜 로그인/가입 — google(내장) / naver / kakao(SocialiteProviders).
 * 흐름: redirect → callback → (기존계정 로그인 | 이메일 자동연결 | 신규는 전화번호 인증 후 가입).
 */
class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google', 'kakao'];

    /** 소셜 인증 페이지로 이동. */
    public function redirect(string $provider)
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        return Socialite::driver($provider)->redirect();
    }

    /** 소셜 콜백 처리. */
    public function callback(string $provider, Request $request)
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        try {
            $su = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            Log::warning('[Social] callback 실패', ['provider' => $provider, 'error' => $e->getMessage()]);

            return redirect()->route('login')->withErrors(['email' => '소셜 로그인에 실패했습니다. 다시 시도해 주세요.']);
        }

        $providerId = (string) $su->getId();
        $email = $su->getEmail();
        $name = $su->getName() ?: $su->getNickname() ?: '회원';

        // 1) 이미 이 소셜로 가입한 계정 → 로그인
        $user = User::where('provider', $provider)->where('provider_id', $providerId)->first();
        if ($user) {
            Auth::login($user, true);

            return redirect()->intended(route('console.dashboard'));
        }

        // 2) 같은 이메일의 기존 계정 → 자동 연결(이메일 검증된 소셜만 신뢰)
        if ($email) {
            $existing = User::where('email', $email)->first();
            if ($existing) {
                $existing->forceFill(['provider' => $provider, 'provider_id' => $providerId])->save();
                Auth::login($existing, true);

                return redirect()->intended(route('console.dashboard'));
            }
        }

        // 3) 신규 → 전화번호 인증 후 가입 완료(세션에 소셜 정보 보관)
        session()->put('social_signup', [
            'provider' => $provider,
            'provider_id' => $providerId,
            'email' => $email,
            'name' => $name,
        ]);
        PhoneVerification::clear();

        return redirect()->route('social.complete');
    }

    /** 소셜 신규가입 마무리 — 전화번호 인증 폼. */
    public function complete(Request $request)
    {
        $social = session('social_signup');
        if (! $social) {
            return redirect()->route('register');
        }

        return view('auth.social-complete', ['social' => $social]);
    }

    /** 소셜 신규가입 마무리 처리 — 전화번호 인증 완료 시 계정 생성 + 로그인. */
    public function completeStore(Request $request)
    {
        $social = session('social_signup');
        if (! $social) {
            return redirect()->route('register');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $phone = PhoneVerification::normalize($data['phone']);
        if (! PhoneVerification::isVerified($phone)) {
            return back()->withErrors(['phone' => '전화번호 인증을 완료해 주세요.'])->withInput();
        }
        if (User::where('phone', $phone)->exists()) {
            return back()->withErrors(['phone' => '이미 가입된 전화번호입니다.'])->withInput();
        }

        $superAdmins = array_map('strtolower', (array) config('rankfree.super_admins', []));
        $role = in_array(strtolower($data['email']), $superAdmins, true) ? 'super' : 'user';

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $phone,
            'phone_verified_at' => now(),
            'provider' => $social['provider'],
            'provider_id' => $social['provider_id'],
            'password' => Str::password(24), // 소셜 전용 — 임의 비밀번호(로그인은 소셜로만)
            'role' => $role,
            'grade_id' => MemberGrade::where('slug', 'free')->value('id'),
        ]);

        session()->forget('social_signup');
        PhoneVerification::clear();
        Auth::login($user, true);

        return redirect()->route('console.dashboard');
    }
}
