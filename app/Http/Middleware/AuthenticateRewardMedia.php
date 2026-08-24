<?php

namespace App\Http\Middleware;

use App\Models\RewardMedia;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 제휴 매체 API 인증(2026-08-24) — Authorization: Bearer rkm_… (또는 X-API-KEY 헤더).
 *
 * 고객용 회원 API 키(auth.apikey / api_keys)와 **별개 체계**다: 키가 곧 매체이므로
 * 제휴 매체는 회원 계정을 만들 필요가 없다. 키는 운영자가 매체를 등록할 때 발급된다.
 * 검사 순서: 키 유효 → 매체 활성. 통과하면 request attribute `reward_media` 에 매체를 담는다.
 */
class AuthenticateRewardMedia
{
    public function handle(Request $request, Closure $next): Response
    {
        $media = RewardMedia::findByKey($request->bearerToken() ?: $request->header('X-API-KEY'));

        if ($media === null) {
            return response()->json(['message' => '유효하지 않은 제휴 매체 키입니다.'], 401);
        }
        if (! $media->is_active) {
            return response()->json(['message' => '이 제휴 매체는 중지 상태입니다. 운영자에게 문의하세요.'], 403);
        }

        // 마지막 사용 시각은 분 단위로만 갱신해 불필요한 쓰기를 줄인다(api_keys 와 같은 근거)
        if ($media->api_key_last_used_at === null || $media->api_key_last_used_at->lt(now()->subMinute())) {
            $media->forceFill(['api_key_last_used_at' => now()])->save();
        }

        $request->attributes->set('reward_media', $media);

        return $next($request);
    }
}
