@extends('console.layout')
@section('page-title', '저장 블로거')

@section('console-content')
@php
    $gradeColor = fn ($g) => match ($g) {
        'S' => 'var(--color-success)', 'A' => 'var(--color-accent)', 'B' => 'var(--color-badge-violet)',
        'C' => 'var(--color-warning)', 'D' => 'var(--color-muted)', default => 'var(--color-muted)',
    };
    $nf = fn ($v) => $v === null ? '—' : number_format((int) $v);
    $ymd = fn ($d) => ($d && \Illuminate\Support\Carbon::hasFormat((string) $d, 'Ymd')) ? \Illuminate\Support\Carbon::createFromFormat('Ymd', (string) $d)->format('Y-m-d') : (string) $d;
@endphp

<div class="flex items-end justify-between flex-wrap gap-2 mb-4">
    <div>
        <div class="text-ink font-display" style="font-size:var(--fs-xl);">저장 블로거</div>
        <div class="text-muted-soft" style="font-size:var(--fs-xs);">키워드 분석에서 저장한 블로거 모음 · <b>키워드 × 블로그 ID</b> 조합으로 관리됩니다</div>
    </div>
    <div class="flex items-center gap-2">
        <button type="button" id="sb-del-sel" class="btn btn-secondary btn-sm" style="height:36px;" disabled>선택 삭제 <span id="sb-sel-count">0</span></button>
        <a href="{{ route('console.blog-saved.export', $kw !== '' ? ['kw' => $kw] : []) }}" class="btn btn-secondary btn-sm" style="height:36px;" title="{{ $kw !== '' ? '‘'.$kw.'’ 저장 블로거만' : '전체 저장 블로거' }} 엑셀 다운로드">엑셀 다운로드</a>
        <a href="{{ route('console.blog') }}" class="btn btn-secondary btn-sm" style="height:36px;">블로그 수집으로</a>
    </div>
</div>

@if ($keywords->count())
    {{-- 키워드 필터 칩 — 전체 / 키워드별 저장 건수 --}}
    <div class="card p-3 mb-3">
        <div class="flex items-center flex-wrap" style="gap:8px 6px;">
            <span class="text-muted" style="font-size:var(--fs-xs);margin-right:4px;">키워드</span>
            <a href="{{ route('console.blog-saved') }}" class="sb-chip {{ $kw === '' ? 'on' : '' }}">전체 <span class="cnt">{{ $keywords->sum('cnt') }}</span></a>
            @foreach ($keywords as $k)
                <a href="{{ route('console.blog-saved', ['kw' => $k->keyword]) }}" class="sb-chip {{ $kw === $k->keyword ? 'on' : '' }}">{{ $k->keyword }} <span class="cnt">{{ $k->cnt }}</span></a>
            @endforeach
            <input type="text" id="sb-search" placeholder="블로그명·ID·제목·주제어 검색" autocomplete="off"
                   class="input" style="height:34px;width:240px;font-size:var(--fs-xs);margin-left:auto;">
        </div>
    </div>
@endif

@if (! $rows->count())
    <div class="card p-8 text-center">
        <div style="font-size:var(--fs-2xl);opacity:.35;">★</div>
        <p class="text-ink font-semibold mt-3" style="font-size:var(--fs-base);">저장한 블로거가 없습니다</p>
        <p class="text-muted mt-1" style="font-size:var(--fs-xs);"><a href="{{ route('console.blog') }}" class="text-accent hover:underline">블로그 수집</a>에서 키워드를 분석한 뒤, 목록에서 ★ 또는 체크박스로 블로거를 저장해 보세요.</p>
    </div>
@else
    <div class="card overflow-hidden mb-4">
        <div style="overflow-x:auto;">
            <table class="w-full" style="min-width:1320px;" id="sb-table">
                <thead>
                    <tr class="text-muted" style="font-size:var(--fs-xs);">
                        <th class="text-center px-3 py-3" style="width:36px;"><input type="checkbox" id="sb-check-all" title="표시 중인 행 전체 선택" style="width:15px;height:15px;accent-color:var(--color-ink);cursor:pointer;"></th>
                        <th class="text-left px-3 py-3 font-semibold">키워드</th>
                        <th class="text-left px-3 py-3 font-semibold">블로그</th>
                        <th class="text-center px-3 py-3 font-semibold">등급</th>
                        <th class="text-right px-3 py-3 font-semibold">지수</th>
                        <th class="text-right px-3 py-3 font-semibold">게시물</th>
                        <th class="text-right px-3 py-3 font-semibold">일방문</th>
                        <th class="text-right px-3 py-3 font-semibold">노출글 사진</th>
                        <th class="text-right px-3 py-3 font-semibold">노출글 본문</th>
                        <th class="text-left px-3 py-3 font-semibold">전문 주제어</th>
                        <th class="text-right px-3 py-3 font-semibold" style="white-space:nowrap;">저장일</th>
                        <th class="text-center px-3 py-3 font-semibold" style="width:56px;">삭제</th>
                    </tr>
                </thead>
                <tbody id="sb-tbody">
                    @foreach ($rows as $s)
                        @php
                            $b = (array) $s->data;
                            $p = $b['profile'] ?? [];
                            $q = $b['quality'] ?? [];
                            $searchStr = mb_strtolower(trim(
                                $s->keyword.' '.($s->blog_name ?? '').' '.$s->blog_id.' '.($b['featured']['title'] ?? '').' '
                                .implode(' ', array_map(fn ($w) => $w['word'], $q['top_words'] ?? []))
                            ));
                        @endphp
                        <tr class="sb-row" style="border-top:1px solid var(--color-hairline-soft);" data-id="{{ $s->id }}" data-search="{{ $searchStr }}">
                            <td class="px-3 py-3 text-center"><input type="checkbox" class="sb-sel" value="{{ $s->id }}" style="width:15px;height:15px;accent-color:var(--color-ink);cursor:pointer;"></td>
                            <td class="px-3 py-3">
                                <a href="{{ route('console.blog-saved', ['kw' => $s->keyword]) }}" class="badge hover:underline" style="font-size:var(--fs-xs);padding:2px 9px;" title="이 키워드 저장 블로거만 보기">{{ $s->keyword }}</a>
                            </td>
                            <td class="px-3 py-3" style="max-width:320px;">
                                <a href="https://blog.naver.com/{{ $s->blog_id }}" target="_blank" class="text-ink font-semibold hover:underline" style="font-size:var(--fs-xs);">{{ $s->blog_name ?: $s->blog_id }}</a>
                                <div class="text-muted-soft" style="font-size:var(--fs-xs);"><a href="{{ route('console.blog-single', ['q' => $s->blog_id]) }}" class="hover:text-ink hover:underline">{{ $s->blog_id }}</a>@if ($p['power_blog'] ?? false) · <span style="color:var(--color-badge-orange);">파워</span>@endif</div>
                                @if (! empty($b['featured']['title']))
                                    <a href="https://blog.naver.com/{{ $s->blog_id }}/{{ $b['featured']['log_no'] ?? '' }}" target="_blank" class="text-muted truncate block hover:underline" style="font-size:var(--fs-xs);margin-top:2px;">📄 {{ $b['featured']['title'] }}</a>
                                    @if (! empty($b['featured']['date']))
                                        <span class="text-muted-soft" style="font-size:var(--fs-xs);">🗓 {{ $ymd($b['featured']['date']) }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center"><span class="badge" style="font-size:var(--fs-xs);padding:2px 10px;background:color-mix(in srgb,{{ $gradeColor($s->grade) }} 14%,var(--color-canvas));color:{{ $gradeColor($s->grade) }};font-weight:700;">{{ $s->grade ?: '—' }}</span></td>
                            <td class="px-3 py-3 text-right font-display" style="font-size:var(--fs-base);color:{{ $gradeColor($s->grade) }};">{{ $s->score ?? '—' }}</td>
                            <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $nf($p['post_total'] ?? 0) }}</td>
                            <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $nf($p['day_visitor_avg'] ?? 0) }}</td>
                            <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $q['avg_photos'] ?? 0 }}</td>
                            <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $nf($q['avg_length'] ?? 0) }}자</td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1" style="max-width:240px;">
                                    @foreach (array_slice($q['top_words'] ?? [], 0, 5) as $w)
                                        <span class="badge" style="font-size:var(--fs-xs);padding:1px 7px;">{{ $w['word'] }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);white-space:nowrap;">{{ $s->created_at?->format('Y-m-d') }}</td>
                            <td class="px-3 py-3 text-center">
                                <button type="button" class="sb-del text-muted-soft hover:text-ink" style="font-size:var(--fs-xs);text-decoration:underline;background:none;border:none;cursor:pointer;">삭제</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div id="sb-empty" class="hidden px-5 py-8 text-center text-muted-soft" style="font-size:var(--fs-xs);">조건에 맞는 저장 블로거가 없습니다.</div>
    </div>
    <p class="text-muted-soft" style="font-size:var(--fs-xs);">지수·등급은 <b>저장 시점</b> 분석 값입니다. 같은 키워드로 다시 저장하면 최신 값으로 갱신됩니다.</p>
@endif

<script>
(function () {
    var tbody = document.getElementById('sb-tbody');
    if (!tbody) return;
    var delBtn = document.getElementById('sb-del-sel');
    var selCountEl = document.getElementById('sb-sel-count');
    var checkAll = document.getElementById('sb-check-all');
    var search = document.getElementById('sb-search');
    var emptyEl = document.getElementById('sb-empty');

    function updateSel() {
        var n = tbody.querySelectorAll('.sb-sel:checked').length;
        if (selCountEl) selCountEl.textContent = n;
        if (delBtn) delBtn.disabled = n === 0;
    }
    function applySearch() {
        var q = (search && search.value || '').trim().toLowerCase();
        var shown = 0;
        tbody.querySelectorAll('tr.sb-row').forEach(function (tr) {
            var ok = !q || (tr.dataset.search || '').indexOf(q) >= 0;
            tr.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        if (emptyEl) emptyEl.classList.toggle('hidden', shown > 0);
    }
    function removeRows(ids) {
        return fetch('{{ route('console.blog-saved.destroy') }}', {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: ids })
        }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }).then(function (d) {
            ids.forEach(function (id) {
                var tr = tbody.querySelector('tr.sb-row[data-id="' + id + '"]');
                if (tr) tr.remove();
            });
            updateSel(); applySearch();
            if (!tbody.querySelector('tr.sb-row')) location.reload(); // 마지막 행 삭제 → 빈 상태/칩 갱신
            return d;
        });
    }

    if (search) search.addEventListener('input', applySearch);
    tbody.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('sb-sel')) updateSel();
    });
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            tbody.querySelectorAll('tr.sb-row').forEach(function (tr) {
                if (tr.style.display === 'none') return; // 검색으로 숨긴 행 제외
                var cb = tr.querySelector('.sb-sel');
                if (cb) cb.checked = checkAll.checked;
            });
            updateSel();
        });
    }
    if (delBtn) {
        delBtn.addEventListener('click', function () {
            var ids = Array.prototype.map.call(tbody.querySelectorAll('.sb-sel:checked'), function (c) { return c.value; });
            if (!ids.length) return;
            Swal.fire({
                icon: 'warning', title: ids.length + '개 저장 블로거를 삭제할까요?', text: '삭제하면 되돌릴 수 없습니다.',
                showCancelButton: true, confirmButtonText: '삭제', cancelButtonText: '취소'
            }).then(function (res) {
                if (!res.isConfirmed) return;
                removeRows(ids).catch(function () {
                    Swal.fire({ icon: 'error', title: '삭제 실패', text: '잠시 후 다시 시도하세요.' });
                });
            });
        });
    }
    tbody.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.sb-del');
        if (!btn) return;
        var tr = btn.closest('tr.sb-row');
        if (!tr) return;
        removeRows([tr.dataset.id]).catch(function () {
            Swal.fire({ icon: 'error', title: '삭제 실패', text: '잠시 후 다시 시도하세요.' });
        });
    });
})();
</script>

<style>
    .sb-chip { display:inline-flex; align-items:center; gap:5px; height:32px; padding:0 11px; border:1px solid var(--color-hairline); border-radius:8px; background:var(--color-canvas); font-size:var(--fs-xs); font-weight:600; color:var(--color-body); transition:all .12s; }
    .sb-chip:hover { border-color:var(--color-ink); }
    .sb-chip.on { background:var(--color-ink); color:var(--color-canvas); border-color:var(--color-ink); }
    .sb-chip .cnt { font-weight:400; opacity:.65; }
    tr.sb-row:hover { background:var(--color-surface-soft); }
</style>
@endsection
