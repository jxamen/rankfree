@extends('console.layout')
@section('page-title', '순위 추적')

@section('console-content')
<div style="max-width:960px;">
    {{-- 사용량 --}}
    <div class="flex items-center justify-between mb-5 flex-wrap gap-2">
        <div class="text-muted" style="font-size:14px;">
            추적 중 <b class="text-ink">{{ $usedSlots }}</b> / {{ $maxSlots < 0 ? '무제한' : $maxSlots.'개' }}
            <span class="text-muted-soft">· 매일 자동 갱신 · 키워드별 순위</span>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:13px;">{{ $errors->first() }}</div>
    @endif

    {{-- 슬롯 추가: URL 1개 + 키워드 N개 --}}
    <form method="POST" action="{{ route('console.rank.store') }}" class="card p-5 mb-6" id="rf-rank-form">
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
            <button type="submit" class="btn btn-primary" @disabled($maxSlots >= 0 && $usedSlots >= $maxSlots)>추적 추가</button>
        </div>
    </form>

    {{-- 슬롯 목록 --}}
    <div class="card overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="text-muted" style="font-size:12px;">
                    <th class="text-left px-5 py-3 font-semibold">키워드 / 플레이스</th>
                    <th class="text-right px-3 py-3 font-semibold">현재 순위</th>
                    <th class="text-right px-3 py-3 font-semibold">리뷰</th>
                    <th class="text-center px-3 py-3 font-semibold">추이</th>
                    <th class="text-right px-5 py-3 font-semibold">갱신 / 삭제</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($slots as $slot)
                    @php
                        $rs = $slot->records->filter(fn ($r) => $r->rank > 0 && $r->rank < 300)->values();
                        $spark = null;
                        if ($rs->count() >= 2) {
                            $min = $rs->min('rank'); $max = $rs->max('rank'); $span = max(1, $max - $min);
                            $w = 110; $h = 28; $n = $rs->count();
                            $spark = $rs->map(fn ($r, $i) => round(($n > 1 ? $i / ($n - 1) : 0) * $w, 1).','.round((($r->rank - $min) / $span) * ($h - 6) + 3, 1))->implode(' ');
                        }
                    @endphp
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-5 py-3">
                            <div class="text-ink font-medium" style="font-size:14px;">{{ $slot->keyword }}</div>
                            <div class="text-muted-soft" style="font-size:12px;">{{ $slot->label ? $slot->label.' · ' : '' }}{{ $slot->place_name ?: ($slot->place_id ? 'ID '.$slot->place_id : $slot->place_url) }}</div>
                        </td>
                        <td class="px-3 py-3 text-right">
                            @if ($slot->last_rank === null)
                                <span class="text-muted-soft" style="font-size:13px;">미확인</span>
                            @elseif ($slot->last_rank > 0 && $slot->last_rank < 300)
                                <span class="font-display text-ink" style="font-size:16px;">{{ $slot->last_rank }}위</span>
                            @elseif ($slot->last_rank < 0)
                                <span style="color:var(--color-error);font-size:13px;">차단</span>
                            @else
                                <span class="text-muted-soft" style="font-size:13px;">300+</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right text-muted" style="font-size:13px;">{{ $slot->last_review_count !== null ? number_format($slot->last_review_count) : '—' }}</td>
                        <td class="px-3 py-3 text-center">
                            @if ($spark)
                                <svg width="110" height="28" style="vertical-align:middle;"><polyline fill="none" stroke="var(--color-primary)" stroke-width="1.8" points="{{ $spark }}"/></svg>
                            @else
                                <span class="text-muted-soft" style="font-size:12px;">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right text-nowrap">
                            <form method="POST" action="{{ route('console.rank.run', $slot) }}" style="display:inline;">@csrf<button type="submit" class="btn btn-secondary btn-sm">지금 확인</button></form>
                            <form method="POST" action="{{ route('console.rank.destroy', $slot) }}" style="display:inline;" onsubmit="return confirm('삭제하시겠습니까?')">@csrf @method('DELETE')<button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center" style="padding:56px 20px;color:var(--color-muted);">
                        <div style="font-size:28px;opacity:.4;">📈</div>
                        <p class="mt-2" style="font-size:14px;">추적 중인 키워드가 없습니다. 위에서 플레이스 URL과 키워드를 추가하세요.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="text-muted-soft mt-3" style="font-size:12px;">플레이스 URL 1개에 키워드를 여러 개 등록하면 키워드별로 순위를 추적합니다. 순위는 하루 1회 기록(당일 재확인 시 갱신)되며, 추이 그래프는 위쪽일수록 상위 순위입니다.</p>
</div>

<script>
(function () {
    const form = document.getElementById('rf-rank-form');
    if (!form) return;
    const kwWrap = document.getElementById('rf-keywords');
    const addBtn = document.getElementById('rf-kw-add');
    const placeEl = document.getElementById('rf-place');
    const infoEl = document.getElementById('rf-place-info');
    const resolveUrl = @json(route('console.rank.resolve'));

    function rowTemplate() {
        const row = document.createElement('div');
        row.className = 'rf-kw-row flex gap-2 mb-2';
        row.innerHTML = '<input name="keywords[]" class="input" style="flex:1;" placeholder="키워드 입력">'
            + '<button type="button" class="btn btn-ghost btn-sm rf-kw-del" title="삭제" style="width:40px;">✕</button>';
        return row;
    }
    // 키워드 추가
    addBtn && addBtn.addEventListener('click', function () {
        const row = rowTemplate();
        kwWrap.appendChild(row);
        row.querySelector('input').focus();
    });
    // 키워드 삭제(위임) — 최소 1행 유지
    kwWrap.addEventListener('click', function (e) {
        const del = e.target.closest('.rf-kw-del');
        if (!del) return;
        const rows = kwWrap.querySelectorAll('.rf-kw-row');
        if (rows.length <= 1) { del.closest('.rf-kw-row').querySelector('input').value = ''; return; }
        del.closest('.rf-kw-row').remove();
        // 첫 행은 required 유지
        const first = kwWrap.querySelector('.rf-kw-row input');
        if (first) first.setAttribute('required', 'required');
    });

    // 업체명 자동조회(디바운스)
    let t = null, lastQuery = '';
    function resolvePlace() {
        const v = (placeEl.value || '').trim();
        if (v === '' || v === lastQuery) return;
        lastQuery = v;
        infoEl.textContent = '업체명 조회 중…';
        infoEl.style.color = 'var(--color-muted)';
        fetch(resolveUrl + '?place=' + encodeURIComponent(v), { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(d => {
                if (d && d.ok && d.place_name) {
                    infoEl.innerHTML = '✓ <b style="color:var(--color-ink)">' + d.place_name + '</b>'
                        + (d.category && d.category !== 'place' ? ' <span style="color:var(--color-muted-soft)">· ' + d.category + '</span>' : '')
                        + (d.place_id ? ' <span style="color:var(--color-muted-soft)">· ID ' + d.place_id + '</span>' : '');
                    infoEl.style.color = 'var(--color-primary)';
                } else if (d && d.place_id) {
                    infoEl.textContent = 'ID ' + d.place_id + ' · 업체명은 등록 후 자동 확인됩니다.';
                    infoEl.style.color = 'var(--color-muted)';
                } else {
                    infoEl.textContent = '플레이스를 찾지 못했습니다. URL/ID를 확인하세요(업체명 직접 입력도 가능).';
                    infoEl.style.color = 'var(--color-muted-soft)';
                }
            })
            .catch(() => { infoEl.textContent = ''; });
    }
    placeEl && placeEl.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(resolvePlace, 600);
    });
    placeEl && placeEl.addEventListener('blur', resolvePlace);
    if (placeEl && placeEl.value.trim() !== '') resolvePlace();
})();
</script>
@endsection
