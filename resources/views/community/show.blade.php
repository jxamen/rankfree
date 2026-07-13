{{-- 로그인 회원 → 콘솔 셸 / 비로그인 → 공개 사이트(SEO) --}}
@extends(auth()->check() ? 'console.layout' : 'layouts.site')
@section('title', $post->title.' · 커뮤니티 · 랭크프리')
@section('page-title', '커뮤니티')

@auth
    @section('console-content')
        @include('community._show_body')
    @endsection
@else
    @section('follow-theme', '1')
    @section('content')
        @include('community._show_body')
    @endsection
@endauth
