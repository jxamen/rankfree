@extends('admin.layout')
@section('page-title', '회원 관리')

@section('admin-content')
@php $isSuper = auth()->user()?->isSuperAdmin(); @endphp

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

{{-- 통계 --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    @foreach ([
        ['전체 회원', number_format($stats['total']).'명'],
        ['유료 구독', number_format($stats['paid']).'명'],
        ['운영자', number_format($stats['operators']).'명'],
        ['최근 7일 가입', number_format($stats['new7d']).'명'],
    ] as [$label, $value])
        <div class="card p-4">
            <div class="text-muted" style="font-size:var(--fs-xs);">{{ $label }}</div>
            <div class="text-ink font-display mt-1" style="font-size:var(--fs-lg);">{{ $value }}</div>
        </div>
    @endforeach
</div>

{{-- 검색·필터 --}}
<form method="GET" class="flex gap-2 flex-wrap items-end mb-4">
    <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">검색</label>
        <input name="q" value="{{ $q }}" class="input" style="width:220px;" placeholder="이름 · 이메일"></div>
    <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">등급</label>
        <select name="grade" class="input" style="width:150px;">
            <option value="">전체 등급</option>
            <option value="none" @selected($gradeId === 'none')>등급 없음</option>
            @foreach ($grades as $g)
                <option value="{{ $g->id }}" @selected((string) $gradeId === (string) $g->id)>{{ $g->name }}</option>
            @endforeach
        </select></div>
    <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">구분</label>
        <select name="role" class="input" style="width:130px;">
            <option value="">전체</option>
            <option value="operator" @selected($role === 'operator')>운영자</option>
            <option value="member" @selected($role === 'member')>일반회원</option>
        </select></div>
    <button type="submit" class="btn btn-primary btn-sm">검색</button>
    @if ($q || $gradeId || $role)
        <a href="{{ route('admin.members') }}" class="btn btn-ghost btn-sm">초기화</a>
    @endif
</form>

{{-- 목록 --}}
<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:920px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);">
                    <th class="text-left px-5 py-3 font-semibold">회원</th>
                    <th class="text-left px-3 py-3 font-semibold">등급</th>
                    <th class="text-left px-3 py-3 font-semibold">구독 만료</th>
                    <th class="text-left px-3 py-3 font-semibold">운영 권한</th>
                    <th class="text-right px-3 py-3 font-semibold">순위 슬롯</th>
                    <th class="text-left px-3 py-3 font-semibold">가입일</th>
                    <th class="text-right px-5 py-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($members as $m)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-5 py-3">
                            <div class="text-ink font-medium" style="font-size:var(--fs-xs);">{{ $m->name }}
                                @if ($m->role === 'super')<span class="badge" style="font-size:var(--fs-xs);padding:1px 7px;background:color-mix(in srgb,var(--color-error) 12%,transparent);color:var(--color-error);">SUPER</span>@endif
                            </div>
                            <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $m->email }}</div>
                        </td>
                        <td class="px-3 py-3">
                            @if ($m->grade)
                                <span class="badge" style="font-size:var(--fs-xs);">{{ $m->grade->name }}</span>
                                @if ($m->grade->is_paid)<span class="text-muted-soft" style="font-size:var(--fs-xs);">유료</span>@endif
                            @else
                                <span class="text-muted-soft" style="font-size:var(--fs-xs);">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-3" style="font-size:var(--fs-xs);">
                            @if (! $m->grade?->is_paid)
                                <span class="text-muted-soft">—</span>
                            @elseif ($m->subscription_expires_at === null)
                                <span class="text-ink">무기한</span>
                            @elseif ($m->subscription_expires_at->isPast())
                                <span style="color:var(--color-error);">만료 {{ $m->subscription_expires_at->format('Y-m-d') }}</span>
                            @else
                                <span class="text-body">{{ $m->subscription_expires_at->format('Y-m-d') }}</span>
                                <span class="text-muted-soft">({{ $m->subscription_expires_at->diffForHumans() }})</span>
                            @endif
                        </td>
                        <td class="px-3 py-3" style="font-size:var(--fs-xs);">
                            {{ $m->operatorRole?->name ?? '—' }}
                        </td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format($m->rankSlotsUsedTotal()) }} / {{ $m->rankSlotLimit() < 0 ? '∞' : $m->rankSlotLimit() }}</td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $m->created_at->format('Y-m-d') }}</td>
                        <td class="px-5 py-3 text-right text-nowrap">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="rfTgl('edit-{{ $m->id }}')">수정</button>
                        </td>
                    </tr>
                    {{-- 편집 --}}
                    <tr id="edit-{{ $m->id }}" class="hidden">
                        <td colspan="7" class="px-5 py-4" style="background:var(--color-surface-soft);border-top:1px solid var(--color-hairline-soft);">
                            <form method="POST" action="{{ route('admin.members.update', $m) }}" class="flex gap-3 items-end flex-wrap">
                                @csrf @method('PUT')
                                <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">등급(요금제)</label>
                                    <select name="grade_id" class="input" style="width:150px;">
                                        <option value="">— 없음 —</option>
                                        @foreach ($grades as $g)
                                            <option value="{{ $g->id }}" @selected($m->grade_id == $g->id)>{{ $g->name }}{{ $g->is_paid ? ' (유료)' : '' }}</option>
                                        @endforeach
                                    </select></div>
                                <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">구독 만료일 <span class="text-muted-soft">(비우면 무기한)</span></label>
                                    <input type="date" name="subscription_expires_at" class="input" style="width:160px;" value="{{ $m->subscription_expires_at?->format('Y-m-d') }}"></div>
                                <div><label class="block text-muted mb-1" style="font-size:var(--fs-xs);">직원 지정 {{ $isSuper ? '' : '(슈퍼관리자만)' }}</label>
                                    <select name="operator_role_id" class="input" style="width:150px;" {{ $isSuper ? '' : 'disabled' }}>
                                        <option value="">— 일반회원 —</option>
                                        @foreach ($roles->where('is_super', false) as $r)
                                            <option value="{{ $r->id }}" @selected($m->operator_role_id == $r->id)>{{ $r->name }}</option>
                                        @endforeach
                                    </select></div>
                                <button type="submit" class="btn btn-primary btn-sm">저장</button>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="rfTgl('edit-{{ $m->id }}')">닫기</button>
                            </form>
                            @if ($m->role === 'super')
                                <p class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">※ 슈퍼관리자 계정의 최고 권한(role=super)은 이 화면에서 변경되지 않습니다.</p>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center" style="padding:56px 20px;color:var(--color-muted);">조건에 맞는 회원이 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $members->links() }}</div>

<script>
    function rfTgl(id) { var e = document.getElementById(id); if (e) e.classList.toggle('hidden'); }
</script>
@endsection
