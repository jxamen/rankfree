@extends('admin.layout')
@section('page-title', '키워드 탐색')

@php
    $stLabel = ['pending' => '대기', 'approved' => '승인', 'rejected' => '보류', 'published' => '발행됨'];
    $srcLabel = ['seed' => '시드', 'related' => '연관', 'autocomplete' => '자동완성', 'user' => '사용자', 'gsc' => '검색유입', 'datalab' => '데이터랩', 'combo' => '지역조합'];
    // 현재 조건 유지하며 일부만 바꾼 URL
    $url = fn (array $over = []) => route('admin.keyword-browse', array_filter(array_merge([
        'type' => $type, 'category' => $catId ?: null, 'region_type' => $regionType ?: null,
        'region' => $region ?: null, 'q' => $q ?: null, 'rq' => $regionQ ?: null,
    ], $over), fn ($v) => $v !== null && $v !== ''));
@endphp

@section('admin-content')
<x-console.page-head title="키워드 탐색" desc="수집된 키워드를 단계별로 좁혀서 봅니다 — 쇼핑: 1차→2차→3차 / 플레이스: 업종→지역유형→지역. 승인·발행은 키워드 콘텐츠 허브에서." />

{{-- 타입 --}}
<div class="flex items-center gap-2 mb-4">
    @foreach (['place' => '플레이스', 'shopping' => '쇼핑'] as $t => $label)
        <a href="{{ route('admin.keyword-browse', ['type' => $t]) }}" class="btn {{ $type === $t ? 'btn-primary' : 'btn-secondary' }} btn-sm">{{ $label }}</a>
    @endforeach
    <span class="text-muted ml-auto" style="font-size:var(--fs-xs);">
        <b class="font-mono text-ink">{{ number_format($total) }}</b>개
        @foreach ($stLabel as $k => $label)
            @isset($statusCounts[$k])<span class="text-muted-soft"> · {{ $label }} <b class="font-mono">{{ number_format($statusCounts[$k]) }}</b></span>@endisset
        @endforeach
    </span>
</div>

{{-- 경로(브레드크럼) --}}
<div class="flex items-center gap-1.5 flex-wrap mb-3" style="font-size:var(--fs-xs);">
    <a href="{{ route('admin.keyword-browse', ['type' => $type]) }}" class="text-muted" style="text-decoration:none;">{{ $type === 'place' ? '플레이스' : '쇼핑' }} 전체</a>
    @if ($cat?->parent?->parent)
        <span class="text-muted-soft">›</span><a href="{{ $url(['category' => $cat->parent->parent->id, 'region_type' => null, 'region' => null]) }}" class="text-muted" style="text-decoration:none;">{{ $cat->parent->parent->name }}</a>
    @endif
    @if ($cat?->parent)
        <span class="text-muted-soft">›</span><a href="{{ $url(['category' => $cat->parent->id, 'region_type' => null, 'region' => null]) }}" class="text-muted" style="text-decoration:none;">{{ $cat->parent->name }}</a>
    @endif
    @if ($cat)
        <span class="text-muted-soft">›</span><span class="text-ink font-semibold">{{ $cat->name }}</span>
    @endif
    @if ($regionType !== '')
        <span class="text-muted-soft">›</span><a href="{{ $url(['region' => null]) }}" class="text-muted" style="text-decoration:none;">{{ $regionTypes[$regionType] ?? $regionType }}</a>
    @endif
    @if ($region !== '')
        <span class="text-muted-soft">›</span><span class="text-ink font-semibold">{{ $region }}</span>
    @endif
</div>

<div class="card p-4 mb-4">
    {{-- 1단계: 카테고리 드릴다운 --}}
    @if ($subCats->isNotEmpty())
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $cat ? '하위 분류' : ($type === 'shopping' ? '1차 분류' : '업종') }}</span>
            @foreach ($subCats as $sc)
                <a href="{{ $url(['category' => $sc->id, 'region_type' => null, 'region' => null]) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;">
                    {{ $sc->name }} <b class="font-mono">{{ number_format($sc->total_count) }}</b>
                </a>
            @endforeach
        </div>
    @elseif ($cat)
        <div class="text-muted-soft" style="font-size:var(--fs-xs);">하위 분류 없음 — 이 분류의 키워드입니다.</div>
    @endif

    {{-- 2단계(플레이스): 지역 유형 --}}
    @if ($type === 'place' && $regionTypeCounts->isNotEmpty())
        <div class="flex flex-wrap items-center gap-1.5 mt-3 pt-3" style="border-top:1px solid var(--color-hairline-soft);">
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">지역 유형</span>
            <a href="{{ $url(['region_type' => null, 'region' => null]) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;{{ $regionType === '' ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">전체</a>
            @foreach ($regionTypes as $rt => $rtLabel)
                @isset($regionTypeCounts[$rt])
                    <a href="{{ $url(['region_type' => $rt, 'region' => null]) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;{{ $regionType === $rt ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">
                        {{ $rtLabel }} <b class="font-mono">{{ number_format($regionTypeCounts[$rt]) }}</b>
                    </a>
                @endisset
            @endforeach
        </div>
    @endif

    {{-- 3단계(플레이스): 지역 — 유형 선택 후에만. 지역 검색으로 좁히기 --}}
    @if ($type === 'place' && $regionType !== '')
        <div class="mt-3 pt-3" style="border-top:1px solid var(--color-hairline-soft);">
            <form method="GET" action="{{ route('admin.keyword-browse') }}" class="flex items-center gap-2 mb-2">
                <input type="hidden" name="type" value="{{ $type }}">
                <input type="hidden" name="category" value="{{ $catId ?: '' }}">
                <input type="hidden" name="region_type" value="{{ $regionType }}">
                <input type="hidden" name="q" value="{{ $q }}">
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">지역 {{ number_format(count($regions)) }}곳</span>
                <input type="search" name="rq" class="input" style="height:32px;width:150px;" placeholder="지역 검색 (예: 강남)" value="{{ $regionQ }}">
                <button type="submit" class="btn btn-secondary btn-sm" style="height:32px;">찾기</button>
            </form>
            <div class="flex flex-wrap items-center gap-1.5" style="max-height:180px;overflow-y:auto;">
                <a href="{{ $url(['region' => null]) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;{{ $region === '' ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">전체</a>
                @forelse ($regions as $rg => $cnt)
                    <a href="{{ $url(['region' => $rg]) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;{{ $region === (string) $rg ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">{{ $rg }} <b class="font-mono">{{ number_format($cnt) }}</b></a>
                @empty
                    <span class="text-muted-soft" style="font-size:var(--fs-xs);">검색된 지역이 없습니다.</span>
                @endforelse
            </div>
        </div>
    @endif

    {{-- 키워드 검색 --}}
    <form method="GET" action="{{ route('admin.keyword-browse') }}" class="flex items-center gap-2 mt-3 pt-3" style="border-top:1px solid var(--color-hairline-soft);">
        <input type="hidden" name="type" value="{{ $type }}">
        <input type="hidden" name="category" value="{{ $catId ?: '' }}">
        <input type="hidden" name="region_type" value="{{ $regionType }}">
        <input type="hidden" name="region" value="{{ $region }}">
        <input type="search" name="q" class="input" style="height:36px;width:220px;" placeholder="키워드 검색" value="{{ $q }}">
        <button type="submit" class="btn btn-secondary btn-sm" style="height:36px;">검색</button>
        @if ($q !== '' || $region !== '' || $regionType !== '' || $catId)
            <a href="{{ route('admin.keyword-browse', ['type' => $type]) }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>
        @endif
    </form>
</div>

{{-- 키워드 목록 --}}
<div class="card p-5">
    <div style="overflow-x:auto;">
        <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
            <thead>
                <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                    <th style="padding:8px 6px;">키워드</th>
                    <th style="padding:8px 6px;">분류</th>
                    @if ($type === 'place')<th style="padding:8px 6px;">지역</th>@endif
                    <th style="padding:8px 6px;">출처</th>
                    <th style="padding:8px 6px;text-align:right;">월 검색량</th>
                    <th style="padding:8px 6px;">상태</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $it)
                    <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                        <td style="padding:7px 6px;" class="text-ink font-semibold">{{ $it->keyword }}</td>
                        <td style="padding:7px 6px;" class="text-muted">{{ $it->category?->name ?? '—' }}</td>
                        @if ($type === 'place')
                            <td style="padding:7px 6px;" class="text-muted">{{ $it->region ?: '—' }}</td>
                        @endif
                        <td style="padding:7px 6px;"><span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $srcLabel[$it->source] ?? $it->source }}</span></td>
                        <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ $it->monthly_total === null ? '미상' : number_format($it->monthly_total) }}</td>
                        <td style="padding:7px 6px;" class="text-muted">{{ $stLabel[$it->status] ?? $it->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $type === 'place' ? 6 : 5 }}" class="text-muted-soft text-center" style="padding:40px;">키워드가 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $items->links() }}</div>
</div>
@endsection
