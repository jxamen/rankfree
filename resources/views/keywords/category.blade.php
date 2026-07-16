@extends('layouts.site')
@section('follow-theme', '1')

@php
    $__desc = "'{$cat->name}' 카테고리 네이버 키워드 분석 리포트 ".number_format($docTotal).'건 — 검색량·경쟁강도·성별연령·트렌드를 무료로 확인하세요.'
        .($cat->description ? ' '.$cat->description : '');
    $__f = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $__crumbs = [
        ['@type' => 'ListItem', 'position' => 1, 'name' => '홈', 'item' => url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => '키워드 인사이트', 'item' => route('keywords.index')],
    ];
    if ($cat->parent) {
        $__crumbs[] = ['@type' => 'ListItem', 'position' => 3, 'name' => $cat->parent->name, 'item' => route('keywords.category', $cat->parent->slug)];
    }
    $__crumbs[] = ['@type' => 'ListItem', 'position' => count($__crumbs) + 1, 'name' => $cat->name, 'item' => url()->current()];
@endphp

@section('title', $cat->name.' 키워드 인사이트 — 검색량·트렌드 분석 · 랭크프리')
@section('description', $__desc)

@push('head')
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => $__crumbs,
], $__f) !!}</script>
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $cat->name.' 키워드 인사이트',
    'description' => $__desc,
    'url' => url()->current(),
    'inLanguage' => 'ko-KR',
    'mainEntity' => [
        '@type' => 'ItemList',
        'numberOfItems' => $docTotal,
        'itemListElement' => $docs->take(20)->values()->map(fn ($d, $i) => [
            '@type' => 'ListItem', 'position' => $i + 1, 'name' => $d->keyword.' 키워드 분석', 'url' => $d->shareUrl(),
        ])->all(),
    ],
], $__f) !!}</script>
@endpush

@section('content')
<section class="container-page" style="padding-top:48px;padding-bottom:80px;">
    {{-- 브레드크럼(가시) --}}
    <nav class="text-muted-soft" style="font-size:var(--fs-xs);margin-bottom:14px;" aria-label="브레드크럼">
        <a href="{{ url('/') }}" class="text-muted-soft" style="text-decoration:none;">홈</a>
        <span aria-hidden="true"> › </span>
        <a href="{{ route('keywords.index') }}" class="text-muted-soft" style="text-decoration:none;">키워드 인사이트</a>
        @if ($cat->parent)
            <span aria-hidden="true"> › </span>
            <a href="{{ route('keywords.category', $cat->parent->slug) }}" class="text-muted-soft" style="text-decoration:none;">{{ $cat->parent->name }}</a>
        @endif
        <span aria-hidden="true"> › </span>
        <span class="text-ink">{{ $cat->name }}</span>
    </nav>

    <div class="badge mb-4 border border-hairline">{{ $cat->type === 'place' ? '플레이스' : '쇼핑' }} 키워드 인사이트</div>
    <h1 class="font-display text-ink" style="font-size:clamp(24px,4vw,34px);line-height:1.2;">{{ $cat->name }} 키워드 인사이트</h1>
    <p class="text-muted" style="margin-top:8px;font-size:var(--fs-sm);line-height:1.6;">
        {{ $cat->description ?: "'{$cat->name}' 카테고리 키워드의 네이버 검색량·경쟁강도·성별연령·트렌드 분석 리포트입니다." }}
    </p>

    {{-- 집계 --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4" style="margin-top:20px;max-width:640px;">
        <div class="card p-4 text-center">
            <div class="text-muted-soft" style="font-size:var(--fs-xs);">분석 리포트</div>
            <div class="font-mono text-ink font-semibold" style="font-size:var(--fs-lg);">{{ number_format($docTotal) }}건</div>
        </div>
        <div class="card p-4 text-center">
            <div class="text-muted-soft" style="font-size:var(--fs-xs);">월간 검색량 합계</div>
            <div class="font-mono text-ink font-semibold" style="font-size:var(--fs-lg);">{{ number_format($volumeSum) }}회</div>
        </div>
        <div class="card p-4 text-center col-span-2 sm:col-span-1">
            <div class="text-muted-soft" style="font-size:var(--fs-xs);">기준</div>
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);padding-top:4px;">네이버 검색광고<br>자체 집계</div>
        </div>
    </div>

    {{-- 하위/형제 카테고리 --}}
    @if ($children->isNotEmpty() || $siblings->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2" style="margin-top:20px;">
            @foreach ($children as $c)
                <a href="{{ route('keywords.category', $c->slug) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;">{{ $c->name }} <span class="font-mono">{{ number_format($c->docs_count) }}</span></a>
            @endforeach
            @if ($siblings->isNotEmpty())
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">함께 보기:</span>
                @foreach ($siblings as $s)
                    <a href="{{ route('keywords.category', $s->slug) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;">{{ $s->name }}</a>
                @endforeach
            @endif
        </div>
    @endif

    {{-- 키워드 문서 목록 (검색량순) --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3" style="margin-top:28px;">
        @forelse ($docs as $d)
            <a href="{{ $d->shareUrl() }}" class="card p-4" style="display:block;text-decoration:none;">
                <div class="text-ink font-semibold" style="font-size:var(--fs-sm);line-height:1.45;">{{ $d->keyword }} 키워드 분석</div>
                <div class="text-muted font-mono" style="margin-top:4px;font-size:var(--fs-xs);">
                    월 {{ number_format((int) $d->monthly_total) }}회{{ $d->comp_idx ? ' · 경쟁 '.$d->comp_idx : '' }}{{ $d->grade ? ' · '.$d->grade.'등급' : '' }}
                </div>
            </a>
        @empty
            <div class="card text-center text-muted-soft sm:col-span-2 lg:col-span-3" style="padding:40px;font-size:var(--fs-sm);">아직 이 카테고리에 발행된 리포트가 없습니다.</div>
        @endforelse
    </div>

    <div style="margin-top:16px;">{{ $docs->links() }}</div>

    {{-- 퍼널 CTA --}}
    <div class="card p-6 text-center" style="margin-top:40px;">
        <div class="font-display text-ink" style="font-size:var(--fs-lg);line-height:1.35;">내 키워드도 무료로 분석해 보세요</div>
        <p class="text-muted" style="margin-top:6px;font-size:var(--fs-sm);">검색량·경쟁·성별연령·트렌드 리포트와 순위 추적을 무료로 시작할 수 있습니다.</p>
        <a href="{{ auth()->check() ? route('console.dashboard') : route('register') }}" class="btn btn-primary" style="margin-top:14px;">무료로 시작</a>
    </div>
</section>
@endsection
