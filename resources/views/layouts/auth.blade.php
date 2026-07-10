<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '로그인') · rankfree</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-surface-page min-h-screen flex items-center justify-center font-sans antialiased text-body" style="padding:24px;">
    <div class="w-full" style="max-width:400px;">
        <a href="/" class="flex items-center justify-center gap-2 mb-8">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-md bg-primary text-on-primary font-display" style="font-size:17px;">R</span>
            <span class="font-display text-ink" style="font-size:22px;">rankfree</span>
        </a>
        <div class="card p-8">
            @yield('auth-content')
        </div>
        <p class="text-center text-muted mt-6" style="font-size:14px;">@yield('auth-footer')</p>
    </div>
</body>
</html>
