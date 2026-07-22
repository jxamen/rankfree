<?php

namespace App\Support;

use Jcurve\Ga4Insights\Contracts\LandingKeywordResolver;

/**
 * GA4 대시보드 — 랜딩 경로 → 키워드 환원. rankfree 의 SEO 공유/허브 페이지는 키워드 슬러그 URL 이라
 * (21·22번: /keyword/여름브라 · /keywords/맛집-음식점 · /place/… 등) 랜딩 자체가 유입 키워드다.
 * 네이버 등 검색어를 리퍼러로 안 넘겨주는 소스의 유입 키워드 추정에 쓴다.
 */
class Ga4LandingKeywordResolver implements LandingKeywordResolver
{
    /** 키워드 슬러그를 갖는 공개 페이지 프리픽스(21_SEO_SLUG_SITEMAP·22_KEYWORD_CONTENT_HUB). */
    private const PREFIXES = ['keyword', 'keywords', 'place', 'shopping', 'market', 'product', 'seller', 'store', 'compete'];

    public function resolve(string $landingPath): ?string
    {
        $path = (string) (parse_url($landingPath, PHP_URL_PATH) ?? $landingPath);
        if (! preg_match('#^/([a-z]+)/([^/]+)$#u', $path, $m)) {
            return null;
        }
        if (! in_array($m[1], self::PREFIXES, true)) {
            return null;
        }
        // 고정 하위 경로(타입 홈·검색)는 키워드가 아니다 — /keywords/place, /keywords/search 등
        if (in_array($m[2], ['place', 'shopping', 'search'], true)) {
            return null;
        }
        $slug = trim(urldecode($m[2]));

        return $slug !== '' ? $slug : null;
    }
}
