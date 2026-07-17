{{-- 폴백 독립 레이아웃 — 호스트 레이아웃 미지정 시 단독 페이지로 렌더(config('ga4-insights.view.layout')). --}}
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('page-title', 'GA4 방문 분석')</title>
    <style>
        body{margin:0;font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Apple SD Gothic Neo',sans-serif;background:#f5f6f8;color:#0a0b0d;-webkit-font-smoothing:antialiased}
        .ga4-page{max-width:1200px;margin:0 auto;padding:24px 20px 64px}
        .ga4-page h1{font-size:22px;margin:0 0 4px}
    </style>
</head>
<body>
    <div class="ga4-page">@yield('content')</div>
</body>
</html>
