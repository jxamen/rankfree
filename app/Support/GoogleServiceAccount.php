<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 구글 서비스 계정 JWT(RS256) → access token 발급 공용 헬퍼.
 * .env GOOGLE_SERVICE_ACCOUNT_JSON=키 파일 경로. 스코프별 토큰을 짧게 캐시(50분).
 * 사용처: 외부 발주 구글시트 append · 서치 콘솔 검색 성과 수집.
 */
class GoogleServiceAccount
{
    public static function configured(): bool
    {
        $path = self::keyPath();

        return $path !== '' && is_file($path);
    }

    /** 서비스 계정 이메일(서치 콘솔 속성/시트 공유 대상 안내용). 미설정 시 null. */
    public static function clientEmail(): ?string
    {
        $sa = self::readKey();

        return $sa['client_email'] ?? null;
    }

    /** @param string $scope 공백 구분 다중 스코프 허용 */
    public static function token(string $scope): ?string
    {
        $sa = self::readKey();
        if (! $sa) {
            return null;
        }

        return Cache::remember('google-sa-token:'.md5($scope), 3000, function () use ($sa, $scope) {
            $b64 = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
            $now = time();
            $jwtBody = $b64(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$b64([
                'iss' => $sa['client_email'],
                'scope' => $scope,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now, 'exp' => $now + 3600,
            ]);
            if (! openssl_sign($jwtBody, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
                return null;
            }
            $jwt = $jwtBody.'.'.rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

            $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            return $res->successful() ? ($res->json('access_token') ?: null) : null;
        });
    }

    private static function keyPath(): string
    {
        return (string) config('services.google_sheets.credentials', env('GOOGLE_SERVICE_ACCOUNT_JSON', ''));
    }

    private static function readKey(): ?array
    {
        if (! self::configured()) {
            return null;
        }
        $sa = json_decode((string) file_get_contents(self::keyPath()), true);

        return is_array($sa) && ! empty($sa['client_email']) && ! empty($sa['private_key']) ? $sa : null;
    }
}
