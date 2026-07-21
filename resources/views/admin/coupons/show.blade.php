@extends('admin.layout')
@section('page-title', '쿠폰 발급 관리')

@section('page-actions')
    <a href="{{ route('admin.coupons') }}" class="btn btn-secondary btn-sm">← 목록</a>
@endsection

@section('admin-content')
<x-console.page-head :title="'쿠폰 발급 관리 — '.$coupon->name" desc="특정 회원 발행·전체 회원 일괄 발급·발급 내역과 사용 현황을 관리합니다" />

@if (session('status'))
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-success) 8%,#fff);color:var(--color-success);font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

{{-- 쿠폰 요약 --}}
<div class="card p-5 mb-6">
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
        @foreach ([
            ['코드', $coupon->code],
            ['할인', $coupon->discountLabel().((float) $coupon->min_order_amount > 0 ? ' · '.number_format((float) $coupon->min_order_amount).'원 이상' : '')],
            ['기간', ($coupon->starts_at?->format('Y-m-d') ?? '제한 없음').' ~ '.($coupon->ends_at?->format('Y-m-d') ?? '제한 없음').($coupon->valid_days ? ' · 발급 후 '.$coupon->valid_days.'일' : '')],
            ['적용 상품', $coupon->product_ids ? count($coupon->product_ids).'개 상품 전용' : '전체 상품'],
            ['발급 / 사용', number_format($coupon->user_coupons_count).($coupon->max_issuance ? ' / '.number_format($coupon->max_issuance) : '').'매 · '.number_format($coupon->used_count).' 사용'],
        ] as [$lab, $val])
            <div>
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $lab }}</div>
                <div class="text-ink mt-0.5" style="font-size:var(--fs-xs);">{{ $val }}</div>
            </div>
        @endforeach
    </div>
    <div class="flex items-center gap-2 mt-4 pt-4" style="border-top:1px solid var(--color-hairline-soft);">
        @if (! $coupon->is_active)
            <span class="badge" style="font-size:var(--fs-xs);color:var(--color-error);">중지됨 — 사용·다운로드 불가</span>
        @elseif ($coupon->is_downloadable)
            <span class="badge" style="font-size:var(--fs-xs);">쿠폰함 다운로드 허용</span>
        @endif
        <form method="POST" action="{{ route('admin.coupons.issue-all', $coupon) }}" style="display:inline;"
              data-confirm="전체 회원에게 발급할까요?" data-confirm-text="아직 이 쿠폰이 없는 회원 {{ number_format($notIssuedCount) }}명에게 발급됩니다{{ $coupon->max_issuance ? ' (수량 제한 내에서)' : '' }}." data-confirm-ok="전체 발급">
            @csrf<button type="submit" class="btn btn-primary btn-sm" {{ $notIssuedCount === 0 ? 'disabled' : '' }}>전체 회원 발급 ({{ number_format($notIssuedCount) }}명)</button>
        </form>
    </div>
</div>

{{-- 특정 회원 발행 — 검색 후 결과에서 발행 --}}
<h2 class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">특정 회원 발행</h2>
<div class="card p-5 mb-6">
    <form method="GET" action="{{ route('admin.coupons.show', $coupon) }}" class="flex gap-2 items-center flex-wrap">
        <input name="member" class="input" style="width:280px;" value="{{ $memberQ }}" placeholder="이름·이메일·전화로 검색">
        <button type="submit" class="btn btn-secondary btn-sm">검색</button>
    </form>
    @if ($memberQ !== '')
        <div class="mt-4">
            @forelse ($members as $m)
                <div class="flex items-center justify-between flex-wrap gap-2 py-2" style="border-top:1px solid var(--color-hairline-soft);">
                    <div style="font-size:var(--fs-xs);">
                        <span class="text-ink font-medium">{{ $m->name }}</span>
                        <span class="text-muted-soft ml-2">{{ $m->email }}</span>
                        @if ($m->phone)<span class="text-muted-soft ml-2">{{ $m->phone }}</span>@endif
                    </div>
                    @if (in_array($m->id, $ownedUserIds, true))
                        <span class="badge" style="font-size:var(--fs-xs);">보유 중</span>
                    @else
                        <form method="POST" action="{{ route('admin.coupons.issue', $coupon) }}">
                            @csrf<input type="hidden" name="user_id" value="{{ $m->id }}">
                            <button type="submit" class="btn btn-primary btn-sm">발행</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="text-muted-soft" style="font-size:var(--fs-xs);">'{{ $memberQ }}' 검색 결과가 없습니다.</p>
            @endforelse
        </div>
    @endif
</div>

{{-- 발급 내역 --}}
<h2 class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">발급 내역</h2>
<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:820px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);">
                    <th class="text-left px-5 py-3 font-semibold">회원</th>
                    <th class="text-left px-3 py-3 font-semibold">출처</th>
                    <th class="text-left px-3 py-3 font-semibold">발급일</th>
                    <th class="text-left px-3 py-3 font-semibold">만료일</th>
                    <th class="text-left px-3 py-3 font-semibold">상태</th>
                    <th class="text-left px-3 py-3 font-semibold">사용 주문</th>
                    <th class="text-right px-5 py-3 font-semibold">관리</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($issued as $uc)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-5 py-3">
                            <div class="text-ink font-medium" style="font-size:var(--fs-xs);">{{ $uc->user?->name ?? '(탈퇴 회원)' }}</div>
                            <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $uc->user?->email }}</div>
                        </td>
                        <td class="px-3 py-3" style="font-size:var(--fs-xs);">
                            <span class="badge" style="font-size:var(--fs-xs);">{{ \App\Models\UserCoupon::SOURCES[$uc->source] ?? $uc->source }}</span>
                            @if ($uc->issuer)<div class="text-muted-soft mt-1">{{ $uc->issuer->name }}</div>@endif
                        </td>
                        <td class="px-3 py-3 text-body" style="font-size:var(--fs-xs);">{{ $uc->created_at->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-3 text-body" style="font-size:var(--fs-xs);">{{ $uc->expires_at?->format('Y-m-d') ?? '무기한' }}</td>
                        <td class="px-3 py-3" style="font-size:var(--fs-xs);">
                            @php $st = $uc->statusLabel(); @endphp
                            @if ($st === '사용됨')<span style="color:var(--color-accent);">● 사용됨</span>
                            @elseif ($st === '사용 가능')<span style="color:var(--color-success);">● 사용 가능</span>
                            @else<span class="text-muted">● {{ $st }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-3" style="font-size:var(--fs-xs);">
                            @if ($uc->order)
                                <a href="{{ route('admin.orders.show', $uc->order) }}" class="hover:underline" style="color:var(--color-accent);">{{ $uc->order->order_no }}</a>
                            @else
                                <span class="text-muted-soft">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right">
                            @if (! $uc->used_at)
                                <form method="POST" action="{{ route('admin.coupons.revoke', $uc) }}" style="display:inline;"
                                      data-confirm="발급을 회수할까요?" data-confirm-text="'{{ $uc->user?->name }}' 회원의 쿠폰함에서 사라집니다." data-confirm-ok="회수">
                                    @csrf @method('DELETE')<button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">회수</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center" style="padding:48px 20px;color:var(--color-muted);">아직 발급된 쿠폰이 없습니다. 위에서 회원을 검색해 발행하거나 전체 발급하세요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $issued->links() }}</div>
@endsection
