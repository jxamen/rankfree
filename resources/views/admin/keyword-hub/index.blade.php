@extends('admin.layout')
@section('page-title', '키워드 콘텐츠 허브')

@php
    $stLabel = ['pending' => '대기', 'approved' => '승인', 'rejected' => '거부', 'published' => '발행됨'];
@endphp

@section('admin-content')
<x-console.page-head title="키워드 콘텐츠 허브" desc="승인된 키워드 후보를 분석 문서(/keyword/슬러그)로 발행합니다. 수집·승인은 후보·수집 관리에서 (설계 .claude/22)" />

{{-- 자동 발행 상단 바 — 서버 백그라운드 진행상황. 새로고침·재방문해도 서버 상태로 복원(키워드 탐색 수집바와 동일 개념) --}}
<div id="kh-auto-bar" class="flex items-center gap-3 px-4 py-2 mb-4"
     style="position:sticky;top:0;z-index:30;border-radius:16px;border:1px solid var(--color-hairline);background:color-mix(in srgb,var(--color-primary) 6%,var(--color-canvas));{{ ($auto['running'] ?? false) ? '' : 'display:none;' }}">
    <span class="badge border border-hairline flex-none" style="font-size:var(--fs-xs);">자동 발행 중</span>
    <span id="kh-auto-bar-msg" class="text-muted" style="font-size:var(--fs-xs);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
    <button type="button" id="kh-auto-bar-stop" class="btn btn-secondary btn-sm flex-none">중단</button>
</div>

{{-- 플래시(status)는 admin.layout 이 전역 표시 — 여기서는 검증 오류만 --}}
@if ($errors->any())
    <div class="card-soft px-4 py-3 mb-4" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

{{-- 현황 + 발행 --}}
<div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4 mb-5">
    <div class="card p-5">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">후보 현황</div>
        <div class="flex flex-wrap gap-2">
            @foreach ($stLabel as $k => $label)
                <a href="{{ route('admin.keyword-hub.candidates', ['status' => $k]) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);">
                    {{ $label }} <b class="font-mono">{{ number_format($counts[$k] ?? 0) }}</b>
                </a>
            @endforeach
        </div>
        <div class="text-muted mt-3" style="font-size:var(--fs-xs);">발행 문서 <b class="font-mono text-ink">{{ number_format($hubDocCount) }}</b>개 — 사이트맵 keyword 섹션에 자동 포함</div>
        <div class="mt-3">
            <a href="{{ route('admin.keyword-hub.candidates') }}" class="btn btn-secondary btn-sm">후보·수집 관리 →</a>
        </div>
    </div>

    <div class="card p-5">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">자동 분석·발행</div>
        <div class="flex items-center gap-3 mb-3" id="kh-pub-type" style="font-size:var(--fs-sm);">
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">유형</span>
            <label class="flex items-center gap-1.5" style="cursor:pointer;">
                <input type="radio" name="kh-type" value="shopping" checked> 쇼핑
            </label>
            <label class="flex items-center gap-1.5" style="cursor:pointer;">
                <input type="radio" name="kh-type" value="place"> 플레이스
            </label>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" id="kh-auto-start" class="btn btn-primary btn-sm">자동 발행 시작</button>
            <button type="button" id="kh-auto-stop" class="btn btn-secondary btn-sm" disabled>중단</button>
            <span id="kh-auto-status" class="text-muted" style="font-size:var(--fs-xs);"></span>
        </div>
        <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">시작하면 서버가 백그라운드로 선택한 유형의 쌓인 키워드를 검색량 큰 순으로 계속 분석·발행합니다(검색량 없으면 자동 보류). 브라우저를 닫아도 계속되고, 진행상황은 상단 바에 표시됩니다(매분 배치).</div>
    </div>

    <div class="card p-5">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">백그라운드 수집</div>
        <div class="flex items-center gap-2 mb-3 flex-wrap" style="font-size:var(--fs-xs);">
            <span id="kh-collect-control-badge" class="badge border border-hairline">
                수집 처리 {{ ($collectionControl['enabled'] ?? true) ? 'ON' : 'OFF' }}
            </span>
            <button type="button" id="kh-collect-control-on" class="btn btn-secondary btn-sm" style="height:32px;">켜기</button>
            <button type="button" id="kh-collect-control-off" class="btn btn-ghost btn-sm" style="height:32px;">끄기</button>
            <span id="kh-collect-control-note" class="text-muted-soft"></span>
        </div>
        <form method="POST" action="{{ route('admin.keyword-hub.collect-batch') }}" class="flex flex-col gap-3">
            @csrf
            <div class="flex items-center gap-3 flex-wrap" style="font-size:var(--fs-sm);">
                <label class="flex items-center gap-1.5" style="cursor:pointer;">
                    <input type="checkbox" name="collect_place" value="1" checked> 플레이스
                    <span class="font-mono text-muted">{{ number_format($collectTargets['place_categories'] ?? 0) }}</span>
                </label>
                <label class="flex items-center gap-1.5" style="cursor:pointer;">
                    <input type="checkbox" name="collect_shopping" value="1" checked> 쇼핑
                    <span class="font-mono text-muted">{{ number_format($collectTargets['shopping_roots'] ?? 0) }}</span>
                </label>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <label class="text-muted" style="font-size:var(--fs-xs);">플레이스 카테고리
                    <input type="number" name="place_limit" min="1" max="500" value="50" class="input mt-1" style="height:36px;">
                </label>
                <label class="text-muted" style="font-size:var(--fs-xs);">쇼핑 페이지
                    <input type="number" name="shopping_pages" min="1" max="25" value="{{ (int) config('rankfree.hub.datalab_pages', 25) }}" class="input mt-1" style="height:36px;">
                </label>
                <label class="text-muted" style="font-size:var(--fs-xs);">쇼핑 깊이
                    <select name="shopping_depth" class="input mt-1" style="height:36px;">
                        <option value="3" selected>3단계</option>
                        <option value="2">2단계</option>
                    </select>
                </label>
                <label class="text-muted" style="font-size:var(--fs-xs);">요청 간격(ms)
                    <input type="number" name="shopping_delay_ms" min="0" max="5000" value="300" class="input mt-1" style="height:36px;">
                </label>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="height:40px;">동시 수집 시작</button>
        </form>
        <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">큐 워커가 `hub-place`, `hub-shopping` 큐를 처리하면 병렬로 진행됩니다.</div>
    </div>
</div>

{{-- 최근 발행 문서 --}}
<div class="card p-5">
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">최근 발행 문서</div>
    @forelse ($hubDocs as $d)
        <div class="flex items-center gap-2 py-1.5" style="border-bottom:1px solid var(--color-hairline-soft);font-size:var(--fs-xs);">
            <a href="{{ $d->shareUrl() }}" target="_blank" class="text-ink font-semibold" style="text-decoration:none;">{{ $d->keyword }}</a>
            <span class="font-mono text-muted">월 {{ number_format((int) $d->monthly_total) }}회</span>
            <span class="text-muted-soft ml-auto">{{ $d->refreshed_at?->format('m-d H:i') ?? $d->created_at->format('m-d H:i') }}</span>
        </div>
    @empty
        <div class="text-muted-soft" style="font-size:var(--fs-xs);">아직 발행된 허브 문서가 없습니다.</div>
    @endforelse
</div>

<div class="card p-5 mt-5">
    <div class="flex items-center justify-between gap-2 mb-3">
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">최근 수집 작업</div>
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">자동 갱신</span>
    </div>
    <div id="kh-collect-runs" class="flex flex-col gap-2">
        @forelse ($collectionRuns as $run)
            @php
                $pct = $run->total_jobs > 0 ? (int) floor(($run->finished_jobs / max(1, $run->total_jobs)) * 100) : 100;
            @endphp
            <div class="card-soft p-3" style="font-size:var(--fs-xs);">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="badge border border-hairline">#{{ $run->id }}</span>
                    <span class="badge">{{ $run->type }}</span>
                    <span class="text-ink font-semibold">{{ $run->status }}</span>
                    <span class="text-muted font-mono">{{ number_format($run->finished_jobs) }}/{{ number_format($run->total_jobs) }}</span>
                    <span class="text-muted-soft ml-auto">{{ $run->created_at?->format('m-d H:i') }}</span>
                </div>
                <div class="mt-2" style="height:6px;border-radius:999px;background:var(--color-hairline-soft);overflow:hidden;">
                    <div style="height:100%;width:{{ $pct }}%;background:var(--color-primary);"></div>
                </div>
                <div class="text-muted mt-2">
                    신규 <b class="font-mono">{{ number_format($run->created_candidates) }}</b>
                    · 갱신 <b class="font-mono">{{ number_format($run->updated_candidates) }}</b>
                    · 필터 <b class="font-mono">{{ number_format($run->filtered_candidates) }}</b>
                    @if ($run->failed_jobs)
                        · 실패 <b class="font-mono text-error">{{ number_format($run->failed_jobs) }}</b>
                    @endif
                </div>
            </div>
        @empty
            <div class="text-muted-soft" style="font-size:var(--fs-xs);">아직 등록된 수집 작업이 없습니다.</div>
        @endforelse
    </div>
</div>

<script>
(function () {
    const startBtn = document.getElementById('kh-auto-start');
    const stopBtn = document.getElementById('kh-auto-stop');
    const statusEl = document.getElementById('kh-auto-status');
    const typeEls = document.querySelectorAll('input[name="kh-type"]');
    const bar = document.getElementById('kh-auto-bar');
    const barMsg = document.getElementById('kh-auto-bar-msg');
    const barStop = document.getElementById('kh-auto-bar-stop');
    const csrf = '{{ csrf_token() }}';
    const statusUrl = '{{ route('admin.keyword-hub.auto-status') }}';
    const toggleUrl = '{{ route('admin.keyword-hub.auto') }}';
    const typeLabel = { shopping: '쇼핑', place: '플레이스' };
    let poll = null;

    const selType = () => (document.querySelector('input[name="kh-type"]:checked') || {}).value || 'shopping';
    const fmt = (d) => (d.type ? '유형 ' + (typeLabel[d.type] || d.type) + ' · ' : '')
        + '발행 ' + (d.done || 0) + ' · 보류 ' + (d.held || 0)
        + ' · 남은 ' + Number(d.remaining || 0).toLocaleString()
        + (d.updated_ago != null ? ' · ' + d.updated_ago + '초 전 갱신' : '');

    function render(d) {
        d = d || {};
        const running = !!d.running;
        startBtn.disabled = running;
        stopBtn.disabled = !running;
        typeEls.forEach((el) => { el.disabled = running; if (running && d.type) el.checked = (el.value === d.type); });

        if (running) {
            statusEl.textContent = fmt(d);
            bar.style.display = '';
            barMsg.textContent = (d.stale ? '⚠ 크론 지연? · ' : '') + fmt(d);
            if (!poll) poll = setInterval(fetchStatus, 4000);
        } else {
            statusEl.textContent = (d.done || d.held) ? (fmt(d) + ' — 완료/중단됨') : '';
            bar.style.display = 'none';
            if (poll) { clearInterval(poll); poll = null; }
        }
    }

    async function fetchStatus() {
        try {
            // 캐시 무력화 — 브라우저가 이전 응답을 재사용하면 진행이 멈춘 것처럼 보인다(타임스탬프 + no-store)
            const url = statusUrl + (statusUrl.includes('?') ? '&' : '?') + 't=' + Date.now();
            const res = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            if (res.ok) render((await res.json()).data);
        } catch (e) { /* 이전 상태 유지 */ }
    }

    async function toggle(on) {
        try {
            const res = await fetch(toggleUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ on: on ? 1 : 0, type: selType() }),
            });
            if (res.ok) render((await res.json()).data);
        } catch (e) { statusEl.textContent = '요청 실패 — 다시 시도해 주세요'; }
    }

    startBtn.addEventListener('click', () => toggle(true));
    stopBtn.addEventListener('click', () => toggle(false));
    barStop.addEventListener('click', () => toggle(false));

    render(@json($auto));   // 서버 초기 상태 — 진행 중이면 상단 바 표시 + 폴링 시작
})();
</script>
<script>
(function () {
    const box = document.getElementById('kh-collect-runs');
    if (!box) return;

    const statusUrl = '{{ route('admin.keyword-hub.collect-status') }}';
    const controlUrl = '{{ route('admin.keyword-hub.collect-control') }}';
    const csrf = '{{ csrf_token() }}';
    const controlBadge = document.getElementById('kh-collect-control-badge');
    const controlOn = document.getElementById('kh-collect-control-on');
    const controlOff = document.getElementById('kh-collect-control-off');
    const controlNote = document.getElementById('kh-collect-control-note');
    const label = {
        queued: '대기',
        running: '진행',
        completed: '완료',
        failed: '실패',
        cancelled: '취소',
        both: '전체',
        place: '플레이스',
        shopping: '쇼핑',
    };

    const esc = (v) => String(v == null ? '' : v)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const nf = (v) => Number(v || 0).toLocaleString();

    function renderControl(state) {
        state = state || {};
        const enabled = state.enabled !== false;
        if (controlBadge) {
            controlBadge.textContent = '수집 처리 ' + (enabled ? 'ON' : 'OFF');
            controlBadge.style.background = enabled
                ? 'color-mix(in srgb,var(--color-success) 12%,var(--color-canvas))'
                : 'color-mix(in srgb,var(--color-error) 10%,var(--color-canvas))';
            controlBadge.style.color = enabled ? 'var(--color-success)' : 'var(--color-error)';
        }
        if (controlOn) controlOn.disabled = enabled;
        if (controlOff) controlOff.disabled = !enabled;
        if (controlNote) {
            const by = state.updated_by ? ' · ' + state.updated_by : '';
            controlNote.textContent = state.updated_at ? state.updated_at + by : '';
        }
    }

    async function setControl(enabled) {
        try {
            if (controlOn) controlOn.disabled = true;
            if (controlOff) controlOff.disabled = true;
            const res = await fetch(controlUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ enabled: enabled ? 1 : 0 }),
            });
            if (res.ok) renderControl((await res.json()).data);
        } catch (e) {
            if (controlNote) controlNote.textContent = '상태 변경 실패';
        }
    }

    function runHtml(run) {
        const pct = Math.max(0, Math.min(100, Number(run.progress || 0)));
        const items = (run.items || []).map((item) => (
            '<span class="badge border border-hairline" style="font-size:var(--fs-xs);">' +
            esc(item.label) + ' · ' + esc(label[item.status] || item.status) +
            (item.created_candidates ? ' +' + nf(item.created_candidates) : '') +
            '</span>'
        )).join(' ');

        return '<div class="card-soft p-3" style="font-size:var(--fs-xs);">' +
            '<div class="flex items-center gap-2 flex-wrap">' +
            '<span class="badge border border-hairline">#' + esc(run.id) + '</span>' +
            '<span class="badge">' + esc(label[run.type] || run.type) + '</span>' +
            '<span class="text-ink font-semibold">' + esc(label[run.status] || run.status) + '</span>' +
            '<span class="text-muted font-mono">' + nf(run.finished_jobs) + '/' + nf(run.total_jobs) + '</span>' +
            '<span class="text-muted-soft ml-auto">' + esc(run.created_at || '') + '</span>' +
            '</div>' +
            '<div class="mt-2" style="height:6px;border-radius:999px;background:var(--color-hairline-soft);overflow:hidden;">' +
            '<div style="height:100%;width:' + pct + '%;background:var(--color-primary);"></div>' +
            '</div>' +
            '<div class="text-muted mt-2">신규 <b class="font-mono">' + nf(run.created_candidates) + '</b>' +
            ' · 갱신 <b class="font-mono">' + nf(run.updated_candidates) + '</b>' +
            ' · 필터 <b class="font-mono">' + nf(run.filtered_candidates) + '</b>' +
            (run.failed_jobs ? ' · 실패 <b class="font-mono text-error">' + nf(run.failed_jobs) + '</b>' : '') +
            '</div>' +
            (items ? '<div class="flex flex-wrap gap-1.5 mt-2">' + items + '</div>' : '') +
            '</div>';
    }

    async function refreshRuns() {
        try {
            const url = statusUrl + (statusUrl.includes('?') ? '&' : '?') + 't=' + Date.now();
            const res = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            if (!res.ok) return;
            const runs = (await res.json()).data || [];
            box.innerHTML = runs.length
                ? runs.map(runHtml).join('')
                : '<div class="text-muted-soft" style="font-size:var(--fs-xs);">아직 등록된 수집 작업이 없습니다.</div>';
        } catch (e) {
            // keep the last rendered state
        }
    }

    refreshRuns();
    setInterval(refreshRuns, 5000);
    renderControl(@json($collectionControl));
    controlOn?.addEventListener('click', () => setControl(true));
    controlOff?.addEventListener('click', () => setControl(false));
})();
</script>
@endsection
