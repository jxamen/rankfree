@extends('admin.layout')
@section('page-title', '환경 설정')

@push('head')
<style>
    .rf-tabs { display:flex; gap:2px; border-bottom:1px solid var(--color-hairline); margin-bottom:20px; }
    .rf-tab { padding:9px 18px; font-size:var(--fs-sm); font-weight:600; color:var(--color-muted); background:none; border:0; border-bottom:2px solid transparent; margin-bottom:-1px; cursor:pointer; transition:color .12s ease, border-color .12s ease; }
    .rf-tab:hover { color:var(--color-ink); }
    .rf-tab.on { color:var(--color-primary); border-bottom-color:var(--color-primary); }
    .rf-tabpane[hidden] { display:none; }
</style>
@endpush

@section('admin-content')
@php
    // 저장/리디렉션 후에도 보던 탭 유지 — ?tab= 파라미터로 초기 활성 탭 결정
    $__tabs = ['basic' => '광고·데이터 API', 'api' => 'AI API', 'integ' => '외부 연동', 'member' => '회원', 'payment' => '결제', 'place' => '플레이스 패턴', 'domains' => '2차 도메인', 'custom' => '커스텀 코드'];
    $__active = array_key_exists(request('tab'), $__tabs) ? request('tab') : 'basic';
@endphp
<x-console.page-head title="환경 설정" desc="API 자격증명·수집·AI 등 서비스 운영 설정 · 탭별로 저장됩니다" />
<form id="rf-settings-form" method="POST" action="{{ route('admin.settings.update') }}">
    @csrf @method('PUT')
    <input type="hidden" name="tab" id="rf-active-tab" value="{{ $__active }}">

    <div class="rf-tabs" role="tablist">
        @foreach ($__tabs as $__k => $__label)
            <button type="button" class="rf-tab {{ $__active === $__k ? 'on' : '' }}" data-tab="{{ $__k }}" role="tab">{{ $__label }}</button>
        @endforeach
    </div>

    {{-- ── 기본: 네이버 데이터 수집 자격증명 ───────────────────────────── --}}
    <div class="rf-tabpane" data-tab="basic" @if ($__active !== 'basic') hidden @endif>
        <p class="text-muted mb-4" style="font-size:var(--fs-xs);">
            네이버 데이터 수집에 필요한 자격증명입니다. 각 항목은 <b>여러 개 등록</b>할 수 있고(조회 실패 시 자동 로테이션),
            값은 <b>암호화 저장</b>되며 <code>.env</code>보다 우선 적용됩니다.
            비밀 키·비밀번호는 기본적으로 가려져 있고 <i class="fa-regular fa-eye"></i>(보기)로 확인·<i class="fa-regular fa-copy"></i>(복사)할 수 있으며,
            <b class="text-error"><i class="fa-solid fa-xmark"></i></b>를 누르면 해당 줄이 바로 사라집니다(저장 시 반영).
        </p>

        @include('admin.settings._credgroup', [
            'g' => 'searchad',
            'title' => '네이버 검색광고 API',
            'desc' => '키워드 분석(월간 검색량·경쟁강도)에 사용 — "여름매트" 조회 실패는 이 값이 없어 발생합니다. 발급: manage.searchad.naver.com → 도구 → API 사용 관리',
            'plain' => ['api_key', 'customer_id'],
            'secret' => 'secret_key',
            'labels' => ['api_key' => 'API 키(액세스 라이선스)', 'customer_id' => 'Customer ID', 'secret_key' => '비밀 키'],
            'rows' => $searchadRows,
            'live' => $liveSearchad,
        ])

        @include('admin.settings._credgroup', [
            'g' => 'ads',
            'title' => '네이버 광고주 로그인',
            'desc' => '성별·연령별 비율, 월별 검색 트렌드(웹 크롤링 세션)에 사용. 광고주 계정 아이디/비밀번호만 필요합니다.',
            'plain' => ['id'],
            'secret' => 'pw',
            'labels' => ['id' => '광고주 아이디', 'pw' => '비밀번호'],
            'rows' => $adsRows,
            'live' => $liveAds,
        ])

        @include('admin.settings._credgroup', [
            'g' => 'openapi',
            'title' => '네이버 OpenAPI · 데이터랩(트렌드) 키',
            'desc' => '쇼핑 검색·발행량·데이터랩 트렌드에 공용. developers.naver.com 애플리케이션의 Client ID/Secret.',
            'plain' => ['id'],
            'secret' => 'secret',
            'labels' => ['id' => 'Client ID', 'secret' => 'Client Secret'],
            'rows' => $openapiRows,
            'live' => $liveOpenapi,
        ])

        {{-- 잔디(JANDI) 웹훅 — 주문 접수 알림 --}}
        <div class="card p-5 mb-4 mt-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">
                잔디(JANDI) 웹훅 <span class="text-muted-soft" style="font-weight:400;">주문 접수 알림</span>
                @if (trim((string) $jandiOrderWebhookUrl) !== '')
                    <span class="badge" style="font-size:var(--fs-xs);margin-left:6px;background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);">적용중</span>
                @endif
            </div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);line-height:1.6;">
                잔디 커넥트 → <b>Webhook 수신(Incoming Webhook)</b>에서 발급받은 URL을 넣으면
                <b>주문이 접수될 때마다</b> 지정한 대화방으로 주문번호(클릭 시 주문 상세)·상품·수량·기간·금액·주문자·회원 누적 알림이 전송됩니다(웹·API 주문 공통, 큐 발송). 비우면 알림을 보내지 않습니다.
            </p>
            @include('admin.settings._simplefield', ['name' => 'jandi_order_webhook_url', 'label' => '웹훅 URL (주문 접수 알림)', 'value' => $jandiOrderWebhookUrl, 'secret' => true, 'placeholder' => 'https://wh.jandi.com/connect-api/webhook/…'])
        </div>
    </div>

    {{-- ── API 설정: AI 모델 키 ─────────────────────────────────────── --}}
    <div class="rf-tabpane" data-tab="api" @if ($__active !== 'api') hidden @endif>
        <p class="text-muted mb-4" style="font-size:var(--fs-xs);">
            AI 모델(LLM) API 키를 관리합니다. 커뮤니티 글·댓글 자동 생성 등에 사용되며, 값은 <b>암호화 저장</b>됩니다.
            비밀 키는 기본적으로 가려져 있고 <i class="fa-regular fa-eye"></i>(보기)·<i class="fa-regular fa-copy"></i>(복사)할 수 있습니다.
        </p>

        @include('admin.settings._aigroup', [
            'rows' => $aiRows,
            'providers' => $aiProviders,
            'live' => $liveAi,
        ])

        {{-- 커뮤니티 글 재작성(글밥 → AI 재작성) 설정 --}}
        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">커뮤니티 글 재작성 <span class="text-muted-soft" style="font-weight:400;">수집 글밥 → 페르소나 글·댓글</span></div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">
                수집한 글밥(카페 인기글·댓글)을 어떤 AI 로 재작성할지 정합니다. 키는 위 <b>AI 모델 API 키</b>에 등록하세요.
                <b>자동</b>은 Gemini(무료 티어) 우선, 실패 시 Claude 순으로 시도합니다.
            </p>
            <div class="mb-3" style="max-width:560px;">
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;display:block;margin-bottom:5px;">재작성 AI 공급자</label>
                <select name="community_rewrite_provider" class="input" style="width:100%;font-size:var(--fs-xs);">
                    <option value="auto" @selected($rewriteProvider === 'auto')>자동 — Gemini → Claude → OpenAI → Grok 순 시도</option>
                    <option value="gemini" @selected($rewriteProvider === 'gemini')>Gemini (Google)</option>
                    <option value="anthropic" @selected($rewriteProvider === 'anthropic')>Claude (Anthropic)</option>
                    <option value="openai" @selected($rewriteProvider === 'openai')>OpenAI (GPT)</option>
                    <option value="xai" @selected($rewriteProvider === 'xai')>Grok (xAI)</option>
                    <option value="off" @selected($rewriteProvider === 'off')>사용 안 함 — AI 재작성 끄기</option>
                </select>
            </div>
            @php
                // 공급자별 사용 가능한 최신 모델(위가 상위). 값은 사용자 지정 가능.
                // Gemini 는 -latest 별칭 권장 — 신규 프로젝트는 2.5-pro/2.5-flash 가 404(종료).
                $rewriteModelGroups = [
                    'Gemini' => ['gemini-flash-latest', 'gemini-pro-latest', 'gemini-3.5-flash', 'gemini-3.1-pro-preview', 'gemini-3.1-flash-lite'],
                    'Claude' => ['claude-sonnet-5', 'claude-opus-4-8'],
                    'OpenAI' => ['gpt-5.6', 'gpt-5'],
                    'Grok' => ['grok-4.3', 'grok-4'],
                ];
                $rewriteModelKnown = collect($rewriteModelGroups)->flatten()->all();
            @endphp
            <div class="mb-3" style="max-width:560px;">
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;display:block;margin-bottom:5px;">재작성 모델 <span class="text-muted-soft" style="font-weight:400;">비우면 공급자 기본 — Gemini: gemini-flash-latest · Claude: claude-sonnet-5</span></label>
                <select name="community_rewrite_model" class="input" style="width:100%;font-size:var(--fs-xs);">
                    <option value="" @selected($rewriteModel === '')>공급자 기본값 사용</option>
                    @if ($rewriteModel !== '' && ! in_array($rewriteModel, $rewriteModelKnown, true))
                        <option value="{{ $rewriteModel }}" selected>{{ $rewriteModel }} (사용자 지정)</option>
                    @endif
                    @foreach ($rewriteModelGroups as $grp => $models)
                        <optgroup label="{{ $grp }}">
                            @foreach ($models as $mo)
                                <option value="{{ $mo }}" @selected($rewriteModel === $mo)>{{ $mo }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:var(--fs-xs);color:var(--color-muted);cursor:pointer;">
                <input type="checkbox" name="community_rewrite_fallback" value="1" @checked($rewriteFallback)>
                AI 호출 실패 시 글밥 원문을 가볍게 변형해 사용 <span class="text-muted-soft">(끄면 재작성 실패 시 글밥을 쓰지 않고 일반 문장 생성)</span>
            </label>
            {{-- 재작성 추론(thinking) — 끄면 flash/lite 계열은 thinkingBudget=0 으로 추론 토큰이 사라져 비용 대폭 절감(기본 꺼짐) --}}
            <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:var(--fs-xs);color:var(--color-muted);cursor:pointer;">
                <input type="checkbox" name="community_rewrite_thinking" value="1" @checked($rewriteThinking)>
                추론(thinking) 사용 <span class="text-muted-soft">정확도↑·비용↑ — 끄면 flash/lite 계열 재작성 토큰이 대폭 줄어듭니다(기본 꺼짐 권장). Pro 계열은 항상 추론</span>
            </label>
        </div>

        {{-- 캡차(퀴즈) 이미지 분석 모델 — 판매자정보 영수증 퀴즈 풀이(멀티 공급자 비전) --}}
        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">캡차(퀴즈) 이미지 분석 <span class="text-muted-soft" style="font-weight:400;">판매자정보 영수증 퀴즈 풀이</span></div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">
                판매자정보 캡차(영수증)를 어떤 <b>비전 모델</b>로 풀지 정합니다 — <b>Gemini·OpenAI(GPT)·Claude·Grok</b> 지원(모델명으로 공급자 자동 판별).
                <b>Pro</b>급은 숫자·표 판독 정확도가 높고 <b>Flash</b>급은 빠릅니다. 선택한 공급자의 키를 위 <b>AI 모델 API 키</b>에 등록하세요.
            </p>
            @php
                // 공급자별 비전(이미지) 모델. 모델 접두사로 서버가 공급자를 자동 판별한다.
                // 라벨의 요금 = (입력/출력 $/1M) · 1000건 예상(영수증 이미지 1장+짧은 출력 기준, 크기에 따라 ±).
                // Gemini 는 -latest 별칭 권장(구모델 종료돼도 자동 승계). 키는 위 'AI 모델 API 키'에 등록.
                $quizModelGroups = [
                    'Gemini (Google)' => [
                        'gemini-flash-lite-latest' => 'gemini-flash-lite-latest — 최저가·추론 최소·높은 RPM (대량 권장)',
                        'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash-Lite — $0.25/$1.5 · 최저가',
                        'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash-Lite — 저가·높은 RPM',
                        'gemini-flash-latest' => 'gemini-flash-latest — 최신 Flash·자동승계',
                        'gemini-3.5-flash' => 'Gemini 3.5 Flash — $1.5/$9 · 추론 많음(비용↑ 주의)',
                        'gemini-pro-latest' => 'gemini-pro-latest — 최신 Pro·정확도↑·비용↑',
                        'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro — $2/$12 · 1000건 ~$9.00',
                    ],
                    'OpenAI (GPT)' => [
                        'gpt-5.6' => 'GPT-5.6 Terra — $2.5/$15 · 1000건 ~$11.25',
                        'gpt-5' => 'GPT-5',
                    ],
                    'Claude (Anthropic)' => [
                        'claude-sonnet-5' => 'Claude Sonnet 5 — $2/$10(인트로,~8/31) · 1000건 ~$8.00',
                        'claude-opus-4-8' => 'Claude Opus 4.8',
                    ],
                    'Grok (xAI)' => [
                        'grok-4.3' => 'Grok 4.3 — $1.25/$2.5 · 1000건 ~$3.13',
                        'grok-4' => 'Grok 4',
                    ],
                ];
                $quizModelKnown = collect($quizModelGroups)->flatMap(fn ($g) => array_keys($g))->all();
                $quizCurrent = $quizModel !== '' ? $quizModel : $quizModelLive;
            @endphp
            <div>
                {{-- 라벨은 전체 폭 한 줄 고정(설명이 select 폭 560px에 걸려 줄바꿈되지 않게), 입력칸만 폭 제한 --}}
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;display:block;margin-bottom:5px;white-space:nowrap;">분석 모델 <span class="text-muted-soft" style="font-weight:400;">Gemini·OpenAI·Claude·Grok · 요금=입력/출력 $1M · 1000건 예상 — 선택 공급자의 키 필요</span></label>
                <select name="quiz_model" class="input" style="width:100%;max-width:560px;font-size:var(--fs-xs);">
                    @if ($quizCurrent !== '' && ! in_array($quizCurrent, $quizModelKnown, true))
                        <option value="{{ $quizCurrent }}" selected>{{ $quizCurrent }} (사용자 지정)</option>
                    @endif
                    @foreach ($quizModelGroups as $grp => $models)
                        <optgroup label="{{ $grp }}">
                            @foreach ($models as $mo => $label)
                                <option value="{{ $mo }}" @selected($quizCurrent === $mo)>{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div style="margin-top:12px;">
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;display:block;margin-bottom:5px;white-space:nowrap;">풀이 대기 시간(초) <span class="text-muted-soft" style="font-weight:400;">이 시간 안에 정답이 안 오면 요청을 버리고 새로고침해 다른 캡차로 재시도 (기본 10, 3~60)</span></label>
                <input type="number" name="quiz_solve_timeout" value="{{ $quizSolveTimeout }}" min="3" max="60" step="1" class="input" style="width:120px;font-size:var(--fs-xs);">
            </div>
            {{-- 추론(thinking) — 끄면 flash/lite 계열은 thinkingBudget=0 으로 추론 토큰이 사라져 건당 비용 대폭 절감(기본 꺼짐) --}}
            <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:var(--fs-xs);color:var(--color-muted);cursor:pointer;">
                <input type="hidden" name="quiz_thinking" value="off">
                <input type="checkbox" name="quiz_thinking" value="on" @checked($quizThinking === 'on')>
                추론(thinking) 사용 <span class="text-muted-soft">정확도↑·비용↑ — 끄면 flash/lite 계열 건당 토큰이 대폭 줄어듭니다(기본 꺼짐 권장). Pro 계열은 항상 추론 사용</span>
            </label>
        </div>
    </div>

    {{-- ── 외부 연동: Cloudflare · 소셜 로그인 · 알리고 SMS ──────────────── --}}
    <div class="rf-tabpane" data-tab="integ" @if ($__active !== 'integ') hidden @endif>
        <p class="text-muted mb-4" style="font-size:var(--fs-xs);">
            봇 차단(Cloudflare)·소셜 로그인(구글·카카오)·알리고 SMS 키를 관리합니다. 값은 <b>암호화 저장</b>되며 <code>.env</code>보다 우선 적용됩니다.
            저장하면 해당 기능(순위조회 봇검증·소셜 로그인·문자 발송)에 <b>즉시 반영</b>됩니다. 비밀 키는 <i class="fa-regular fa-eye"></i>(보기)·<i class="fa-regular fa-copy"></i>(복사)할 수 있습니다.
        </p>

        {{-- Cloudflare Turnstile --}}
        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">Cloudflare Turnstile <span class="text-muted-soft" style="font-weight:400;">무료 순위조회 봇 차단</span></div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">dash.cloudflare.com → Turnstile 에서 위젯 생성 후 발급. 미설정 시 비회원 봇 검증을 건너뜁니다.</p>
            @include('admin.settings._simplefield', ['name' => 'turnstile_site_key', 'label' => '사이트 키 (공개)', 'value' => $turnstileSiteKey, 'secret' => false, 'placeholder' => '0x4AAAAAAA...'])
            @include('admin.settings._simplefield', ['name' => 'turnstile_secret', 'label' => '시크릿 키', 'value' => $turnstileSecret, 'secret' => true, 'placeholder' => '0x4AAAAAAA...'])
        </div>

        {{-- Google 로그인 --}}
        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">Google 로그인 <span class="text-muted-soft" style="font-weight:400;">소셜 로그인·가입</span></div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">console.cloud.google.com → OAuth 클라이언트. 승인된 리디렉션 URI: <code>{{ url('/auth/google/callback') }}</code></p>
            @include('admin.settings._simplefield', ['name' => 'google_client_id', 'label' => 'Client ID', 'value' => $googleClientId, 'secret' => false])
            @include('admin.settings._simplefield', ['name' => 'google_client_secret', 'label' => 'Client Secret', 'value' => $googleClientSecret, 'secret' => true])
        </div>

        {{-- Kakao 로그인 --}}
        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">Kakao 로그인 <span class="text-muted-soft" style="font-weight:400;">소셜 로그인·가입</span></div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">developers.kakao.com → 내 애플리케이션. Client ID = REST API 키. Redirect URI: <code>{{ url('/auth/kakao/callback') }}</code> (Secret 은 선택)</p>
            @include('admin.settings._simplefield', ['name' => 'kakao_client_id', 'label' => 'Client ID (REST API 키)', 'value' => $kakaoClientId, 'secret' => false])
            @include('admin.settings._simplefield', ['name' => 'kakao_client_secret', 'label' => 'Client Secret (선택)', 'value' => $kakaoClientSecret, 'secret' => true])
        </div>

        {{-- 알리고 SMS --}}
        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">알리고 SMS <span class="text-muted-soft" style="font-weight:400;">전화번호 인증 문자</span></div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">smartsms.aligo.in → 발신번호 사전 등록 후 API 키 발급. 발신번호는 등록된 번호만 사용 가능합니다.</p>
            @include('admin.settings._simplefield', ['name' => 'aligo_user_id', 'label' => '알리고 아이디', 'value' => $aligoUserId, 'secret' => false])
            @include('admin.settings._simplefield', ['name' => 'aligo_api_key', 'label' => 'API 키', 'value' => $aligoApiKey, 'secret' => true])
            @include('admin.settings._simplefield', ['name' => 'aligo_sender', 'label' => '발신번호', 'value' => $aligoSender, 'secret' => false, 'placeholder' => '1668-3721'])
        </div>

        {{-- 구글 데이터 연동 (서치 콘솔·GA4 공용) --}}
        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">구글 데이터 연동 <span class="text-muted-soft" style="font-weight:400;">서치 콘솔 · GA4 공용</span></div>
            @if ($googleConnected)
                <p class="mb-3" style="font-size:var(--fs-xs);">
                    <span style="color:var(--color-success);">✓ 연동됨</span>
                    <b class="text-ink">{{ $googleEmail ?: '(이메일 미확인)' }}</b>
                    <span class="text-muted-soft">— 이 계정의 서치 콘솔·GA4 접근 권한으로 데이터를 수집합니다.</span>
                </p>
                {{-- 중첩 form 금지 — 메인 저장 폼(#rf-settings-form) 안에 form 을 두면 </form> 이 메인 폼을 조기 종료시킴.
                     실제 해제 form 은 메인 폼 밖(하단 #rf-gdisconnect)에 두고, 버튼만 form 속성으로 연결한다. --}}
                <button type="submit" form="rf-gdisconnect" class="btn btn-secondary btn-sm">연동 해제</button>
            @else
                <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">
                    워드프레스 Site Kit 처럼 <b class="text-muted">구글 계정 클릭 한 번으로 연동</b>합니다(위 소셜 로그인 클라이언트 재사용).
                    사전 준비(최초 1회): GCP 해당 프로젝트에서 <b class="text-muted">Search Console API · Google Analytics Data API</b> 활성화 +
                    OAuth 클라이언트의 승인된 리디렉션 URI에 <code>{{ route('admin.google-connect.callback') }}</code> 추가.
                    연동 계정은 서치 콘솔 속성·GA4 속성에 접근 권한이 있어야 합니다(소유 계정이면 됩니다).
                    @if ($gscServiceEmail)<br>대안: 서비스 계정({{ $gscServiceEmail }}) 키가 설정돼 있어 폴백으로 사용됩니다.@endif
                </p>
                <a href="{{ route('admin.google-connect') }}" class="btn btn-primary btn-sm">구글 계정으로 연동</a>
            @endif
        </div>

        {{-- 구글 서치 콘솔 --}}
        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">구글 서치 콘솔 <span class="text-muted-soft" style="font-weight:400;">검색 유입 분석</span></div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">
                위 구글 연동(또는 서비스 계정 폴백)으로 Search Analytics API를 조회합니다.
                수집: 매일 04:00 자동 · 수동 <code>php artisan gsc:collect --days=480</code>(최초 적재).
            </p>
            @include('admin.settings._simplefield', ['name' => 'gsc_property', 'label' => '속성 (도메인: sc-domain:rankfree.kr · URL 접두어: https://rankfree.kr/)', 'value' => $gscProperty, 'secret' => false, 'placeholder' => 'sc-domain:rankfree.kr'])
            <div class="mt-3">
                @include('admin.settings._simplefield', ['name' => 'google_site_verification', 'label' => 'HTML 소유확인 토큰 (Search Console → 소유권 확인 → HTML 태그의 content 값. 전체 <meta> 태그를 붙여넣어도 됩니다)', 'value' => $googleSiteVerification, 'secret' => false, 'placeholder' => 'abcd1234... 또는 <meta name="google-site-verification" content="...">'])
                <p class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">저장하면 모든 공개 페이지 <code>&lt;head&gt;</code>에 확인 메타가 출력됩니다 → GSC에서 "HTML 태그" 방식으로 소유확인 가능.</p>
            </div>
        </div>

        {{-- 구글 애널리틱스(GA4) --}}
        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">구글 애널리틱스 (GA4) <span class="text-muted-soft" style="font-weight:400;">방문 분석</span></div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">
                위 구글 연동(또는 서비스 계정 폴백)으로 Analytics Data API를 조회합니다.
                속성 ID는 GA4 관리 → 속성 설정에 있는 <b>숫자 ID</b>입니다. 수집: 매일 04:10 자동 · 수동 <code>php artisan ga:collect --days=400</code>.
            </p>
            @include('admin.settings._simplefield', ['name' => 'ga_property_id', 'label' => 'GA4 속성 ID (숫자)', 'value' => $gaPropertyId, 'secret' => false, 'placeholder' => '123456789'])
        </div>

        {{-- 서울 열린데이터광장 — 신규 개업(인허가) 수집(24) --}}
        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">
                서울 열린데이터광장 <span class="text-muted-soft" style="font-weight:400;">신규 개업(인허가) 수집</span>
                @if ($seoulKeyLive === 'sample' || $seoulKeyLive === '')
                    <span class="badge" style="font-size:var(--fs-xs);margin-left:6px;background:color-mix(in srgb,var(--color-warning) 14%,var(--color-canvas));color:var(--color-warning);">sample · 일자당 5건 제한</span>
                @else
                    <span class="badge" style="font-size:var(--fs-xs);margin-left:6px;background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);">적용중</span>
                @endif
            </div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);line-height:1.6;">
                <a href="https://data.seoul.go.kr/together/mypage/actkeyMain.do" target="_blank" rel="noopener" class="text-ink">data.seoul.go.kr → 마이페이지 → 인증키 신청</a>
                에서 <b>일반 인증키</b>를 발급받아 넣으세요(로그인 필요·즉시 발급). 비우면 sample 키로 동작해 <b>일자당 5건</b>만 수집됩니다.
                수집: <code>php artisan newbiz:collect</code> · 확인: <a href="{{ route('admin.new-businesses') }}" class="text-ink">신규 개업</a>
            </p>
            @include('admin.settings._simplefield', ['name' => 'seoul_openapi_key', 'label' => '일반 인증키', 'value' => $seoulOpenapiKey, 'secret' => true, 'placeholder' => '발급받은 32자 인증키'])
        </div>
    </div>

    {{-- ── 회원: 추천인 보상 ──────────────────────────────────────────── --}}
    <div class="rf-tabpane" data-tab="member" @if ($__active !== 'member') hidden @endif>
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">추천인 보상 — 순위체크 슬롯</div>
        <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">
            회원의 <b class="text-muted">마이페이지 추천 링크</b>로 가입이 완료되면 추천인의 순위 추적 가능 개수(플레이스+쇼핑 합산 한도)가 자동으로 늘어납니다.
            가입 화면에는 추천인 입력칸이 없고, 링크로 진입하면 백엔드에서 자동 처리됩니다.
        </p>
        <div class="flex gap-4 flex-wrap">
            <div style="width:220px;">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);font-weight:600;">추천 1회당 증가 개수</label>
                <input type="number" name="referral_bonus_per" min="0" value="{{ old('referral_bonus_per', $referralPer) }}" class="input text-right" style="width:100%;">
            </div>
            <div style="width:220px;">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);font-weight:600;">추천으로 늘릴 수 있는 최대 개수</label>
                <input type="number" name="referral_bonus_max" min="0" value="{{ old('referral_bonus_max', $referralMax) }}" class="input text-right" style="width:100%;">
            </div>
        </div>
        <p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">
            예) 1회당 20 · 최대 200 → 추천 가입 10명까지 슬롯 +200개. 이미 최대치에 도달한 추천인은 추천 관계만 기록되고 더 늘지 않습니다.
        </p>
    </div>

    {{-- ── 결제: 무통장 입금 계좌 ──────────────────────────────────────── --}}
    <div class="rf-tabpane" data-tab="payment" @if ($__active !== 'payment') hidden @endif>
        <p class="text-muted mb-4" style="font-size:var(--fs-xs);">
            셀프마케팅 <b>주문 완료 화면</b>에 노출되는 무통장 입금 계좌입니다. 은행을 고르고 계좌번호·예금주를 입력하세요.
            값은 <b>암호화 저장</b>되며, 은행과 계좌번호가 모두 채워져 있어야 주문 후 안내가 표시됩니다.
        </p>
        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">무통장 입금 계좌 <span class="text-muted-soft" style="font-weight:400;">주문 결제 안내</span></div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">주문이 접수되면 고객에게 이 계좌와 결제 금액이 안내됩니다.</p>
            <div class="flex gap-4 flex-wrap">
                <div style="width:220px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);font-weight:600;">은행</label>
                    @php $__bank = old('bank_name', $bankName); @endphp
                    <select name="bank_name" class="input" style="width:100%;font-size:var(--fs-xs);">
                        <option value="">— 은행 선택 —</option>
                        @foreach ($bankList as $__b)
                            <option value="{{ $__b }}" @selected($__bank === $__b)>{{ $__b }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="width:260px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);font-weight:600;">계좌번호</label>
                    <input type="text" name="bank_account" value="{{ old('bank_account', $bankAccount) }}" placeholder="숫자·하이픈(-)만" autocomplete="off" class="input" style="width:100%;font-family:var(--font-mono);font-size:var(--fs-xs);">
                </div>
                <div style="width:180px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);font-weight:600;">예금주</label>
                    <input type="text" name="bank_holder" value="{{ old('bank_holder', $bankHolder) }}" placeholder="예금주명" autocomplete="off" class="input" style="width:100%;font-size:var(--fs-xs);">
                </div>
            </div>
        </div>
    </div>

    {{-- ── 커스텀 코드: 모든 페이지 <head> 주입 ─────────────────────────── --}}
    {{-- ── 플레이스 패턴: 업종별 키워드 패턴 넣고 빼기 ───────────────────── --}}
    <div class="rf-tabpane" data-tab="place" @if ($__active !== 'place') hidden @endif>
        <p class="text-muted mb-4" style="font-size:var(--fs-xs);">
            플레이스 키워드는 <b>{지역} + {패턴}</b> 조합으로 만들어집니다(예: 강남역 + <b>곱창</b> → "강남역 곱창").
            여기서 업종별 패턴을 <b>넣고 뺄 수</b> 있고, 저장 후 <code>hub:place-seed</code> 를 실행하면 <b>새로 추가된 조합만</b> 생성됩니다(기존은 중복 제외).
            패턴 1개를 추가하면 그 업종의 지역 수만큼 키워드가 늘어납니다.
        </p>

        @php $__totalP = collect($placePatterns)->sum(fn ($c) => count($c['patterns'])); @endphp
        <div class="card-soft px-4 py-3 mb-4 flex items-center gap-3 flex-wrap" style="font-size:var(--fs-xs);">
            <span class="text-muted">현재 총 패턴 <b class="font-mono text-ink">{{ number_format($__totalP) }}</b>개</span>
            @foreach ($placePatterns as $__k => $__c)
                <span class="badge border border-hairline">{{ $__c['name'] }} <b class="font-mono">{{ count($__c['patterns']) }}</b></span>
            @endforeach
        </div>

        <div class="flex flex-col gap-4">
            @foreach ($placePatterns as $__key => $__cat)
                <div class="card p-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $__cat['name'] }}</label>
                        <span class="text-muted-soft font-mono" style="font-size:var(--fs-xs);">{{ count($__cat['patterns']) }}개</span>
                    </div>
                    <textarea name="place_patterns[{{ $__key }}]" class="input" rows="4"
                              style="font-size:var(--fs-xs);line-height:1.7;height:auto;"
                              placeholder="콤마 또는 줄바꿈으로 구분 — 예: 맛집, 곱창, 감자탕">{{ implode(', ', $__cat['patterns']) }}</textarea>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ── 2차 도메인: Short URL 호스트 풀 ─────────────────────────────── --}}
    <div class="rf-tabpane" data-tab="domains" @if ($__active !== 'domains') hidden @endif>
        @if ($errors->has('cloudflare'))
            <div class="card-soft px-4 py-3 mb-4" style="font-size:var(--fs-xs);color:var(--color-error);">
                {{ $errors->first('cloudflare') }}
            </div>
        @endif

        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">Cloudflare DNS API</div>
            <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);line-height:1.6;">
                Cloudflare에 연결된 상위 도메인을 등록하고, 선택한 상위 도메인 아래에 2차 도메인을 자동 생성합니다.
                DNS 대상이 IP면 A/AAAA, 호스트명이면 CNAME 레코드로 생성됩니다.
            </p>

            @include('admin.settings._simplefield', ['name' => 'cloudflare_api_token', 'label' => 'Cloudflare API 토큰', 'value' => $cloudflareApiToken, 'secret' => true, 'placeholder' => 'Zone DNS Edit 토큰'])
            @include('admin.settings._simplefield', ['name' => 'cloudflare_dns_target', 'label' => 'DNS 대상 호스트/IP', 'value' => $cloudflareDnsTarget, 'secret' => false, 'placeholder' => 'rankfree.kr 또는 서버 IP'])

            <div class="text-ink font-semibold mt-4 mb-2" style="font-size:var(--fs-xs);">Cloudflare 연결 도메인</div>
            <div class="rf-zone-wrap">
                @forelse ($cloudflareZones as $zone)
                    <div class="flex items-center gap-2 mb-2 rf-zone-row flex-wrap">
                        <input type="text" name="cloudflare_zone_domain[]" value="{{ $zone['domain'] }}" class="input" autocomplete="off" placeholder="rankfree.kr" style="max-width:260px;font-family:var(--font-mono);font-size:var(--fs-xs);">
                        <input type="text" name="cloudflare_zone_id[]" value="{{ $zone['zone_id'] ?? '' }}" class="input" autocomplete="off" placeholder="Zone ID (비워두면 자동 조회)" style="max-width:360px;font-family:var(--font-mono);font-size:var(--fs-xs);">
                        <button type="button" class="btn btn-ghost btn-sm rf-zone-del flex-none" title="삭제" aria-label="삭제" style="color:var(--color-error);"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                @empty
                    <div class="flex items-center gap-2 mb-2 rf-zone-row flex-wrap">
                        <input type="text" name="cloudflare_zone_domain[]" value="" class="input" autocomplete="off" placeholder="rankfree.kr" style="max-width:260px;font-family:var(--font-mono);font-size:var(--fs-xs);">
                        <input type="text" name="cloudflare_zone_id[]" value="" class="input" autocomplete="off" placeholder="Zone ID (비워두면 자동 조회)" style="max-width:360px;font-family:var(--font-mono);font-size:var(--fs-xs);">
                        <button type="button" class="btn btn-ghost btn-sm rf-zone-del flex-none" title="삭제" aria-label="삭제" style="color:var(--color-error);"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                @endforelse
            </div>

            <template id="rf-zone-tpl">
                <div class="flex items-center gap-2 mb-2 rf-zone-row flex-wrap">
                    <input type="text" name="cloudflare_zone_domain[]" value="" class="input" autocomplete="off" placeholder="rankfree.kr" style="max-width:260px;font-family:var(--font-mono);font-size:var(--fs-xs);">
                    <input type="text" name="cloudflare_zone_id[]" value="" class="input" autocomplete="off" placeholder="Zone ID (비워두면 자동 조회)" style="max-width:360px;font-family:var(--font-mono);font-size:var(--fs-xs);">
                    <button type="button" class="btn btn-ghost btn-sm rf-zone-del flex-none" title="삭제" aria-label="삭제" style="color:var(--color-error);"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </template>

            <button type="button" class="btn btn-secondary btn-sm rf-zone-add mt-1">＋ 연결 도메인 추가</button>

            <div class="card-soft px-4 py-3 mt-5">
                <div class="text-ink font-semibold mb-2" style="font-size:var(--fs-xs);">2차 도메인 생성</div>
                <div class="flex items-center gap-2 flex-wrap">
                    <input type="text" id="rf-cf-subdomain" class="input" autocomplete="off" placeholder="self 또는 비워두면 랜덤" style="max-width:240px;font-family:var(--font-mono);font-size:var(--fs-xs);">
                    <span class="text-muted-soft">.</span>
                    <select id="rf-cf-zone" class="input" style="max-width:260px;font-family:var(--font-mono);font-size:var(--fs-xs);">
                        @forelse ($cloudflareZones as $zone)
                            <option value="{{ $zone['domain'] }}">{{ $zone['domain'] }}</option>
                        @empty
                            <option value="">연결 도메인 저장 필요</option>
                        @endforelse
                    </select>
                    <input type="number" id="rf-cf-count" class="input" value="1" min="1" max="50" step="1" style="width:96px;font-family:var(--font-mono);font-size:var(--fs-xs);" aria-label="생성 수량">
                    <button type="button" id="rf-cf-create-btn" class="btn btn-primary btn-sm">생성하기</button>
                </div>
                <p class="text-muted-soft mt-2 mb-0" style="font-size:var(--fs-xs);line-height:1.6;">
                    예: <code>self</code> + <code>rankfree.kr</code> = <code>self.rankfree.kr</code>. 생성된 도메인은 Short URL 도메인 목록에도 자동 추가됩니다.
                </p>
            </div>
        </div>

        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">2차 도메인</div>
            <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">
                쇼핑 노출 키워드 Short URL 생성 시 사용할 도메인입니다. 여러 개를 등록하면 Short URL이 도메인별로 순서대로 배정됩니다.
                프로토콜이나 경로를 붙여 넣어도 저장 시 도메인만 정리합니다.
            </p>

            <div class="rf-domain-wrap">
                @forelse ($secondaryDomains as $domain)
                    <div class="flex items-center gap-2 mb-2 rf-domain-row">
                        <input type="text" name="secondary_domains[]" value="{{ $domain }}" class="input" autocomplete="off" placeholder="go.example.com" style="max-width:520px;font-family:var(--font-mono);font-size:var(--fs-xs);">
                        <button type="button" class="btn btn-ghost btn-sm rf-domain-del flex-none" title="삭제" aria-label="삭제" style="color:var(--color-error);"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                @empty
                    <div class="flex items-center gap-2 mb-2 rf-domain-row">
                        <input type="text" name="secondary_domains[]" value="" class="input" autocomplete="off" placeholder="go.example.com" style="max-width:520px;font-family:var(--font-mono);font-size:var(--fs-xs);">
                        <button type="button" class="btn btn-ghost btn-sm rf-domain-del flex-none" title="삭제" aria-label="삭제" style="color:var(--color-error);"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                @endforelse
            </div>

            <template id="rf-domain-tpl">
                <div class="flex items-center gap-2 mb-2 rf-domain-row">
                    <input type="text" name="secondary_domains[]" value="" class="input" autocomplete="off" placeholder="go.example.com" style="max-width:520px;font-family:var(--font-mono);font-size:var(--fs-xs);">
                    <button type="button" class="btn btn-ghost btn-sm rf-domain-del flex-none" title="삭제" aria-label="삭제" style="color:var(--color-error);"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </template>

            <button type="button" class="btn btn-secondary btn-sm rf-domain-add mt-1">＋ 도메인 추가</button>
        </div>
    </div>

    <div class="rf-tabpane" data-tab="custom" @if ($__active !== 'custom') hidden @endif>
        <p class="text-muted mb-4" style="font-size:var(--fs-xs);">
            아래 코드가 모든 공개 페이지(홈·콘솔)의 <code>&lt;head&gt;</code>에 삽입됩니다. 웹폰트·분석 스크립트(GA·GTM·메타 픽셀)·커스텀 CSS 등에 사용하세요.
            <b class="text-error">주의</b>: 잘못된 코드는 화면을 깨뜨릴 수 있습니다. 스크립트/외부 링크는 신뢰할 수 있는 것만 넣으세요(어드민 화면에는 적용되지 않습니다).
        </p>

        <div class="mb-5">
            <label class="text-ink font-semibold" style="font-size:var(--fs-sm);display:block;margin-bottom:6px;">커스텀 CSS</label>
            <div class="text-muted-soft mb-2" style="font-size:var(--fs-xs);"><code>&lt;style&gt;</code>로 자동 감싸 삽입됩니다. CSS만 입력하세요.</div>
            <textarea name="custom_head_css" spellcheck="false" placeholder=".btn-primary { border-radius: 8px; }" class="input" style="width:100%;height:300px;font-family:var(--font-mono);font-size:var(--fs-xs);line-height:1.6;resize:vertical;white-space:pre;">{{ old('custom_head_css', $customCss) }}</textarea>
        </div>

        <div class="mb-2">
            <label class="text-ink font-semibold" style="font-size:var(--fs-sm);display:block;margin-bottom:6px;">커스텀 스크립트 · head HTML</label>
            <div class="text-muted-soft mb-2" style="font-size:var(--fs-xs);"><b>원문 그대로</b> 삽입됩니다. <code>&lt;script&gt;</code>·<code>&lt;meta&gt;</code>·<code>&lt;link&gt;</code> 태그를 직접 포함하세요.</div>
            <textarea name="custom_head_html" spellcheck="false" placeholder="&lt;meta name=&quot;...&quot; content=&quot;...&quot;&gt;&#10;&lt;script async src=&quot;https://www.googletagmanager.com/gtag/js?id=G-XXXX&quot;&gt;&lt;/script&gt;" class="input" style="width:100%;height:300px;font-family:var(--font-mono);font-size:var(--fs-xs);line-height:1.6;resize:vertical;white-space:pre;">{{ old('custom_head_html', $customHtml) }}</textarea>
        </div>
    </div>

    <div class="flex items-center gap-2">
        <button type="submit" class="btn btn-primary">저장</button>
        <a href="{{ route('admin.home') }}" class="btn btn-secondary">취소</a>
    </div>
</form>

{{-- 구글 연동 해제 — 메인 설정 폼과 중첩되면 안 되므로 폼 밖에 둔다(위 '연동 해제' 버튼이 form 속성으로 참조). --}}
@if ($googleConnected)
<form id="rf-gdisconnect" method="POST" action="{{ route('admin.google-connect.disconnect') }}" hidden
      data-confirm="구글 연동을 해제할까요?" data-confirm-text="검색 유입·방문 분석 수집이 중단됩니다." data-confirm-ok="해제">
    @csrf
</form>
@endif

<form id="rf-cf-create" method="POST" action="{{ route('admin.settings.secondary-domain.create') }}" hidden>
    @csrf
    <input type="hidden" name="zone_domain" id="rf-cf-create-zone">
    <input type="hidden" name="subdomain" id="rf-cf-create-subdomain">
    <input type="hidden" name="dns_target" id="rf-cf-create-target">
    <input type="hidden" name="count" id="rf-cf-create-count">
</form>

<script>
(function () {
    // ── 탭 전환 (숨긴 탭 입력도 폼 전송됨) ──
    var tabs = document.querySelectorAll('.rf-tab');
    var panes = document.querySelectorAll('.rf-tabpane');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (x) { x.classList.toggle('on', x === t); });
            panes.forEach(function (p) { p.hidden = (p.dataset.tab !== t.dataset.tab); });
            document.getElementById('rf-active-tab').value = t.dataset.tab;   // 저장 후 탭 유지
        });
    });

    // ── 줄 추가 — 그룹 템플릿 복제 후 append ──
    document.querySelectorAll('.rf-cred-add').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var g = btn.dataset.group;
            var tpl = document.querySelector('.rf-cred-tpl[data-group="' + g + '"]');
            var wrap = document.querySelector('.rf-cred-wrap[data-group="' + g + '"]');
            if (!tpl || !wrap) return;
            var row = tpl.content.firstElementChild.cloneNode(true);
            wrap.appendChild(row);
            var first = row.querySelector('input, select'); if (first) first.focus();
        });
    });

    var domainAdd = document.querySelector('.rf-domain-add');
    if (domainAdd) {
        domainAdd.addEventListener('click', function () {
            var tpl = document.getElementById('rf-domain-tpl');
            var wrap = document.querySelector('.rf-domain-wrap');
            if (!tpl || !wrap) return;
            var row = tpl.content.firstElementChild.cloneNode(true);
            wrap.appendChild(row);
            var input = row.querySelector('input');
            if (input) input.focus();
        });
    }

    // ── 위임: 삭제(즉시) · 보기/가리기 · 복사 ──
    var zoneAdd = document.querySelector('.rf-zone-add');
    if (zoneAdd) {
        zoneAdd.addEventListener('click', function () {
            var tpl = document.getElementById('rf-zone-tpl');
            var wrap = document.querySelector('.rf-zone-wrap');
            if (!tpl || !wrap) return;
            var row = tpl.content.firstElementChild.cloneNode(true);
            wrap.appendChild(row);
            var input = row.querySelector('input');
            if (input) input.focus();
        });
    }

    var cfCreate = document.getElementById('rf-cf-create-btn');
    if (cfCreate) {
        cfCreate.addEventListener('click', function () {
            if (cfCreate.disabled) return;
            var form = document.getElementById('rf-cf-create');
            var zone = document.getElementById('rf-cf-zone');
            var subdomain = document.getElementById('rf-cf-subdomain');
            var target = document.querySelector('input[name="cloudflare_dns_target"]');
            var count = document.getElementById('rf-cf-count');
            if (!form || !zone || !zone.value) return;
            document.getElementById('rf-cf-create-zone').value = zone.value;
            document.getElementById('rf-cf-create-subdomain').value = subdomain ? subdomain.value : '';
            document.getElementById('rf-cf-create-target').value = target ? target.value : '';
            document.getElementById('rf-cf-create-count').value = count ? count.value : '1';
            cfCreate.dataset.originalText = cfCreate.innerHTML;
            cfCreate.disabled = true;
            cfCreate.setAttribute('aria-busy', 'true');
            cfCreate.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 생성 중...';
            form.submit();
        });
    }

    document.addEventListener('click', function (e) {
        // 즉시 삭제 — 클릭하면 라인이 바로 사라짐(저장 시 반영)
        var del = e.target.closest('.rf-cred-del');
        if (del) { var r = del.closest('.rf-cred-row'); if (r) r.remove(); return; }

        var domainDel = e.target.closest('.rf-domain-del');
        if (domainDel) { var dr = domainDel.closest('.rf-domain-row'); if (dr) dr.remove(); return; }

        var zoneDel = e.target.closest('.rf-zone-del');
        if (zoneDel) { var zr = zoneDel.closest('.rf-zone-row'); if (zr) zr.remove(); return; }

        // 보기/가리기 토글
        var show = e.target.closest('.rf-secret-show');
        if (show) {
            var inp = show.closest('.rf-secret').querySelector('.rf-secret-input');
            var ic = show.querySelector('i');
            if (inp.type === 'password') { inp.type = 'text'; if (ic) ic.className = 'fa-regular fa-eye-slash'; }
            else { inp.type = 'password'; if (ic) ic.className = 'fa-regular fa-eye'; }
            return;
        }

        // 복사
        var copy = e.target.closest('.rf-secret-copy');
        if (copy) {
            var target = copy.closest('.rf-secret').querySelector('.rf-secret-input');
            var val = target ? target.value : '';
            var mark = copy.querySelector('i');
            var restore = function () { if (mark) mark.className = 'fa-regular fa-copy'; };
            var ok = function () { if (mark) mark.className = 'fa-solid fa-check'; setTimeout(restore, 1200); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(val).then(ok, function () { fallbackCopy(target); ok(); });
            } else { fallbackCopy(target); ok(); }
            return;
        }
    });

    function fallbackCopy(input) {
        if (!input) return;
        var t = input.type; input.type = 'text';
        input.focus(); input.select();
        try { document.execCommand('copy'); } catch (x) {}
        input.type = t; input.blur();
    }
})();
</script>
@endsection
