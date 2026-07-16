@extends('console.layout')
@section('page-title', '마이페이지')
@section('crumb-title', '마이페이지')

@section('console-content')
<x-console.page-head title="마이페이지" desc="계정 정보 · 순위 추적 한도 · 추천 링크" />

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
