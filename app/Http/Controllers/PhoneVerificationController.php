<?php

namespace App\Http\Controllers;

use App\Support\PhoneVerification;
use Illuminate\Http\Request;

/**
 * 전화번호 SMS 인증(알리고) — 가입 폼(일반·소셜)에서 AJAX 로 호출.
 */
class PhoneVerificationController extends Controller
{
    /** 인증코드 발송. */
    public function send(Request $request)
    {
        $phone = (string) $request->input('phone', '');
        if (! PhoneVerification::valid($phone)) {
            return response()->json(['ok' => false, 'message' => '올바른 휴대폰 번호를 입력하세요.'], 422);
        }

        $r = PhoneVerification::send($phone);
        if ($r['resend']) {
            return response()->json(['ok' => false, 'message' => '잠시 후(60초) 다시 시도해 주세요.'], 429);
        }
        if (! $r['ok']) {
            return response()->json(['ok' => false, 'message' => '문자 발송에 실패했습니다. 잠시 후 다시 시도해 주세요.'], 500);
        }

        return response()->json([
            'ok' => true,
            'message' => '인증번호를 발송했습니다. 3분 내에 입력해 주세요.',
            'dev_code' => $r['dev_code'], // 알리고 미설정(개발)일 때만 코드 노출
        ]);
    }

    /** 인증코드 검증. */
    public function verify(Request $request)
    {
        $phone = (string) $request->input('phone', '');
        $code = (string) $request->input('code', '');

        if (PhoneVerification::verify($phone, $code)) {
            return response()->json(['ok' => true, 'message' => '인증되었습니다.']);
        }

        return response()->json(['ok' => false, 'message' => '인증번호가 올바르지 않거나 만료되었습니다.'], 422);
    }
}
