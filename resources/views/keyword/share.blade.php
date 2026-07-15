@extends('layouts.site')
@section('follow-theme', '1')
@section('robots', 'noindex, nofollow') {{-- 토큰 공유 리포트 — 색인 제외(OG 미리보기는 유지) --}}

@section('title', $vm['keyword'].' 키워드 분석 리포트 · 랭크프리')
@section('description', $vm['keyword'].' — 월간 검색량·성별/연령·트렌드·콘텐츠 포화 분석 리포트')

@section('content')
<section class="container-page" style="padding-top:48px;padding-bottom:80px;">
    <div class="badge mb-4 border border-hairline">키워드 분석 리포트 · 랭크프리</div>
    @include('partials._keyword_body', [
        'vm' => $vm,
        'saturation' => $saturation,
        'popular' => $popular,
        'weekday' => $weekday,
        'autocomplete' => $autocomplete ?? [],
        'public' => true,
        'shareUrl' => null,
    ])
</section>
@endsection
