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
</style>
@endpush

@section('admin-content')
<x-console.page-head :title="$title" :desc="$desc">
    <a href="{{ route('admin.'.($isPlace ? 'shop-tracking' : 'place-tracking')) }}" class="btn btn-secondary btn-sm">{{ $isPlace ? '쇼핑 추적 보기' : '플레이스 추적 보기' }}</a>
</x-console.page-head>

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

{{-- 목록 --}}
<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="trk-tbl" style="min-width:{{ $isPlace ? 900 : 960 }}px;">
            <thead>
                <tr>
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
                        <td>
                            <div class="text-ink" style="font-weight:500;">{{ $s->user?->name ?? '—' }}</div>
                            <div class="text-muted-soft" style="max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $s->user?->email }}</div>
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
                        <td class="r"><span class="{{ $s->last_rank ? 'trk-rank' : '' }}">{{ $s->last_rank ? $fmt($s->last_rank).'위' : '—' }}</span></td>
                        <td class="r">
                            @if ($isPlace)
                                {{ $s->last_review_count ? $fmt($s->last_review_count) : '—' }}
                            @else
                                {{ $s->last_price ? $fmt($s->last_price).'원' : '—' }}
                            @endif
                        </td>
                        <td><span class="trk-chip {{ $s->is_active ? 'ok' : '' }}">{{ $s->is_active ? '활성' : '중지' }}</span></td>
                        <td class="r">{{ $kst($s->last_checked_at) ?? '미확인' }}</td>
                        <td class="r">{{ $kst($s->created_at) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center" style="padding:56px 20px;color:var(--color-muted);">{{ $q !== '' || $active !== '' ? '조건에 맞는 슬롯이 없습니다.' : '등록된 순위추적 슬롯이 없습니다.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $slots->links() }}</div>
@endsection
