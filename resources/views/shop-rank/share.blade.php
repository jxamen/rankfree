@extends('layouts.site')
@section('follow-theme', '1')

@section('title', $slot->keyword.' 쇼핑 순위 리포트 · 랭크프리')
@section('description', ($slot->product_title ?: $slot->mall_name).' — '.$slot->keyword.' 쇼핑 검색 순위 추적 리포트')

@section('content')
<style>
    .rf-cell { width: 118px; padding: 10px 8px 8px; }
</style>
<section class="container-page" style="padding-top:48px;padding-bottom:80px;">
    <div class="mb-6">
        <div class="badge mb-3 border border-hairline">쇼핑 순위 추적 리포트 · 랭크프리</div>
        <h1 class="font-display text-ink" style="font-size:clamp(24px,3vw,32px);line-height:1.2;">{{ $slot->keyword }}</h1>
        <p class="text-muted mt-2" style="font-size:var(--fs-xs);">
            {{ $slot->target_type === 'mall' ? '업체' : '상품' }} · {{ $slot->product_title ?: ($slot->mall_name ?: ($slot->product_id ? 'ID '.$slot->product_id : '')) }}
            @if ($slot->product_url)
                · <a href="{{ $slot->product_url }}" target="_blank" class="text-accent" style="font-size:var(--fs-xs);">{{ $slot->product_url }}</a>
            @endif
        </p>
    </div>

    <div class="card overflow-hidden">
        @include('shop-rank.partials.cells', ['slot' => $slot])
    </div>

    <p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">
        순위 변동은 전일 대비(＋상승/−하락). 순위는 네이버 쇼핑 검색(정확도순) 기준 랭크프리 자체 수집 데이터입니다.
    </p>

    <div class="card-soft mt-8 p-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">내 상품 순위도 무료로 추적해보세요</div>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);">상품 URL과 키워드만 넣으면 매일 쇼핑 검색 순위를 기록합니다.</p>
        </div>
        <a href="{{ route('home') }}" class="btn btn-primary btn-sm">무료로 시작</a>
    </div>
</section>
@endsection
