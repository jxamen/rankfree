<?php

namespace App\Http\Controllers;

use App\Models\MemberGrade;
use App\Models\User;
use App\Support\PhoneVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function showLogin(Request $request)
    {
        // 로그인 후 직전 페이지로 복귀 (헤더의 from 파라미터 우선)
        $from = $request->query('from');
        if (is_string($from) && $from !== '' && ! str_contains($from, '/login') && ! str_contains($from, '/register')) {
            session()->put('url.intended', $from);
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $cred = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($cred, $request->boolean('remember'))) {
            $request->session()->regenerate();

            return redirect()->intended(route('console.dashboard'));
        }

        return back()
            ->withErrors(['email' => '이메일 또는 비밀번호가 올바르지 않습니다.'])
            ->onlyInput('email');
    }

    public function showRegister(Request $request)
    {
        // 추천 링크(?ref=CODE) — 세션에 담아 가입 완료 시 백엔드에서 자동 처리(폼 노출 없음)
        if (($ref = trim((string) $request->query('ref', ''))) !== '') {
            session([\App\Domain\Member\ReferralService::SESSION_KEY => $ref]);
        }

        return view('auth.register');
    }

    public function register(Request $request)
    {
        // 약관 동의(2026-07-27) — 필수 항목은 반드시 체크해야 가입된다(항목은 환경설정에서 관리)
        $terms = \App\Domain\Member\SignupTerms::active();
        $termRules = [];
        $termMessages = [];
        foreach ($terms as $t) {
            if (! empty($t['required'])) {
                $termRules['terms.'.$t['key']] = ['accepted'];
                $termMessages['terms.'.$t['key'].'.accepted'] = "'{$t['title']}'에 동의해 주세요.";
            }
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', Password::min(8)],
        ] + $termRules, $termMessages);

        // 전화번호 SMS 인증 필수 — 세션의 인증완료 번호와 대조
        $phone = PhoneVerification::normalize($data['phone']);
        if (! PhoneVerification::isVerified($phone)) {
            return back()->withErrors(['phone' => '전화번호 인증을 완료해 주세요.'])->onlyInput('name', 'email', 'phone');
        }
        if (User::where('phone', $phone)->exists()) {
            return back()->withErrors(['phone' => '이미 가입된 전화번호입니다.'])->onlyInput('name', 'email', 'phone');
        }

        // 최상위 이메일이면 super, 그 외는 일반 회원 + 무료 등급 자동 배정
        // (등급이 없으면 콘솔 메뉴 권한이 비어 사이드바가 비어 보이므로 free 기본 배정)
        $superAdmins = array_map('strtolower', (array) config('rankfree.super_admins', []));
        $role = in_array(strtolower($data['email']), $superAdmins, true) ? 'super' : 'user';

        // 동의 이력 — 마케팅·제3자 제공은 "언제 무엇에 동의했는지" 근거가 필요해 제목까지 스냅샷으로 남긴다
        $agreed = [];
        foreach ($terms as $t) {
            if ($request->boolean('terms.'.$t['key'])) {
                $agreed[$t['key']] = [
                    'title' => $t['title'],
                    'required' => (bool) $t['required'],
                    'agreed_at' => now()->toIso8601String(),
                    'ip' => $request->ip(),
                ];
            }
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $phone,
            'phone_verified_at' => now(),
            'password' => $data['password'],
            'role' => $role,
            'grade_id' => MemberGrade::where('slug', 'free')->value('id'),
            'term_agreements' => $agreed ?: null,
        ]);

        PhoneVerification::clear();
        app(\App\Domain\Member\ReferralService::class)->apply($user);   // 추천 링크 가입 자동 처리
        Auth::login($user);

        // GTM 회원가입 전환 신호 — 도착 페이지에 ?signup=1 표식, dataLayer.push({event:'sign_up'}) 1회 후 URL 정리(새로고침·재로그인 중복 없음)
        return redirect()->route('console.dashboard', ['signup' => 1]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
