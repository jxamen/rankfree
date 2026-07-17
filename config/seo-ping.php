<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 검색엔진 발행 알림 (21_SEO_SLUG_SITEMAP)
    |--------------------------------------------------------------------------
    | 허브 문서 발행 시 "공식 지원" 경로로만 알린다.
    |  · IndexNow — 네이버·빙·얀덱스. 키는 임의 영숫자(8~128자)를 .env 에 두면
    |    /{key}.txt 키 파일이 자동 서빙된다(별도 파일 업로드 불필요).
    |  · 구글 — 폐기된 sitemap ping(2024-01 404) 대신 Search Console API
    |    sitemaps.submit 으로 사이트맵을 재제출한다(쓰기 스코프 필요).
    |  · Indexing API 는 채용공고·라이브방송 전용 정책이라 사용하지 않는다.
    */

    'enabled' => (bool) env('SEO_PING_ENABLED', true),

    'indexnow' => [
        'key' => (string) env('INDEXNOW_KEY', ''),
        'endpoint' => (string) env('INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow'),
    ],

    // 발행 시 구글 서치 콘솔 사이트맵 재제출 — 서비스 계정 키 또는 [구글 계정으로 연동](쓰기 스코프) 필요
    'gsc_sitemap_submit' => (bool) env('SEO_PING_GSC_SITEMAP', true),
];
