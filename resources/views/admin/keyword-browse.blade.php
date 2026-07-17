@extends('admin.layout')
@section('page-title', '키워드 탐색')

@php
    $stLabel = ['pending' => '대기', 'approved' => '승인', 'rejected' => '보류', 'published' => '발행됨'];
    $srcLabel = ['seed' => '시드', 'related' => '연관', 'autocomplete' => '자동완성', 'user' => '사용자', 'gsc' => '검색유입', 'datalab' => '데이터랩', 'combo' => '지역조합'];
@endphp

@section('admin-content')
<x-console.page-head title="키워드 탐색" desc="수집된 키워드를 분야별로 봅니다 — 상위를 고르면 하위 분류가 채워지고, 고르지 않으면 전체 키워드가 나옵니다. 승인·발행은 키워드 콘텐츠 허브에서." />

{{-- 타입 --}}
<div class="flex items-center gap-2 mb-4">
    @foreach (['shopping' => '쇼핑', 'place' => '플레이스'] as $t => $label)
        <a href="{{ route('admin.keyword-browse', ['type' => $t]) }}" class="btn {{ $type === $t ? 'btn-primary' : 'btn-secondary' }} btn-sm">{{ $label }}</a>
    @endforeach
    <span class="text-muted ml-auto" style="font-size:var(--fs-xs);">
        @if (($refreshed ?? 0) > 0)
            <span class="badge border border-hairline" title="검색량은 {{ $volumeTtlDays ?? 7 }}일마다 자동 갱신됩니다">검색량 {{ $refreshed }}건 갱신됨</span>
        @endif
        <b class="font-mono text-ink">{{ number_format($total) }}</b>개
        @foreach ($stLabel as $k => $label)
            @isset($statusCounts[$k])<span class="text-muted-soft"> · {{ $label }} <b class="font-mono">{{ number_format($statusCounts[$k]) }}</b></span>@endisset
        @endforeach
    </span>
</div>

{{-- 분야 셀렉트 — 항상 3단 고정 표기. 상위 선택 시 하위 option 이 채워진다(미선택이면 비활성). --}}
<div class="card p-4 mb-4">
    {{-- 한 줄 고정 — 좁으면 가로 스크롤(줄바꿈 금지) --}}
    <form method="GET" action="{{ route('admin.keyword-browse') }}" id="kb-form" class="flex items-center gap-2" style="flex-wrap:nowrap;overflow-x:auto;">
        <input type="hidden" name="type" value="{{ $type }}">
        <span class="text-ink font-semibold flex-none" style="font-size:var(--fs-xs);">분야</span>

        {{-- 1단: 쇼핑=1분류 / 플레이스=업종 --}}
        <select name="c1" class="input" style="height:36px;min-width:150px;" onchange="kbGo(this, ['c2','c3','sido','sgg','rg'])">
            <option value="">{{ $type === 'shopping' ? '1분류' : '업종' }}</option>
            @foreach ($lv1 as $c)
                <option value="{{ $c->id }}" @selected($c1 === $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        <span class="text-muted-soft">›</span>

        @if ($type === 'shopping')
            {{-- 2단: 2분류 --}}
            <select name="c2" class="input" style="height:36px;min-width:150px;" onchange="kbGo(this, ['c3'])" @disabled($lv2->isEmpty())>
                <option value="">2분류</option>
                @foreach ($lv2 as $c)
                    <option value="{{ $c->id }}" @selected($c2 === $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
            <span class="text-muted-soft">›</span>
            {{-- 3단: 3분류 --}}
            <select name="c3" class="input" style="height:36px;min-width:150px;" onchange="kbGo(this, [])" @disabled($lv3->isEmpty())>
                <option value="">3분류</option>
                @foreach ($lv3 as $c)
                    <option value="{{ $c->id }}" @selected($c3 === $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        @else
            {{-- 플레이스 지역 3단 — 공개 /keywords/place 와 동일 계층(시/도 › 시/군/구 › 지역) --}}
            <select name="sido" class="input" style="height:36px;min-width:130px;" onchange="kbGo(this, ['sgg','rg'])">
                <option value="">시/도</option>
                @foreach ($sidos as $s => $cnt)
                    <option value="{{ $s }}" @selected($sido === (string) $s)>{{ $s }} ({{ number_format($cnt) }})</option>
                @endforeach
            </select>
            <span class="text-muted-soft">›</span>
            <select name="sgg" class="input" style="height:36px;min-width:150px;" onchange="kbGo(this, ['rg'])" @disabled(empty($sggs))>
                <option value="">시/군/구</option>
                @foreach ($sggs as $g => $cnt)
                    <option value="{{ $g }}" @selected($sgg === (string) $g)>{{ $g }} ({{ number_format($cnt) }})</option>
                @endforeach
            </select>
            <span class="text-muted-soft">›</span>
            <select name="rg" class="input" style="height:36px;min-width:170px;" onchange="kbGo(this, [])" @disabled(empty($regions))>
                <option value="">지역{{ $regions ? ' ('.number_format(count($regions)).'곳)' : '' }}</option>
                @foreach ($regions as $r => $cnt)
                    <option value="{{ $r }}" @selected($rg === (string) $r)>{{ $r }} ({{ number_format($cnt) }})</option>
                @endforeach
            </select>
        @endif

        <span class="mx-1"></span>
        <input type="search" name="q" class="input" style="height:36px;width:200px;" placeholder="키워드 검색" value="{{ $q }}">
        <button type="submit" class="btn btn-secondary btn-sm" style="height:36px;">검색</button>
        @if ($c1 || $c2 || $c3 || $sido !== '' || $sgg !== '' || $rg !== '' || $q !== '')
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
                    <th style="padding:8px 6px;">최종 업데이트</th>
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
                        <td style="padding:7px 6px;" class="text-muted-soft" title="{{ $it->volume_checked_at?->format('Y-m-d H:i') ?? '조회 전' }}">
                            {{ $it->volume_checked_at ? $it->volume_checked_at->diffForHumans() : '—' }}
                        </td>
                        <td style="padding:7px 6px;" class="text-muted">{{ $stLabel[$it->status] ?? $it->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $type === 'place' ? 7 : 6 }}" class="text-muted-soft text-center" style="padding:40px;">키워드가 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $items->links() }}</div>
</div>

<script>
    // 상위 분류를 바꾸면 하위 선택은 비우고 다시 조회(데이터랩과 동일한 연동)
    function kbGo(sel, clear) {
        var f = document.getElementById('kb-form');
        (clear || []).forEach(function (n) { var el = f.elements[n]; if (el) el.value = ''; });
        f.submit();
    }
</script>
@endsection
