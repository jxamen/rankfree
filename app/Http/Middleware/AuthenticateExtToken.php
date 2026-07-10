<?php

namespace App\Http\Middleware;

use App\Models\ExtToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer 토큰(ext_tokens) 기반 확장 API 인증. */
class AuthenticateExtToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = ExtToken::findByPlain($request->bearerToken());

        if ($token === null || $token->user === null) {
            return response()->json(['message' => '로그인이 필요합니다.'], 401);
        }

        // last_used_at 는 분 단위로만 갱신해 불필요한 쓰기를 줄인다
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('ext_token', $token);

        return $next($request);
    }
}
