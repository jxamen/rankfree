@extends('console.layout')
@section('page-title', '마이페이지')
@section('crumb-title', '마이페이지')

@section('console-content')
<x-console.page-head title="마이페이지" desc="계정 정보 · 순위 추적 한도 · 추천 링크 · 쿠폰" />

@if (session('status'))
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-success) 8%,var(--color-canvas));color:var(--color-success);font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4" style="max-width:1040px;">
    {{-- 계정 정보 --}}
    <div class="card p-6">
        <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-sm);">계정 정보</div>
        <div class="flex flex-col gap-3" style="font-size:var(--fs-xs);">
            <div class="flex items-center justify-between"><span class="text-muted">이름</span><span class="text-ink font-medium">{{ $user->name }}</span></div>
            <div class="flex items-center justify-between"><span class="text-muted">이메일</span><span class="text-ink">{{ $user->email }}</span></div>
            <div class="flex items-center justify-between"><span class="text-muted">등급</span><span class="text-ink">{{ $user->grade?->name ?? '무료' }}</span></div>
            <div class="flex items-center justify-between">
                <span class="text-muted">순위 추적 한도 <span class="text-muted-soft">(플레이스+쇼핑)</span></span>
                <span class="text-ink font-mono">{{ $user->rankSlotsUsedTotal() }} / {{ $user->rankSlotLimit() < 0 ? '무제한' : number_format($user->rankSlotLimit()) }}</span>
            </div>
            @if ($bonusSlots > 0)
                <div class="flex items-center justify-between"><span class="text-muted">└ 추천 보너스 포함</span><span style="color:var(--color-success);font-family:var(--font-mono);">+{{ number_format($bonusSlots) }}</span></div>
            @endif
            <div class="flex items-center justify-between">
                <span class="text-muted">사용 가능 쿠폰</span>
                <span class="text-ink"><span class="font-mono">{{ number_format($myCoupons->filter->isUsable()->count()) }}</span>장</span>
            </div>
        </div>
    </div>

    {{-- 추천 링크 --}}
    <div class="card p-6">
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">추천 링크</div>
        <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">
            이 링크로 가입이 완료되면 <b class="text-muted">순위 추적 가능 개수가 자동으로 +{{ number_format($bonusPer) }}개</b> 늘어납니다.
            (별도 코드 입력 없이 자동 적용 · 최대 +{{ number_format($bonusMax) }}개까지)
        </p>
        <div class="flex items-center gap-2">
            <input type="text" id="rf-ref-url" class="input" readonly value="{{ $referralUrl }}" style="flex:1;font-size:var(--fs-xs);font-family:var(--font-mono);" onclick="this.select()">
            <button type="button" id="rf-ref-copy" class="btn btn-primary btn-sm" style="height:40px;">복사</button>
        </div>
        <div class="flex items-center gap-4 mt-4" style="font-size:var(--fs-xs);">
            <span class="text-muted">추천 가입 <b class="text-ink font-mono">{{ number_format($referredCount) }}</b>명</span>
            <span class="text-muted">획득 보너스 <b class="font-mono" style="color:var(--color-success);">+{{ number_format($bonusSlots) }}</b> / 최대 {{ number_format($bonusMax) }}개</span>
        </div>
    </div>
</div>

{{-- 쿠폰(26) — 별도 메뉴 없이 마이페이지에서 확인·다운로드. 사용은 셀프마케팅 주문에서 --}}
@php
    $couponProductLabel = function ($coupon) use ($couponProductTitles) {
        if (! $coupon?->product_ids) return '전체 상품';
        $names = collect($coupon->product_ids)->map(fn ($id) => $couponProductTitles[$id] ?? null)->filter()->values();
        return $names->isEmpty() ? '전체 상품' : $names->take(2)->implode(', ').($names->count() > 2 ? ' 외 '.($names->count() - 2).'개' : '');
    };
@endphp
<div class="mt-6" style="max-width:1040px;">
    @if ($downloadable->isNotEmpty())
        <h2 class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">받을 수 있는 쿠폰</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-6">
            @foreach ($downloadable as $c)
                <div class="card p-5 flex flex-col gap-2">
                    <div class="text-ink font-display" style="font-size:var(--fs-lg);">{{ $c->discountLabel() }}</div>
                    <div class="text-ink font-medium" style="font-size:var(--fs-xs);">{{ $c->name }}</div>
                    <div class="text-muted-soft" style="font-size:var(--fs-xs);">
                        {{ $couponProductLabel($c) }}
                        @if ((float) $c->min_order_amount > 0) · {{ number_format((float) $c->min_order_amount) }}원 이상 주문 @endif
                    </div>
                    <div class="text-muted-soft" style="font-size:var(--fs-xs);">
                        @if ($c->valid_days) 받은 날부터 {{ $c->valid_days }}일간 사용 가능 @endif
                        @if ($c->ends_at) {{ $c->valid_days ? '·' : '' }} {{ $c->ends_at->format('Y-m-d') }}까지 @endif
                        @if (! $c->valid_days && ! $c->ends_at) 기간 제한 없음 @endif
                    </div>
                    <form method="POST" action="{{ route('console.coupons.download', $c) }}" class="mt-2">
                        @csrf<button type="submit" class="btn btn-primary btn-sm w-full">쿠폰 받기</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    <h2 class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">내 쿠폰 <span class="text-muted-soft font-normal">({{ $myCoupons->count() }})</span></h2>
    @if ($myCoupons->isEmpty())
        <div class="card p-8 text-center">
            <p class="text-muted" style="font-size:var(--fs-sm);">보유한 쿠폰이 없습니다.</p>
            <p class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">이벤트·관리자 발행으로 받은 쿠폰이 여기에 표시됩니다.</p>
        </div>
    @else
        <div class="card overflow-hidden">
            <div style="overflow-x:auto;">
                <table class="w-full" style="min-width:680px;">
                    <thead>
                        <tr class="text-muted" style="font-size:var(--fs-xs);">
                            <th class="text-left px-5 py-3 font-semibold">쿠폰</th>
                            <th class="text-left px-3 py-3 font-semibold">할인</th>
                            <th class="text-left px-3 py-3 font-semibold">사용 조건</th>
                            <th class="text-left px-3 py-3 font-semibold">만료일</th>
                            <th class="text-left px-5 py-3 font-semibold">상태</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($myCoupons as $uc)
                            @php $st = $uc->statusLabel(); @endphp
                            <tr style="border-top:1px solid var(--color-hairline-soft);{{ $st === '사용 가능' ? '' : 'opacity:.55;' }}">
                                <td class="px-5 py-3">
                                    <div class="text-ink font-medium" style="font-size:var(--fs-xs);">{{ $uc->coupon?->name ?? '(종료된 쿠폰)' }}</div>
                                    <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $uc->created_at->format('Y-m-d') }} 받음</div>
                                </td>
                                <td class="px-3 py-3 text-ink" style="font-size:var(--fs-xs);">{{ $uc->coupon?->discountLabel() ?? '—' }}</td>
                                <td class="px-3 py-3 text-body" style="font-size:var(--fs-xs);">
                                    {{ $couponProductLabel($uc->coupon) }}
                                    @if ($uc->coupon && (float) $uc->coupon->min_order_amount > 0)
                                        <div class="text-muted-soft">{{ number_format((float) $uc->coupon->min_order_amount) }}원 이상 주문</div>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-body" style="font-size:var(--fs-xs);">{{ $uc->expires_at?->format('Y-m-d') ?? '무기한' }}</td>
                                <td class="px-5 py-3" style="font-size:var(--fs-xs);">
                                    @if ($st === '사용 가능')
                                        <span style="color:var(--color-success);">● 사용 가능</span>
                                    @elseif ($st === '사용됨')
                                        <span class="text-muted">● 사용됨</span>
                                        @if ($uc->order)<div class="text-muted-soft mt-0.5">{{ $uc->order->order_no }}</div>@endif
                                    @else
                                        <span class="text-muted">● {{ $st }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

<script>
document.getElementById('rf-ref-copy').addEventListener('click', function () {
    var inp = document.getElementById('rf-ref-url');
    inp.select();
    (navigator.clipboard ? navigator.clipboard.writeText(inp.value) : Promise.reject())
        .catch(function () { document.execCommand('copy'); })
        .finally(function () {
            var b = document.getElementById('rf-ref-copy');
            var o = b.textContent; b.textContent = '복사됨 ✓';
            setTimeout(function () { b.textContent = o; }, 1400);
        });
});
</script>
@endsection
