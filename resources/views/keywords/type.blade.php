@extends('layouts.site')
@section('follow-theme', '1')

@php
    $__isPlace = $type === 'place';
    $__desc = $__isPlace
        ? 'pcmap 업종별 지역 키워드(맛집·병원·헤어·숙박)의 네이버 검색량·경쟁·트렌드 분석 리포트 '.number_format($typeDocCount).'건.'
        : '네이버 데이터랩 분야별 인기 검색어의 검색량·경쟁·트렌드 분석 리포트 '.number_format($typeDocCount).'건.';
    $__f = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
@endphp

@section('title', $typeLabel.' 키워드 인사이트 — 카테고리 · 랭크프리')
@section('description', $__desc)
{{-- 발행 문서가 없으면 빈 목록이라 색인 대상이 아니다(도어웨이 방지) — 링크는 따라가게 follow 유지 --}}
@if ($typeDocCount === 0)
    @section('robots', 'noindex, follow')
@endif

@push('head')
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => '홈', 'item' => url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => '키워드 인사이트', 'item' => route('keywords.index')],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $typeLabel, 'item' => url()->current()],
    ],
], $__f) !!}</script>
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $typeLabel.' 키워드 인사이트',
    'description' => $__desc,
    'url' => url()->current(),
    'inLanguage' => 'ko-KR',
    'mainEntity' => [
        '@type' => 'ItemList',
        'numberOfItems' => $groups->count(),
        'itemListElement' => $groups->values()->map(fn ($c, $i) => [
            '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c->name, 'url' => route('keywords.category', $c->slug),
        ])->all(),
    ],
], $__f) !!}</script>
@endpush

@section('content')
{{-- 헤더 → 브레드크럼 16px, 브레드크럼 → 제목 34px (keyword/share 와 동일 리듬) --}}
<section class="container-page" style="padding-top:16px;padding-bottom:80px;">
    <nav class="text-muted-soft" style="font-size:var(--fs-xs);margin-bottom:34px;" aria-label="브레드크럼">
        <a href="{{ url('/') }}" class="text-muted-soft" style="text-decoration:none;">홈</a>
        <span aria-hidden="true"> › </span>
        <a href="{{ route('keywords.index') }}" class="text-muted-soft" style="text-decoration:none;">키워드 인사이트</a>
        <span aria-hidden="true"> › </span>
        <span class="text-ink">{{ $typeLabel }}</span>
    </nav>

    <h1 class="font-display text-ink" style="font-size:clamp(24px,4vw,34px);line-height:1.2;">{{ $typeLabel }} 키워드 인사이트</h1>
    <p class="text-muted" style="margin-top:6px;font-size:var(--fs-sm);line-height:1.6;">{{ $__desc }}</p>

    {{-- 플레이스는 아래 셀렉트 줄에 검색을 함께 두므로 세그먼트만(중복 검색창 방지) --}}
    @include('keywords._searchbar', ['active' => $type, 'q' => $__isPlace ? ($q ?? '') : '', 'big' => false, 'segOnly' => $__isPlace])

    @if ($__isPlace)
        {{-- 업종 · 지역 셀렉트 — 관리자 키워드 탐색과 동일한 방식(상위를 고르면 하위 option 이 채워진다).
             카드 박스 없이 셀렉트 줄만. 고르기 전에는 목록을 내지 않는다 — 지역을 고르면 그 지역 키워드가 쭉 나온다. --}}
        <div style="margin-top:24px;">
            <form method="GET" action="{{ route('keywords.type', 'place') }}" id="kp-form" class="flex items-center gap-2" style="flex-wrap:nowrap;overflow-x:auto;">
                <span class="text-ink font-semibold flex-none" style="font-size:var(--fs-xs);">업종</span>
                <select name="cat" class="input" style="height:36px;min-width:150px;" onchange="kpGo(this, [])">
                    <option value="">전체</option>
                    @foreach ($cats as $c)
                        <option value="{{ $c->slug }}" @selected($activeCat && $activeCat->id === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>

                <span class="text-ink font-semibold flex-none" style="font-size:var(--fs-xs);padding-left:8px;">지역</span>
                <select name="sido" class="input" style="height:36px;min-width:120px;" onchange="kpGo(this, ['sgg','region'])">
                    <option value="">시/도</option>
                    @foreach ($grouped['sido'] as $__s => $__c)
                        <option value="{{ $__s }}" @selected($sido === (string) $__s)>{{ $__s }} ({{ number_format($__c) }})</option>
                    @endforeach
                </select>
                <span class="text-muted-soft">›</span>
                <select name="sgg" class="input" style="height:36px;min-width:140px;" onchange="kpGo(this, ['region'])" @disabled($sido === null)>
                    <option value="">시/군/구</option>
                    @foreach (($grouped['sgg'][$sido] ?? []) as $__g => $__c)
                        <option value="{{ $__g }}" @selected($sgg === (string) $__g)>{{ $__g }} ({{ number_format($__c) }})</option>
                    @endforeach
                </select>
                <span class="text-muted-soft">›</span>
                <select name="region" class="input" style="height:36px;min-width:150px;" onchange="kpGo(this, [])" @disabled($sgg === null)>
                    <option value="">지역</option>
                    @foreach (($grouped['leaf'][$sido][$sgg] ?? []) as $__r => $__c)
                        <option value="{{ $__r }}" @selected($region === (string) $__r)>{{ $__r }} ({{ number_format($__c) }})</option>
                    @endforeach
                </select>

                {{-- 검색 — 지역 우측(관리자 키워드 탐색과 동일한 한 줄) --}}
                <span class="mx-1"></span>
                <input type="search" name="q" class="input flex-none" style="height:36px;width:200px;" placeholder="키워드 검색" value="{{ $q ?? '' }}">
                <button type="submit" class="btn btn-secondary btn-sm flex-none" style="height:36px;">검색</button>
                @if ($sido !== null || $activeCat || ($q ?? '') !== '')
                    <a href="{{ route('keywords.type', 'place') }}" class="btn btn-ghost btn-sm flex-none" style="height:36px;">초기화</a>
                @endif
            </form>
        </div>

        {{-- 고른 지역(·업종)의 키워드 — 게시판형 리스트. 지역 미선택이면 목록 없음 --}}
        @if ($docs)
            <div style="margin-top:24px;">
                <h2 class="font-display text-ink" style="font-size:var(--fs-lg);line-height:1.3;">
                    @if (($isRecent ?? false))
                        최근 발행 키워드
                        <span class="text-muted-soft font-mono" style="font-size:var(--fs-xs);">{{ number_format($docs->count()) }}건</span>
                    @else
                        {{ trim(($region ?? $sgg ?? $sido ?? '').' '.($activeCat?->name ?? '')) ?: (($q ?? '') !== '' ? "‘{$q}’ 검색" : '') }} 키워드
                        <span class="text-muted-soft font-mono" style="font-size:var(--fs-xs);">{{ number_format($docs->total()) }}건</span>
                    @endif
                </h2>
                <div style="margin-top:12px;overflow-x:auto;">
                    <table class="w-full" style="border-collapse:collapse;font-size:var(--fs-sm);">
                        <thead>
                            <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                                <th style="padding:10px 8px;font-size:var(--fs-xs);font-weight:600;">키워드</th>
                                <th style="padding:10px 8px;font-size:var(--fs-xs);font-weight:600;">지역</th>
                                <th style="padding:10px 8px;font-size:var(--fs-xs);font-weight:600;text-align:right;">월 검색량</th>
                                <th style="padding:10px 8px;font-size:var(--fs-xs);font-weight:600;">경쟁</th>
                                <th style="padding:10px 8px;font-size:var(--fs-xs);font-weight:600;">등급</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($docs as $d)
                                <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                                    <td style="padding:11px 8px;">
                                        <a href="{{ $d->shareUrl() }}" class="text-ink font-semibold" style="text-decoration:none;">{{ $d->keyword }}</a>
                                    </td>
                                    <td class="text-muted" style="padding:11px 8px;font-size:var(--fs-xs);">{{ $d->region ?: '—' }}</td>
                                    <td style="padding:11px 8px;text-align:right;" class="font-mono text-body">{{ number_format((int) $d->monthly_total) }}</td>
                                    <td style="padding:11px 8px;" class="text-muted">{{ $d->comp_idx ?: '—' }}</td>
                                    <td style="padding:11px 8px;" class="font-mono text-muted">{{ $d->grade ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-muted-soft text-center" style="padding:40px;font-size:var(--fs-sm);">이 조건의 리포트가 아직 없습니다.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @unless (($isRecent ?? false))
                    <div style="margin-top:16px;">{{ $docs->links() }}</div>
                @endunless
            </div>
        @endif

        <script>
            // 상위 선택을 바꾸면 하위 선택은 비우고 다시 조회(관리자 키워드 탐색과 동일)
            function kpGo(sel, clear) {
                var f = document.getElementById('kp-form');
                (clear || []).forEach(function (n) { var el = f.elements[n]; if (el) el.value = ''; });
                f.submit();
            }
        </script>
    @else
        {{-- 쇼핑 — 대분류 섹션 + 소분류 텍스트 인덱스(다열). 카드가 아니라 인덱스라야 수십~수백 개가 읽힌다.
             ⚠️ 소분류 링크를 JS lazy-fetch 로 '최적화'하지 말 것 — 내부 링크 그물이 통째로 사라진다. --}}
        <div style="margin-top:32px;">
            @forelse ($groups as $g)
                @php $__subs = $byParent[$g->id] ?? collect(); @endphp
                <div style="margin-bottom:28px;">
                    <div class="flex items-baseline gap-2 flex-wrap">
                        <a href="{{ route('keywords.category', $g->slug) }}" class="font-display text-ink" style="font-size:var(--fs-lg);line-height:1.3;text-decoration:none;">{{ $g->name }}</a>
                        <span class="text-muted-soft font-mono" style="font-size:var(--fs-xs);">소분류 {{ number_format($__subs->count()) }} · 리포트 {{ number_format($g->docs_count + $__subs->sum('docs_count')) }}건</span>
                    </div>
                    @if ($__subs->isNotEmpty())
                        <div class="card p-4" style="margin-top:10px;column-gap:24px;column-width:220px;">
                            @foreach ($__subs as $s)
                                <a href="{{ route('keywords.category', $s->slug) }}" class="text-body" style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:5px 0;font-size:var(--fs-xs);text-decoration:none;break-inside:avoid;">
                                    <span>{{ $s->name }}</span>
                                    <span class="text-muted-soft font-mono">{{ number_format($s->docs_count) }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="card text-center text-muted-soft" style="padding:40px;font-size:var(--fs-sm);">아직 공개된 분야가 없습니다.</div>
            @endforelse
        </div>
    @endif

    @if ($topDocs->isNotEmpty())
        <div style="margin-top:40px;">
            <h2 class="font-display text-ink" style="font-size:var(--fs-lg);line-height:1.3;">{{ $typeLabel }} 인기 리포트</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3" style="margin-top:12px;">
                @foreach ($topDocs as $d)
                    <a href="{{ $d->shareUrl() }}" class="card p-4" style="display:block;text-decoration:none;">
                        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $d->keyword }} 키워드 분석</div>
                        <div class="text-muted font-mono" style="margin-top:4px;font-size:var(--fs-xs);">월 {{ number_format((int) $d->monthly_total) }}회{{ $d->comp_idx ? ' · 경쟁 '.$d->comp_idx : '' }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card p-6 text-center" style="margin-top:40px;">
        <div class="font-display text-ink" style="font-size:var(--fs-lg);line-height:1.35;">내 키워드도 무료로 분석해 보세요</div>
        <p class="text-muted" style="margin-top:6px;font-size:var(--fs-sm);">검색량·경쟁·성별연령·트렌드 리포트와 순위 추적을 무료로 시작할 수 있습니다.</p>
        <a href="{{ auth()->check() ? route('console.dashboard') : route('register') }}" class="btn btn-primary" style="margin-top:14px;">무료로 시작</a>
    </div>
</section>
@endsection
