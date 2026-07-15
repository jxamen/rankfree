@extends('console.layout')
@section('page-title', '대량 분석 — '.($bulk->name ?: $bulk->total.'개'))
@section('crumb-title', $bulk->name ?: $bulk->total.'개 키워드')

@section('page-actions')
    <a href="{{ route('console.bulk') }}" class="btn btn-secondary btn-sm">← 목록</a>
    @if ($bulk->status === 'done')
        <a href="{{ route('console.bulk.export', $bulk) }}" class="btn btn-primary btn-sm">엑셀 다운로드</a>
    @endif
@endsection

@section('console-content')
@php
    $itemBadge = fn ($s) => match ($s) {
        'done' => ['완료', 'var(--color-success)'], 'failed' => ['실패', 'var(--color-error)'],
        default => ['대기', 'var(--color-muted)'],
    };
@endphp

<x-console.page-head :title="$bulk->name ?: $bulk->total.'개 키워드'" desc="키워드 대량 분석 상세 · <b>검색량·발행량·포화·성별연령·요일·섹션배치</b> 결과" />

{{-- 진행 상태 --}}
<div class="card p-5 mb-6" id="bulk-progress" data-url="{{ route('console.bulk.process', $bulk) }}" data-finished="{{ $bulk->status === 'done' ? 1 : 0 }}">
    <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
        <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $bulk->name ?: $bulk->total.'개 키워드' }}</span>
        <span class="text-muted" style="font-size:var(--fs-xs);"><b id="bp-done">{{ $bulk->done + $bulk->failed }}</b> / {{ $bulk->total }} 완료 <span id="bp-fail" style="color:var(--color-error);">@if ($bulk->failed)(실패 {{ $bulk->failed }})@endif</span></span>
    </div>
    <div style="height:10px;background:var(--color-surface-strong);border-radius:99px;overflow:hidden;">
        <div id="bp-bar" style="height:100%;width:{{ $bulk->progressPct() }}%;background:var(--color-accent);border-radius:99px;transition:width .3s;"></div>
    </div>
    <div class="flex items-center gap-2 mt-3" id="bp-status">
        @if ($bulk->status === 'done')
            <span class="text-success" style="font-size:var(--fs-xs);font-weight:600;">✓ 수집 완료</span>
            <a href="{{ route('console.bulk.export', $bulk) }}" class="btn btn-secondary btn-sm" style="height:30px;margin-left:auto;">엑셀 다운로드</a>
        @else
            <span class="inline-flex items-center gap-2 text-muted" style="font-size:var(--fs-xs);">
                <span style="width:12px;height:12px;border:2px solid var(--color-hairline);border-top-color:var(--color-accent);border-radius:50%;display:inline-block;animation:bpspin .7s linear infinite;"></span>
                수집 중… 이 창을 열어두세요
            </span>
        @endif
    </div>
</div>
<style>@keyframes bpspin{to{transform:rotate(360deg)}}</style>

{{-- 키워드 목록 --}}
<div class="card overflow-hidden">
    <div class="px-5 py-4 text-ink font-semibold" style="font-size:var(--fs-xs);">키워드 {{ $bulk->total }}개</div>
    <div style="overflow:auto;max-height:600px;">
        <table class="w-full" style="min-width:520px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);position:sticky;top:0;background:var(--color-canvas);z-index:1;">
                    <th class="text-center px-4 py-2.5 font-semibold" style="width:52px;">No</th>
                    <th class="text-left px-3 py-2.5 font-semibold">키워드</th>
                    <th class="text-center px-3 py-2.5 font-semibold" style="width:80px;">상태</th>
                    <th class="text-left px-5 py-2.5 font-semibold">비고</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bulk->items as $i => $it)
                    @php [$il, $ic] = $itemBadge($it->status); @endphp
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="text-center px-4 py-2.5 text-muted font-display" style="font-size:var(--fs-xs);">{{ $i + 1 }}</td>
                        <td class="px-3 py-2.5 text-ink" style="font-size:var(--fs-xs);">{{ $it->keyword }}</td>
                        <td class="text-center px-3 py-2.5" style="font-size:var(--fs-xs);color:{{ $ic }};font-weight:600;">{{ $il }}</td>
                        <td class="px-5 py-2.5 text-muted-soft" style="font-size:var(--fs-xs);">{{ $it->fail_reason ?? ($it->status === 'done' && ($it->data['total'] ?? null) !== null ? '검색량 '.number_format((int) $it->data['total']) : '') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var box = document.getElementById('bulk-progress');
    if (!box || box.dataset.finished === '1') return;
    var bar = document.getElementById('bp-bar');
    var doneEl = document.getElementById('bp-done');
    var failEl = document.getElementById('bp-fail');
    var csrf = @json(csrf_token());
    var stopped = false;

    function tick() {
        if (stopped) return;
        fetch(box.dataset.url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                bar.style.width = d.pct + '%';
                doneEl.textContent = (d.done + d.failed);
                failEl.textContent = d.failed ? ('(실패 ' + d.failed + ')') : '';
                if (d.finished) {
                    stopped = true;
                    window.location.reload();   // 완료 → 최종 상태·다운로드 반영
                } else {
                    setTimeout(tick, 250);
                }
            })
            .catch(function () { stopped = true; document.getElementById('bp-status').innerHTML = '<span style="color:var(--color-error);font-size:var(--fs-xs);">수집 중 오류 — 새로고침하면 이어서 진행됩니다.</span>'; });
    }
    tick();
})();
</script>
@endsection
