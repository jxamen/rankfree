@extends('layouts.site')

@section('title', $keyword.' 순위 결과 — rankfree')

@section('content')
<section class="container-page py-16 lg:py-20" style="max-width:760px;">
    <a href="/#hero-form" class="text-muted hover:text-ink transition" style="font-size:14px;">← 다시 조회</a>

    <div class="mt-4 mb-8">
        <div class="badge mb-3">순위 조회 결과</div>
        <h1 class="font-display text-ink" style="font-size:clamp(26px,3.5vw,36px);line-height:1.15;">
            “{{ $keyword }}”
        </h1>
        <p class="mt-2 text-muted" style="font-size:15px;">대상 · {{ $result['place_name'] ?: $place }}</p>
    </div>

    @if ($result['blocked'])
        {{-- 차단/토큰 만료 --}}
        <div class="card p-8 text-center">
            <div style="font-size:32px;">⏳</div>
            <h2 class="mt-3 text-ink font-semibold" style="font-size:18px;">지금은 순위를 불러올 수 없어요</h2>
            <p class="mt-2 text-muted" style="font-size:15px;line-height:1.6;">
                네이버 조회가 일시적으로 제한됐습니다. 잠시 후 다시 시도해 주세요.
            </p>
            <a href="/#hero-form" class="btn btn-secondary mt-6">다시 조회</a>
        </div>
    @elseif ($result['found'])
        {{-- 순위 발견 --}}
        <div class="card overflow-hidden" style="box-shadow:var(--shadow-card);">
            <div class="card-soft flex items-center justify-between p-6" style="border-radius:0;">
                <div>
                    <div class="text-muted" style="font-size:13px;">현재 순위</div>
                    <div class="text-ink font-semibold mt-1" style="font-size:16px;">{{ $result['place_name'] ?: $place }}</div>
                    <div class="text-muted-soft mt-0.5" style="font-size:12px;">{{ ['place'=>'플레이스','restaurant'=>'음식점','hospital'=>'병원','hairshop'=>'미용','nailshop'=>'네일','accommodation'=>'숙박'][$result['category']] ?? '플레이스' }} · 총 {{ number_format($result['list_total']) }}개 노출</div>
                </div>
                <div class="font-display text-ink" style="font-size:52px;line-height:1;">{{ $result['rank'] }}<span class="text-muted" style="font-size:20px;">위</span></div>
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
                    <div class="text-muted" style="font-size:12px;">{{ $mt[0] }}</div>
                    <div class="font-display text-ink mt-1" style="font-size:22px;">{{ $mt[1] !== null ? (is_float($mt[1]) ? $mt[1] : number_format($mt[1])) : '—' }}</div>
                </div>
                @endforeach
            </div>

            @if (!empty($result['tags']))
            <div class="p-5" style="border-top:1px solid var(--color-hairline);">
                <div class="text-muted mb-2" style="font-size:12px;">대표 키워드</div>
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
            <div style="font-size:32px;">🔍</div>
            <h2 class="mt-3 text-ink font-semibold" style="font-size:18px;">300위 안에서 찾지 못했어요</h2>
            <p class="mt-2 text-muted" style="font-size:15px;line-height:1.6;">
                “{{ $keyword }}” 검색 결과 상위 300위 내에 노출되지 않았습니다.<br>
                업체명 대신 <b class="text-ink">플레이스 URL</b>로 조회하면 더 정확해요.
            </p>
            <a href="/#hero-form" class="btn btn-secondary mt-6">URL로 다시 조회</a>
        </div>
    @endif

    {{-- 전환 CTA (추적 유도) --}}
    <div class="card-soft text-center mt-8" style="padding:32px 24px;">
        <h3 class="font-display text-ink" style="font-size:20px;">이 순위, 매일 자동으로 추적할까요?</h3>
        <p class="mt-2 text-muted" style="font-size:14px;">회원가입하면 순위 변동을 그래프·알림으로 받아볼 수 있어요. (무료 100개)</p>
        <a href="/register" class="btn btn-primary mt-4">무료로 추적 시작</a>
    </div>
</section>
@endsection
