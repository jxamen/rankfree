@extends('layouts.site')

@section('title', $keyword.' 쇼핑 순위 결과 — 랭크프리')

@section('content')
<section class="container-page py-16 lg:py-20" style="max-width:760px;">
    <a href="/#hero-form" class="text-muted hover:text-ink transition" style="font-size:var(--fs-xs);">← 다시 조회</a>

    <div class="mt-4 mb-8">
        <div class="badge mb-3">쇼핑 순위 조회 결과</div>
        <h1 class="font-display text-ink" style="font-size:clamp(26px,3.5vw,36px);line-height:1.15;">“{{ $keyword }}”</h1>
        <p class="mt-2 text-muted" style="font-size:var(--fs-sm);">대상 · {{ $result['title'] ?: ($result['mall_name'] ?: $target) }}</p>
    </div>

    @if (($result['error'] ?? '') === 'no_api_keys')
        <div class="card p-8 text-center">
            <div style="font-size:var(--fs-2xl);">🛠</div>
            <h2 class="mt-3 text-ink font-semibold" style="font-size:var(--fs-md);">쇼핑 순위 조회를 준비 중입니다</h2>
            <p class="mt-2 text-muted" style="font-size:var(--fs-sm);line-height:1.6;">잠시 후 다시 시도해 주세요.</p>
            <a href="/#hero-form" class="btn btn-secondary mt-6">다시 조회</a>
        </div>
    @elseif (in_array($result['error'] ?? '', ['empty_keyword', 'invalid_target'], true))
        <div class="card p-8 text-center">
            <div style="font-size:var(--fs-2xl);">🔍</div>
            <h2 class="mt-3 text-ink font-semibold" style="font-size:var(--fs-md);">입력을 다시 확인해 주세요</h2>
            <p class="mt-2 text-muted" style="font-size:var(--fs-sm);line-height:1.6;">
                키워드와 <b class="text-ink">상품 URL·상품ID</b> 또는 <b class="text-ink">스토어명</b>을 정확히 입력하면 조회됩니다.
            </p>
            <a href="/#hero-form" class="btn btn-secondary mt-6">다시 조회</a>
        </div>
    @elseif ($result['blocked'] || ($result['error'] ?? '') !== '')
        <div class="card p-8 text-center">
            <div style="font-size:var(--fs-2xl);">⏳</div>
            <h2 class="mt-3 text-ink font-semibold" style="font-size:var(--fs-md);">지금은 순위를 불러올 수 없어요</h2>
            <p class="mt-2 text-muted" style="font-size:var(--fs-sm);line-height:1.6;">네이버 조회가 일시적으로 제한됐습니다. 잠시 후 다시 시도해 주세요.</p>
            <a href="/#hero-form" class="btn btn-secondary mt-6">다시 조회</a>
        </div>
    @elseif ($result['found'])
        <div class="card overflow-hidden" style="box-shadow:var(--shadow-card);">
            <div class="card-soft flex items-center justify-between p-6" style="border-radius:0;">
                <div style="min-width:0;">
                    <div class="text-muted" style="font-size:var(--fs-xs);">현재 쇼핑 순위</div>
                    <div class="text-ink font-semibold mt-1 truncate" style="font-size:var(--fs-base);max-width:420px;">{{ $result['title'] ?: ($result['mall_name'] ?: $target) }}</div>
                    <div class="text-muted-soft mt-0.5" style="font-size:var(--fs-xs);">
                        @if ($result['mall_name']){{ $result['mall_name'] }} · @endif총 {{ number_format($result['total']) }}개 노출
                    </div>
                </div>
                <div class="font-display text-ink flex-none" style="font-size:var(--fs-3xl);line-height:1;">{{ $result['rank'] }}<span class="text-muted" style="font-size:var(--fs-lg);">위</span></div>
            </div>

            <div class="grid grid-cols-2 divide-x" style="border-top:1px solid var(--color-hairline);">
                <div class="p-5 text-center" style="border-color:var(--color-hairline);">
                    <div class="text-muted" style="font-size:var(--fs-xs);">판매가</div>
                    <div class="font-display text-ink mt-1" style="font-size:var(--fs-xl);">{{ $result['price'] ? number_format($result['price']).'원' : '—' }}</div>
                </div>
                <div class="p-5 text-center" style="border-color:var(--color-hairline);">
                    <div class="text-muted" style="font-size:var(--fs-xs);">상품</div>
                    <div class="mt-1" style="font-size:var(--fs-sm);">
                        @if ($result['link'])<a href="{{ $result['link'] }}" target="_blank" rel="noopener" class="text-accent hover:underline">상품 보기 →</a>@else<span class="text-muted-soft">—</span>@endif
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="card p-8 text-center">
            <div style="font-size:var(--fs-2xl);">🔍</div>
            <h2 class="mt-3 text-ink font-semibold" style="font-size:var(--fs-md);">상위 {{ number_format($result['total'] ?: 1000) }}위 안에서 찾지 못했어요</h2>
            <p class="mt-2 text-muted" style="font-size:var(--fs-sm);line-height:1.6;">
                “{{ $keyword }}” 쇼핑 검색 상위 노출에서 발견되지 않았습니다.<br>
                스토어명 대신 <b class="text-ink">상품 URL·상품ID</b>로 조회하면 더 정확해요.
            </p>
            <a href="/#hero-form" class="btn btn-secondary mt-6">URL로 다시 조회</a>
        </div>
    @endif

    {{-- 전환 CTA --}}
    <div class="card-soft text-center mt-8" style="padding:32px 24px;">
        <h3 class="font-display text-ink" style="font-size:var(--fs-lg);">이 순위, 매일 자동으로 추적할까요?</h3>
        <p class="mt-2 text-muted" style="font-size:var(--fs-xs);">회원가입하면 쇼핑·플레이스 순위 변동을 그래프·알림으로 받아볼 수 있어요.</p>
        <a href="/register" class="btn btn-primary mt-4">무료로 추적 시작</a>
    </div>
</section>
@endsection
