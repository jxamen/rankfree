@extends('console.layout')
@section('page-title', '경쟁 분석 · '.$slot->keyword)

@section('console-content')
@php
    $bar = function ($v, $color = 'var(--color-primary)') {
        $w = $v === null ? 0 : max(0, min(100, (float) $v));
        return '<div style="height:6px;background:var(--color-surface-strong);border-radius:99px;overflow:hidden;"><div style="height:100%;width:'.$w.'%;background:'.$color.';"></div></div>';
    };
    $fmt = fn ($v) => $v === null ? '—' : round($v);
    $dimMeta = [
        'd1' => ['방문자 리뷰', .18], 'd2' => ['블로그 리뷰', .09], 'd3' => ['예약 리뷰', .07], 'd4' => ['평점', .12],
        'd5' => ['저장수', .08], 'd7' => ['정보 충실성', .14], 'd9' => ['최근 활동', .20], 'd10' => ['리뷰 영향력', .12],
    ];
    $nd = $dates->count();
@endphp

<div>
    <a href="{{ route('console.compete') }}" class="text-muted hover:text-ink" style="font-size:13px;">← 경쟁 분석 목록</a>

    <div class="flex items-end justify-between flex-wrap gap-3 mt-2 mb-5">
        <div>
            <div class="font-display text-ink" style="font-size:22px;">{{ $slot->keyword }}</div>
            <div class="text-muted-soft" style="font-size:13px;">{{ $slot->place_name ?: ('ID '.$slot->place_id) }} @if ($ymd)· 분석일 {{ \Illuminate\Support\Carbon::parse($ymd)->format('Y.m.d') }}@endif</div>
        </div>
        <form method="POST" action="{{ route('console.compete.analyze', $slot) }}" class="rf-analyze-form" data-keyword="{{ $slot->keyword }}">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm">{{ $ymd ? '분석 갱신' : '분석 시작' }}</button>
        </form>
    </div>

    @unless ($ymd)
        <div class="card p-8 text-center">
            <div style="font-size:30px;opacity:.4;">📊</div>
            <p class="text-muted mt-2" style="font-size:14px;">아직 분석 데이터가 없습니다. 위 <b class="text-ink">분석 시작</b>을 눌러 경쟁사 대비 점수를 산출하세요. (20~40초 소요)</p>
        </div>
    @else
        {{-- KPI --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            @php
                $kpis = [
                    ['순위', $mine && $mine->rnk > 0 && $mine->rnk < 300 ? $mine->rnk.'위' : '300+', null],
                    ['N1 유사도', $fmt($mine?->n1), $mine?->n1],
                    ['N2 관련성', $fmt($mine?->n2), $mine?->n2],
                    ['N3 랭킹', $fmt($mine?->n3), $mine?->n3],
                ];
            @endphp
            @foreach ($kpis as [$label, $val, $sc])
                <div class="card p-4">
                    <div class="text-muted" style="font-size:12px;">{{ $label }}</div>
                    <div class="font-display text-ink mt-1" style="font-size:24px;">{{ $val }}</div>
                    @if ($sc !== null)<div class="mt-2">{!! $bar($sc) !!}</div>@endif
                </div>
            @endforeach
        </div>

        {{-- 경쟁 비교표 (일별 순위 + 주별 신규 리뷰) --}}
        <div class="card overflow-x-auto mb-6">
            <table class="w-full" style="min-width:1180px;border-collapse:collapse;white-space:nowrap;">
                <thead>
                    <tr class="text-muted" style="font-size:11px;border-bottom:1px solid var(--color-hairline-soft);">
                        <th rowspan="2" class="text-right px-2 py-2 font-semibold" style="width:40px;">순위</th>
                        <th rowspan="2" class="text-left px-3 py-2 font-semibold">매장</th>
                        <th colspan="{{ max(1, $nd) }}" class="text-center px-2 py-1 font-semibold" style="border-left:1px solid var(--color-hairline-soft);">일별 순위 <span class="text-muted-soft">(좌=최신)</span></th>
                        <th colspan="5" class="text-center px-2 py-1 font-semibold" style="border-left:1px solid var(--color-hairline-soft);">영수증 리뷰 <span class="text-muted-soft">주별 신규·총</span></th>
                        <th colspan="5" class="text-center px-2 py-1 font-semibold" style="border-left:1px solid var(--color-hairline-soft);">블로그 리뷰 <span class="text-muted-soft">주별 신규·총</span></th>
                        <th rowspan="2" class="text-right px-2 py-2 font-semibold" style="border-left:1px solid var(--color-hairline-soft);">평점</th>
                        <th rowspan="2" class="text-right px-2 py-2 font-semibold">정보충실</th>
                        <th rowspan="2" class="text-right px-2 py-2 font-semibold">N1</th>
                        <th rowspan="2" class="text-right px-2 py-2 font-semibold">N2</th>
                        <th rowspan="2" class="text-right px-3 py-2 font-semibold">N3</th>
                    </tr>
                    <tr class="text-muted-soft" style="font-size:10px;border-bottom:1px solid var(--color-hairline-soft);">
                        @foreach ($dates as $d)
                            <th class="text-center px-1 py-1 font-medium @if($loop->first) text-ink @endif" style="@if($loop->first)border-left:1px solid var(--color-hairline-soft);@endif">{{ \Illuminate\Support\Carbon::parse($d)->format('n/j') }}</th>
                        @endforeach
                        <th class="text-center px-1 py-1" style="border-left:1px solid var(--color-hairline-soft);">1주</th><th class="text-center px-1 py-1">2주</th><th class="text-center px-1 py-1">3주</th><th class="text-center px-1 py-1">4주</th><th class="text-center px-1 py-1 text-muted">총</th>
                        <th class="text-center px-1 py-1" style="border-left:1px solid var(--color-hairline-soft);">1주</th><th class="text-center px-1 py-1">2주</th><th class="text-center px-1 py-1">3주</th><th class="text-center px-1 py-1">4주</th><th class="text-center px-1 py-1 text-muted">총</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr style="border-top:1px solid var(--color-hairline-soft);{{ $r->is_mine ? 'background:color-mix(in srgb,var(--color-primary) 5%,var(--color-canvas));' : '' }}">
                            <td class="px-2 py-2 text-right text-muted" style="font-size:12px;">{{ $r->rnk < 300 ? $r->rnk : '—' }}</td>
                            <td class="px-3 py-2">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-ink" style="font-size:13px;font-weight:{{ $r->is_mine ? 700 : 500 }};">{{ $r->is_mine ? '⭐ ' : '' }}{{ $r->name }}</span>
                                    @if ($r->tier == 1)<span class="text-muted-soft" style="font-size:10px;">리스트</span>@endif
                                    <button type="button" class="rf-detail-btn" data-place="{{ $r->place_id }}" style="font-size:10px;color:var(--color-muted);border:1px solid var(--color-hairline);border-radius:5px;padding:1px 5px;background:none;cursor:pointer;">상세</button>
                                    <button type="button" class="rf-trend-btn" data-place="{{ $r->place_id }}" style="font-size:10px;color:var(--color-muted);border:1px solid var(--color-hairline);border-radius:5px;padding:1px 5px;background:none;cursor:pointer;">추이</button>
                                </div>
                            </td>
                            @foreach ($dates as $i => $d)
                                @php
                                    $rk = $r->daily[$d] ?? null;
                                    $prevD = $dates[$i + 1] ?? null;
                                    $prk = $prevD ? ($r->daily[$prevD] ?? null) : null;
                                    $col = 'var(--color-muted)';
                                    if ($rk !== null && $prk !== null && $rk < 300 && $prk < 300) {
                                        $col = $rk < $prk ? 'var(--color-primary)' : ($rk > $prk ? 'var(--color-error)' : 'var(--color-muted)');
                                    }
                                @endphp
                                <td class="text-center px-1 py-2" style="font-size:12px;color:{{ $col }};@if($i===0)border-left:1px solid var(--color-hairline-soft);@endif">{{ ($rk !== null && $rk > 0 && $rk < 300) ? $rk : '·' }}</td>
                            @endforeach
                            @php $wv = $r->wv; @endphp
                            @for ($k = 0; $k < 4; $k++)
                                <td class="text-center px-1 py-2" style="font-size:12px;@if($k===0)border-left:1px solid var(--color-hairline-soft);@endif">
                                    @if ($wv && $wv[$k] > 0)<span class="text-ink">{{ $wv[$k] }}</span>@else<span class="text-muted-soft">·</span>@endif
                                </td>
                            @endfor
                            <td class="text-center px-1 py-2 text-muted" style="font-size:12px;">{{ $r->visitor !== null ? number_format($r->visitor) : '—' }}</td>
                            @php $wb = $r->wb; @endphp
                            @for ($k = 0; $k < 4; $k++)
                                <td class="text-center px-1 py-2" style="font-size:12px;@if($k===0)border-left:1px solid var(--color-hairline-soft);@endif">
                                    @if ($wb && $wb[$k] > 0)<span class="text-ink">{{ $wb[$k] }}</span>@else<span class="text-muted-soft">·</span>@endif
                                </td>
                            @endfor
                            <td class="text-center px-1 py-2 text-muted" style="font-size:12px;">{{ $r->blog !== null ? number_format($r->blog) : '—' }}</td>
                            <td class="px-2 py-2 text-right text-muted" style="font-size:12px;border-left:1px solid var(--color-hairline-soft);">{{ $r->score !== null ? number_format($r->score, 2) : '—' }}</td>
                            <td class="px-2 py-2 text-right text-ink" style="font-size:12px;">{{ $fmt($r->d7) }}</td>
                            <td class="px-2 py-2 text-right text-ink" style="font-size:12px;">{{ $fmt($r->n1) }}</td>
                            <td class="px-2 py-2 text-right text-ink" style="font-size:12px;">{{ $fmt($r->n2) }}</td>
                            <td class="px-3 py-2 text-right text-ink" style="font-size:13px;font-weight:600;">{{ $fmt($r->n3) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- 내 매장 점수 근거 --}}
        @if ($explain)
            <div class="grid md:grid-cols-2 gap-4 mb-6">
                <div class="card p-5">
                    <div class="text-ink font-semibold mb-3" style="font-size:14px;">N1 유사도 구성 <span class="text-muted-soft" style="font-weight:400;">(내 매장)</span></div>
                    @php $comp = $explain['components']; $cmpMeta = ['L' => '지역 일치', 'B' => '업종 일치', 'T' => '대표키워드', 'M' => '상호 일치']; @endphp
                    @foreach ($cmpMeta as $k => $lab)
                        <div class="flex items-center gap-3 mb-2">
                            <div class="text-muted" style="font-size:12px;width:72px;">{{ $lab }}</div>
                            <div style="flex:1;">{!! $bar($comp[$k] === null ? 0 : $comp[$k] * 100) !!}</div>
                            <div class="text-ink text-right" style="font-size:12px;width:44px;">{{ $comp[$k] === null ? 'N/A' : round($comp[$k] * 100).'%' }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="card p-5">
                    <div class="text-ink font-semibold mb-3" style="font-size:14px;">N2 관련성 차원</div>
                    @foreach ($dimMeta as $k => [$lab, $w])
                        @php $v = $explain['dims']?->$k; @endphp
                        <div class="flex items-center gap-3 mb-1.5">
                            <div class="text-muted" style="font-size:12px;width:72px;">{{ $lab }}</div>
                            <div style="flex:1;">{!! $bar($v) !!}</div>
                            <div class="text-ink text-right" style="font-size:12px;width:36px;">{{ $v === null ? '—' : round($v) }}</div>
                            <div class="text-muted-soft text-right" style="font-size:11px;width:34px;">{{ intval($w * 100) }}%</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card p-5 mb-6">
                <div class="text-ink font-semibold mb-3" style="font-size:14px;">정보 충실성 (D7 = {{ $fmt($mine?->d7) }})</div>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-2">
                    @foreach ($explain['seo'] as $it)
                        @if ($it['avail'])
                            <div class="flex items-center justify-between" style="font-size:12px;">
                                <span class="text-muted">{{ $it['label'] }}</span>
                                <span style="color:{{ $it['grade'] >= 0.99 ? 'var(--color-primary)' : ($it['grade'] > 0 ? 'var(--color-ink)' : 'var(--color-muted-soft)') }};">
                                    {{ $it['raw'] }} {{ $it['grade'] >= 0.99 ? '✓' : ($it['grade'] > 0 ? '·' : '✕') }}
                                </span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            @if ($explain['daily'] && $explain['daily']->review_quality)
                @php $rq = $explain['daily']->review_quality; $au = $rq['authority'] ?? null; @endphp
                <div class="card p-5 mb-6">
                    <div class="text-ink font-semibold mb-3" style="font-size:14px;">리뷰 품질 <span class="text-muted-soft" style="font-weight:400;">(최근 4주 · D9 최근성 {{ $fmt($explain['dims']?->d9) }} · D10 영향력 {{ $fmt($explain['dims']?->d10) }})</span></div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-3">
                        <div><div class="text-muted-soft" style="font-size:11px;">사진 포함</div><div class="text-ink font-display" style="font-size:18px;">{{ round(($rq['photo_ratio'] ?? 0) * 100) }}%</div></div>
                        @if ($au)
                            <div><div class="text-muted-soft" style="font-size:11px;">인플루언서 <span style="opacity:.7;">팔로워 100+</span></div><div class="text-ink font-display" style="font-size:18px;">{{ $au['infl'] }}<span style="font-size:12px;"> 명</span></div></div>
                            <div><div class="text-muted-soft" style="font-size:11px;">파워리뷰어 <span style="opacity:.7;">리뷰 100+</span></div><div class="text-ink font-display" style="font-size:18px;">{{ $au['power'] }}<span style="font-size:12px;"> 명</span></div></div>
                            <div><div class="text-muted-soft" style="font-size:11px;">평균 팔로워</div><div class="text-ink font-display" style="font-size:18px;">{{ number_format($au['avg_fol']) }}</div></div>
                        @endif
                    </div>
                    @if ($au && ! empty($au['top']))
                        <div class="text-muted-soft mb-1.5" style="font-size:11px;">주요 리뷰어</div>
                        <div class="flex flex-wrap gap-1.5 mb-2">
                            @foreach (array_slice($au['top'], 0, 5) as $t)
                                <span class="badge">{{ $t['n'] ?: '익명' }} · 팔로워 {{ number_format($t['f']) }}</span>
                            @endforeach
                        </div>
                    @endif
                    @if (! empty($rq['bloggers']))
                        <div class="text-muted-soft mb-1.5 mt-2" style="font-size:11px;">블로그 리뷰어</div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($rq['bloggers'] as $bl)
                                <a href="https://blog.naver.com/{{ $bl['id'] }}" target="_blank" class="badge" style="text-decoration:none;">{{ $bl['n'] ?: $bl['id'] }}</a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        @endif

        {{-- 시계열 --}}
        @if ($series->count() >= 2)
            @php
                $w = 640; $h = 90; $n = $series->count();
                $line = function ($key, $color) use ($series, $w, $h, $n) {
                    $pts = $series->values()->map(function ($r, $i) use ($key, $w, $h, $n) {
                        $x = $n > 1 ? round($i / ($n - 1) * $w, 1) : 0;
                        $y = round($h - (max(0, min(100, (float) ($r->$key ?? 0))) / 100) * ($h - 8) - 4, 1);
                        return $x.','.$y;
                    })->implode(' ');
                    return '<polyline fill="none" stroke="'.$color.'" stroke-width="1.8" points="'.$pts.'"/>';
                };
            @endphp
            <div class="card p-5 mb-6">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-ink font-semibold" style="font-size:14px;">내 매장 점수 추이</div>
                    <div class="flex gap-3" style="font-size:11px;">
                        <span style="color:var(--color-primary);">● N1</span>
                        <span style="color:#7c9cff;">● N2</span>
                        <span style="color:#f2a24d;">● N3</span>
                    </div>
                </div>
                <svg viewBox="0 0 {{ $w }} {{ $h }}" style="width:100%;height:90px;">
                    @for ($g = 0; $g <= 100; $g += 25)
                        <line x1="0" x2="{{ $w }}" y1="{{ $h - ($g / 100) * ($h - 8) - 4 }}" y2="{{ $h - ($g / 100) * ($h - 8) - 4 }}" stroke="var(--color-hairline-soft)" stroke-width="1"/>
                    @endfor
                    {!! $line('n1', 'var(--color-primary)') !!}
                    {!! $line('n2', '#7c9cff') !!}
                    {!! $line('n3', '#f2a24d') !!}
                </svg>
            </div>
        @endif

        <p class="text-muted-soft" style="font-size:11px;">N1 유사도·N2 관련성·N3 랭킹 및 세부지표(D1~D10)는 관측 신호 기반 <b>자체 추정치</b>이며 네이버 공식 점수가 아닙니다. 일별 순위 색: <span style="color:var(--color-primary);">상승</span>/<span style="color:var(--color-error);">하락</span>. 리뷰 최근성(D9)·영향력(D10)은 내 매장과 상위 경쟁사만 수집합니다.</p>
    @endunless
</div>

{{-- 상세/추이 모달 --}}
<div id="cmp-modal" class="hidden" style="position:fixed;inset:0;z-index:50;">
    <div id="cmp-modal-bg" style="position:absolute;inset:0;background:color-mix(in srgb, var(--color-ink) 40%, transparent);"></div>
    <div class="card" style="position:relative;max-width:560px;margin:8vh auto 0;max-height:80vh;overflow-y:auto;box-shadow:var(--shadow-card);">
        <div class="flex items-center justify-between px-5 border-b border-hairline-soft" style="height:52px;">
            <span id="cmp-modal-title" class="text-ink font-semibold" style="font-size:15px;">상세</span>
            <button type="button" id="cmp-modal-close" class="btn btn-ghost btn-sm" title="닫기">✕</button>
        </div>
        <div id="cmp-modal-body" class="p-5" style="font-size:13px;"></div>
    </div>
</div>

<script>
(function () {
    // ---- 분석 실행: Swal 로딩 (순위추적과 동일) ----
    document.querySelectorAll('.rf-analyze-form').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            Swal.fire({ title: '경쟁 분석 중…', html: '<span style="font-size:13px;color:var(--color-muted);">상위 경쟁사 상세·리뷰를 수집해 점수를 산출합니다. 20~40초 걸릴 수 있습니다.</span>', allowOutsideClick: false, showConfirmButton: false, didOpen: function () { Swal.showLoading(); } });
            fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
                .then(d => { Swal.fire({ toast: true, position: 'top-end', icon: d.ok ? 'success' : 'warning', title: d.message, showConfirmButton: false, timer: 1800 }).then(() => { if (d.redirect) location.href = d.redirect; else location.reload(); }); })
                .catch(() => { Swal.fire({ icon: 'error', title: '분석에 실패했습니다', text: '잠시 후 다시 시도하세요.' }); });
        });
    });

    // ---- 상세/추이 모달 ----
    const modal = document.getElementById('cmp-modal');
    const title = document.getElementById('cmp-modal-title');
    const body = document.getElementById('cmp-modal-body');
    function open() { modal.classList.remove('hidden'); }
    function close() { modal.classList.add('hidden'); }
    document.getElementById('cmp-modal-close').addEventListener('click', close);
    document.getElementById('cmp-modal-bg').addEventListener('click', close);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });

    const explainUrl = @json(route('console.compete.explain', ['slot' => $slot->id, 'place' => '__PID__']));
    const historyUrl = @json(route('console.compete.history', ['slot' => $slot->id, 'place' => '__PID__']));
    const dimLabels = { d1: '방문자 리뷰', d2: '블로그 리뷰', d3: '예약 리뷰', d4: '평점', d5: '저장수', d7: '정보 충실성', d9: '최근 활동', d10: '리뷰 영향력' };
    const cmpLabels = { L: '지역 일치', B: '업종 일치', T: '대표키워드', M: '상호 일치' };
    function bar(v, color) { const w = v === null || v === undefined ? 0 : Math.max(0, Math.min(100, v)); return '<div style="height:5px;background:var(--color-surface-strong);border-radius:99px;overflow:hidden;flex:1;"><div style="height:100%;width:' + w + '%;background:' + (color || 'var(--color-primary)') + ';"></div></div>'; }
    function row(label, barHtml, val) { return '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;"><span style="width:78px;color:var(--color-muted);font-size:12px;">' + label + '</span>' + barHtml + '<span style="width:40px;text-align:right;color:var(--color-ink);font-size:12px;">' + val + '</span></div>'; }

    document.querySelectorAll('.rf-detail-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const pid = btn.dataset.place;
            title.textContent = '점수 근거';
            body.innerHTML = '<div style="color:var(--color-muted);">불러오는 중…</div>';
            open();
            fetch(explainUrl.replace('__PID__', pid), { headers: { 'Accept': 'application/json' } }).then(r => r.json()).then(function (d) {
                if (!d || !d.ok) { body.innerHTML = '<div style="color:var(--color-muted);">데이터가 없습니다.</div>'; return; }
                let h = '<div style="font-weight:700;color:var(--color-ink);margin-bottom:2px;">' + (d.is_mine ? '⭐ ' : '') + (d.name || '') + '</div>';
                h += '<div style="color:var(--color-muted-soft);font-size:12px;margin-bottom:12px;">순위 ' + (d.rnk && d.rnk < 300 ? d.rnk + '위' : '300+') + (d.tier == 1 ? ' · 리스트(상세 미수집)' : '') + '</div>';
                if (d.components) {
                    h += '<div style="font-weight:600;color:var(--color-ink);margin:10px 0 6px;font-size:13px;">N1 유사도 구성</div>';
                    ['L', 'B', 'T', 'M'].forEach(function (k) { const v = d.components[k]; h += row(cmpLabels[k], bar(v === null ? 0 : v * 100), v === null ? 'N/A' : Math.round(v * 100) + '%'); });
                }
                if (d.dims) {
                    h += '<div style="font-weight:600;color:var(--color-ink);margin:12px 0 6px;font-size:13px;">N2 관련성 차원</div>';
                    ['d1', 'd2', 'd3', 'd4', 'd5', 'd7', 'd9', 'd10'].forEach(function (k) { const v = d.dims[k]; h += row(dimLabels[k], bar(v), v === null ? '—' : Math.round(v)); });
                }
                if (d.seo && d.seo.length) {
                    h += '<div style="font-weight:600;color:var(--color-ink);margin:12px 0 6px;font-size:13px;">정보 충실성</div><div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 14px;">';
                    d.seo.forEach(function (it) { h += '<div style="display:flex;justify-content:space-between;font-size:12px;"><span style="color:var(--color-muted);">' + it.label + '</span><span style="color:' + (it.grade >= 0.99 ? 'var(--color-primary)' : (it.grade > 0 ? 'var(--color-ink)' : 'var(--color-muted-soft)')) + ';">' + it.raw + '</span></div>'; });
                    h += '</div>';
                }
                body.innerHTML = h;
            }).catch(function () { body.innerHTML = '<div style="color:var(--color-error);">불러오기 실패</div>'; });
        });
    });

    document.querySelectorAll('.rf-trend-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const pid = btn.dataset.place;
            title.textContent = '순위·점수 추이';
            body.innerHTML = '<div style="color:var(--color-muted);">불러오는 중…</div>';
            open();
            fetch(historyUrl.replace('__PID__', pid), { headers: { 'Accept': 'application/json' } }).then(r => r.json()).then(function (d) {
                if (!d || !d.ok || !d.history || !d.history.length) { body.innerHTML = '<div style="color:var(--color-muted);">추이 데이터가 없습니다. 여러 날 분석하면 쌓입니다.</div>'; return; }
                let h = '<div style="font-weight:700;color:var(--color-ink);margin-bottom:10px;">' + (d.name || '') + '</div>';
                h += '<table style="width:100%;font-size:12px;border-collapse:collapse;"><thead><tr style="color:var(--color-muted);text-align:right;"><th style="text-align:left;padding:3px 0;">날짜</th><th>순위</th><th>N1</th><th>N2</th><th>N3</th></tr></thead><tbody>';
                d.history.slice().reverse().forEach(function (r) {
                    h += '<tr style="border-top:1px solid var(--color-hairline-soft);text-align:right;color:var(--color-ink);"><td style="text-align:left;padding:4px 0;color:var(--color-muted);">' + r.ymd + '</td><td>' + (r.rnk && r.rnk < 300 ? r.rnk + '위' : '300+') + '</td><td>' + (r.n1 === null ? '—' : Math.round(r.n1)) + '</td><td>' + (r.n2 === null ? '—' : Math.round(r.n2)) + '</td><td>' + (r.n3 === null ? '—' : Math.round(r.n3)) + '</td></tr>';
                });
                h += '</tbody></table>';
                body.innerHTML = h;
            }).catch(function () { body.innerHTML = '<div style="color:var(--color-error);">불러오기 실패</div>'; });
        });
    });
})();
</script>
@endsection
