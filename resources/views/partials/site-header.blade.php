{{-- 상단 네비 — NAVER Cloud 스타일 메가 메뉴 (정적 링크, 토큰 기반).
     구성: 분석 도구(플레이스·쇼핑·키워드·블로그) · 마케팅 · 셀프마케팅 · 커뮤니티 · 요금
     모바일(md 미만): 우측 햄버거 → 메뉴·콘솔 드로어 --}}
@php
    // 메뉴관리(사이트 SEO)에서 상태를 끈 라우트는 헤더·푸터 링크에서도 숨긴다(레이아웃 렌더용 — DB 문제 시 전체 노출 폴백).
    try {
        $__siteOff = \App\Models\Menu::where('area', 'site')->where('is_active', false)
            ->pluck('route')->map(fn ($r) => explode('?', trim((string) $r), 2)[0])->filter()->all();
    } catch (\Throwable $e) {
        $__siteOff = [];
    }
    $menuOn = fn (string $route) => ! in_array($route, $__siteOff, true);
@endphp
<header class="sticky top-0 z-40 bg-canvas/95 backdrop-blur border-b border-hairline-soft">
    <div class="container-page flex items-center justify-between" style="height:64px;">
        {{-- 로고 --}}
        <a href="/" class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-md bg-primary text-on-primary font-display" style="font-size:var(--fs-sm);">R</span>
            <span class="font-display text-ink" style="font-size:var(--fs-lg);">랭크프리</span>
        </a>

        {{-- 중앙 메뉴 (호버 시 헤더 전폭 메가 패널) --}}
        <nav class="hidden md:flex items-center gap-1 self-stretch" style="font-size:var(--fs-sm);font-weight:500;">
            {{-- 분석 도구 --}}
            <div class="nav-item">
                <a href="/#features" class="nav-link px-3 py-2 rounded-md text-muted hover:bg-surface-card transition inline-flex">
                    분석 도구<span style="font-size:var(--fs-xs);line-height:1;margin-left:2px;color:var(--color-accent);">●</span>
                </a>
                <div class="nav-mega">
                    <div class="container-page grid gap-x-10 py-9" style="grid-template-columns:260px 1fr 1fr 1fr;">
                        <div>
                            <div class="text-accent font-semibold" style="font-size:var(--fs-lg);">분석 도구</div>
                            <p class="mt-3 text-muted" style="font-size:var(--fs-sm);line-height:1.6;">
                                플레이스·쇼핑·키워드·블로그까지, 네이버 마케팅 데이터를 한곳에서. 대부분 무료로 시작합니다.
                            </p>
                            <a href="{{ route('register') }}" class="btn btn-accent btn-sm mt-5">무료로 시작</a>
                        </div>
                        {{-- 플레이스 --}}
                        <div>
                            <div class="text-muted-soft mb-1" style="font-size:var(--fs-sm);font-weight:700;">플레이스</div>
                            <a href="/#place" class="nav-mega-link">플레이스 순위 추적</a>
                            <a href="/#place" class="nav-mega-link">경쟁 분석 (SEO 점수)</a>
                            <a href="/#place" class="nav-mega-link">스마트플레이스 리포트</a>
                        </div>
                        {{-- 쇼핑 --}}
                        <div>
                            <div class="text-muted-soft mb-1" style="font-size:var(--fs-sm);font-weight:700;">쇼핑</div>
                            <a href="/#shopping" class="nav-mega-link">쇼핑 순위추적</a>
                            <a href="/#shopping" class="nav-mega-link">쇼핑 시장 분석 <span class="badge-update">확장</span></a>
                            <a href="/#shopping" class="nav-mega-link">셀러력 · 상품 SEO</a>
                            <a href="/#shopping" class="nav-mega-link">상품 리뷰 분석</a>
                        </div>
                        {{-- 키워드·블로그 --}}
                        <div>
                            <div class="text-muted-soft mb-1" style="font-size:var(--fs-sm);font-weight:700;">키워드 · 블로그</div>
                            <a href="/#keyword" class="nav-mega-link">키워드 분석 <span class="badge-update">New</span></a>
                            <a href="/#keyword" class="nav-mega-link">키워드 추천 · 대량 분석</a>
                            <a href="/#blog" class="nav-mega-link">블로그 지수 분석</a>
                            @if ($menuOn('developers'))<a href="/developers" class="nav-mega-link">순위 API</a>@endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- 마케팅 --}}
            <div class="nav-item">
                <a href="/#marketing" class="nav-link px-3 py-2 rounded-md text-muted hover:bg-surface-card transition inline-flex">
                    마케팅<span style="font-size:var(--fs-xs);line-height:1;margin-left:2px;color:var(--color-badge-emerald);">●</span>
                </a>
                <div class="nav-mega">
                    <div class="container-page grid gap-x-12 py-9" style="grid-template-columns:300px 1fr 1fr;">
                        <div>
                            <div class="text-accent font-semibold" style="font-size:var(--fs-lg);">마케팅 서비스</div>
                            <p class="mt-3 text-muted" style="font-size:var(--fs-sm);line-height:1.6;">
                                분석으로 찾은 약점을 실행으로. 데이터에 근거한 마케팅을 연결해드립니다.
                            </p>
                            <a href="/support" class="btn btn-accent btn-sm mt-5">상담 문의</a>
                        </div>
                        <div>
                            <a href="/#marketing" class="nav-mega-link">플레이스 최적화</a>
                            <a href="/#marketing" class="nav-mega-link">블로그·체험단 매칭</a>
                        </div>
                        <div>
                            <a href="/#marketing" class="nav-mega-link">광고 운영 대행</a>
                            <a href="/support" class="nav-mega-link">상담 문의</a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 단일 링크 --}}
            <a href="{{ route('self-marketing') }}" class="px-3 py-2 rounded-md text-muted hover:text-accent hover:bg-surface-card transition">셀프마케팅</a>
            @if ($menuOn('community'))
                <a href="/community?cat=free" class="px-3 py-2 rounded-md text-muted hover:text-accent hover:bg-surface-card transition">커뮤니티</a>
            @endif
            <a href="/#pricing" class="px-3 py-2 rounded-md text-muted hover:text-accent hover:bg-surface-card transition">요금</a>
        </nav>

        {{-- 우측 액션 — 로그인 상태 분기 · 모바일은 햄버거만 --}}
        <div class="flex items-center gap-2">
            @auth
                <a href="{{ route('console.dashboard') }}" class="btn btn-primary btn-sm hidden md:inline-flex">콘솔</a>
            @else
                <a href="{{ route('login', ['from' => request()->fullUrl()]) }}" class="btn btn-ghost btn-sm hidden md:inline-flex">로그인</a>
            @endauth
            <button type="button" id="rf-mnav-btn" class="md:hidden btn btn-ghost btn-sm" aria-label="메뉴 열기" aria-expanded="false" style="padding:0 10px;">
                <svg id="rf-mnav-ico-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                <svg id="rf-mnav-ico-close" class="hidden" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="5" y1="5" x2="19" y2="19"/><line x1="19" y1="5" x2="5" y2="19"/></svg>
            </button>
        </div>
    </div>

    {{-- 모바일 메뉴 패널 — 햄버거 클릭 시 헤더 아래로 펼침. 대분류는 아코디언(기본 펼침) + 세부 메뉴 --}}
    <style>
        #rf-mnav summary { list-style: none; cursor: pointer; }
        #rf-mnav summary::-webkit-details-marker { display: none; }
        #rf-mnav details[open] summary .chev { transform: rotate(180deg); }
        #rf-mnav .rf-mnav-sub { display: block; padding: 8px 8px 8px 20px; border-radius: 8px; color: var(--color-muted); font-weight: 400; }
        #rf-mnav .rf-mnav-sub:hover { color: var(--color-ink); background: var(--color-surface-card); }
        #rf-mnav .rf-mnav-cat { padding: 8px 8px 2px 20px; color: var(--color-muted-soft); font-size: var(--fs-xs); font-weight: 700; }
    </style>
    <div id="rf-mnav" class="hidden md:hidden border-t border-hairline-soft bg-canvas" style="max-height:calc(100vh - 64px);overflow-y:auto;">
        <nav class="container-page py-3 flex flex-col" style="font-size:var(--fs-sm);font-weight:500;">
            {{-- 분석 도구 — 세부 메뉴 포함 --}}
            <details open>
                <summary class="flex items-center justify-between px-2 py-2.5 rounded-md text-body hover:bg-surface-card transition">
                    분석 도구
                    <svg class="chev transition" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </summary>
                <div class="pb-2">
                    <div class="rf-mnav-cat">플레이스</div>
                    <a href="/#place" class="rf-mnav-sub">플레이스 순위 추적</a>
                    <a href="/#place" class="rf-mnav-sub">경쟁 분석 (SEO 점수)</a>
                    <a href="/#place" class="rf-mnav-sub">스마트플레이스 리포트</a>
                    <div class="rf-mnav-cat">쇼핑</div>
                    <a href="/#shopping" class="rf-mnav-sub">쇼핑 순위추적</a>
                    <a href="/#shopping" class="rf-mnav-sub">쇼핑 시장 분석</a>
                    <a href="/#shopping" class="rf-mnav-sub">셀러력 · 상품 SEO</a>
                    <a href="/#shopping" class="rf-mnav-sub">상품 리뷰 분석</a>
                    <div class="rf-mnav-cat">키워드 · 블로그</div>
                    <a href="/#keyword" class="rf-mnav-sub">키워드 분석</a>
                    <a href="/#keyword" class="rf-mnav-sub">키워드 추천 · 대량 분석</a>
                    <a href="/#blog" class="rf-mnav-sub">블로그 지수 분석</a>
                    @if ($menuOn('developers'))<a href="/developers" class="rf-mnav-sub">순위 API</a>@endif
                </div>
            </details>
            {{-- 마케팅 — 세부 메뉴 포함 --}}
            <details open>
                <summary class="flex items-center justify-between px-2 py-2.5 rounded-md text-body hover:bg-surface-card transition">
                    마케팅
                    <svg class="chev transition" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </summary>
                <div class="pb-2">
                    <a href="/#marketing" class="rf-mnav-sub">플레이스 최적화</a>
                    <a href="/#marketing" class="rf-mnav-sub">블로그·체험단 매칭</a>
                    <a href="/#marketing" class="rf-mnav-sub">광고 운영 대행</a>
                    <a href="/support" class="rf-mnav-sub">상담 문의</a>
                </div>
            </details>
            <a href="{{ route('self-marketing') }}" class="px-2 py-2.5 rounded-md text-body hover:bg-surface-card transition">셀프마케팅</a>
            @if ($menuOn('community'))
                <a href="/community?cat=free" class="px-2 py-2.5 rounded-md text-body hover:bg-surface-card transition">커뮤니티</a>
            @endif
            <a href="/#pricing" class="px-2 py-2.5 rounded-md text-body hover:bg-surface-card transition">요금</a>
            <div class="flex flex-col gap-2 pt-3 mt-2 border-t border-hairline-soft">
                @auth
                    <a href="{{ route('console.dashboard') }}" class="btn btn-primary btn-sm w-full">콘솔</a>
                @else
                    <a href="{{ route('login', ['from' => request()->fullUrl()]) }}" class="btn btn-secondary btn-sm w-full">로그인</a>
                    @if ($menuOn('register'))
                        <a href="{{ route('register') }}" class="btn btn-primary btn-sm w-full">무료로 시작</a>
                    @endif
                @endauth
            </div>
        </nav>
    </div>
</header>

<script>
(function () {
    // 모바일 헤더 메뉴 토글 — 바깥 클릭·ESC·링크 이동 시 닫힘
    var btn = document.getElementById('rf-mnav-btn'), panel = document.getElementById('rf-mnav');
    if (!btn || !panel) return;
    var icoOpen = document.getElementById('rf-mnav-ico-open'), icoClose = document.getElementById('rf-mnav-ico-close');
    function setOpen(open) {
        panel.classList.toggle('hidden', !open);
        icoOpen.classList.toggle('hidden', open);
        icoClose.classList.toggle('hidden', !open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    btn.addEventListener('click', function (e) { e.stopPropagation(); setOpen(panel.classList.contains('hidden')); });
    panel.addEventListener('click', function (e) { if (e.target.closest('a')) setOpen(false); });
    document.addEventListener('click', function (e) { if (!e.target.closest('#rf-mnav') && !e.target.closest('#rf-mnav-btn')) setOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
})();
</script>
