<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 알리고 SMS 발송 — crm lib/aligo.lib.php 이식.
 * apis.aligo.in/send/ 에 폼 POST, 응답 result_code=1 이면 성공.
 * user_id/key/sender 는 config('services.aligo.*'), 발신번호는 알리고 사전등록 필요.
 */
class Aligo
{
    /** 발송에 필요한 자격증명이 모두 설정됐는지. */
    public static function configured(): bool
    {
        return (bool) (config('services.aligo.user_id')
            && config('services.aligo.key')
            && config('services.aligo.sender'));
    }

    /**
     * 단문(SMS) 발송. 성공 시 true.
     * 미설정 시에는 개발 편의를 위해 발송을 건너뛰고 true(코드는 화면/로그로 확인).
     */
    public static function sendSms(string $to, string $msg): bool
    {
        $to = preg_replace('/\D/', '', $to);

        if (! self::configured()) {
            Log::info('[Aligo] 미설정 — 발송 생략', ['to' => $to]);

            return true;
        }

        try {
            $resp = Http::asForm()->timeout(10)->post('https://apis.aligo.in/send/', [
                'user_id' => (string) config('services.aligo.user_id'),
                'key' => (string) config('services.aligo.key'),
                'sender' => (string) config('services.aligo.sender'),
                'receiver' => $to,
                'msg' => $msg,
                'msg_type' => 'SMS',
                'testmode_yn' => config('services.aligo.test') ? 'Y' : 'N',
            ]);

            $code = (int) ($resp->json('result_code') ?? -999);
            if ($code !== 1) {
                Log::warning('[Aligo] 발송 실패', ['code' => $code, 'message' => $resp->json('message')]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[Aligo] 예외', ['msg' => $e->getMessage()]);

            return false;
        }
    }
}
