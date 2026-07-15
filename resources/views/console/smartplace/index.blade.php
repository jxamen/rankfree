@extends('console.layout')
@section('page-title', '스마트플레이스 리포트')

@section('console-content')
{{-- 메뉴명 + 설명 · 안내 --}}
<x-console.page-head title="스마트플레이스 리포트">
    <x-slot:desc>스마트플레이스 <b>통계·리뷰·스마트콜·예약</b>을 한 번에 수집합니다 · 등록 계정 <b>{{ $accounts->count() }}</b>개</x-slot:desc>
    <button type="button" id="sp-guide-toggle" class="btn btn-ghost btn-sm">등록 방법</button>
</x-console.page-head>

{{-- 안내창 — 아이디/비밀번호 자동 로그인 가이드 (토글) --}}
<div id="sp-guide" class="card-soft mb-5 hidden" style="padding:18px 20px;">
    <div class="text-ink font-semibold mb-2" style="font-size:var(--fs-xs);">사장님(광고주) 계정 등록 방법</div>
    <ol class="text-body" style="font-size:var(--fs-xs);line-height:1.9;padding-left:18px;list-style:decimal;">
        <li>우측 상단 <b class="text-ink">＋ 계정 등록</b>에서 <b class="text-ink">광고주 네이버 아이디·비밀번호</b>를 입력하고 <b class="text-ink">[매장 불러오기]</b>를 누릅니다.</li>
        <li>로그인 후 등록된 매장을 자동으로 불러옵니다 — 매장이 <b class="text-ink">1개면 자동 선택</b>, 여러 개면 목록에서 선택하면 <b class="text-ink">업체명·URL이 자동 입력</b>됩니다.</li>
        <li>등록 후 <b class="text-ink">[수집]</b>을 누르면 저장된 세션으로 리포트를 가져옵니다. 세션이 만료되면 자동으로 다시 로그인합니다.</li>
    </ol>
    <p class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">
        비밀번호는 암호화되어 저장되며 자동 로그인에만 사용됩니다. 해당 네이버 계정에 <b>2차 인증(OTP)·새 기기 알림</b>이 켜져 있으면 자동 로그인이 캡차/인증에 막힐 수 있습니다.
    </p>
</div>

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

{{-- 수집기간 컨트롤 — [수집] 실행 시 적용 (비우면 최근 7일) --}}
<div class="card mb-4 px-5 py-3 flex items-center gap-2 flex-wrap" style="font-size:var(--fs-xs);">
    <span class="text-ink font-semibold">수집기간</span>
    <button type="button" class="btn btn-secondary btn-sm" data-period="day">오늘</button>
    <button type="button" class="btn btn-secondary btn-sm" data-period="week">최근 7일</button>
    <select id="sp-msel" class="input" style="width:auto;height:32px;font-size:var(--fs-xs);padding:0 8px;"></select>
    <input type="date" id="sp-sd" class="input" style="width:auto;height:32px;font-size:var(--fs-xs);padding:0 8px;">
    <span class="text-muted-soft">~</span>
    <input type="date" id="sp-ed" class="input" style="width:auto;height:32px;font-size:var(--fs-xs);padding:0 8px;">
    <span class="text-muted-soft" style="font-size:var(--fs-xs);">비워두면 최근 7일 기준으로 수집합니다.</span>
    <button type="button" id="sp-open-modal" class="btn btn-primary btn-sm" style="height:36px;margin-left:auto;">＋ 계정 등록</button>
</div>

{{-- 업체명 검색(우) --}}
<form method="GET" class="card p-3 mb-4">
    <div class="flex items-center gap-2">
        <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
            @if ($q)<a href="{{ route('console.smartplace') }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>@endif
            <input name="q" value="{{ $q }}" class="input" style="width:260px;font-size:var(--fs-xs);" placeholder="업체명 검색">
            <button type="submit" class="btn btn-primary btn-sm" style="height:36px;">검색</button>
        </div>
    </div>
</form>

{{-- 계정 목록 — 가로 전체 --}}
@if ($accounts->count())
    <div class="card overflow-x-auto">
        <table class="w-full" style="min-width:960px;border-collapse:collapse;white-space:nowrap;font-size:var(--fs-xs);">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-center px-3 py-3 font-semibold" style="width:44px;">No</th>
                    <th class="text-left px-4 py-3 font-semibold">업체명</th>
                    <th class="text-left px-3 py-3 font-semibold">placeSeq</th>
                    <th class="text-left px-3 py-3 font-semibold">수집매장 ID</th>
                    <th class="text-center px-3 py-3 font-semibold">로그인 세션</th>
                    <th class="text-center px-3 py-3 font-semibold">최근 수집</th>
                    <th class="text-center px-3 py-3 font-semibold">상태</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:200px;">수집 · 리포트</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:120px;">관리</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($accounts as $a)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-3 py-3 text-center text-muted-soft">{{ $loop->iteration }}</td>
                        <td class="px-4 py-3">
                            <div class="text-ink font-semibold">{{ $a->label }}</div>
                            @if ($a->sp_name && $a->sp_name !== $a->label)
                                <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $a->sp_name }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-muted">{{ $a->place_seq }}</td>
                        <td class="px-3 py-3 text-muted-soft" style="font-size:var(--fs-xs);">{{ $a->place_id ? 'place '.$a->place_id.' / biz '.($a->business_id ?: '-') : '-' }}</td>
                        <td class="px-3 py-3 text-center">
                            @if ($a->logged_in_at)
                                <span class="badge" style="background:color-mix(in srgb,var(--color-success) 12%,var(--color-canvas));color:var(--color-success);" title="{{ $a->logged_in_at->format('Y-m-d H:i') }} 로그인">유지 중</span>
                            @elseif (trim((string) $a->naver_id) !== '')
                                <span class="badge" style="background:var(--color-surface-strong);color:var(--color-muted);" title="[수집] 시 자동 로그인">대기</span>
                            @else
                                <span class="badge" style="background:color-mix(in srgb,var(--color-error) 10%,var(--color-canvas));color:var(--color-error);">계정 없음</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center text-muted">{{ $a->last_collected_at?->format('Y-m-d H:i') ?? '-' }}</td>
                        <td class="px-3 py-3 text-center">
                            @if ($a->last_status === 'OK')
                                <span class="badge" style="background:color-mix(in srgb,var(--color-success) 12%,var(--color-canvas));color:var(--color-success);">OK</span>
                            @elseif ($a->last_status !== '')
                                <span class="badge" style="background:color-mix(in srgb,var(--color-error) 10%,var(--color-canvas));color:var(--color-error);">{{ $a->last_status }}</span>
                            @else
                                <span class="text-muted-soft">-</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center">
                            <button type="button" class="btn btn-primary btn-sm sp-collect-btn" data-url="{{ route('console.smartplace.collect', $a) }}" data-label="{{ $a->label }}">수집</button>
                            @if ($a->last_result)
                                <a href="{{ route('console.smartplace.report', $a) }}" class="btn btn-secondary btn-sm">리포트</a>
                            @else
                                <span class="btn btn-secondary btn-sm" style="opacity:.45;cursor:not-allowed;" title="먼저 [수집]을 실행하세요">리포트</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center">
                            <button type="button" class="btn btn-ghost btn-sm sp-edit-btn"
                                    data-action="{{ route('console.smartplace.update', $a) }}"
                                    data-id="{{ $a->id }}"
                                    data-label="{{ $a->label }}"
                                    data-place="{{ $a->place_url }}"
                                    data-place-seq="{{ $a->place_seq }}"
                                    data-business-id="{{ $a->business_id }}"
                                    data-category="{{ $a->category }}"
                                    data-naver-id="{{ $a->naver_id }}"
                                    data-naver-pw="{{ $a->naver_pw }}">수정</button>
                            <form method="POST" action="{{ route('console.smartplace.destroy', $a) }}" class="inline" data-confirm="이 업체를 삭제할까요?" data-confirm-text="수집된 리포트도 함께 삭제됩니다.">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <div class="card text-center" style="padding:56px 20px;color:var(--color-muted);">
        <div style="font-size:var(--fs-2xl);opacity:.4;">🏪</div>
        <p class="mt-2" style="font-size:var(--fs-xs);">등록된 스마트플레이스 계정이 없습니다. 우측 상단 "＋ 계정 등록"으로 광고주 네이버 아이디·비밀번호를 등록하세요.</p>
    </div>
@endif

<p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">
    수집 항목: 통계 6종(일자별·연령성별·시간대·요일·유입채널·유입검색어) · 방문자/블로그 리뷰 · 스마트콜 · 예약 고객.
    수집에는 수십 초가 걸릴 수 있습니다. 쿠키·수집 결과는 암호화 저장됩니다.
</p>

{{-- 계정 등록/수정 모달 --}}
<div id="sp-modal" class="hidden" style="position:fixed;inset:0;z-index:50;">
    <div id="sp-modal-bg" style="position:absolute;inset:0;background:color-mix(in srgb, var(--color-ink) 40%, transparent);"></div>
    <div class="card" style="position:relative;max-width:640px;margin:7vh auto 0;max-height:84vh;overflow-y:auto;box-shadow:var(--shadow-card);">
        <div class="flex items-center justify-between px-5 border-b border-hairline-soft" style="height:52px;">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);" id="sp-modal-title">스마트플레이스 계정 등록</span>
            <button type="button" id="sp-modal-close" class="btn btn-ghost btn-sm" title="닫기">✕</button>
        </div>
        <form method="POST" action="{{ route('console.smartplace.store') }}" class="p-5" id="sp-form" autocomplete="off">
            @csrf
            <input type="hidden" name="_method" id="sp-method" value="PUT" disabled>
            <input type="hidden" name="edit_account_id" id="sp-edit-id" value="">
            <input type="hidden" name="place_seq" id="sp-place-seq" value="{{ old('place_seq') }}">
            <input type="hidden" name="business_id" id="sp-business-id" value="{{ old('business_id') }}">
            {{-- 크롬 비밀번호 저장/자동완성 팝업 억제용 더미(화면 밖) --}}
            <input type="text" style="display:none" autocomplete="username" tabindex="-1" aria-hidden="true">

            {{-- 1단계: 광고주 네이버 계정 --}}
            <div class="text-muted-soft mb-2" style="font-size:var(--fs-xs);font-weight:700;">① 광고주 네이버 계정</div>
            <div class="flex gap-3 flex-wrap items-end">
                <div style="flex:1;min-width:180px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">네이버 아이디</label>
                    <input name="naver_id" id="sp-naver-id" class="input" value="{{ old('naver_id') }}" placeholder="광고주 네이버 아이디" required maxlength="100" autocomplete="off" data-lpignore="true" data-1p-ignore data-form-type="other">
                </div>
                <div style="flex:1;min-width:180px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);" id="sp-pw-label">네이버 비밀번호</label>
                    <div class="flex gap-2">
                        <input type="password" name="naver_pw" id="sp-naver-pw" class="input" style="flex:1;" placeholder="비밀번호" maxlength="200" autocomplete="off" data-lpignore="true" data-1p-ignore data-form-type="other">
                        <button type="button" class="btn btn-secondary btn-sm" id="sp-pw-toggle" tabindex="-1" title="보기">보기</button>
                    </div>
                </div>
                <button type="button" class="btn btn-secondary" id="sp-discover-btn">매장 불러오기</button>
            </div>
            <p class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">아이디·비밀번호로 로그인해 등록된 매장을 자동으로 불러옵니다. 비밀번호는 암호화 저장 · 실제 네이버 로그인에만 사용됩니다.</p>

            {{-- 매장 선택 영역 (매장 불러오기 후 표시) --}}
            <div id="sp-biz-picker" class="hidden mt-3 mb-1 p-3 rounded-lg" style="background:var(--color-surface-soft);border:1px solid var(--color-hairline-soft);">
                <div class="text-muted mb-2" style="font-size:var(--fs-xs);font-weight:700;">스마트플레이스 매장 선택</div>
                <div id="sp-biz-list" class="flex flex-col gap-1.5"></div>
                <div id="sp-biz-msg" class="text-muted-soft" style="font-size:var(--fs-xs);"></div>
            </div>

            {{-- 2단계: 매장 정보 --}}
            <div class="text-muted-soft mt-4 mb-2" style="font-size:var(--fs-xs);font-weight:700;">② 매장 정보</div>
            <div class="mb-3">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">플레이스 URL <span class="text-muted-soft">(PC 지도 URL 붙여넣기 → m.place 자동 변환)</span></label>
                <input name="place" id="sp-place" class="input" value="{{ old('place') }}" placeholder="https://map.naver.com/p/entry/place/1137930547 · m.place URL · 플레이스 ID" maxlength="500" autocomplete="off" data-lpignore="true">
                <div id="sp-place-info" class="mt-1" style="font-size:var(--fs-xs);min-height:16px;"></div>
            </div>
            <div class="flex gap-3 flex-wrap items-start">
                <div style="flex:1;min-width:220px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">업체명 <span class="text-muted-soft">(자동 · 수정 가능)</span></label>
                    <input name="label" id="sp-label" class="input" value="{{ old('label') }}" placeholder="매장 선택·플레이스 조회 시 자동" required maxlength="100" autocomplete="off">
                </div>
                <div style="width:120px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">업종 <span class="text-muted-soft">(선택)</span></label>
                    <select name="category" id="sp-category" class="input">
                        <option value="">자동 판별</option>
                        @foreach (['미용실', '네일샵', '병원', '레스토랑', '숙박업소', '일반'] as $cat)
                            <option value="{{ $cat }}" @selected(old('category') === $cat)>{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-end mt-4 gap-2">
                <button type="button" class="btn btn-secondary btn-sm" id="sp-modal-cancel">취소</button>
                <button type="submit" class="btn btn-primary" id="sp-submit">등록</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // ---- 안내창 토글 -----------------------------------------------------
    const guide = document.getElementById('sp-guide');
    document.getElementById('sp-guide-toggle').addEventListener('click', function () {
        guide.classList.toggle('hidden');
    });

    // ---- 수집기간 컨트롤 ---------------------------------------------------
    const sd = document.getElementById('sp-sd');
    const ed = document.getElementById('sp-ed');
    const msel = document.getElementById('sp-msel');
    (function () {
        const d = new Date();
        let h = '<option value="">월 선택</option>';
        for (let i = 0; i < 18; i++) {
            const y = d.getFullYear(), m = d.getMonth() + 1;
            h += '<option value="' + y + '-' + String(m).padStart(2, '0') + '">' + y + '년 ' + m + '월</option>';
            d.setMonth(d.getMonth() - 1);
        }
        msel.innerHTML = h;
    })();
    function fmt(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    document.querySelectorAll('[data-period]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const e = new Date();
            const s = btn.dataset.period === 'week' ? new Date(Date.now() - 6 * 864e5) : e;
            sd.value = fmt(s); ed.value = fmt(e);
        });
    });
    msel.addEventListener('change', function () {
        if (!msel.value) return;
        const [y, m] = msel.value.split('-').map(Number);
        sd.value = msel.value + '-01';
        ed.value = msel.value + '-' + String(new Date(y, m, 0).getDate()).padStart(2, '0');
    });

    // ---- 수집 실행 (AJAX + Swal — 순위추적과 동일 패턴) ---------------------
    document.querySelectorAll('.sp-collect-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            Swal.fire({
                title: '리포트 수집 중…',
                html: '<span style="font-size:var(--fs-xs);color:var(--color-muted);">‘' + btn.dataset.label + '’ 의 통계·리뷰·스마트콜·예약 데이터를 수집하고 있습니다. 수십 초 걸릴 수 있습니다.</span>',
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: function () { Swal.showLoading(); }
            });
            const fd = new FormData();
            fd.append('_token', @json(csrf_token()));
            if (sd.value && ed.value) { fd.append('start', sd.value); fd.append('end', ed.value); }
            fetch(btn.dataset.url, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (res) {
                    if (res.ok && res.d.ok) {
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.d.message, showConfirmButton: false, timer: 1800, timerProgressBar: true })
                            .then(function () { location.reload(); });
                    } else {
                        Swal.fire({ icon: 'warning', title: '수집 실패', text: (res.d && (res.d.message || (res.d.errors && Object.values(res.d.errors)[0][0]))) || '잠시 후 다시 시도하세요.' });
                    }
                })
                .catch(function () { Swal.fire({ icon: 'error', title: '수집에 실패했습니다', text: '잠시 후 다시 시도하세요.' }); });
        });
    });

    // ---- 등록/수정 모달 ----------------------------------------------------
    const modal = document.getElementById('sp-modal');
    const form = document.getElementById('sp-form');
    const method = document.getElementById('sp-method');
    const title = document.getElementById('sp-modal-title');
    const submit = document.getElementById('sp-submit');
    const pwEl = document.getElementById('sp-naver-pw');
    const pwLabel = document.getElementById('sp-pw-label');
    const storeUrl = @json(route('console.smartplace.store'));

    function openCreate() {
        form.action = storeUrl;
        method.disabled = true;
        document.getElementById('sp-edit-id').value = '';
        title.textContent = '스마트플레이스 계정 등록';
        submit.textContent = '등록';
        ['sp-place-seq', 'sp-business-id', 'sp-place', 'sp-label', 'sp-naver-id'].forEach(function (id) { document.getElementById(id).value = ''; });
        document.getElementById('sp-place-info').textContent = '';
        document.getElementById('sp-category').value = '';
        pwEl.value = '';
        pwEl.required = true;
        pwLabel.textContent = '네이버 비밀번호';
        resetPicker();
        modal.classList.remove('hidden');
        setTimeout(function () { document.getElementById('sp-naver-id').focus(); }, 50);
    }
    function resetPicker() {
        picker.classList.add('hidden');
        bizList.innerHTML = '';
        bizMsg.textContent = '';
    }
    function openEdit(btn) {
        const d = btn.dataset;
        form.action = d.action;
        method.disabled = false;
        document.getElementById('sp-edit-id').value = d.id || '';
        title.textContent = '계정 수정 — ' + (d.label || '');
        submit.textContent = '수정 저장';
        document.getElementById('sp-label').value = d.label || '';
        document.getElementById('sp-place').value = d.place || '';
        document.getElementById('sp-place-seq').value = d.placeSeq || '';
        document.getElementById('sp-business-id').value = d.businessId || '';
        document.getElementById('sp-category').value = d.category || '';
        document.getElementById('sp-naver-id').value = d.naverId || '';
        document.getElementById('sp-place-info').textContent = '';
        pwEl.value = d.naverPw || '';         // 저장된 비밀번호를 복호화해 채워 표시(보기 가능)
        pwEl.required = false;
        pwLabel.textContent = '네이버 비밀번호 (수정 가능 · 비우면 기존 유지)';
        resetPicker();
        if (d.placeSeq) {
            picker.classList.remove('hidden');
            bizMsg.textContent = '현재 매장: placeSeq ' + d.placeSeq + ' · 변경하려면 [매장 불러오기]를 누르세요.';
        }
        modal.classList.remove('hidden');
        setTimeout(function () { document.getElementById('sp-label').focus(); }, 50);
    }
    function closeModal() { modal.classList.add('hidden'); }

    // 비밀번호 보기/숨김
    document.getElementById('sp-pw-toggle').addEventListener('click', function () {
        if (pwEl.type === 'password') { pwEl.type = 'text'; this.textContent = '숨김'; }
        else { pwEl.type = 'password'; this.textContent = '보기'; }
    });

    // ---- 매장 불러오기 (로그인 → 매장 목록 → placeSeq·업체명 자동) -----------
    const discoverBtn = document.getElementById('sp-discover-btn');
    const picker = document.getElementById('sp-biz-picker');
    const bizList = document.getElementById('sp-biz-list');
    const bizMsg = document.getElementById('sp-biz-msg');
    const discoverUrl = @json(route('console.smartplace.discover'));

    function applyBiz(b) {
        document.getElementById('sp-place-seq').value = b.placeSeq || '';
        document.getElementById('sp-business-id').value = b.businessId || '';
        const labelEl = document.getElementById('sp-label');
        if (b.name && !labelEl.value.trim()) labelEl.value = b.name; // 업체명 비어있을 때만 자동
    }
    function renderBusinesses(list) {
        bizList.innerHTML = '';
        list.forEach(function (b) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-secondary btn-sm sp-biz-opt';
            btn.style.cssText = 'justify-content:flex-start;text-align:left;';
            btn.textContent = (b.name || '(이름 없음)') + '  ·  placeSeq ' + b.placeSeq;
            btn.addEventListener('click', function () {
                bizList.querySelectorAll('.sp-biz-opt').forEach(function (x) { x.classList.remove('btn-primary'); x.classList.add('btn-secondary'); });
                btn.classList.remove('btn-secondary'); btn.classList.add('btn-primary');
                applyBiz(b);
            });
            bizList.appendChild(btn);
        });
    }
    discoverBtn.addEventListener('click', function () {
        const id = document.getElementById('sp-naver-id').value.trim();
        const pw = pwEl.value;
        if (!id || !pw) { Swal.fire({ icon: 'info', title: '아이디와 비밀번호를 입력하세요' }); return; }
        Swal.fire({
            title: '로그인 · 매장 조회 중…',
            html: '<span style="font-size:var(--fs-xs);color:var(--color-muted);">네이버에 로그인해 등록된 매장을 불러옵니다. 수십 초 걸릴 수 있습니다.</span>',
            allowOutsideClick: false, showConfirmButton: false, didOpen: function () { Swal.showLoading(); }
        });
        const fd = new FormData();
        fd.append('_token', @json(csrf_token()));
        fd.append('naver_id', id);
        fd.append('naver_pw', pw);
        fetch(discoverUrl, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                if (!res.ok || !res.d.ok) {
                    Swal.fire({ icon: 'warning', title: '매장 조회 실패', text: (res.d && res.d.message) || '잠시 후 다시 시도하세요.' });
                    return;
                }
                const list = res.d.businesses || [];
                picker.classList.remove('hidden');
                if (list.length === 0) {
                    bizList.innerHTML = '';
                    bizMsg.textContent = '이 계정에 등록된 스마트플레이스 매장이 없습니다. URL을 직접 입력할 수도 있습니다.';
                    Swal.close();
                    return;
                }
                renderBusinesses(list);
                if (list.length === 1) {
                    // 1개 — 자동 선택
                    bizList.querySelector('.sp-biz-opt').classList.remove('btn-secondary');
                    bizList.querySelector('.sp-biz-opt').classList.add('btn-primary');
                    applyBiz(list[0]);
                    bizMsg.textContent = '매장 1개를 자동 선택했습니다.';
                    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '‘' + (list[0].name || list[0].placeSeq) + '’ 선택됨', showConfirmButton: false, timer: 1600 });
                } else {
                    bizMsg.textContent = list.length + '개 매장 중 하나를 선택하세요.';
                    Swal.close();
                }
            })
            .catch(function () { Swal.fire({ icon: 'error', title: '매장 조회에 실패했습니다', text: '잠시 후 다시 시도하세요.' }); });
    });

    // ---- 플레이스 URL → m.place 정규화 + 업체명 조회 (순위추적 resolve 재사용) ----
    const placeEl = document.getElementById('sp-place');
    const placeInfo = document.getElementById('sp-place-info');
    const resolveUrl = @json(route('console.rank.resolve'));
    let placeT = null, placeLast = '';
    function resolvePlacePreview() {
        const v = (placeEl.value || '').trim();
        if (v === '' || v === placeLast) return;
        placeLast = v;
        placeInfo.textContent = '업체명 조회 중…';
        placeInfo.style.color = 'var(--color-muted)';
        fetch(resolveUrl + '?place=' + encodeURIComponent(v), { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(d => {
                if (d && d.ok && d.place_name) {
                    placeInfo.innerHTML = '✓ <b style="color:var(--color-ink)">' + d.place_name + '</b>'
                        + (d.category && d.category !== 'place' ? ' <span style="color:var(--color-muted-soft)">· ' + d.category + '</span>' : '')
                        + (d.place_url ? ' <span style="color:var(--color-muted-soft)">· ' + d.place_url + '</span>' : '');
                    placeInfo.style.color = 'var(--color-primary)';
                    const labelEl = document.getElementById('sp-label');
                    if (!labelEl.value.trim()) labelEl.value = d.place_name; // 업체명 비어있으면 자동
                } else {
                    placeInfo.textContent = '플레이스를 찾지 못했습니다. URL/ID를 확인하세요(선택 항목이라 비워도 됩니다).';
                    placeInfo.style.color = 'var(--color-muted-soft)';
                }
            })
            .catch(() => { placeInfo.textContent = ''; });
    }
    placeEl.addEventListener('input', function () { clearTimeout(placeT); placeT = setTimeout(resolvePlacePreview, 600); });
    placeEl.addEventListener('blur', resolvePlacePreview);

    // 등록 제출 전 — 매장 선택(placeSeq) 필수 안내
    form.addEventListener('submit', function (e) {
        if (!document.getElementById('sp-place-seq').value.trim()) {
            e.preventDefault();
            Swal.fire({ icon: 'info', title: '매장을 선택하세요', text: '[매장 불러오기]로 스마트플레이스 매장을 먼저 선택해야 합니다.' });
        }
    });

    document.getElementById('sp-open-modal').addEventListener('click', openCreate);
    document.getElementById('sp-modal-close').addEventListener('click', closeModal);
    document.getElementById('sp-modal-cancel').addEventListener('click', closeModal);
    document.getElementById('sp-modal-bg').addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
    });
    document.querySelectorAll('.sp-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () { openEdit(btn); });
    });

    // 검증 실패 시 해당 모달을 입력값 유지한 채 다시 연다 (비밀번호는 보안상 유지하지 않음)
    @if (old('edit_account_id') || $errors->any() || old('naver_id'))
        openCreate();
        document.getElementById('sp-naver-id').value = @json(old('naver_id') ?? '');
        document.getElementById('sp-label').value = @json(old('label') ?? '');
        document.getElementById('sp-place').value = @json(old('place') ?? '');
        document.getElementById('sp-category').value = @json(old('category') ?? '');
        @if (old('place_seq'))
            document.getElementById('sp-place-seq').value = @json(old('place_seq'));
            document.getElementById('sp-business-id').value = @json(old('business_id') ?? '');
            picker.classList.remove('hidden');
            bizMsg.textContent = '선택된 매장: placeSeq ' + @json(old('place_seq')) + ' · 비밀번호를 다시 입력한 뒤 등록하세요.';
        @endif
        @if (old('edit_account_id'))
            form.action = @json(route('console.smartplace.update', ['account' => '__ID__'])).replace('__ID__', @json(old('edit_account_id')));
            method.disabled = false;
            document.getElementById('sp-edit-id').value = @json(old('edit_account_id'));
            title.textContent = '계정 수정';
            submit.textContent = '수정 저장';
            pwEl.required = false;
        @endif
    @endif
})();
</script>
@endsection
