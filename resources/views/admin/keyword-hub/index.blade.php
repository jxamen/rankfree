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
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
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
            const res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });
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
@endsection
