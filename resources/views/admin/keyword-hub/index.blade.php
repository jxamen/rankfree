@extends('admin.layout')
@section('page-title', '키워드 자동 분석')

@php
    $stLabel = ['pending' => '대기', 'approved' => '승인', 'rejected' => '거부', 'published' => '발행됨'];
@endphp

@section('admin-content')
<x-console.page-head title="키워드 자동 분석" desc="플레이스 후보는 키워드 분석, 쇼핑 후보는 쇼핑 시장 분석 문서로 병렬 발행합니다." />

<style>
/* 실행 중 중단 버튼 강조 — error 는 텍스트·보더로만(배경 금지 규칙) */
.kh-stop-live{border-color:var(--color-error) !important;color:var(--color-error) !important;font-weight:600;}
</style>

<div id="kh-auto-bar" class="flex items-center gap-3 px-4 py-2 mb-4"
     style="position:sticky;top:0;z-index:30;border-radius:16px;border:1px solid var(--color-hairline);background:color-mix(in srgb,var(--color-primary) 6%,var(--color-canvas));{{ ($auto['running'] ?? false) ? '' : 'display:none;' }}">
    <span class="badge border border-hairline flex-none" style="font-size:var(--fs-xs);">자동 분석 중</span>
    <span id="kh-auto-bar-msg" class="text-muted" style="font-size:var(--fs-xs);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
    <button type="button" id="kh-auto-bar-stop" class="btn btn-secondary btn-sm flex-none">중단</button>
</div>

@if ($errors->any())
    <div class="card-soft px-4 py-3 mb-4" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
    <div class="card p-5">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">자동 분석·발행</div>
                <div class="flex flex-wrap gap-2" style="font-size:var(--fs-xs);">
                    <span class="badge border border-hairline">플레이스 → 키워드 분석</span>
                    <span class="badge border border-hairline">쇼핑 → 쇼핑 시장 분석</span>
                    <span class="badge border border-hairline">Supervisor 워커 병렬 처리</span>
                    <span class="badge border border-hairline">분당 큐 {{ number_format((int) config('rankfree.hub.auto_per_run', 60)) }}개</span>
                </div>
                <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">유형별로 따로 돌리거나 동시에 돌릴 수 있습니다. 실제 동시 발행 개수는 서버 Supervisor의 `numprocs` 값입니다.</div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <button type="button" class="kh-auto-start btn btn-secondary btn-sm" data-type="place">플레이스만 시작</button>
                <button type="button" class="kh-auto-start btn btn-secondary btn-sm" data-type="shopping">쇼핑만 시작</button>
                <button type="button" class="kh-auto-start btn btn-primary btn-sm" data-type="">동시 시작</button>
                <button type="button" id="kh-auto-stop" class="btn btn-secondary btn-sm" disabled>중단</button>
                <span id="kh-auto-status" class="text-muted" style="font-size:var(--fs-xs);"></span>
            </div>
        </div>
    </div>

    <div class="card p-5">
        <div>
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">발행 현황</div>
            <div class="grid grid-cols-2 gap-2">
                <div class="card-soft px-3 py-2">
                    <div class="text-muted-soft" style="font-size:var(--fs-xs);">플레이스 키워드 분석</div>
                    <div class="font-mono text-ink font-semibold" style="font-size:var(--fs-lg);">{{ number_format($publishedCounts['place'] ?? 0) }}개</div>
                </div>
                <div class="card-soft px-3 py-2">
                    <div class="text-muted-soft" style="font-size:var(--fs-xs);">쇼핑 시장 분석</div>
                    <div class="font-mono text-ink font-semibold" style="font-size:var(--fs-lg);">{{ number_format($publishedCounts['shopping'] ?? 0) }}개</div>
                </div>
            </div>
            <div class="mt-3">
                <a href="{{ route('admin.keyword-hub.candidates', ['type' => 'place']) }}" class="btn btn-secondary btn-sm">후보 관리 →</a>
            </div>
        </div>
    </div>
</div>

{{-- 쇼핑 시장 수집 상태 — 별도 시작 버튼 없음. 위 '쇼핑만/동시 시작'이 발행과 수집을 함께 구동한다
     (admin-bridge bulkStart — 확장이 대기 쇼핑 키워드의 시장분석을 자동 수집 = 쇼핑 발행의 재료). --}}
<div class="card p-5 mb-5">
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">쇼핑 시장 수집 <span class="text-muted-soft font-normal">(자동 — 위 시작 버튼에 포함)</span></div>
            <div class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">'쇼핑만 시작' 또는 '동시 시작'을 누르면 확장이 대기 키워드의 시장분석(판매량·매출)을 자동 수집하고, 수집되는 대로 발행이 따라갑니다. 브라우저를 켜둔 채 두세요(확장 v0.3.8+ 로그인 필요). 동시 수집 수량은 우측에서 조절합니다 — 차단 방지를 위해 2개로 시작해 목표까지 자동으로 올라갑니다.</div>
        </div>
        <div class="flex items-center gap-2 flex-none">
            <label for="kh-collect-conc" class="text-muted-soft" style="font-size:var(--fs-xs);">동시 수집</label>
            <input type="number" id="kh-collect-conc" class="input" min="1" max="10" step="1" value="4"
                   title="동시에 여는 수집 탭 수(1~10). 많을수록 빠르지만 네이버 차단 위험이 커집니다 — 낮게 시작해 자동으로 올라갑니다."
                   style="width:64px;padding:4px 10px;font-size:var(--fs-xs);text-align:center;">
            <button type="button" id="kh-collect-stop" class="btn btn-secondary btn-sm kh-stop-live" hidden>수집 중단</button>
        </div>
    </div>
    <div id="kh-collect-msg" class="text-muted mt-2" style="font-size:var(--fs-xs);">대기 — 위 '쇼핑만 시작' 또는 '동시 시작'을 누르면 수집이 자동 시작됩니다.</div>
</div>

<script>
(function () {
    const stop = document.getElementById('kh-collect-stop');
    const msg = document.getElementById('kh-collect-msg');
    const concInput = document.getElementById('kh-collect-conc');

    // 동시 수집 수량 — 마지막 값 기억(localStorage), 1~10 클램프(확장 상한과 동일)
    const savedConc = parseInt(localStorage.getItem('khCollectConc') || '', 10);
    if (savedConc >= 1 && savedConc <= 10) concInput.value = savedConc;
    function collectConc() {
        let v = parseInt(concInput.value, 10);
        if (isNaN(v)) v = 4;
        v = Math.min(10, Math.max(1, v));
        concInput.value = v;
        localStorage.setItem('khCollectConc', String(v));
        return v;
    }
    concInput.addEventListener('change', collectConc);
    const statusUrl = '{{ route('admin.keyword-hub.collect-market-status') }}';
    let poll = null;
    let statPoll = null;
    let lastStat = null;
    let autoPubAt = 0;   // 시장분석 생성 감지 시 쇼핑 발행 자동 시작(2분 간격 재시도 가드)

    const send = (type, extra) => window.postMessage(Object.assign({ source: 'rankfree-admin', type }, extra || {}), '*');
    const hasExt = () => document.documentElement.getAttribute('data-rf-ext') === '1';

    async function fetchStat() {
        try {
            const r = await fetch(statusUrl + '?t=' + Date.now(), { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (r.ok) lastStat = (await r.json()).data;
        } catch (e) { /* noop */ }
        // 시장분석이 생기기 시작했고 발행이 꺼져 있으면 쇼핑 발행을 자동 시작한다 — 버튼 하나 흐름의 뒷단
        if (lastStat && lastStat.market_10m > 0 && !window.__khPublishRunning
            && Date.now() - autoPubAt > 120000 && window.__khStartPublish) {
            autoPubAt = Date.now();
            window.__khStartPublish('shopping');
        }
    }
    function statLine() {
        if (!lastStat) return '';
        let s = ' · 최근 10분 수집 ' + lastStat.serp_10m + ' · 시장분석 생성 ' + lastStat.market_10m;
        if (lastStat.serp_10m > 0 && lastStat.market_10m === 0) {
            s += ' — ⚠️ 수집은 되는데 시장분석이 안 생깁니다: 확장이 구버전입니다. chrome://extensions 에서 v0.3.8 로 새로고침하세요';
        }
        return s;
    }
    function live() {
        stop.hidden = false;
        if (!poll) poll = setInterval(() => send('bulkStatus'), 1500);
        if (!statPoll) { fetchStat(); statPoll = setInterval(fetchStat, 20000); }
    }
    function idle() {
        if (poll) { clearInterval(poll); poll = null; }
        if (statPoll) { clearInterval(statPoll); statPoll = null; }
        stop.hidden = true;
    }

    window.addEventListener('message', (e) => {
        const m = e.data;
        if (!m || m.source !== 'rankfree-ext') return;
        if (m.type === 'bulkStartResult') {
            if (!m.ok) { msg.style.color = 'var(--color-error)'; msg.textContent = m.message || '수집 시작 실패 — 확장 설치·로그인을 확인하세요.'; return; }
            msg.style.color = '';
            live();
        }
        if (m.type === 'bulkStatusResult' && m.bulk) {
            const b = m.bulk;
            if (b.running) live();
            const waiting = b.blockedUntil && b.blockedUntil > Date.now();
            msg.style.color = '';
            msg.textContent = (!b.running ? '수집 종료 — ' : (waiting ? '차단 감지 — 대기 중… ' : '수집 중… '))
                + '성공 ' + (b.done || 0) + ' · 실패 ' + (b.failed || 0)
                + (b.running && b.target ? ' · 동시 ' + (b.conc || 1) + '/' + b.target : '')
                + (b.category ? ' · 분류: ' + b.category + (b.categoryTotal ? ' (' + b.categoryIndex + '/' + b.categoryTotal + ')' : '') : '')
                + (b.running && b.current ? ' · 현재: ' + b.current : '')
                + (b.remaining ? ' · 남은 ' + Number(b.remaining).toLocaleString() : '')
                + statLine();
            if (b.failed > 0 && b.lastError) { msg.textContent += ' · 사유: ' + b.lastError; if (!waiting) msg.style.color = 'var(--color-error)'; }
            if (!b.running) { idle(); msg.textContent += ' — 다시 시작하면 남은 분류부터 이어집니다'; }
        }
        if (m.type === 'bulkStopResult') { msg.textContent = '중단 요청됨 — 현재 키워드까지 마치고 멈춥니다.'; }
    });

    /** 수집 시작 — 위 '쇼핑만/동시 시작' 버튼이 호출한다(별도 수집 버튼 없음). 성공 여부 반환. */
    function startCollect() {
        if (!hasExt()) {
            msg.style.color = 'var(--color-error)';
            msg.textContent = '랭크프리 확장이 이 페이지에 연결되지 않았습니다 — chrome://extensions 에서 확장 새로고침(v0.3.8)·로그인 후 이 페이지를 새로고침하세요.';
            return false;
        }
        msg.style.color = '';
        msg.textContent = '수집 시작하는 중…';
        send('bulkStart', { limit: 0, delayMs: 6000, concurrency: collectConc() });
        return true;
    }
    window.__khStartCollect = startCollect;
    stop.addEventListener('click', () => send('bulkStop'));

    // 이미 수집이 돌고 있으면 이어서 표시(브릿지가 붙을 때까지 잠깐 재시도)
    (function ask(n) {
        if (hasExt()) { send('bulkStatus'); return; }
        if (n > 0) setTimeout(() => ask(n - 1), 300);
    })(10);
})();
</script>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
    <div class="card p-5">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">플레이스 후보 현황</div>
        <div class="grid grid-cols-2 gap-2">
            @foreach ($stLabel as $k => $label)
                @php
                    $href = $k === 'published'
                        ? route('admin.keyword-hub.published-all', ['type' => 'place'])
                        : route('admin.keyword-hub.candidates', ['status' => $k, 'type' => 'place']);
                    $count = $k === 'published'
                        ? ($publishedCounts['place'] ?? 0)
                        : ($candidateTypeCounts[$k]['place'] ?? 0);
                @endphp
                <a href="{{ $href }}" class="card-soft px-3 py-2" style="text-decoration:none;">
                    <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $label }}</div>
                    <div class="font-mono text-ink font-semibold" style="font-size:var(--fs-lg);">{{ number_format($count) }}</div>
                </a>
            @endforeach
        </div>
    </div>

    <div class="card p-5">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">쇼핑 후보 현황</div>
        <div class="grid grid-cols-2 gap-2 mb-4">
            @foreach ($stLabel as $k => $label)
                @php
                    $href = $k === 'published'
                        ? route('admin.keyword-hub.published-all', ['type' => 'shopping'])
                        : route('admin.keyword-hub.candidates', ['status' => $k, 'type' => 'shopping']);
                    $count = $k === 'published'
                        ? ($publishedCounts['shopping'] ?? 0)
                        : ($candidateTypeCounts[$k]['shopping'] ?? 0);
                @endphp
                <a href="{{ $href }}" class="card-soft px-3 py-2" style="text-decoration:none;">
                    <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $label }}</div>
                    <div class="font-mono text-ink font-semibold" style="font-size:var(--fs-lg);">{{ number_format($count) }}</div>
                </a>
            @endforeach
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
    <div class="card p-5">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">플레이스 카테고리별 발행</div>
        <div class="grid grid-cols-2 gap-2">
            @forelse (($categoryBreakdown['place'] ?? collect()) as $row)
                <a href="{{ route('admin.keyword-hub.published', ['type' => 'place', 'category' => $row['id']]) }}" class="card-soft px-3 py-2" style="display:block;text-decoration:none;">
                    <div class="text-muted-soft truncate" style="font-size:var(--fs-xs);">{{ $row['name'] }}</div>
                    <div class="font-mono text-ink font-semibold" style="font-size:var(--fs-lg);">{{ number_format($row['count']) }}</div>
                </a>
            @empty
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">플레이스 카테고리가 없습니다.</div>
            @endforelse
        </div>
    </div>

    <div class="card p-5">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">쇼핑 카테고리별 발행</div>
        <div class="grid grid-cols-3 gap-2">
            @forelse (($categoryBreakdown['shopping'] ?? collect()) as $row)
                <a href="{{ route('admin.keyword-hub.published', ['type' => 'shopping', 'category' => $row['id']]) }}" class="card-soft px-3 py-2" style="display:block;text-decoration:none;">
                    <div class="text-muted-soft truncate" style="font-size:var(--fs-xs);">{{ $row['name'] }}</div>
                    <div class="font-mono text-ink font-semibold" style="font-size:var(--fs-lg);">{{ number_format($row['count']) }}</div>
                </a>
            @empty
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">쇼핑 1단계 카테고리가 없습니다.</div>
            @endforelse
        </div>
    </div>
</div>

<div class="card p-5">
    <div class="flex items-center justify-between gap-2 mb-3">
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">최근 발행 문서</div>
        <div class="flex items-center gap-1" role="tablist" aria-label="최근 발행 문서 유형">
            <button type="button" class="kh-doc-tab badge border border-hairline" data-doc-tab="place" style="font-size:var(--fs-xs);cursor:pointer;">플레이스</button>
            <button type="button" class="kh-doc-tab badge border border-hairline" data-doc-tab="shopping" style="font-size:var(--fs-xs);cursor:pointer;">쇼핑</button>
        </div>
    </div>

    @foreach (['place' => '플레이스 키워드 분석', 'shopping' => '쇼핑 시장 분석'] as $type => $label)
        <div class="kh-doc-panel" data-doc-panel="{{ $type }}" style="{{ $type === 'place' ? '' : 'display:none;' }}">
            @forelse (($hubDocsByType[$type] ?? collect()) as $d)
                <div class="flex items-center gap-2 py-1.5" style="border-bottom:1px solid var(--color-hairline-soft);font-size:var(--fs-xs);">
                    <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $label }}</span>
                    <a href="{{ $d->shareUrl() }}" target="_blank" class="text-ink font-semibold" style="text-decoration:none;">{{ $d->keyword }}</a>
                    @if ($type === 'shopping')
                        <span class="font-mono text-muted">시장 {{ number_format((int) $d->revenue_6m) }}원</span>
                    @else
                        <span class="font-mono text-muted">월{{ number_format((int) $d->monthly_total) }}회</span>
                    @endif
                    <span class="text-muted-soft ml-auto">{{ ($d->refreshed_at ?? null)?->format('m-d H:i') ?? $d->created_at->format('m-d H:i') }}</span>
                </div>
            @empty
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">최근 발행된 {{ $label }} 문서가 없습니다.</div>
            @endforelse
        </div>
    @endforeach
</div>

<script>
(function () {
    const startBtns = Array.from(document.querySelectorAll('.kh-auto-start'));
    const stopBtn = document.getElementById('kh-auto-stop');
    const statusEl = document.getElementById('kh-auto-status');
    const bar = document.getElementById('kh-auto-bar');
    const barMsg = document.getElementById('kh-auto-bar-msg');
    const barStop = document.getElementById('kh-auto-bar-stop');
    const csrf = '{{ csrf_token() }}';
    const statusUrl = '{{ route('admin.keyword-hub.auto-status') }}';
    const toggleUrl = '{{ route('admin.keyword-hub.auto') }}';
    const typeLabel = { shopping: '쇼핑', place: '플레이스' };
    let poll = null;

    const typeLine = (d, type) => {
        const row = ((d.by_type || {})[type] || {});
        return (typeLabel[type] || type)
            + ' 발행 ' + (row.done || 0)
            + ' · 보류 ' + (row.held || 0)
            + ' · 남은 ' + Number(row.remaining || 0).toLocaleString();
    };
    const fmt = (d) => {
        const head = d.type ? '유형 ' + (typeLabel[d.type] || d.type) : '쇼핑+플레이스';
        const lines = d.type
            ? [typeLine(d, d.type)]
            : [typeLine(d, 'place'), typeLine(d, 'shopping')];
        lines.push('합계 발행 ' + (d.done || 0) + ' · 보류 ' + (d.held || 0) + ' · 남은 ' + Number(d.remaining || 0).toLocaleString());

        return head + ' · ' + lines.join(' / ')
            + (d.updated_ago != null ? ' · ' + d.updated_ago + '초 전 갱신' : '');
    };

    // 중단 요청 진행 표시 — 응답 올 때까지 버튼을 '중단 중…'으로 고정해 반복 클릭을 막는다
    let stopping = false;
    function applyStoppingUi() {
        [stopBtn, barStop].forEach((b) => { b.style.display = ''; b.disabled = true; b.textContent = '중단 중…'; b.classList.remove('kh-stop-live'); });
        barMsg.textContent = '중단 처리 중 — 진행 중이던 작업까지만 하고 멈춥니다';
    }

    function render(d) {
        d = d || {};
        const running = !!d.running;
        window.__khPublishRunning = running;   // 수집 카드가 발행 자동 시작 여부를 판단할 때 사용
        startBtns.forEach((b) => { b.disabled = running || stopping; });

        if (stopping) {
            applyStoppingUi();
            return;
        }
        // 중단 버튼은 실행 중일 때만 노출(시작 전·종료 후엔 숨김) + 실행 중엔 강조(kh-stop-live)
        [stopBtn, barStop].forEach((b) => {
            b.disabled = !running;
            b.textContent = '중단';
            b.classList.toggle('kh-stop-live', running);
        });
        stopBtn.style.display = running ? '' : 'none';

        if (running) {
            statusEl.textContent = fmt(d);
            bar.style.display = '';
            barMsg.textContent = (d.stale ? '워커 지연 · ' : '') + fmt(d);
            if (!poll) poll = setInterval(fetchStatus, 4000);
        } else {
            statusEl.textContent = (d.done || d.held) ? (fmt(d) + ' · 완료/중단됨') : '';
            bar.style.display = 'none';
            if (poll) { clearInterval(poll); poll = null; }
        }
    }

    async function fetchStatus() {
        try {
            const url = statusUrl + (statusUrl.includes('?') ? '&' : '?') + 't=' + Date.now();
            const res = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            if (res.ok) render((await res.json()).data);
        } catch (e) {}
    }

    async function toggle(on, type) {
        if (!on) {
            if (stopping) return;   // 이미 중단 요청 중 — 중복 클릭 무시
            stopping = true;
            applyStoppingUi();
        }
        try {
            const res = await fetch(toggleUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ on: on ? 1 : 0, type: type || null }),
            });
            if (res.ok) {
                const d = (await res.json()).data;
                stopping = false;
                render(d);
                const wantsShopping = on && (type === 'shopping' || !type);
                if (d.hint) {
                    // 쇼핑 발행 재고(시장분석 데이터)가 없음 — 안내만 하지 않고 **수집을 자동 시작**한다.
                    // 시장분석이 생기면 수집 카드 폴링이 발행도 자동 시작한다(버튼 하나 흐름).
                    if (wantsShopping && window.__khStartCollect && window.__khStartCollect()) {
                        statusEl.textContent = '발행할 시장분석 데이터가 없어 수집을 자동 시작했습니다 — 시장분석이 생기는 대로 발행이 자동 시작됩니다(아래 수집 카드에서 진행 확인).';
                    } else {
                        statusEl.textContent = d.hint;
                    }
                } else {
                    if (!on) statusEl.textContent = fmt(d) + ' · 중단됨(진행 중이던 작업까지만 처리)';
                    // 쇼핑이 포함된 발행을 시작했으면 수집도 함께 켠다 — 발행이 소진돼도 계속 공급되게
                    if (on && d.running && wantsShopping && window.__khStartCollect) window.__khStartCollect();
                }
                return;
            }
            throw new Error('HTTP ' + res.status);
        } catch (e) {
            stopping = false;
            statusEl.textContent = '요청 실패 — 다시 시도해 주세요.';
            fetchStatus();   // 실제 상태로 버튼 복구
        }
    }

    startBtns.forEach((b) => b.addEventListener('click', () => toggle(true, b.dataset.type || null)));
    stopBtn.addEventListener('click', () => toggle(false));
    barStop.addEventListener('click', () => toggle(false));
    window.__khStartPublish = (t) => toggle(true, t);   // 수집 카드가 시장분석 생성 감지 시 발행 자동 시작

    render(@json($auto));
})();
</script>

<script>
(function () {
    const tabs = document.querySelectorAll('.kh-doc-tab');
    const panels = document.querySelectorAll('.kh-doc-panel');
    function activate(type) {
        tabs.forEach((tab) => {
            const active = tab.dataset.docTab === type;
            tab.style.background = active ? 'var(--color-ink)' : '';
            tab.style.color = active ? 'var(--color-canvas)' : '';
        });
        panels.forEach((panel) => {
            panel.style.display = panel.dataset.docPanel === type ? '' : 'none';
        });
    }
    tabs.forEach((tab) => tab.addEventListener('click', () => activate(tab.dataset.docTab)));
    activate('place');
})();
</script>
@endsection
