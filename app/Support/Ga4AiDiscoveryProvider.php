<?php

namespace App\Support;

use App\Models\AiCrawlerHit;
use Jcurve\Ga4Insights\Contracts\AiDiscoveryProvider;

/**
 * AI Discovery = AI Referral + Generative Organic (2026-07-27).
 *
 *  - AI Referral        : GA4 소스/매체 중 AI 서비스에서 온 세션 — 사람이 브라우저로 들어온 방문.
 *  - Generative Organic : AI 크롤러·에이전트가 문서를 직접 읽어간 요청(ai_crawler_hits).
 *                         JS 를 실행하지 않아 GA4 에 잡히지 않으므로 서버 집계가 유일한 근거다.
 *                         그중 ChatGPT-User·Perplexity-User 등은 "사용자 질문에 답하려고 그 순간 연 것"이라
 *                         사실상 유입에 가깝다(user_agent_hits 로 따로 표기).
 */
class Ga4AiDiscoveryProvider implements AiDiscoveryProvider
{
    /** GA4 source 에 나타나는 AI 서비스 도메인·이름 → 표시명. */
    private const AI_SOURCES = [
        'chatgpt.com' => 'ChatGPT',
        'chat.openai.com' => 'ChatGPT',
        'openai.com' => 'OpenAI',
        'perplexity.ai' => 'Perplexity',
        'www.perplexity.ai' => 'Perplexity',
        'claude.ai' => 'Claude',
        'gemini.google.com' => 'Gemini',
        'bard.google.com' => 'Gemini',
        'copilot.microsoft.com' => 'Copilot',
        'bing.com/chat' => 'Copilot',
        'you.com' => 'You.com',
        'poe.com' => 'Poe',
        'phind.com' => 'Phind',
        'wrtn.ai' => '뤼튼',
        'wrtn.io' => '뤼튼',
        'clova-x.naver.com' => 'CLOVA X',
        'cue.search.naver.com' => '네이버 큐:',
    ];

    public function summary(string $startDate, string $endDate, array $ga): array
    {
        $referral = $this->referralRows((array) ($ga['sourceMedium'] ?? []));
        [$generative, $pages, $userHits] = $this->generativeRows($startDate, $endDate);

        return [
            'referral' => $referral,
            'generative' => $generative,
            'pages' => $pages,
            'totals' => [
                'referral_sessions' => array_sum(array_column($referral, 'sessions')),
                'generative_hits' => array_sum(array_column($generative, 'hits')),
                'user_agent_hits' => $userHits,
            ],
        ];
    }

    /** GA4 소스/매체 행에서 AI 서비스만 골라 표시명으로 합산. */
    private function referralRows(array $sourceMedium): array
    {
        $acc = [];
        foreach ($sourceMedium as $row) {
            $name = strtolower((string) ($row['name'] ?? ''));   // "chatgpt.com / referral"
            $label = $this->matchAiSource($name);
            if ($label === null) {
                continue;
            }
            $acc[$label]['sessions'] = ($acc[$label]['sessions'] ?? 0) + (int) ($row['sessions'] ?? 0);
            $acc[$label]['users'] = ($acc[$label]['users'] ?? 0) + (int) ($row['totalUsers'] ?? $row['users'] ?? 0);
        }

        $rows = [];
        foreach ($acc as $label => $v) {
            $rows[] = ['name' => $label, 'sessions' => (int) $v['sessions'], 'users' => (int) $v['users']];
        }
        usort($rows, fn ($a, $b) => $b['sessions'] <=> $a['sessions']);

        return $rows;
    }

    private function matchAiSource(string $haystack): ?string
    {
        foreach (self::AI_SOURCES as $needle => $label) {
            if (str_contains($haystack, $needle)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * AI 크롤러 집계 — 봇별 히트, 많이 읽힌 문서, 사용자 요청형(ChatGPT-User 등) 합계.
     *
     * @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>, 2: int}
     */
    private function generativeRows(string $startDate, string $endDate): array
    {
        $base = AiCrawlerHit::query()->whereBetween('hit_date', [$startDate, $endDate]);

        $byBot = (clone $base)
            ->selectRaw('bot, SUM(hits) as hits')
            ->groupBy('bot')->orderByDesc('hits')->get();

        $generative = $byBot->map(fn ($r) => [
            'name' => (string) $r->bot,
            'hits' => (int) $r->hits,
            // 사용자 요청형 = 사람이 그 링크를 연 것에 가깝다 / 그 외는 수집·인덱싱
            'kind' => in_array($r->bot, AiCrawlerHit::USER_AGENTS, true) ? 'user' : 'crawl',
        ])->values()->all();

        $pages = (clone $base)
            ->selectRaw('path, SUM(hits) as hits')
            ->groupBy('path')->orderByDesc('hits')->limit(15)->get()
            ->map(fn ($r) => ['name' => (string) $r->path, 'hits' => (int) $r->hits])
            ->values()->all();

        $userHits = (int) (clone $base)->whereIn('bot', AiCrawlerHit::USER_AGENTS)->sum('hits');

        return [$generative, $pages, $userHits];
    }
}
