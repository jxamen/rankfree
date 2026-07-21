@extends('admin.layout')
@section('page-title', '마케팅 상품')

@section('page-actions')
    <a href="{{ route('admin.products.create') }}" class="btn btn-primary btn-sm">＋ 새 상품</a>
@endsection

@section('admin-content')
<x-console.page-head title="마케팅 상품" desc="셀프마케팅 카탈로그에 노출되는 상품 등록·관리 · 주문 입력 필드와 단가를 구성합니다" />

@if (session('status'))
    <div class="card-soft px-4 py-3 mb-4 text-muted" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif

{{-- 검색 — 유형별 + 상품명 --}}
<form method="GET" class="card p-3 mb-4">
    <div class="flex items-center flex-wrap gap-2">
        <select name="type" class="input" style="width:160px;font-size:var(--fs-xs);">
            <option value="">전체 유형</option>
            @foreach ($types as $code => $t)
                <option value="{{ $code }}" @selected($type === $code)>{{ $t->name }}</option>
            @endforeach
        </select>
        @if ($q || $type)
            <a href="{{ route('admin.products') }}" class="btn btn-ghost btn-sm">초기화</a>
        @endif
        <input name="q" value="{{ $q }}" class="input" style="width:260px;font-size:var(--fs-xs);margin-left:auto;" placeholder="상품명 검색">
    </div>
</form>

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:820px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-center px-3 py-3 font-semibold" style="width:44px;">No</th>
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
                        <td class="px-3 py-3 text-center text-muted-soft" style="font-size:var(--fs-xs);">{{ $products->firstItem() + $loop->index }}</td>
                        <td class="px-5 py-3">
                            <a href="{{ route('admin.products.edit', $p) }}" class="text-ink font-medium hover:underline" style="font-size:var(--fs-xs);">{{ $p->title }}</a>
                            <div class="text-muted-soft" style="font-size:var(--fs-xs);">주문 URL: <a href="{{ $p->orderUrl() }}" target="_blank" class="text-accent hover:underline">/order/{{ Str::limit($p->order_token, 10, '…') }}</a></div>
                        </td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $types[$p->product_type]->name ?? $p->product_type }}</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format($p->min_price) }}원</td>
                        <td class="px-3 py-3 text-center text-muted" style="font-size:var(--fs-xs);">{{ $p->fields_count }}</td>
                        <td class="px-3 py-3 text-center text-muted" style="font-size:var(--fs-xs);">{{ $p->orders_count }}</td>
                        <td class="px-3 py-3 text-center">
                            <label class="rf-switch" title="{{ $p->is_active ? '노출 중 — 클릭하면 숨김' : '숨김 — 클릭하면 노출' }}">
                                <input type="checkbox" class="rf-toggle-active" data-url="{{ route('admin.products.toggle', $p) }}" @checked($p->is_active)>
                                <span class="rf-track"></span>
                            </label>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1" style="white-space:nowrap;">
                                <button type="button" class="btn btn-ghost btn-sm rf-copy" data-url="{{ url($p->orderUrl()) }}" title="주문 URL 복사">URL복사</button>
                                <a href="{{ route('admin.products.edit', $p) }}" class="btn btn-secondary btn-sm">수정</a>
                                <form method="POST" action="{{ route('admin.products.duplicate', $p) }}" class="inline">@csrf
                                    <button type="submit" class="btn btn-ghost btn-sm" title="필드·단계·업체 배분까지 복제(비활성으로 시작)">복제</button>
                                </form>
                                <form method="POST" action="{{ route('admin.products.destroy', $p) }}" class="inline" data-confirm="이 상품을 삭제할까요?" data-confirm-text="주문 내역도 함께 삭제됩니다.">@csrf @method('DELETE')
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center" style="padding:40px;color:var(--color-muted);font-size:var(--fs-xs);">아직 상품이 없습니다. 우측 상단 "＋ 새 상품"으로 만드세요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $products->links() }}</div>

<script>
// 노출 토글 — 스위치 변경 시 AJAX 반영(새로고침 없음), 실패하면 원복
document.querySelectorAll('.rf-toggle-active').forEach(function (el) {
    el.addEventListener('change', function () {
        el.disabled = true;
        fetch(el.dataset.url, { method: 'POST', headers: { 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw 0; })
            .catch(function () {
                el.checked = !el.checked;
                Swal.fire({ icon: 'error', title: '노출 상태 변경에 실패했습니다', text: '잠시 후 다시 시도하세요.' });
            })
            .finally(function () { el.disabled = false; });
    });
});

document.querySelectorAll('.rf-copy').forEach(function (b) {
    b.addEventListener('click', function () {
        navigator.clipboard && navigator.clipboard.writeText(b.dataset.url);
        var o = b.textContent; b.textContent = '복사됨 ✓';
        setTimeout(function () { b.textContent = o; }, 1200);
    });
});
</script>
@endsection
