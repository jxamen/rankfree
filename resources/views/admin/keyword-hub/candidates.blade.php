@extends('admin.layout')
@section('page-title', '후보·수집 관리')
@section('crumb-parent', 'admin.keyword-hub')

@php
    $srcLabel = ['seed' => '시드', 'related' => '연관', 'autocomplete' => '자동완성', 'user' => '사용자', 'gsc' => '검색유입', 'datalab' => '데이터랩', 'combo' => '지역조합'];
    $stLabel = ['pending' => '대기', 'approved' => '승인', 'rejected' => '거부', 'published' => '발행됨'];
    $rtLabel = ['hotplace' => '핫플', 'district' => '구', 'city' => '시', 'dong' => '동', 'travel' => '여행지'];
    $typeLabel = ['place' => '플레이스', 'shopping' => '쇼핑'];
@endphp

@section('admin-content')
<x-console.page-head title="후보·수집 관리" desc="플레이스 지역·업종 조합 후보와 쇼핑 데이터랩 후보를 확인하고 상태를 관리합니다. 발행은 키워드 자동 분석에서 처리합니다." />

{{-- 플래시(status)는 admin.layout 이 전역 표시 — 여기서는 검증 오류만 --}}
@if ($errors->any())
    <div class="card-soft px-4 py-3 mb-4" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

<div class="card p-5 mb-5">
    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
        <div class="flex items-center gap-1 p-1" style="border:1px solid var(--color-hairline);border-radius:10px;background:var(--color-canvas-muted);">
            @foreach ($typeLabel as $k => $label)
                <a href="{{ route('admin.keyword-hub.candidates', ['status' => $status, 'type' => $k, 'q' => $q ?: null]) }}"
                   class="px-3 py-1.5 font-semibold" style="border-radius:8px;font-size:var(--fs-xs);text-decoration:none;{{ $type === $k ? 'background:var(--color-ink);color:var(--color-canvas);' : 'color:var(--color-muted);' }}">
                    {{ $label }} 후보
                </a>
            @endforeach
        </div>
        <div class="text-muted-soft" style="font-size:var(--fs-xs);">
            {{ $type === 'place' ? '지역 기준 + 업종 조합 후보' : '데이터랩 쇼핑 카테고리 후보' }}
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
        @foreach ($stLabel as $k => $label)
            <a href="{{ route('admin.keyword-hub.candidates', ['status' => $k, 'type' => $type]) }}" class="card-soft px-3 py-2" style="display:block;text-decoration:none;{{ $status === $k ? 'outline:2px solid var(--color-ink);' : '' }}">
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $label }}</div>
                <div class="font-mono text-ink font-semibold" style="font-size:var(--fs-lg);">{{ number_format($typeCounts[$k][$type] ?? 0) }}</div>
            </a>
        @endforeach
    </div>

    @if (!empty($sourceCounts) && count($sourceCounts))
        <div class="flex flex-wrap gap-1.5 mt-3 pt-3" style="border-top:1px solid var(--color-hairline-soft);">
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">출처</span>
            @foreach ($srcLabel as $k => $label)
                @isset($sourceCounts[$k])
                    <a href="{{ route('admin.keyword-hub.candidates', ['status' => $status, 'type' => $type, 'source' => $k, 'category' => $catId ?: null]) }}"
                       class="badge border border-hairline" style="font-size:var(--fs-xs);{{ ($source ?? '') === $k ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">
                        {{ $label }} <b class="font-mono">{{ number_format($sourceCounts[$k]) }}</b>
                    </a>
                @endisset
            @endforeach
        </div>
    @endif
</div>

<div class="flex items-center justify-between gap-2 flex-wrap mb-3">
    <form method="GET" action="{{ route('admin.keyword-hub.candidates') }}" class="flex items-center gap-2 flex-wrap">
        <input type="hidden" name="status" value="{{ $status }}">
        <input type="hidden" name="type" value="{{ $type }}">
        <select name="source" class="input" style="height:36px;width:140px;" onchange="this.form.submit()" title="출처 필터">
            <option value="">전체 출처</option>
            @foreach ($srcLabel as $k => $label)
                <option value="{{ $k }}" @selected(($source ?? '') === $k)>{{ $label }}@isset($sourceCounts[$k]) ({{ number_format($sourceCounts[$k]) }})@endisset</option>
            @endforeach
        </select>
        <select name="category" class="input" style="height:36px;width:260px;" onchange="this.form.submit()">
            <option value="">전체 {{ $type === 'shopping' ? '쇼핑 카테고리' : '플레이스 업종' }}</option>
            @foreach ($categories as $c)
                @php
                    $catName = collect([$c->parent?->parent?->name, $c->parent?->name, $c->name])->filter()->implode(' › ');
                @endphp
                <option value="{{ $c->id }}" @selected($catId === $c->id)>{{ $catName }}</option>
            @endforeach
        </select>
        @if ($type === 'place' && !empty($regionCounts) && count($regionCounts))
            <select name="region" class="input" style="height:36px;width:170px;" onchange="this.form.submit()" title="지역 필터(플레이스)">
                <option value="">전체 지역</option>
                @foreach ($regionCounts as $rg => $cnt)
                    <option value="{{ $rg }}" @selected(($region ?? '') === (string) $rg)>{{ $rg }} ({{ number_format($cnt) }})</option>
                @endforeach
            </select>
        @endif
        <input type="search" name="q" class="input" style="height:36px;width:180px;" placeholder="키워드 검색" value="{{ $q ?? '' }}">
        <button type="submit" class="btn btn-secondary btn-sm" style="height:36px;">검색</button>
        @if (($q ?? '') !== '' || ($region ?? '') !== '' || ($source ?? '') !== '' || $catId)
            <a href="{{ route('admin.keyword-hub.candidates', ['status' => $status, 'type' => $type]) }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>
        @endif
    </form>

    @if ($candidates->total() > 0)
        <form method="POST" action="{{ route('admin.keyword-hub.candidates.bulk-all') }}" class="flex items-center gap-2 flex-wrap">
            @csrf
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="hidden" name="type" value="{{ $type }}">
            <input type="hidden" name="category" value="{{ $catId ?: '' }}">
            <input type="hidden" name="source" value="{{ $source ?? '' }}">
            <input type="hidden" name="q" value="{{ $q ?? '' }}">
            <input type="hidden" name="region" value="{{ $region ?? '' }}">
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">필터 전체 <b class="font-mono text-ink">{{ number_format($candidates->total()) }}</b>건</span>
            <button type="submit" name="action" value="approve" class="btn btn-secondary btn-sm" data-confirm="현재 필터의 {{ number_format($candidates->total()) }}건을 모두 승인할까요?" data-confirm-text="승인된 후보는 자동 분석 발행에서 처리됩니다.">전체 승인</button>
            <button type="submit" name="action" value="reject" class="btn btn-secondary btn-sm" data-confirm="현재 필터의 {{ number_format($candidates->total()) }}건을 모두 거부할까요?">전체 거부</button>
            <button type="submit" name="action" value="delete" class="btn btn-ghost btn-sm" data-confirm="현재 필터의 {{ number_format($candidates->total()) }}건을 모두 삭제할까요?" data-confirm-text="삭제는 되돌릴 수 없습니다.">전체 삭제</button>
        </form>
    @endif
</div>

{{-- 후보 승인 큐 --}}
<div class="card p-5 mb-5">
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">{{ $typeLabel[$type] ?? $type }} 후보 큐 — {{ $stLabel[$status] }} <span class="font-mono text-muted">{{ number_format($candidates->total()) }}</span></div>

    <form method="POST" action="{{ route('admin.keyword-hub.candidates.bulk') }}" id="kh-bulk">
        @csrf
        <div style="overflow-x:auto;">
            <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
                <thead>
                    <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                        <th style="padding:8px 6px;width:34px;"><input type="checkbox" id="kh-all" title="전체 선택"></th>
                        <th style="padding:8px 6px;">키워드</th>
                        <th style="padding:8px 6px;">{{ $type === 'shopping' ? '쇼핑 카테고리' : '플레이스 업종' }}</th>
                        @if ($type === 'place')
                            <th style="padding:8px 6px;">지역 기준</th>
                        @endif
                        <th style="padding:8px 6px;">출처</th>
                        <th style="padding:8px 6px;text-align:right;">월 검색량</th>
                        <th style="padding:8px 6px;">경쟁</th>
                        <th style="padding:8px 6px;">비고</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($candidates as $c)
                        <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                            <td style="padding:7px 6px;"><input type="checkbox" name="ids[]" value="{{ $c->id }}" class="kh-ck"></td>
                            <td style="padding:7px 6px;" class="text-ink font-semibold">{{ $c->keyword }}</td>
                            @php
                                $cat = $c->category;
                                $catName = $cat ? collect([$cat->parent?->parent?->name, $cat->parent?->name, $cat->name])->filter()->implode(' › ') : '—';
                            @endphp
                            <td style="padding:7px 6px;" class="text-muted">{{ $catName }}</td>
                            @if ($type === 'place')
                                <td style="padding:7px 6px;" class="text-muted">{{ $c->region ? $c->region.($c->region_type ? ' · '.($rtLabel[$c->region_type] ?? $c->region_type) : '') : '—' }}</td>
                            @endif
                            <td style="padding:7px 6px;"><span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $srcLabel[$c->source] ?? $c->source }}</span></td>
                            <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ $c->monthly_total === null ? '미상' : number_format($c->monthly_total) }}</td>
                            <td style="padding:7px 6px;" class="text-muted">{{ $c->comp_idx ?? '—' }}</td>
                            @php
                                $docLinks = $candidateDocumentLinks[$c->id] ?? [];
                            @endphp
                            <td style="padding:7px 6px;" class="text-muted-soft">
                                <div class="flex items-center gap-1 flex-wrap">
                                    @if (!empty($docLinks['keyword']))
                                        <a href="{{ $docLinks['keyword'] }}" target="_blank" rel="noopener" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;">키워드분석</a>
                                    @endif
                                    @if (!empty($docLinks['market']))
                                        <a href="{{ $docLinks['market'] }}" target="_blank" rel="noopener" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;">쇼핑시장분석</a>
                                    @endif
                                </div>
                                @if ($c->note)
                                    <div class="mt-1">{{ $c->note }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $type === 'place' ? 8 : 7 }}" class="text-muted-soft text-center" style="padding:28px;">'{{ $stLabel[$status] }}' 상태의 {{ $typeLabel[$type] ?? $type }} 후보가 없습니다.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($candidates->count())
            <div class="flex items-center gap-2 mt-3 flex-wrap">
                <button type="submit" name="action" value="approve" class="btn btn-primary btn-sm">선택 승인</button>
                <button type="submit" name="action" value="reject" class="btn btn-secondary btn-sm">선택 거부</button>
                <button type="submit" name="action" value="pending" class="btn btn-secondary btn-sm">대기로</button>
                <button type="submit" name="action" value="delete" class="btn btn-ghost btn-sm" data-confirm="선택한 후보를 삭제할까요?">선택 삭제</button>
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">승인 또는 대기 후보는 키워드 자동 분석에서 플레이스 키워드 분석·쇼핑 시장 분석 문서로 발행됩니다.</span>
            </div>
        @endif
    </form>

    <div class="mt-3">{{ $candidates->links() }}</div>
</div>

<script>
    document.getElementById('kh-all')?.addEventListener('change', function () {
        document.querySelectorAll('.kh-ck').forEach(function (el) { el.checked = this.checked; }, this);
    });
</script>
@endsection
