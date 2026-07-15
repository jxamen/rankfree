@extends('console.layout')
@section('page-title', '쇼핑 시장 분석')

@section('console-content')
@php
    $won = fn ($n) => $n >= 100000000
        ? number_format($n / 100000000, 1).'억'
        : ($n >= 10000 ? number_format($n / 10000).'만' : number_format($n));
@endphp

<x-console.page-head title="쇼핑 시장 분석" desc="네이버 쇼핑 검색 페이지에서 크롬 확장으로 실행한 시장 분석이 자동 저장됩니다 · <b>6개월 시장규모·매출·판매량·평균가</b>" />

<form method="GET" class="card p-3 mb-4">
    <div class="flex items-center gap-2">
        <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
            @if ($q)<a href="{{ route('console.market') }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>@endif
            <input name="q" value="{{ $q }}" class="input" style="width:260px;font-size:var(--fs-xs);" placeholder="키워드 검색">
            <button type="submit" class="btn btn-primary btn-sm" style="height:36px;">검색</button>
        </div>
    </div>
</form>

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:880px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);">
                    <th class="text-left px-5 py-3 font-semibold" style="width:52px;">No</th>
                    <th class="text-left px-3 py-3 font-semibold">키워드</th>
                    <th class="text-right px-3 py-3 font-semibold">6개월 시장규모</th>
                    <th class="text-right px-3 py-3 font-semibold">월평균 매출</th>
                    <th class="text-right px-3 py-3 font-semibold">6개월 판매량</th>
                    <th class="text-right px-3 py-3 font-semibold">평균가</th>
                    <th class="text-right px-3 py-3 font-semibold">월 검색량</th>
                    <th class="text-left px-3 py-3 font-semibold">분석 시각</th>
                    <th class="text-right px-5 py-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody>
                @php $rowNo = $analyses->total() - (($analyses->firstItem() ?? 1) - 1); @endphp
                @forelse ($analyses as $a)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-5 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $rowNo - $loop->index }}</td>
                        <td class="px-3 py-3">
                            <a href="{{ route('console.market.show', $a) }}" class="text-ink font-semibold hover:underline" style="font-size:var(--fs-xs);">{{ $a->keyword }}</a>
                            <div class="text-muted-soft" style="font-size:var(--fs-xs);">상품 {{ number_format($a->item_count) }}개 분석 · 전체 {{ number_format($a->total_count) }}개</div>
                        </td>
                        <td class="px-3 py-3 text-right text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $won($a->revenue_6m) }}원</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $won((int) round($a->revenue_6m / 6)) }}원</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format($a->sales_6m) }}건</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format($a->avg_price) }}원</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">
                            {{ $a->monthly_search !== null ? number_format($a->monthly_search) : '—' }}
                            @if ($a->comp_idx)<span class="text-muted-soft" style="font-size:var(--fs-xs);">({{ $a->comp_idx }})</span>@endif
                        </td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $a->created_at->format('Y-m-d H:i') }}</td>
                        <td class="px-5 py-3 text-right text-nowrap">
                            <a href="{{ route('console.market.show', $a) }}" class="btn btn-ghost btn-sm">상세</a>
                            <form method="POST" action="{{ route('console.market.destroy', $a) }}" style="display:inline;" onsubmit="return confirm('이 분석 내역을 삭제하시겠습니까?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center" style="padding:56px 20px;color:var(--color-muted);">
                        저장된 분석이 없습니다. 크롬 확장에서 시장 분석을 실행해 보세요.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $analyses->links() }}</div>
@endsection
