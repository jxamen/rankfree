{{-- 로그인 회원 → 콘솔 셸 / 비로그인 → 공개 사이트(SEO). 타이틀·디스크립션은 메뉴관리 > 사이트 SEO(route: community) --}}
@extends(auth()->check() ? 'console.layout' : 'layouts.site')
@section('page-title', '커뮤니티')

{{-- SEO — 카테고리별 고유 title/description + canonical(cat·page 만 보존해 파라미터 URL 중복 색인 방지) --}}
@if ($category)
    @section('title', $category->name.' · 커뮤니티 · 랭크프리')
    @if ($category->description)
        @section('description', $category->description.' — 랭크프리 커뮤니티')
    @endif
@endif
@section('canonical', route('community', array_filter([
    'cat' => $category?->slug,
    'page' => (int) request('page') > 1 ? (int) request('page') : null,
])))
@push('head')
<link rel="alternate" type="application/rss+xml" title="랭크프리 커뮤니티 RSS" href="{{ route('community.feed') }}">
@endpush

@auth
    @section('console-content')
        @include('community._index_body')
    @endsection
@else
    @section('follow-theme', '1')
    @section('content')
        @include('community._index_body')
    @endsection
@endauth
