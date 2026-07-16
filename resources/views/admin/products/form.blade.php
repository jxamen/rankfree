@extends('admin.layout')
@section('page-title', $mode === 'edit' ? '상품 수정' : '새 상품')

@section('page-actions')
    <div class="flex items-center gap-2">
        @if ($product->exists)
            <a href="{{ $product->orderUrl() }}" target="_blank" class="btn btn-secondary btn-sm">주문 페이지 ↗</a>
        @endif
        <a href="{{ route('admin.products') }}" class="btn btn-secondary btn-sm">← 목록</a>
    </div>
@endsection

@section('admin-content')
@php $subByType = $subTypes->map(fn ($g) => $g->map(fn ($s) => ['code' => $s->code, 'name' => $s->name])->values()); @endphp
<x-console.page-head :title="$mode === 'edit' ? '상품 수정' : '새 상품'">
    <x-slot:desc>셀프마케팅 카탈로그 상품 정보·단가·주문 입력 필드 구성{{ $product->exists ? ' · 「'.$product->title.'」' : '' }}</x-slot:desc>
</x-console.page-head>
@if (session('status'))
    <div class="card-soft px-4 py-3 mb-4 text-muted" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}" id="prod-form">
    @csrf
    @if ($product->exists) @method('PUT') @endif
    <input type="hidden" name="fields_json" id="fields_json">

    {{-- 기본 정보 --}}
    <div class="card p-6 mb-4">
        <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-sm);">기본 정보</div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">유형</label>
                <select name="product_type" id="f-type" class="input mt-1" style="width:100%;">
                    @foreach ($types as $t)
                        <option value="{{ $t->code }}" {{ old('product_type', $product->product_type) === $t->code ? 'selected' : '' }}>{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">세부 유형</label>
                <select name="sub_type_code" id="f-sub" class="input mt-1" style="width:100%;"></select>
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">노출</label>
                <select name="is_active" class="input mt-1" style="width:100%;">
                    <option value="1" {{ old('is_active', $product->is_active) ? 'selected' : '' }}>노출</option>
                    <option value="0" {{ ! old('is_active', $product->is_active ?? true) ? 'selected' : '' }}>숨김</option>
                </select>
            </div>
        </div>
        <div class="mb-4">
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">상품명</label>
            <input name="title" value="{{ old('title', $product->title) }}" class="input mt-1" style="width:100%;" placeholder="예: 네이버 플레이스 저장 리워드">
        </div>
        <div>
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">설명</label>
            <textarea name="description" class="input mt-1" style="width:100%;height:500px;padding:12px 14px;line-height:1.7;resize:vertical;">{{ old('description', $product->description) }}</textarea>
        </div>
    </div>

    {{-- 가격·수량 --}}
    <div class="card p-6 mb-4">
        <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-sm);">가격 · 수량</div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            @foreach ([
                ['base_cost', '원가(원)', $product->base_cost ?? 0],
                ['min_price', '판매 단가(원)', $product->min_price ?? 0],
                ['min_quantity', '최소 수량', $product->min_quantity ?? 10],
                ['max_quantity', '최대 수량', $product->max_quantity ?? 10000],
            ] as [$k, $lab, $def])
                <div>
                    <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">{{ $lab }}</label>
                    <input type="number" step="any" name="{{ $k }}" value="{{ old($k, $def) }}" class="input mt-1 text-right" style="width:100%;">
                </div>
            @endforeach
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">과금 방식</label>
                <select name="quantity_mode" class="input mt-1" style="width:100%;">
                    <option value="daily" {{ old('quantity_mode', $product->quantity_mode) === 'daily' ? 'selected' : '' }}>일수량 × 기간 (리워드)</option>
                    <option value="total" {{ old('quantity_mode', $product->quantity_mode) === 'total' ? 'selected' : '' }}>전체 수량 (체험단)</option>
                </select>
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">최소 기간(일)</label>
                <input type="number" name="min_days" value="{{ old('min_days', $product->min_days ?? 1) }}" class="input mt-1 text-right" style="width:100%;">
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">일 최소수량(0=제한없음)</label>
                <input type="number" name="min_daily_quantity" value="{{ old('min_daily_quantity', $product->min_daily_quantity ?? 0) }}" class="input mt-1 text-right" style="width:100%;">
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">기본 이행률(%)</label>
                <input type="number" step="any" name="default_fulfillment" value="{{ old('default_fulfillment', $product->default_fulfillment ?? 100) }}" class="input mt-1 text-right" style="width:100%;">
            </div>
        </div>
        <p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">
            과금 방식 · <b class="text-muted">일수량 × 기간(리워드)</b>: 금액 = 단가 × 일수량 × 진행일수 (예: 일 100회 × 14일 = 1,400회).
            <b class="text-muted">전체 수량(체험단)</b>: 금액 = 단가 × 수량 (예: 100명 모집 = 100명 비용, 기간과 무관).
        </p>
    </div>

    {{-- 접수/진행 스케줄 --}}
    <div class="card p-6 mb-4">
        <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-sm);">접수 · 진행 스케줄</div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 items-end">
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">일 접수 마감(시, 0~23)</label>
                <input type="number" min="0" max="23" name="daily_cutoff_hour" value="{{ old('daily_cutoff_hour', $product->daily_cutoff_hour) }}" placeholder="없음" class="input mt-1 text-right" style="width:100%;">
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">진행 시작 지연(영업일)</label>
                <input type="number" min="0" name="processing_lag_days" value="{{ old('processing_lag_days', $product->processing_lag_days ?? 0) }}" class="input mt-1 text-right" style="width:100%;">
            </div>
            <div class="inline-flex items-center gap-2" style="height:40px;">
                <label class="rf-switch"><input type="checkbox" name="process_weekends" value="1" {{ old('process_weekends', $product->process_weekends ?? true) ? 'checked' : '' }}><span class="rf-track"></span></label>
                <span style="font-size:var(--fs-xs);">주말 진행</span>
            </div>
            <div class="inline-flex items-center gap-2" style="height:40px;">
                <label class="rf-switch"><input type="checkbox" name="process_holidays" value="1" {{ old('process_holidays', $product->process_holidays ?? true) ? 'checked' : '' }}><span class="rf-track"></span></label>
                <span style="font-size:var(--fs-xs);">공휴일 진행</span>
            </div>
        </div>
    </div>

    {{-- 주문 폼 필드 빌더 --}}
    <div class="card p-6 mb-4">
        <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">주문 폼 필드 <span class="text-muted-soft" style="font-weight:400;">주문자가 입력할 항목</span></span>
            <div class="flex items-center gap-2">
                {{-- 주문 폼 렌더 방식 — 인라인(한 페이지) / 스텝(단계별) 토글 --}}
                <input type="hidden" name="field_render_mode" id="f-render" value="{{ old('field_render_mode', $product->field_render_mode ?? 'inline') }}">
                <div class="inline-flex" style="gap:2px;background:var(--color-surface-strong);border-radius:var(--radius-pill);padding:2px;">
                    <button type="button" class="btn btn-sm rf-render" data-mode="inline" style="height:28px;">인라인</button>
                    <button type="button" class="btn btn-sm rf-render" data-mode="step" style="height:28px;">스텝</button>
                </div>
                <button type="button" id="add-group" class="btn btn-ghost btn-sm">＋ 단계</button>
                <button type="button" id="add-field" class="btn btn-secondary btn-sm">＋ 필드 추가</button>
            </div>
        </div>
        <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">라벨·타입·필수 여부를 지정하세요. 단일/다중 선택은 옵션(줄바꿈 구분)을 입력합니다. <b class="text-muted">＋ 단계</b>로 구분한 그룹은 <b class="text-muted">스텝</b>이면 단계별 화면으로, <b class="text-muted">인라인</b>이면 한 페이지 안에서 그룹 제목으로 구분되어 표시됩니다.</p>
        <div id="field-list" class="flex flex-col gap-3"></div>
        <div id="field-empty" class="text-muted-soft" style="font-size:var(--fs-xs);padding:14px 0;text-align:center;">필드가 없습니다. "＋ 필드 추가"로 주문 입력 항목을 만드세요.</div>
    </div>

    {{-- 외부 발주 — 업체 배분 · 매핑 --}}
    <div class="card p-6 mb-4">
        <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">외부 발주 — 업체 배분 <span class="text-muted-soft" style="font-weight:400;">주문 승인 시 자동 발주</span></span>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.vendors') }}" target="_blank" class="btn btn-ghost btn-sm">업체 관리 ↗</a>
                <button type="button" id="add-vendor" class="btn btn-secondary btn-sm">＋ 업체 추가</button>
            </div>
        </div>
        <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">
            관리자가 주문을 <b class="text-muted">승인</b>하면 아래 배분(비율/고정 수량)대로 각 업체에 자동 전송됩니다(API 호출 · 구글시트).
            <b class="text-muted">[매핑]</b>에서 업체별로 보낼 키 ← 주문 값 연결을 설정하세요 — 구글시트는 매핑 순서대로 열(A, B, C…)에 기록됩니다. 비율 내림 잔여분은 마지막 비율 업체에 배분됩니다.
        </p>
        <input type="hidden" name="vendors_json" id="vendors_json">
        <div id="vendor-list" class="flex flex-col gap-3"></div>
        <div id="vendor-empty" class="text-muted-soft" style="font-size:var(--fs-xs);padding:14px 0;text-align:center;">
            배분 설정이 없습니다. 승인해도 외부 발주가 나가지 않습니다. "＋ 업체 추가"로 설정하세요.
        </div>
    </div>

    <div class="flex items-center gap-2">
        <button type="submit" class="btn btn-primary">{{ $mode === 'edit' ? '저장' : '상품 생성' }}</button>
        <a href="{{ route('admin.products') }}" class="btn btn-secondary">취소</a>
    </div>
</form>

{{-- 업체 배분 행 템플릿 --}}
<template id="vendor-tpl">
    <div class="vendor-row card-soft" style="padding:12px;">
        <div class="flex items-center gap-2 flex-wrap">
            <select class="input vx-vendor" style="flex:1.4;min-width:180px;">
                <option value="">업체 선택</option>
                @foreach ($vendors as $v)
                    <option value="{{ $v->id }}">{{ $v->name }} ({{ \App\Models\Vendor::CHANNELS[$v->channel] }})</option>
                @endforeach
            </select>
            <select class="input vx-type" style="width:120px;">
                <option value="ratio">비율(%)</option>
                <option value="fixed">고정 수량</option>
            </select>
            <input type="number" class="input vx-value text-right" style="width:110px;" min="0" value="0">
            <span class="inline-flex items-center gap-1.5" style="height:34px;">
                <label class="rf-switch"><input type="checkbox" class="vx-active" checked><span class="rf-track"></span></label>
                <span style="font-size:var(--fs-xs);">활성</span>
            </span>
            <button type="button" class="btn btn-ghost btn-sm vx-map-toggle">매핑</button>
            <button type="button" class="btn btn-ghost btn-sm vx-del" style="color:var(--color-error);">삭제</button>
        </div>
        {{-- 매핑 패널 — 보낼 키 ← 값 소스 --}}
        <div class="vx-map mt-2 rounded-lg" style="display:none;background:var(--color-surface-soft);border:1px solid var(--color-hairline-soft);padding:10px 12px;">
            <div class="vx-map-rows flex flex-col gap-2"></div>
            <div class="flex items-center justify-between mt-2">
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">비워두면 기본 페이로드(주문번호·상품명·수량·입력값 전체)로 전송됩니다.</span>
                <button type="button" class="btn btn-ghost btn-sm vx-map-add">＋ 매핑 행</button>
            </div>
        </div>
    </div>
</template>

{{-- 매핑 행 템플릿 --}}
<template id="vmap-tpl">
    <div class="vmap-row flex items-center gap-2 flex-wrap">
        <span class="vm-col badge" style="display:none;font-size:var(--fs-xs);background:var(--color-surface-strong);"></span>
        <input class="input vm-key" placeholder="보낼 키 (예: keyword)" style="width:180px;">
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">←</span>
        <select class="input vm-src" style="flex:1;min-width:180px;"></select>
        <input class="input vm-value" placeholder="고정값" style="flex:1;min-width:140px;display:none;">
        <button type="button" class="btn btn-ghost btn-sm vm-del" style="color:var(--color-error);">✕</button>
    </div>
</template>

<style>
    /* 빌더 내 입력 사이즈 통일 — 높이 34px · 폰트 fs-xs (textarea 제외) */
    #field-list input.input, #field-list select.input,
    #vendor-list input.input, #vendor-list select.input { height: 34px; font-size: var(--fs-xs); }
    #field-list .field-row, #field-list .group-row { transition: box-shadow .1s ease, opacity .1s ease; }
    #field-list .field-row.fx-dragging, #field-list .group-row.fx-dragging { opacity: .5; box-shadow: 0 4px 14px rgba(17,17,17,.12); }
    #field-list .fx-grip:active { cursor: grabbing; }
    #field-list .group-row { display:flex; align-items:center; gap:8px; padding:8px 10px; margin-top:4px; background:var(--color-surface-card); border:1px solid var(--color-hairline); border-radius:8px; }
    #field-list .field-row { margin-left:14px; }
</style>

{{-- 단계(그룹) 헤더 템플릿 --}}
<template id="group-tpl">
    <div class="group-row">
        <span class="fx-grip" title="드래그로 순서 변경" style="cursor:grab;user-select:none;display:inline-flex;align-items:center;color:var(--color-muted-soft);font-size:var(--fs-sm);line-height:1;">⠿</span>
        <span class="badge" style="font-size:var(--fs-xs);padding:1px 8px;">단계</span>
        <input class="input gx-name" placeholder="단계 이름 (예: 기본 정보 / 상품 정보)" style="flex:1;font-weight:600;">
        <button type="button" class="btn btn-ghost btn-sm gx-del" style="color:var(--color-error);">삭제</button>
    </div>
</template>

{{-- 필드 행 템플릿 --}}
<template id="field-tpl">
    <div class="field-row card-soft" style="padding:12px;">
        <div class="flex items-start gap-2 flex-wrap">
            <span class="fx-grip" title="드래그로 순서 변경" style="cursor:grab;user-select:none;height:34px;display:inline-flex;align-items:center;padding:0 4px;color:var(--color-muted-soft);font-size:var(--fs-sm);line-height:1;">⠿</span>
            <input class="input fx-label" placeholder="라벨 (예: 매장 URL)" style="flex:1;min-width:160px;">
            <select class="input fx-type" style="width:150px;">
                @foreach ($fieldTypes as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
            </select>
            <input type="hidden" class="fx-key">
            <span class="inline-flex items-center gap-1.5" style="height:34px;">
                <label class="rf-switch"><input type="checkbox" class="fx-req" checked><span class="rf-track"></span></label>
                <span style="font-size:var(--fs-xs);">필수</span>
            </span>
            <button type="button" class="btn btn-ghost btn-sm fx-more" title="placeholder·필수 포함값 등 개별 설정">옵션</button>
            <button type="button" class="btn btn-ghost btn-sm fx-del" style="color:var(--color-error);">삭제</button>
        </div>
        <div class="fx-opts mt-2" style="display:none;">
            <textarea class="input" rows="3" placeholder="선택 옵션 — 한 줄에 하나씩" style="width:100%;font-size:var(--fs-xs);resize:vertical;"></textarea>
        </div>
        {{-- 개별 설정 패널 — [옵션] 버튼으로 펼침 --}}
        <div class="fx-extra mt-2 rounded-lg" style="display:none;background:var(--color-surface-soft);border:1px solid var(--color-hairline-soft);padding:10px 12px;">
            <div class="flex gap-3 flex-wrap">
                <div style="flex:1;min-width:200px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">입력 예시 문구 (placeholder)</label>
                    <input class="input fx-ph" placeholder="예: https://m.place.naver.com/… 주소 입력" style="width:100%;">
                </div>
                <div style="flex:1;min-width:160px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">필수 포함 값</label>
                    <input class="input fx-contains" placeholder="예: m.place.naver.com" style="width:100%;">
                </div>
                <div style="flex:1.4;min-width:220px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">미포함 시 안내 메시지</label>
                    <input class="input fx-contains-msg" placeholder="예: 네이버 플레이스(m.place.naver.com) URL을 입력하세요" style="width:100%;">
                </div>
            </div>
            <div class="text-muted-soft mt-1.5" style="font-size:var(--fs-xs);">필수 포함 값을 설정하면 주문 입력값에 해당 문자열이 없을 때 주문이 접수되지 않고 안내 메시지가 표시됩니다.</div>
        </div>
    </div>
</template>

<script>
(function () {
    var TYPES_WITH_OPTS = ['SELECT', 'MULTI_SELECT'];
    var SUBS = @json($subByType);
    var INIT = @json($fieldsJson);

    // 세부 유형 연동
    var typeSel = document.getElementById('f-type'), subSel = document.getElementById('f-sub');
    var initSub = @json(old('sub_type_code', $product->sub_type_code));
    function fillSub() {
        var t = typeSel.value; var list = SUBS[t] || [];
        subSel.innerHTML = '<option value="">(없음)</option>' + list.map(function (s) {
            return '<option value="' + s.code + '"' + (s.code === initSub ? ' selected' : '') + '>' + s.name + '</option>';
        }).join('');
    }
    typeSel.addEventListener('change', function () { initSub = ''; fillSub(); });
    fillSub();

    // 필드 빌더
    var list = document.getElementById('field-list');
    var tpl = document.getElementById('field-tpl');
    var empty = document.getElementById('field-empty');
    function refreshEmpty() { empty.style.display = list.children.length ? 'none' : 'block'; }

    // ── 주문 폼 렌더 방식 토글 (인라인/스텝) ──
    var renderInput = document.getElementById('f-render');
    var renderBtns = document.querySelectorAll('.rf-render');
    function syncRender() {
        renderBtns.forEach(function (b) {
            var on = b.dataset.mode === renderInput.value;
            b.classList.toggle('btn-primary', on);
            b.classList.toggle('btn-ghost', !on);
        });
    }
    renderBtns.forEach(function (b) {
        b.addEventListener('click', function () { renderInput.value = b.dataset.mode; syncRender(); });
    });
    syncRender();
    // 필드 키는 내부용 — 자동 생성(field_N). 기존 필드는 키 유지, 신규는 미사용 번호 부여.
    function genKey() {
        var used = {};
        list.querySelectorAll('.fx-key').forEach(function (k) { if (k.value) used[k.value] = 1; });
        var i = 1;
        while (used['field_' + i]) i++;
        return 'field_' + i;
    }

    function addRow(data) {
        data = data || {};
        var node = tpl.content.firstElementChild.cloneNode(true);
        var label = node.querySelector('.fx-label'), type = node.querySelector('.fx-type');
        var key = node.querySelector('.fx-key'), req = node.querySelector('.fx-req');
        var optsWrap = node.querySelector('.fx-opts'), optsTa = optsWrap.querySelector('textarea');
        label.value = data.label || '';
        type.value = data.field_type || 'TEXT';
        key.value = data.field_key || genKey();   // 기존 키 유지, 없으면 자동 생성
        req.checked = data.is_required !== false;
        if (Array.isArray(data.options)) optsTa.value = data.options.map(function (o) { return o.label || o; }).join('\n');
        function syncOpts() { optsWrap.style.display = TYPES_WITH_OPTS.indexOf(type.value) !== -1 ? 'block' : 'none'; }
        type.addEventListener('change', syncOpts); syncOpts();
        // 개별 설정 패널 — placeholder·필수 포함값·안내 메시지. 설정값이 있으면 [옵션] 버튼 강조
        var extra = node.querySelector('.fx-extra'), moreBtn = node.querySelector('.fx-more');
        node.querySelector('.fx-ph').value = data.placeholder || '';
        node.querySelector('.fx-contains').value = data.contains || '';
        node.querySelector('.fx-contains-msg').value = data.contains_message || '';
        function syncMore() {
            var has = ['.fx-ph', '.fx-contains', '.fx-contains-msg'].some(function (s) { return node.querySelector(s).value.trim() !== ''; });
            moreBtn.classList.toggle('btn-secondary', has);
            moreBtn.classList.toggle('btn-ghost', !has);
            moreBtn.textContent = has ? '옵션 ●' : '옵션';
        }
        moreBtn.addEventListener('click', function () {
            extra.style.display = extra.style.display === 'none' ? 'block' : 'none';
        });
        extra.addEventListener('input', syncMore); syncMore();
        node.querySelector('.fx-del').addEventListener('click', function () { node.remove(); refreshEmpty(); });
        // 드래그 핸들에서만 드래그 시작(입력칸 텍스트 선택 방해 방지)
        var grip = node.querySelector('.fx-grip');
        grip.addEventListener('mousedown', function () { node.setAttribute('draggable', 'true'); });
        grip.addEventListener('mouseup', function () { node.removeAttribute('draggable'); });
        node.addEventListener('dragend', function () { node.removeAttribute('draggable'); node.classList.remove('fx-dragging'); });
        list.appendChild(node); refreshEmpty();
    }
    // 단계(그룹) 헤더
    var gtpl = document.getElementById('group-tpl');
    function addGroup(name) {
        var node = gtpl.content.firstElementChild.cloneNode(true);
        node.querySelector('.gx-name').value = name || '';
        node.querySelector('.gx-del').addEventListener('click', function () { node.remove(); refreshEmpty(); });
        var grip = node.querySelector('.fx-grip');
        grip.addEventListener('mousedown', function () { node.setAttribute('draggable', 'true'); });
        grip.addEventListener('mouseup', function () { node.removeAttribute('draggable'); });
        node.addEventListener('dragend', function () { node.removeAttribute('draggable'); node.classList.remove('fx-dragging'); });
        list.appendChild(node); refreshEmpty();
    }
    document.getElementById('add-group').addEventListener('click', function () { addGroup(); });
    document.getElementById('add-field').addEventListener('click', function () { addRow(); });

    // 기존 데이터: 그룹이 바뀌는 지점마다 단계 헤더 삽입 후 필드 추가
    var lastGroup = null;
    (INIT || []).forEach(function (f) {
        var g = (f.group || '').trim();
        if (g && g !== lastGroup) { addGroup(g); lastGroup = g; }
        addRow(f);
    });
    refreshEmpty();

    // ── 드래그앤드랍 순서 변경 ──
    var dragEl = null;
    list.addEventListener('dragstart', function (e) {
        var row = e.target.closest && e.target.closest('.field-row, .group-row');
        if (!row) return;
        dragEl = row; row.classList.add('fx-dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', ''); } catch (err) {}
    });
    list.addEventListener('dragover', function (e) {
        if (!dragEl) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var after = getDragAfter(e.clientY);
        if (after == null) list.appendChild(dragEl);
        else list.insertBefore(dragEl, after);
    });
    list.addEventListener('drop', function (e) { e.preventDefault(); });
    function getDragAfter(y) {
        var rows = Array.prototype.slice.call(list.querySelectorAll('.field-row:not(.fx-dragging), .group-row:not(.fx-dragging)'));
        var closest = { offset: -Infinity, el: null };
        rows.forEach(function (r) {
            var box = r.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) closest = { offset: offset, el: r };
        });
        return closest.el;
    }

    // ── 외부 발주 — 업체 배분 빌더 ──
    var VINIT = @json($vendorsJson ?? []);
    var vlist = document.getElementById('vendor-list');
    var vtpl = document.getElementById('vendor-tpl');
    var vmapTpl = document.getElementById('vmap-tpl');
    var vempty = document.getElementById('vendor-empty');
    function vRefreshEmpty() { vempty.style.display = vlist.children.length ? 'none' : 'block'; }

    // 매핑 소스 — 주문 고정 항목 + 현재 빌더의 동적 필드(열 때마다 갱신)
    function fieldSources() {
        var out = [
            ['alloc:quantity', '배분 수량(이 업체 몫)'],
            ['order:quantity', '주문 전체 수량'],
            ['order:order_no', '주문번호'],
            ['order:days', '기간(일)'],
            ['order:unit_price', '단가'],
            ['order:total_price', '총액'],
            ['order:orderer_name', '주문자 이름'],
            ['order:orderer_contact', '주문자 연락처'],
            ['order:created_at', '주문일시'],
            ['product:title', '상품명'],
        ];
        list.querySelectorAll('.field-row').forEach(function (r) {
            var k = r.querySelector('.fx-key').value.trim();
            var lb = r.querySelector('.fx-label').value.trim();
            if (k) out.push(['field:' + k, '필드: ' + (lb || k)]);
        });
        out.push(['static', '고정값 직접 입력']);
        return out;
    }
    function fillSrc(sel, cur) {
        sel.innerHTML = fieldSources().map(function (s) {
            return '<option value="' + s[0] + '"' + (s[0] === cur ? ' selected' : '') + '>' + s[1] + '</option>';
        }).join('');
    }
    function addMapRow(wrap, data) {
        data = data || {};
        var row = vmapTpl.content.firstElementChild.cloneNode(true);
        row.querySelector('.vm-key').value = data.key || '';
        var src = row.querySelector('.vm-src');
        fillSrc(src, data.src || 'alloc:quantity');
        var val = row.querySelector('.vm-value');
        val.value = data.value || '';
        function syncStatic() { val.style.display = src.value === 'static' ? '' : 'none'; }
        src.addEventListener('change', syncStatic); syncStatic();
        row.querySelector('.vm-del').addEventListener('click', function () { row.remove(); });
        wrap.appendChild(row);
    }
    function addVendorRow(data) {
        data = data || {};
        var node = vtpl.content.firstElementChild.cloneNode(true);
        node.querySelector('.vx-vendor').value = data.vendor_id || '';
        node.querySelector('.vx-type').value = data.alloc_type || 'ratio';
        node.querySelector('.vx-value').value = data.alloc_value != null ? data.alloc_value : 0;
        node.querySelector('.vx-active').checked = data.is_active !== false;
        var map = node.querySelector('.vx-map');
        var mapRows = node.querySelector('.vx-map-rows');
        var vendorSel = node.querySelector('.vx-vendor');
        (Array.isArray(data.map) ? data.map : []).forEach(function (m) { addMapRow(mapRows, m); });
        var mapBtn = node.querySelector('.vx-map-toggle');
        function isGsheet() {
            var opt = vendorSel.options[vendorSel.selectedIndex];
            return !!opt && opt.textContent.indexOf('구글시트') !== -1;
        }
        // 구글시트 업체는 매핑 행 = 시트 열(A, B, C…) — 행마다 대상 열 표시
        function syncCols() {
            var gs = isGsheet();
            Array.prototype.forEach.call(mapRows.querySelectorAll('.vmap-row'), function (r, i) {
                var col = r.querySelector('.vm-col');
                col.style.display = gs ? '' : 'none';
                col.textContent = '열 ' + String.fromCharCode(65 + (i % 26));
                r.querySelector('.vm-key').placeholder = gs ? '열 제목 (메모용, 예: 키워드)' : '보낼 키 (예: keyword)';
            });
        }
        function syncMapBtn() {
            var n = mapRows.children.length;
            mapBtn.textContent = n ? '매핑 ' + n : '매핑';
            mapBtn.classList.toggle('btn-secondary', !!n);
            mapBtn.classList.toggle('btn-ghost', !n);
            syncCols();
        }
        vendorSel.addEventListener('change', syncCols);
        mapBtn.addEventListener('click', function () {
            var open = map.style.display !== 'none';
            map.style.display = open ? 'none' : 'block';
            if (!open) { mapRows.querySelectorAll('.vm-src').forEach(function (s) { fillSrc(s, s.value); }); syncCols(); }   // 필드 목록 최신화
        });
        node.querySelector('.vx-map-add').addEventListener('click', function () { addMapRow(mapRows, {}); syncMapBtn(); });
        map.addEventListener('click', function () { setTimeout(syncMapBtn, 0); });   // ✕ 삭제 후 카운트 갱신
        syncMapBtn();
        node.querySelector('.vx-del').addEventListener('click', function () { node.remove(); vRefreshEmpty(); });
        vlist.appendChild(node); vRefreshEmpty();
    }
    document.getElementById('add-vendor').addEventListener('click', function () { addVendorRow(); });
    (VINIT || []).forEach(addVendorRow);
    vRefreshEmpty();

    // 제출 시 필드 → JSON 직렬화 (단계 헤더를 만나면 이후 필드의 group 으로 지정)
    document.getElementById('prod-form').addEventListener('submit', function () {
        var rows = [];
        var currentGroup = '';
        Array.prototype.forEach.call(list.children, function (r) {
            if (r.classList.contains('group-row')) { currentGroup = r.querySelector('.gx-name').value.trim(); return; }
            if (!r.classList.contains('field-row')) return;
            var label = r.querySelector('.fx-label').value.trim();
            if (!label) return;
            var type = r.querySelector('.fx-type').value;
            var opts = null;
            if (TYPES_WITH_OPTS.indexOf(type) !== -1) {
                opts = r.querySelector('.fx-opts textarea').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
            }
            rows.push({
                label: label, field_type: type,
                field_key: r.querySelector('.fx-key').value.trim() || 'field_' + (rows.length + 1),
                is_required: r.querySelector('.fx-req').checked, options: opts, group: currentGroup, sort_order: rows.length,
                placeholder: r.querySelector('.fx-ph').value.trim() || null,
                contains: r.querySelector('.fx-contains').value.trim() || null,
                contains_message: r.querySelector('.fx-contains-msg').value.trim() || null,
            });
        });
        document.getElementById('fields_json').value = JSON.stringify(rows);

        // 업체 배분 → JSON 직렬화
        var vrows = [];
        Array.prototype.forEach.call(vlist.querySelectorAll('.vendor-row'), function (r) {
            var vid = parseInt(r.querySelector('.vx-vendor').value, 10);
            if (!vid) return;
            var map = [];
            r.querySelectorAll('.vmap-row').forEach(function (m) {
                var k = m.querySelector('.vm-key').value.trim();
                if (!k) return;
                map.push({ key: k, src: m.querySelector('.vm-src').value, value: m.querySelector('.vm-value').value });
            });
            vrows.push({
                vendor_id: vid,
                alloc_type: r.querySelector('.vx-type').value,
                alloc_value: parseInt(r.querySelector('.vx-value').value, 10) || 0,
                is_active: r.querySelector('.vx-active').checked,
                map: map,
            });
        });
        document.getElementById('vendors_json').value = JSON.stringify(vrows);
    });
})();
</script>
@endsection
