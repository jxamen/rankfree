@extends('layouts.site')
@section('follow-theme', '1')
@section('robots', 'noindex, nofollow') {{-- 토큰 공유 리포트 — 색인 제외(OG 미리보기는 유지) --}}

@section('title', $a->name.' 매장 분석 리포트 · 랭크프리')
@section('description', $a->name.' — 플레이스 순위·리뷰·정보충실 등 매장 SEO 분석 리포트')

@section('content')
@php
    $d = (array) $a->detail;
    $dd = (array) ($d['d'] ?? []);
    $kc = (array) ($d['kc'] ?? []);
    $seo = array_values(array_filter((array) ($d['seo'] ?? []), fn ($s) => ! empty($s['avail'])));
    $rep = array_values((array) ($d['rep_keywords'] ?? []));
    $barw = fn ($v) => $v === null ? 0 : max(2, min(100, round($v)));
    $dims = [
        ['D1 영수증리뷰', 'd1'], ['D2 블로그리뷰', 'd2'], ['D3 예약리뷰', 'd3'],
        ['D4 평점', 'd4'], ['D5 저장수', 'd5'], ['D6 사진수', 'd6'],
        ['D7 정보충실', 'd7'], ['D9 최근활동', 'd9'], ['D10 리뷰어영향력', 'd10'],
    ];
@endphp
<style>
    .ps-row { display:flex; align-items:center; gap:12px; font-size:var(--fs-sm); margin:8px 0; }
    .ps-row .l { width:150px; flex:none; color:var(--color-body); }
    .ps-row .l small { color:var(--color-muted-soft); font-weight:400; }
    .ps-row .t { flex:1; height:8px; background:var(--color-surface-strong); border-radius:4px; overflow:hidden; }
    .ps-row .t span { display:block; height:100%; background:var(--color-accent); border-radius:4px; }
    .ps-row .v { width:38px; text-align:right; color:var(--color-ink); font-weight:600; }
</style>

<section class="container-page" style="padding-top:48px;padding-bottom:80px;max-width:900px;">
    <div class="badge mb-4 border border-hairline">플레이스 매장 분석 리포트 · 랭크프리</div>
    <h1 class="font-display text-ink" style="font-size:clamp(24px,4vw,34px);line-height:1.2;">{{ $a->name }}</h1>
    <p class="text-muted" style="margin-top:8px;font-size:var(--fs-sm);">
        키워드 '<b class="text-ink">{{ $a->keyword }}</b>' ·
        {{ (! $a->rank || $a->rank >= 300) ? '상위권 밖' : '순위 '.$a->rank.'위' }} ·
        {{ $a->created_at->format('Y-m-d') }} 분석
    </p>

    {{-- 종합 지수 --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-6">
        @foreach ([['N1 유사도', $a->n1], ['N2 관련성', $a->n2], ['N3 랭킹', $a->n3]] as [$lb, $v])
            <div class="card p-5 text-center">
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $lb }}</div>
                <div class="font-display text-ink" style="font-size:34px;line-height:1.1;margin-top:4px;">{{ $v === null ? '–' : round($v) }}</div>
            </div>
        @endforeach
    </div>

    {{-- 리뷰·저장 --}}
    <div class="grid grid-cols-3 gap-4 mt-4">
        @foreach ([['영수증리뷰', $a->visitor_cnt], ['블로그리뷰', $a->blog_cnt], ['저장수', $a->save_cnt]] as [$lb, $v])
            <div class="card p-4 text-center">
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $lb }}</div>
                <div class="text-ink font-semibold" style="font-size:var(--fs-lg);">{{ number_format((int) $v) }}</div>
            </div>
        @endforeach
    </div>

    {{-- 세부 지표 D1~D10 --}}
    <div class="card p-6 mt-6">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">세부 지표 (D1~D10)</div>
        @foreach ($dims as [$lb, $k])
            @php $v = $dd[$k] ?? null; @endphp
            <div class="ps-row"><span class="l">{{ $lb }}</span><span class="t"><span style="width:{{ $barw($v) }}%"></span></span><span class="v">{{ $v === null ? '–' : round($v) }}</span></div>
        @endforeach
    </div>

    {{-- N1 유사도 요소 --}}
    @if ($kc)
        <div class="card p-6 mt-4">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">N1 유사도 요소</div>
            @foreach ([['지역 L', 'L', 'region'], ['업종 B', 'B', 'bizterm'], ['대표키워드 T', 'T', 'core'], ['상호 M', 'M', null]] as [$lb, $k, $sub])
                @php $v = (isset($kc[$k]) && $kc[$k] !== null) ? $kc[$k] * 100 : null; @endphp
                <div class="ps-row"><span class="l">{{ $lb }} @if ($sub && ! empty($kc[$sub]))<small>{{ $kc[$sub] }}</small>@endif</span><span class="t"><span style="width:{{ $barw($v) }}%"></span></span><span class="v">{{ $v === null ? '–' : round($v) }}</span></div>
            @endforeach
        </div>
    @endif

    {{-- 정보충실성 --}}
    @if ($seo)
        <div class="card p-6 mt-4">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">정보충실성 (D7 세부)</div>
            @foreach ($seo as $s)
                @php $g = round(((float) ($s['grade'] ?? 0)) * 100); @endphp
                <div class="ps-row"><span class="l">{{ $s['label'] }} <small>{{ $s['raw'] }}</small></span><span class="t"><span style="width:{{ max(2, $g) }}%"></span></span><span class="v">{{ $g }}</span></div>
            @endforeach
        </div>
    @endif

    {{-- 대표키워드 --}}
    @if ($rep)
        <div class="card p-6 mt-4">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">대표키워드</div>
            <div class="flex flex-wrap gap-2">
                @foreach (array_slice($rep, 0, 30) as $t)<span class="badge">{{ $t }}</span>@endforeach
            </div>
        </div>
    @endif

    <p class="text-muted-soft text-center mt-6" style="font-size:var(--fs-xs);line-height:1.6;">
        N1 유사도·N2 관련성·N3 랭킹 및 세부 지표는 관측 신호 기반 <b>자체 추정치</b>이며 네이버 공식 점수가 아닙니다.
    </p>
</section>
@endsection
