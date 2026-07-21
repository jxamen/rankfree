@extends('admin.layout')
@section('page-title', '키워드 자동 분석')

@php
    $stLabel = ['pending' => '대기', 'approved' => '승인', 'rejected' => '거부', 'published' => '발행됨'];
@endphp

@section('admin-content')
<x-console.page-head title="키워드 자동 분석" desc="플레이스 후보는 키워드 분석, 쇼핑 후보는 쇼핑 시장 분석 문서로 병렬 발행합니다." />

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
        [stopBtn, barStop].forEach((b) => { b.style.display = ''; b.disabled = true; b.textContent = '중단 중…'; });
        barMsg.textContent = '중단 처리 중 — 진행 중이던 작업까지만 하고 멈춥니다';
    }

    function render(d) {
        d = d || {};
        const running = !!d.running;
        startBtns.forEach((b) => { b.disabled = running || stopping; });

        if (stopping) {
            applyStoppingUi();
            return;
        }
        // 중단 버튼은 실행 중일 때만 노출(시작 전·종료 후엔 숨김)
        [stopBtn, barStop].forEach((b) => { b.disabled = !running; b.textContent = '중단'; });
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
                if (!on) statusEl.textContent = fmt(d) + ' · 중단됨(진행 중이던 작업까지만 처리)';
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
