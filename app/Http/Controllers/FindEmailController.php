<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PhoneVerification;
use Illuminate\Http\Request;

/**
 * 아이디(이메일) 찾기 — 가입 시 인증한 전화번호로 이메일을 찾는다.
 * 전화번호 SMS 인증(PhoneVerification) 완료 후에만 조회 가능하며, 이메일은 마스킹해 보여준다.
 */
class FindEmailController extends Controller
{
    public function show()
    {
        return view('auth.find-email');
    }

    /** 전화 인증 완료 시 해당 번호의 가입 이메일(마스킹) 반환. */
    public function find(Request $request)
    {
        $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $phone = PhoneVerification::normalize((string) $request->input('phone'));

        if (! PhoneVerification::isVerified($phone)) {
            return response()->json(['ok' => false, 'message' => '전화번호 인증을 먼저 완료해 주세요.'], 422);
        }

        $user = User::where('phone', $phone)->first();
        if (! $user) {
            return response()->json(['ok' => true, 'found' => false, 'message' => '해당 번호로 가입된 계정이 없습니다.']);
        }

        return response()->json([
            'ok' => true,
            'found' => true,
            'email' => self::mask($user->email),
            'provider' => $user->provider, // google|kakao|null(이메일가입)
        ]);
    }

    /** 이메일 마스킹 — 앞 3자만 노출. */
    private static function mask(string $email): string
    {
        [$id, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $keep = mb_substr($id, 0, min(3, mb_strlen($id)));

        return $keep.str_repeat('*', max(1, mb_strlen($id) - mb_strlen($keep))).'@'.$domain;
    }
}
