@extends('admin.layout')
@section('page-title', '업체 관리')

@section('admin-content')
<x-console.page-head title="업체 관리">
    <x-slot:desc>외부 발주 업체 등록·관리 — <b>API 호출</b> 또는 <b>구글시트 행 추가</b>로 주문을 전송합니다. 상품 편집에서 업체별 배분(비율/수량)·매핑을 설정하세요.</x-slot:desc>
    <a href="{{ route('admin.vendors.create') }}" class="btn btn-primary btn-sm">＋ 업체 등록</a>
</x-console.page-head>

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

{{-- 검색 --}}
<form method="GET" class="card p-3 mb-4">
    <div class="flex items-center gap-2">
        <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
            @if ($q)<a href="{{ route('admin.vendors') }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>@endif
            <input name="q" value="{{ $q }}" class="input" style="width:260px;font-size:var(--fs-xs);" placeholder="업체명 검색">
            <button type="submit" class="btn btn-primary btn-sm" style="height:36px;">검색</button>
        </div>
    </div>
</form>

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:900px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-center px-3 py-3 font-semibold" style="width:44px;">No</th>
                    <th class="text-left px-5 py-3 font-semibold">업체명</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:190px;">채널</th>
                    <th class="text-left px-3 py-3 font-semibold">전송 대상</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:90px;">연결 상품</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:80px;">활성</th>
                    <th class="text-right px-5 py-3 font-semibold" style="width:150px;">작업</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($vendors as $v)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-3 py-3 text-center text-muted-soft" style="font-size:var(--fs-xs);">{{ $vendors->firstItem() + $loop->index }}</td>
                        <td class="px-5 py-3">
                            <div class="text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $v->name }}</div>
                            @if ($v->memo)<div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $v->memo }}</div>@endif
                            @if ($v->weekend_batch_dispatch)<div class="text-muted mt-0.5" style="font-size:var(--fs-xs);">주말 몰아 발주 · 토·일·월 → 금요일 자동</div>@endif
                            {{-- 랜딩 URL 배정 방식 — 기본(group)이 아닐 때만 표기 --}}
                            @if ($v->shop_link_mode && $v->shop_link_mode !== 'group')
                                <div class="text-muted mt-0.5" style="font-size:var(--fs-xs);">{{ \App\Models\Vendor::LINK_MODES[$v->shop_link_mode] ?? $v->shop_link_mode }}</div>
                            @endif
                            @if (is_array($v->shop_url_patterns) && count($v->shop_url_patterns))<div class="text-muted-soft mt-0.5" style="font-size:var(--fs-xs);">랜딩 URL {{ count($v->shop_url_patterns) }}개</div>@endif
                            @if (is_array($v->shop_param_keys) && count($v->shop_param_keys))<div class="text-muted-soft mt-0.5" style="font-size:var(--fs-xs);">바뀌는 파라미터 {{ implode(', ', $v->shop_param_keys) }}</div>@endif
                        </td>
                        <td class="px-3 py-3 text-center text-body" style="font-size:var(--fs-xs);">{{ \App\Models\Vendor::CHANNELS[$v->channel] ?? $v->channel }}</td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            {{ $v->channel === 'gsheet' ? ('시트 '.$v->gsheet_id) : ($v->api_method.' '.$v->api_url) }}
                        </td>
                        <td class="px-3 py-3 text-center text-muted" style="font-size:var(--fs-xs);">{{ $v->product_vendors_count }}</td>
                        <td class="px-3 py-3 text-center">
                            <label class="rf-switch" title="{{ $v->is_active ? '활성 — 클릭하면 비활성' : '비활성 — 클릭하면 활성' }}">
                                <input type="checkbox" class="vd-toggle" data-url="{{ route('admin.vendors.toggle', $v) }}" @checked($v->is_active)>
                                <span class="rf-track"></span>
                            </label>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1" style="white-space:nowrap;">
                                <a href="{{ route('admin.vendors.edit', $v) }}" class="btn btn-secondary btn-sm">수정</a>
                                <form method="POST" action="{{ route('admin.vendors.destroy', $v) }}" class="inline" data-confirm="이 업체를 삭제할까요?" data-confirm-text="상품의 배분 설정도 함께 삭제됩니다(전송 이력은 보존).">@csrf @method('DELETE')
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center" style="padding:40px;color:var(--color-muted);font-size:var(--fs-xs);">등록된 업체가 없습니다. 우측 상단 "＋ 업체 등록"으로 만드세요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $vendors->links() }}</div>

<script>
(function () {
    // 활성 토글 (AJAX) — 등록·수정은 별도 페이지(admin.vendors.create / edit)
    document.querySelectorAll('.vd-toggle').forEach(function (el) {
        el.addEventListener('change', function () {
            el.disabled = true;
            fetch(el.dataset.url, { method: 'POST', headers: { 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) throw 0; })
                .catch(function () { el.checked = !el.checked; Swal.fire({ icon: 'error', title: '변경에 실패했습니다' }); })
                .finally(function () { el.disabled = false; });
        });
    });
})();
</script>
@endsection
