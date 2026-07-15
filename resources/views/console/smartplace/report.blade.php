@extends('console.layout')
@section('page-title', ($account->sp_name ?: $account->label ?: '스마트플레이스').' 리포트')
@section('crumb-title', $account->sp_name ?: $account->label ?: '리포트')

@section('page-actions')
    <a href="{{ route('console.smartplace') }}" class="btn btn-secondary btn-sm">← 계정 목록</a>
@endsection

@section('console-content')
<style>
    /* 스마트플레이스 리포트 — crm report.php 이식, 색·radius 는 디자인 토큰만 사용.
       폰트 최저 하한: 본문 13px, SVG 축 라벨 11px. 이 미만은 사용하지 않는다. */
    #sp-report { font-size:var(--fs-xs); }
    #sp-report .sp-panel { background: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-lg); padding: 16px 18px; margin-bottom: 14px; }
    #sp-report .sp-panel h3 { margin: 0 0 12px; font-size:var(--fs-xs); font-weight: 700; color: var(--color-ink); }
    #sp-report .sp-mrow { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
    #sp-report .sp-mtc { background: var(--color-surface-soft); border: 1px solid var(--color-hairline-soft); border-radius: var(--radius-md); padding: 12px; }
    #sp-report .sp-mtl { display: block; color: var(--color-muted); font-size:var(--fs-xs); margin-bottom: 4px; }
    #sp-report .sp-mtv { font-size:var(--fs-lg); font-weight: 800; color: var(--color-ink); font-family: var(--font-display); }
    #sp-report .sp-mtv em { font-size:var(--fs-xs); font-weight: 600; color: var(--color-muted); margin-left: 2px; font-style: normal; }
    #sp-report .sp-cards { display: grid; grid-template-columns: 1fr; gap: 14px; }
    @media (min-width: 768px) { #sp-report .sp-cards { grid-template-columns: repeat(2, 1fr); } }
    #sp-report .sp-card { background: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-lg); padding: 16px 18px; }
    #sp-report .sp-card h4, #sp-report .sp-sec { margin: 0 0 10px; font-size:var(--fs-xs); font-weight: 700; color: var(--color-ink); }
    #sp-report .sp-card h4 b { color: var(--color-accent); }
    #sp-report .sp-card h4 small, #sp-report .sp-sec small { color: var(--color-muted-soft); font-weight: 500; margin-left: 4px; }
    #sp-report .sp-sec { margin: 20px 0 10px; }
    #sp-report .sp-chart { width: 100%; height: auto; max-height: 200px; display: block; }
    #sp-report .sp-axl { font-size:var(--fs-xs); fill: var(--color-muted-soft); }
    /* 그래프 호버 — 열 전체 히트 영역, 올리면 막대 강조·포인트 확대 + <title> 툴팁 */
    #sp-report svg g.sp-hit { cursor: pointer; }
    #sp-report svg g.sp-hit:hover rect.sp-bar { opacity: 0.72; }
    #sp-report svg g.sp-hit:hover circle { r: 4.4; }
    #sp-report .sp-hbars { display: flex; flex-direction: column; gap: 8px; }
    #sp-report .sp-hb { display: grid; grid-template-columns: 1fr 110px auto; align-items: center; gap: 8px; }
    #sp-report .sp-hbl { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--color-body); }
    #sp-report .sp-hbtrack { background: var(--color-surface-strong); border-radius: 99px; height: 8px; overflow: hidden; }
    #sp-report .sp-hbfill { display: block; height: 100%; border-radius: 99px; }
    #sp-report .sp-hbv { color: var(--color-ink); font-weight: 700; white-space: nowrap; }
    #sp-report .sp-hbv em { color: var(--color-muted-soft); font-weight: 500; font-style: normal; }
    #sp-report .sp-genderline { display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 8px; }
    #sp-report .sp-pyr { display: flex; flex-direction: column; gap: 3px; }
    #sp-report .sp-pyrow { display: grid; grid-template-columns: 34px 1fr 52px 1fr 34px; align-items: center; gap: 4px; font-size:var(--fs-xs); }
    #sp-report .sp-pv { text-align: center; font-weight: 700; }
    #sp-report .sp-pbk { text-align: center; color: var(--color-muted); }
    #sp-report .sp-pbar { background: var(--color-surface-strong); border-radius: 3px; height: 9px; overflow: hidden; display: flex; justify-content: flex-end; }
    #sp-report .sp-pbar.r { justify-content: flex-start; }
    #sp-report .sp-pfill { height: 100%; }
    #sp-report .sp-tw { overflow: auto; max-height: 420px; }
    #sp-report table { width: 100%; border-collapse: collapse; font-size:var(--fs-xs); }
    #sp-report th, #sp-report td { border-bottom: 1px solid var(--color-hairline-soft); padding: 6px 8px; text-align: left; white-space: nowrap; }
    #sp-report th { color: var(--color-muted); background: var(--color-surface-soft); position: sticky; top: 0; font-weight: 600; }
    #sp-report .sp-rvgrid { display: grid; grid-template-columns: 1fr; gap: 8px; margin-bottom: 14px; }
    @media (min-width: 768px) { #sp-report .sp-rvgrid { grid-template-columns: repeat(2, 1fr); } }
    #sp-report .sp-review { background: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-md); padding: 10px 12px; color: var(--color-body); }
    #sp-report .sp-review small { color: var(--color-muted-soft); margin-left: 4px; }
    #sp-report .sp-review a { color: var(--color-accent); text-decoration: none; }
    #sp-report .sp-star { color: var(--color-warning); }
    #sp-report .sp-empty { color: var(--color-muted-soft); font-size:var(--fs-xs); padding: 14px; text-align: center; background: var(--color-surface-soft); border-radius: var(--radius-md); }
    #sp-report .sp-needdata { color: var(--color-warning); background: color-mix(in srgb, var(--color-warning) 8%, var(--color-canvas)); border: 1px dashed color-mix(in srgb, var(--color-warning) 40%, var(--color-canvas)); padding: 14px; text-align: center; border-radius: var(--radius-md); }
    #sp-report .sp-note { color: var(--color-muted-soft); font-size:var(--fs-xs); margin: 8px 2px; }
    #sp-report .sp-badges { display: flex; gap: 8px; flex-wrap: wrap; }
    #sp-report .sp-metric { background: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-md); padding: 10px 16px; text-align: center; font-size:var(--fs-xs); color: var(--color-muted); }
    #sp-report .sp-metric b { display: block; font-size:var(--fs-lg); color: var(--color-ink); font-family: var(--font-display); }
    /* 탭 */
    #sp-report .sp-tab { padding: 12px 16px; border: 0; background: none; font-size:var(--fs-xs); font-weight: 700; color: var(--color-muted); cursor: pointer; border-bottom: 2px solid transparent; }
    #sp-report .sp-tab.on { color: var(--color-ink); border-bottom-color: var(--color-primary); }
    #sp-report .sp-pane { display: none; }
    #sp-report .sp-pane.on { display: block; }
</style>

{{-- 메뉴명 + 설명 — 다른 콘솔 상세 페이지와 동일한 page-head 패턴 --}}
<x-console.page-head :title="($account->sp_name ?: $account->label ?: '스마트플레이스').' 리포트'">
    <x-slot:desc>스마트플레이스 <b>통계·방문자/블로그 리뷰·스마트콜·예약</b> 수집 결과 리포트</x-slot:desc>
</x-console.page-head>

<div id="sp-report" class="w-full">
@if (! $result)
    <div class="card text-center" style="padding:56px 20px;color:var(--color-muted);">
        <div style="font-size:var(--fs-2xl);opacity:.4;">🏪</div>
        <p class="mt-2" style="font-size:var(--fs-xs);">아직 수집된 리포트가 없습니다. <a href="{{ route('console.smartplace') }}" class="text-accent">계정 목록</a>에서 <b class="text-ink">[수집]</b>을 먼저 실행하세요.</p>
    </div>
@else
    @php $period = $result['period'] ?? ['', '']; @endphp

    {{-- 업체 헤더 --}}
    <div class="card mb-3 px-5 py-3 flex items-center gap-3 flex-wrap">
        <span class="font-display text-ink" style="font-size:var(--fs-md);">🟢 {{ $result['name'] ?: ($account->label ?: '업체명 미확인') }}</span>
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">placeSeq {{ $result['placeSeq'] ?? $account->place_seq }}</span>
        <div class="flex-1"></div>
        <span class="text-muted" style="font-size:var(--fs-xs);">
            로그인 <b style="color:{{ ! empty($result['loggedIn']) ? 'var(--color-success)' : 'var(--color-error)' }};">{{ ! empty($result['loggedIn']) ? 'OK' : 'FAIL' }}</b>
            · Bearer <b style="color:{{ ! empty($result['bearerOk']) ? 'var(--color-success)' : 'var(--color-error)' }};">{{ ! empty($result['bearerOk']) ? 'OK' : 'X' }}</b>
            · placeId {{ $result['ids']['placeId'] ?? '-' }}
            · businessId {{ ($result['ids']['businessId'] ?? '') !== '' ? $result['ids']['businessId'] : '-' }}
            · siteId {{ $result['ids']['siteId'] ?? '-' }}
        </span>
    </div>

    {{-- 기간 · 재수집 --}}
    <div class="card mb-4 px-5 py-3 flex items-center gap-2 flex-wrap" style="font-size:var(--fs-xs);">
        <span class="text-ink font-semibold">기간</span>
        <button type="button" class="btn btn-secondary btn-sm" data-period="day">오늘</button>
        <button type="button" class="btn btn-secondary btn-sm" data-period="week">최근 7일</button>
        <select id="sp-msel" class="input" style="width:auto;height:32px;font-size:var(--fs-xs);padding:0 8px;"></select>
        <input type="date" id="sp-sd" class="input" style="width:auto;height:32px;font-size:var(--fs-xs);padding:0 8px;" value="{{ $period[0] ?? '' }}">
        <span class="text-muted-soft">~</span>
        <input type="date" id="sp-ed" class="input" style="width:auto;height:32px;font-size:var(--fs-xs);padding:0 8px;" value="{{ $period[1] ?? '' }}">
        <button type="button" id="sp-apply" class="btn btn-primary btn-sm">적용 (재수집)</button>
        <div class="flex-1"></div>
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">
            현재 {{ $period[0] ?? '' }} ~ {{ $period[1] ?? '' }} · 수집 {{ $account->last_collected_at?->format('Y-m-d H:i') ?? '-' }}
        </span>
    </div>

    {{-- 탭 --}}
    <div class="card mb-4 overflow-hidden">
        <div class="flex px-3 border-b border-hairline-soft" style="gap:4px;overflow-x:auto;">
            @foreach ($tabs as $t)
                <button type="button" class="sp-tab {{ $loop->first ? 'on' : '' }}" data-t="{{ $t['key'] }}">{{ $t['label'] }}</button>
            @endforeach
        </div>
        <div class="p-4 sm:p-5" style="background:var(--color-surface-soft);">
            @foreach ($tabs as $t)
                <section class="sp-pane {{ $loop->first ? 'on' : '' }}" id="sp-pane-{{ $t['key'] }}">{!! $t['html'] !!}</section>
            @endforeach
        </div>
    </div>

    <p class="text-muted-soft" style="font-size:var(--fs-xs);">
        수집 시각 {{ $result['collectedAt'] ?? '-' }} · 데이터는 네이버 스마트플레이스/bizadvisor 응답 그대로이며, 네이버 화면과 집계 기준이 다를 수 있습니다.
    </p>
@endif
</div>

<script>
(function () {
    // ---- 차트 커스텀 툴팁 — 키워드 분석 추이 차트와 동일한 즉시 반응 스타일 ----
    var spTip = document.createElement('div');
    spTip.style.cssText = 'position:fixed;display:none;pointer-events:none;background:var(--color-surface-dark);color:#fff;border-radius:8px;padding:8px 11px;font-size:var(--fs-xs);white-space:nowrap;z-index:60;box-shadow:var(--shadow-card);';
    document.body.appendChild(spTip);
    document.addEventListener('mousemove', function (e) {
        var g = e.target.closest ? e.target.closest('g.sp-hit') : null;
        if (!g || !g.dataset.l) {
            if (spTip.style.display !== 'none') spTip.style.display = 'none';
            return;
        }
        // XSS 방지 — textContent 로만 구성
        spTip.textContent = '';
        var l = document.createElement('div');
        l.style.cssText = 'font-weight:700;margin-bottom:3px;';
        l.textContent = g.dataset.l;
        var row = document.createElement('div');
        row.style.cssText = 'display:flex;align-items:center;gap:6px;';
        var dot = document.createElement('i');
        dot.style.cssText = 'width:8px;height:8px;border-radius:50%;display:inline-block;background:' + (g.dataset.c || 'var(--color-accent)') + ';';
        var v = document.createElement('span');
        v.textContent = g.dataset.v;
        row.appendChild(dot); row.appendChild(v);
        spTip.appendChild(l); spTip.appendChild(row);
        spTip.style.display = 'block';
        var x = e.clientX + 14, y = e.clientY - 12;
        if (x + spTip.offsetWidth + 8 > window.innerWidth) x = e.clientX - spTip.offsetWidth - 14;
        if (y + spTip.offsetHeight + 8 > window.innerHeight) y = e.clientY - spTip.offsetHeight - 8;
        spTip.style.left = x + 'px';
        spTip.style.top = Math.max(4, y) + 'px';
    });

    // ---- 탭 전환 -----------------------------------------------------------
    document.querySelectorAll('.sp-tab').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.sp-tab').forEach(function (x) { x.classList.remove('on'); });
            document.querySelectorAll('.sp-pane').forEach(function (x) { x.classList.remove('on'); });
            b.classList.add('on');
            document.getElementById('sp-pane-' + b.dataset.t).classList.add('on');
        });
    });

    // ---- 기간 컨트롤 + 재수집 ------------------------------------------------
    const sd = document.getElementById('sp-sd');
    const ed = document.getElementById('sp-ed');
    const msel = document.getElementById('sp-msel');
    if (!sd) return; // 수집 전 빈 화면
    (function () {
        const d = new Date();
        let h = '<option value="">월 선택</option>';
        for (let i = 0; i < 18; i++) {
            const y = d.getFullYear(), m = d.getMonth() + 1;
            h += '<option value="' + y + '-' + String(m).padStart(2, '0') + '">' + y + '년 ' + m + '월</option>';
            d.setMonth(d.getMonth() - 1);
        }
        msel.innerHTML = h;
    })();
    function fmt(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    document.querySelectorAll('[data-period]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const e = new Date();
            const s = btn.dataset.period === 'week' ? new Date(Date.now() - 6 * 864e5) : e;
            sd.value = fmt(s); ed.value = fmt(e);
            collect();
        });
    });
    msel.addEventListener('change', function () {
        if (!msel.value) return;
        const [y, m] = msel.value.split('-').map(Number);
        sd.value = msel.value + '-01';
        ed.value = msel.value + '-' + String(new Date(y, m, 0).getDate()).padStart(2, '0');
        collect();
    });
    document.getElementById('sp-apply').addEventListener('click', collect);

    function collect() {
        if (!sd.value || !ed.value) { Swal.fire({ icon: 'info', title: '기간을 선택하세요' }); return; }
        Swal.fire({
            title: '재수집 중…',
            html: '<span style="font-size:var(--fs-xs);color:var(--color-muted);">' + sd.value + ' ~ ' + ed.value + ' 기간으로 다시 수집합니다. 수십 초 걸릴 수 있습니다.</span>',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: function () { Swal.showLoading(); }
        });
        const fd = new FormData();
        fd.append('_token', @json(csrf_token()));
        fd.append('start', sd.value);
        fd.append('end', ed.value);
        fetch(@json(route('console.smartplace.collect', $account)), { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                if (res.ok && res.d.ok) {
                    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.d.message, showConfirmButton: false, timer: 1500 })
                        .then(function () { location.reload(); });
                } else {
                    Swal.fire({ icon: 'warning', title: '수집 실패', text: (res.d && res.d.message) || '잠시 후 다시 시도하세요.' });
                }
            })
            .catch(function () { Swal.fire({ icon: 'error', title: '수집에 실패했습니다', text: '잠시 후 다시 시도하세요.' }); });
    }
})();
</script>
@endsection
