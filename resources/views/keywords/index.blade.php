@extends('layouts.site')
@section('follow-theme', '1')

@php
    $__desc = '카테고리별 네이버 키워드 검색량·경쟁·트렌드 분석 리포트 모음 — 플레이스·쇼핑 키워드 인사이트를 무료로 열람하세요.';
    $__f = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
@endphp

@section('title', '키워드 인사이트 — 카테고리별 검색량·트렌드 분석 · 랭크프리')
@section('description', $__desc)

@push('head')
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => '키워드 인사이트',
    'description' => $__desc,
    'url' => route('keywords.index'),
    'inLanguage' => 'ko-KR',
    'isPartOf' => ['@type' => 'WebSite', 'name' => '랭크프리', 'url' => url('/')],
], $__f) !!}</script>
@endpush

@section('content')
<section class="container-page" style="padding-top:48px;padding-bottom:80px;">
    <div class="badge mb-4 border border-hairline">키워드 인사이트 · 랭크프리</div>
    <h1 class="font-display text-ink" style="font-size:clamp(24px,4vw,34px);line-height:1.2;">카테고리별 키워드 인사이트</h1>
    <p class="text-muted" style="margin-top:8px;font-size:var(--fs-sm);line-height:1.6;">
        네이버 검색량·경쟁강도·성별연령·트렌드를 담은 키워드 분석 리포트 <b class="font-mono text-ink">{{ number_format($docCount) }}</b>건을 카테고리별로 모았습니다. 모두 무료입니다.
    </p>

    {{-- 카테고리 (대분류 > 소분류) --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" style="margin-top:28px;">
        @forelse ($groups as $g)
            <div class="card p-5">
                <a href="{{ route('keywords.category', $g->slug) }}" class="text-ink font-semibold" style="font-size:var(--fs-md);text-decoration:none;">
                    {{ $g->name }}
                </a>
                <div class="text-muted-soft" style="margin-top:2px;font-size:var(--fs-xs);">
                    {{ $g->type === 'place' ? '플레이스' : '쇼핑' }} · 리포트 <span class="font-mono">{{ number_format($g->docs_count + ($byParent[$g->id] ?? collect())->sum('docs_count')) }}</span>건
                </div>
                @if ($g->description)
                    <p class="text-muted" style="margin-top:8px;font-size:var(--fs-xs);line-height:1.6;">{{ $g->description }}</p>
                @endif
                @if (($byParent[$g->id] ?? collect())->isNotEmpty())
                    <div class="flex flex-wrap gap-2" style="margin-top:12px;">
                        @foreach ($byParent[$g->id] as $child)
                            <a href="{{ route('keywords.category', $child->slug) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;">
                                {{ $child->name }} <span class="font-mono">{{ number_format($child->docs_count) }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="card text-center text-muted-soft sm:col-span-2 lg:col-span-3" style="padding:40px;font-size:var(--fs-sm);">아직 공개된 카테고리가 없습니다.</div>
        @endforelse
    </div>

    {{-- 인기 키워드 리포트 --}}
    @if ($topDocs->isNotEmpty())
        <div style="margin-top:48px;">
            <h2 class="font-display text-ink" style="font-size:var(--fs-xl);line-height:1.3;">지금 인기 키워드 리포트</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3" style="margin-top:12px;">
                @foreach ($topDocs as $d)
                    <a href="{{ $d->shareUrl() }}" class="card p-4" style="display:block;text-decoration:none;">
                        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $d->keyword }} 키워드 분석</div>
                        <div class="text-muted font-mono" style="margin-top:4px;font-size:var(--fs-xs);">월 {{ number_format((int) $d->monthly_total) }}회 검색{{ $d->comp_idx ? ' · 경쟁 '.$d->comp_idx : '' }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</section>
@endsection
