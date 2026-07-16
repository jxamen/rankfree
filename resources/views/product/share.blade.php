@extends('layouts.site')
@section('follow-theme', '1')
@section('og-type', 'article') {{-- 공개 색인 대상(1회성 상품 리뷰 분석) — noindex 없음 --}}

@php
    $__name = $a->name;
    $__rev = $a->total_reviews ?? null;
    $__score = $a->avg_score ?? null;
    $__repur = $a->repurchase_pct ?? null;
    $__summary = "‘{$__name}’ 스마트스토어 리뷰 분석"
        .($__rev !== null ? ' — 리뷰 '.number_format((int) $__rev).'개' : '')
        .($__score !== null ? ', 평균 별점 '.number_format((float) $__score, 1) : '')
        .'. 리뷰 감정·옵션 선호·약점을 무료로 진단합니다.';
    $__faq = [[
        'q' => "‘{$__name}’ 상품의 리뷰 분석 결과는 어떤가요?",
        'a' => ($__rev !== null ? "총 ".number_format((int) $__rev)."개 리뷰" : "리뷰")
            .($__score !== null ? ", 평균 별점 ".number_format((float) $__score, 1)."점" : '')
            .($__repur !== null ? ", 재구매 언급 비율 ".number_format((float) $__repur, 1)."%" : '')."로 분석됐습니다.",
    ]];
@endphp

@section('title', $__name.' 리뷰 분석 · 랭크프리')
@section('description', $__summary)

@include('partials.report-seo', ['seoTitle' => $__name.' 리뷰 분석', 'seoDesc' => $__summary, 'seoSection' => '상품 리뷰 분석', 'seoDate' => $a->created_at, 'seoFaq' => $__faq])

@section('content')
<section class="container-page" style="padding-top:48px;padding-bottom:80px;">
    <div class="badge mb-4 border border-hairline">상품 리뷰 분석 리포트 · 랭크프리</div>
    <h1 class="font-display text-ink" style="font-size:clamp(22px,3.6vw,32px);line-height:1.25;">{{ $__name }}</h1>
    <p class="text-muted" style="margin-top:8px;font-size:var(--fs-sm);line-height:1.6;">{{ $__summary }}</p>
    @include('console._product_body', ['a' => $a, 'shareUrl' => null, 'public' => true])
    @include('partials.related-docs', ['related' => $related ?? []])
</section>
@endsection
