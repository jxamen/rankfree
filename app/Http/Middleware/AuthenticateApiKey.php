<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 외부 API 키 인증 — Authorization: Bearer rk_… (또는 X-API-KEY 헤더).
 * 검사 순서: 키 유효 → 활성 → 만료 → 허용 IP → scope → 일일 한도(카운트 증가).
 * 미들웨어 파라미터로 scope 지정: auth.apikey:rank
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $key = ApiKey::findByPlain($request->bearerToken() ?: $request->header('X-API-KEY'));

        if ($key === null || $key->user === null) {
            return response()->json(['message' => '유효하지 않은 API 키입니다.'], 401);
        }
        if (! $key->is_active) {
            return response()->json(['message' => '비활성화된 API 키입니다.'], 401);
        }
        if ($key->expires_at !== null && $key->expires_at->isPast()) {
            return response()->json(['message' => 'API 키 유효기간이 만료되었습니다.', 'expired_at' => $key->expires_at->toIso8601String()], 401);
        }
        if (! $key->ipAllowed($request->ip())) {
            return response()->json(['message' => '허용되지 않은 IP입니다.', 'ip' => $request->ip()], 403);
        }
        if ($scope !== null && ! $key->hasScope($scope)) {
            return response()->json(['message' => "이 키에는 '{$scope}' 권한이 없습니다.", 'scopes' => $key->scopes], 403);
        }

        // 일일 사용량 — 한도 초과 시 429, 무제한 키도 집계는 남긴다
        $usage = ApiKeyUsage::firstOrCreate(
            ['api_key_id' => $key->id, 'used_date' => now()->toDateString()],
            ['count' => 0],
        );
        if ($key->daily_limit !== null && $usage->count >= $key->daily_limit) {
            return response()->json([
                'message' => '일일 호출 한도를 초과했습니다.',
                'daily_limit' => $key->daily_limit,
                'used' => $usage->count,
            ], 429);
        }
        $usage->increment('count');

        // last_used_at 은 분 단위로만 갱신해 불필요한 쓰기를 줄인다
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->save();
        }

        $request->setUserResolver(fn () => $key->user);
        $request->attributes->set('api_key', $key);

        $response = $next($request);
        if ($key->daily_limit !== null) {
            $response->headers->set('X-RateLimit-Limit', (string) $key->daily_limit);
            $response->headers->set('X-RateLimit-Remaining', (string) max(0, $key->daily_limit - $usage->count - 1));
        }

        return $response;
    }
}
