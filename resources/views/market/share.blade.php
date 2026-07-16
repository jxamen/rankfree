@extends('layouts.site')
@section('follow-theme', '1')
@section('og-type', 'article') {{-- 공개 색인 대상(1회성 시장 분석) — noindex 없음 --}}

@php
    $__kw = $a->keyword;
    $__sales = $a->sales_6m ?? null;
    $__rev = $a->revenue_6m ?? null;
    $__avg = $a->avg_price ?? null;
    $__won = fn ($v) => $v !== null ? number_format((int) $v).'원' : null;
    $__summary = "‘{$__kw}’ 네이버 쇼핑 시장 분석"
        .($__sales !== null ? ' — 최근 6개월 판매량 약 '.number_format((int) $__sales).'건' : '')
        .($__avg !== null ? ', 평균가 '.number_format((int) $__avg).'원' : '')
        .'. 시장 규모·판매량·가격대·경쟁 강도를 무료로 진단합니다.';
    $__faq = [[
        'q' => "‘{$__kw}’ 네이버 쇼핑 시장 규모는 어느 정도인가요?",
        'a' => ($__sales !== null ? "최근 6개월 추정 판매량은 약 ".number_format((int) $__sales)."건" : "판매량을 집계 중")
            .($__rev !== null ? ", 추정 매출은 약 ".$__won($__rev) : '')
            .($__avg !== null ? ", 평균 판매가는 ".$__won($__avg) : '')."입니다.",
    ]];
@endphp

@section('title', $__kw.' 쇼핑 시장 분석 · 랭크프리')
@section('description', $__summary)

@include('partials.report-seo', ['seoTitle' => $__kw.' 쇼핑 시장 분석', 'seoDesc' => $__summary, 'seoSection' => '쇼핑 시장 분석', 'seoDate' => $a->created_at, 'seoFaq' => $__faq])

@section('content')
<section class="container-page" style="padding-top:48px;padding-bottom:80px;">
    <div class="badge mb-4 border border-hairline">쇼핑 시장 분석 리포트 · 랭크프리</div>
    <h1 class="font-display text-ink" style="font-size:clamp(24px,4vw,34px);line-height:1.2;">{{ $__kw }} 쇼핑 시장 분석</h1>
    <p class="text-muted" style="margin-top:8px;font-size:var(--fs-sm);line-height:1.6;">{{ $__summary }}</p>
    @include('console._market_body', ['a' => $a, 'weekday' => $weekday ?? null, 'shareUrl' => null, 'public' => true])
    @include('partials.related-docs', ['related' => $related ?? []])
</section>
@endsection
