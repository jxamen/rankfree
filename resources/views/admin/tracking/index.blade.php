@extends('admin.layout')
@section('page-title', $title)

@php
    $fmt = fn ($n) => number_format((int) $n);
    $isPlace = $mode === 'place';
@endphp

@push('head')
<style>
    .trk-stats { display:grid; gap:12px; grid-template-columns:repeat(2,1fr); margin-bottom:20px; }
    @media(min-width:680px){ .trk-stats{ grid-template-columns:repeat(4,1fr); } }
    .trk-stats .lab { color:var(--color-muted); font-size:var(--fs-xs); }
    .trk-stats .val { color:var(--color-ink); font-family:var(--font-mono); font-size:var(--fs-lg); font-weight:650; margin-top:4px; }
    /* 날짜별 순위 셀 — 콘솔 카드와 동일. 플레이스는 리뷰·저장 접기 지원 */
    .rf-cell { width:{{ $isPlace ? 104 : 100 }}px; padding:10px 8px 8px; }
    @if ($isPlace)
    .rf-slot.rf-collapsed .rf-metrics { display:none; }
    .rf-slot.rf-collapsed .rf-cell { width:78px; padding:8px 6px; }
    @endif
</style>
@endpush

@section('admin-content')
<x-console.page-head :title="$title" :desc="$desc">
    {{-- 전체 순위체크 — 화면의 슬롯을 순차 확인(콘솔과 동일). 회원별 보기면 그 회원 슬롯만 --}}
    <button type="button" id="rf-run-all" class="btn btn-secondary btn-sm" @disabled($slots->isEmpty())>전체 순위체크</button>
    {{-- 전환 시 회원 필터 유지 — 같은 회원의 플레이스·쇼핑 추적을 오가며 볼 수 있게 --}}
    <a href="{{ route('admin.'.($isPlace ? 'shop-tracking' : 'place-tracking'), array_filter(['user' => $userId ?: null])) }}" class="btn btn-secondary btn-sm">{{ $isPlace ? '쇼핑 추적 보기' : '플레이스 추적 보기' }}</a>
    {{-- 운영자 등록 — 회원 필터 상태에서만(대상 회원 = 그 회원). 필터 없으면 회원 뱃지로 진입 후 등록 --}}
    @if ($filterUser ?? null)
        <button type="button" id="rf-open-modal" class="btn btn-primary btn-sm">＋ 추적 추가</button>
    @endif
</x-console.page-head>

{{-- 회원 필터 배너 — 아이디 클릭으로 진입한 업체별 추적 리스트 --}}
@if ($filterUser ?? null)
    <div class="card-soft px-4 py-3 mb-4 flex items-center gap-2" style="font-size:var(--fs-xs);">
        <span class="text-muted">업체:</span>
        <b class="text-ink">{{ $filterUser->name }}</b>
        <span class="text-muted-soft">{{ $filterUser->email }}</span>
        <span class="text-muted">· 키워드 <b class="text-ink">{{ $slots->count() }}</b>개</span>
        <a href="{{ route($routeName) }}" class="btn btn-ghost btn-sm" style="margin-left:auto;">← 전체 목록</a>
    </div>
@endif

@unless ($filterUser ?? null)
{{-- 통계 --}}
<div class="trk-stats">
    @foreach ([
        ['전체 슬롯', $fmt($stats['total']).'개'],
        ['활성', $fmt($stats['active']).'개'],
        ['등록 회원', $fmt($stats['users']).'명'],
        ['최근 7일 확인', $fmt($stats['checked7']).'개'],
    ] as [$lab, $val])
        <div class="card p-4">
            <div class="lab">{{ $lab }}</div>
            <div class="val">{{ $val }}</div>
        </div>
    @endforeach
</div>

{{-- 검색·필터 --}}
<form method="GET" class="card p-3 mb-4">
    <div class="flex items-center flex-wrap gap-2">
        @if (($userId ?? 0) > 0)<input type="hidden" name="user" value="{{ $userId }}">@endif
        {{-- 상태는 아래 탭으로 고른다 — 검색해도 보고 있던 탭에 머문다 --}}
        @if ($active !== '')<input type="hidden" name="active" value="{{ $active }}">@endif
        @if ($q !== '' || $active !== '')
            <a href="{{ route($routeName) }}" class="btn btn-ghost btn-sm">초기화</a>
        @endif
        <input name="q" value="{{ $q }}" class="input" style="width:300px;font-size:var(--fs-xs);margin-left:auto;"
               placeholder="키워드 · {{ $isPlace ? '플레이스명' : '상품명·몰' }} · 회원(이름/이메일)">
        <button type="submit" class="btn btn-primary btn-sm">검색</button>
    </div>
</form>
@endunless

{{-- 상태 탭 — 자동 중단된 슬롯을 따로 모아 본다. 회원 필터 화면에서도 쓰므로 @unless 밖에 둔다 --}}
<x-rank.tabs :current="$active" :tabs="[
    ['key' => '', 'label' => '전체', 'count' => $tabCounts[''], 'url' => request()->fullUrlWithQuery(['active' => null, 'page' => null])],
    ['key' => '1', 'label' => '추적 중', 'count' => $tabCounts['1'], 'url' => request()->fullUrlWithQuery(['active' => '1', 'page' => null])],
    ['key' => '0', 'label' => '체크 중단됨', 'count' => $tabCounts['0'], 'url' => request()->fullUrlWithQuery(['active' => '0', 'page' => null])],
]" />

{{-- 슬롯 목록 — 콘솔과 동일한 카드(공용 컴포넌트 x-rank.slot-card). 회원 뱃지로 어느 회원인지 표시.
     열람 어드민이라 수정/삭제/추가는 없고, 중단/재개·순위체크·공유·이미지만. --}}
@forelse ($slots as $s)
    <x-rank.slot-card :rank-slot="$s" :mode="$mode" area="admin" :show-member="true" :from="null" :to="null" />
@empty
    <div class="card text-center text-muted-soft" style="padding:56px 20px;font-size:var(--fs-xs);">
        @if ($active === '0')
            자동 중단된 슬롯이 없습니다.
        @else
            {{ ($filterUser ?? null) ? '이 회원의 추적 슬롯이 없습니다.' : (($q !== '' || $active !== '') ? '조건에 맞는 슬롯이 없습니다.' : '등록된 순위추적 슬롯이 없습니다.') }}
        @endif
    </div>
@endforelse

@unless ($filterUser ?? null)
    <div class="mt-4">{{ $slots->links() }}</div>
@endunless

@include('console.partials._image-save')
@include('rank.partials._card-scripts')

{{-- 순위추적 등록·수정(2026-07-27, 운영자) — 회원 필터 상태에서 그 회원에게 등록. 수정은 전 회원 슬롯(소유권 무시). --}}
@php
    $trkStore = $isPlace ? 'admin.place-tracking.store' : 'admin.shop-tracking.store';
    $trkUpdateTpl = route($isPlace ? 'admin.place-tracking.update' : 'admin.shop-tracking.update', ['slot' => '__ID__']);
    $trkResolve = route($isPlace ? 'admin.place-tracking.resolve' : 'admin.shop-tracking.resolve');
@endphp

@if ($filterUser ?? null)
{{-- 추적 추가 모달(대상 회원 = 필터 회원) --}}
<div id="rf-modal" class="hidden" style="position:fixed;inset:0;z-index:50;">
    <div id="rf-modal-bg" style="position:absolute;inset:0;background:color-mix(in srgb, var(--color-ink) 40%, transparent);"></div>
    <div class="card" style="position:relative;max-width:640px;margin:7vh auto 0;max-height:84vh;overflow-y:auto;box-shadow:var(--shadow-card);">
        <div class="flex items-center justify-between px-5 border-b border-hairline-soft" style="height:52px;">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $isPlace ? '플레이스' : '쇼핑' }} 순위추적 추가 · {{ $filterUser->name }}</span>
            <button type="button" id="rf-modal-close" class="btn btn-ghost btn-sm" title="닫기">✕</button>
        </div>
        <form method="POST" action="{{ route($trkStore) }}" class="p-5" id="rf-rank-form">
            @csrf
            <input type="hidden" name="user_id" value="{{ $filterUser->id }}">
            <div class="flex gap-3 flex-wrap items-start mb-4">
                <div style="flex:2;min-width:280px;">
                    @if ($isPlace)
                        <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">플레이스 URL 또는 ID</label>
                        <input name="place" id="rf-place" class="input" value="{{ old('place') }}" placeholder="https://map.naver.com/... · m.place URL · 플레이스 ID" required maxlength="1000" autocomplete="off">
                        <div id="rf-place-info" class="mt-1" style="font-size:var(--fs-xs);min-height:16px;"></div>
                    @else
                        <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">상품 URL(스마트스토어/가격비교) 또는 업체명</label>
                        <input name="target" id="rf-target" class="input" value="{{ old('target') }}" placeholder="https://smartstore.naver.com/.../products/123... · 또는 업체명" required maxlength="500" autocomplete="off">
                        <div id="rf-target-info" class="mt-1" style="font-size:var(--fs-xs);min-height:16px;"></div>
                    @endif
                </div>
                <div style="width:150px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">라벨 <span class="text-muted-soft">(선택)</span></label>
                    <input name="label" class="input" value="{{ old('label') }}" placeholder="{{ $isPlace ? '예: 본점' : '예: 신상' }}">
                </div>
            </div>

            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">추적 키워드 <span class="text-muted-soft">(여러 개 추가 가능)</span></label>
            <div id="rf-keywords">
                @php $olds = array_values(array_filter((array) old('keywords', ['']), fn ($v) => $v !== null)); @endphp
                @forelse ($olds as $kw)
                    <div class="rf-kw-row flex gap-2 mb-2">
                        <input name="keywords[]" class="input" style="flex:1;" value="{{ $kw }}" placeholder="{{ $isPlace ? '강남 미용실' : '예: 강아지 사료' }}" @if($loop->first) required @endif>
                        <button type="button" class="btn btn-ghost btn-sm rf-kw-del" title="삭제" style="width:40px;">✕</button>
                    </div>
                @empty
                    <div class="rf-kw-row flex gap-2 mb-2">
                        <input name="keywords[]" class="input" style="flex:1;" placeholder="{{ $isPlace ? '강남 미용실' : '예: 강아지 사료' }}" required>
                        <button type="button" class="btn btn-ghost btn-sm rf-kw-del" title="삭제" style="width:40px;">✕</button>
                    </div>
                @endforelse
            </div>

            <div class="flex items-center justify-between mt-3 flex-wrap gap-2">
                <button type="button" id="rf-kw-add" class="btn btn-secondary btn-sm">＋ 키워드 추가</button>
                <button type="submit" class="btn btn-primary">추적 추가 (한도 무시)</button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- 수정 모달(전 회원 슬롯 · 운영자) — slot-card 의 rf-edit-btn 이 열어준다 --}}
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
                <input name="keyword" id="rf-edit-keyword" class="input" value="" required maxlength="{{ $isPlace ? 100 : 120 }}">
            </div>
            <div class="mb-3">
                @if ($isPlace)
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">플레이스 URL 또는 ID</label>
                    <input name="place" id="rf-edit-place" class="input" value="" required maxlength="1000" autocomplete="off" placeholder="https://m.place.naver.com/... · 플레이스 ID">
                    <div id="rf-edit-place-info" class="mt-1" style="font-size:var(--fs-xs);min-height:16px;"></div>
                @else
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">상품 URL 또는 업체명</label>
                    <input name="target" id="rf-edit-target" class="input" value="" required maxlength="500" autocomplete="off" placeholder="상품 URL · 또는 업체명">
                    <div id="rf-edit-target-info" class="mt-1" style="font-size:var(--fs-xs);min-height:16px;"></div>
                @endif
            </div>
            <div class="mb-4">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">라벨 <span class="text-muted-soft">(선택)</span></label>
                <input name="label" id="rf-edit-label" class="input" value="" maxlength="100" placeholder="{{ $isPlace ? '예: 본점' : '예: 신상' }}">
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
    const isPlace = @json($isPlace);
    const resolveUrl = @json($trkResolve);
    const resolveParam = isPlace ? 'place' : 'target';
    const editUrlTpl = @json($trkUpdateTpl);

    // ── 추가 모달(회원 필터 시에만 DOM 존재) ──
    const modal = document.getElementById('rf-modal');
    if (modal) {
        const openBtn = document.getElementById('rf-open-modal');
        const closeBtn = document.getElementById('rf-modal-close');
        const bg = document.getElementById('rf-modal-bg');
        const openModal = function () {
            modal.classList.remove('hidden');
            const first = modal.querySelector('input[name="' + resolveParam + '"]');
            if (first) setTimeout(() => first.focus(), 50);
        };
        const closeModal = function () { modal.classList.add('hidden'); };
        openBtn && openBtn.addEventListener('click', openModal);
        closeBtn && closeBtn.addEventListener('click', closeModal);
        bg && bg.addEventListener('click', closeModal);
        window.__rfOpenAdd = openModal;
        window.__rfCloseAdd = closeModal;

        // 키워드 행 추가/삭제
        const kwWrap = document.getElementById('rf-keywords');
        const addBtn = document.getElementById('rf-kw-add');
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
        kwWrap && kwWrap.addEventListener('click', function (e) {
            const del = e.target.closest('.rf-kw-del');
            if (!del) return;
            const rows = kwWrap.querySelectorAll('.rf-kw-row');
            if (rows.length <= 1) { del.closest('.rf-kw-row').querySelector('input').value = ''; return; }
            del.closest('.rf-kw-row').remove();
            const first = kwWrap.querySelector('.rf-kw-row input');
            if (first) first.setAttribute('required', 'required');
        });
    }

    // ── 수정 모달(항상 존재) ──
    const editModal = document.getElementById('rf-edit-modal');
    const editForm = document.getElementById('rf-edit-form');
    function editTargetEl() { return document.getElementById(isPlace ? 'rf-edit-place' : 'rf-edit-target'); }
    function editInfoEl() { return document.getElementById(isPlace ? 'rf-edit-place-info' : 'rf-edit-target-info'); }
    function openEdit(action, slotId, keyword, target, label) {
        editForm.action = action;
        document.getElementById('rf-edit-slot-id').value = slotId || '';
        document.getElementById('rf-edit-keyword').value = keyword || '';
        editTargetEl().value = target || '';
        editInfoEl().textContent = '';
        document.getElementById('rf-edit-label').value = label || '';
        editModal.classList.remove('hidden');
        setTimeout(() => document.getElementById('rf-edit-keyword').focus(), 50);
    }
    function closeEdit() { editModal.classList.add('hidden'); }
    document.querySelectorAll('.rf-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const tgt = isPlace ? btn.dataset.place : btn.dataset.target;
            openEdit(btn.dataset.action, btn.dataset.slotId, btn.dataset.keyword, tgt, btn.dataset.label);
        });
    });
    editModal.querySelectorAll('.rf-edit-close').forEach(function (el) { el.addEventListener('click', closeEdit); });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (!editModal.classList.contains('hidden')) closeEdit();
        else if (modal && !modal.classList.contains('hidden') && window.__rfCloseAdd) window.__rfCloseAdd();
    });

    // 검증 실패 시 해당 모달 재오픈
    @if (old('edit_slot_id'))
        openEdit(editUrlTpl.replace('__ID__', @json(old('edit_slot_id'))), @json(old('edit_slot_id')), @json(old('keyword')), @json(old($isPlace ? 'place' : 'target')), @json(old('label')));
    @elseif (($filterUser ?? null) && ($errors->any() || old($isPlace ? 'place' : 'target')))
        window.__rfOpenAdd && window.__rfOpenAdd();
    @endif

    // ── 대상 자동조회(디바운스) — 등록·수정 공용 ──
    function attachResolver(el, info) {
        if (!el || !info) return;
        let t = null, last = '';
        function doResolve() {
            const v = (el.value || '').trim();
            if (v === '' || v === last) return;
            last = v;
            info.textContent = isPlace ? '업체명 조회 중…' : '대상 확인 중…';
            info.style.color = 'var(--color-muted)';
            fetch(resolveUrl + '?' + resolveParam + '=' + encodeURIComponent(v), { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(d => {
                    if (isPlace) {
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
                    } else {
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
                    }
                })
                .catch(() => { info.textContent = ''; });
        }
        el.addEventListener('input', function () { clearTimeout(t); t = setTimeout(doResolve, 600); });
        el.addEventListener('blur', doResolve);
        if (el.value.trim() !== '') doResolve();
    }
    attachResolver(document.getElementById(isPlace ? 'rf-place' : 'rf-target'), document.getElementById(isPlace ? 'rf-place-info' : 'rf-target-info'));
    attachResolver(editTargetEl(), editInfoEl());
})();
</script>

@unless ($isPlace)
{{-- 제목 수집(쇼핑) — 미노출 상품은 순위체크로 제목이 안 붙으므로, 확장(admin-bridge)이 상품페이지에서 긁어와 저장.
     서버는 네이버 상품페이지를 직접 못 가져옴(429). 스마트스토어/브랜드 상품만. --}}
<script>
(function () {
    var csrf = '{{ csrf_token() }}';
    // 확장 브릿지 연결 대기(admin-bridge 가 data-rf-ext 를 심는다 — document_idle 레이스 방지)
    function waitExt(ms) {
        return new Promise(function (res) {
            var t0 = Date.now();
            (function chk() {
                if (document.documentElement.getAttribute('data-rf-ext') === '1') return res(true);
                if (Date.now() - t0 > ms) return res(false);
                setTimeout(chk, 150);
            })();
        });
    }
    // 확장 호출 왕복(rankfree-admin) — 타임아웃 시 null
    function extCall(type, payload, timeoutMs) {
        return new Promise(function (resolve) {
            var timer = setTimeout(function () { window.removeEventListener('message', on); resolve(null); }, timeoutMs || 45000);
            function on(e) {
                if (e.source !== window) return;
                var m = e.data;
                if (!m || m.source !== 'rankfree-ext' || m.type !== type + 'Result') return;
                clearTimeout(timer); window.removeEventListener('message', on); resolve(m);
            }
            window.addEventListener('message', on);
            window.postMessage(Object.assign({ source: 'rankfree-admin', type: type }, payload || {}), '*');
        });
    }
    document.querySelectorAll('.rf-title-collect').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var url = btn.dataset.url, endpoint = btn.dataset.endpoint;
            if (!url) { alert('상품 URL이 없어 수집할 수 없습니다.'); return; }
            var orig = btn.textContent;
            btn.disabled = true; btn.textContent = '수집 중…';
            var ok = await waitExt(1500);
            if (!ok) {
                btn.disabled = false; btn.textContent = orig;
                alert('랭크프리 확장이 이 페이지에 연결되지 않았습니다 — 확장 설치·로그인 후 페이지를 새로고침하세요.');
                return;
            }
            var pi = await extCall('collectProductPage', { url: url }, 45000);
            if (pi && pi.ok && pi.info && pi.info.title) {
                try {
                    var r = await fetch(endpoint, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                        body: JSON.stringify({ info: pi.info }),
                    });
                    var d = await r.json();
                    if (d.ok) {
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: d.message || '제목 수집 완료', showConfirmButton: false, timer: 1600 })
                            .then(function () { location.reload(); });
                        return;
                    }
                    btn.disabled = false; btn.textContent = orig;
                    alert((d && d.message) || '저장에 실패했습니다.');
                } catch (e) {
                    btn.disabled = false; btn.textContent = orig;
                    alert('저장 중 오류가 발생했습니다.');
                }
            } else {
                btn.disabled = false; btn.textContent = orig;
                // 확장이 원인을 알려주면 그대로(네이버 로그인 게이트 등 — 열린 탭에서 확인 후 재시도)
                alert((pi && pi.message) || '수집에 실패했습니다 — 상품페이지가 열리지 않았을 수 있어요. 잠시 후 다시 시도하세요.');
            }
        });
    });
})();
</script>
@endunless
@endsection
