@extends('admin.layout')
@section('page-title', '쇼핑 노출 키워드')

@section('admin-content')

<x-console.page-head title="쇼핑 노출 키워드 분석">
    <x-slot:desc>핵심 키워드와 상품 URL로 키워드를 추출·조합해, <b>어떤 검색어에서 내 상품이 쇼핑 상위 {{ $top }}위에 노출되는지</b> 찾아 제품명·태그·상세페이지 SEO 개선 근거로 씁니다.</x-slot:desc>
</x-console.page-head>

@error('core_keyword')<div class="card-soft px-4 py-3 mb-4 text-error" style="font-size:var(--fs-xs);">{{ $message }}</div>@enderror
@error('product')<div class="card-soft px-4 py-3 mb-4 text-error" style="font-size:var(--fs-xs);">{{ $message }}</div>@enderror

<form method="POST" action="{{ route('admin.shop-keyword.store') }}" class="card p-5 mb-5">
    @csrf
    <div class="flex gap-3 flex-wrap items-start mb-3">
        <div style="flex:1;min-width:220px;">
            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">핵심 키워드</label>
            <input name="core_keyword" class="input" value="{{ old('core_keyword') }}" placeholder="예: 비타민c" required autofocus>
        </div>
        <div style="flex:2;min-width:300px;">
            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">상품 URL(스마트스토어/가격비교) 또는 업체명</label>
            <input name="product" class="input" value="{{ old('product') }}" placeholder="https://smartstore.naver.com/.../products/123..." required autocomplete="off">
        </div>
        <div style="width:110px;">
            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">노출 기준(위)</label>
            <input type="number" name="threshold" class="input" value="{{ old('threshold', $top) }}" min="1" max="40">
        </div>
    </div>

    <div class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">
        브랜드·상품속성·연관·자동완성·어미 조합을 <b>자동으로 만들 수 있는 만큼 전부</b> 만들어 확인합니다 — 따로 입력할 것이 없습니다.
    </div>

    <div class="flex items-center justify-between flex-wrap gap-2">
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">순위는 <b>네이버 모바일 검색 가격비교(광고 제외) 노출 위치</b>입니다. 조합이 많으면 순위를 채우는 데 시간이 걸릴 수 있어요.</span>
        <button type="submit" class="btn btn-primary">분석하기</button>
    </div>
</form>

<div class="text-ink font-semibold mb-2" style="font-size:var(--fs-sm);">최근 분석</div>
@forelse ($analyses as $a)
    <a href="{{ route('admin.shop-keyword.show', $a) }}" class="card p-4 mb-2 flex items-center gap-3" style="text-decoration:none;">
        <div style="flex:1;min-width:0;">
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $a->core_keyword }}
                <span class="text-muted-soft font-normal" style="font-size:var(--fs-xs);">· {{ $a->product_id ? '상품 '.$a->product_id : ($a->mall_name ?: '대상 미상') }}</span>
            </div>
            <div class="text-muted" style="font-size:var(--fs-xs);">조합 {{ $a->combo_count }} · 확인 {{ $a->checked_count }} · 단축 URL {{ $a->short_links_count }} · <b class="text-ink">상위 {{ $a->threshold }}위 노출 {{ $a->exposed_count }}</b>
                @if ($a->status === 'blocked')<span class="text-error">· 일부 미확인(열면 이어서 확인)</span>
                @elseif ($a->status === 'paused')<span class="text-muted">· 중단됨("이어서 확인"으로 재개)</span>@endif
            </div>
        </div>
        <span class="font-mono text-muted-soft" style="font-size:var(--fs-xs);">{{ $a->created_at->timezone('Asia/Seoul')->format('m-d H:i') }}</span>
    </a>
@empty
    <div class="card-soft p-5 text-center text-muted-soft" style="font-size:var(--fs-xs);">아직 분석이 없습니다. 위에서 핵심 키워드와 상품 URL로 시작하세요.</div>
@endforelse

@endsection
