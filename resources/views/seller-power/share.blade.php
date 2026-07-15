@extends('layouts.site')
@section('follow-theme', '1')
@section('robots', 'noindex, nofollow') {{-- 토큰 공유 리포트 — 색인 제외(OG 미리보기는 유지) --}}

@section('title', ($a->product_name ?: $a->store_id).' 셀러력 진단 리포트 · 랭크프리')
@section('description', ($a->keyword).' 기준 셀러력 진단 — 5축 경쟁 비교·개선 우선순위·처방 리포트')

@section('content')
{{-- 콘솔 상세와 같은 폭(1440px)으로 — 기본 container-page(1200px)보다 넓게 --}}
<section class="container-page" style="max-width:1440px;padding-top:48px;padding-bottom:80px;">
    <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
        <div class="badge border border-hairline">셀러력 진단 리포트 · 랭크프리</div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="spSaveImage(this)" title="리포트를 화면 그대로 PNG 이미지로 저장">🖼 이미지 저장</button>
    </div>
    @include('partials._seller_power_body', ['a' => $a, 'r' => $r])
</section>
@endsection
