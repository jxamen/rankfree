<?php

use App\Http\Controllers\Api\CompeteController;
use App\Http\Controllers\Api\ExtQuizController;
use App\Http\Controllers\Api\ExtAuthController;
use App\Http\Controllers\Api\ExtKeywordController;
use App\Http\Controllers\Api\ExtMarketController;
use App\Http\Controllers\Api\ExtPlaceController;
use App\Http\Controllers\Api\ExtProductController;
use App\Http\Controllers\Api\ExtProductInfoController;
use App\Http\Controllers\Api\ExtSellerCaptchaController;
use App\Http\Controllers\Api\ExtSellerInfoController;
use App\Http\Controllers\Api\ExtSellerPowerController;
use App\Http\Controllers\Api\ExtShoppingSeoController;
use App\Http\Controllers\Api\KeywordController;
use App\Http\Controllers\Api\RankController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 크롬 확장(rankfree extension) API — Bearer 토큰(ext_tokens) 인증
|--------------------------------------------------------------------------
*/
Route::prefix('ext')->group(function (): void {
    Route::post('/login', [ExtAuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth.ext')->group(function (): void {
        Route::get('/me', [ExtAuthController::class, 'me']);
        Route::post('/logout', [ExtAuthController::class, 'logout']);
        Route::get('/keyword-analysis', [ExtKeywordController::class, 'show'])->middleware('throttle:30,1');
        Route::get('/keyword-analysis/detail', [KeywordController::class, 'detail'])->middleware('throttle:15,1');
        // '함께 많이 찾는'(SERP qra 모듈, badge 포함) — 확장이 서버에서 받아 표시(DOM scrape 대체)
        Route::get('/keyword-together', [KeywordController::class, 'together'])->middleware('throttle:20,1');
        // 확장이 수집한 쇼핑 노출 상품(상위 80) 저장 — 서버는 search.shopping 418 이라 직접 수집 불가
        Route::post('/keyword-shop-serp', [\App\Http\Controllers\Api\ExtKeywordShopSerpController::class, 'store'])->middleware('throttle:120,1');
        // 대량 자동 수집 대기열 — 미수집·오래된 키워드를 검색량 순으로(확장이 연속 수집)
        Route::get('/keyword-shop-serp/queue', [\App\Http\Controllers\Api\ExtKeywordShopSerpController::class, 'queue'])->middleware('throttle:60,1');
        // 판매자 정보 팝업의 수동 입력용 퀴즈 문구/이미지 저장. 정답 추론·제출은 하지 않는다.
        Route::post('/seller-captchas', [ExtSellerCaptchaController::class, 'store'])->middleware('throttle:60,1');
        // 캡차 통과 후 표시되는 판매자(사업자) 정보 — 업체(채널) 기준 저장
        Route::post('/seller-infos', [ExtSellerInfoController::class, 'store'])->middleware('throttle:60,1');

        // 플레이스 리스트 순위(map.naver 배지·시장분석) — 키워드 상위 오가닉 순위 + N1/N2/N3
        Route::get('/place-serp', [RankController::class, 'serp'])->middleware('throttle:20,1');
        // 단일 매장 정밀 분석(매장분석) — D7/D9/D10 포함 완전 지표(상세 수집으로 수 초 소요)
        Route::get('/place-detail', [RankController::class, 'placeDetail'])->middleware('throttle:10,1');

        // 플레이스 매장 분석 저장/내역
        Route::post('/place-analyses', [ExtPlaceController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/place-analyses', [ExtPlaceController::class, 'index']);
        Route::get('/place-analyses/{analysis}', [ExtPlaceController::class, 'show']);

        // 쇼핑 시장 분석 저장/내역
        Route::post('/market-analyses', [ExtMarketController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/market-analyses', [ExtMarketController::class, 'index']);
        Route::get('/market-analyses/{analysis}', [ExtMarketController::class, 'show']);

        // 상품정보(제목·업체명·가격·SEO태그) — 노출 키워드 분석 조합 재료(서버는 상품페이지 429라 확장 수집)
        Route::post('/product-infos', [ExtProductInfoController::class, 'store'])->middleware('throttle:60,1');

        // 상품 분석(리뷰 분석) 저장/내역
        Route::post('/product-analyses', [ExtProductController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/product-analyses', [ExtProductController::class, 'index']);
        Route::get('/product-analyses/{analysis}', [ExtProductController::class, 'show']);

        // 쇼핑 상품명 SEO 분석(제목 점수·공통단어·추천 상품명·노출 키워드)
        Route::post('/shopping-seo', [ExtShoppingSeoController::class, 'analyze'])->middleware('throttle:20,1');

        // 셀러력(상품 SEO·지수 경쟁 비교) 수집·계산·저장/내역
        Route::get('/seller-power/competitors', [ExtSellerPowerController::class, 'competitors'])->middleware('throttle:30,1');
        Route::post('/seller-power', [ExtSellerPowerController::class, 'store'])->middleware('throttle:15,1');
        Route::get('/seller-power', [ExtSellerPowerController::class, 'index']);
        Route::post('/talk-contacts', [ExtSellerPowerController::class, 'harvestTalk'])->middleware('throttle:20,1');
        Route::get('/seller-power/{analysis}', [ExtSellerPowerController::class, 'show']);
        // 운영자 본인이 인증 상태로 대량 수집에 쓰는 엔드포인트 — 서버측 요청 제한 없음
        // (폭주 방지는 확장 콘텐츠 스크립트의 호출 쿨다운으로 처리).
        Route::post('/quiz/solve', [ExtQuizController::class, 'solve']);
        // 확장이 읽는 퀴즈 풀이 설정(정답 대기 시간 등)
        Route::get('/quiz/config', [ExtQuizController::class, 'config']);
    });
});

/*
|--------------------------------------------------------------------------
| 외부 공개 API v1 — API 키 인증 (auth.apikey:{scope})
| 키 발급·허용기간·일일 한도·허용 IP: 콘솔 → API 키 관리
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function (): void {
    // 순위추적 (scope: rank)
    Route::middleware('auth.apikey:rank')->prefix('rank')->group(function (): void {
        Route::get('/slots', [RankController::class, 'slots']);
        Route::post('/slots', [RankController::class, 'store']);
        Route::get('/resolve', [RankController::class, 'resolve']);
        Route::post('/slots/{slot}/run', [RankController::class, 'run']);
        Route::delete('/slots/{slot}', [RankController::class, 'destroy']);
        Route::post('/check', [RankController::class, 'check']);
    });

    // 경쟁분석 (scope: compete)
    Route::middleware('auth.apikey:compete')->prefix('compete')->group(function (): void {
        Route::get('/tracks', [CompeteController::class, 'tracks']);
        Route::get('/{slot}', [CompeteController::class, 'show']);
        Route::post('/{slot}/analyze', [CompeteController::class, 'analyze']);
    });

    // 키워드분석 — 경량(scope: keyword)과 상세(scope: keyword_detail)를 분리 제공
    Route::middleware('auth.apikey:keyword')->get('/keyword', [KeywordController::class, 'show']);
    Route::middleware('auth.apikey:keyword_detail')->get('/keyword/detail', [KeywordController::class, 'detail']);
});

/*
|--------------------------------------------------------------------------
| 키워드 인사이트 허브(22) — 공개 자동완성(인증 없음)
|--------------------------------------------------------------------------
| /keywords 검색창의 제안어. 발행 문서(origin=hub)와 카테고리만 노출한다.
| ⚠️ origin=hub 강제 — 빠지면 타 사용자의 검색 내역(origin=user)이 공개된다(21 비공개 원칙).
| 응답은 JSON 이라 meta robots 를 못 쓴다 → X-Robots-Tag 헤더로 색인 차단(컨트롤러).
*/
Route::get('/keywords/suggest', [\App\Http\Controllers\Api\KeywordSuggestController::class, 'index'])
    ->middleware('throttle:60,1')->name('api.keywords.suggest');
