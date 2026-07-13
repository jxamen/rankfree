@extends('layouts.site')

@section('title', $keyword.' 순위 결과 — 랭크프리')

@section('content')
<section class="container-page py-16 lg:py-20" style="max-width:760px;">
    <a href="/#hero-form" class="text-muted hover:text-ink transition" style="font-size:var(--fs-xs);">← 다시 조회</a>

    <div class="mt-4 mb-8">
        <div class="badge mb-3">순위 조회 결과</div>
        <h1 class="font-display text-ink" style="font-size:clamp(26px,3.5vw,36px);line-height:1.15;">
            “{{ $keyword }}”
        </h1>
        <p class="mt-2 text-muted" style="font-size:var(--fs-sm);">대상 · {{ $result['place_name'] ?: $place }}</p>
    </div>

    @if ($result['blocked'])
        {{-- 차단/토큰 만료 --}}
        <div class="card p-8 text-center">
            <div style="font-size:var(--fs-2xl);">⏳</div>
            <h2 class="mt-3 text-ink font-semibold" style="font-size:var(--fs-md);">지금은 순위를 불러올 수 없어요</h2>
            <p class="mt-2 text-muted" style="font-size:var(--fs-sm);line-height:1.6;">
                네이버 조회가 일시적으로 제한됐습니다. 잠시 후 다시 시도해 주세요.
            </p>
            <a href="/#hero-form" class="btn btn-secondary mt-6">다시 조회</a>
        </div>
    @elseif ($result['found'])
        {{-- 순위 발견 --}}
        @php
            $catLabel = ['place'=>'플레이스','restaurant'=>'음식점','hospital'=>'병원','hairshop'=>'미용','nailshop'=>'네일','accommodation'=>'숙박'][$result['category']] ?? '플레이스';
            $pct = $result['list_total'] > 0 ? max(0.1, round($result['rank'] / $result['list_total'] * 100, 1)) : null;
        @endphp
        <div class="card overflow-hidden" style="box-shadow:var(--shadow-card);">
            {{-- 순위 히어로 (다크 featured 밴드) --}}
            <div style="background:var(--color-surface-dark);padding:34px 32px;">
                <div class="flex items-end justify-between flex-wrap gap-5">
                    <div style="min-width:0;">
                        <div style="color:rgba(255,255,255,.5);font-size:var(--fs-xs);">{{ $catLabel }} · 총 {{ number_format($result['list_total']) }}개 노출</div>
                        <div class="font-semibold mt-1 truncate" style="color:#fff;font-size:var(--fs-md);max-width:360px;">{{ $result['place_name'] ?: $place }}</div>
                        @if ($pct !== null)
                        <span class="inline-flex items-center mt-3" style="background:color-mix(in srgb, var(--color-success) 20%, transparent);color:color-mix(in srgb, var(--color-success) 40%, #fff);padding:5px 13px;border-radius:var(--radius-pill);font-size:var(--fs-xs);font-weight:600;">상위 {{ $pct < 1 ? $pct : round($pct) }}%</span>
                        @endif
                    </div>
                    <div class="text-right flex-none">
                        <div style="color:rgba(255,255,255,.5);font-size:var(--fs-xs);">현재 순위</div>
                        <div class="font-display" style="color:#fff;font-size:clamp(52px,9vw,80px);line-height:.85;letter-spacing:-0.02em;">{{ $result['rank'] }}<span style="font-size:var(--fs-xl);color:rgba(255,255,255,.55);"> 위</span></div>
                    </div>
                </div>
            </div>

            {{-- 지표 --}}
            <div class="grid grid-cols-3 divide-x" style="border-top:1px solid var(--color-hairline);">
                @php
                    $metrics = [
                        ['방문자 리뷰', $result['review_count']],
                        ['블로그 리뷰', $result['blog_review_count']],
                        [$result['save_count'] !== null ? '저장수' : '평점', $result['save_count'] !== null ? $result['save_count'] : $result['review_score']],
                    ];
                @endphp
                @foreach ($metrics as $mt)
                <div class="p-5 text-center" style="border-color:var(--color-hairline);">
                    <div class="text-muted" style="font-size:var(--fs-xs);">{{ $mt[0] }}</div>
                    <div class="font-display text-ink mt-1" style="font-size:var(--fs-xl);">{{ $mt[1] !== null ? (is_float($mt[1]) ? $mt[1] : number_format($mt[1])) : '—' }}</div>
                </div>
                @endforeach
            </div>

            @if (!empty($result['tags']))
            <div class="p-5" style="border-top:1px solid var(--color-hairline);">
                <div class="text-muted mb-2" style="font-size:var(--fs-xs);">대표 키워드</div>
                <div class="flex flex-wrap gap-2">
                    @foreach (array_slice($result['tags'], 0, 8) as $tag)
                    <span class="badge">{{ $tag }}</span>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
    @else
        {{-- 순위 밖 --}}
        <div class="card p-8 text-center">
            <div style="font-size:var(--fs-2xl);">🔍</div>
            <h2 class="mt-3 text-ink font-semibold" style="font-size:var(--fs-md);">300위 안에서 찾지 못했어요</h2>
            <p class="mt-2 text-muted" style="font-size:var(--fs-sm);line-height:1.6;">
                “{{ $keyword }}” 검색 결과 상위 300위 내에 노출되지 않았습니다.<br>
                업체명 대신 <b class="text-ink">플레이스 URL</b>로 조회하면 더 정확해요.
            </p>
            <a href="/#hero-form" class="btn btn-secondary mt-6">URL로 다시 조회</a>
        </div>
    @endif

    {{-- 전환 CTA (추적 유도) --}}
    <div class="card-soft text-center mt-8" style="padding:32px 24px;">
        <h3 class="font-display text-ink" style="font-size:var(--fs-lg);">이 순위, 매일 자동으로 추적할까요?</h3>
        <p class="mt-2 text-muted" style="font-size:var(--fs-xs);">회원가입하면 순위 변동을 그래프·알림으로 받아볼 수 있어요. (무료 100개)</p>
        <a href="/register" class="btn btn-primary mt-4">무료로 추적 시작</a>
    </div>
</section>
@endsection
