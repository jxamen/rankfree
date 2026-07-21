@extends('admin.layout')
@section('page-title', '쿠폰 관리')

@section('admin-content')
<x-console.page-head title="쿠폰 관리" desc="마케팅 상품 주문 할인 쿠폰 발행·다운로드·사용 내역을 관리합니다" />

@if (session('status'))
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-success) 8%,#fff);color:var(--color-success);font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

{{-- 통계 --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    @foreach ([
        ['활성 쿠폰', number_format($stats['active']).'개'],
        ['총 발급', number_format($stats['issued']).'매'],
        ['사용됨', number_format($stats['used']).'매'],
        ['누적 할인액', number_format($stats['discounted']).'원'],
    ] as [$label, $value])
        <div class="card p-4">
            <div class="text-muted" style="font-size:var(--fs-xs);">{{ $label }}</div>
            <div class="text-ink font-display mt-1" style="font-size:var(--fs-lg);">{{ $value }}</div>
        </div>
    @endforeach
</div>

{{-- 쿠폰 목록 헤더 + 만들기 --}}
<div class="flex items-center justify-between mb-3">
    <h2 class="text-ink font-semibold" style="font-size:var(--fs-sm);">쿠폰 목록</h2>
    <button type="button" class="btn btn-primary btn-sm" onclick="rfTgl('coupon-new')">＋ 쿠폰 만들기</button>
</div>

{{-- 새 쿠폰 --}}
<div id="coupon-new" class="hidden card mb-4 p-5">
    <form method="POST" action="{{ route('admin.coupons.store') }}">
        @csrf
        @include('admin.coupons._form', ['coupon' => null, 'products' => $products])
        <button type="submit" class="btn btn-primary btn-sm mt-4">쿠폰 만들기</button>
    </form>
</div>

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:960px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);">
                    <th class="text-left px-5 py-3 font-semibold">쿠폰</th>
                    <th class="text-left px-3 py-3 font-semibold">할인</th>
                    <th class="text-left px-3 py-3 font-semibold">조건</th>
                    <th class="text-left px-3 py-3 font-semibold">기간</th>
                    <th class="text-right px-3 py-3 font-semibold">발급 / 사용</th>
                    <th class="text-left px-3 py-3 font-semibold">상태</th>
                    <th class="text-right px-5 py-3 font-semibold">관리</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($coupons as $c)
                    <tr style="border-top:1px solid var(--color-hairline-soft);{{ $c->is_active ? '' : 'opacity:.55;' }}">
                        <td class="px-5 py-3">
                            <a href="{{ route('admin.coupons.show', $c) }}" class="text-ink font-medium hover:underline" style="font-size:var(--fs-xs);">{{ $c->name }}</a>
                            <div class="text-muted-soft" style="font-size:var(--fs-xs);font-family:var(--font-mono);">{{ $c->code }}</div>
                            @if ($c->memo)
                                <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $c->memo }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-ink" style="font-size:var(--fs-xs);">{{ $c->discountLabel() }}</td>
                        <td class="px-3 py-3 text-body" style="font-size:var(--fs-xs);">
                            @if ((float) $c->min_order_amount > 0)<div>{{ number_format((float) $c->min_order_amount) }}원 이상</div>@endif
                            <div class="text-muted-soft">{{ $c->product_ids ? count($c->product_ids).'개 상품 전용' : '전체 상품' }}</div>
                            @if ($c->is_downloadable)<span class="badge" style="font-size:var(--fs-xs);padding:1px 7px;">다운로드 허용</span>@endif
                        </td>
                        <td class="px-3 py-3 text-body" style="font-size:var(--fs-xs);">
                            <div>{{ $c->starts_at?->format('Y-m-d') ?? '제한 없음' }} ~ {{ $c->ends_at?->format('Y-m-d') ?? '제한 없음' }}</div>
                            @if ($c->valid_days)<div class="text-muted-soft">발급 후 {{ $c->valid_days }}일</div>@endif
                        </td>
                        <td class="px-3 py-3 text-right" style="font-size:var(--fs-xs);">
                            <span class="text-ink font-mono">{{ number_format($c->user_coupons_count) }}{{ $c->max_issuance ? ' / '.number_format($c->max_issuance) : '' }}</span>
                            <span class="text-muted-soft">발급</span>
                            <div class="text-muted">{{ number_format($c->used_count) }} 사용</div>
                        </td>
                        <td class="px-3 py-3" style="font-size:var(--fs-xs);">
                            @if (! $c->is_active)
                                <span style="color:var(--color-error);">● 중지</span>
                            @elseif (! $c->inPeriod())
                                <span class="text-muted">● 기간 외</span>
                            @else
                                <span style="color:var(--color-success);">● 활성</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right text-nowrap">
                            <a href="{{ route('admin.coupons.show', $c) }}" class="btn btn-secondary btn-sm">발급 관리</a>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="rfTgl('coupon-{{ $c->id }}')">수정</button>
                            <form method="POST" action="{{ route('admin.coupons.toggle', $c) }}" style="display:inline;">@csrf<button type="submit" class="btn btn-ghost btn-sm">{{ $c->is_active ? '중지' : '활성' }}</button></form>
                            <form method="POST" action="{{ route('admin.coupons.destroy', $c) }}" style="display:inline;" data-confirm="쿠폰을 삭제할까요?" data-confirm-text="미사용 발급분도 함께 회수됩니다. 사용 이력이 있으면 삭제할 수 없습니다.">@csrf @method('DELETE')<button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button></form>
                        </td>
                    </tr>
                    <tr id="coupon-{{ $c->id }}" class="hidden">
                        <td colspan="7" class="px-5 py-4" style="background:var(--color-surface-soft);border-top:1px solid var(--color-hairline-soft);">
                            <form method="POST" action="{{ route('admin.coupons.update', $c) }}">
                                @csrf @method('PUT')
                                @include('admin.coupons._form', ['coupon' => $c, 'products' => $products])
                                <button type="submit" class="btn btn-primary btn-sm mt-4">저장</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center" style="padding:48px 20px;color:var(--color-muted);">쿠폰이 없습니다. '＋ 쿠폰 만들기'로 시작하세요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $coupons->links() }}</div>

<script>
    function rfTgl(id) { var e = document.getElementById(id); if (e) e.classList.toggle('hidden'); }
</script>
@endsection
