<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('page-title', '관리자') · rankfree</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* 공통 토글 스위치 (Cal.com 모노크롬) */
        .rf-switch { position:relative; display:inline-flex; width:36px; height:20px; flex:0 0 auto; vertical-align:middle; cursor:pointer; }
        .rf-switch input { position:absolute; inset:0; width:100%; height:100%; margin:0; opacity:0; cursor:pointer; z-index:1; }
        .rf-switch .rf-track { position:absolute; inset:0; background:var(--color-surface-strong); border-radius:9999px; transition:background .15s ease; }
        .rf-switch .rf-track::after { content:''; position:absolute; top:2px; left:2px; width:16px; height:16px; background:#fff; border-radius:50%; box-shadow:0 1px 2px rgba(17,17,17,.25); transition:transform .15s ease; }
        .rf-switch input:checked + .rf-track { background:var(--color-primary); }
        .rf-switch input:checked + .rf-track::after { transform:translateX(16px); }
        .rf-switch input:focus-visible + .rf-track { box-shadow:0 0 0 3px rgba(17,17,17,.12); }
    </style>
    @stack('head')
</head>
<body class="bg-surface-page font-sans antialiased text-body">
<div class="flex min-h-screen">
    <aside class="w-60 flex-none bg-canvas border-r border-hairline flex flex-col sticky top-0 h-screen">
        <a href="{{ route('admin.home') }}" class="flex items-center gap-2 px-5" style="height:64px;">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-md bg-primary text-on-primary font-display" style="font-size:var(--fs-sm);">R</span>
            <span class="font-display text-ink" style="font-size:var(--fs-md);">rankfree</span>
            <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">관리자</span>
        </a>

        <nav class="flex-1 px-3 py-2 flex flex-col gap-0.5 overflow-y-auto">
            @foreach (\App\Domain\Access\MenuService::sidebarTree(auth()->user(), 'admin') as $node)
                @if ($node->is_group)
                    <div class="flex items-center gap-2 px-3 pt-4 pb-1 text-ink">
                        <span style="font-size:var(--fs-md);width:20px;text-align:center;line-height:1;"><x-icon :name="$node->icon" /></span>
                        <span style="font-size:var(--fs-xs);font-weight:700;letter-spacing:-.01em;">{{ $node->name }}</span>
                    </div>
                    @foreach ($node->menuItems as $item)
                        @php $on = $item->route && request()->routeIs($item->route.'*'); @endphp
                        <a href="{{ $item->resolvedUrl() ?? '#' }}" class="flex items-center gap-2 px-3 rounded-md transition {{ $on ? 'bg-surface-card text-ink' : 'text-muted hover:bg-surface-soft hover:text-ink' }}" style="height:38px;font-size:var(--fs-xs);font-weight:{{ $on ? '600' : '500' }};">
                            <span style="width:20px;text-align:center;font-size:var(--fs-xs);color:var(--color-muted-soft);">@if (trim((string) $item->icon) !== '')<x-icon :name="$item->icon" />@else·@endif</span>{{ $item->name }}
                        </a>
                    @endforeach
                @else
                    @php $on = $node->route && request()->routeIs($node->route.'*'); @endphp
                    <a href="{{ $node->resolvedUrl() ?? '#' }}" class="flex items-center gap-2 px-3 rounded-md transition {{ $on ? 'bg-surface-card text-ink' : 'text-muted hover:bg-surface-soft hover:text-ink' }}" style="height:40px;font-size:var(--fs-xs);font-weight:{{ $on ? '600' : '500' }};">
                        <span style="width:20px;text-align:center;font-size:var(--fs-sm);">@if (trim((string) $node->icon) !== '')<x-icon :name="$node->icon" />@endif</span>{{ $node->name }}
                    </a>
                @endif
            @endforeach
        </nav>

        <div class="border-t border-hairline p-4">
            <a href="{{ route('console.dashboard') }}" class="btn btn-secondary btn-sm w-full mb-3">← 콘솔로</a>
            <div class="text-ink font-semibold truncate" style="font-size:var(--fs-xs);">{{ auth()->user()?->name }}</div>
            <div class="text-muted-soft truncate" style="font-size:var(--fs-xs);">{{ auth()->user()?->operatorRole?->name ?? (auth()->user()?->isSuperAdmin() ? '슈퍼관리자' : '운영자') }}</div>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm w-full">로그아웃</button>
            </form>
        </div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0">
        <header class="bg-canvas border-b border-hairline flex items-center justify-between px-8 sticky top-0 z-10" style="height:64px;">
            <h1 class="font-display text-ink" style="font-size:var(--fs-lg);">@yield('page-title', '관리자')</h1>
            <div>@yield('page-actions')</div>
        </header>
        <main class="p-8 flex-1">
            <div style="max-width:1440px;margin:0 auto;">
                @if (session('status'))
                    <div class="card-soft mb-6 px-4 py-3 text-ink" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
                @endif
                @yield('admin-content')
            </div>
        </main>
    </div>
</div>
@stack('scripts')
</body>
</html>
