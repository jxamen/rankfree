@extends('layouts.site')
@section('follow-theme', '1')

@section('title', $a->name.' 리뷰 분석 리포트 · rankfree')
@section('description', $a->name.' — 스마트스토어 리뷰 감정·옵션·약점 분석 리포트')

@section('content')
<section class="container-page" style="padding-top:48px;padding-bottom:80px;">
    <div class="badge mb-4 border border-hairline">상품 리뷰 분석 리포트 · rankfree</div>
    @include('console._product_body', ['a' => $a, 'shareUrl' => null, 'public' => true])
</section>
@endsection
