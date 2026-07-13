@extends('layouts.site')
@section('follow-theme', '1')

@section('title', $slot->keyword.' 순위 리포트 · rankfree')
@section('description', $slot->place_name.' — '.$slot->keyword.' 키워드 플레이스 순위 추적 리포트')

@section('content')
<style>
    .rf-cell { width: 104px; padding: 10px 8px 8px; }
</style>
<section class="container-page" style="padding-top:48px;padding-bottom:80px;">
    <div class="mb-6">
        <div class="badge mb-3 border border-hairline">순위 추적 리포트 · rankfree</div>
        <h1 class="font-display text-ink" style="font-size:clamp(24px,3vw,32px);line-height:1.2;">{{ $slot->keyword }}</h1>
        <p class="text-muted mt-2" style="font-size:var(--fs-xs);">
            {{ $slot->place_name ?: ($slot->place_id ? 'ID '.$slot->place_id : '') }}
            @if ($slot->place_url)
                · <a href="{{ $slot->place_url }}" target="_blank" class="text-accent" style="font-size:var(--fs-xs);">{{ $slot->place_url }}</a>
            @endif
        </p>
    </div>

    <div class="card overflow-hidden">
        @include('rank.partials.cells', ['slot' => $slot])
    </div>

    <p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">
        영=영수증(방문자) 리뷰 · 블=블로그 리뷰 · 저장=저장수(음식점만) · 순위 변동은 전일 대비(＋상승/−하락).
        순위·지표는 rankfree 자체 수집 데이터입니다.
    </p>

    <div class="card-soft mt-8 p-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">내 가게 순위도 무료로 추적해보세요</div>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);">키워드만 넣으면 매일 순위를 기록하고 알림으로 알려드립니다.</p>
        </div>
        <a href="{{ route('home') }}" class="btn btn-primary btn-sm">무료로 시작</a>
    </div>
</section>
@endsection
