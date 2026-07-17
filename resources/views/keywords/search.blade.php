@extends('layouts.site')
@section('follow-theme', '1')

{{-- 검색 결과 — 쿼리 조합이 무한이라 색인하면 얇은 페이지가 양산된다. 링크는 따라가게 follow 유지
     (결과 카드·매칭 카테고리를 통해 색인 자산인 /keyword/{slug}·/keywords/{slug} 로 크롤을 넘긴다). --}}
@section('robots', 'noindex, follow')
{{-- canonical 자기참조 — 기본값 url()->current() 는 쿼리를 버려 '내용이 다른 URL'을 정규 URL 로 지목한다.
     route() 로 q·type 만 실어 정규화(utm 등 잡파라미터가 canonical 에 실리지 않게). --}}
@section('canonical', route('keywords.search', array_filter(['type' => $type, 'q' => $q !== '' ? $q : null])))

@section('title', ($q !== '' ? "‘{$q}’ 검색 결과" : '키워드 검색').' · 키워드 인사이트 · 랭크프리')
@section('description', ($q !== '' ? "‘{$q}’" : '키워드').' 네이버 검색량·경쟁·트렌드 분석 리포트 검색 결과입니다.')

@section('content')
<section class="container-page" style="padding-top:48px;padding-bottom:80px;">
    <nav class="text-muted-soft" style="font-size:var(--fs-xs);margin-bottom:14px;" aria-label="브레드크럼">
        <a href="{{ url('/') }}" class="text-muted-soft" style="text-decoration:none;">홈</a>
        <span aria-hidden="true"> › </span>
        <a href="{{ route('keywords.index') }}" class="text-muted-soft" style="text-decoration:none;">키워드 인사이트</a>
        @if ($type)
            <span aria-hidden="true"> › </span>
            <a href="{{ route('keywords.type', $type) }}" class="text-muted-soft" style="text-decoration:none;">{{ $typeLabel }}</a>
        @endif
        <span aria-hidden="true"> › </span>
        <span class="text-ink">검색</span>
    </nav>

    <h1 class="font-display text-ink" style="font-size:clamp(24px,4vw,34px);line-height:1.2;">
        {{ $q !== '' ? "‘{$q}’ 검색 결과" : '키워드 검색' }}
    </h1>
    <p class="text-muted" style="margin-top:6px;font-size:var(--fs-sm);">
        리포트 <b class="font-mono text-ink">{{ number_format($docs->total()) }}</b>건{{ $typeLabel ? ' · '.$typeLabel : '' }}
    </p>

    @include('keywords._searchbar', ['active' => $type ?? '', 'q' => $q, 'big' => false])

    {{-- 매칭 카테고리 — 결과 리스트 위. 검색 트래픽을 색인 자산(카테고리 페이지)으로 되돌리는 깔때기 --}}
    @if ($matchedCats->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2" style="margin-top:24px;">
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">카테고리</span>
            @foreach ($matchedCats as $c)
                <a href="{{ route('keywords.category', $c->slug) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;">
                    {{ $c->type === 'place' ? '플레이스' : '쇼핑' }} · {{ $c->name }} <span class="font-mono">{{ number_format($c->docs_count) }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3" style="margin-top:24px;">
        @forelse ($docs as $d)
            <a href="{{ $d->shareUrl() }}" class="card p-4" style="display:block;text-decoration:none;">
                @if ($d->category)
                    <span class="badge border border-hairline" style="font-size:var(--fs-xs);">{{ $d->category->type === 'place' ? '플레이스' : '쇼핑' }}</span>
                @endif
                <div class="text-ink font-semibold" style="margin-top:8px;font-size:var(--fs-sm);line-height:1.45;">{{ $d->keyword }} 키워드 분석</div>
                <div class="text-muted font-mono" style="margin-top:4px;font-size:var(--fs-xs);">
                    월 {{ number_format((int) $d->monthly_total) }}회{{ $d->comp_idx ? ' · 경쟁 '.$d->comp_idx : '' }}{{ $d->grade ? ' · '.$d->grade.'등급' : '' }}
                </div>
            </a>
        @empty
            <div class="card sm:col-span-2 lg:col-span-3" style="padding:40px;text-align:center;">
                <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">
                    {{ $q !== '' ? "‘{$q}’ 리포트가 아직 없습니다." : '검색어를 입력해 주세요.' }}
                </div>
                <p class="text-muted" style="margin-top:6px;font-size:var(--fs-xs);line-height:1.6;">
                    회원가입하면 원하는 키워드를 직접 분석할 수 있습니다. 분석한 키워드는 검색량·경쟁·성별연령·트렌드 리포트로 저장됩니다.
                </p>
                <a href="{{ auth()->check() ? route('console.dashboard') : route('register') }}" class="btn btn-primary btn-sm" style="margin-top:12px;">무료로 분석하기</a>
            </div>
        @endforelse
    </div>

    <div style="margin-top:16px;">{{ $docs->links() }}</div>

    @if ($fallbackDocs->isNotEmpty())
        <div style="margin-top:40px;">
            <h2 class="font-display text-ink" style="font-size:var(--fs-lg);line-height:1.3;">인기 키워드 리포트</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3" style="margin-top:12px;">
                @foreach ($fallbackDocs as $d)
                    <a href="{{ $d->shareUrl() }}" class="card p-4" style="display:block;text-decoration:none;">
                        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $d->keyword }} 키워드 분석</div>
                        <div class="text-muted font-mono" style="margin-top:4px;font-size:var(--fs-xs);">월 {{ number_format((int) $d->monthly_total) }}회</div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</section>
@endsection
