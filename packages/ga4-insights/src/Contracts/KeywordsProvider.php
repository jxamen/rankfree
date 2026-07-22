<?php

namespace Jcurve\Ga4Insights\Contracts;

/**
 * 검색 유입 '실제 검색어' 공급자(호스트 앱 구현) — 예: 구글 서치 콘솔 수집분.
 * GA4 는 자연검색 검색어를 제공하지 않으므로 외부 소스를 주입받는다.
 * config('ga4-insights.keywords.gsc_provider') 에 구현 클래스명을 등록하면 대시보드에 표시된다.
 */
interface KeywordsProvider
{
    /**
     * 기간 내 상위 검색어.
     *
     * @return list<array{query:string, clicks:int, impressions:int, position:float|null}>
     */
    public function rows(string $startDate, string $endDate, int $limit = 15): array;
}
