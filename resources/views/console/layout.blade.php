<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('page-title', '콘솔') · rankfree</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-surface-page font-sans antialiased text-body">
<div class="flex min-h-screen">
    {{-- 사이드바 (Cal.com: 흰 캔버스 + hairline, 모노크롬) --}}
    <aside class="w-60 flex-none bg-canvas border-r border-hairline flex flex-col sticky top-0 h-screen">
        <a href="/console" class="flex items-center gap-2 px-5" style="height:64px;">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-md bg-primary text-on-primary font-display" style="font-size:15px;">R</span>
            <span class="font-display text-ink" style="font-size:18px;">rankfree</span>
        </a>

        <nav class="flex-1 px-3 py-2 flex flex-col gap-0.5">
            @php
                $nav = [
                    ['console.dashboard', '대시보드', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                    ['console.rank', '순위 추적', 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'],
                    ['console.compete', '경쟁 분석', 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                    ['console.keyword', '키워드 분석', 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'],
                    ['console.settings', '설정', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z'],
                ];
            @endphp
            @foreach ($nav as [$route, $label, $icon])
                @php $on = request()->routeIs($route); @endphp
                <a href="{{ Route::has($route) ? route($route) : '#' }}"
                   class="flex items-center gap-3 px-3 rounded-md transition {{ $on ? 'bg-surface-card text-ink' : 'text-muted hover:bg-surface-soft hover:text-ink' }}"
                   style="height:40px;font-size:14px;font-weight:{{ $on ? '600' : '500' }};">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        {{-- 무료 슬롯 게이지 --}}
        <div class="px-4 py-3 mx-3 mb-2 rounded-lg bg-surface-soft">
            <div class="flex items-center justify-between mb-1.5" style="font-size:12px;">
                <span class="text-muted">무료 순위 추적</span>
                <span class="text-ink font-semibold">{{ $usedSlots ?? 0 }}/{{ $maxSlots ?? 100 }}</span>
            </div>
            <div class="h-1.5 rounded-full bg-surface-strong overflow-hidden">
                <div class="h-full bg-primary" style="width:{{ min(100, ($maxSlots ?? 100) ? (($usedSlots ?? 0) / ($maxSlots ?? 100) * 100) : 0) }}%;"></div>
            </div>
        </div>

        {{-- 유저 --}}
        <div class="border-t border-hairline p-4">
            <div class="text-ink font-semibold truncate" style="font-size:13px;">{{ auth()->user()?->name ?? '사용자' }}</div>
            <div class="text-muted-soft truncate" style="font-size:12px;">{{ auth()->user()?->email }}</div>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn btn-secondary btn-sm w-full">로그아웃</button>
            </form>
        </div>
    </aside>

    {{-- 메인 --}}
    <div class="flex-1 flex flex-col min-w-0">
        <header class="bg-canvas border-b border-hairline flex items-center justify-between px-8 sticky top-0 z-10" style="height:64px;">
            <h1 class="font-display text-ink" style="font-size:20px;">@yield('page-title', '대시보드')</h1>
            <div>@yield('page-actions')</div>
        </header>
        <main class="p-8 flex-1">
            @if (session('status'))
                <div class="card-soft mb-6 px-4 py-3 text-ink" style="font-size:14px;">{{ session('status') }}</div>
            @endif
            @yield('console-content')
        </main>
    </div>
</div>
</body>
</html>
