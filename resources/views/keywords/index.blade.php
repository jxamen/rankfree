@extends('layouts.site')
@section('follow-theme', '1')

@php
    $__desc = '네이버 키워드 검색량·경쟁강도·성별연령·트렌드 분석 리포트를 검색해 보세요. 플레이스·쇼핑 키워드 인사이트 무료 열람.';
    $__f = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
@endphp

@section('title', '키워드 인사이트 — 검색량·트렌드 분석 검색 · 랭크프리')
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
{{-- 사이트링크 검색창 신호 — 검색 진입점임을 검색엔진에 알린다 --}}
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => '랭크프리',
    'url' => url('/'),
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => ['@type' => 'EntryPoint', 'urlTemplate' => route('keywords.search').'?q={search_term_string}'],
        'query-input' => 'required name=search_term_string',
    ],
], $__f) !!}</script>
@endpush

@section('content')
<section class="container-page" style="padding-top:48px;padding-bottom:80px;">
    {{-- 검색 진입점 — 상단은 제목·설명·검색만(카테고리 나열은 타입 홈으로 분리) --}}
    <h1 class="font-display text-ink" style="font-size:clamp(24px,4vw,34px);line-height:1.2;">키워드 인사이트</h1>
    <p class="text-muted" style="margin-top:6px;font-size:var(--fs-sm);line-height:1.6;">
        네이버 검색량·경쟁·성별연령·트렌드 분석 리포트 <b class="font-mono text-ink">{{ number_format($docCount) }}</b>건. 모두 무료입니다.
    </p>

    @include('keywords._searchbar', ['active' => '', 'q' => '', 'big' => true])

    {{-- 인기 검색어 칩 — 빈 검색창 앞에서 막히지 않게 --}}
    @if ($topDocs->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2" style="margin-top:16px;">
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">인기</span>
            @foreach ($topDocs->take(6) as $d)
                <a href="{{ $d->shareUrl() }}" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;">{{ $d->keyword }}</a>
            @endforeach
        </div>
    @endif

    {{-- 카테고리 메뉴 진입 — 플레이스/쇼핑 2분기 --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" style="margin-top:56px;">
        @foreach ($typeStats as $type => $s)
            <a href="{{ route('keywords.type', $type) }}" class="card p-6" style="display:block;text-decoration:none;">
                <div class="text-ink font-semibold" style="font-size:var(--fs-md);">{{ $s['label'] }}</div>
                <p class="text-muted" style="margin-top:6px;font-size:var(--fs-xs);line-height:1.6;">
                    {{ $type === 'place' ? '지역 × 업종 키워드 — 맛집·병원·헤어·숙박까지' : '네이버 데이터랩 분야별 인기 검색어 — 대분류 > 소분류' }}
                </p>
                <div class="text-muted-soft font-mono" style="margin-top:10px;font-size:var(--fs-xs);">
                    {{ $type === 'place' ? '업종' : '분야' }} {{ number_format($s['cats']) }} · 리포트 {{ number_format($s['docs']) }}건
                </div>
                <div class="text-ink" style="margin-top:12px;font-size:var(--fs-xs);font-weight:600;">카테고리 전체 보기 →</div>
            </a>
        @endforeach
    </div>

    {{-- 인기 키워드 리포트 --}}
    @if ($topDocs->isNotEmpty())
        <div style="margin-top:48px;">
            <h2 class="font-display text-ink" style="font-size:var(--fs-xl);line-height:1.3;">지금 인기 키워드 리포트</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3" style="margin-top:12px;">
                @foreach ($topDocs as $d)
                    <a href="{{ $d->shareUrl() }}" class="card p-4" style="display:block;text-decoration:none;">
                        @if ($d->category)
                            <span class="badge border border-hairline" style="font-size:var(--fs-xs);">{{ $d->category->type === 'place' ? '플레이스' : '쇼핑' }}</span>
                        @endif
                        <div class="text-ink font-semibold" style="margin-top:8px;font-size:var(--fs-sm);">{{ $d->keyword }} 키워드 분석</div>
                        <div class="text-muted font-mono" style="margin-top:4px;font-size:var(--fs-xs);">월 {{ number_format((int) $d->monthly_total) }}회 검색{{ $d->comp_idx ? ' · 경쟁 '.$d->comp_idx : '' }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card p-6 text-center" style="margin-top:40px;">
        <div class="font-display text-ink" style="font-size:var(--fs-lg);line-height:1.35;">찾는 키워드가 없나요?</div>
        <p class="text-muted" style="margin-top:6px;font-size:var(--fs-sm);">회원가입하면 원하는 키워드를 직접 분석하고 순위까지 추적할 수 있습니다.</p>
        <a href="{{ auth()->check() ? route('console.dashboard') : route('register') }}" class="btn btn-primary" style="margin-top:14px;">무료로 시작</a>
    </div>
</section>
@endsection
