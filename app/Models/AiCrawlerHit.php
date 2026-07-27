<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * AI 크롤러·에이전트 유입 집계(2026-07-27) — 일자 × 봇 × 경로.
 * GA4 는 JS 실행 방문만 잡으므로, AI 가 직접 읽어간 요청은 여기서만 보인다(Generative Organic).
 */
class AiCrawlerHit extends Model
{
    protected $fillable = ['hit_date', 'bot', 'path', 'hits'];

    protected $casts = [
        'hit_date' => 'date',
        'hits' => 'integer',
    ];

    /**
     * User-Agent → 봇 이름. 매칭 안 되면 null(일반 트래픽).
     * 목적별로 나뉜다: 학습 수집(GPTBot·ClaudeBot·CCBot) / 검색 인덱싱(OAI-SearchBot·PerplexityBot) /
     * 사용자 요청 실시간 조회(ChatGPT-User·Perplexity-User — 사실상 '사람이 그 링크를 열었다'에 가깝다).
     */
    public const BOTS = [
        'OAI-SearchBot' => 'OAI-SearchBot',
        'ChatGPT-User' => 'ChatGPT-User',
        'GPTBot' => 'GPTBot',
        'ClaudeBot' => 'ClaudeBot',
        'Claude-User' => 'Claude-User',
        'Claude-SearchBot' => 'Claude-SearchBot',
        'PerplexityBot' => 'PerplexityBot',
        'Perplexity-User' => 'Perplexity-User',
        'Google-Extended' => 'Google-Extended',
        'Applebot-Extended' => 'Applebot-Extended',
        'Applebot' => 'Applebot',
        'Bingbot' => 'Bingbot',
        'CCBot' => 'CCBot',
        'Bytespider' => 'Bytespider',
        'Amazonbot' => 'Amazonbot',
        'meta-externalagent' => 'Meta-ExternalAgent',
        'cohere-ai' => 'Cohere-AI',
        'YouBot' => 'YouBot',
    ];

    /** 사용자의 질문에 답하려고 그 순간 문서를 읽는 요청 — 실제 '유입'에 가장 가깝다. */
    public const USER_AGENTS = ['ChatGPT-User', 'Claude-User', 'Perplexity-User'];

    /** UA 문자열에서 봇 이름을 찾는다(대소문자 무시, 더 구체적인 이름이 먼저 매칭되도록 정의 순서 유지). */
    public static function detect(?string $userAgent): ?string
    {
        $ua = trim((string) $userAgent);
        if ($ua === '') {
            return null;
        }
        foreach (self::BOTS as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                return $name;
            }
        }

        return null;
    }

    /** 히트 1건 기록(일자·봇·경로 단위 누적). 실패해도 요청은 막지 않는다. */
    public static function record(string $bot, string $path, ?string $date = null): void
    {
        $date = $date ?: now()->toDateString();
        $path = mb_substr($path === '' ? '/' : $path, 0, 255);

        try {
            // upsert 후 증가 — 동시 요청에도 카운트가 유실되지 않게
            static::query()->upsert(
                [['hit_date' => $date, 'bot' => $bot, 'path' => $path, 'hits' => 0, 'created_at' => now(), 'updated_at' => now()]],
                ['hit_date', 'bot', 'path'],
                ['updated_at'],
            );
            static::query()
                ->where('hit_date', $date)->where('bot', $bot)->where('path', $path)
                ->update(['hits' => DB::raw('hits + 1')]);
        } catch (\Throwable) {
            // 집계 실패로 서비스가 멈추면 안 된다
        }
    }
}
