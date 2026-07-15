@php
    // 로그인/가입 SEO — 페이지 @section('title') 우선, 없으면 메뉴('사이트 SEO')의 타이틀 사용.
    $__seo = \App\Models\Menu::seo(\Illuminate\Support\Facades\Route::currentRouteName());
    $__t = html_entity_decode(trim($__env->yieldContent('title')), ENT_QUOTES);
    $__title = $__t !== '' ? $__t.' · 랭크프리' : (($__seo['title'] ?? '') ?: '랭크프리');
    $__desc = (string) ($__seo['description'] ?? '') ?: '랭크프리 — 네이버 플레이스·쇼핑 순위 무료 분석. 로그인하고 순위 추적을 이어가세요.';
@endphp
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $__title }}</title>
    <meta name="description" content="{{ $__desc }}">
    @if ($__seo['keywords'])<meta name="keywords" content="{{ $__seo['keywords'] }}">@endif
    @include('partials.seo', ['title' => $__title, 'description' => $__desc])
    @vite(['resources/css/app.css'])
    @stack('head')
</head>
<body class="bg-surface-page min-h-screen flex items-center justify-center font-sans antialiased text-body" style="padding:24px;">
    <div class="w-full" style="max-width:400px;">
        <a href="/" class="flex items-center justify-center gap-2 mb-8">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-md bg-primary text-on-primary font-display" style="font-size:var(--fs-md);">R</span>
            <span class="font-display text-ink" style="font-size:var(--fs-xl);">랭크프리</span>
        </a>
        <div class="card p-8">
            @yield('auth-content')
        </div>
        <p class="text-center text-muted mt-6" style="font-size:var(--fs-xs);">@yield('auth-footer')</p>
    </div>
    @stack('scripts')
</body>
</html>
