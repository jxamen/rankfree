<?php

use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\FaqController as AdminFaqController;
use App\Http\Controllers\Admin\MarketingOrderController;
use App\Http\Controllers\Admin\MarketingProductController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\NoticeController as AdminNoticeController;
use App\Http\Controllers\Admin\PopupController;
use App\Http\Controllers\Admin\QnaController as AdminQnaController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BlogIndexController;
use App\Http\Controllers\PhoneVerificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\FindEmailController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\BulkKeywordController;
use App\Http\Controllers\Admin\CafeSeedController;
use App\Http\Controllers\Admin\CommunityCategoryController;
use App\Http\Controllers\Admin\CommunitySeedController;
use App\Http\Controllers\Admin\PersonaController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\CompeteController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\KeywordAnalysisController;
use App\Http\Controllers\KeywordInsightController;
use App\Http\Controllers\MarketAnalysisController;
use App\Http\Controllers\MarketingLeadController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PlaceStoreAnalysisController;
use App\Http\Controllers\ProductAnalysisController;
use App\Http\Controllers\RankCheckController;
use App\Http\Controllers\RankTrackController;
use App\Http\Controllers\SelfMarketingController;
use App\Http\Controllers\SellerPowerController;
use App\Http\Controllers\ShopKeywordExposureController;
use App\Http\Controllers\ShopRankTrackController;
use App\Http\Controllers\SmartplaceController;
use App\Http\Controllers\TalkContactController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// A1 플레이스 순위체크 — 1회성 무료 조회
// 1회성 무료 순위 조회 (비회원 허용 — Turnstile 봇 차단). 폼은 POST 제출.
Route::post('/rank-check', [RankCheckController::class, 'check'])->name('rank.check');
Route::post('/shop-check', [RankCheckController::class, 'shopCheck'])->name('shop.check');
Route::get('/rank-check', fn () => redirect('/#hero-form')); // 구 GET 링크 안전 처리
// 홈 폼 자동입력(공개) — 플레이스 URL → m.place 변환·업체명
Route::post('/rank-resolve/place', [RankCheckController::class, 'resolvePlace'])->middleware('throttle:30,1')->name('rank.resolve.place');

// 키워드 인사이트 허브(22) — 검색 진입(/keywords) · 타입별 카테고리 메뉴(/keywords/place|shopping) · 카테고리 상세
// ⚠️ 순서 의존: 고정 경로(search·{type})를 반드시 {slug} **앞에** 둔다. {type} 은 whereIn 으로 제약해
//    한글 슬러그(/keywords/맛집-음식점)가 타입 라우트를 통과해 {slug} 로 내려가게 한다. 회귀는 KeywordInsightTest 가 고정.
Route::get('/keywords', [KeywordInsightController::class, 'index'])->name('keywords.index');
Route::get('/keywords/search', [KeywordInsightController::class, 'search'])->name('keywords.search');
Route::get('/keywords/{type}', [KeywordInsightController::class, 'typeHome'])
    ->whereIn('type', ['place', 'shopping'])->name('keywords.type');
Route::get('/keywords/{slug}', [KeywordInsightController::class, 'category'])->name('keywords.category');

// ── 분석 공개 공유 리포트 (SEO 한글/영문 슬러그, 비로그인 열람) ─────────────────
//    예) /keyword/여름브라 · /place/강남맛집 · /shopping/여름원피스
//    구 /k /m /p /sp /ps /r /rc /sr 토큰 URL 은 아래 루프에서 301 로 이 URL 로 이동(기존 링크 유지).
Route::get('/keyword/{slug}', [KeywordAnalysisController::class, 'shared'])->name('keyword.shared');
Route::get('/market/{slug}', [MarketAnalysisController::class, 'shared'])->name('market.shared');
Route::get('/product/{slug}', [ProductAnalysisController::class, 'shared'])->name('product.shared');
Route::get('/seller/{slug}', [SellerPowerController::class, 'shared'])->name('seller-power.shared');
Route::get('/store/{slug}', function (string $slug) {
    $a = \App\Models\PlaceStoreAnalysis::findByShareKey($slug);
    abort_if(! $a, 404);

    return view('place.share', [
        'a' => $a,
        'related' => app(\App\Domain\Seo\RelatedDocsService::class)->sectionsFor($a, array_filter([$a->keyword])),
    ]);
})->name('place-store.shared');
Route::get('/place/{slug}', [RankTrackController::class, 'shared'])->name('rank.shared');
Route::get('/compete/{slug}', [CompeteController::class, 'shared'])->name('compete.shared');
Route::get('/shopping/{slug}', [ShopRankTrackController::class, 'shared'])->name('shop-rank.shared');

Route::get('/s/{token}', [ShopKeywordExposureController::class, 'short'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('shop-keyword.short');

// 구 토큰 공유 URL → 새 슬러그 URL 301(SEO 정규화 · 기존 배포 링크 유지)
foreach ([
    'k' => [\App\Models\KeywordSearch::class, 'shareUrl'],
    'm' => [\App\Models\MarketAnalysis::class, 'shareUrl'],
    'p' => [\App\Models\ProductAnalysis::class, 'shareUrl'],
    'sp' => [\App\Models\SellerPowerAnalysis::class, 'shareUrl'],
    'ps' => [\App\Models\PlaceStoreAnalysis::class, 'shareUrl'],
    'r' => [\App\Models\PlaceRankSlot::class, 'shareUrl'],
    'rc' => [\App\Models\PlaceRankSlot::class, 'competeUrl'],
    'sr' => [\App\Models\ShopRankSlot::class, 'shareUrl'],
] as $seg => [$cls, $method]) {
    Route::get("/{$seg}/{token}", function (string $token) use ($cls, $method) {
        $m = $cls::findByShareKey($token);
        abort_if(! $m, 404);

        return redirect()->to($m->{$method}(), 301);
    })->name("share.legacy.{$seg}");
}

// 마케팅 리드(상담 문의) 접수 — 공개(비로그인 공유 페이지 포함). 스팸 방지 throttle.
Route::post('/lead', [MarketingLeadController::class, 'store'])->middleware('throttle:8,1')->name('lead.store');

// 마케팅 상품 주문 페이지 (회원 전용 — order_token 기반, 주문자는 로그인 회원으로 자동 연결)
Route::middleware('auth')->group(function () {
    Route::get('/order/{token}', [OrderController::class, 'show'])->name('order.show');
    Route::post('/order/{token}', [OrderController::class, 'store'])->name('order.store');
    Route::post('/order-resolve-place', [OrderController::class, 'resolvePlace'])->middleware('throttle:30,1')->name('order.resolve-place');
});

// API 문서 (공개)
Route::view('/developers', 'site.developers')->name('developers');

// 개인정보처리방침 (공개 — 크롬 웹스토어 심사 필수)
Route::view('/privacy', 'site.privacy')->name('privacy');

// 마케팅 상담 (공개 — 푸터·홈 CTA 의 /support. 접수는 기존 POST /lead)
Route::view('/support', 'site.support')->name('support');

// sitemap.xml (공개 — robots.txt 의 Sitemap 지시자 대상). 인덱스 + 섹션별 자식 사이트맵.
Route::get('/sitemap.xml', [\App\Http\Controllers\SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemap-{section}.xml', [\App\Http\Controllers\SitemapController::class, 'section'])
    ->where('section', '[a-z-]+')->name('sitemap.section');

// 커뮤니티 RSS (네이버 서치어드바이저 제출용 — 최신 글 50건)
Route::get('/community/feed', [\App\Http\Controllers\SitemapController::class, 'communityFeed'])->name('community.feed');

// llms.txt / llm.txt — 한글 콘텐츠라 charset=utf-8 을 보장하려고 라우트로 서빙(정적 파일 미사용, 서버 charset 설정 무관).
//   소스: resources/seo/llms.txt (단일 소스). robots.txt·ai.txt 는 ASCII 라 public/ 정적 파일로 둔다.
$__llms = function () {
    $path = resource_path('seo/llms.txt');
    abort_unless(is_file($path), 404);

    return response(file_get_contents($path), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
};
Route::get('/llms.txt', $__llms)->name('llms.txt');
Route::get('/llm.txt', $__llms)->name('llm.txt');

// IndexNow 소유 확인 키 파일(/{key}.txt) — 발행 알림(네이버·빙, 21). .env INDEXNOW_KEY 로 서빙.
//   16자 미만 파일명(robots·ai·llms 등)과는 패턴이 겹치지 않는다.
Route::get('/{indexnowKey}.txt', function (string $indexnowKey) {
    $key = (string) config('seo-ping.indexnow.key');
    abort_unless($key !== '' && hash_equals($key, $indexnowKey), 404);

    return response($key, 200, ['Content-Type' => 'text/plain']);
})->where('indexnowKey', '[A-Za-z0-9-]{16,128}')->name('indexnow.key');

// 셀프마케팅 (공개 카탈로그 — 마케팅 상품 카드, 주문은 로그인)
Route::get('/self-marketing', [SelfMarketingController::class, 'index'])->name('self-marketing');

// 커뮤니티 (공개 열람, 작성은 로그인 필요)
Route::get('/community', [CommunityController::class, 'index'])->name('community');
Route::get('/community/post/{post}', [CommunityController::class, 'show'])->name('community.show');
Route::middleware('auth')->group(function () {
    // 에디터 이미지 첨부 업로드 (로그인 사용자 공용)
    Route::post('/upload/image', [UploadController::class, 'image'])->middleware('throttle:30,1')->name('upload.image');

    Route::get('/community/new', [CommunityController::class, 'create'])->name('community.create');
    Route::post('/community', [CommunityController::class, 'store'])->name('community.store');
    Route::get('/community/post/{post}/edit', [CommunityController::class, 'edit'])->name('community.edit');
    Route::put('/community/post/{post}', [CommunityController::class, 'update'])->name('community.update');
    Route::post('/community/post/{post}/comment', [CommunityController::class, 'comment'])->name('community.comment');
    Route::put('/community/comment/{comment}', [CommunityController::class, 'commentUpdate'])->name('community.comment.update');
    Route::delete('/community/comment/{comment}', [CommunityController::class, 'commentDestroy'])->name('community.comment.destroy');
    Route::post('/community/post/{post}/like', [CommunityController::class, 'like'])->name('community.like');
    Route::delete('/community/post/{post}', [CommunityController::class, 'destroy'])->name('community.destroy');
});

// 인증
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);

    // 소셜 로그인/가입 (google 내장 · naver/kakao SocialiteProviders)
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])->name('social.redirect')->where('provider', 'google|kakao');
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])->name('social.callback')->where('provider', 'google|kakao');
    Route::get('/auth/complete', [SocialAuthController::class, 'complete'])->name('social.complete');
    Route::post('/auth/complete', [SocialAuthController::class, 'completeStore']);

    // 전화번호 SMS 인증(가입 폼 AJAX) — 발송 남용 방지 throttle
    Route::post('/phone/send-code', [PhoneVerificationController::class, 'send'])->middleware('throttle:10,1')->name('phone.send');
    Route::post('/phone/verify-code', [PhoneVerificationController::class, 'verify'])->middleware('throttle:30,1')->name('phone.verify');

    // 비밀번호 찾기(재설정) — Laravel Password 브로커
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');

    // 아이디(이메일) 찾기 — 전화 SMS 인증 후 마스킹 이메일 조회
    Route::get('/find-email', [FindEmailController::class, 'show'])->name('find-email');
    Route::post('/find-email', [FindEmailController::class, 'find'])->middleware('throttle:10,1')->name('find-email.find');
});
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// 콘솔 (로그인 필요) — menu.gate: 메뉴 접근 차단, usage.gate: 메뉴×등급 월 이용횟수 제한
Route::middleware(['auth', 'menu.gate', 'usage.gate'])->prefix('console')->name('console.')->group(function () {
    Route::get('/', [ConsoleController::class, 'dashboard'])->name('dashboard');

    // 마이페이지 — 계정 정보 · 추천 링크(추천 보너스 슬롯)
    Route::get('/me', [ConsoleController::class, 'me'])->name('me');

    // 순위 추적 슬롯
    Route::get('/rank', [RankTrackController::class, 'index'])->name('rank');
    Route::get('/rank/resolve', [RankTrackController::class, 'resolve'])->name('rank.resolve');
    Route::get('/rank/export', [RankTrackController::class, 'export'])->name('rank.export');
    Route::post('/rank', [RankTrackController::class, 'store'])->name('rank.store');
    Route::post('/rank/{slot}/run', [RankTrackController::class, 'run'])->name('rank.run');
    Route::put('/rank/{slot}', [RankTrackController::class, 'update'])->name('rank.update');
    Route::delete('/rank/{slot}', [RankTrackController::class, 'destroy'])->name('rank.destroy');

    // 스마트플레이스 리포트 수집 (crm ads/smartplace 이식)
    Route::get('/smartplace', [SmartplaceController::class, 'index'])->name('smartplace');
    Route::post('/smartplace/discover', [SmartplaceController::class, 'discover'])->name('smartplace.discover');
    Route::post('/smartplace', [SmartplaceController::class, 'store'])->name('smartplace.store');
    Route::put('/smartplace/{account}', [SmartplaceController::class, 'update'])->name('smartplace.update');
    Route::delete('/smartplace/{account}', [SmartplaceController::class, 'destroy'])->name('smartplace.destroy');
    Route::post('/smartplace/{account}/collect', [SmartplaceController::class, 'collect'])->name('smartplace.collect');
    Route::get('/smartplace/{account}/report', [SmartplaceController::class, 'report'])->name('smartplace.report');

    // 쇼핑 순위추적 (openapi shop.json — 상품/업체 × 키워드)
    Route::get('/shop-rank', [ShopRankTrackController::class, 'index'])->name('shop-rank');
    Route::get('/shop-rank/resolve', [ShopRankTrackController::class, 'resolve'])->name('shop-rank.resolve');
    Route::get('/shop-rank/export', [ShopRankTrackController::class, 'export'])->name('shop-rank.export');
    Route::post('/shop-rank', [ShopRankTrackController::class, 'store'])->name('shop-rank.store');
    Route::post('/shop-rank/{slot}/run', [ShopRankTrackController::class, 'run'])->name('shop-rank.run');
    Route::put('/shop-rank/{slot}', [ShopRankTrackController::class, 'update'])->name('shop-rank.update');
    Route::delete('/shop-rank/{slot}', [ShopRankTrackController::class, 'destroy'])->name('shop-rank.destroy');

    // 마케팅 키워드 분석 (검색량·성별/연령·트렌드·연관키워드)
    Route::get('/keyword', [KeywordAnalysisController::class, 'index'])->name('keyword');
    // 통합검색 PC/모바일 섹션 배치 순서 (Playwright 수집 — 비동기 lazy 로드)
    Route::get('/keyword/sections', [KeywordAnalysisController::class, 'sections'])->name('keyword.sections');
    // 키워드 검색 내역 삭제
    Route::delete('/keyword/{search}', [KeywordAnalysisController::class, 'destroy'])->name('keyword.destroy');
    // 키워드 추천 (연관·자동완성 → 기회 점수 랭킹, 황금 키워드 발굴)
    Route::get('/keyword-recommend', [KeywordAnalysisController::class, 'recommend'])->name('keyword-recommend');

    // 키워드 대량 분석 (텍스트/엑셀 업로드 → 청크 수집 → 엑셀 다운로드)
    Route::get('/bulk', [BulkKeywordController::class, 'index'])->name('bulk');
    Route::post('/bulk', [BulkKeywordController::class, 'store'])->name('bulk.store');
    Route::get('/bulk/{bulk}', [BulkKeywordController::class, 'show'])->name('bulk.show');
    Route::post('/bulk/{bulk}/process', [BulkKeywordController::class, 'process'])->name('bulk.process');
    Route::get('/bulk/{bulk}/export', [BulkKeywordController::class, 'export'])->name('bulk.export');
    Route::delete('/bulk/{bulk}', [BulkKeywordController::class, 'destroy'])->name('bulk.destroy');

    // 마케팅 블로그 수집 (키워드→블로거들 / 블로그ID→단건, 게시물 품질·전문성) — URL /blog-collect (route명은 blog 유지)
    Route::get('/blog-collect', [BlogIndexController::class, 'index'])->name('blog');
    // 블로그 지수 분석 (블로그 ID/URL 전용 단건 분석) — URL /blog-index (route명은 blog-single 유지)
    Route::get('/blog-index', [BlogIndexController::class, 'single'])->name('blog-single');
    // 저장된 분석 열람(스냅샷) — 두 기능 공용 URL /blog-index/{id}
    Route::get('/blog-index/{analysis}', [BlogIndexController::class, 'show'])->name('blog.show');
    Route::get('/blog-index/{analysis}/export', [BlogIndexController::class, 'export'])->name('blog.export');
    Route::post('/blog-index/{analysis}/more', [BlogIndexController::class, 'collectMore'])->name('blog.more');
    Route::delete('/blog-index/{analysis}', [BlogIndexController::class, 'destroy'])->name('blog.destroy');
    // 블로거 저장(키워드×ID 조합) — 분석 화면에서 단건·다중 저장/해제 + 저장 목록·엑셀·삭제
    Route::post('/blog-index/{analysis}/save', [BlogIndexController::class, 'saveBloggers'])->name('blog.save');
    Route::post('/blog-index/{analysis}/unsave', [BlogIndexController::class, 'unsaveBloggers'])->name('blog.unsave');
    Route::get('/blog-saved', [BlogIndexController::class, 'saved'])->name('blog-saved');
    Route::get('/blog-saved/export', [BlogIndexController::class, 'savedExport'])->name('blog-saved.export');
    Route::delete('/blog-saved', [BlogIndexController::class, 'savedDestroy'])->name('blog-saved.destroy');

    // 경쟁 분석 (SEO 점수 + 순위추적)
    Route::get('/compete', [CompeteController::class, 'index'])->name('compete');
    Route::get('/compete/{slot}', [CompeteController::class, 'show'])->name('compete.show');
    Route::get('/compete/{slot}/explain/{place}', [CompeteController::class, 'explain'])->name('compete.explain');
    Route::get('/compete/{slot}/history/{place}', [CompeteController::class, 'history'])->name('compete.history');
    Route::post('/compete/{slot}/analyze', [CompeteController::class, 'analyze'])->name('compete.analyze');

    Route::get('/place-store', [PlaceStoreAnalysisController::class, 'index'])->name('place-store');
    Route::post('/place-store', [PlaceStoreAnalysisController::class, 'store'])->name('place-store.store');
    Route::get('/place-store/{analysis}', [PlaceStoreAnalysisController::class, 'show'])->name('place-store.show');
    Route::delete('/place-store/{analysis}', [PlaceStoreAnalysisController::class, 'destroy'])->name('place-store.destroy');

    // 셀러력 — 쇼핑 상품 SEO·지수 경쟁 비교 (확장 수집분)
    Route::get('/seller-power', [SellerPowerController::class, 'index'])->name('seller-power');
    Route::get('/seller-power/{analysis}', [SellerPowerController::class, 'show'])->name('seller-power.show');
    Route::delete('/seller-power/{analysis}', [SellerPowerController::class, 'destroy'])->name('seller-power.destroy');

    // 판매자 톡톡 연락처(셀러력 수집) — 슈퍼어드민 전용(컨트롤러 게이트, menu.gate는 슈퍼 통과)
    Route::get('/talk-contacts', [TalkContactController::class, 'index'])->name('talk-contacts');
    Route::get('/talk-contacts/export', [TalkContactController::class, 'export'])->name('talk-contacts.export');

    // 마케팅 리드(상담 문의) 관리 — 슈퍼어드민 전용(컨트롤러 게이트)
    Route::get('/leads', [MarketingLeadController::class, 'adminIndex'])->name('leads');
    Route::get('/leads/export', [MarketingLeadController::class, 'export'])->name('leads.export');
    Route::put('/leads/{lead}/status', [MarketingLeadController::class, 'updateStatus'])->name('leads.status');

    // 쇼핑 시장 분석 내역 (확장 프로그램 수집분)
    Route::get('/market', [MarketAnalysisController::class, 'index'])->name('market');
    Route::get('/market/{analysis}', [MarketAnalysisController::class, 'show'])->name('market.show');
    Route::delete('/market/{analysis}', [MarketAnalysisController::class, 'destroy'])->name('market.destroy');

    // 상품 분석(리뷰 분석) 내역
    Route::get('/product', [ProductAnalysisController::class, 'index'])->name('product');
    Route::get('/product/{analysis}', [ProductAnalysisController::class, 'show'])->name('product.show');
    Route::delete('/product/{analysis}', [ProductAnalysisController::class, 'destroy'])->name('product.destroy');

    // API 키 관리 (발급·허용기간·일일 한도·허용 IP) + 콘솔 내 개발자 문서(공개 /developers 와 본문 공용)
    Route::view('/developers', 'console.developers')->name('developers');
    Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::post('/api-keys/{key}/toggle', [ApiKeyController::class, 'toggle'])->name('api-keys.toggle');
    Route::post('/api-keys/{key}/regenerate', [ApiKeyController::class, 'regenerate'])->name('api-keys.regenerate');
    Route::delete('/api-keys/{key}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

    // 고객센터 — 공지사항 / FAQ / 1:1 문의
    Route::get('/notices', [SupportController::class, 'notices'])->name('notices');
    Route::get('/notices/{notice}', [SupportController::class, 'notice'])->name('notices.show');
    Route::get('/faq', [SupportController::class, 'faq'])->name('faq');
    Route::get('/support', [SupportController::class, 'qnaIndex'])->name('qna');
    Route::get('/support/new', [SupportController::class, 'qnaCreate'])->name('qna.create');
    Route::post('/support', [SupportController::class, 'qnaStore'])->name('qna.store');
    Route::get('/support/{qna}', [SupportController::class, 'qnaShow'])->name('qna.show');
    Route::delete('/support/{qna}', [SupportController::class, 'qnaDestroy'])->name('qna.destroy');
});

// 관리자 (운영자 전용)
Route::middleware(['auth', 'operator'])->prefix('admin')->name('admin.')->group(function () {
    // 관리자 대시보드 — /admin 진입 시 핵심 지표(방문·가입·추적·문의·커뮤니티·발행 등)
    Route::get('/', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('home');
    Route::get('/dashboard', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');

    // 순위추적 관리 — 전 회원의 플레이스·쇼핑 순위추적 슬롯 열람
    Route::get('/place-tracking', [\App\Http\Controllers\Admin\RankTrackingController::class, 'place'])->name('place-tracking');
    Route::get('/shop-tracking', [\App\Http\Controllers\Admin\RankTrackingController::class, 'shop'])->name('shop-tracking');

    // 쇼핑 노출 키워드 분석 (핵심 키워드+상품 → 조합 → 쇼핑 상위 N위 노출 판정) (25) — 2026-07-21 콘솔→관리자 이동
    Route::get('/shop-keyword', [ShopKeywordExposureController::class, 'index'])->name('shop-keyword');
    Route::post('/shop-keyword', [ShopKeywordExposureController::class, 'store'])->middleware('throttle:30,1')->name('shop-keyword.store');
    Route::get('/shop-keyword/{analysis}', [ShopKeywordExposureController::class, 'show'])->name('shop-keyword.show');
    Route::post('/shop-keyword/{analysis}/check', [ShopKeywordExposureController::class, 'check'])->middleware('throttle:240,1')->name('shop-keyword.check');
    Route::get('/shop-keyword/{analysis}/pending', [ShopKeywordExposureController::class, 'pending'])->middleware('throttle:240,1')->name('shop-keyword.pending');
    Route::post('/shop-keyword/{analysis}/check-html', [ShopKeywordExposureController::class, 'checkHtml'])->middleware('throttle:240,1')->name('shop-keyword.check-html');
    Route::post('/shop-keyword/{analysis}/supplement', [ShopKeywordExposureController::class, 'supplement'])->middleware('throttle:30,1')->name('shop-keyword.supplement');
    Route::post('/shop-keyword/{analysis}/product-info', [ShopKeywordExposureController::class, 'refreshProductInfo'])->middleware('throttle:30,1')->name('shop-keyword.product-info');
    Route::post('/shop-keyword/{analysis}/pause', [ShopKeywordExposureController::class, 'pause'])->middleware('throttle:60,1')->name('shop-keyword.pause');
    Route::post('/shop-keyword/{analysis}/regenerate', [ShopKeywordExposureController::class, 'regenerate'])->middleware('throttle:30,1')->name('shop-keyword.regenerate');
    Route::post('/shop-keyword/{analysis}/recheck-exposed', [ShopKeywordExposureController::class, 'recheckExposed'])->middleware('throttle:30,1')->name('shop-keyword.recheck-exposed');
    Route::post('/shop-keyword/{analysis}/short-links', [ShopKeywordExposureController::class, 'storeShortLinks'])->name('shop-keyword.short-links.store');
    Route::post('/shop-keyword/{analysis}/short-links/reassign', [ShopKeywordExposureController::class, 'reassignShortLinks'])->name('shop-keyword.short-links.reassign');
    Route::delete('/shop-keyword/{analysis}/item/{item}', [ShopKeywordExposureController::class, 'deleteItem'])->name('shop-keyword.item');
    Route::delete('/shop-keyword/{analysis}', [ShopKeywordExposureController::class, 'destroy'])->name('shop-keyword.destroy');

    // 마케팅 상품 관리 (폼 빌더 + 주문 URL 발급)
    Route::get('/products', [MarketingProductController::class, 'index'])->name('products');
    Route::get('/products/create', [MarketingProductController::class, 'create'])->name('products.create');
    Route::post('/products', [MarketingProductController::class, 'store'])->name('products.store');
    Route::post('/products/reorder', [MarketingProductController::class, 'reorder'])->name('products.reorder');
    Route::get('/products/{product}/edit', [MarketingProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [MarketingProductController::class, 'update'])->name('products.update');
    Route::post('/products/{product}/toggle', [MarketingProductController::class, 'toggle'])->name('products.toggle');
    Route::post('/products/{product}/duplicate', [MarketingProductController::class, 'duplicate'])->name('products.duplicate');
    Route::delete('/products/{product}', [MarketingProductController::class, 'destroy'])->name('products.destroy');

    // 주문 관리 (목록·상세·상태 변경 · 승인=외부 발주)
    Route::get('/orders', [MarketingOrderController::class, 'index'])->name('orders');
    Route::get('/orders/{order}', [MarketingOrderController::class, 'show'])->name('orders.show');
    Route::put('/orders/{order}/status', [MarketingOrderController::class, 'updateStatus'])->name('orders.status');
    Route::post('/orders/{order}/approve', [MarketingOrderController::class, 'approve'])->name('orders.approve');
    Route::post('/orders/{order}/shop-keyword', [MarketingOrderController::class, 'createShopKeyword'])->middleware('throttle:30,1')->name('orders.shop-keyword');
    Route::put('/orders/{order}/internal-fields', [MarketingOrderController::class, 'updateInternalFields'])->name('orders.internal-fields');
    Route::post('/orders/{order}/autofill', [MarketingOrderController::class, 'autofillInternalFields'])->name('orders.autofill');
    Route::post('/orders/dispatches/{dispatch}/retry', [MarketingOrderController::class, 'retryDispatch'])->name('orders.dispatch.retry');
    Route::delete('/orders/{order}', [MarketingOrderController::class, 'destroy'])->name('orders.destroy');

    // 구글 계정 OAuth 연동 (서치 콘솔·GA4 공용)
    Route::get('/google-connect', [\App\Http\Controllers\Admin\GoogleConnectController::class, 'redirect'])->name('google-connect');
    Route::get('/google-connect/callback', [\App\Http\Controllers\Admin\GoogleConnectController::class, 'callback'])->name('google-connect.callback');
    Route::post('/google-connect/disconnect', [\App\Http\Controllers\Admin\GoogleConnectController::class, 'disconnect'])->name('google-connect.disconnect');

    // 키워드 탐색 — 수집된 키워드를 플레이스/쇼핑별로 검색·조회만(관리는 허브에서)
    Route::get('/keyword-browse', [\App\Http\Controllers\Admin\KeywordBrowseController::class, 'index'])->name('keyword-browse');
    // 키워드 상세 — 그 키워드로 노출되는 업체 수집(플레이스 SERP 최대 300)
    Route::get('/keyword-browse/detail', [\App\Http\Controllers\Admin\KeywordBrowseController::class, 'detail'])->name('keyword-browse.detail');
    Route::delete('/keyword-browse/month', [\App\Http\Controllers\Admin\KeywordBrowseController::class, 'deleteMonth'])->name('keyword-browse.month.delete');
    // 수집 상품 — 키워드와 별개로, 수집된 상품 전체를 상품 기준으로 본다
    Route::get('/shop-products', [\App\Http\Controllers\Admin\ShopProductController::class, 'index'])->name('shop-products');
    Route::get('/shop-products/seller-captchas/{captcha}/image', [\App\Http\Controllers\Admin\ShopProductController::class, 'captchaImage'])->name('shop-products.seller-captchas.image');

    // 판매자정보 — 캡차 통과 후 수집된 사업자 정보만 별도 목록(업체명·대표자·톡톡·전화·스토어)
    Route::get('/seller-infos', [\App\Http\Controllers\Admin\SellerInfoController::class, 'index'])->name('seller-infos');

    // 키워드 자동 분석 — 후보 관리, 플레이스 키워드 분석·쇼핑 시장 분석 병렬 발행
    Route::get('/keyword-hub', [\App\Http\Controllers\Admin\KeywordHubController::class, 'index'])->name('keyword-hub');
    Route::get('/keyword-hub/candidates', [\App\Http\Controllers\Admin\KeywordHubController::class, 'candidates'])->name('keyword-hub.candidates');
    Route::get('/keyword-hub/published/{type}', [\App\Http\Controllers\Admin\KeywordHubController::class, 'published'])->name('keyword-hub.published-all');
    Route::get('/keyword-hub/published/{type}/{category}', [\App\Http\Controllers\Admin\KeywordHubController::class, 'published'])->name('keyword-hub.published');
    Route::post('/keyword-hub/categories', [\App\Http\Controllers\Admin\KeywordHubController::class, 'storeCategory'])->name('keyword-hub.categories.store');
    Route::put('/keyword-hub/categories/{category}', [\App\Http\Controllers\Admin\KeywordHubController::class, 'updateCategory'])->name('keyword-hub.categories.update');
    Route::post('/keyword-hub/categories/{category}/toggle', [\App\Http\Controllers\Admin\KeywordHubController::class, 'toggleCategory'])->name('keyword-hub.categories.toggle');
    Route::delete('/keyword-hub/categories/{category}', [\App\Http\Controllers\Admin\KeywordHubController::class, 'destroyCategory'])->name('keyword-hub.categories.destroy');
    Route::post('/keyword-hub/candidates/bulk', [\App\Http\Controllers\Admin\KeywordHubController::class, 'bulkCandidates'])->name('keyword-hub.candidates.bulk');
    Route::post('/keyword-hub/candidates/bulk-all', [\App\Http\Controllers\Admin\KeywordHubController::class, 'bulkAllCandidates'])->name('keyword-hub.candidates.bulk-all');
    Route::post('/keyword-hub/collect', [\App\Http\Controllers\Admin\KeywordHubController::class, 'collect'])->name('keyword-hub.collect');
    Route::post('/keyword-hub/collect-batch', [\App\Http\Controllers\Admin\KeywordHubController::class, 'startCollection'])->name('keyword-hub.collect-batch');
    Route::get('/keyword-hub/collect-status', [\App\Http\Controllers\Admin\KeywordHubController::class, 'collectionStatus'])->name('keyword-hub.collect-status');
    Route::post('/keyword-hub/collect-control', [\App\Http\Controllers\Admin\KeywordHubController::class, 'collectionControl'])->name('keyword-hub.collect-control');
    Route::post('/keyword-hub/publish', [\App\Http\Controllers\Admin\KeywordHubController::class, 'publish'])->name('keyword-hub.publish');
    Route::post('/keyword-hub/publish-batch', [\App\Http\Controllers\Admin\KeywordHubController::class, 'publishBatch'])->name('keyword-hub.publish-batch');
    Route::post('/keyword-hub/auto', [\App\Http\Controllers\Admin\KeywordHubController::class, 'autoToggle'])->name('keyword-hub.auto');
    Route::get('/keyword-hub/auto-status', [\App\Http\Controllers\Admin\KeywordHubController::class, 'autoStatus'])->name('keyword-hub.auto-status');
    Route::get('/keyword-hub/collect-market-status', [\App\Http\Controllers\Admin\KeywordHubController::class, 'collectMarketStatus'])->name('keyword-hub.collect-market-status');

    // 자동 수집 현황 — 스케줄러에 등록된 자동 작업(무엇을·언제)과 데이터별 최근 수집 시각 열람
    Route::get('/schedule', [\App\Http\Controllers\Admin\ScheduleOverviewController::class, 'index'])->name('schedule');

    // 신규 개업(24) — 인허가 공공데이터 열람 + 네이버 플레이스 등록 여부. ⚠️ 열람 전용(광고 발송 금지)
    Route::get('/new-businesses', [\App\Http\Controllers\Admin\NewBusinessController::class, 'index'])->name('new-businesses');
    Route::post('/new-businesses/collect', [\App\Http\Controllers\Admin\NewBusinessController::class, 'collect'])->name('new-businesses.collect');
    Route::post('/new-businesses/place-match', [\App\Http\Controllers\Admin\NewBusinessController::class, 'placeMatch'])->name('new-businesses.place-match');

    // 검색 유입 분석 (구글 서치 콘솔)
    Route::get('/search-stats', [\App\Http\Controllers\Admin\SearchStatsController::class, 'index'])->name('search-stats');
    Route::post('/search-stats/collect', [\App\Http\Controllers\Admin\SearchStatsController::class, 'collect'])->name('search-stats.collect');

    // 방문 분석 (GA4) — ga4-insights 패키지가 /admin/traffic-stats(route명 admin.traffic-stats)에 마운트.
    //   패키지: packages/ga4-insights · 설정: config/ga4-insights.php · 자격증명: App\Support\AppGa4Credentials

    // 외부 발주 업체 관리 (API/구글시트)
    Route::get('/vendors', [\App\Http\Controllers\Admin\VendorController::class, 'index'])->name('vendors');
    Route::post('/vendors', [\App\Http\Controllers\Admin\VendorController::class, 'store'])->name('vendors.store');
    Route::put('/vendors/{vendor}', [\App\Http\Controllers\Admin\VendorController::class, 'update'])->name('vendors.update');
    Route::post('/vendors/{vendor}/toggle', [\App\Http\Controllers\Admin\VendorController::class, 'toggle'])->name('vendors.toggle');
    Route::get('/vendors/{vendor}/sheet-columns', [\App\Http\Controllers\Admin\VendorController::class, 'sheetColumns'])->name('vendors.sheet-columns');
    Route::put('/vendors/{vendor}/gsheet-tab', [\App\Http\Controllers\Admin\VendorController::class, 'updateGsheetTab'])->name('vendors.gsheet-tab');
    Route::delete('/vendors/{vendor}', [\App\Http\Controllers\Admin\VendorController::class, 'destroy'])->name('vendors.destroy');

    // 회원 관리
    Route::get('/members', [MemberController::class, 'index'])->name('members');
    Route::put('/members/{user}', [MemberController::class, 'update'])->name('members.update');

    // 구독 관리 (요금제 + 구독 현황)
    Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions');
    Route::post('/subscriptions/plans', [SubscriptionController::class, 'storePlan'])->name('subscriptions.plans.store');
    Route::put('/subscriptions/plans/{plan}', [SubscriptionController::class, 'updatePlan'])->name('subscriptions.plans.update');
    Route::post('/subscriptions/plans/{plan}/toggle', [SubscriptionController::class, 'togglePlan'])->name('subscriptions.plans.toggle');
    Route::delete('/subscriptions/plans/{plan}', [SubscriptionController::class, 'destroyPlan'])->name('subscriptions.plans.destroy');
    Route::post('/subscriptions/{user}/extend', [SubscriptionController::class, 'extend'])->name('subscriptions.extend');
    Route::post('/subscriptions/{user}/cancel', [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');

    // 공지사항 관리
    Route::get('/notices', [AdminNoticeController::class, 'index'])->name('notices');
    Route::get('/notices/create', [AdminNoticeController::class, 'create'])->name('notices.create');
    Route::post('/notices', [AdminNoticeController::class, 'store'])->name('notices.store');
    Route::get('/notices/{notice}/edit', [AdminNoticeController::class, 'edit'])->name('notices.edit');
    Route::put('/notices/{notice}', [AdminNoticeController::class, 'update'])->name('notices.update');
    Route::delete('/notices/{notice}', [AdminNoticeController::class, 'destroy'])->name('notices.destroy');
    Route::post('/notices/{notice}/toggle', [AdminNoticeController::class, 'toggle'])->name('notices.toggle');

    // FAQ 관리
    Route::get('/faqs', [AdminFaqController::class, 'index'])->name('faqs');
    Route::get('/faqs/create', [AdminFaqController::class, 'create'])->name('faqs.create');
    Route::post('/faqs', [AdminFaqController::class, 'store'])->name('faqs.store');
    Route::get('/faqs/{faq}/edit', [AdminFaqController::class, 'edit'])->name('faqs.edit');
    Route::put('/faqs/{faq}', [AdminFaqController::class, 'update'])->name('faqs.update');
    Route::delete('/faqs/{faq}', [AdminFaqController::class, 'destroy'])->name('faqs.destroy');
    Route::post('/faqs/{faq}/toggle', [AdminFaqController::class, 'toggle'])->name('faqs.toggle');

    // 1:1 문의 관리 (답변)
    Route::get('/qnas', [AdminQnaController::class, 'index'])->name('qnas');
    Route::get('/qnas/{qna}', [AdminQnaController::class, 'show'])->name('qnas.show');
    Route::post('/qnas/{qna}/answer', [AdminQnaController::class, 'answer'])->name('qnas.answer');
    Route::delete('/qnas/{qna}', [AdminQnaController::class, 'destroy'])->name('qnas.destroy');

    // 배너 관리 (대시보드 홍보)
    Route::get('/banners', [BannerController::class, 'index'])->name('banners');
    Route::get('/banners/create', [BannerController::class, 'create'])->name('banners.create');
    Route::post('/banners', [BannerController::class, 'store'])->name('banners.store');
    Route::get('/banners/{banner}/edit', [BannerController::class, 'edit'])->name('banners.edit');
    Route::put('/banners/{banner}', [BannerController::class, 'update'])->name('banners.update');
    Route::delete('/banners/{banner}', [BannerController::class, 'destroy'])->name('banners.destroy');
    Route::post('/banners/{banner}/toggle', [BannerController::class, 'toggle'])->name('banners.toggle');

    // 팝업 관리 (위치·기간·에디터)
    Route::get('/popups', [PopupController::class, 'index'])->name('popups');
    Route::get('/popups/create', [PopupController::class, 'create'])->name('popups.create');
    Route::post('/popups', [PopupController::class, 'store'])->name('popups.store');
    Route::get('/popups/{popup}/edit', [PopupController::class, 'edit'])->name('popups.edit');
    Route::put('/popups/{popup}', [PopupController::class, 'update'])->name('popups.update');
    Route::delete('/popups/{popup}', [PopupController::class, 'destroy'])->name('popups.destroy');
    Route::post('/popups/{popup}/toggle', [PopupController::class, 'toggle'])->name('popups.toggle');

    // 메뉴 관리
    Route::get('/menus', [MenuController::class, 'index'])->name('menus');
    Route::post('/menus', [MenuController::class, 'store'])->name('menus.store');
    Route::put('/menus/{menu}', [MenuController::class, 'update'])->name('menus.update');
    Route::delete('/menus/{menu}', [MenuController::class, 'destroy'])->name('menus.destroy');
    Route::post('/menus/{menu}/toggle', [MenuController::class, 'toggle'])->name('menus.toggle');
    Route::post('/menus/reorder', [MenuController::class, 'reorder'])->name('menus.reorder');
    Route::post('/menus/{menu}/permissions', [MenuController::class, 'savePermissions'])->name('menus.permissions');

    // 커뮤니티 페르소나 관리 + 자동 활동 시뮬레이션
    Route::get('/personas', [PersonaController::class, 'index'])->name('personas');
    Route::get('/personas/create', [PersonaController::class, 'create'])->name('personas.create');
    Route::post('/personas', [PersonaController::class, 'store'])->name('personas.store');
    Route::post('/personas/generate', [PersonaController::class, 'generate'])->name('personas.generate');
    Route::post('/personas/simulate', [PersonaController::class, 'simulate'])->name('personas.simulate');
    Route::get('/personas/{persona}/edit', [PersonaController::class, 'edit'])->name('personas.edit');
    Route::put('/personas/{persona}', [PersonaController::class, 'update'])->name('personas.update');
    Route::post('/personas/{persona}/toggle', [PersonaController::class, 'toggle'])->name('personas.toggle');
    Route::delete('/personas/{persona}', [PersonaController::class, 'destroy'])->name('personas.destroy');

    // 환경 설정 — 네이버 API 자격증명 관리
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/secondary-domain', [SettingsController::class, 'createSecondaryDomain'])->name('settings.secondary-domain.create');

    // 커뮤니티 카테고리 관리 (추가·이름/아이콘/정렬·사용 여부)
    Route::get('/community-categories', [CommunityCategoryController::class, 'index'])->name('community-categories');
    Route::post('/community-categories', [CommunityCategoryController::class, 'store'])->name('community-categories.store');
    Route::put('/community-categories/{category}', [CommunityCategoryController::class, 'update'])->name('community-categories.update');
    Route::post('/community-categories/{category}/toggle', [CommunityCategoryController::class, 'toggle'])->name('community-categories.toggle');
    Route::delete('/community-categories/{category}', [CommunityCategoryController::class, 'destroy'])->name('community-categories.destroy');

    // 글밥(소스) 관리 — 수집한 글감을 페르소나가 소재로 변형해 사용
    Route::get('/community-seeds', [CommunitySeedController::class, 'index'])->name('community-seeds');
    Route::post('/community-seeds', [CommunitySeedController::class, 'store'])->name('community-seeds.store');
    Route::post('/community-seeds/{seed}/toggle', [CommunitySeedController::class, 'toggle'])->name('community-seeds.toggle');
    Route::delete('/community-seeds/{seed}', [CommunitySeedController::class, 'destroy'])->name('community-seeds.destroy');

    // 카페 수집 글감 — 크롤 원본(글·댓글) 조회 + 수동 수집
    Route::get('/cafe-seeds', [CafeSeedController::class, 'index'])->name('cafe-seeds');
    Route::post('/cafe-seeds/crawl', [CafeSeedController::class, 'crawl'])->name('cafe-seeds.crawl');
    Route::post('/cafe-seeds/{article}/toggle-seed', [CafeSeedController::class, 'toggleSeed'])->name('cafe-seeds.toggle-seed');
    Route::get('/cafe-seeds/{article}', [CafeSeedController::class, 'show'])->name('cafe-seeds.show');

    Route::view('/permissions', 'admin.stub', ['title' => '권한 설정'])->name('permissions');
});
