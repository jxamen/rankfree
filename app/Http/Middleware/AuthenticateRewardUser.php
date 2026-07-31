<?php

namespace App\Http\Middleware;

use App\Models\RewardMedia;
use App\Models\RewardUser;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * 리워드 매체 사용자 인증 — 쿠키 세션 불가 환경(iOS 서드파티 쿠키 차단)이라 x-user-key 헤더만 쓴다.
 * 키는 sha256 해시로만 저장·조회하고, 매체(slug) 스코프로 사용자 행을 만든다(design-01 §2-1).
 */
class AuthenticateRewardUser
{
    public function handle(Request $request, Closure $next, string $mediaSlug): Response
    {
        // 심야 휴지(02~06) 초고속 경로(design-02 §6-4, Phase 4) — 목록 조회는 인증·DB 없이 닫힘 응답(쿼리 0)
        if ($request->isMethod('GET') && str_ends_with($request->path(), '/missions') && \App\Support\RewardDay::isQuiet()) {
            $day = \App\Support\RewardDay::current();

            return response()->json(['missions' => [], 'meta' => [
                'closed' => true,
                'opensAt' => \App\Support\RewardDay::start(\Illuminate\Support\Carbon::parse($day)->addDay()->toDateString())->toIso8601String(),
            ]]);
        }

        $media = RewardMedia::query()->where('slug', $mediaSlug)->where('is_active', true)->first();
        if (! $media) {
            return response()->json(['message' => 'media not available'], 503);
        }

        $key = trim((string) $request->header('x-user-key'));
        if ($key === '') {
            return response()->json(['message' => 'unauthorized'], 401);
        }

        $hash = hash('sha256', $key);
        $user = RewardUser::query()
            ->where('media_id', $media->id)->where('user_key_hash', $hash)->first();

        if (! $user) {
            // x-user-key 는 클라이언트가 자유 발급한다 — 신규 행 생성만 IP 예산으로 제한한다
            // (요청 자체를 스로틀하면 통신사 NAT 뒤의 정상 사용자가 함께 막힌다)
            $budget = (int) config('reward.new_user_per_ip_hourly');
            if ($budget > 0 && ! RateLimiter::attempt(
                'reward:newuser:'.$media->id.':'.$request->ip(), $budget, fn () => true, 3600)) {
                return response()->json(['message' => 'too many requests'], 429);
            }

            try {
                $user = RewardUser::query()->firstOrCreate(
                    ['media_id' => $media->id, 'user_key_hash' => $hash],
                );
            } catch (UniqueConstraintViolationException) {
                // 동시 첫 요청이 unique(ru_key)에 충돌한 경우 — 이미 만들어진 행을 다시 읽는다
                $user = RewardUser::query()
                    ->where('media_id', $media->id)->where('user_key_hash', $hash)->firstOrFail();
            }
        }

        if ($user->wasRecentlyCreated) {
            $user->refresh();   // DB 기본값(status='active' 등)을 로드 — 없으면 첫 요청이 차단으로 오판된다
            // 지급 재시도용 익명키 원문(암호화 저장) — 지급 확정 시 비운다(design-01 §2-1)
            $user->forceFill(['anon_key_enc' => $key, 'last_seen_at' => now()])->saveQuietly();
        } elseif (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinute())) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();   // 1분 단위로만 갱신
        }

        $request->attributes->set('rewardMedia', $media);
        $request->attributes->set('rewardUser', $user);

        return $next($request);
    }
}
