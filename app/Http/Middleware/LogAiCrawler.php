<?php

namespace App\Http\Middleware;

use App\Models\AiCrawlerHit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI 크롤러·에이전트 유입 기록(2026-07-27) — Generative Organic 집계용.
 *
 * GPTBot·OAI-SearchBot·ChatGPT-User·PerplexityBot 등은 자바스크립트를 실행하지 않아
 * GA4(gtag)에 전혀 안 잡힌다. 서버에서 직접 세어야 AI 노출·인용 규모를 알 수 있다.
 * 일반 트래픽은 UA 문자열 검사만 하고 지나가므로 비용이 없다.
 */
class LogAiCrawler
{
    public function handle(Request $request, Closure $next): Response
    {
        // GET 문서 요청만 — 정적 자원·프리플라이트는 의미 없다
        if ($request->isMethod('GET')) {
            $bot = AiCrawlerHit::detect($request->userAgent());
            if ($bot !== null && ! $this->isAsset($request->path())) {
                AiCrawlerHit::record($bot, '/'.ltrim($request->path(), '/'));
            }
        }

        return $next($request);
    }

    /** 이미지·스크립트 등은 문서 소비가 아니라 제외. */
    private function isAsset(string $path): bool
    {
        return (bool) preg_match('/\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|map|xml|txt)$/i', $path);
    }
}
