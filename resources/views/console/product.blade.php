@extends('console.layout')
@section('page-title', '상품 분석')

@section('console-content')
<div class="card-soft mb-6 px-4 py-3 text-muted" style="font-size:var(--fs-xs);">
    크롬 확장에서 스마트스토어 상품 페이지의 리뷰를 분석하면 자동으로 저장됩니다.
</div>

<form method="GET" class="card p-3 mb-4">
    <div class="flex items-center gap-2">
        <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
            @if ($q)<a href="{{ route('console.product') }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>@endif
            <input name="q" value="{{ $q }}" class="input" style="width:260px;font-size:var(--fs-xs);" placeholder="상품명 · 스토어 검색">
            <button type="submit" class="btn btn-primary btn-sm" style="height:36px;">검색</button>
        </div>
    </div>
</form>

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:900px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);">
                    <th class="text-left px-5 py-3 font-semibold" style="width:52px;">No</th>
                    <th class="text-left px-3 py-3 font-semibold">상품</th>
                    <th class="text-right px-3 py-3 font-semibold">전체 리뷰</th>
                    <th class="text-right px-3 py-3 font-semibold">평균 평점</th>
                    <th class="text-right px-3 py-3 font-semibold">재구매율</th>
                    <th class="text-right px-3 py-3 font-semibold">최근 1개월</th>
                    <th class="text-right px-3 py-3 font-semibold">6개월 판매량</th>
                    <th class="text-left px-3 py-3 font-semibold">분석 시각</th>
                    <th class="text-right px-5 py-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody>
                @php $rowNo = $analyses->total() - (($analyses->firstItem() ?? 1) - 1); @endphp
                @forelse ($analyses as $a)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-5 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $rowNo - $loop->index }}</td>
                        <td class="px-3 py-3" style="max-width:280px;">
                            <a href="{{ route('console.product.show', $a) }}" class="text-ink font-semibold hover:underline block truncate" style="font-size:var(--fs-xs);">{{ $a->name }}</a>
                            <div class="text-muted-soft truncate" style="font-size:var(--fs-xs);">{{ $a->store ?: '스마트스토어' }} · {{ number_format($a->analyzed_reviews) }}개 분석</div>
                        </td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format($a->total_reviews) }}</td>
                        <td class="px-3 py-3 text-right text-ink font-semibold" style="font-size:var(--fs-xs);">{{ number_format($a->avg_score, 2) }}</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format($a->repurchase_pct, 1) }}%</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format($a->recent_1m) }}건</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $a->sales_6m !== null ? number_format($a->sales_6m).'건' : '—' }}</td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $a->created_at->format('Y-m-d H:i') }}</td>
                        <td class="px-5 py-3 text-right text-nowrap">
                            <a href="{{ route('console.product.show', $a) }}" class="btn btn-ghost btn-sm">상세</a>
                            <form method="POST" action="{{ route('console.product.destroy', $a) }}" style="display:inline;" onsubmit="return confirm('이 분석 내역을 삭제하시겠습니까?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center" style="padding:56px 20px;color:var(--color-muted);">
                        저장된 상품 분석이 없습니다. 크롬 확장에서 스마트스토어 상품 리뷰를 분석해 보세요.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $analyses->links() }}</div>
@endsection
