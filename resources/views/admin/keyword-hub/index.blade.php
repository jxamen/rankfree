@extends('admin.layout')
@section('page-title', '키워드 콘텐츠 허브')

@php
    $stLabel = ['pending' => '대기', 'approved' => '승인', 'rejected' => '거부', 'published' => '발행됨'];
@endphp

@section('admin-content')
<x-console.page-head title="키워드 콘텐츠 허브" desc="승인된 키워드 후보를 분석 문서(/keyword/슬러그)로 발행합니다. 수집·승인은 후보·수집 관리에서 (설계 .claude/22)" />

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
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">연속 발행</div>
        <div class="flex items-center gap-2">
            <button type="button" id="kh-pub-start" class="btn btn-primary btn-sm">발행 시작</button>
            <button type="button" id="kh-pub-stop" class="btn btn-secondary btn-sm" disabled>중단</button>
            <span id="kh-pub-status" class="text-muted" style="font-size:var(--fs-xs);"></span>
        </div>
        <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">승인 후보를 검색량 큰 순으로 1건씩 계속 발행합니다(검색량 없으면 자동 보류). 중단을 누르면 진행 중인 1건까지만 하고 멈춥니다.</div>
        <div id="kh-pub-items" class="mt-2" style="font-size:var(--fs-xs);max-height:200px;overflow-y:auto;"></div>
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
    const start = document.getElementById('kh-pub-start');
    const stop = document.getElementById('kh-pub-stop');
    const statusEl = document.getElementById('kh-pub-status');
    const itemsEl = document.getElementById('kh-pub-items');
    let running = false, done = 0, held = 0;

    function setRunning(on) {
        running = on;
        start.disabled = on;
        stop.disabled = !on;
    }

    function addItem(it) {
        const row = document.createElement('div');
        row.className = 'flex items-center gap-2 py-1';
        row.style.borderBottom = '1px solid var(--color-hairline-soft)';
        if (it.ok && it.url) {
            row.innerHTML = '<a href="' + it.url + '" target="_blank" class="text-ink" style="text-decoration:none;">' + it.keyword + '</a><span class="text-muted-soft ml-auto">발행</span>';
        } else {
            row.innerHTML = '<span class="text-muted">' + it.keyword + '</span><span class="text-muted-soft ml-auto">보류(데이터 부족)</span>';
        }
        itemsEl.prepend(row);
    }

    async function loop() {
        while (running) {
            let res;
            try {
                res = await fetch('{{ route('admin.keyword-hub.publish-batch') }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                });
            } catch (e) {
                statusEl.textContent = '네트워크 오류 — 중단됨';
                break;
            }
            if (!res.ok) {
                statusEl.textContent = '오류 HTTP ' + res.status + ' — 중단됨';
                break;
            }
            const d = (await res.json()).data || {};
            done += d.published || 0;
            held += d.held || 0;
            (d.items || []).forEach(addItem);
            statusEl.textContent = '발행 ' + done + ' · 보류 ' + held + ' · 남은 승인 ' + (d.remaining ?? '?');
            if (!d.remaining) {
                statusEl.textContent += ' — 완료';
                break;
            }
        }
        setRunning(false);
    }

    start.addEventListener('click', function () {
        setRunning(true);
        statusEl.textContent = '발행 중…';
        loop();
    });
    stop.addEventListener('click', function () {
        running = false;                        // 진행 중인 1건이 끝나면 루프가 멈춘다
        stop.disabled = true;
        statusEl.textContent += ' (중단 요청 — 진행 중인 건까지)';
    });
})();
</script>
@endsection
