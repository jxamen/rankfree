<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 구글 API 액세스 토큰 공급자 — 서치 콘솔·GA4 공용.
 *  1순위: 관리자 OAuth 연동(환경설정 [구글 계정으로 연동] — 소셜 로그인 클라이언트 재사용, refresh token 저장)
 *  2순위: 서비스 계정 키(GOOGLE_SERVICE_ACCOUNT_JSON) 폴백
 */
class GoogleToken
{
    public const KEY_REFRESH = 'google_oauth.refresh_token';

    public const KEY_EMAIL = 'google_oauth.email';

    public const KEY_SCOPES = 'google_oauth.scopes';

    /** OAuth 연동 완료 여부. */
    public static function oauthConnected(): bool
    {
        return trim((string) AppSetting::read(self::KEY_REFRESH)) !== '';
    }

    /** 연동된 구글 계정 이메일(표시용). */
    public static function connectedEmail(): ?string
    {
        return AppSetting::read(self::KEY_EMAIL) ?: null;
    }

    /** OAuth 또는 서비스 계정 중 하나라도 사용 가능한지. */
    public static function available(): bool
    {
        return self::oauthConnected() || GoogleServiceAccount::configured();
    }

    /** 스코프에 맞는 액세스 토큰 — OAuth 우선, 서비스 계정 폴백. 실패 시 null. */
    public static function token(string $scope): ?string
    {
        if (self::oauthConnected() && str_contains((string) AppSetting::read(self::KEY_SCOPES), $scope)) {
            if ($t = self::refreshOauth()) {
                return $t;
            }
        }

        return GoogleServiceAccount::token($scope);
    }

    /** 연동 해제 — 저장된 refresh token 제거. */
    public static function disconnect(): void
    {
        foreach ([self::KEY_REFRESH, self::KEY_EMAIL, self::KEY_SCOPES] as $k) {
            AppSetting::write($k, '');
        }
        Cache::forget('google-oauth-access');
    }

    private static function refreshOauth(): ?string
    {
        return Cache::remember('google-oauth-access', 3000, function () {
            $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => (string) config('services.google.client_id'),
                'client_secret' => (string) config('services.google.client_secret'),
                'refresh_token' => (string) AppSetting::read(self::KEY_REFRESH),
                'grant_type' => 'refresh_token',
            ]);

            return $res->successful() ? ($res->json('access_token') ?: null) : null;
        });
    }
}
