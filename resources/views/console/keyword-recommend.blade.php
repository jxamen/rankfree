@extends('console.layout')
@section('page-title', '키워드 추천')

@section('console-content')
@php
    $n = fn ($v) => $v === null ? '—' : number_format((int) $v);
    $compColor = fn ($c) => match ($c) {
        '높음' => 'var(--color-error)', '중간' => 'var(--color-warning)', '낮음' => 'var(--color-success)',
        default => 'var(--color-muted)',
    };
    $maxScore = 1;
    foreach ($recommendations as $r) { $maxScore = max($maxScore, (int) $r['score']); }
@endphp

{{-- 분석(추천) 입력 --}}
<form method="GET" action="{{ route('console.keyword-recommend') }}" class="flex items-center gap-2 mb-4" id="kr-form">
    <input type="text" name="keyword" value="{{ $keyword }}" placeholder="시드 키워드를 입력하세요 (예: 강남 맛집)"
           class="input" style="flex:1;height:44px;font-size:var(--fs-sm);" autofocus autocomplete="off">
    <button type="submit" class="btn btn-primary" style="height:44px;padding:0 22px;">추천</button>
</form>
<p class="text-muted-soft mb-5" style="font-size:var(--fs-xs);">
    시드 키워드의 <b>연관어·자동완성</b>을 모아 <b>기회 점수</b>(검색량 × 경쟁 낮음 가중치)로 랭킹합니다 — 검색량 많고 경쟁 낮은 <b>황금 키워드</b>를 상단에 노출.
</p>

{{-- 최근 검색 --}}
@if (isset($history) && $history->count())
    <div class="mb-6">
        <div class="text-muted mb-2" style="font-size:var(--fs-xs);font-weight:600;">최근 검색</div>
        <div class="flex flex-wrap gap-2">
            @foreach ($history as $h)
                <a href="{{ route('console.keyword-recommend', ['keyword' => $h->keyword]) }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-hairline hover:border-ink transition"
                   style="padding:6px 12px;font-size:var(--fs-xs);background:{{ $h->keyword === $keyword ? 'var(--color-surface-soft)' : 'var(--color-canvas)' }};">
                    <span class="text-ink font-medium">{{ $h->keyword }}</span>
                    <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ number_format($h->monthly_total) }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif

@if ($keyword === '')
    <div class="card p-8 text-center">
        <div style="font-size:var(--fs-2xl);opacity:.35;">💡</div>
        <p class="text-ink font-semibold mt-3" style="font-size:var(--fs-base);">시드 키워드로 추천 키워드를 발굴하세요</p>
        <p class="text-muted mt-1" style="font-size:var(--fs-xs);">검색량 대비 경쟁이 낮은 공략 키워드를 기회 점수 순으로 정렬합니다.</p>
        <div class="flex items-center justify-center gap-2 mt-4 flex-wrap">
            @foreach (['강남 맛집', '다이어트', '캠핑용품', '제주 여행'] as $ex)
                <a href="{{ route('console.keyword-recommend', ['keyword' => $ex]) }}" class="badge" style="font-size:var(--fs-xs);padding:5px 12px;">{{ $ex }}</a>
            @endforeach
        </div>
    </div>

@elseif (! $seed)
    <div class="card-soft px-4 py-4 text-muted" style="font-size:var(--fs-xs);">
        「{{ $keyword }}」 데이터를 조회하지 못했습니다. 검색광고 API 자격증명 또는 키워드를 확인하세요.
    </div>

@else
    {{-- 시드 요약 --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        @foreach ([
            ['시드 키워드', $seed['keyword'], 'var(--color-ink)'],
            ['월간 검색량', $n($seed['total']), 'var(--color-ink)'],
            ['경쟁 강도', $seed['comp_idx'] ?? '—', $compColor($seed['comp_idx'] ?? null)],
            ['추천 키워드 수', number_format(count($recommendations)).'개', 'var(--color-accent)'],
        ] as [$label, $value, $color])
            <div class="card px-4 py-3 flex items-center justify-between gap-2">
                <span class="text-muted" style="font-size:var(--fs-xs);">{{ $label }}</span>
                <span class="font-display" style="font-size:var(--fs-base);color:{{ $color }};">{{ $value }}</span>
            </div>
        @endforeach
    </div>

    {{-- 필터 툴바 --}}
    <div class="card p-3 mb-3">
        <div class="flex items-center gap-3 flex-wrap">
            <input type="text" id="kr-search" placeholder="키워드 검색" autocomplete="off"
                   class="input" style="height:34px;width:200px;font-size:var(--fs-xs);">
            <div class="flex items-center gap-1.5">
                <span class="text-muted" style="font-size:var(--fs-xs);">경쟁</span>
                @foreach (['낮음', '중간', '높음'] as $g)
                    <button type="button" class="kr-chip badge" data-comp="{{ $g }}" style="font-size:var(--fs-xs);padding:3px 10px;cursor:pointer;">{{ $g }}</button>
                @endforeach
            </div>
            <div class="flex items-center gap-1.5">
                <span class="text-muted" style="font-size:var(--fs-xs);white-space:nowrap;">최소 검색량</span>
                <input type="number" id="kr-minvol" min="0" step="100" placeholder="0" autocomplete="off"
                       class="input text-right" style="height:32px;width:100px;font-size:var(--fs-xs);">
            </div>
            <button type="button" id="kr-reset" class="text-muted-soft hover:text-ink" style="font-size:var(--fs-xs);text-decoration:underline;">초기화</button>
            <span class="text-muted-soft" style="font-size:var(--fs-xs);margin-left:auto;"><b id="kr-count">{{ count($recommendations) }}</b>개 표시</span>
        </div>
    </div>

    <div class="card overflow-hidden mb-6">
        <div style="overflow:auto;max-height:680px;">
            <table class="w-full" style="min-width:820px;" id="kr-table">
                <thead>
                    <tr class="text-muted" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);position:sticky;top:0;background:var(--color-canvas);z-index:1;">
                        <th class="text-center px-4 py-2.5 font-semibold" style="width:52px;">No</th>
                        <th class="text-left px-3 py-2.5 font-semibold">키워드</th>
                        <th class="text-right px-3 py-2.5 font-semibold" style="width:110px;">월간 검색량</th>
                        <th class="text-right px-3 py-2.5 font-semibold" style="width:90px;">PC</th>
                        <th class="text-right px-3 py-2.5 font-semibold" style="width:90px;">모바일</th>
                        <th class="text-center px-3 py-2.5 font-semibold" style="width:74px;">경쟁</th>
                        <th class="text-center px-3 py-2.5 font-semibold" style="width:56px;">등급</th>
                        <th class="text-left px-3 py-2.5 font-semibold" style="width:110px;">출처</th>
                        <th class="text-left px-3 py-2.5 font-semibold" style="width:150px;">추천도</th>
                        <th class="text-center px-4 py-2.5 font-semibold" style="width:64px;">분석</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recommendations as $i => $r)
                        <tr class="kr-row" style="border-top:1px solid var(--color-hairline-soft);"
                            data-kw="{{ mb_strtolower($r['keyword']) }}" data-comp="{{ $r['comp_idx'] ?? '' }}" data-vol="{{ $r['total'] ?? 0 }}">
                            <td class="text-center px-4 py-3 text-muted font-display" style="font-size:var(--fs-xs);">{{ $i + 1 }}</td>
                            <td class="px-3 py-3 text-ink" style="font-size:var(--fs-xs);">{{ $r['keyword'] }}</td>
                            <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $n($r['total']) }}</td>
                            <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ $n($r['pc']) }}</td>
                            <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ $n($r['mobile']) }}</td>
                            <td class="text-center px-3 py-3" style="font-size:var(--fs-xs);color:{{ $compColor($r['comp_idx'] ?? null) }};">{{ $r['comp_idx'] ?? '—' }}</td>
                            <td class="text-center px-3 py-3 font-display" style="font-size:var(--fs-xs);">{{ $r['grade'] ?? '—' }}</td>
                            <td class="px-3 py-3 text-muted-soft" style="font-size:var(--fs-xs);">{{ $r['from'] }}</td>
                            <td class="px-3 py-3">
                                @php $pct = (int) round(($r['score'] ?? 0) / $maxScore * 100); @endphp
                                <div class="flex items-center gap-2">
                                    <div style="flex:1;height:6px;background:var(--color-surface-strong);border-radius:99px;overflow:hidden;">
                                        <div style="height:100%;width:{{ $pct }}%;background:var(--color-accent);border-radius:99px;"></div>
                                    </div>
                                    <span class="text-muted-soft" style="font-size:var(--fs-xs);width:30px;text-align:right;">{{ $pct }}</span>
                                </div>
                            </td>
                            <td class="text-center px-4 py-3">
                                <a href="{{ route('console.keyword', ['keyword' => $r['keyword']]) }}" target="_blank" rel="noopener"
                                   class="text-accent hover:underline" style="font-size:var(--fs-xs);" title="「{{ $r['keyword'] }}」 상세 분석(새 창)">분석 →</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted-soft" style="font-size:var(--fs-xs);">* 기회 점수·등급은 검색량·경쟁강도 기반 자체 추정치입니다. 자동완성 항목은 검색량 미상(0)으로 하위 배치됩니다.</p>
@endif

<script>
(function () {
    var tb = document.getElementById('kr-table');
    if (!tb) return;
    var rows = Array.prototype.slice.call(tb.querySelectorAll('.kr-row'));
    var search = document.getElementById('kr-search');
    var minvol = document.getElementById('kr-minvol');
    var count = document.getElementById('kr-count');
    var comps = {};
    document.querySelectorAll('.kr-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            var g = c.dataset.comp;
            comps[g] = !comps[g];
            c.style.background = comps[g] ? 'var(--color-ink)' : '';
            c.style.color = comps[g] ? '#fff' : '';
            apply();
        });
    });
    function apply() {
        var q = (search.value || '').trim().toLowerCase();
        var mv = parseInt(minvol.value || '0', 10) || 0;
        var active = Object.keys(comps).filter(function (k) { return comps[k]; });
        var shown = 0;
        rows.forEach(function (r) {
            var ok = (!q || r.dataset.kw.indexOf(q) !== -1)
                && (parseInt(r.dataset.vol, 10) || 0) >= mv
                && (active.length === 0 || active.indexOf(r.dataset.comp) !== -1);
            r.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        count.textContent = shown;
    }
    search.addEventListener('input', apply);
    minvol.addEventListener('input', apply);
    document.getElementById('kr-reset').addEventListener('click', function () {
        search.value = ''; minvol.value = '';
        comps = {};
        document.querySelectorAll('.kr-chip').forEach(function (c) { c.style.background = ''; c.style.color = ''; });
        apply();
    });
    // 시드 검색 로딩(지연)
    var form = document.getElementById('kr-form');
    if (form) {
        form.addEventListener('submit', function () {
            var kw = (form.querySelector('input[name=keyword]').value || '').trim();
            if (!kw) return;
            setTimeout(function () {
                Swal.fire({ title: '추천 키워드 발굴 중…', allowOutsideClick: false, showConfirmButton: false, didOpen: function () { Swal.showLoading(); } });
            }, 350);
        });
    }
})();
</script>
@endsection
