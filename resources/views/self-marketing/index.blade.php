{{-- 로그인 회원 → 콘솔 셸 / 비로그인 → 공개 사이트(SEO). 타이틀·디스크립션은 메뉴관리 > 사이트 SEO(route: self-marketing) --}}
@extends(auth()->check() ? 'console.layout' : 'layouts.site')
@section('page-title', '셀프마케팅')
@section('description', '플레이스 최적화·블로그 체험단·리뷰 등 셀프마케팅 상품을 직접 골라 주문하세요. 분석 데이터 기반 추천.')
@section('title', '셀프마케팅 — 랭크프리')

@auth
    @section('console-content')
        @include('self-marketing._index_body')
    @endsection
@else
    @section('follow-theme', '1')
    @section('content')
        @include('self-marketing._index_body')
    @endsection
@endauth
