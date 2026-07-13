<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * 전화번호 SMS 인증(알리고). 코드는 캐시에 5분 보관, 재발송은 60초 제한.
 * 검증 성공 시 세션('phone_verified')에 번호를 저장 → 가입 처리에서 대조한다.
 */
class PhoneVerification
{
    private const TTL = 300;       // 코드 유효시간(초)
    private const RESEND = 60;     // 재발송 최소 간격(초)

    public static function normalize(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    /** 형식 검증(국내 휴대폰 010~019, 10~11자리). */
    public static function valid(string $phone): bool
    {
        return (bool) preg_match('/^01[016789]\d{7,8}$/', self::normalize($phone));
    }

    /**
     * 인증코드 발송.
     *
     * @return array{ok:bool, resend:bool, dev_code:?string}
     *   resend=true: 아직 재발송 대기(60초) 중 / dev_code: 알리고 미설정 시만 노출(개발용)
     */
    public static function send(string $phone): array
    {
        $phone = self::normalize($phone);
        $sentKey = "phone:sent:{$phone}";
        if (Cache::has($sentKey)) {
            return ['ok' => false, 'resend' => true, 'dev_code' => null];
        }

        $code = (string) random_int(100000, 999999);
        Cache::put("phone:code:{$phone}", $code, self::TTL);
        Cache::put($sentKey, 1, self::RESEND);

        $ok = Aligo::sendSms($phone, "[랭크프리] 인증번호 [{$code}] 를 입력해 주세요.");

        return ['ok' => $ok, 'resend' => false, 'dev_code' => Aligo::configured() ? null : $code];
    }

    /** 코드 검증 — 성공 시 세션에 인증완료 번호 저장 후 true. */
    public static function verify(string $phone, string $code): bool
    {
        $phone = self::normalize($phone);
        $saved = Cache::get("phone:code:{$phone}");
        if ($saved !== null && hash_equals((string) $saved, trim($code))) {
            Cache::forget("phone:code:{$phone}");
            session()->put('phone_verified', $phone);

            return true;
        }

        return false;
    }

    /** 세션의 인증완료 번호가 입력값과 일치하는지(가입 처리에서 최종 확인). */
    public static function isVerified(string $phone): bool
    {
        $v = session('phone_verified');

        return $v !== null && self::normalize((string) $v) === self::normalize($phone);
    }

    public static function clear(): void
    {
        session()->forget('phone_verified');
    }
}
