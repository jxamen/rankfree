<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('page-title', '관리자') · rankfree</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-surface-page font-sans antialiased text-body">
<div class="flex min-h-screen">
    <aside class="w-60 flex-none bg-canvas border-r border-hairline flex flex-col sticky top-0 h-screen">
        <a href="{{ route('admin.home') }}" class="flex items-center gap-2 px-5" style="height:64px;">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-md bg-primary text-on-primary font-display" style="font-size:15px;">R</span>
            <span class="font-display text-ink" style="font-size:18px;">rankfree</span>
            <span class="badge" style="font-size:10px;padding:2px 8px;">관리자</span>
        </a>

        <nav class="flex-1 px-3 py-2 flex flex-col gap-0.5 overflow-y-auto">
            @foreach (\App\Domain\Access\MenuService::sidebarTree(auth()->user(), 'admin') as $node)
                @if ($node->is_group)
                    <div class="px-3 pt-3 pb-1 text-muted-soft flex items-center gap-2" style="font-size:11px;font-weight:700;letter-spacing:.03em;">
                        <span style="color:var(--color-muted);"><x-icon :name="$node->icon" /></span>{{ $node->name }}
                    </div>
                    @foreach ($node->menuItems as $item)
                        @php $on = $item->route && request()->routeIs($item->route.'*'); @endphp
                        <a href="{{ $item->resolvedUrl() ?? '#' }}" class="flex items-center gap-2 px-3 rounded-md transition {{ $on ? 'bg-surface-card text-ink' : 'text-muted hover:bg-surface-soft hover:text-ink' }}" style="height:38px;font-size:14px;font-weight:{{ $on ? '600' : '500' }};">
                            <span class="text-muted-soft" style="width:6px;text-align:center;">·</span>{{ $item->name }}
                        </a>
                    @endforeach
                @else
                    @php $on = $node->route && request()->routeIs($node->route.'*'); @endphp
                    <a href="{{ $node->resolvedUrl() ?? '#' }}" class="flex items-center gap-2 px-3 rounded-md transition {{ $on ? 'bg-surface-card text-ink' : 'text-muted hover:bg-surface-soft hover:text-ink' }}" style="height:40px;font-size:14px;font-weight:{{ $on ? '600' : '500' }};">
                        <span style="width:18px;text-align:center;"><x-icon :name="$node->icon" /></span>{{ $node->name }}
                    </a>
                @endif
            @endforeach
        </nav>

        <div class="border-t border-hairline p-4">
            <a href="{{ route('console.dashboard') }}" class="btn btn-secondary btn-sm w-full mb-3">← 콘솔로</a>
            <div class="text-ink font-semibold truncate" style="font-size:13px;">{{ auth()->user()?->name }}</div>
            <div class="text-muted-soft truncate" style="font-size:12px;">{{ auth()->user()?->operatorRole?->name ?? (auth()->user()?->isSuperAdmin() ? '슈퍼관리자' : '운영자') }}</div>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm w-full">로그아웃</button>
            </form>
        </div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0">
        <header class="bg-canvas border-b border-hairline flex items-center justify-between px-8 sticky top-0 z-10" style="height:64px;">
            <h1 class="font-display text-ink" style="font-size:20px;">@yield('page-title', '관리자')</h1>
            <div>@yield('page-actions')</div>
        </header>
        <main class="p-8 flex-1">
            @if (session('status'))
                <div class="card-soft mb-6 px-4 py-3 text-ink" style="font-size:14px;">{{ session('status') }}</div>
            @endif
            @yield('admin-content')
        </main>
    </div>
</div>
</body>
</html>
