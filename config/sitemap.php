<?php

use App\Models\KeywordSearch;
use App\Models\MarketAnalysis;
use App\Models\PlaceStoreAnalysis;
use App\Models\ProductAnalysis;
use App\Models\SellerPowerAnalysis;

return [
    /*
    |--------------------------------------------------------------------------
    | 사이트맵
    |--------------------------------------------------------------------------
    | /sitemap.xml 은 사이트맵 인덱스, /sitemap-{section}.xml 이 실제 URL 목록.
    | 분석데이터 공유링크(SEO 슬러그)를 섹션별로 노출한다. sitemap:refresh 가
    | 슬러그를 백필하고 캐시 버전을 올린다(routes/console.php 스케줄).
    */

    // 분석데이터 공유링크를 사이트맵에 포함할지 (off 면 게시판·상품·정적 페이지만)
    'include_analyses' => (bool) env('SITEMAP_INCLUDE_ANALYSES', true),

    // 한 사이트맵 파일당 최대 URL 수(표준 상한 50,000 이하). 초과분은 ?page= 로 분할.
    'chunk' => (int) env('SITEMAP_CHUNK', 20000),

    // 분석 소스 — 1회성 분석만 사이트맵 공개. slug 공개 URL 은 모델 shareUrl() 사용.
    // ⚠️ 순위 추적 슬롯(/place·/shopping)과 플레이스 경쟁분석(/compete)은 사용자의 추적 대상이라
    //    사이트맵에 공개하지 않는다(공유 버튼으로 본인이 수동 공유하는 것만 허용).
    'analyses' => [
        'keyword' => ['model' => KeywordSearch::class, 'freq' => 'monthly', 'priority' => '0.6'],
        'market' => ['model' => MarketAnalysis::class, 'freq' => 'monthly', 'priority' => '0.6'],
        'product' => ['model' => ProductAnalysis::class, 'freq' => 'monthly', 'priority' => '0.6'],
        'seller' => ['model' => SellerPowerAnalysis::class, 'freq' => 'monthly', 'priority' => '0.6'],
        'store' => ['model' => PlaceStoreAnalysis::class, 'freq' => 'monthly', 'priority' => '0.6'],
    ],
];
