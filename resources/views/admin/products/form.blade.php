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
@if (session('status'))
    <div class="card-soft px-4 py-3 mb-4 text-muted" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}" id="prod-form" style="max-width:920px;">
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
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">주문 폼 렌더</label>
                <select name="field_render_mode" class="input mt-1" style="width:100%;">
                    <option value="inline" {{ old('field_render_mode', $product->field_render_mode) === 'inline' ? 'selected' : '' }}>한 페이지(inline)</option>
                    <option value="step" {{ old('field_render_mode', $product->field_render_mode) === 'step' ? 'selected' : '' }}>스텝(step)</option>
                </select>
            </div>
        </div>
    </div>

    {{-- 주문 폼 필드 빌더 --}}
    <div class="card p-6 mb-4">
        <div class="flex items-center justify-between mb-1">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">주문 폼 필드 <span class="text-muted-soft" style="font-weight:400;">주문자가 입력할 항목</span></span>
            <div class="flex items-center gap-2">
                <button type="button" id="add-group" class="btn btn-ghost btn-sm">＋ 단계</button>
                <button type="button" id="add-field" class="btn btn-secondary btn-sm">＋ 필드 추가</button>
            </div>
        </div>
        <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">라벨·타입·필수 여부를 지정하세요. 단일/다중 선택은 옵션(줄바꿈 구분)을 입력합니다. <b class="text-muted">＋ 단계</b>로 구분선을 넣으면 "과금 방식 아래 렌더=스텝"일 때 그 단계 아래 필드들이 한 단계로 묶여 표시됩니다.</p>
        <div id="field-list" class="flex flex-col gap-3"></div>
        <div id="field-empty" class="text-muted-soft" style="font-size:var(--fs-xs);padding:14px 0;text-align:center;">필드가 없습니다. "＋ 필드 추가"로 주문 입력 항목을 만드세요.</div>
    </div>

    <div class="flex items-center gap-2">
        <button type="submit" class="btn btn-primary">{{ $mode === 'edit' ? '저장' : '상품 생성' }}</button>
        <a href="{{ route('admin.products') }}" class="btn btn-secondary">취소</a>
    </div>
</form>

<style>
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
        <input class="input gx-name" placeholder="단계 이름 (예: 기본 정보 / 상품 정보)" style="flex:1;height:30px;font-size:var(--fs-xs);font-weight:600;">
        <button type="button" class="btn btn-ghost btn-sm gx-del" style="color:var(--color-error);">삭제</button>
    </div>
</template>

{{-- 필드 행 템플릿 --}}
<template id="field-tpl">
    <div class="field-row card-soft" style="padding:12px;">
        <div class="flex items-start gap-2 flex-wrap">
            <span class="fx-grip" title="드래그로 순서 변경" style="cursor:grab;user-select:none;height:34px;display:inline-flex;align-items:center;padding:0 4px;color:var(--color-muted-soft);font-size:var(--fs-sm);line-height:1;">⠿</span>
            <input class="input fx-label" placeholder="라벨 (예: 매장 URL)" style="flex:1;min-width:160px;height:34px;font-size:var(--fs-xs);">
            <select class="input fx-type" style="width:150px;height:34px;font-size:var(--fs-xs);">
                @foreach ($fieldTypes as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
            </select>
            <input type="hidden" class="fx-key">
            <span class="inline-flex items-center gap-1.5" style="height:34px;">
                <label class="rf-switch"><input type="checkbox" class="fx-req" checked><span class="rf-track"></span></label>
                <span style="font-size:var(--fs-xs);">필수</span>
            </span>
            <button type="button" class="btn btn-ghost btn-sm fx-del" style="color:var(--color-error);">삭제</button>
        </div>
        <div class="fx-opts mt-2" style="display:none;">
            <textarea class="input" rows="3" placeholder="선택 옵션 — 한 줄에 하나씩" style="width:100%;font-size:var(--fs-xs);resize:vertical;"></textarea>
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
            });
        });
        document.getElementById('fields_json').value = JSON.stringify(rows);
    });
})();
</script>
@endsection
