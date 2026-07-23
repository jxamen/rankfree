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

    @php
        // 활성 탭 — 주문 완료 직후엔 접수 내역(새 주문 확인), 검증 오류 시엔 주문 폼 유지
        $activeTab = request('tab') === 'history' || (session('order_done') && ! $errors->any()) ? 'history' : 'order';
    @endphp
    {{-- 좌: 주문(폼) / 우: 주문 내역 --}}
    <div class="rf-tabs" role="tablist">
        <button type="button" class="rf-tab {{ $activeTab === 'order' ? 'on' : '' }}" data-tab="order" role="tab">주문</button>
        <button type="button" class="rf-tab {{ $activeTab === 'history' ? 'on' : '' }}" data-tab="history" role="tab">주문 내역 <span class="text-muted-soft">{{ number_format($myOrdersTotal ?? 0) }}</span></button>
        @if (auth()->user()?->isOperator())
            {{-- 어드민 전용 — 상품 수정 페이지 바로가기 --}}
            <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-secondary btn-sm" style="margin-left:auto;align-self:center;margin-bottom:8px;">상품 수정</a>
        @endif
    </div>

    <div class="rf-tabpane" data-tab="order" @if ($activeTab !== 'order') hidden @endif>
    <form method="POST" action="{{ route('order.store', $product->order_token) }}" enctype="multipart/form-data" id="order-form"
          data-step-mode="{{ $stepMode ? '1' : '0' }}" data-mode="{{ $product->quantity_mode }}"
          data-unit="{{ $product->min_price }}" data-qty="{{ $qtyName }}" data-start="{{ $startName }}" data-end="{{ $endName }}" data-days="{{ $daysName }}"
          data-fixed-days="{{ $product->quantity_mode === 'daily' ? ($product->fixed_days ?? '') : '' }}">
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
            @php
                // 고정 수량·기간 상품 — 고객은 그 값 그대로 주문(입력 잠금, 서버도 강제)
                $fixedQty = $product->fixed_quantity;
                $fixedDays = $product->quantity_mode === 'daily' ? $product->fixed_days : null;
                $lockStyle = 'background:var(--color-surface-soft);color:var(--color-muted);pointer-events:none;';
            @endphp
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">수량 · 기간</div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div data-required="1">
                    <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">{{ $qtyField->label ?? '수량' }}
                        <span class="text-muted-soft">{{ $fixedQty ? '(고정)' : '('.number_format($product->min_quantity).'~'.number_format($product->max_quantity).')' }}</span></label>
                    <input type="number" name="{{ $qtyName }}" min="{{ $product->min_quantity }}" max="{{ $product->max_quantity }}"
                           value="{{ $fixedQty ?? old($qtyName, $product->min_quantity) }}"
                           @if ($fixedQty) readonly tabindex="-1" @endif
                           class="input mt-1 text-right" style="width:100%;{{ $fixedQty ? $lockStyle : '' }}">
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
                            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">{{ $endField->label }}{{ $fixedDays ? ' (자동)' : '' }}</label>
                            <input type="date" name="{{ $endName }}" value="{{ old($endName) }}" min="{{ $minDate }}"
                                   @if ($fixedDays) readonly tabindex="-1" @endif
                                   class="input mt-1" style="width:100%;{{ $fixedDays ? $lockStyle : '' }}">
                        </div>
                    @endif
                @elseif ($product->quantity_mode === 'daily')
                    <div data-required="1">
                        <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">기간(일)
                            <span class="text-muted-soft">{{ $fixedDays ? '(고정)' : '(최소 '.$product->min_days.')' }}</span></label>
                        <input type="number" name="days" min="{{ $product->min_days }}" value="{{ $fixedDays ?? old('days', $product->min_days) }}"
                               @if ($fixedDays) readonly tabindex="-1" @endif
                               class="input mt-1 text-right" style="width:100%;{{ $fixedDays ? $lockStyle : '' }}">
                    </div>
                @endif
            </div>
            @if ($startField || $endField)
                <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">가장 빠른 시작일: {{ $minDate }} ·
                    {{ $fixedDays ? '기간 '.$fixedDays.'일 고정 — 시작일을 고르면 종료일이 자동 계산됩니다.' : '금액은 일수량 × 진행일수로 계산됩니다.' }}</div>
            @elseif ($fixedQty || $fixedDays)
                <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">이 상품은 {{ $fixedQty ? '수량' : '' }}{{ $fixedQty && $fixedDays ? '·' : '' }}{{ $fixedDays ? '기간' : '' }}이 고정된 패키지 상품입니다 — 그대로 주문하시면 됩니다.</div>
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
                    @include('order._coupon')
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div>
                            <span class="text-muted" style="font-size:var(--fs-xs);">예상 금액</span>
                            <div id="o-discount-row" style="font-size:var(--fs-xs);color:var(--color-success);display:none;">쿠폰 할인 -<span id="o-discount">0</span>원</div>
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
            <div class="card p-5">
                @include('order._coupon')
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <span class="text-muted" style="font-size:var(--fs-xs);">예상 금액</span>
                        <div id="o-discount-row" style="font-size:var(--fs-xs);color:var(--color-success);display:none;">쿠폰 할인 -<span id="o-discount">0</span>원</div>
                        <div class="text-ink font-display" style="font-size:var(--fs-xl);"><span id="o-total">0</span>원</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span id="o-stepind" class="text-muted-soft" style="font-size:var(--fs-xs);margin-right:4px;"></span>
                        <button type="button" id="o-prev" class="btn btn-secondary" style="display:none;">이전</button>
                        <button type="button" id="o-next" class="btn btn-primary">다음</button>
                        <button type="submit" id="o-submit" class="btn btn-primary" style="display:none;height:46px;padding:0 30px;">주문하기</button>
                    </div>
                </div>
            </div>
        @endif
    </form>
    </div>

    {{-- 이 상품에 대한 내 주문 접수 내역 — 최근 20건. 주문이 없어도 영역은 항상 표기 --}}
    @php
        $myOrders = $myOrders ?? collect();
        $orderStatuses = \App\Models\MarketingOrder::STATUSES;
        $orderStatusColor = ['pending' => 'var(--color-muted)', 'processing' => 'var(--color-accent)', 'completed' => 'var(--color-success)', 'canceled' => 'var(--color-error)'];
    @endphp
    <div class="rf-tabpane" data-tab="history" @if ($activeTab !== 'history') hidden @endif>
    <div class="card overflow-hidden">
        @if ($myOrders->isEmpty())
            <div class="text-center text-muted-soft" style="padding:36px 24px;font-size:var(--fs-xs);">
                아직 이 상품에 주문한 내역이 없습니다. "주문" 탭에서 첫 주문을 접수하면 진행 상태가 여기에 표시됩니다.
            </div>
        @else
            @php $fieldMap = $product->fields->keyBy('field_key'); @endphp
            <div style="overflow-x:auto;">
                <table class="w-full" style="min-width:640px;">
                    <thead>
                        <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                            <th class="text-center px-3 py-3 font-semibold" style="width:56px;">No</th>
                            <th class="text-left px-5 py-3 font-semibold" style="width:170px;">주문번호</th>
                            <th class="text-right px-3 py-3 font-semibold" style="width:120px;">수량 · 기간</th>
                            <th class="text-right px-3 py-3 font-semibold" style="width:120px;">금액</th>
                            <th class="text-center px-3 py-3 font-semibold" style="width:90px;">상태</th>
                            <th class="text-center px-3 py-3 font-semibold" style="width:110px;">순위</th>
                            <th class="text-right px-5 py-3 font-semibold" style="width:140px;">주문일시</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($myOrders as $o)
                            <tr style="border-top:1px solid var(--color-hairline-soft);">
                                {{-- No — 전체 건수 기준 내림차순(최신이 가장 큰 번호) --}}
                                <td class="px-3 py-3 text-center text-muted-soft font-mono" style="font-size:var(--fs-xs);">{{ ($myOrdersTotal ?? $myOrders->count()) - $loop->index }}</td>
                                <td class="px-5 py-3" style="font-size:var(--fs-xs);">
                                    <button type="button" class="rf-od-toggle text-accent font-medium hover:underline" data-target="od-{{ $o->id }}"
                                            style="background:none;border:0;padding:0;cursor:pointer;font-size:var(--fs-xs);font-family:inherit;"
                                            title="클릭하면 상세 주문 내역이 펼쳐집니다">{{ $o->order_no }}</button>
                                </td>
                                <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">
                                    {{ number_format($o->quantity) }}@if ($o->days) <span class="text-muted-soft">× {{ $o->days }}일</span>@endif
                                </td>
                                <td class="px-3 py-3 text-right text-ink font-medium" style="font-size:var(--fs-xs);">{{ number_format($o->total_price) }}원</td>
                                <td class="px-3 py-3 text-center">
                                    <span class="badge" style="font-size:var(--fs-xs);padding:2px 9px;color:{{ $orderStatusColor[$o->status] ?? 'var(--color-muted)' }};">{{ $orderStatuses[$o->status] ?? $o->status }}</span>
                                </td>
                                {{-- 순위 — 진행중 전환 시 자동 등록된 쇼핑 순위추적(2026-07-23). 리포트 새창 --}}
                                <td class="px-3 py-3 text-center" style="font-size:var(--fs-xs);">
                                    @if ($o->shopRankSlot)
                                        <a href="{{ $o->shopRankSlot->shareUrl() }}" target="_blank" rel="noopener" class="text-accent hover:underline font-mono" title="{{ $o->shopRankSlot->keyword }} 순위 리포트 새창">
                                            {{ $o->shopRankSlot->last_rank ? number_format($o->shopRankSlot->last_rank).'위 ↗' : '수집중 ↗' }}
                                        </a>
                                    @else
                                        <span class="text-muted-soft">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right text-muted-soft" style="font-size:var(--fs-xs);">{{ $o->created_at?->format('y.m.d H:i') }}</td>
                            </tr>
                            {{-- 상세 주문 내역 — 주문번호 클릭 시 펼침 --}}
                            <tr id="od-{{ $o->id }}" hidden>
                                <td colspan="7" class="px-5 py-4" style="background:var(--color-surface-soft);border-top:1px solid var(--color-hairline-soft);">
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
                                        @foreach (array_filter([
                                            ['단가', number_format($o->unit_price).'원'],
                                            ['수량', number_format($o->quantity).($o->days ? ' × '.$o->days.'일' : '')],
                                            (float) $o->discount_amount > 0 ? ['쿠폰 할인', '-'.number_format($o->discount_amount).'원'] : null,
                                            ['합계', number_format($o->total_price).'원'],
                                        ]) as [$lab, $val])
                                            <div>
                                                <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $lab }}</div>
                                                <div class="text-ink" style="font-size:var(--fs-xs);font-weight:600;">{{ $val }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                    @if (! empty($o->field_values))
                                        <div class="text-muted font-semibold mb-2" style="font-size:var(--fs-xs);">주문 입력 정보</div>
                                        <div class="flex flex-col gap-1">
                                            @foreach ($o->field_values as $key => $val)
                                                @php $f = $fieldMap->get($key); @endphp
                                                <div class="grid grid-cols-1 sm:grid-cols-4 gap-1" style="font-size:var(--fs-xs);">
                                                    <div class="text-muted-soft">{{ $f->label ?? $key }}</div>
                                                    <div class="sm:col-span-3 text-body" style="word-break:break-all;">
                                                        @if (is_null($val) || $val === '' || $val === [])
                                                            <span class="text-muted-soft">—</span>
                                                        @elseif (in_array($f->field_type ?? '', ['FILE', 'IMAGE'], true))
                                                            <a href="{{ asset('storage/'.$val) }}" target="_blank" rel="noopener" class="text-accent hover:underline">첨부 보기</a>
                                                        @elseif (is_array($val))
                                                            {{ implode(', ', array_map('strval', $val)) }}
                                                        @else
                                                            {{ $val }}
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    </div>
</section>

<style>
    #order-form .order-step { display: none; }
    #order-form .order-step.active { display: block; }
    #order-form [data-required].step-err .input,
    #order-form [data-required].step-err input { border-color: var(--color-error); }
    /* 주문 / 주문 내역 탭 — admin settings 와 동일 패턴 */
    .rf-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--color-hairline); margin-bottom: 20px; }
    .rf-tab { padding: 9px 18px; font-size: var(--fs-sm); font-weight: 600; color: var(--color-muted); background: none; border: 0; border-bottom: 2px solid transparent; margin-bottom: -1px; cursor: pointer; transition: color .12s ease, border-color .12s ease; }
    .rf-tab:hover { color: var(--color-ink); }
    .rf-tab.on { color: var(--color-primary); border-bottom-color: var(--color-primary); }
    .rf-tabpane[hidden] { display: none; }
</style>

<script>
// 주문 ↔ 주문 내역 탭 전환
document.querySelectorAll('.rf-tab').forEach(function (b) {
    b.addEventListener('click', function () {
        document.querySelectorAll('.rf-tab').forEach(function (x) { x.classList.toggle('on', x === b); });
        document.querySelectorAll('.rf-tabpane').forEach(function (p) { p.hidden = p.dataset.tab !== b.dataset.tab; });
    });
});
// 주문번호 클릭 → 상세 주문 내역 펼침/접힘
document.querySelectorAll('.rf-od-toggle').forEach(function (b) {
    b.addEventListener('click', function () {
        var row = document.getElementById(b.dataset.target);
        if (row) row.hidden = !row.hidden;
    });
});
</script>

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
    var couponEl = document.getElementById('o-coupon');
    var dcRow = document.getElementById('o-discount-row');
    var dcOut = document.getElementById('o-discount');
    var dcNote = document.getElementById('o-coupon-note');

    var fixedDays = parseInt(form.dataset.fixedDays, 10) || 0;   // 기간 고정 상품 — 종료일 자동, 일수는 항상 고정값

    function spanDays() {
        if (!daily) return 1;   // 전체 수량 과금(total): 기간과 무관하게 단가 × 수량
        if (fixedDays) return fixedDays;
        if (startEl && endEl && startEl.value && endEl.value) {
            var s = new Date(startEl.value), e = new Date(endEl.value);
            var d = Math.floor((e - s) / 86400000) + 1;
            return d > 0 ? d : 1;
        }
        if (daysEl) return parseInt(daysEl.value, 10) || 1;
        return 1;
    }
    // 기간 고정 + 시작/종료일 필드 — 시작일 선택 시 종료일 자동(시작 + 고정일 - 1)
    if (fixedDays && startEl && endEl) {
        startEl.addEventListener('input', function () {
            if (!startEl.value) { endEl.value = ''; return; }
            var d = new Date(startEl.value);
            d.setDate(d.getDate() + fixedDays - 1);
            endEl.value = d.toISOString().slice(0, 10);
            calc();
        });
    }
    // 쿠폰 할인 미리보기 — 서버 Coupon::discountFor 와 동일 규칙(최종 금액은 서버가 재계산)
    //   반환 -1 = 최소 주문 금액 미달(할인 0 + 경고 표시)
    function couponDiscount(gross) {
        if (!couponEl || !couponEl.value) return 0;
        var o = couponEl.options[couponEl.selectedIndex];
        var min = parseFloat(o.dataset.min) || 0;
        if (gross <= 0 || gross < min) return -1;
        var d;
        if (o.dataset.type === 'percent') {
            d = gross * ((parseFloat(o.dataset.value) || 0) / 100);
            var mx = parseFloat(o.dataset.max);
            if (!isNaN(mx) && mx > 0) d = Math.min(d, mx);
        } else {
            d = parseFloat(o.dataset.value) || 0;
        }
        return Math.min(Math.floor(d), Math.floor(gross));
    }
    function calc() {
        var q = parseInt(qtyEl && qtyEl.value, 10) || 0;
        var gross = unit * q * spanDays();
        var d = couponDiscount(gross);
        var short = d === -1;
        if (short) d = 0;
        if (dcRow) dcRow.style.display = d > 0 ? '' : 'none';
        if (dcOut) dcOut.textContent = d.toLocaleString('ko-KR');
        if (dcNote) dcNote.style.display = short ? '' : 'none';
        out.textContent = (gross - d).toLocaleString('ko-KR');
    }
    [qtyEl, startEl, endEl, daysEl, couponEl].forEach(function (el) { if (el) el.addEventListener('input', calc); });
    if (couponEl) couponEl.addEventListener('change', calc);
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
