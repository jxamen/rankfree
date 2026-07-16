@extends('console.layout')

@section('page-title', $product->title.' 주문')
{{-- 브레드크럼: 셀프마케팅(링크) › 상품명 · 사이드바는 셀프마케팅 메뉴 활성 --}}
@section('crumb-parent', 'self-marketing')
@section('crumb-title', $product->title)
@section('active-menu', 'self-marketing')

@section('console-content')
@php
    $qtyName = $qtyField ? 'f_'.$qtyField->field_key : 'quantity';
    $startName = $startField ? 'f_'.$startField->field_key : '';
    $endName = $endField ? 'f_'.$endField->field_key : '';
    $daysName = (! $startField && ! $endField && $product->quantity_mode === 'daily') ? 'days' : '';
    $stepClass = $stepMode ? 'order-step' : '';
@endphp

<section>
    <x-console.page-head title="셀프마케팅" desc="필요한 마케팅을 직접 골라 신청하세요. 분석으로 찾은 약점을 실행으로 연결합니다." />

    {{-- 유형 탭 — 셀프마케팅 카탈로그와 동일. 클릭 시 해당 유형 카탈로그로 이동, 현재 상품 유형 강조 --}}
    @if (($activeTypeCodes ?? collect())->count())
        <div class="card p-3 mb-6"><div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('self-marketing') }}" class="badge" style="font-size:var(--fs-xs);padding:5px 13px;">전체</a>
            @foreach ($activeTypeCodes as $code)
                <a href="{{ route('self-marketing', ['type' => $code]) }}" class="badge" style="font-size:var(--fs-xs);padding:5px 13px;{{ $product->product_type === $code ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">{{ $typeNames[$code] ?? $code }}</a>
            @endforeach
        </div></div>
    @endif

    @if (session('order_done'))
        <div class="card p-6 text-center mb-6" style="border:1px solid var(--color-success);">
            <div style="font-size:var(--fs-2xl);">✅</div>
            <p class="text-ink font-semibold mt-2" style="font-size:var(--fs-base);">주문이 접수되었습니다</p>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);">주문번호 <b class="text-ink">{{ session('order_done') }}</b> · 담당자가 확인 후 진행합니다.</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('order.store', $product->order_token) }}" enctype="multipart/form-data" id="order-form"
          data-step-mode="{{ $stepMode ? '1' : '0' }}" data-mode="{{ $product->quantity_mode }}"
          data-unit="{{ $product->min_price }}" data-qty="{{ $qtyName }}" data-start="{{ $startName }}" data-end="{{ $endName }}" data-days="{{ $daysName }}">
        @csrf

        {{-- 스텝 1: 상품 정보 + 수량 · 기간 (같은 카드) --}}
        <div class="card p-5 mb-4 {{ $stepClass }}" data-step="0">
            {{-- 상품 헤더 — 유형 · 상품명 · 설명 · 단가 --}}
            <div class="pb-4 mb-4 border-b border-hairline-soft">
                <div class="badge mb-2 border border-hairline" style="font-size:var(--fs-xs);">{{ $product->type()->name ?? $product->product_type }}</div>
                <h1 class="text-ink font-display" style="font-size:var(--fs-xl);line-height:1.25;">{{ $product->title }}</h1>
                @if ($product->description)
                    <p class="text-muted mt-2" style="font-size:var(--fs-sm);line-height:1.7;white-space:pre-line;">{{ $product->description }}</p>
                @endif
                <div class="text-ink font-display mt-3" style="font-size:var(--fs-lg);">{{ number_format($product->min_price) }}<span class="text-muted-soft" style="font-size:var(--fs-xs);">원 / 단가</span></div>
            </div>
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">수량 · 기간</div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div data-required="1">
                    <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">{{ $qtyField->label ?? '수량' }} <span class="text-muted-soft">({{ number_format($product->min_quantity) }}~{{ number_format($product->max_quantity) }})</span></label>
                    <input type="number" name="{{ $qtyName }}" min="{{ $product->min_quantity }}" max="{{ $product->max_quantity }}" value="{{ old($qtyName, $product->min_quantity) }}" class="input mt-1 text-right" style="width:100%;">
                </div>

                @if ($startField || $endField)
                    @if ($startField)
                        <div data-required="1">
                            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">{{ $startField->label }}</label>
                            <input type="date" name="{{ $startName }}" value="{{ old($startName) }}" min="{{ $minDate }}" class="input mt-1" style="width:100%;">
                        </div>
                    @endif
                    @if ($endField)
                        <div data-required="1">
                            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">{{ $endField->label }}</label>
                            <input type="date" name="{{ $endName }}" value="{{ old($endName) }}" min="{{ $minDate }}" class="input mt-1" style="width:100%;">
                        </div>
                    @endif
                @elseif ($product->quantity_mode === 'daily')
                    <div data-required="1">
                        <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">기간(일) <span class="text-muted-soft">(최소 {{ $product->min_days }})</span></label>
                        <input type="number" name="days" min="{{ $product->min_days }}" value="{{ old('days', $product->min_days) }}" class="input mt-1 text-right" style="width:100%;">
                    </div>
                @endif
            </div>
            @if ($startField || $endField)
                <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">가장 빠른 시작일: {{ $minDate }} · 금액은 일수량 × 진행일수로 계산됩니다.</div>
            @endif

            {{-- 비스텝 모드: 입력 정보 · 주문자 · 예상 금액 · 주문까지 같은 카드에.
                 단계(그룹)가 설정돼 있으면 인라인에서도 그룹 제목으로 구분해 표시 --}}
            @unless ($stepMode)
                @foreach ($infoGroups as $g)
                    <div class="pt-4 mt-4 border-t border-hairline-soft flex flex-col gap-4">
                        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $infoGroups->count() > 1 ? $g['name'] : ($g['name'] !== '주문 정보' ? $g['name'] : '주문 입력 정보') }}</div>
                        @foreach ($g['fields'] as $f)
                            @include('order._field', ['f' => $f, 'minDate' => $minDate])
                        @endforeach
                    </div>
                @endforeach
                <div class="pt-4 mt-4 border-t border-hairline-soft">
                    <div class="flex items-center gap-2 text-muted mb-3" style="font-size:var(--fs-xs);">
                        <span>주문자</span>
                        <span class="text-ink font-medium">{{ auth()->user()->name }}</span>
                        <span class="text-muted-soft">{{ auth()->user()->email }}</span>
                    </div>
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div>
                            <span class="text-muted" style="font-size:var(--fs-xs);">예상 금액</span>
                            <div class="text-ink font-display" style="font-size:var(--fs-xl);"><span id="o-total">0</span>원</div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="height:46px;padding:0 30px;">주문하기</button>
                    </div>
                </div>
            @endunless
        </div>

        {{-- 주문 입력 정보 (스텝 모드) --}}
        @if ($stepMode)
            @foreach ($infoGroups as $i => $g)
                <div class="card p-5 mb-4 order-step" data-step="{{ $i + 1 }}">
                    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">{{ $g['name'] }}</div>
                    <div class="flex flex-col gap-4">
                        @foreach ($g['fields'] as $f)
                            @include('order._field', ['f' => $f, 'minDate' => $minDate])
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif

        @if ($stepMode)
            {{-- 주문자 (로그인 회원 자동 연결) --}}
            <div class="card-soft px-4 py-3 mb-4 flex items-center gap-2 text-muted" style="font-size:var(--fs-xs);">
                <span>주문자</span>
                <span class="text-ink font-medium">{{ auth()->user()->name }}</span>
                <span class="text-muted-soft">{{ auth()->user()->email }}</span>
            </div>

            {{-- 합계 + 스텝 네비/제출 — 모든 스텝에서 보여야 하므로 별도 카드 유지 --}}
            <div class="card p-5 flex items-center justify-between flex-wrap gap-3">
                <div>
                    <span class="text-muted" style="font-size:var(--fs-xs);">예상 금액</span>
                    <div class="text-ink font-display" style="font-size:var(--fs-xl);"><span id="o-total">0</span>원</div>
                </div>
                <div class="flex items-center gap-2">
                    <span id="o-stepind" class="text-muted-soft" style="font-size:var(--fs-xs);margin-right:4px;"></span>
                    <button type="button" id="o-prev" class="btn btn-secondary" style="display:none;">이전</button>
                    <button type="button" id="o-next" class="btn btn-primary">다음</button>
                    <button type="submit" id="o-submit" class="btn btn-primary" style="display:none;height:46px;padding:0 30px;">주문하기</button>
                </div>
            </div>
        @endif
    </form>
</section>

<style>
    #order-form .order-step { display: none; }
    #order-form .order-step.active { display: block; }
    #order-form [data-required].step-err .input,
    #order-form [data-required].step-err input { border-color: var(--color-error); }
</style>

<script>
(function () {
    var form = document.getElementById('order-form');
    var stepMode = form.dataset.stepMode === '1';
    var daily = form.dataset.mode === 'daily';
    var unit = parseFloat(form.dataset.unit) || 0;
    var qtyEl = form.querySelector('[name="' + form.dataset.qty + '"]');
    var startEl = form.dataset.start ? form.querySelector('[name="' + form.dataset.start + '"]') : null;
    var endEl = form.dataset.end ? form.querySelector('[name="' + form.dataset.end + '"]') : null;
    var daysEl = form.dataset.days ? form.querySelector('[name="' + form.dataset.days + '"]') : null;
    var out = document.getElementById('o-total');

    function spanDays() {
        if (!daily) return 1;   // 전체 수량 과금(total): 기간과 무관하게 단가 × 수량
        if (startEl && endEl && startEl.value && endEl.value) {
            var s = new Date(startEl.value), e = new Date(endEl.value);
            var d = Math.floor((e - s) / 86400000) + 1;
            return d > 0 ? d : 1;
        }
        if (daysEl) return parseInt(daysEl.value, 10) || 1;
        return 1;
    }
    function calc() {
        var q = parseInt(qtyEl && qtyEl.value, 10) || 0;
        out.textContent = (unit * q * spanDays()).toLocaleString('ko-KR');
    }
    [qtyEl, startEl, endEl, daysEl].forEach(function (el) { if (el) el.addEventListener('input', calc); });
    calc();

    // ── 스텝 마법사 ──
    if (stepMode) {
        var steps = Array.prototype.slice.call(form.querySelectorAll('.order-step'));
        var prevBtn = document.getElementById('o-prev');
        var nextBtn = document.getElementById('o-next');
        var submitBtn = document.getElementById('o-submit');
        var ind = document.getElementById('o-stepind');
        var cur = 0;

        function validStep(step) {
            var ok = true;
            step.querySelectorAll('[data-required]').forEach(function (w) {
                var els = w.querySelectorAll('input, select, textarea');
                var filled = false;
                els.forEach(function (i) {
                    if (i.type === 'checkbox' || i.type === 'radio') { if (i.checked) filled = true; }
                    else if (i.type === 'file') { if (i.files && i.files.length) filled = true; }
                    else if (i.value && String(i.value).trim()) filled = true;
                });
                w.classList.toggle('step-err', !filled);
                if (!filled) ok = false;
            });
            return ok;
        }
        function show(i) {
            cur = Math.max(0, Math.min(i, steps.length - 1));
            steps.forEach(function (s, idx) { s.classList.toggle('active', idx === cur); });
            prevBtn.style.display = cur === 0 ? 'none' : '';
            var last = cur === steps.length - 1;
            nextBtn.style.display = last ? 'none' : '';
            submitBtn.style.display = last ? '' : 'none';
            ind.textContent = (cur + 1) + ' / ' + steps.length;
        }
        nextBtn.addEventListener('click', function () { if (validStep(steps[cur])) show(cur + 1); });
        prevBtn.addEventListener('click', function () { show(cur - 1); });
        show(0);
    }

    // 플레이스 URL 자동 정규화 — 업종별 m.place URL 로 변환(스마트플레이스 등록과 동일)
    var csrf = (form.querySelector('input[name="_token"]') || {}).value || '';
    var resolveUrl = @json(route('order.resolve-place'));
    function parseRef(v) {
        var m = (v || '').trim().match(/\/(place|restaurant|hairshop|nailshop|hospital|accommodation)\/(\d+)/);
        return m ? { category: m[1], id: m[2] } : null;
    }
    form.querySelectorAll('input[type="url"]').forEach(function (inp) {
        function apply() {
            var ref = parseRef(inp.value);
            if (!ref) return;
            if (ref.category !== 'place') { inp.value = 'https://m.place.naver.com/' + ref.category + '/' + ref.id; return; }
            inp.disabled = true; var prev = inp.value;
            fetch(resolveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ url: prev }),
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j && j.url) inp.value = j.url;
            }).catch(function () {}).finally(function () { inp.disabled = false; });
        }
        inp.addEventListener('blur', apply);
        inp.addEventListener('paste', function () { setTimeout(apply, 0); });
    });

    // 날짜 입력칸: 아무 곳이나 클릭해도 달력 열림
    form.querySelectorAll('input[type="date"]').forEach(function (inp) {
        inp.style.cursor = 'pointer';
        inp.addEventListener('click', function () { try { inp.showPicker(); } catch (e) {} });
        inp.addEventListener('focus', function () { try { inp.showPicker(); } catch (e) {} });
    });
})();
</script>
@endsection
