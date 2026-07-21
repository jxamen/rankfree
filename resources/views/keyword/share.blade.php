@extends('layouts.site')
@section('follow-theme', '1')
@section('og-type', 'article') {{-- 공개 색인 대상(1회성 키워드 분석) — noindex 없음 --}}

@php
    $__kw = $vm['keyword'];
    $__total = $vm['total'] ?? null;
    $__grade = $vm['grade'] ?? null;
    $__volTxt = $__total !== null ? '월 '.number_format($__total).'회' : '집계중';
    $__summary = "‘{$__kw}’ 키워드 검색량 {$__volTxt}"
        .($__grade ? " · 경쟁강도 {$__grade}" : '')
        .' — 성별·연령·검색 트렌드·콘텐츠 포화도까지 무료 분석 리포트.';

    // AEO 요약 답변 + FAQ(JSON-LD·화면 동일 문항) — 데이터 기반 결정적 템플릿(22 Phase 2)
    $__aeo = \App\Domain\Keyword\KeywordAnalysisPresenter::aeo($vm);
    $__faq = $__aeo['faq'];

    // meta/og/twitter description — 고정 문구 대신 실제 요약 답변(검색량·경쟁·타겟 수치 문장).
    // 검색량 집계 전엔 요약이 빈약하므로 기존 소개 문구($__summary)를 유지한다.
    $__desc = ! empty($vm['has_volume']) && ($__total ?? 0) > 0
        ? \App\Domain\Keyword\KeywordAnalysisPresenter::metaDescription($vm)
        : $__summary;

    $__rec = $record ?? null;
    $__cat = $__rec?->category;
    $__date = $__rec?->refreshed_at ?? $__rec?->updated_at;
    // 경로: 홈 › 키워드 인사이트 › {플레이스|쇼핑} › {카테고리} › {키워드}
    $__typeLabel = $__cat ? ($__cat->type === 'place' ? '플레이스' : '쇼핑') : null;
    $__crumbs = array_values(array_filter([
        ['name' => '키워드 인사이트', 'url' => route('keywords.index')],
        $__cat ? ['name' => $__typeLabel, 'url' => route('keywords.type', $__cat->type)] : null,
        $__cat ? ['name' => $__cat->name, 'url' => route('keywords.category', $__cat->slug)] : null,
    ]));
@endphp

@section('title', $__kw.' 키워드 검색량 분석 · 랭크프리')
@section('description', $__desc)

@include('partials.report-seo', ['seoTitle' => $__kw.' 키워드 분석', 'seoDesc' => $__desc, 'seoSection' => '키워드 분석', 'seoDate' => $__date, 'seoFaq' => $__faq, 'seoCrumbs' => $__crumbs])

@section('content')
{{-- 헤더 → 브레드크럼 16px, 브레드크럼 → 제목 블록 34px (keywords/type 과 동일 리듬) --}}
<section class="container-page" style="padding-top:16px;padding-bottom:80px;">
    {{-- 브레드크럼(가시) — 허브 페이지로의 내부 링크(BreadcrumbList JSON-LD 와 동일 경로) --}}
    <nav class="text-muted-soft" style="font-size:var(--fs-xs);margin-bottom:34px;" aria-label="브레드크럼">
        <a href="{{ url('/') }}" class="text-muted-soft" style="text-decoration:none;">홈</a>
        <span aria-hidden="true"> › </span>
        <a href="{{ route('keywords.index') }}" class="text-muted-soft" style="text-decoration:none;">키워드 인사이트</a>
        @if ($__cat)
            <span aria-hidden="true"> › </span>
            <a href="{{ route('keywords.type', $__cat->type) }}" class="text-muted-soft" style="text-decoration:none;">{{ $__typeLabel }}</a>
            <span aria-hidden="true"> › </span>
            <a href="{{ route('keywords.category', $__cat->slug) }}" class="text-muted-soft" style="text-decoration:none;">{{ $__cat->name }}</a>
        @endif
        <span aria-hidden="true"> › </span>
        <span class="text-ink">{{ $__kw }}</span>
    </nav>

    <div class="badge mb-4 border border-hairline">키워드 분석 리포트 · 랭크프리</div>
    {{-- 제목 라인에 네이버 통합검색(새 창) — 본문에서 키워드·버튼을 다시 그리지 않는다(제목 중복 방지).
         'N' 은 네이버 브랜드색이라 예외적으로 인라인 hex 사용(콘솔 검색폼과 동일). --}}
    <div class="flex items-center gap-3 flex-wrap">
        <h1 class="font-display text-ink" style="font-size:clamp(24px,4vw,34px);line-height:1.2;">{{ $__kw }} 키워드 분석</h1>
        <a href="https://search.naver.com/search.naver?query={{ urlencode($__kw) }}" target="_blank" rel="noopener"
           class="btn btn-secondary btn-sm inline-flex items-center gap-1" title="「{{ $__kw }}」 통합검색 (새 창)">
            <span style="color:#03c75a;font-weight:800;font-size:var(--fs-xs);">N</span> 통합검색
        </a>
    </div>
    <p class="text-muted" style="margin-top:8px;font-size:var(--fs-sm);line-height:1.6;">{{ $__summary }}</p>

    {{-- AEO 요약 답변 — 답변엔진·생성엔진이 인용할 완결형 수치 문장 + 출처·기준일(GEO) --}}
    <div class="card p-5" style="margin-top:20px;" data-aeo-summary>
        <div class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:600;">요약 답변</div>
        <p class="text-ink" style="margin-top:6px;font-size:var(--fs-sm);line-height:1.75;">{{ $__aeo['summary'] }}</p>
        <p class="text-muted-soft" style="margin-top:10px;font-size:var(--fs-xs);line-height:1.6;">
            출처: 검색광고·데이터랩 데이터 기반 랭크프리 자체 집계{{ $__date ? ' · '.$__date->format('Y년 n월 j일').' 기준' : '' }} · 등급·상업성 등 파생 지표는 자체 추정치입니다.
        </p>
    </div>

    {{-- AI 인사이트(선택 보강) — 발행 시점 실측 데이터 근거로 생성·저장된 해석(AI 생성 표기) --}}
    @if (! empty($aiInsight['text']))
        <div class="card p-5" style="margin-top:12px;" data-ai-insight>
            <div class="flex items-center gap-2">
                <span class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:600;">AI 인사이트</span>
                <span class="badge border border-hairline" style="font-size:var(--fs-xs);">AI 생성</span>
            </div>
            <p class="text-ink" style="margin-top:6px;font-size:var(--fs-sm);line-height:1.75;">{{ $aiInsight['text'] }}</p>
            <p class="text-muted-soft" style="margin-top:8px;font-size:var(--fs-xs);">위 실측 데이터를 근거로 AI가 작성한 해석이며 참고용입니다{{ ! empty($aiInsight['generated_at']) ? ' · '.\Illuminate\Support\Carbon::parse($aiInsight['generated_at'])->format('Y-m-d').' 생성' : '' }}.</p>
        </div>
    @endif

    {{-- 요약 답변(·AI 인사이트) 과 본문(자동완성 카드부터) 사이 간격 — 요약 블록과 데이터 블록의 경계 --}}
    <div style="margin-top:32px;">
    @include('partials._keyword_body', [
        'vm' => $vm,
        'saturation' => $saturation,
        'popular' => $popular,
        'weekday' => $weekday,
        'autocomplete' => $autocomplete ?? [],
        'public' => true,
        'shareUrl' => null,
    ])
    </div>

    {{-- 자주 묻는 질문(가시 FAQ) — FAQPage JSON-LD 와 동일 문항(AEO) --}}
    @if (count($__faq))
        <div style="margin-top:48px;" data-faq>
            <h2 class="font-display text-ink" style="font-size:var(--fs-xl);line-height:1.3;">자주 묻는 질문</h2>
            <div class="flex flex-col gap-3" style="margin-top:12px;">
                @foreach ($__faq as $__f)
                    <div class="card p-5">
                        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $__f['q'] }}</div>
                        <p class="text-muted" style="margin-top:6px;font-size:var(--fs-sm);line-height:1.7;">{{ $__f['a'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @include('partials.related-docs', ['related' => $related ?? []])

    {{-- 퍼널 CTA — 무료 분석·추적 시작 --}}
    <div class="card p-6 text-center" style="margin-top:40px;" data-cta>
        <div class="font-display text-ink" style="font-size:var(--fs-lg);line-height:1.35;">'{{ $__kw }}' 순위, 매일 자동으로 추적해 보세요</div>
        <p class="text-muted" style="margin-top:6px;font-size:var(--fs-sm);">플레이스·쇼핑 순위추적과 키워드·시장 분석을 무료로 시작할 수 있습니다.</p>
        <a href="{{ auth()->check() ? route('console.dashboard') : route('register') }}" class="btn btn-primary" style="margin-top:14px;">무료로 시작</a>
    </div>
</section>
@endsection
