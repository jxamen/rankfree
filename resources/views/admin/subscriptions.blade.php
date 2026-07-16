@extends('admin.layout')
@section('page-title', '구독 관리')

@section('admin-content')
<x-console.page-head title="구독 관리" desc="유료 구독 현황·기간 관리 · 활성/만료 예정 구독과 매출을 확인합니다" />

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif
@php $won = fn ($n) => $n >= 10000 ? number_format($n / 10000, 0).'만' : number_format($n); @endphp

{{-- 통계 --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    @foreach ([
        ['활성 구독', number_format($stats['active']).'명'],
        ['7일 내 만료', number_format($stats['expiring']).'명'],
        ['만료됨', number_format($stats['expired']).'명'],
        ['월 예상 매출(MRR)', $won($stats['mrr']).'원'],
    ] as [$label, $value])
        <div class="card p-4">
            <div class="text-muted" style="font-size:var(--fs-xs);">{{ $label }}</div>
            <div class="text-ink font-display mt-1" style="font-size:var(--fs-lg);">{{ $value }}</div>
        </div>
    @endforeach
</div>

{{-- 요금제(플랜) --}}
<div class="flex items-center justify-between mb-3">
    <h2 class="text-ink font-semibold" style="font-size:var(--fs-sm);">요금제</h2>
    <button type="button" class="btn btn-primary btn-sm" onclick="rfTgl('plan-new')">＋ 요금제 추가</button>
</div>

{{-- 신규 요금제 --}}
<div id="plan-new" class="hidden card mb-4 p-5">
    <form method="POST" action="{{ route('admin.subscriptions.plans.store') }}" class="flex gap-3 items-end flex-wrap">
        @csrf
        <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">이름</label><input name="name" class="input" style="width:140px;" placeholder="예: 프로" required></div>
        <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">단계(tier)</label><input type="number" name="tier" class="input" style="width:90px;" value="0" min="0"></div>
        <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">월 요금(원)</label><input type="number" name="monthly_price" class="input" style="width:120px;" placeholder="0" min="0"></div>
        <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">슬롯 한도 <span class="text-muted-soft">(-1=무제한)</span></label><input type="number" name="rank_slot_limit" class="input" style="width:120px;" value="100" min="-1"></div>
        <span class="flex items-center gap-2 text-muted" style="font-size:var(--fs-xs);height:40px;"><label class="rf-switch"><input type="checkbox" name="is_paid" value="1"><span class="rf-track"></span></label> 유료</span>
        <span class="flex items-center gap-2 text-muted" style="font-size:var(--fs-xs);height:40px;"><label class="rf-switch"><input type="checkbox" name="is_active" value="1" checked><span class="rf-track"></span></label> 노출</span>
        <div style="flex-basis:100%;height:0;"></div>
        <div style="flex-basis:100%;">
            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);font-weight:600;">기능별 월간 횟수 제한 <span class="text-muted-soft">(-1=무제한, 0=미제공)</span></label>
            <div class="flex gap-3 flex-wrap">
                @foreach (\App\Models\MemberGrade::FEATURES as $key => $label)
                    <div><label class="block text-muted-soft mb-1" style="font-size:var(--fs-xs);">{{ $label }}</label>
                        <input type="number" name="feature_limits[{{ $key }}]" class="input" style="width:110px;" value="-1" min="-1"></div>
                @endforeach
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm mt-2">추가</button>
    </form>
</div>

<div class="card overflow-hidden mb-8">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:820px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);">
                    <th class="text-left px-5 py-3 font-semibold">요금제</th>
                    <th class="text-left px-3 py-3 font-semibold">유형</th>
                    <th class="text-right px-3 py-3 font-semibold">월 요금</th>
                    <th class="text-right px-3 py-3 font-semibold">슬롯 한도</th>
                    <th class="text-right px-3 py-3 font-semibold">구독자</th>
                    <th class="text-right px-5 py-3 font-semibold">관리</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($plans as $p)
                    <tr style="border-top:1px solid var(--color-hairline-soft);{{ $p->is_active ? '' : 'opacity:.55;' }}">
                        <td class="px-5 py-3">
                            <div class="text-ink font-medium" style="font-size:var(--fs-xs);">{{ $p->name }}</div>
                            <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $p->description ?: $p->slug }}</div>
                            <div class="flex gap-1 flex-wrap mt-1">
                                @foreach (\App\Models\MemberGrade::FEATURES as $key => $label)
                                    @php $lim = $p->featureLimit($key); @endphp
                                    <span class="badge" style="font-size:var(--fs-xs);padding:1px 7px;{{ $lim === 0 ? 'opacity:.5;' : '' }}">{{ $label }} {{ $lim < 0 ? '∞' : ($lim === 0 ? '✕' : $lim.'회') }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-3 py-3"><span class="badge" style="font-size:var(--fs-xs);">{{ $p->is_paid ? '유료' : '무료' }}</span></td>
                        <td class="px-3 py-3 text-right text-ink" style="font-size:var(--fs-xs);">{{ $p->monthly_price !== null ? number_format($p->monthly_price).'원' : '—' }}</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $p->rank_slot_limit < 0 ? '무제한' : number_format($p->rank_slot_limit) }}</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format($p->users_count) }}명</td>
                        <td class="px-5 py-3 text-right text-nowrap">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="rfTgl('plan-{{ $p->id }}')">수정</button>
                            <form method="POST" action="{{ route('admin.subscriptions.plans.toggle', $p) }}" style="display:inline;">@csrf<button type="submit" class="btn btn-ghost btn-sm">{{ $p->is_active ? '숨김' : '노출' }}</button></form>
                            <form method="POST" action="{{ route('admin.subscriptions.plans.destroy', $p) }}" style="display:inline;" onsubmit="return confirm('이 요금제를 삭제할까요?')">@csrf @method('DELETE')<button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button></form>
                        </td>
                    </tr>
                    <tr id="plan-{{ $p->id }}" class="hidden">
                        <td colspan="6" class="px-5 py-4" style="background:var(--color-surface-soft);border-top:1px solid var(--color-hairline-soft);">
                            <form method="POST" action="{{ route('admin.subscriptions.plans.update', $p) }}" class="flex gap-3 items-end flex-wrap">
                                @csrf @method('PUT')
                                <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">이름</label><input name="name" class="input" style="width:140px;" value="{{ $p->name }}" required></div>
                                <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">단계</label><input type="number" name="tier" class="input" style="width:80px;" value="{{ $p->tier }}" min="0"></div>
                                <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">월 요금</label><input type="number" name="monthly_price" class="input" style="width:120px;" value="{{ $p->monthly_price }}" min="0"></div>
                                <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">슬롯 한도</label><input type="number" name="rank_slot_limit" class="input" style="width:110px;" value="{{ $p->rank_slot_limit }}" min="-1"></div>
                                <div style="flex-basis:100%;"></div>
                                <div style="flex:1;min-width:220px;"><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">설명</label><input name="description" class="input" value="{{ $p->description }}" maxlength="255"></div>
                                <span class="flex items-center gap-2 text-muted" style="font-size:var(--fs-xs);height:40px;"><label class="rf-switch"><input type="checkbox" name="is_paid" value="1" @checked($p->is_paid)><span class="rf-track"></span></label> 유료</span>
                                <span class="flex items-center gap-2 text-muted" style="font-size:var(--fs-xs);height:40px;"><label class="rf-switch"><input type="checkbox" name="is_active" value="1" @checked($p->is_active)><span class="rf-track"></span></label> 노출</span>
                                <div style="flex-basis:100%;">
                                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);font-weight:600;">기능별 월간 횟수 제한 <span class="text-muted-soft">(-1=무제한, 0=미제공)</span></label>
                                    <div class="flex gap-3 flex-wrap">
                                        @foreach (\App\Models\MemberGrade::FEATURES as $key => $label)
                                            <div><label class="block text-muted-soft mb-1" style="font-size:var(--fs-xs);">{{ $label }}</label>
                                                <input type="number" name="feature_limits[{{ $key }}]" class="input" style="width:110px;" value="{{ $p->featureLimit($key) }}" min="-1"></div>
                                        @endforeach
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm mt-2">저장</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- 유료 구독 회원 --}}
<h2 class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">유료 구독 회원</h2>
<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:760px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);">
                    <th class="text-left px-5 py-3 font-semibold">회원</th>
                    <th class="text-left px-3 py-3 font-semibold">요금제</th>
                    <th class="text-left px-3 py-3 font-semibold">상태</th>
                    <th class="text-left px-3 py-3 font-semibold">만료일</th>
                    <th class="text-right px-5 py-3 font-semibold">구독 관리</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($subscribers as $s)
                    @php
                        $expired = $s->subscription_expires_at && $s->subscription_expires_at->isPast();
                        $active = ! $expired; // 유료 등급 + (무기한 or 미래)
                    @endphp
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-5 py-3">
                            <div class="text-ink font-medium" style="font-size:var(--fs-xs);">{{ $s->name }}</div>
                            <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $s->email }}</div>
                        </td>
                        <td class="px-3 py-3"><span class="badge" style="font-size:var(--fs-xs);">{{ $s->grade?->name }}</span></td>
                        <td class="px-3 py-3" style="font-size:var(--fs-xs);">
                            @if ($expired)
                                <span style="color:var(--color-error);">● 만료</span>
                            @else
                                <span style="color:var(--color-success);">● 활성</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-body" style="font-size:var(--fs-xs);">
                            {{ $s->subscription_expires_at ? $s->subscription_expires_at->format('Y-m-d') : '무기한' }}
                        </td>
                        <td class="px-5 py-3 text-right text-nowrap">
                            <form method="POST" action="{{ route('admin.subscriptions.extend', $s) }}" style="display:inline-flex;gap:4px;align-items:center;">
                                @csrf
                                <select name="months" class="input" style="width:78px;height:32px;padding:0 8px;font-size:var(--fs-xs);">
                                    <option value="1">1개월</option><option value="3">3개월</option><option value="6">6개월</option><option value="12">12개월</option>
                                </select>
                                <button type="submit" class="btn btn-secondary btn-sm">연장</button>
                            </form>
                            <form method="POST" action="{{ route('admin.subscriptions.cancel', $s) }}" style="display:inline;" onsubmit="return confirm('이 회원의 구독을 해지하고 무료 등급으로 전환할까요?')">
                                @csrf<button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">해지</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center" style="padding:48px 20px;color:var(--color-muted);">유료 구독 회원이 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $subscribers->links() }}</div>

<script>
    function rfTgl(id) { var e = document.getElementById(id); if (e) e.classList.toggle('hidden'); }
</script>
@endsection
