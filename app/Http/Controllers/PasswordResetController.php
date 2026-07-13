<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * 비밀번호 찾기(재설정) — Laravel Password 브로커 사용.
 * 소셜 전용 계정도 이 흐름으로 비밀번호를 설정하면 이메일/비밀번호 로그인이 가능해진다.
 */
class PasswordResetController extends Controller
{
    /** 재설정 링크 요청 폼. */
    public function request()
    {
        return view('auth.forgot-password');
    }

    /** 재설정 링크 발송. 이메일 존재 여부는 노출하지 않는다(계정 열거 방지). */
    public function email(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        return back()->with('status', '가입된 이메일이라면 비밀번호 재설정 링크를 보냈습니다. 메일함을 확인해 주세요.');
    }

    /** 재설정 폼(토큰). */
    public function reset(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    /** 새 비밀번호 저장. */
    public function update(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', '비밀번호가 변경되었습니다. 새 비밀번호로 로그인해 주세요.');
        }

        return back()->withErrors(['email' => __($status)])->onlyInput('email');
    }
}
