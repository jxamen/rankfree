{{-- 공개 API 문서 — 외부 파트너 공유용(비로그인 열람). 본문은 콘솔 문서(console.developers)와 공용 partial --}}
@extends('layouts.site')
@section('title', 'API 문서 — 랭크프리')
@section('description', '랭크프리 오픈 API — 인증 · 순위추적 · 경쟁분석 · 키워드분석 · 마케팅 상품 주문 연동 문서')

@section('content')
<section class="py-10 lg:py-14 container-page">
    <div class="mb-6">
        <h1 class="font-display text-ink" style="font-size:clamp(24px,2.8vw,32px);line-height:1.2;">API 문서</h1>
        <p class="text-muted mt-1" style="font-size:var(--fs-sm);">랭크프리 오픈 API — 인증 · 순위추적 · 경쟁분석 · 키워드분석 · 마케팅 상품 주문</p>
    </div>
    <div style="max-width:880px;">
        @include('partials.developers-doc')
    </div>
</section>
@endsection
