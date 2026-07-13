{{-- 로그인 회원 → 콘솔 셸 / 비로그인 → 공개 사이트(SEO). 타이틀·디스크립션은 메뉴관리 > 사이트 SEO(route: community) --}}
@extends(auth()->check() ? 'console.layout' : 'layouts.site')
@section('page-title', '커뮤니티')

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
