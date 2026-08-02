<?php

namespace App\Http\Middleware;

use App\Models\BlockedIp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * 취약점 탐침 IP 자동 차단(2026-08-02).
 *
 * /.env·/.aws/credentials·/.git-credentials 같은 경로는 사람이 브라우저로 요청할 일이 없다.
 * 한 번만 들어와도 자격증명 스캐너로 보고 그 IP 를 일정 시간 차단한다.
 * (이 요청들은 원래도 404 로 안전하게 끝났다 — 차단의 목적은 반복 탐침·로그 오염을 줄이는 것이다.)
 *
 * 미들웨어 맨 앞에 둔다: 차단된 IP 는 세션·라우팅 비용을 쓰기 전에 끊는다.
 */
class BlockProbeIps
{
    public function handle(Request $request, Closure $next): Response
    {
        $cfg = (array) config('security.probe_block');

        if (! ($cfg['enabled'] ?? false)) {
            return $next($request);
        }

        $ip = (string) $request->ip();
        if ($ip === '' || in_array($ip, (array) ($cfg['never_block'] ?? []), true)) {
            return $next($request);
        }

        if (BlockedIp::isBlocked($ip)) {
            $this->deny();
        }

        // 프록시 뒤라면 REMOTE_ADDR 이 실제 클라이언트가 아니다 — 잘못 잡으면 전체 사용자가 막힌다
        if ($this->isProbe($request->path(), $cfg) && ! $this->behindUntrustedProxy($request, $cfg)) {
            BlockedIp::block($ip, '/'.ltrim($request->path(), '/'));
            $this->deny();
        }

        return $next($request);
    }

    /** 탐침 경로인가. safe_paths 가 patterns 보다 우선한다. */
    private function isProbe(string $path, array $cfg): bool
    {
        $path = ltrim($path, '/');

        foreach ((array) ($cfg['safe_paths'] ?? []) as $safe) {
            if (preg_match($safe, $path)) {
                return false;
            }
        }

        foreach ((array) ($cfg['patterns'] ?? []) as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * CDN·리버스 프록시 뒤인데 TrustProxies 가 설정돼 있지 않은 상태인가.
     *
     * 이때 REMOTE_ADDR 은 방문자가 아니라 프록시(예: Cloudflare 엣지)다.
     * 그 IP 를 차단하면 그 엣지를 통과하는 **모든 정상 사용자**가 함께 막힌다.
     * 그래서 이 경우엔 차단하지 않고 그냥 통과시킨다(요청은 어차피 404 로 끝난다).
     */
    private function behindUntrustedProxy(Request $request, array $cfg): bool
    {
        if (! ($cfg['trust_proxy_guard'] ?? true)) {
            return false;
        }

        if (SymfonyRequest::getTrustedProxies() !== []) {
            return false;   // 프록시를 신뢰하도록 설정됨 → $request->ip() 가 실제 클라이언트다
        }

        foreach ((array) ($cfg['proxy_headers'] ?? []) as $header) {
            if ($request->headers->has($header)) {
                return true;
            }
        }

        return false;
    }

    /** @return never */
    private function deny(): void
    {
        abort(403, '차단된 접근입니다. 정상 이용 중 이 화면이 보인다면 고객센터로 알려 주세요.');
    }
}
