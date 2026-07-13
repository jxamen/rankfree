@extends('admin.layout')
@section('page-title', '마케팅 상품')

@section('page-actions')
    <a href="{{ route('admin.products.create') }}" class="btn btn-primary btn-sm">＋ 새 상품</a>
@endsection

@section('admin-content')
@if (session('status'))
    <div class="card-soft px-4 py-3 mb-4 text-muted" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:820px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-left px-5 py-3 font-semibold">상품명</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:120px;">유형</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:110px;">단가</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:70px;">필드</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:70px;">주문</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:80px;">노출</th>
                    <th class="text-right px-5 py-3 font-semibold" style="width:200px;">작업</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $p)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-5 py-3">
                            <a href="{{ route('admin.products.edit', $p) }}" class="text-ink font-medium hover:underline" style="font-size:var(--fs-xs);">{{ $p->title }}</a>
                            <div class="text-muted-soft" style="font-size:var(--fs-xs);">주문 URL: <a href="{{ $p->orderUrl() }}" target="_blank" class="text-accent hover:underline">/order/{{ Str::limit($p->order_token, 10, '…') }}</a></div>
                        </td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $types[$p->product_type]->name ?? $p->product_type }}</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format($p->min_price) }}원</td>
                        <td class="px-3 py-3 text-center text-muted" style="font-size:var(--fs-xs);">{{ $p->fields_count }}</td>
                        <td class="px-3 py-3 text-center text-muted" style="font-size:var(--fs-xs);">{{ $p->orders_count }}</td>
                        <td class="px-3 py-3 text-center">
                            <form method="POST" action="{{ route('admin.products.toggle', $p) }}" class="inline">@csrf
                                <button type="submit" class="badge" style="font-size:var(--fs-xs);padding:2px 9px;cursor:pointer;color:{{ $p->is_active ? 'var(--color-success)' : 'var(--color-muted)' }};">{{ $p->is_active ? '노출' : '숨김' }}</button>
                            </form>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <button type="button" class="btn btn-ghost btn-sm rf-copy" data-url="{{ url($p->orderUrl()) }}" title="주문 URL 복사">URL복사</button>
                            <a href="{{ route('admin.products.edit', $p) }}" class="btn btn-secondary btn-sm">수정</a>
                            <form method="POST" action="{{ route('admin.products.destroy', $p) }}" class="inline" onsubmit="return confirm('삭제할까요? 주문 내역도 함께 삭제됩니다.')">@csrf @method('DELETE')
                                <button type="submit" class="text-muted-soft hover:text-ink" style="font-size:var(--fs-xs);text-decoration:underline;margin-left:6px;">삭제</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center" style="padding:40px;color:var(--color-muted);font-size:var(--fs-xs);">아직 상품이 없습니다. 우측 상단 "＋ 새 상품"으로 만드세요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $products->links() }}</div>

<script>
document.querySelectorAll('.rf-copy').forEach(function (b) {
    b.addEventListener('click', function () {
        navigator.clipboard && navigator.clipboard.writeText(b.dataset.url);
        var o = b.textContent; b.textContent = '복사됨 ✓';
        setTimeout(function () { b.textContent = o; }, 1200);
    });
});
</script>
@endsection
