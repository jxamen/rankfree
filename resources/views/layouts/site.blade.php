<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'rankfree — 네이버 플레이스·쇼핑 순위 분석')</title>
    <meta name="description" content="@yield('description', '키워드만 입력하면 네이버 플레이스·쇼핑 순위와 경쟁사, 블로그 지수를 무료로 분석합니다. 가입 없이 바로 시작하세요.')">
    {{-- @section('follow-theme','1') 선언 페이지(공유 리포트 등): 사용자의 콘솔 테마 선택(localStorage)을 따라 다크/라이트 렌더 --}}
    @hasSection('follow-theme')
        <script>if (localStorage.getItem('rf-theme') === 'dark') document.documentElement.classList.add('theme-dark');</script>
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
{{-- 페이지가 @section('theme-dark', '1')을 선언하면 다크 테마(expo.dev 스타일)로 렌더 --}}
<body class="@hasSection('theme-dark') theme-dark bg-surface-dark-deep @else bg-canvas @endif text-body font-sans antialiased min-h-screen flex flex-col">
    @include('partials.site-header')
    <main class="flex-1">
        @yield('content')
    </main>
    @include('partials.site-footer')
    @stack('scripts')
</body>
</html>
