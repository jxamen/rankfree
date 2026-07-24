@extends('console.layout')
@section('page-title', '순위 추적')

@section('console-content')
<style>
    /* 날짜 카드 — 슬롯별 접기(날짜+순위만)/펼치기(리뷰·저장 포함) */
    .rf-cell { width: 104px; padding: 10px 8px 8px; }
    .rf-slot.rf-collapsed .rf-metrics { display: none; }
    .rf-slot.rf-collapsed .rf-cell { width: 78px; padding: 8px 6px; }
</style>
{{-- 메뉴명 + 설명 · 사용량 --}}
<x-console.page-head title="순위 추적">
    <x-slot:desc>플레이스 <b>키워드별 순위</b>를 매일 자동 갱신합니다 · 추적 중 <b>{{ $usedSlots }}</b> / {{ $maxSlots < 0 ? '무제한' : $maxSlots.'개' }} (플레이스+쇼핑 합산)</x-slot:desc>
</x-console.page-head>
{{-- 기간 필터(좌) + 키워드 검색(우) — 카드. 기간 지정 시 해당 기간의 순위만 표시 --}}
<form method="GET" class="card p-3 mb-4">
    <div class="flex items-center flex-wrap gap-2">
        {{-- 액션 버튼 (좌) --}}
        @if (auth()->user()->isOperator())
            {{-- 전체 순위체크 — 운영자/슈퍼어드민 전용 --}}
            <button type="button" id="rf-run-all" class="btn btn-secondary btn-sm" style="height:36px;" @disabled($slots->isEmpty())>전체 순위체크</button>
        @endif
        <a href="{{ route('console.rank.export') }}" class="btn btn-secondary btn-sm" style="height:36px;">엑셀 다운로드</a>
        <button type="button" id="rf-open-modal" class="btn btn-primary btn-sm" style="height:36px;" @disabled($maxSlots >= 0 && $usedSlots >= $maxSlots)>＋ 추적 추가</button>
        {{-- 기간 + 검색 (우) --}}
        <div style="margin-left:auto;display:flex;align-items:center;flex-wrap:wrap;gap:6px;">
            @if (($q ?? '') !== '' || ($from ?? null) || ($to ?? null))
                <a href="{{ route('console.rank') }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>
            @endif
            <input type="date" name="from" value="{{ $from ?? '' }}" class="input" style="width:148px;font-size:var(--fs-xs);">
            <span class="text-muted-soft">~</span>
            <input type="date" name="to" value="{{ $to ?? '' }}" class="input" style="width:148px;font-size:var(--fs-xs);">
            <input name="q" value="{{ $q ?? '' }}" class="input" style="width:260px;font-size:var(--fs-xs);" placeholder="키워드 검색">
            <button type="submit" class="btn btn-primary btn-sm" style="height:36px;">검색</button>
        </div>
    </div>
</form>

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

{{-- 슬롯 목록 — 키워드(슬롯)별로 각각 이미지 저장 --}}
@forelse ($slots as $slot)
    <x-rank.slot-card :rank-slot="$slot" mode="place" area="console" :from="$from ?? null" :to="$to ?? null" />
@empty
    <div class="card text-center" style="padding:56px 20px;color:var(--color-muted);">
        <div style="font-size:var(--fs-2xl);opacity:.4;">📈</div>
        <p class="mt-2" style="font-size:var(--fs-xs);">추적 중인 키워드가 없습니다. 우측 상단 "＋ 추적 추가"로 플레이스와 키워드를 등록하세요.</p>
    </div>
@endforelse
@include('console.partials._image-save')
@include('rank.partials._card-scripts')

<p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">
    플레이스 1개에 키워드를 여러 개 등록하면 키워드별로 순위를 추적합니다. 순위는 하루 1회 기록(당일 재확인 시 갱신)되며,
    영=영수증(방문자) 리뷰 · 블=블로그 리뷰 · 저장=저장수(음식점만 제공)입니다.
</p>

{{-- 추적 추가 모달 --}}
<div id="rf-modal" class="hidden" style="position:fixed;inset:0;z-index:50;">
    <div id="rf-modal-bg" style="position:absolute;inset:0;background:color-mix(in srgb, var(--color-ink) 40%, transparent);"></div>
    <div class="card" style="position:relative;max-width:640px;margin:7vh auto 0;max-height:84vh;overflow-y:auto;box-shadow:var(--shadow-card);">
        <div class="flex items-center justify-between px-5 border-b border-hairline-soft" style="height:52px;">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">순위 추적 추가</span>
            <button type="button" id="rf-modal-close" class="btn btn-ghost btn-sm" title="닫기">✕</button>
        </div>
        <form method="POST" action="{{ route('console.rank.store') }}" class="p-5" id="rf-rank-form">
            @csrf
            <div class="flex gap-3 flex-wrap items-start mb-4">
                <div style="flex:2;min-width:260px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">내 플레이스 URL 또는 ID</label>
                    <input name="place" id="rf-place" class="input" value="{{ old('place') }}" placeholder="https://map.naver.com/... · m.place URL · 플레이스 ID" required maxlength="1000" autocomplete="off">
                    <div id="rf-place-info" class="mt-1" style="font-size:var(--fs-xs);min-height:16px;"></div>
                </div>
                <div style="width:150px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">라벨 <span class="text-muted-soft">(선택)</span></label>
                    <input name="label" class="input" value="{{ old('label') }}" placeholder="예: 본점">
                </div>
            </div>

            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">추적 키워드 <span class="text-muted-soft">(여러 개 추가 가능)</span></label>
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
    </div>
</div>

{{-- 키워드/라벨 수정 모달 --}}
<div id="rf-edit-modal" class="hidden" style="position:fixed;inset:0;z-index:50;">
    <div class="rf-edit-close" style="position:absolute;inset:0;background:color-mix(in srgb, var(--color-ink) 40%, transparent);"></div>
    <div class="card" style="position:relative;max-width:480px;margin:14vh auto 0;box-shadow:var(--shadow-card);">
        <div class="flex items-center justify-between px-5 border-b border-hairline-soft" style="height:52px;">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">추적 키워드 수정</span>
            <button type="button" class="btn btn-ghost btn-sm rf-edit-close" title="닫기">✕</button>
        </div>
        <form method="POST" id="rf-edit-form" action="" class="p-5">
            @csrf @method('PUT')
            <input type="hidden" name="edit_slot_id" id="rf-edit-slot-id" value="">
            <div class="mb-3">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">키워드</label>
                <input name="keyword" id="rf-edit-keyword" class="input" value="" required maxlength="100">
            </div>
            <div class="mb-3">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">플레이스 URL 또는 ID</label>
                <input name="place" id="rf-edit-place" class="input" value="" required maxlength="1000" autocomplete="off" placeholder="https://m.place.naver.com/... · 플레이스 ID">
                <div id="rf-edit-place-info" class="mt-1" style="font-size:var(--fs-xs);min-height:16px;"></div>
            </div>
            <div class="mb-4">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">라벨 <span class="text-muted-soft">(선택)</span></label>
                <input name="label" id="rf-edit-label" class="input" value="" maxlength="100" placeholder="예: 본점">
            </div>
            <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">키워드·플레이스를 바꾸면 다음 확인부터 변경 기준으로 기록됩니다. 기존 기록은 유지됩니다.</p>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-secondary btn-sm rf-edit-close">취소</button>
                <button type="submit" class="btn btn-primary btn-sm">저장</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // ---- 추가 모달 열기/닫기 ---------------------------------------------
    const modal = document.getElementById('rf-modal');
    const openBtn = document.getElementById('rf-open-modal');
    const closeBtn = document.getElementById('rf-modal-close');
    const bg = document.getElementById('rf-modal-bg');
    function openModal() {
        modal.classList.remove('hidden');
        const first = modal.querySelector('input[name="place"]');
        if (first) setTimeout(() => first.focus(), 50);
    }
    function closeModal() { modal.classList.add('hidden'); }
    openBtn && openBtn.addEventListener('click', openModal);
    closeBtn && closeBtn.addEventListener('click', closeModal);
    bg && bg.addEventListener('click', closeModal);

    // ---- 수정 모달 -------------------------------------------------------
    const editModal = document.getElementById('rf-edit-modal');
    const editForm = document.getElementById('rf-edit-form');
    function openEdit(action, slotId, keyword, place, label) {
        editForm.action = action;
        document.getElementById('rf-edit-slot-id').value = slotId || '';
        document.getElementById('rf-edit-keyword').value = keyword || '';
        document.getElementById('rf-edit-place').value = place || '';
        document.getElementById('rf-edit-place-info').textContent = '';
        document.getElementById('rf-edit-label').value = label || '';
        editModal.classList.remove('hidden');
        setTimeout(() => document.getElementById('rf-edit-keyword').focus(), 50);
    }
    function closeEdit() { editModal.classList.add('hidden'); }
    document.querySelectorAll('.rf-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openEdit(btn.dataset.action, btn.dataset.slotId, btn.dataset.keyword, btn.dataset.place, btn.dataset.label);
        });
    });
    editModal.querySelectorAll('.rf-edit-close').forEach(function (el) {
        el.addEventListener('click', closeEdit);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (!editModal.classList.contains('hidden')) closeEdit();
        else if (!modal.classList.contains('hidden')) closeModal();
    });

    // 검증 실패 시 해당 모달을 입력값 유지한 채 다시 연다
    const editUrlTpl = @json(route('console.rank.update', ['slot' => '__ID__']));
    @if (old('edit_slot_id'))
        openEdit(editUrlTpl.replace('__ID__', @json(old('edit_slot_id'))), @json(old('edit_slot_id')), @json(old('keyword')), @json(old('place')), @json(old('label')));
    @elseif ($errors->any() || old('place'))
        openModal();
    @endif

    // ---- 공유 링크 복사: 상단 rfCopyShare(버튼 인라인 '복사됨 ✓') 사용 — 별도 핸들러 불필요 ----

    // 접기/펼치기 · 전체 순위체크 · 순위체크 AJAX 는 공용 파셜(rank.partials._card-scripts)로 이동

    // ---- 키워드 행 추가/삭제 --------------------------------------------
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

    // ---- 업체명 자동조회(디바운스) — 등록·수정 모달 공용 --------------------
    function attachResolver(el, info) {
        if (!el || !info) return;
        let t = null, lastQuery = '';
        function doResolve() {
            const v = (el.value || '').trim();
            if (v === '' || v === lastQuery) return;
            lastQuery = v;
            info.textContent = '업체명 조회 중…';
            info.style.color = 'var(--color-muted)';
            fetch(resolveUrl + '?place=' + encodeURIComponent(v), { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(d => {
                    if (d && d.ok && d.place_name) {
                        info.innerHTML = '✓ <b style="color:var(--color-ink)">' + d.place_name + '</b>'
                            + (d.category && d.category !== 'place' ? ' <span style="color:var(--color-muted-soft)">· ' + d.category + '</span>' : '')
                            + (d.place_id ? ' <span style="color:var(--color-muted-soft)">· ID ' + d.place_id + '</span>' : '');
                        info.style.color = 'var(--color-primary)';
                    } else if (d && d.place_id) {
                        info.textContent = 'ID ' + d.place_id + ' · 업체명은 등록 후 자동 확인됩니다.';
                        info.style.color = 'var(--color-muted)';
                    } else {
                        info.textContent = '플레이스를 찾지 못했습니다. URL/ID를 확인하세요(업체명 직접 입력도 가능).';
                        info.style.color = 'var(--color-muted-soft)';
                    }
                })
                .catch(() => { info.textContent = ''; });
        }
        el.addEventListener('input', function () {
            clearTimeout(t);
            t = setTimeout(doResolve, 600);
        });
        el.addEventListener('blur', doResolve);
        if (el.value.trim() !== '') doResolve();
    }
    attachResolver(placeEl, infoEl);
    attachResolver(document.getElementById('rf-edit-place'), document.getElementById('rf-edit-place-info'));
})();
</script>
@endsection
