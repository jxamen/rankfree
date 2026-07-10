@extends('console.layout')
@section('page-title', '경쟁 분석')

@section('page-actions')
    <button type="button" id="rf-open-modal" class="btn btn-primary btn-sm" @disabled($maxSlots >= 0 && $usedSlots >= $maxSlots)>＋ 트랙 등록</button>
@endsection

@section('console-content')
<div>
    <p class="text-muted mb-4" style="font-size:14px;">
        순위추적 중인 <b class="text-ink">키워드 × 플레이스</b>의 SEO 경쟁력을 분석합니다. 같은 키워드 상위 경쟁사와 비교해
        <b class="text-ink">N1 유사도·N2 관련성·N3 랭킹</b> 점수를 산출합니다.
        <span class="text-muted-soft">점수는 관측 신호 기반 자체 추정치입니다.</span>
    </p>

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:13px;">{{ $errors->first() }}</div>
    @endif

    <div class="card overflow-x-auto">
        <table class="w-full" style="min-width:840px;">
            <thead>
                <tr class="text-muted" style="font-size:12px;">
                    <th class="text-right px-4 py-3 font-semibold" style="width:48px;">No</th>
                    <th class="text-left px-3 py-3 font-semibold">키워드</th>
                    <th class="text-left px-3 py-3 font-semibold">내 플레이스</th>
                    <th class="text-right px-3 py-3 font-semibold">순위</th>
                    <th class="text-right px-3 py-3 font-semibold">N1 유사도</th>
                    <th class="text-right px-3 py-3 font-semibold">N2 관련성</th>
                    <th class="text-right px-3 py-3 font-semibold">N3 랭킹</th>
                    <th class="text-center px-3 py-3 font-semibold">추이</th>
                    <th class="text-center px-3 py-3 font-semibold">최근분석</th>
                    <th class="text-right px-4 py-3 font-semibold">관리</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($slots as $slot)
                    @php
                        $s = $mineScores[$slot->id] ?? collect();
                        $sc = $s->last();
                        $prev = $s->count() >= 2 ? $s[$s->count() - 2] : null;
                        $delta = ($sc && $prev && $sc->n3 !== null && $prev->n3 !== null) ? round($sc->n3 - $prev->n3, 2) : null;
                        // N3 스파크라인
                        $spark = null;
                        $ns = $s->pluck('n3')->filter(fn ($v) => $v !== null)->values();
                        if ($ns->count() >= 2) {
                            $min = $ns->min(); $max = $ns->max(); $span = max(1, $max - $min);
                            $w = 90; $h = 24; $n = $ns->count();
                            $spark = $ns->map(fn ($v, $i) => round(($n > 1 ? $i / ($n - 1) : 0) * $w, 1).','.round($h - 3 - (($v - $min) / $span) * ($h - 6), 1))->implode(' ');
                        }
                    @endphp
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-4 py-3 text-right text-muted-soft" style="font-size:12px;">{{ $slot->id }}</td>
                        <td class="px-3 py-3">
                            <div class="text-ink font-medium" style="font-size:14px;">{{ $slot->keyword }}</div>
                            @if ($slot->label)<div class="text-muted-soft" style="font-size:11px;">{{ $slot->label }}</div>@endif
                        </td>
                        <td class="px-3 py-3">
                            <span class="text-ink" style="font-size:13px;">{{ $slot->place_name ?: ($slot->place_id ? 'ID '.$slot->place_id : '—') }}</span>
                            <span class="text-muted-soft" style="font-size:11px;"> · {{ $slot->category ?: 'place' }}</span>
                        </td>
                        <td class="px-3 py-3 text-right">
                            @if ($sc && $sc->rnk > 0 && $sc->rnk < 300)
                                <span class="font-display text-ink" style="font-size:15px;">{{ $sc->rnk }}위</span>
                            @elseif ($sc)
                                <span class="text-muted-soft" style="font-size:13px;">300+</span>
                            @else
                                <span class="text-muted-soft" style="font-size:12px;">미분석</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right text-ink" style="font-size:14px;">{{ $sc && $sc->n1 !== null ? round($sc->n1) : '—' }}</td>
                        <td class="px-3 py-3 text-right text-ink" style="font-size:14px;">{{ $sc && $sc->n2 !== null ? round($sc->n2) : '—' }}</td>
                        <td class="px-3 py-3 text-right">
                            @if ($sc && $sc->n3 !== null)
                                <span class="font-display text-ink" style="font-size:15px;">{{ round($sc->n3) }}</span>
                                @if ($delta !== null && $delta != 0)
                                    <span style="font-size:11px;color:{{ $delta > 0 ? 'var(--color-primary)' : 'var(--color-error)' }};">{{ $delta > 0 ? '▲' : '▼' }}{{ abs($delta) }}</span>
                                @endif
                            @else
                                <span class="text-muted-soft" style="font-size:13px;">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center">
                            @if ($spark)
                                <svg width="90" height="24" style="vertical-align:middle;"><polyline fill="none" stroke="var(--color-primary)" stroke-width="1.6" points="{{ $spark }}"/></svg>
                            @else
                                <span class="text-muted-soft" style="font-size:12px;">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center text-muted-soft" style="font-size:12px;">{{ $sc ? \Illuminate\Support\Carbon::parse($sc->ymd)->format('m-d') : '—' }}</td>
                        <td class="px-4 py-3 text-right text-nowrap">
                            <form method="POST" action="{{ route('console.compete.analyze', $slot) }}" class="rf-analyze-form" style="display:inline;" data-keyword="{{ $slot->keyword }}">
                                @csrf
                                <button type="submit" class="btn btn-primary btn-sm">{{ $sc ? '재분석' : '분석' }}</button>
                            </form>
                            <a href="{{ route('console.compete.show', $slot) }}" class="btn btn-secondary btn-sm" @if (! $sc) style="opacity:.45;pointer-events:none;" @endif>상세·비교</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center" style="padding:56px 20px;color:var(--color-muted);">
                        <div style="font-size:28px;opacity:.4;">📊</div>
                        <p class="mt-2" style="font-size:14px;">등록된 트랙이 없습니다. 우측 상단 <b class="text-ink">＋ 트랙 등록</b>으로 플레이스와 키워드를 추가하세요.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="text-muted-soft mt-3" style="font-size:12px;">"분석"은 상위 경쟁사 상세·리뷰를 수집해 점수를 산출하므로 20~40초 걸릴 수 있습니다. 결과는 매일 스냅샷으로 누적되어 추이가 쌓입니다.</p>
</div>

{{-- 트랙 등록 모달 (순위추적과 동일 UI) --}}
<div id="rf-modal" class="hidden" style="position:fixed;inset:0;z-index:50;">
    <div id="rf-modal-bg" style="position:absolute;inset:0;background:color-mix(in srgb, var(--color-ink) 40%, transparent);"></div>
    <div class="card" style="position:relative;max-width:640px;margin:7vh auto 0;max-height:84vh;overflow-y:auto;box-shadow:var(--shadow-card);">
        <div class="flex items-center justify-between px-5 border-b border-hairline-soft" style="height:52px;">
            <span class="text-ink font-semibold" style="font-size:15px;">트랙 등록</span>
            <button type="button" id="rf-modal-close" class="btn btn-ghost btn-sm" title="닫기">✕</button>
        </div>
        <form method="POST" action="{{ route('console.rank.store') }}" class="p-5" id="rf-rank-form">
            @csrf
            <div class="flex gap-3 flex-wrap items-start mb-4">
                <div style="flex:2;min-width:260px;">
                    <label class="block text-muted mb-1" style="font-size:12px;">내 플레이스 URL 또는 ID</label>
                    <input name="place" id="rf-place" class="input" value="{{ old('place') }}" placeholder="https://map.naver.com/... · m.place URL · 플레이스 ID" required autocomplete="off">
                    <div id="rf-place-info" class="mt-1" style="font-size:12px;min-height:16px;"></div>
                </div>
                <div style="width:150px;">
                    <label class="block text-muted mb-1" style="font-size:12px;">라벨 <span class="text-muted-soft">(선택)</span></label>
                    <input name="label" class="input" value="{{ old('label') }}" placeholder="예: 본점">
                </div>
            </div>

            <label class="block text-muted mb-1" style="font-size:12px;">추적 키워드 <span class="text-muted-soft">(여러 개 추가 가능)</span></label>
            <div id="rf-keywords">
                @php $olds = array_values(array_filter((array) old('keywords', ['']), fn ($v) => $v !== null)); @endphp
                @forelse ($olds as $kw)
                    <div class="rf-kw-row flex gap-2 mb-2">
                        <input name="keywords[]" class="input" style="flex:1;" value="{{ $kw }}" placeholder="강남 미용실" @if($loop->first) required @endif>
                        <button type="button" class="btn btn-ghost btn-sm rf-kw-del" title="삭제" style="width:40px;">✕</button>
                    </div>
                @empty
                    <div class="rf-kw-row flex gap-2 mb-2">
                        <input name="keywords[]" class="input" style="flex:1;" placeholder="강남 미용실" required>
                        <button type="button" class="btn btn-ghost btn-sm rf-kw-del" title="삭제" style="width:40px;">✕</button>
                    </div>
                @endforelse
            </div>

            <div class="flex items-center justify-between mt-3 flex-wrap gap-2">
                <button type="button" id="rf-kw-add" class="btn btn-secondary btn-sm">＋ 키워드 추가</button>
                <button type="submit" class="btn btn-primary" @disabled($maxSlots >= 0 && $usedSlots >= $maxSlots)>등록</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // ---- 등록 모달 ----
    const modal = document.getElementById('rf-modal');
    const openBtn = document.getElementById('rf-open-modal');
    function openModal() { modal.classList.remove('hidden'); const f = modal.querySelector('input[name="place"]'); if (f) setTimeout(() => f.focus(), 50); }
    function closeModal() { modal.classList.add('hidden'); }
    openBtn && openBtn.addEventListener('click', openModal);
    document.getElementById('rf-modal-close').addEventListener('click', closeModal);
    document.getElementById('rf-modal-bg').addEventListener('click', closeModal);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal(); });
    @if ($errors->any() || old('place')) openModal(); @endif

    // ---- 키워드 행 추가/삭제 ----
    const kwWrap = document.getElementById('rf-keywords');
    document.getElementById('rf-kw-add').addEventListener('click', function () {
        const row = document.createElement('div');
        row.className = 'rf-kw-row flex gap-2 mb-2';
        row.innerHTML = '<input name="keywords[]" class="input" style="flex:1;" placeholder="키워드 입력"><button type="button" class="btn btn-ghost btn-sm rf-kw-del" title="삭제" style="width:40px;">✕</button>';
        kwWrap.appendChild(row); row.querySelector('input').focus();
    });
    kwWrap.addEventListener('click', function (e) {
        const del = e.target.closest('.rf-kw-del'); if (!del) return;
        const rows = kwWrap.querySelectorAll('.rf-kw-row');
        if (rows.length <= 1) { del.closest('.rf-kw-row').querySelector('input').value = ''; return; }
        del.closest('.rf-kw-row').remove();
        const first = kwWrap.querySelector('.rf-kw-row input'); if (first) first.setAttribute('required', 'required');
    });

    // ---- 업체명 자동조회 ----
    const placeEl = document.getElementById('rf-place'), infoEl = document.getElementById('rf-place-info');
    const resolveUrl = @json(route('console.rank.resolve'));
    let t = null, last = '';
    function resolve() {
        const v = (placeEl.value || '').trim(); if (v === '' || v === last) return; last = v;
        infoEl.textContent = '업체명 조회 중…'; infoEl.style.color = 'var(--color-muted)';
        fetch(resolveUrl + '?place=' + encodeURIComponent(v), { headers: { 'Accept': 'application/json' } }).then(r => r.json()).then(d => {
            if (d && d.ok && d.place_name) {
                infoEl.innerHTML = '✓ <b style="color:var(--color-ink)">' + d.place_name + '</b>' + (d.category && d.category !== 'place' ? ' <span style="color:var(--color-muted-soft)">· ' + d.category + '</span>' : '') + (d.place_id ? ' <span style="color:var(--color-muted-soft)">· ID ' + d.place_id + '</span>' : '');
                infoEl.style.color = 'var(--color-primary)';
            } else if (d && d.place_id) { infoEl.textContent = 'ID ' + d.place_id + ' · 업체명은 등록 후 자동 확인됩니다.'; infoEl.style.color = 'var(--color-muted)'; }
            else { infoEl.textContent = '플레이스를 찾지 못했습니다. URL/ID를 확인하세요.'; infoEl.style.color = 'var(--color-muted-soft)'; }
        }).catch(() => { infoEl.textContent = ''; });
    }
    placeEl.addEventListener('input', () => { clearTimeout(t); t = setTimeout(resolve, 600); });
    placeEl.addEventListener('blur', resolve);
    if (placeEl.value.trim() !== '') resolve();

    // ---- 분석 실행: 순위추적과 동일한 Swal 로딩 ----
    document.querySelectorAll('.rf-analyze-form').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            Swal.fire({ title: '경쟁 분석 중…', html: '<span style="font-size:13px;color:var(--color-muted);">‘' + (f.dataset.keyword || '') + '’ 상위 경쟁사 상세·리뷰를 수집해 점수를 산출합니다. 20~40초 걸릴 수 있습니다.</span>', allowOutsideClick: false, showConfirmButton: false, didOpen: function () { Swal.showLoading(); } });
            fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
                .then(d => { Swal.fire({ toast: true, position: 'top-end', icon: d.ok ? 'success' : 'warning', title: d.message, showConfirmButton: false, timer: 1800, timerProgressBar: true }).then(() => { if (d.redirect) location.href = d.redirect; else location.reload(); }); })
                .catch(() => { Swal.fire({ icon: 'error', title: '분석에 실패했습니다', text: '잠시 후 다시 시도하세요.' }); });
        });
    });
})();
</script>
@endsection
