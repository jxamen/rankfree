<?php

namespace Jcurve\Ga4Insights\Contracts;

/**
 * 랜딩 경로 → 키워드 환원(호스트 앱 구현) — 네이버 등 검색어를 안 넘겨주는 소스의 유입 키워드 추정.
 * 호스트 사이트의 키워드 슬러그 URL(/keyword/여름브라 등)을 알고 있는 쪽은 앱이므로 규칙을 앱이 제공한다.
 * config('ga4-insights.keywords.landing_resolver') 에 구현 클래스명 등록.
 */
interface LandingKeywordResolver
{
    /** 키워드 페이지가 아니면 null. */
    public function resolve(string $landingPath): ?string;
}
