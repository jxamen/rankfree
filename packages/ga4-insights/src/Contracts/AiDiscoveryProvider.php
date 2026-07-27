<?php

namespace Jcurve\Ga4Insights\Contracts;

/**
 * AI 유입(AI Discovery) 공급자(호스트 앱 구현).
 *
 * AI Discovery = AI Referral + Generative Organic
 *  - AI Referral        : ChatGPT·Perplexity·Claude 등에서 링크를 눌러 들어온 **브라우저 방문**(GA4 에 잡힘)
 *  - Generative Organic : AI 크롤러·에이전트가 문서를 **직접 읽어간 요청**. JS 를 실행하지 않아
 *                         GA4 에는 전혀 안 잡히므로 호스트 앱이 서버 로그로 집계해 넘긴다.
 *
 * config('ga4-insights.ai_discovery.provider') 에 구현 클래스명을 등록하면 대시보드에 섹션이 표시된다.
 */
interface AiDiscoveryProvider
{
    /**
     * 기간 내 AI 유입 요약.
     *
     * @param  array  $ga  이미 조회한 GA4 리포트(sourceMedium 등 재사용 — 추가 쿼터 소모를 피한다)
     * @return array{
     *     referral: list<array{name:string, sessions:int, users:int}>,
     *     generative: list<array{name:string, hits:int, kind:string}>,
     *     totals: array{referral_sessions:int, generative_hits:int, user_agent_hits:int},
     *     pages: list<array{name:string, hits:int}>
     * }
     */
    public function summary(string $startDate, string $endDate, array $ga): array;
}
