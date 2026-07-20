@extends('console.layout')
@section('page-title', '쇼핑 노출 키워드')

@section('console-content')

<x-console.page-head title="쇼핑 노출 키워드 분석">
    <x-slot:desc>핵심 키워드와 상품 URL로 키워드를 추출·조합해, <b>어떤 검색어에서 내 상품이 쇼핑 상위 {{ $top }}위에 노출되는지</b> 찾아 제품명·태그·상세페이지 SEO 개선 근거로 씁니다.</x-slot:desc>
</x-console.page-head>

@error('core_keyword')<div class="card-soft px-4 py-3 mb-4 text-error" style="font-size:var(--fs-xs);">{{ $message }}</div>@enderror
@error('product')<div class="card-soft px-4 py-3 mb-4 text-error" style="font-size:var(--fs-xs);">{{ $message }}</div>@enderror

<form method="POST" action="{{ route('console.shop-keyword.store') }}" class="card p-5 mb-5">
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
        <div style="width:130px;">
            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">조합 수</label>
            <select name="target_combos" class="input">
                @foreach ([30, 50, 80, 100] as $n)
                    <option value="{{ $n }}" @selected((int) old('target_combos', $defaultCombos) === $n)>{{ $n }}개</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="mb-3">
        <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">추가 어미/수식어 <span class="text-muted-soft">(선택 · 콤마 또는 줄바꿈 구분)</span></label>
        <input name="suffixes" class="input" value="{{ old('suffixes') }}" placeholder="예: 추천, 인기, 무료배송, 정품, 가성비 …">
        <div class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">기본 어미(추천·인기·무료배송·정품·최저가·가성비 등 {{ count((array) config('rankfree.shopping.exposure.suffixes', [])) }}개)에 여기 입력한 어미가 더해져 <b>“{핵심} {어미}”</b> 조합을 만듭니다.</div>
    </div>

    <details class="mb-3">
        <summary class="text-muted" style="font-size:var(--fs-xs);cursor:pointer;">쇼핑 필터 HTML 붙여넣기 (선택) — 브랜드·키워드추천·상품속성 추출</summary>
        <div class="text-muted-soft mt-2 mb-2" style="font-size:var(--fs-xs);">
            네이버 쇼핑 검색결과 페이지의 브랜드/키워드추천/속성 영역 HTML을 붙여넣으면 그 값들도 조합 후보에 넣어 노출을 확인합니다.
            (서버가 쇼핑 페이지를 직접 못 읽어 붙여넣기로 받습니다 — 없어도 자동완성·연관·함께 많이 찾는은 자동 추출됩니다.)
        </div>
        <textarea name="filter_html" class="input" rows="4" placeholder="<ul class=&quot;basicTypeFilter_...&quot;>… 또는 <div class=&quot;product_detail_box__...&quot;>…" style="font-family:var(--font-mono);font-size:var(--fs-xs);">{{ old('filter_html') }}</textarea>
    </details>

    <div class="flex items-center justify-between flex-wrap gap-2">
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">순위는 네이버 쇼핑 검색(sort=sim) 기준 <b>추정치</b>입니다. 조합이 많으면 시간이 걸릴 수 있어요.</span>
        <button type="submit" class="btn btn-primary">분석하기</button>
    </div>
</form>

<div class="text-ink font-semibold mb-2" style="font-size:var(--fs-sm);">최근 분석</div>
@forelse ($analyses as $a)
    <a href="{{ route('console.shop-keyword.show', $a) }}" class="card p-4 mb-2 flex items-center gap-3" style="text-decoration:none;">
        <div style="flex:1;min-width:0;">
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $a->core_keyword }}
                <span class="text-muted-soft font-normal" style="font-size:var(--fs-xs);">· {{ $a->product_id ? '상품 '.$a->product_id : ($a->mall_name ?: '대상 미상') }}</span>
            </div>
            <div class="text-muted" style="font-size:var(--fs-xs);">조합 {{ $a->combo_count }} · 확인 {{ $a->checked_count }} · <b class="text-ink">상위 {{ $a->threshold }}위 노출 {{ $a->exposed_count }}</b>
                @if ($a->status === 'blocked')<span class="text-error">· API 한도로 일부 미확인</span>@endif
            </div>
        </div>
        <span class="font-mono text-muted-soft" style="font-size:var(--fs-xs);">{{ $a->created_at->timezone('Asia/Seoul')->format('m-d H:i') }}</span>
    </a>
@empty
    <div class="card-soft p-5 text-center text-muted-soft" style="font-size:var(--fs-xs);">아직 분석이 없습니다. 위에서 핵심 키워드와 상품 URL로 시작하세요.</div>
@endforelse

@endsection
