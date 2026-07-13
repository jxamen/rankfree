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

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', Password::min(8)],
            'referral' => ['nullable', 'string', 'max:32'],
        ]);

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
        // NOTE: 추천인(referral) 리워드(+20, 최대200)는 별도 단계에서 반영.
        $superAdmins = array_map('strtolower', (array) config('rankfree.super_admins', []));
        $role = in_array(strtolower($data['email']), $superAdmins, true) ? 'super' : 'user';

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $phone,
            'phone_verified_at' => now(),
            'password' => $data['password'],
            'role' => $role,
            'grade_id' => MemberGrade::where('slug', 'free')->value('id'),
        ]);

        PhoneVerification::clear();
        Auth::login($user);

        return redirect()->route('console.dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
