<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile — 비회원 무료 순위조회 봇 차단.
 * 키가 설정되지 않으면 검증을 생략(로컬/미배포에서 폼이 막히지 않게).
 */
class Turnstile
{
    /** 위젯에 쓸 공개 사이트 키. 미설정 시 null → 위젯 미표시. */
    public static function siteKey(): ?string
    {
        return config('services.turnstile.key') ?: null;
    }

    public static function configured(): bool
    {
        return (bool) (config('services.turnstile.key') && config('services.turnstile.secret'));
    }

    /**
     * 토큰 검증. 미설정 → 통과(생략). 설정됐는데 토큰 없음 → 실패(봇/미완료).
     * 명시적 실패(success=false)만 차단하고, 우리/CF 인프라 오류는 통과시켜 정상 유저를 막지 않는다.
     */
    public static function verify(?string $token, ?string $ip = null): bool
    {
        $secret = config('services.turnstile.secret');
        if (! $secret) {
            return true; // 미설정 → 검증 생략
        }
        if (! $token) {
            return false; // 설정됐는데 토큰 없음 → 통과 불가
        }

        try {
            $resp = Http::asForm()->timeout(8)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));

            if (! $resp->ok()) {
                return true; // CF/네트워크 오류 → 정상 유저 차단하지 않음
            }

            return (bool) $resp->json('success');
        } catch (\Throwable) {
            return true; // 예외 → 통과(정상 유저 보호)
        }
    }
}
