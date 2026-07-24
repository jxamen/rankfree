@extends('admin.layout')
@section('page-title', $title)

@php
    $fmt = fn ($n) => number_format((int) $n);
    $isPlace = $mode === 'place';
@endphp

@push('head')
<style>
    .trk-stats { display:grid; gap:12px; grid-template-columns:repeat(2,1fr); margin-bottom:20px; }
    @media(min-width:680px){ .trk-stats{ grid-template-columns:repeat(4,1fr); } }
    .trk-stats .lab { color:var(--color-muted); font-size:var(--fs-xs); }
    .trk-stats .val { color:var(--color-ink); font-family:var(--font-mono); font-size:var(--fs-lg); font-weight:650; margin-top:4px; }
    /* 날짜별 순위 셀 — 콘솔 카드와 동일. 플레이스는 리뷰·저장 접기 지원 */
    .rf-cell { width:{{ $isPlace ? 104 : 100 }}px; padding:10px 8px 8px; }
    @if ($isPlace)
    .rf-slot.rf-collapsed .rf-metrics { display:none; }
    .rf-slot.rf-collapsed .rf-cell { width:78px; padding:8px 6px; }
    @endif
</style>
@endpush

@section('admin-content')
<x-console.page-head :title="$title" :desc="$desc">
    {{-- 전체 순위체크 — 화면의 슬롯을 순차 확인(콘솔과 동일). 회원별 보기면 그 회원 슬롯만 --}}
    <button type="button" id="rf-run-all" class="btn btn-secondary btn-sm" @disabled($slots->isEmpty())>전체 순위체크</button>
    {{-- 전환 시 회원 필터 유지 — 같은 회원의 플레이스·쇼핑 추적을 오가며 볼 수 있게 --}}
    <a href="{{ route('admin.'.($isPlace ? 'shop-tracking' : 'place-tracking'), array_filter(['user' => $userId ?: null])) }}" class="btn btn-secondary btn-sm">{{ $isPlace ? '쇼핑 추적 보기' : '플레이스 추적 보기' }}</a>
</x-console.page-head>

{{-- 회원 필터 배너 — 아이디 클릭으로 진입한 업체별 추적 리스트 --}}
@if ($filterUser ?? null)
    <div class="card-soft px-4 py-3 mb-4 flex items-center gap-2" style="font-size:var(--fs-xs);">
        <span class="text-muted">업체:</span>
        <b class="text-ink">{{ $filterUser->name }}</b>
        <span class="text-muted-soft">{{ $filterUser->email }}</span>
        <span class="text-muted">· 키워드 <b class="text-ink">{{ $slots->count() }}</b>개</span>
        <a href="{{ route($routeName) }}" class="btn btn-ghost btn-sm" style="margin-left:auto;">← 전체 목록</a>
    </div>
@endif

@unless ($filterUser ?? null)
{{-- 통계 --}}
<div class="trk-stats">
    @foreach ([
        ['전체 슬롯', $fmt($stats['total']).'개'],
        ['활성', $fmt($stats['active']).'개'],
        ['등록 회원', $fmt($stats['users']).'명'],
        ['최근 7일 확인', $fmt($stats['checked7']).'개'],
    ] as [$lab, $val])
        <div class="card p-4">
            <div class="lab">{{ $lab }}</div>
            <div class="val">{{ $val }}</div>
        </div>
    @endforeach
</div>

{{-- 검색·필터 --}}
<form method="GET" class="card p-3 mb-4">
    <div class="flex items-center flex-wrap gap-2">
        @if (($userId ?? 0) > 0)<input type="hidden" name="user" value="{{ $userId }}">@endif
        <select name="active" class="input" style="width:130px;font-size:var(--fs-xs);" onchange="this.form.submit()">
            <option value="">전체 상태</option>
            <option value="1" @selected($active === '1')>활성</option>
            <option value="0" @selected($active === '0')>중지</option>
        </select>
        @if ($q !== '' || $active !== '')
            <a href="{{ route($routeName) }}" class="btn btn-ghost btn-sm">초기화</a>
        @endif
        <input name="q" value="{{ $q }}" class="input" style="width:300px;font-size:var(--fs-xs);margin-left:auto;"
               placeholder="키워드 · {{ $isPlace ? '플레이스명' : '상품명·몰' }} · 회원(이름/이메일)">
        <button type="submit" class="btn btn-primary btn-sm">검색</button>
    </div>
</form>
@endunless

{{-- 슬롯 목록 — 콘솔과 동일한 카드(공용 컴포넌트 x-rank.slot-card). 회원 뱃지로 어느 회원인지 표시.
     열람 어드민이라 수정/삭제/추가는 없고, 중단/재개·순위체크·공유·이미지만. --}}
@forelse ($slots as $s)
    <x-rank.slot-card :rank-slot="$s" :mode="$mode" area="admin" :show-member="true" :from="null" :to="null" />
@empty
    <div class="card text-center text-muted-soft" style="padding:56px 20px;font-size:var(--fs-xs);">
        {{ ($filterUser ?? null) ? '이 회원의 추적 슬롯이 없습니다.' : (($q !== '' || $active !== '') ? '조건에 맞는 슬롯이 없습니다.' : '등록된 순위추적 슬롯이 없습니다.') }}
    </div>
@endforelse

@unless ($filterUser ?? null)
    <div class="mt-4">{{ $slots->links() }}</div>
@endunless

@include('console.partials._image-save')
@include('rank.partials._card-scripts')
@endsection
