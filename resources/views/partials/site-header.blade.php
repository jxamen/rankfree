{{-- 상단 네비 — Cal.com top-nav (흰 캔버스, 64px, 모노크롬) --}}
<header class="sticky top-0 z-40 bg-canvas/95 backdrop-blur border-b border-hairline-soft">
    <div class="container-page flex items-center justify-between" style="height:64px;">
        {{-- 로고 --}}
        <a href="/" class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-md bg-primary text-on-primary font-display" style="font-size:15px;">R</span>
            <span class="font-display text-ink" style="font-size:19px;">rankfree</span>
        </a>

        {{-- 중앙 메뉴 --}}
        <nav class="hidden md:flex items-center gap-1" style="font-size:14px;font-weight:500;">
            <a href="/#features" class="px-3 py-2 rounded-md text-muted hover:text-ink hover:bg-surface-card transition">기능</a>
            <a href="/#place" class="px-3 py-2 rounded-md text-muted hover:text-ink hover:bg-surface-card transition">플레이스 분석</a>
            <a href="/#pricing" class="px-3 py-2 rounded-md text-muted hover:text-ink hover:bg-surface-card transition">요금</a>
            <a href="/#marketing" class="px-3 py-2 rounded-md text-muted hover:text-ink hover:bg-surface-card transition">마케팅</a>
        </nav>

        {{-- 우측 액션 — 로그인 상태 분기 --}}
        <div class="flex items-center gap-2">
            @auth
                <a href="{{ route('console.dashboard') }}" class="btn btn-ghost btn-sm">콘솔</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">로그아웃</button>
                </form>
            @else
                <a href="{{ route('login', ['from' => request()->fullUrl()]) }}" class="hidden sm:inline-flex btn btn-ghost btn-sm">로그인</a>
                <a href="{{ route('register') }}" class="btn btn-primary btn-sm">무료로 시작</a>
            @endauth
        </div>
    </div>
</header>
