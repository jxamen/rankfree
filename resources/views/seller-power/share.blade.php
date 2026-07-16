@extends('layouts.site')
@section('follow-theme', '1')
@section('og-type', 'article') {{-- 공개 색인 대상(1회성 셀러력 진단) — noindex 없음 --}}

@php
    $__name = $a->product_name ?: $a->store_id;
    $__kw = $a->keyword;
    $__score = $a->score ?? null;
    $__grade = $a->grade ?? null;
    $__pct = $a->market_percentile ?? null;
    $__summary = "‘{$__name}’ 셀러력 진단"
        .($__score !== null ? ' — 종합 '.round((float) $__score).'점' : '')
        .($__grade ? ' ('.$__grade.'등급)' : '')
        .($__kw ? " · ‘{$__kw}’ 시장 기준" : '')
        .'. 5축 경쟁 비교·개선 우선순위·처방을 제공합니다.';
    $__faq = [[
        'q' => "‘{$__name}’의 셀러력(경쟁력) 점수는 몇 점인가요?",
        'a' => ($__score !== null ? "종합 셀러력 점수는 ".round((float) $__score)."점" : "셀러력을 산출 중")
            .($__grade ? " ({$__grade}등급)" : '')
            .($__pct !== null ? "으로, 동일 시장 상위 약 ".(int) $__pct."%에 해당합니다." : "입니다."),
    ]];
@endphp

@section('title', $__name.' 셀러력 진단 · 랭크프리')
@section('description', $__summary)

@include('partials.report-seo', ['seoTitle' => $__name.' 셀러력 진단', 'seoDesc' => $__summary, 'seoSection' => '셀러력 진단', 'seoDate' => $a->created_at, 'seoFaq' => $__faq])

@section('content')
{{-- 콘솔 상세와 같은 폭(1440px)으로 — 기본 container-page(1200px)보다 넓게 --}}
<section class="container-page" style="max-width:1440px;padding-top:48px;padding-bottom:80px;">
    <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
        <div class="badge border border-hairline">셀러력 진단 리포트 · 랭크프리</div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="spSaveImage(this)" title="리포트를 화면 그대로 PNG 이미지로 저장">🖼 이미지 저장</button>
    </div>
    <h1 class="font-display text-ink" style="font-size:clamp(22px,3.6vw,32px);line-height:1.25;">{{ $__name }} 셀러력 진단</h1>
    <p class="text-muted" style="margin-top:8px;margin-bottom:16px;font-size:var(--fs-sm);line-height:1.6;">{{ $__summary }}</p>
    @include('partials._seller_power_body', ['a' => $a, 'r' => $r])
    @include('partials.related-docs', ['related' => $related ?? []])
</section>
@endsection
