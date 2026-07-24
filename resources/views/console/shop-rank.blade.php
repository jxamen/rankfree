@extends('console.layout')
@section('page-title', '쇼핑 순위추적')

@section('console-content')
<style>
    .rf-cell { width: 100px; padding: 10px 8px 8px; }
</style>

{{-- 메뉴명 + 설명 · 사용량 --}}
<x-console.page-head title="쇼핑 순위추적">
    <x-slot:desc>네이버 쇼핑 <b>상품/업체 × 키워드</b> 순위를 매일 자동 기록합니다 · 추적 중 <b>{{ $usedSlots }}</b> / {{ $maxSlots < 0 ? '무제한' : $maxSlots.'개' }} (플레이스+쇼핑 합산)</x-slot:desc>
</x-console.page-head>
{{-- 액션(좌) + 기간·키워드 검색(우) — 카드 --}}
<form method="GET" class="card p-3 mb-4">
    <div class="flex items-center flex-wrap gap-2">
        {{-- 액션 버튼 (좌) --}}
        @if (auth()->user()->isOperator())
            {{-- 전체 순위체크 — 운영자/슈퍼어드민 전용 --}}
            <button type="button" id="rf-run-all" class="btn btn-secondary btn-sm" style="height:36px;" @disabled($slots->isEmpty())>전체 순위체크</button>
        @endif
        @if (! $slots->isEmpty())
            <a href="{{ route('console.shop-rank.export') }}" class="btn btn-secondary btn-sm" style="height:36px;">엑셀 다운로드</a>
        @endif
        <button type="button" id="rf-open-modal" class="btn btn-primary btn-sm" style="height:36px;" @disabled($maxSlots >= 0 && $usedSlots >= $maxSlots)>＋ 추적 추가</button>
        {{-- 기간 + 검색 (우) --}}
        <div style="margin-left:auto;display:flex;align-items:center;flex-wrap:wrap;gap:6px;">
            @if (($q ?? '') !== '' || ($from ?? null) || ($to ?? null))
                <a href="{{ route('console.shop-rank') }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>
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
    <x-rank.slot-card :rank-slot="$slot" mode="shop" area="console" :from="$from ?? null" :to="$to ?? null" />
@empty
    <div class="card text-center" style="padding:56px 20px;color:var(--color-muted);">
        <div style="font-size:var(--fs-2xl);opacity:.4;">🛒</div>
        <p class="mt-2" style="font-size:var(--fs-xs);">추적 중인 상품이 없습니다. 우측 상단 "＋ 추적 추가"로 상품 URL(또는 업체명)과 키워드를 등록하세요.</p>
    </div>
@endforelse
@include('console.partials._image-save')
@include('rank.partials._card-scripts')

<p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">
    스마트스토어·가격비교 상품 URL 또는 업체명 1개에 키워드를 여러 개 등록하면 키워드별로 쇼핑 검색 순위를 추적합니다.
    순위는 하루 1회 기록(당일 재확인 시 갱신)되며 최대 {{ number_format((int) config('rankfree.shopping.display', 100) * (int) config('rankfree.shopping.max_pages', 10)) }}위까지 조회합니다.
</p>

{{-- 추적 추가 모달 --}}
<div id="rf-modal" class="hidden" style="position:fixed;inset:0;z-index:50;">
    <div id="rf-modal-bg" style="position:absolute;inset:0;background:color-mix(in srgb, var(--color-ink) 40%, transparent);"></div>
    <div class="card" style="position:relative;max-width:640px;margin:7vh auto 0;max-height:84vh;overflow-y:auto;box-shadow:var(--shadow-card);">
        <div class="flex items-center justify-between px-5 border-b border-hairline-soft" style="height:52px;">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">쇼핑 순위추적 추가</span>
            <button type="button" id="rf-modal-close" class="btn btn-ghost btn-sm" title="닫기">✕</button>
        </div>
        <form method="POST" action="{{ route('console.shop-rank.store') }}" class="p-5" id="rf-rank-form">
            @csrf
            <div class="flex gap-3 flex-wrap items-start mb-4">
                <div style="flex:2;min-width:280px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">상품 URL(스마트스토어/가격비교) 또는 업체명</label>
                    <input name="target" id="rf-target" class="input" value="{{ old('target') }}" placeholder="https://smartstore.naver.com/.../products/123... · 또는 업체명" required autocomplete="off">
                    <div id="rf-target-info" class="mt-1" style="font-size:var(--fs-xs);min-height:16px;"></div>
                </div>
                <div style="width:150px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">라벨 <span class="text-muted-soft">(선택)</span></label>
                    <input name="label" class="input" value="{{ old('label') }}" placeholder="예: 신상">
                </div>
            </div>

            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">노출시킬 키워드 <span class="text-muted-soft">(여러 개 추가 가능)</span></label>
            <div id="rf-keywords">
                @php $olds = array_values(array_filter((array) old('keywords', ['']), fn ($v) => $v !== null)); @endphp
                @forelse ($olds as $kw)
                    <div class="rf-kw-row flex gap-2 mb-2">
                        <input name="keywords[]" class="input" style="flex:1;" value="{{ $kw }}" placeholder="예: 강아지 사료" @if($loop->first) required @endif>
                        <button type="button" class="btn btn-ghost btn-sm rf-kw-del" title="삭제" style="width:40px;">✕</button>
                    </div>
                @empty
                    <div class="rf-kw-row flex gap-2 mb-2">
                        <input name="keywords[]" class="input" style="flex:1;" placeholder="예: 강아지 사료" required>
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

{{-- 수정 모달 --}}
<div id="rf-edit-modal" class="hidden" style="position:fixed;inset:0;z-index:50;">
    <div class="rf-edit-close" style="position:absolute;inset:0;background:color-mix(in srgb, var(--color-ink) 40%, transparent);"></div>
    <div class="card" style="position:relative;max-width:480px;margin:14vh auto 0;box-shadow:var(--shadow-card);">
        <div class="flex items-center justify-between px-5 border-b border-hairline-soft" style="height:52px;">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">추적 수정</span>
            <button type="button" class="btn btn-ghost btn-sm rf-edit-close" title="닫기">✕</button>
        </div>
        <form method="POST" id="rf-edit-form" action="" class="p-5">
            @csrf @method('PUT')
            <input type="hidden" name="edit_slot_id" id="rf-edit-slot-id" value="">
            <div class="mb-3">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">키워드</label>
                <input name="keyword" id="rf-edit-keyword" class="input" value="" required maxlength="120">
            </div>
            <div class="mb-3">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">상품 URL 또는 업체명</label>
                <input name="target" id="rf-edit-target" class="input" value="" required maxlength="500" autocomplete="off" placeholder="상품 URL · 또는 업체명">
                <div id="rf-edit-target-info" class="mt-1" style="font-size:var(--fs-xs);min-height:16px;"></div>
            </div>
            <div class="mb-4">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">라벨 <span class="text-muted-soft">(선택)</span></label>
                <input name="label" id="rf-edit-label" class="input" value="" maxlength="100" placeholder="예: 신상">
            </div>
            <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">키워드·대상을 바꾸면 다음 확인부터 변경 기준으로 기록됩니다. 기존 기록은 유지됩니다.</p>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-secondary btn-sm rf-edit-close">취소</button>
                <button type="submit" class="btn btn-primary btn-sm">저장</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('rf-modal');
    const openBtn = document.getElementById('rf-open-modal');
    const closeBtn = document.getElementById('rf-modal-close');
    const bg = document.getElementById('rf-modal-bg');
    function openModal() {
        modal.classList.remove('hidden');
        const first = modal.querySelector('input[name="target"]');
        if (first) setTimeout(() => first.focus(), 50);
    }
    function closeModal() { modal.classList.add('hidden'); }
    openBtn && openBtn.addEventListener('click', openModal);
    closeBtn && closeBtn.addEventListener('click', closeModal);
    bg && bg.addEventListener('click', closeModal);

    const editModal = document.getElementById('rf-edit-modal');
    const editForm = document.getElementById('rf-edit-form');
    function openEdit(action, slotId, keyword, target, label) {
        editForm.action = action;
        document.getElementById('rf-edit-slot-id').value = slotId || '';
        document.getElementById('rf-edit-keyword').value = keyword || '';
        document.getElementById('rf-edit-target').value = target || '';
        document.getElementById('rf-edit-target-info').textContent = '';
        document.getElementById('rf-edit-label').value = label || '';
        editModal.classList.remove('hidden');
        setTimeout(() => document.getElementById('rf-edit-keyword').focus(), 50);
    }
    function closeEdit() { editModal.classList.add('hidden'); }
    document.querySelectorAll('.rf-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openEdit(btn.dataset.action, btn.dataset.slotId, btn.dataset.keyword, btn.dataset.target, btn.dataset.label);
        });
    });
    editModal.querySelectorAll('.rf-edit-close').forEach(function (el) { el.addEventListener('click', closeEdit); });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (!editModal.classList.contains('hidden')) closeEdit();
        else if (!modal.classList.contains('hidden')) closeModal();
    });

    const editUrlTpl = @json(route('console.shop-rank.update', ['slot' => '__ID__']));
    @if (old('edit_slot_id'))
        openEdit(editUrlTpl.replace('__ID__', @json(old('edit_slot_id'))), @json(old('edit_slot_id')), @json(old('keyword')), @json(old('target')), @json(old('label')));
    @elseif ($errors->any() || old('target'))
        openModal();
    @endif

    // 공유 링크 복사: 상단 rfCopyShare(버튼 인라인 '복사됨 ✓') 사용 — 별도 핸들러 불필요

    // 전체 순위체크 · 순위체크 AJAX 는 공용 파셜(rank.partials._card-scripts)로 이동

    // 키워드 행 추가/삭제
    const form = document.getElementById('rf-rank-form');
    if (!form) return;
    const kwWrap = document.getElementById('rf-keywords');
    const addBtn = document.getElementById('rf-kw-add');
    const resolveUrl = @json(route('console.shop-rank.resolve'));

    function rowTemplate() {
        const row = document.createElement('div');
        row.className = 'rf-kw-row flex gap-2 mb-2';
        row.innerHTML = '<input name="keywords[]" class="input" style="flex:1;" placeholder="키워드 입력">'
            + '<button type="button" class="btn btn-ghost btn-sm rf-kw-del" title="삭제" style="width:40px;">✕</button>';
        return row;
    }
    addBtn && addBtn.addEventListener('click', function () {
        const row = rowTemplate();
        kwWrap.appendChild(row);
        row.querySelector('input').focus();
    });
    kwWrap.addEventListener('click', function (e) {
        const del = e.target.closest('.rf-kw-del');
        if (!del) return;
        const rows = kwWrap.querySelectorAll('.rf-kw-row');
        if (rows.length <= 1) { del.closest('.rf-kw-row').querySelector('input').value = ''; return; }
        del.closest('.rf-kw-row').remove();
        const first = kwWrap.querySelector('.rf-kw-row input');
        if (first) first.setAttribute('required', 'required');
    });

    // 대상(상품/업체) 파싱 미리보기(디바운스) — 등록·수정 공용
    function attachResolver(el, info) {
        if (!el || !info) return;
        let t = null, last = '';
        function doResolve() {
            const v = (el.value || '').trim();
            if (v === '' || v === last) return;
            last = v;
            info.textContent = '대상 확인 중…';
            info.style.color = 'var(--color-muted)';
            fetch(resolveUrl + '?target=' + encodeURIComponent(v), { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(d => {
                    if (d && d.ok && d.product_id) {
                        info.innerHTML = '✓ <b style="color:var(--color-ink)">상품 ID ' + d.product_id + '</b> <span style="color:var(--color-muted-soft)">· 상품명은 순위체크 후 표시</span>';
                        info.style.color = 'var(--color-primary)';
                    } else if (d && d.ok && d.mall_name) {
                        info.innerHTML = '✓ <b style="color:var(--color-ink)">업체명 ' + d.mall_name + '</b> <span style="color:var(--color-muted-soft)">· mallName 일치로 순위 탐색</span>';
                        info.style.color = 'var(--color-primary)';
                    } else {
                        info.textContent = 'URL에서 상품 ID를 찾지 못했습니다. 업체명으로 검색하려면 그대로 두세요.';
                        info.style.color = 'var(--color-muted-soft)';
                    }
                })
                .catch(() => { info.textContent = ''; });
        }
        el.addEventListener('input', function () { clearTimeout(t); t = setTimeout(doResolve, 600); });
        el.addEventListener('blur', doResolve);
        if (el.value.trim() !== '') doResolve();
    }
    attachResolver(document.getElementById('rf-target'), document.getElementById('rf-target-info'));
    attachResolver(document.getElementById('rf-edit-target'), document.getElementById('rf-edit-target-info'));
})();
</script>
@endsection
