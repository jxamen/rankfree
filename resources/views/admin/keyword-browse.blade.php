@extends('admin.layout')
@section('page-title', '키워드 탐색')

@php
    $stLabel = ['pending' => '대기', 'approved' => '승인', 'rejected' => '보류', 'published' => '발행됨'];
    $srcLabel = ['seed' => '시드', 'related' => '연관', 'autocomplete' => '자동완성', 'user' => '사용자', 'gsc' => '검색유입', 'datalab' => '데이터랩', 'combo' => '지역조합'];
    $rtLabel = ['hotplace' => '핫플', 'district' => '구', 'city' => '시', 'dong' => '동', 'travel' => '여행지'];
    $qs = fn (array $over = []) => array_filter(array_merge(
        ['type' => $type, 'category' => $catId ?: null, 'region' => $region ?: null, 'q' => $q ?: null], $over
    ), fn ($v) => $v !== null && $v !== '');
@endphp

@section('admin-content')
<x-console.page-head title="키워드 탐색" desc="수집된 키워드를 플레이스·쇼핑별로 검색해서 봅니다 — 카테고리·지역으로 좁히기. 승인·발행은 키워드 콘텐츠 허브에서." />

{{-- 플레이스 / 쇼핑 --}}
<div class="flex items-center gap-2 mb-4">
    @foreach (['place' => '플레이스', 'shopping' => '쇼핑'] as $t => $label)
        <a href="{{ route('admin.keyword-browse', ['type' => $t]) }}"
           class="btn {{ $type === $t ? 'btn-primary' : 'btn-secondary' }} btn-sm">{{ $label }}</a>
    @endforeach
    <span class="text-muted ml-auto" style="font-size:var(--fs-xs);">
        검색 결과 <b class="font-mono text-ink">{{ number_format($total) }}</b>개
        @foreach ($stLabel as $k => $label)
            @isset($statusCounts[$k])<span class="text-muted-soft"> · {{ $label }} <b class="font-mono">{{ number_format($statusCounts[$k]) }}</b></span>@endisset
        @endforeach
    </span>
</div>

{{-- 검색 --}}
<div class="card p-4 mb-4">
    <form method="GET" action="{{ route('admin.keyword-browse') }}" class="flex items-center gap-2 flex-wrap">
        <input type="hidden" name="type" value="{{ $type }}">
        <input type="search" name="q" class="input" style="height:38px;width:220px;" placeholder="키워드 검색 (예: 맛집)" value="{{ $q }}">
        <select name="category" class="input" style="height:38px;max-width:200px;" onchange="this.form.submit()">
            <option value="">전체 카테고리</option>
            @foreach ($cats as $c)
                <option value="{{ $c->id }}" @selected($catId === $c->id)>{{ $c->name }} ({{ number_format($c->candidates_count) }})</option>
            @endforeach
        </select>
        @if ($regions->isNotEmpty())
            <select name="region" class="input" style="height:38px;max-width:180px;" onchange="this.form.submit()">
                <option value="">전체 지역</option>
                @foreach ($regions as $rg => $cnt)
                    <option value="{{ $rg }}" @selected($region === (string) $rg)>{{ $rg }} ({{ number_format($cnt) }})</option>
                @endforeach
            </select>
        @endif
        <button type="submit" class="btn btn-secondary btn-sm" style="height:38px;">검색</button>
        @if ($q !== '' || $region !== '' || $catId)
            <a href="{{ route('admin.keyword-browse', ['type' => $type]) }}" class="btn btn-ghost btn-sm" style="height:38px;">초기화</a>
        @endif
    </form>

    {{-- 지역 바로가기(플레이스) --}}
    @if ($regions->isNotEmpty())
        <div class="flex flex-wrap items-center gap-1.5 mt-3 pt-3" style="border-top:1px solid var(--color-hairline-soft);">
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">지역 {{ number_format(count($regions)) }}곳</span>
            <a href="{{ route('admin.keyword-browse', $qs(['region' => null])) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);{{ $region === '' ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">전체</a>
            @foreach ($regions as $rg => $cnt)
                <a href="{{ route('admin.keyword-browse', $qs(['region' => $rg])) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);{{ $region === (string) $rg ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">{{ $rg }} <b class="font-mono">{{ number_format($cnt) }}</b></a>
            @endforeach
        </div>
    @endif
</div>

{{-- 키워드 목록 --}}
<div class="card p-5">
    <div style="overflow-x:auto;">
        <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
            <thead>
                <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                    <th style="padding:8px 6px;">키워드</th>
                    <th style="padding:8px 6px;">카테고리</th>
                    @if ($type === 'place')<th style="padding:8px 6px;">지역</th>@endif
                    <th style="padding:8px 6px;">출처</th>
                    <th style="padding:8px 6px;text-align:right;">월 검색량</th>
                    <th style="padding:8px 6px;">경쟁</th>
                    <th style="padding:8px 6px;">상태</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $it)
                    <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                        <td style="padding:7px 6px;" class="text-ink font-semibold">{{ $it->keyword }}</td>
                        <td style="padding:7px 6px;" class="text-muted">{{ $it->category?->name ?? '—' }}</td>
                        @if ($type === 'place')
                            <td style="padding:7px 6px;" class="text-muted">{{ $it->region ? $it->region.($it->region_type ? ' · '.($rtLabel[$it->region_type] ?? $it->region_type) : '') : '—' }}</td>
                        @endif
                        <td style="padding:7px 6px;"><span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $srcLabel[$it->source] ?? $it->source }}</span></td>
                        <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ $it->monthly_total === null ? '미상' : number_format($it->monthly_total) }}</td>
                        <td style="padding:7px 6px;" class="text-muted">{{ $it->comp_idx ?? '—' }}</td>
                        <td style="padding:7px 6px;" class="text-muted">{{ $stLabel[$it->status] ?? $it->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $type === 'place' ? 7 : 6 }}" class="text-muted-soft text-center" style="padding:40px;">
                        수집된 키워드가 없습니다. 허브에서 수집(hub:collect)이나 시딩(hub:place-seed)을 실행하세요.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $items->links() }}</div>
</div>
@endsection
