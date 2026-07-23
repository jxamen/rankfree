@extends('admin.layout')
@section('page-title', $title)
@use('Illuminate\Support\Carbon')
@use('Illuminate\Support\Str')

@php
    $fmt = fn ($n) => number_format((int) $n);
    $kst = fn ($v) => $v ? Carbon::parse($v)->timezone('Asia/Seoul')->format('Y-m-d H:i') : null;
    $isPlace = $mode === 'place';
@endphp

@push('head')
<style>
    .trk-stats { display:grid; gap:12px; grid-template-columns:repeat(2,1fr); margin-bottom:20px; }
    @media(min-width:680px){ .trk-stats{ grid-template-columns:repeat(4,1fr); } }
    .trk-stats .lab { color:var(--color-muted); font-size:var(--fs-xs); }
    .trk-stats .val { color:var(--color-ink); font-family:var(--font-mono); font-size:var(--fs-lg); font-weight:650; margin-top:4px; }
    .trk-tbl { width:100%; border-collapse:collapse; font-size:var(--fs-xs); }
    .trk-tbl thead th { color:var(--color-muted); font-weight:600; text-align:left; padding:10px 8px; white-space:nowrap; }
    .trk-tbl thead th.r { text-align:right; }
    .trk-tbl tbody td { padding:11px 8px; border-top:1px solid var(--color-hairline-soft); vertical-align:middle; }
    .trk-tbl tbody td.r { text-align:right; font-family:var(--font-mono); white-space:nowrap; color:var(--color-muted); }
    .trk-tbl .kw { color:var(--color-ink); font-weight:600; }
    .trk-tbl .tg { color:var(--color-body); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:240px; display:inline-block; vertical-align:middle; }
    .trk-tbl a.tg:hover { color:var(--color-primary); text-decoration:underline; }
    .trk-chip { display:inline-block; font-size:var(--fs-xs); padding:1px 8px; border-radius:99px; background:var(--color-surface-strong); color:var(--color-muted); white-space:nowrap; }
    .trk-chip.ok { background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas)); color:var(--color-success); }
    .trk-rank { color:var(--color-ink); font-weight:650; }
    /* 회원(업체) 보기 — 콘솔 순위추적과 동일한 날짜별 카드(rank/shop-rank partials.cells 공용) */
    .rf-cell { width:{{ $isPlace ? 104 : 100 }}px; padding:10px 8px 8px; }
</style>
@endpush

@section('admin-content')
<x-console.page-head :title="$title" :desc="$desc">
    {{-- 전환 시 회원 필터 유지 — 같은 회원의 플레이스·쇼핑 추적을 오가며 볼 수 있게(2026-07-24) --}}
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
        <select name="active" class="input" style="width:130px;font-size:var(--fs-xs);">
            <option value="">전체 상태</option>
            <option value="1" @selected($active === '1')>활성</option>
            <option value="0" @selected($active === '0')>중지</option>
        </select>
        @if ($q !== '' || $active !== '')
            <a href="{{ route($routeName) }}" class="btn btn-ghost btn-sm">초기화</a>
        @endif
        <input name="q" value="{{ $q }}" class="input" style="width:300px;font-size:var(--fs-xs);margin-left:auto;"
               placeholder="키워드 · {{ $isPlace ? '플레이스명' : '상품명·몰' }} · 회원(이름/이메일)">
    </div>
</form>
@endunless

{{-- 회원(업체) 보기 — 콘솔 순위추적과 동일: 키워드 슬롯 카드 + 날짜별 순위 그리드(2026-07-24) --}}
@if ($filterUser ?? null)
    <div class="flex flex-col gap-4">
        @forelse ($slots as $slot)
            <div class="card overflow-hidden rf-slot">
                <div class="px-4 pt-4 pb-3 flex items-center gap-3 flex-wrap" style="border-bottom:1px solid var(--color-hairline-soft);">
                    <span class="text-ink" style="font-size:var(--fs-sm);font-weight:700;">{{ $slot->keyword }}</span>
                    <a href="{{ $slot->shareUrl() }}" target="_blank" rel="noopener" class="text-muted hover:text-ink truncate" style="font-size:var(--fs-xs);max-width:380px;" title="공유 리포트 열기">
                        {{ $isPlace ? ($slot->place_name ?: '—') : ($slot->product_title ?: ($slot->mall_name ?: '—')) }} ↗</a>
                    @unless ($slot->is_active)
                        <span class="trk-chip" style="color:var(--color-error);">체크 중단됨</span>
                    @endunless
                    <form method="POST" action="{{ route('admin.'.($isPlace ? 'place' : 'shop').'-tracking.toggle', $slot) }}" style="display:inline;">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm" title="{{ $slot->is_active ? '순위체크 일시 중단(기록 유지)' : '순위체크 재개' }}">{{ $slot->is_active ? '중단' : '재개' }}</button>
                    </form>
                    <div class="flex-1"></div>
                    @if ($slot->last_checked_at)
                        <span class="text-muted-soft" style="font-size:var(--fs-xs);">최종 수집 {{ $kst($slot->last_checked_at) }}</span>
                    @endif
                    {{-- 추적 주소 전체 표기(2026-07-24) — 자르지 않고 전부 --}}
                    @php $slotUrl = $isPlace ? $slot->place_url : $slot->product_url; @endphp
                    @if ($slotUrl)
                        <div class="w-full" style="font-size:var(--fs-xs);word-break:break-all;">
                            <a href="{{ $slotUrl }}" target="_blank" rel="noopener" class="text-muted-soft hover:text-ink hover:underline">{{ $slotUrl }}</a>
                        </div>
                    @endif
                </div>
                {{-- 날짜별 순위 카드 — 콘솔·공개 리포트와 공용 파셜 --}}
                @include(($isPlace ? 'rank' : 'shop-rank').'.partials.cells', ['slot' => $slot, 'from' => null, 'to' => null])
            </div>
        @empty
            <div class="card text-center text-muted-soft" style="padding:56px 20px;font-size:var(--fs-xs);">이 회원의 추적 슬롯이 없습니다.</div>
        @endforelse
    </div>
@else
{{-- 목록 --}}
<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="trk-tbl" style="min-width:{{ $isPlace ? 900 : 960 }}px;">
            <thead>
                <tr>
                    <th style="width:56px;">No</th>
                    <th>회원</th>
                    <th>키워드</th>
                    <th>{{ $isPlace ? '플레이스' : '상품' }}</th>
                    <th>{{ $isPlace ? '카테고리' : '몰 · 유형' }}</th>
                    <th class="r">현재 순위</th>
                    <th class="r">{{ $isPlace ? '리뷰수' : '가격' }}</th>
                    <th>상태</th>
                    <th class="r">최근 확인</th>
                    <th class="r">등록일</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($slots as $s)
                    <tr>
                        {{-- No — 최신이 가장 큰 번호(desc, 페이지 걸쳐 연속) --}}
                        <td class="text-muted-soft" style="font-family:var(--font-mono);">{{ $slots->total() - $slots->firstItem() + 1 - $loop->index }}</td>
                        <td>
                            {{-- 아이디 클릭 → 이 회원의 추적 리스트(플레이스·쇼핑 전환 시 필터 유지) --}}
                            @if ($s->user)
                                <a href="{{ route($routeName, ['user' => $s->user_id]) }}" title="{{ $s->user->name }} 회원의 추적만 보기">
                                    <div class="text-ink hover:underline" style="font-weight:500;">{{ $s->user->name }}</div>
                                    <div class="text-muted-soft" style="max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $s->user->email }}</div>
                                </a>
                            @else
                                <div class="text-muted-soft">—</div>
                            @endif
                        </td>
                        <td><span class="kw">{{ $s->keyword }}</span></td>
                        <td>
                            <a href="{{ $s->shareUrl() }}" target="_blank" rel="noopener" class="tg" title="{{ $isPlace ? $s->place_name : $s->product_title }}">{{ $isPlace ? ($s->place_name ?: '—') : ($s->product_title ?: '—') }}</a>
                        </td>
                        <td class="text-muted">
                            @if ($isPlace)
                                {{ $s->category ?: '—' }}
                            @else
                                <div>{{ $s->mall_name ?: '—' }}</div>
                                <div class="text-muted-soft">{{ $s->target_type === 'product' ? '상품' : ($s->target_type === 'mall' ? '업체' : $s->target_type) }}</div>
                            @endif
                        </td>
                        {{-- 현재 순위 — 콘솔 순위 카드와 동일 스타일: N위 + 전일 대비(+초록/−빨강), 미노출 300+/1000+, 차단(2026-07-24) --}}
                        <td class="r">
                            @php
                                $r0 = $s->records[0] ?? null; $r1 = $s->records[1] ?? null;
                                $rmax = $isPlace ? 300 : 1000;
                                $v0 = $r0 && $r0->rank > 0 && $r0->rank < $rmax;
                                $v1 = $r1 && $r1->rank > 0 && $r1->rank < $rmax;
                                $rdiff = ($v0 && $v1) ? $r1->rank - $r0->rank : null;
                            @endphp
                            @if ($v0)
                                <b class="text-ink font-display" style="font-size:var(--fs-sm);">{{ $fmt($r0->rank) }}위</b>
                                @if ($rdiff)<span style="font-weight:700;color:{{ $rdiff > 0 ? 'var(--color-success)' : 'var(--color-error)' }};">{{ $rdiff > 0 ? '+'.$rdiff : $rdiff }}</span>@endif
                            @elseif ($r0 && $r0->rank < 0)
                                <span style="color:var(--color-error);">차단</span>
                            @elseif ($r0)
                                <span class="text-muted-soft">{{ $rmax }}+</span>
                            @else
                                <span class="text-muted-soft">—</span>
                            @endif
                        </td>
                        <td class="r">
                            @if ($isPlace)
                                {{ $s->last_review_count ? $fmt($s->last_review_count) : '—' }}
                            @else
                                {{ $s->last_price ? $fmt($s->last_price).'원' : '—' }}
                            @endif
                        </td>
                        {{-- 상태 + 중단/재개 토글(2026-07-24) --}}
                        <td style="white-space:nowrap;">
                            <span class="trk-chip {{ $s->is_active ? 'ok' : '' }}">{{ $s->is_active ? '활성' : '중지' }}</span>
                            <form method="POST" action="{{ route('admin.'.($isPlace ? 'place' : 'shop').'-tracking.toggle', $s) }}" style="display:inline;">
                                @csrf
                                <button type="submit" class="btn btn-ghost btn-sm" style="height:24px;padding:0 8px;font-size:var(--fs-xs);"
                                        title="{{ $s->is_active ? '순위체크 일시 중단(기록 유지)' : '순위체크 재개' }}">{{ $s->is_active ? '중단' : '재개' }}</button>
                            </form>
                        </td>
                        <td class="r">{{ $kst($s->last_checked_at) ?? '미확인' }}</td>
                        <td class="r">{{ $kst($s->created_at) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center" style="padding:56px 20px;color:var(--color-muted);">{{ $q !== '' || $active !== '' || ($userId ?? 0) > 0 ? '조건에 맞는 슬롯이 없습니다.' : '등록된 순위추적 슬롯이 없습니다.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $slots->links() }}</div>
@endif
@endsection
