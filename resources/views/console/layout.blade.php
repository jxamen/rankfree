<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('page-title', '콘솔') · rankfree</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-surface-page font-sans antialiased text-body">
<div class="flex min-h-screen">
    {{-- 사이드바 (Cal.com: 흰 캔버스 + hairline) --}}
    <aside class="w-60 flex-none bg-canvas border-r border-hairline flex flex-col sticky top-0 h-screen">
        <a href="/console" class="flex items-center gap-2 px-5" style="height:64px;">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-md bg-primary text-on-primary font-display" style="font-size:15px;">R</span>
            <span class="font-display text-ink" style="font-size:18px;">rankfree</span>
        </a>

        {{-- DB 메뉴 트리 (메뉴관리에서 구성) --}}
        <nav class="flex-1 px-3 py-2 flex flex-col gap-0.5 overflow-y-auto">
            @foreach (\App\Domain\Access\MenuService::sidebarTree(auth()->user(), 'console') as $node)
                @if ($node->is_group)
                    <div class="px-3 pt-3 pb-1 text-muted-soft flex items-center gap-2" style="font-size:11px;font-weight:700;letter-spacing:.03em;">
                        <span style="color:var(--color-muted);"><x-icon :name="$node->icon" /></span>{{ $node->name }}
                    </div>
                    @foreach ($node->menuItems as $item)
                        @php $on = $item->route && request()->routeIs($item->route); @endphp
                        <a href="{{ $item->resolvedUrl() ?? '#' }}" @if ($item->target === '_blank') target="_blank" @endif
                           class="flex items-center gap-2 px-3 rounded-md transition {{ $on ? 'bg-surface-card text-ink' : 'text-muted hover:bg-surface-soft hover:text-ink' }}"
                           style="height:38px;font-size:14px;font-weight:{{ $on ? '600' : '500' }};">
                            <span class="text-muted-soft" style="width:6px;text-align:center;">·</span>{{ $item->name }}
                        </a>
                    @endforeach
                @else
                    @php $on = $node->route && request()->routeIs($node->route); @endphp
                    <a href="{{ $node->resolvedUrl() ?? '#' }}" @if ($node->target === '_blank') target="_blank" @endif
                       class="flex items-center gap-2 px-3 rounded-md transition {{ $on ? 'bg-surface-card text-ink' : 'text-muted hover:bg-surface-soft hover:text-ink' }}"
                       style="height:40px;font-size:14px;font-weight:{{ $on ? '600' : '500' }};">
                        <span style="width:18px;text-align:center;"><x-icon :name="$node->icon" /></span>{{ $node->name }}
                    </a>
                @endif
            @endforeach
        </nav>

        {{-- 관리자 진입점 (운영자 전용) --}}
        @if (auth()->user()?->isOperator())
        <div class="px-3 pb-1">
            <a href="{{ route('admin.home') }}" class="flex items-center gap-3 px-3 rounded-md text-muted hover:bg-surface-soft hover:text-ink transition" style="height:40px;font-size:14px;font-weight:600;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                관리자
            </a>
        </div>
        @endif

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
