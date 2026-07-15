{{-- 공개 페이지 공통 SEO — canonical·robots·파비콘·Open Graph·Twitter 카드.
     입력: $title, $description (레이아웃에서 계산해 전달 — 원문/비이스케이프).
     페이지 오버라이드: @section('canonical') · @section('robots','noindex, nofollow') · @section('og-type','article') · @section('og-image', 절대URL) --}}
@php
    $__canonical = trim($__env->yieldContent('canonical')) ?: url()->current();
    $__robots = trim($__env->yieldContent('robots'));
    $__ogImageCustom = trim($__env->yieldContent('og-image'));
    $__ogImage = $__ogImageCustom !== '' ? $__ogImageCustom : asset('og-image.png');
@endphp
<link rel="canonical" href="{{ $__canonical }}">
@if ($__robots !== '')<meta name="robots" content="{{ $__robots }}">@endif
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="icon" href="{{ asset('favicon-32.png') }}" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<meta property="og:site_name" content="랭크프리">
<meta property="og:locale" content="ko_KR">
<meta property="og:type" content="@yield('og-type', 'website')">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $__canonical }}">
<meta property="og:image" content="{{ $__ogImage }}">
@if ($__ogImageCustom === '')<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="랭크프리 — 네이버 플레이스·쇼핑 순위 무료 분석">@endif
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $__ogImage }}">
