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
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BlogIndexController;
use App\Http\Controllers\PhoneVerificationController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\BulkKeywordController;
use App\Http\Controllers\Admin\CommunitySeedController;
use App\Http\Controllers\Admin\PersonaController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\CompeteController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\KeywordAnalysisController;
use App\Http\Controllers\MarketAnalysisController;
use App\Http\Controllers\MarketingLeadController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductAnalysisController;
use App\Http\Controllers\RankCheckController;
use App\Http\Controllers\RankTrackController;
use App\Http\Controllers\SellerPowerController;
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
// 홈 폼 자동입력(공개) — URL → m.place 변환·업체명 / 쇼핑 상품명
Route::post('/rank-resolve/place', [RankCheckController::class, 'resolvePlace'])->middleware('throttle:30,1')->name('rank.resolve.place');
Route::post('/rank-resolve/shop', [RankCheckController::class, 'resolveShop'])->middleware('throttle:30,1')->name('rank.resolve.shop');

// 순위 추적 공개 리포트 — 공유 토큰으로 비로그인 열람
Route::get('/r/{token}', [RankTrackController::class, 'shared'])->name('rank.shared');
// 경쟁 분석 공개 리포트 — 공유 토큰으로 비로그인 열람
Route::get('/rc/{token}', [CompeteController::class, 'shared'])->name('compete.shared');
// 쇼핑 순위추적 공개 리포트 — 공유 토큰으로 비로그인 열람
Route::get('/sr/{token}', [ShopRankTrackController::class, 'shared'])->name('shop-rank.shared');
// 시장 분석 공개 리포트 — 공유 토큰으로 비로그인 열람
Route::get('/m/{token}', [MarketAnalysisController::class, 'shared'])->name('market.shared');
// 상품 분석 공개 리포트 — 공유 토큰으로 비로그인 열람
Route::get('/p/{token}', [ProductAnalysisController::class, 'shared'])->name('product.shared');
// 키워드 분석 공개 리포트 — 공유 토큰으로 비로그인 열람
Route::get('/k/{token}', [KeywordAnalysisController::class, 'shared'])->name('keyword.shared');
// 셀러력 분석 공개 리포트 — 공유 토큰으로 비로그인 열람
Route::get('/sp/{token}', [SellerPowerController::class, 'shared'])->name('seller-power.shared');

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

// 커뮤니티 (공개 열람, 작성은 로그인 필요)
Route::get('/community', [CommunityController::class, 'index'])->name('community');
Route::get('/community/post/{post}', [CommunityController::class, 'show'])->name('community.show');
Route::middleware('auth')->group(function () {
    Route::get('/community/new', [CommunityController::class, 'create'])->name('community.create');
    Route::post('/community', [CommunityController::class, 'store'])->name('community.store');
    Route::post('/community/post/{post}/comment', [CommunityController::class, 'comment'])->name('community.comment');
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
});
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// 콘솔 (로그인 필요) — menu.gate: 메뉴 접근 차단, usage.gate: 메뉴×등급 월 이용횟수 제한
Route::middleware(['auth', 'menu.gate', 'usage.gate'])->prefix('console')->name('console.')->group(function () {
    Route::get('/', [ConsoleController::class, 'dashboard'])->name('dashboard');

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
    Route::post('/shop-rank', [ShopRankTrackController::class, 'store'])->name('shop-rank.store');
    Route::post('/shop-rank/{slot}/run', [ShopRankTrackController::class, 'run'])->name('shop-rank.run');
    Route::put('/shop-rank/{slot}', [ShopRankTrackController::class, 'update'])->name('shop-rank.update');
    Route::delete('/shop-rank/{slot}', [ShopRankTrackController::class, 'destroy'])->name('shop-rank.destroy');

    // 마케팅 키워드 분석 (검색량·성별/연령·트렌드·연관키워드)
    Route::get('/keyword', [KeywordAnalysisController::class, 'index'])->name('keyword');
    // 통합검색 PC/모바일 섹션 배치 순서 (Playwright 수집 — 비동기 lazy 로드)
    Route::get('/keyword/sections', [KeywordAnalysisController::class, 'sections'])->name('keyword.sections');
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

    // API 키 관리 (발급·허용기간·일일 한도·허용 IP)
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
    Route::get('/', fn () => redirect()->route('admin.menus'))->name('home');

    // 마케팅 상품 관리 (폼 빌더 + 주문 URL 발급)
    Route::get('/products', [MarketingProductController::class, 'index'])->name('products');
    Route::get('/products/create', [MarketingProductController::class, 'create'])->name('products.create');
    Route::post('/products', [MarketingProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [MarketingProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [MarketingProductController::class, 'update'])->name('products.update');
    Route::post('/products/{product}/toggle', [MarketingProductController::class, 'toggle'])->name('products.toggle');
    Route::delete('/products/{product}', [MarketingProductController::class, 'destroy'])->name('products.destroy');

    // 주문 관리 (목록·상세·상태 변경)
    Route::get('/orders', [MarketingOrderController::class, 'index'])->name('orders');
    Route::get('/orders/{order}', [MarketingOrderController::class, 'show'])->name('orders.show');
    Route::put('/orders/{order}/status', [MarketingOrderController::class, 'updateStatus'])->name('orders.status');
    Route::delete('/orders/{order}', [MarketingOrderController::class, 'destroy'])->name('orders.destroy');

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

    // 글밥(소스) 관리 — 수집한 글감을 페르소나가 소재로 변형해 사용
    Route::get('/community-seeds', [CommunitySeedController::class, 'index'])->name('community-seeds');
    Route::post('/community-seeds', [CommunitySeedController::class, 'store'])->name('community-seeds.store');
    Route::post('/community-seeds/{seed}/toggle', [CommunitySeedController::class, 'toggle'])->name('community-seeds.toggle');
    Route::delete('/community-seeds/{seed}', [CommunitySeedController::class, 'destroy'])->name('community-seeds.destroy');

    Route::view('/permissions', 'admin.stub', ['title' => '권한 설정'])->name('permissions');
});
