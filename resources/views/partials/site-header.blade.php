{{-- 상단 네비 — NAVER Cloud 스타일 메가 메뉴 (정적 링크, 토큰 기반).
     구성: 분석 도구(플레이스·쇼핑·키워드·블로그) · 마케팅 · 셀프마케팅 · 커뮤니티 · 요금 --}}
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
                            <a href="/developers" class="nav-mega-link">순위 API</a>
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
            <a href="/community?cat=free" class="px-3 py-2 rounded-md text-muted hover:text-accent hover:bg-surface-card transition">커뮤니티</a>
            <a href="/#pricing" class="px-3 py-2 rounded-md text-muted hover:text-accent hover:bg-surface-card transition">요금</a>
        </nav>

        {{-- 우측 액션 — 로그인 상태 분기 --}}
        <div class="flex items-center gap-2">
            @auth
                <a href="{{ route('console.dashboard') }}" class="btn btn-primary btn-sm">콘솔</a>
            @else
                <a href="{{ route('login', ['from' => request()->fullUrl()]) }}" class="btn btn-ghost btn-sm">로그인</a>
            @endauth
        </div>
    </div>
</header>
