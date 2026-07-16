@extends('layouts.site')
@section('follow-theme', '1')
@section('og-type', 'article') {{-- 공개 색인 대상(1회성 키워드 분석) — noindex 없음 --}}

@php
    $__kw = $vm['keyword'];
    $__total = $vm['total'] ?? null;
    $__grade = $vm['grade'] ?? null;
    $__pc = $vm['pc'] ?? null;
    $__mobile = $vm['mobile'] ?? null;
    $__volTxt = $__total !== null ? '월 '.number_format($__total).'회' : '집계중';
    $__summary = "‘{$__kw}’ 네이버 키워드 검색량 {$__volTxt}"
        .($__grade ? " · 경쟁강도 {$__grade}" : '')
        .' — 성별·연령·검색 트렌드·콘텐츠 포화도까지 무료 분석 리포트.';
    $__faq = [[
        'q' => "‘{$__kw}’ 월간 검색량은 얼마인가요?",
        'a' => ($__total !== null
            ? "네이버 기준 월 약 ".number_format($__total)."회입니다(PC ".number_format((int) $__pc)." · 모바일 ".number_format((int) $__mobile)."). "
            : "검색량 데이터를 집계 중입니다. ")
            .($__grade ? "경쟁강도는 {$__grade} 수준입니다." : ''),
    ]];
@endphp

@section('title', $__kw.' 키워드 검색량 분석 · 랭크프리')
@section('description', $__summary)

@include('partials.report-seo', ['seoTitle' => $__kw.' 키워드 분석', 'seoDesc' => $__summary, 'seoSection' => '키워드 분석', 'seoDate' => null, 'seoFaq' => $__faq])

@section('content')
<section class="container-page" style="padding-top:48px;padding-bottom:80px;">
    <div class="badge mb-4 border border-hairline">키워드 분석 리포트 · 랭크프리</div>
    <h1 class="font-display text-ink" style="font-size:clamp(24px,4vw,34px);line-height:1.2;">{{ $__kw }} 키워드 분석</h1>
    <p class="text-muted" style="margin-top:8px;font-size:var(--fs-sm);line-height:1.6;">{{ $__summary }}</p>
    @include('partials._keyword_body', [
        'vm' => $vm,
        'saturation' => $saturation,
        'popular' => $popular,
        'weekday' => $weekday,
        'autocomplete' => $autocomplete ?? [],
        'public' => true,
        'shareUrl' => null,
    ])
    @include('partials.related-docs', ['related' => $related ?? []])
</section>
@endsection
