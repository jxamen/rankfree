<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'rankfree — 네이버 플레이스·쇼핑 순위 분석')</title>
    <meta name="description" content="@yield('description', '키워드만 입력하면 네이버 플레이스·쇼핑 순위와 경쟁사, 블로그 지수를 무료로 분석합니다. 가입 없이 바로 시작하세요.')">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="bg-canvas text-body font-sans antialiased min-h-screen flex flex-col">
    @include('partials.site-header')
    <main class="flex-1">
        @yield('content')
    </main>
    @include('partials.site-footer')
    @stack('scripts')
</body>
</html>
