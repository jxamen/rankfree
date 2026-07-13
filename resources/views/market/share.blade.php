@extends('layouts.site')
@section('follow-theme', '1')

@section('title', $a->keyword.' 쇼핑 시장 분석 리포트 · rankfree')
@section('description', $a->keyword.' — 네이버 쇼핑 시장 규모·판매량·경쟁 분석 리포트')

@section('content')
<section class="container-page" style="padding-top:48px;padding-bottom:80px;">
    <div class="badge mb-4 border border-hairline">쇼핑 시장 분석 리포트 · rankfree</div>
    @include('console._market_body', ['a' => $a, 'weekday' => $weekday ?? null, 'shareUrl' => null, 'public' => true])
</section>
@endsection
