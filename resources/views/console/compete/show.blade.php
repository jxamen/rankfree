@extends('console.layout')
@section('page-title', '경쟁 분석 · '.$slot->keyword)

@section('console-content')
@php
    $bar = function ($v, $color = 'var(--color-primary)') {
        $w = $v === null ? 0 : max(0, min(100, (float) $v));
        return '<div style="height:6px;background:var(--color-surface-strong);border-radius:99px;overflow:hidden;"><div style="height:100%;width:'.$w.'%;background:'.$color.';"></div></div>';
    };
    $fmt = fn ($v) => $v === null ? '—' : round($v);
    $nd = $dates->count();
@endphp

<div>
    <a href="{{ route('console.compete') }}" class="text-muted hover:text-ink" style="font-size:13px;">← 경쟁 분석 목록</a>

    <div class="flex items-end justify-between flex-wrap gap-3 mt-2 mb-5">
        <div>
            <div class="font-display text-ink" style="font-size:22px;">{{ $slot->keyword }}</div>
            <div class="text-muted-soft" style="font-size:13px;">{{ $slot->place_name ?: ('ID '.$slot->place_id) }} @if ($ymd)· 분석일 {{ \Illuminate\Support\Carbon::parse($ymd)->format('Y.m.d') }}@endif</div>
        </div>
        <div class="flex items-center gap-2">
            @if ($ymd && $slot->share_token)
                <button type="button" class="rf-share-btn btn btn-secondary btn-sm" data-url="{{ route('compete.shared', $slot->share_token) }}" title="공유 링크 복사 (로그인 없이 열람)">공유</button>
            @endif
            <form method="POST" action="{{ route('console.compete.analyze', $slot) }}" class="rf-analyze-form" data-keyword="{{ $slot->keyword }}">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm">{{ $ymd ? '분석 갱신' : '분석 시작' }}</button>
            </form>
        </div>
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
                    <div class="text-muted" style="font-size:13px;">{{ $label }}</div>
                    <div class="font-display text-ink mt-1" style="font-size:26px;">{{ $val }}</div>
                    @if ($sc !== null)<div class="mt-2">{!! $bar($sc) !!}</div>@endif
                </div>
            @endforeach
        </div>

        {{-- 경쟁 비교표 (일별 순위 + 주별 신규 리뷰) --}}
        <div class="card overflow-x-auto mb-4">
            <table class="w-full" style="min-width:1180px;border-collapse:collapse;white-space:nowrap;">
                <thead>
                    <tr class="text-muted" style="font-size:13px;border-bottom:1px solid var(--color-hairline-soft);">
                        <th rowspan="2" class="text-right px-2 py-2.5 font-semibold" style="width:44px;">순위</th>
                        <th rowspan="2" class="text-left px-3 py-2.5 font-semibold">매장</th>
                        <th colspan="{{ max(1, $nd) }}" class="text-center px-2 py-2 font-semibold" style="border-left:1px solid var(--color-hairline-soft);">일별 순위 <span class="text-muted-soft" style="font-weight:400;">(좌=최신)</span></th>
                        <th colspan="5" class="text-center px-2 py-2 font-semibold" style="border-left:1px solid var(--color-hairline-soft);">영수증 리뷰 <span class="text-muted-soft" style="font-weight:400;">주별 신규·총</span></th>
                        <th colspan="5" class="text-center px-2 py-2 font-semibold" style="border-left:1px solid var(--color-hairline-soft);">블로그 리뷰 <span class="text-muted-soft" style="font-weight:400;">주별 신규·총</span></th>
                        <th rowspan="2" class="text-right px-2 py-2.5 font-semibold" style="border-left:1px solid var(--color-hairline-soft);">평점</th>
                        <th rowspan="2" class="text-right px-2 py-2.5 font-semibold">정보충실</th>
                        <th rowspan="2" class="text-right px-2 py-2.5 font-semibold">N1</th>
                        <th rowspan="2" class="text-right px-2 py-2.5 font-semibold">N2</th>
                        <th rowspan="2" class="text-right px-3 py-2.5 font-semibold">N3</th>
                    </tr>
                    <tr class="text-muted-soft" style="font-size:12px;border-bottom:1px solid var(--color-hairline-soft);">
                        @foreach ($dates as $d)
                            <th class="text-center px-1.5 py-1.5 font-medium @if($loop->first) text-ink @endif" style="@if($loop->first)border-left:1px solid var(--color-hairline-soft);@endif">{{ \Illuminate\Support\Carbon::parse($d)->format('n/j') }}</th>
                        @endforeach
                        <th class="text-center px-1.5 py-1.5" style="border-left:1px solid var(--color-hairline-soft);">1주</th><th class="text-center px-1.5">2주</th><th class="text-center px-1.5">3주</th><th class="text-center px-1.5">4주</th><th class="text-center px-1.5 text-muted">총</th>
                        <th class="text-center px-1.5 py-1.5" style="border-left:1px solid var(--color-hairline-soft);">1주</th><th class="text-center px-1.5">2주</th><th class="text-center px-1.5">3주</th><th class="text-center px-1.5">4주</th><th class="text-center px-1.5 text-muted">총</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr style="border-top:1px solid var(--color-hairline-soft);{{ $r->is_mine ? 'background:color-mix(in srgb,var(--color-primary) 5%,var(--color-canvas));' : '' }}">
                            <td class="px-2 py-2.5 text-right text-muted" style="font-size:13px;">{{ $r->rnk < 300 ? $r->rnk : '—' }}</td>
                            <td class="px-3 py-2.5">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-ink" style="font-size:14px;font-weight:{{ $r->is_mine ? 700 : 500 }};">{{ $r->is_mine ? '⭐ ' : '' }}{{ $r->name }}</span>
                                    @if ($r->tier == 1)<span class="text-muted-soft" style="font-size:11px;">리스트</span>@endif
                                    <button type="button" class="rf-detail-btn" data-place="{{ $r->place_id }}" style="font-size:12px;color:var(--color-ink);border:1px solid var(--color-hairline);border-radius:6px;padding:3px 10px;background:var(--color-canvas);cursor:pointer;">상세</button>
                                    <button type="button" class="rf-trend-btn" data-place="{{ $r->place_id }}" style="font-size:12px;color:var(--color-ink);border:1px solid var(--color-hairline);border-radius:6px;padding:3px 10px;background:var(--color-canvas);cursor:pointer;">추이</button>
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
                                <td class="text-center px-1.5 py-2.5" style="font-size:13px;color:{{ $col }};@if($i===0)border-left:1px solid var(--color-hairline-soft);@endif">{{ ($rk !== null && $rk > 0 && $rk < 300) ? $rk : '·' }}</td>
                            @endforeach
                            @php $wv = $r->wv; @endphp
                            @for ($k = 0; $k < 4; $k++)
                                <td class="text-center px-1.5 py-2.5" style="font-size:13px;@if($k===0)border-left:1px solid var(--color-hairline-soft);@endif">@if ($wv && $wv[$k] > 0)<span class="text-ink">{{ $wv[$k] }}</span>@else<span class="text-muted-soft">·</span>@endif</td>
                            @endfor
                            <td class="text-center px-1.5 py-2.5 text-muted" style="font-size:13px;">{{ $r->visitor !== null ? number_format($r->visitor) : '—' }}</td>
                            @php $wb = $r->wb; @endphp
                            @for ($k = 0; $k < 4; $k++)
                                <td class="text-center px-1.5 py-2.5" style="font-size:13px;@if($k===0)border-left:1px solid var(--color-hairline-soft);@endif">@if ($wb && $wb[$k] > 0)<span class="text-ink">{{ $wb[$k] }}</span>@else<span class="text-muted-soft">·</span>@endif</td>
                            @endfor
                            <td class="text-center px-1.5 py-2.5 text-muted" style="font-size:13px;">{{ $r->blog !== null ? number_format($r->blog) : '—' }}</td>
                            <td class="px-2 py-2.5 text-right text-muted" style="font-size:13px;border-left:1px solid var(--color-hairline-soft);">{{ $r->score !== null ? number_format($r->score, 2) : '—' }}</td>
                            <td class="px-2 py-2.5 text-right text-ink" style="font-size:13px;">{{ $fmt($r->d7) }}</td>
                            <td class="px-2 py-2.5 text-right text-ink" style="font-size:13px;">{{ $fmt($r->n1) }}</td>
                            <td class="px-2 py-2.5 text-right text-ink" style="font-size:13px;">{{ $fmt($r->n2) }}</td>
                            <td class="px-3 py-2.5 text-right text-ink" style="font-size:14px;font-weight:600;">{{ $fmt($r->n3) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-muted-soft" style="font-size:12px;">각 행의 <b>[상세]</b>는 점수 근거(N1·N2·정보충실성·리뷰), <b>[추이]</b>는 일자별 순위·점수 이력입니다. 일별 순위 색: <span style="color:var(--color-primary);">상승</span>/<span style="color:var(--color-error);">하락</span>. N1·N2·N3 및 D1~D10은 관측 신호 기반 <b>자체 추정치</b>이며 네이버 공식 점수가 아닙니다.</p>
    @endunless
</div>

{{-- 상세/추이 모달 (crm 형식 HTML 주입) --}}
<div id="cmp-modal" class="hidden" style="position:fixed;inset:0;z-index:50;">
    <div id="cmp-modal-bg" style="position:absolute;inset:0;background:color-mix(in srgb, var(--color-ink) 45%, transparent);"></div>
    <div id="cmp-modal-card" class="card" style="position:relative;max-width:1200px;width:calc(100% - 32px);margin:4vh auto 0;max-height:92vh;display:flex;flex-direction:column;box-shadow:var(--shadow-card);">
        <div class="flex items-center justify-between px-5 border-b border-hairline-soft" style="height:52px;flex:none;">
            <span id="cmp-modal-title" class="text-ink font-semibold" style="font-size:15px;">상세</span>
            <button type="button" id="cmp-modal-close" class="btn btn-ghost btn-sm" title="닫기">✕</button>
        </div>
        <div id="cmp-modal-body" class="p-5" style="overflow-y:auto;"></div>
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

    // ---- 공유 링크 복사 ----
    document.querySelectorAll('.rf-share-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const url = new URL(btn.dataset.url, location.origin).href;
            function done() { Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '공유 링크가 복사되었습니다', showConfirmButton: false, timer: 1600 }); }
            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done).catch(fallback); } else { fallback(); }
            function fallback() { const ta = document.createElement('textarea'); ta.value = url; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) { Swal.fire({ icon: 'info', title: '링크를 복사하세요', text: url }); } ta.remove(); }
        });
    });

    // ---- 상세/추이 모달 (crm HTML 주입) ----
    const modal = document.getElementById('cmp-modal');
    const title = document.getElementById('cmp-modal-title');
    const body = document.getElementById('cmp-modal-body');
    function openM() { modal.classList.remove('hidden'); body.scrollTop = 0; }
    function closeM() { modal.classList.add('hidden'); }
    document.getElementById('cmp-modal-close').addEventListener('click', closeM);
    document.getElementById('cmp-modal-bg').addEventListener('click', closeM);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeM(); });

    const explainUrl = @json(route('console.compete.explain', ['slot' => $slot->id, 'place' => '__PID__']));
    const historyUrl = @json(route('console.compete.history', ['slot' => $slot->id, 'place' => '__PID__']));

    const card = document.getElementById('cmp-modal-card');
    function load(url, name, width) {
        title.textContent = name;
        card.style.maxWidth = width;
        body.innerHTML = '<div style="color:var(--color-muted);padding:20px 0;">불러오는 중…</div>';
        openM();
        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.text().then(t => ({ ok: r.ok, t })))
            .then(({ ok, t }) => {
                if (!ok) { body.innerHTML = '<div style="color:var(--color-error);padding:20px 0;">불러오기 실패 (' + t.slice(0, 200) + ')</div>'; return; }
                let d; try { d = JSON.parse(t); } catch (e) { body.innerHTML = '<div style="color:var(--color-error);padding:20px 0;">응답 형식 오류</div>'; return; }
                body.innerHTML = (d && d.ok && d.html) ? d.html : '<div style="color:var(--color-muted);padding:20px 0;">데이터가 없습니다. 여러 날 분석하면 쌓입니다.</div>';
            })
            .catch(() => { body.innerHTML = '<div style="color:var(--color-error);padding:20px 0;">불러오기 실패</div>'; });
    }
    document.querySelectorAll('.rf-detail-btn').forEach(b => b.addEventListener('click', () => load(explainUrl.replace('__PID__', b.dataset.place), '점수 근거 상세', '1200px')));
    document.querySelectorAll('.rf-trend-btn').forEach(b => b.addEventListener('click', () => load(historyUrl.replace('__PID__', b.dataset.place), '순위·점수 추이', '1880px')));
})();
</script>
@endsection
