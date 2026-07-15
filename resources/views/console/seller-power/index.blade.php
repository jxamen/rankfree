@extends('console.layout')
@section('page-title', '셀러력')

@section('console-content')
@php
    $gc = fn ($g) => match ($g) {
        'S' => 'var(--color-success)', 'A' => 'var(--color-accent)', 'B' => 'var(--color-badge-violet)',
        'C' => 'var(--color-warning)', default => 'var(--color-muted)',
    };
@endphp

<x-console.page-head title="셀러력" desc="크롬 확장에서 <b>내 상품 페이지</b>의 셀러력을 분석하면 같은 키워드 <b>검색 상위 10개 경쟁 상품</b>과 비교해 저장됩니다 · 점수·등급은 관측 신호 기반 <b>자체 추정치</b>(네이버 공식 아님)" />

<form method="GET" class="card p-3 mb-4">
    <div class="flex items-center gap-2">
        <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
            @if ($q)<a href="{{ route('console.seller-power') }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>@endif
            <input name="q" value="{{ $q }}" class="input" style="width:260px;font-size:var(--fs-xs);" placeholder="키워드 · 상품명 검색">
            <button type="submit" class="btn btn-primary btn-sm" style="height:36px;">검색</button>
        </div>
    </div>
</form>

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:920px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);">
                    <th class="text-left px-5 py-3 font-semibold" style="width:52px;">No</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:150px;">키워드</th>
                    <th class="text-left px-3 py-3 font-semibold">상품</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:72px;">점수</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:64px;">등급</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:100px;">시장 상위</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:110px;">경쟁 순위</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:150px;">분석 시각</th>
                    <th class="text-right px-5 py-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody>
                @php $rowNo = $analyses->total() - (($analyses->firstItem() ?? 1) - 1); @endphp
                @forelse ($analyses as $a)
                    @php $c = $gc($a->grade); @endphp
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-5 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $rowNo - $loop->index }}</td>
                        <td class="px-3 py-3">
                            <span class="badge" style="font-size:var(--fs-xs);padding:1px 8px;background:var(--color-surface-soft);">{{ $a->keyword }}</span>
                        </td>
                        <td class="px-3 py-3">
                            <a href="{{ route('console.seller-power.show', $a) }}" class="text-ink font-semibold hover:underline" style="font-size:var(--fs-xs);display:block;max-width:460px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $a->product_name ?: $a->store_id }}</a>
                        </td>
                        <td class="px-3 py-3 text-right font-display" style="font-size:var(--fs-base);color:{{ $c }};">{{ round($a->score) }}</td>
                        <td class="px-3 py-3 text-center">
                            <span class="badge" style="font-size:var(--fs-xs);padding:1px 8px;background:color-mix(in srgb,{{ $c }} 14%,var(--color-canvas));color:{{ $c }};font-weight:800;">{{ $a->grade }}</span>
                        </td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">상위 {{ $a->market_percentile }}%</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $a->rank_in_top }} / {{ $a->competitor_count + 1 }}위</td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $a->updated_at->format('Y-m-d H:i') }}</td>
                        <td class="px-5 py-3 text-right text-nowrap">
                            <a href="{{ route('console.seller-power.show', $a) }}" class="btn btn-ghost btn-sm">상세</a>
                            <form method="POST" action="{{ route('console.seller-power.destroy', $a) }}" style="display:inline;" onsubmit="return confirm('이 분석 내역을 삭제하시겠습니까?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center" style="padding:56px 20px;color:var(--color-muted);">
                        저장된 셀러력 분석이 없습니다. 크롬 확장에서 상품 페이지를 열고 셀러력을 분석해 보세요.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $analyses->links() }}</div>
@endsection
