@extends('admin.layout')
@section('page-title', '주문 상세')

@section('page-actions')
    <a href="{{ route('admin.orders') }}" class="btn btn-secondary btn-sm">← 목록</a>
@endsection

@section('admin-content')
@php
    $statusColor = ['pending' => 'var(--color-muted)', 'processing' => 'var(--color-accent)', 'completed' => 'var(--color-success)', 'canceled' => 'var(--color-error)'];
    $fieldMap = $order->product?->fields->keyBy('field_key') ?? collect();
    // 내부(숨김) 필드 — 고객에겐 안 보이고 외부 발주 전달에 쓰는 값(수집 자동 채움 + 수동 입력)
    $hiddenFields = $fieldMap->where('is_active', true)->where('is_hidden', true)->values();
    $hiddenKeys = $hiddenFields->pluck('field_key')->all();
@endphp

<x-console.page-head title="주문 상세" desc="주문 정보·입력 값 확인 및 진행 상태 변경" />

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

<div class="flex flex-col gap-4">
    {{-- 상단 1줄: 주문 정보(2/3) · 주문자+상태(1/3) — 이하 콘텐츠는 전체 폭 사용(2026-07-23) --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="card p-6 lg:col-span-2">
            {{-- 주문번호는 카드 제목으로(페이지 제목은 '주문 상세'만, 2026-07-23) --}}
            <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
                <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">주문 상세 — <span class="font-mono">{{ $order->order_no }}</span></div>
                <span class="badge" style="font-size:var(--fs-xs);padding:3px 12px;color:{{ $statusColor[$order->status] ?? 'var(--color-muted)' }};">{{ $statuses[$order->status] ?? $order->status }}</span>
            </div>
            {{-- 1줄: 상품 정보 (+ 주문 시작일·종료일 — 단가 좌측) --}}
            @php
                $ordStart = trim((string) ($order->field_values['start_date'] ?? ''));
                $ordEnd = trim((string) ($order->field_values['end_date'] ?? ''));
                $row1 = array_filter([
                    ['상품', $order->product?->title ?? '(삭제됨)'],
                    ['수량', number_format($order->quantity).($order->days ? ' × '.$order->days.'일' : '')],
                    $ordStart !== '' ? ['시작일', $ordStart] : null,
                    $ordEnd !== '' ? ['종료일', $ordEnd] : null,
                ]);
            @endphp
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach ($row1 as [$lab, $val])
                    <div>
                        <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $lab }}</div>
                        <div class="text-ink mt-0.5" style="font-size:var(--fs-sm);">{{ $val }}</div>
                    </div>
                @endforeach
            </div>
            {{-- 2줄(금액): 총 수량 → 단가 → 주문 금액(할인 전) → 쿠폰 할인 → 합계 (2026-07-23) --}}
            @php
                $hasDc = (float) $order->discount_amount > 0;
                $totalQty = $order->quantity * max(1, (int) ($order->days ?: 1));
            @endphp
            <div class="grid grid-cols-2 {{ $hasDc ? 'sm:grid-cols-5' : 'sm:grid-cols-4' }} gap-4 mt-4 pt-4" style="border-top:1px solid var(--color-hairline-soft);">
                @foreach (array_filter([
                    ['총 수량', number_format($totalQty)],
                    ['단가', number_format($order->unit_price).'원'],
                    ['주문 금액', number_format((float) $order->total_price + (float) $order->discount_amount).'원'],
                    $hasDc ? ['쿠폰 할인', '-'.number_format($order->discount_amount).'원'.($order->userCoupon?->coupon ? ' · '.$order->userCoupon->coupon->name : '')] : null,
                    ['합계', number_format($order->total_price).'원'],
                ]) as [$lab, $val])
                    <div>
                        <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $lab }}</div>
                        <div class="text-ink mt-0.5" style="font-size:var(--fs-sm);">{{ $val }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- 주문자 + 상태 변경(카드 통합, 2026-07-23) — 삭제 버튼은 상단 우측 --}}
        <div class="card p-6">
            <div class="flex items-center justify-between gap-2 mb-3">
                <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">주문자</div>
                <form method="POST" action="{{ route('admin.orders.destroy', $order) }}" data-confirm="이 주문을 삭제할까요?" data-confirm-text="발주 이력도 함께 삭제됩니다.">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">주문 삭제</button>
                </form>
            </div>
            <div class="text-ink" style="font-size:var(--fs-sm);">{{ $order->orderer_name }}</div>
            <div class="text-muted mt-0.5" style="font-size:var(--fs-xs);">{{ $order->orderer_contact }}</div>
            @if ($order->user)
                <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">회원: {{ $order->user->name }} ({{ $order->user->email }})</div>
            @else
                <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">비회원 주문</div>
            @endif
            <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">주문일시: {{ $order->created_at?->format('Y.m.d H:i') }}</div>

            <div class="mt-4 pt-4" style="border-top:1px solid var(--color-hairline-soft);">
                <div class="text-muted font-semibold mb-2" style="font-size:var(--fs-xs);">상태 변경</div>
                {{-- 선택 즉시 반영(저장 버튼 없음) --}}
                <form method="POST" action="{{ route('admin.orders.status', $order) }}">
                    @csrf @method('PUT')
                    <select name="status" class="input" style="width:100%;font-size:var(--fs-xs);" onchange="this.form.submit()">
                        @foreach ($statuses as $code => $label)
                            <option value="{{ $code }}" {{ $order->status === $code ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
        </div>
    </div>

    {{-- 승인 · 외부 발주(1회성 주문만) — 세부주문서 주문은 회차별 [발주]가 대체(2026-07-23) --}}
    @if ($order->items->isEmpty())
    <div class="card p-6">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">승인 · 외부 발주</div>
        @if (! empty($allocPreview))
            <div class="flex flex-col gap-1.5 mb-3">
                @foreach ($allocPreview as [$pv, $qty])
                    <div class="flex items-center justify-between" style="font-size:var(--fs-xs);">
                        <span class="text-body">{{ $pv->vendor->name }} <span class="text-muted-soft">({{ \App\Models\Vendor::CHANNELS[$pv->vendor->channel] }} · {{ $pv->alloc_type === 'ratio' ? $pv->alloc_value.'%' : '고정 '.number_format($pv->alloc_value) }})</span></span>
                        <span class="font-mono text-ink">{{ number_format($qty) }}</span>
                    </div>
                @endforeach
            </div>
            @php $hasActiveDispatch = $order->dispatches->where('status', '!=', 'canceled')->isNotEmpty(); @endphp
            @if (! $hasActiveDispatch)
                <form method="POST" action="{{ route('admin.orders.approve', $order) }}"
                      data-confirm="주문을 승인하고 발주할까요?" data-confirm-text="위 배분대로 각 업체에 즉시 전송됩니다." data-confirm-ok="{{ $order->dispatches->isEmpty() ? '승인 · 발주' : '다시 발주' }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm" style="min-width:220px;">{{ $order->dispatches->isEmpty() ? '승인 · 발주' : '다시 발주' }}</button>
                </form>
            @else
                <p class="text-muted-soft" style="font-size:var(--fs-xs);">발주 완료 — 결과는 "외부 발주 현황"에서 확인하세요. 다시 넣으려면 현황에서 발주를 <b class="text-ink">취소</b>한 뒤 재발주하세요.</p>
            @endif
        @else
            <p class="text-muted-soft" style="font-size:var(--fs-xs);">이 상품에 활성화된 업체 배분 설정이 없습니다. <a href="{{ $order->product ? route('admin.products.edit', $order->product) : '#' }}" class="text-accent hover:underline">상품 편집</a>에서 설정하세요.</p>
        @endif
    </div>
    @endif

        {{-- 주문 입력값(+쇼핑 유입키워드 수집 통합) + 내부 필드 — 1줄 2카드(2026-07-23) --}}
        <div class="grid grid-cols-1 {{ $hiddenFields->isNotEmpty() ? 'lg:grid-cols-2' : '' }} gap-4">
        <div class="card p-6">
            <div class="flex items-center justify-between gap-2 flex-wrap mb-4">
                <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">주문 입력 정보</div>
                @if ($order->shopKeywordAnalyses->isEmpty() && $order->shopKeywordSource())
                    <form method="POST" action="{{ route('admin.orders.shop-keyword', $order) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm">유입키워드 수집 요청</button>
                    </form>
                @endif
            </div>
            {{-- 쇼핑 유입키워드 수집 — 연결 분석 요약(카드 통합, 2026-07-23) --}}
            @if ($order->shopKeywordAnalyses->isNotEmpty())
                <div class="flex flex-col gap-2 mb-4 pb-1" style="border-bottom:1px solid var(--color-hairline-soft);">
                    @foreach ($order->shopKeywordAnalyses as $a)
                        <div class="flex items-center gap-3 flex-wrap" style="font-size:var(--fs-xs);padding-bottom:8px;">
                            <a href="{{ route('admin.shop-keyword.show', $a) }}" class="text-ink font-semibold">{{ $a->core_keyword }} ↗</a>
                            <span class="text-muted">노출 <b class="font-mono text-success">{{ number_format($a->exposed_count) }}</b> / 확인 <span class="font-mono">{{ number_format($a->checked_count) }}</span></span>
                            <span class="text-muted">Short URL <b class="font-mono">{{ $a->shortLinks->count() }}</b>개</span>
                            <span class="text-muted-soft">{{ $a->created_at->format('y.m.d H:i') }}</span>
                        </div>
                    @endforeach
                </div>
            @elseif ($order->shopKeywordSource())
                <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">유입키워드 수집 요청 시 주문의 키워드·상품 URL 로 노출 키워드 분석을 만들고 이 주문과 연결합니다 — 노출 키워드가 모이면 Short URL 을 생성해 발주에 씁니다.</p>
            @endif
            @if (empty($order->field_values))
                <p class="text-muted-soft" style="font-size:var(--fs-xs);">입력 항목이 없습니다.</p>
            @else
                <div class="flex flex-col gap-3">
                    @foreach ($order->field_values as $key => $val)
                        @continue(in_array($key, $hiddenKeys, true))
                        @php $f = $fieldMap->get($key); $label = $f->label ?? $key; $type = $f->field_type ?? 'TEXT'; @endphp
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-2" style="border-bottom:1px solid var(--color-hairline-soft);padding-bottom:10px;">
                            <div class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">{{ $label }}</div>
                            <div class="sm:col-span-3 text-body" style="font-size:var(--fs-xs);word-break:break-all;">
                                @if (is_null($val) || $val === '' || $val === [])
                                    <span class="text-muted-soft">—</span>
                                @elseif (is_array($val))
                                    {{ implode(', ', $val) }}
                                @elseif (in_array($type, ['FILE', 'IMAGE'], true))
                                    <a href="{{ \Illuminate\Support\Facades\Storage::url($val) }}" target="_blank" class="text-accent hover:underline">첨부파일 보기 ↗</a>
                                @elseif ($type === 'URL')
                                    <a href="{{ $val }}" target="_blank" class="text-accent hover:underline">{{ $val }}</a>
                                @else
                                    {{ $val }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- 내부(숨김) 필드 — 외부 발주 전달용. 유입키워드 수집값으로 자동 채움 + 수동 입력(2026-07-22) --}}
        @if ($hiddenFields->isNotEmpty())
        <div class="card p-6">
            <div class="flex items-center justify-between gap-2 flex-wrap mb-2">
                <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">내부 필드 <span class="text-muted-soft font-normal">(발주 전달용 · 고객에게 안 보임)</span></div>
                <div class="flex items-center gap-1.5">
                    @if ($order->shopKeywordAnalyses->isNotEmpty())
                        <form method="POST" action="{{ route('admin.orders.autofill', $order) }}">
                            @csrf
                            <button type="submit" class="btn btn-secondary btn-sm">수집값 다시 채우기</button>
                        </form>
                    @endif
                    <button type="submit" form="internal-fields-form" class="btn btn-primary btn-sm">저장</button>
                </div>
            </div>
            <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">유입키워드 수집(확장 상품정보)이 반영될 때 매핑된 값이 자동 저장됩니다. 비어 있는 항목은 직접 입력할 수 있습니다.</p>
            <form id="internal-fields-form" method="POST" action="{{ route('admin.orders.internal-fields', $order) }}" class="flex flex-col gap-3">
                @csrf
                @method('PUT')
                @foreach ($hiddenFields as $f)
                    @php $cur = $order->field_values[$f->field_key] ?? null; @endphp
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-2 items-center">
                        {{-- 타이틀 1줄 — 자동 채움 상세는 title 툴팁으로(2026-07-23) --}}
                        <div style="font-size:var(--fs-xs);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                             @if ($f->autofill_source) title="자동 채움: {{ \App\Models\ProductField::AUTOFILL_SOURCES[$f->autofill_source] ?? $f->autofill_source }}" @endif>
                            <span class="text-muted font-semibold">{{ $f->label }}</span>@if ($f->is_required)<span class="text-error">*</span>@endif
                        </div>
                        <div class="sm:col-span-3">
                            <input type="text" name="internal[{{ $f->field_key }}]" value="{{ is_array($cur) ? implode(', ', $cur) : $cur }}"
                                   class="input w-full" style="font-size:var(--fs-xs);" placeholder="{{ $f->autofill_source ? '수집 대기 중 — 직접 입력 가능' : '직접 입력' }}">
                        </div>
                    </div>
                @endforeach
            </form>
        </div>
        @endif
        </div>

        {{-- 세부주문서(일할, 2026-07-23) — 기간형 주문의 회차별 관리: 업체 분산·Short URL 순차·개별 발주/취소 --}}
        @if ($order->items->isNotEmpty())
            @php $itemColor = ['pending' => 'var(--color-muted)', 'sent' => 'var(--color-success)', 'failed' => 'var(--color-error)', 'canceled' => 'var(--color-muted-soft)']; @endphp
            <div class="card p-6 mb-6">
                <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                    <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">세부주문서
                        <span class="text-muted-soft" style="font-weight:400;">일 발주량(일수량×이행률)을 업체 비율로 분배 · 진행일 아침(09:00) 자동 전송</span></span>
                    <span class="flex items-center gap-1.5">
                        @if ($order->items->where('status', 'sent')->isEmpty())
                            <form method="POST" action="{{ route('admin.orders.items.generate', $order) }}"
                                  data-confirm="세부주문을 다시 생성할까요?" data-confirm-text="기존 세부주문(대기·실패·취소분)을 지우고 현재 이행률·업체 배분 기준으로 새로 만듭니다. 수동 수정한 URL·업체는 사라집니다." data-confirm-ok="재생성">
                                @csrf<input type="hidden" name="regenerate" value="1">
                                <button type="submit" class="btn btn-ghost btn-sm">재생성</button>
                            </form>
                        @endif
                        <button type="submit" form="items-bulk-form" class="btn btn-secondary btn-sm">세부주문 저장</button>
                    </span>
                </div>
                <form id="items-bulk-form" method="POST" action="{{ route('admin.orders.items.update', $order) }}">@csrf @method('PUT')</form>
                <div style="overflow-x:auto;">
                    <table class="w-full" style="min-width:960px;font-size:var(--fs-xs);border-collapse:collapse;">
                        <thead>
                            <tr class="text-muted" style="border-bottom:1px solid var(--color-hairline-soft);">
                                <th class="text-center py-2 pr-3 font-semibold" style="width:50px;">회차</th>
                                <th class="text-left py-2 px-3 font-semibold" style="width:145px;">시작일</th>
                                <th class="text-left py-2 px-3 font-semibold" style="width:145px;">종료일</th>
                                <th class="text-right py-2 px-3 font-semibold" style="width:95px;">수량</th>
                                <th class="text-left py-2 px-3 font-semibold">Short URL</th>
                                <th class="text-left py-2 px-3 font-semibold" style="width:150px;">업체</th>
                                <th class="text-center py-2 px-3 font-semibold" style="width:70px;">상태</th>
                                <th class="text-center py-2 pl-3 font-semibold" style="width:130px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($order->items as $it)
                                <tr style="border-top:1px solid var(--color-hairline-soft);">
                                    <td class="py-2 pr-3 text-center text-ink font-mono">{{ $it->day_no }}</td>
                                    <td class="py-2 px-3">
                                        <input form="items-bulk-form" type="date" name="items[{{ $it->id }}][work_date]" value="{{ $it->work_date?->format('Y-m-d') }}"
                                               class="input" style="width:100%;height:30px;font-size:var(--fs-xs);">
                                    </td>
                                    <td class="py-2 px-3">
                                        <input form="items-bulk-form" type="date" name="items[{{ $it->id }}][end_date]" value="{{ ($it->end_date ?? $it->work_date)?->format('Y-m-d') }}"
                                               class="input" style="width:100%;height:30px;font-size:var(--fs-xs);">
                                    </td>
                                    <td class="py-2 px-3">
                                        <input form="items-bulk-form" type="number" min="1" name="items[{{ $it->id }}][quantity]" value="{{ $it->quantity }}"
                                               class="input text-right" style="width:100%;height:30px;font-size:var(--fs-xs);">
                                    </td>
                                    <td class="py-2 px-3">
                                        <input form="items-bulk-form" name="items[{{ $it->id }}][short_url]" value="{{ $it->short_url }}"
                                               class="input" style="width:100%;height:30px;font-size:var(--fs-xs);" placeholder="미배정 — Short URL 생성 시 자동 배정">
                                    </td>
                                    <td class="py-2 px-3">
                                        <select form="items-bulk-form" name="items[{{ $it->id }}][vendor_id]" class="input" style="width:100%;height:30px;font-size:var(--fs-xs);">
                                            <option value="">자동(배분 1순위)</option>
                                            @foreach ($itemVendors as $v)
                                                <option value="{{ $v->id }}" @selected($it->vendor_id === $v->id)>{{ $v->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="py-2 px-3 text-center" style="font-size:var(--fs-xs);color:{{ $itemColor[$it->status] ?? 'var(--color-muted)' }};">{{ \App\Models\MarketingOrderItem::STATUSES[$it->status] ?? $it->status }}</td>
                                    <td class="py-2 pl-3 text-center" style="white-space:nowrap;">
                                        @if ($it->status !== 'sent')
                                            <button type="submit" form="item-d-{{ $it->id }}" class="btn btn-secondary btn-sm">{{ in_array($it->status, ['failed', 'canceled'], true) ? '재발주' : '발주' }}</button>
                                        @endif
                                        @if ($it->status !== 'canceled')
                                            <button type="submit" form="item-c-{{ $it->id }}" class="btn btn-ghost btn-sm" style="color:var(--color-error);">취소</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">첫 회차를 [발주]하면 주문이 진행중으로 바뀌고, 이후 회차는 진행일 아침(09:00) 자동 발주됩니다. 취소한 발주의 시트 행은 직접 정리하세요.</p>
            </div>
            {{-- 행별 발주/취소 폼 — 테이블 폼 중첩 방지를 위해 밖에 두고 버튼이 form 속성으로 참조(display:none — 카드 사이 공백 방지) --}}
            @foreach ($order->items as $it)
                <form id="item-d-{{ $it->id }}" method="POST" action="{{ route('admin.orders.items.dispatch', $it) }}" style="display:none;"
                      data-confirm="{{ $it->day_no }}일차를 지금 발주할까요?" data-confirm-text="배정 업체로 즉시 전송됩니다. 첫 발주 시 주문이 진행중으로 바뀌고 이후 회차는 진행일 아침 자동 발주됩니다." data-confirm-ok="발주">@csrf</form>
                <form id="item-c-{{ $it->id }}" method="POST" action="{{ route('admin.orders.items.cancel', $it) }}" style="display:none;"
                      data-confirm="{{ $it->day_no }}일차를 취소할까요?" data-confirm-text="전송 기록도 취소로 표시됩니다. 시트에 적힌 행은 자동으로 지워지지 않습니다." data-confirm-ok="세부주문 취소">@csrf</form>
            @endforeach
        @elseif (($order->product?->quantity_mode ?? '') === 'daily' && (int) $order->days >= 1)
            <div class="card p-6 mb-6">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div>
                        <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">세부주문서</span>
                        <p class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">기간 {{ $order->days }}일 주문입니다 — 세부주문을 만들면 회차별(1일 1건)로 업체 분산·Short URL 순차 배정·자동 발주로 관리됩니다.</p>
                    </div>
                    <form method="POST" action="{{ route('admin.orders.items.generate', $order) }}">@csrf
                        <button type="submit" class="btn btn-primary btn-sm">세부주문 {{ $order->days }}건 생성</button>
                    </form>
                </div>
            </div>
        @endif

        {{-- 외부 발주 현황 — 1회성 주문만(세부주문서 주문은 회차 상태가 대체, 2026-07-23 중복 제거) --}}
        @if ($order->items->isEmpty())
        <div class="card p-6">
            <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-sm);">외부 발주 현황</div>
            @if ($order->dispatches->isEmpty())
                <p class="text-muted-soft" style="font-size:var(--fs-xs);">
                    @if ($order->items->isNotEmpty())
                        아직 발주되지 않았습니다. 위 <b class="text-ink">세부주문서</b>에서 회차별 [발주]를 누르거나, 첫 발주 후 진행일 아침 자동 전송을 기다리세요.
                    @else
                        아직 발주되지 않았습니다. 우측 <b class="text-ink">[승인 · 발주]</b> 를 누르면 업체 배분 설정대로 자동 전송됩니다.
                    @endif
                </p>
            @else
                <div style="overflow-x:auto;">
                    <table class="w-full" style="min-width:560px;font-size:var(--fs-xs);border-collapse:collapse;">
                        <thead>
                            <tr class="text-muted" style="border-bottom:1px solid var(--color-hairline-soft);">
                                <th class="text-left py-2 pr-3 font-semibold">업체</th>
                                <th class="text-center py-2 px-3 font-semibold">채널</th>
                                <th class="text-right py-2 px-3 font-semibold">수량</th>
                                <th class="text-center py-2 px-3 font-semibold">상태</th>
                                <th class="text-left py-2 px-3 font-semibold">응답</th>
                                <th class="text-center py-2 pl-3 font-semibold" style="width:90px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($order->dispatches as $d)
                                <tr style="border-top:1px solid var(--color-hairline-soft);">
                                    <td class="py-2 pr-3 text-ink font-medium">{{ $d->vendor_name }}</td>
                                    <td class="py-2 px-3 text-center text-muted">{{ \App\Models\Vendor::CHANNELS[$d->channel] ?? $d->channel }}</td>
                                    <td class="py-2 px-3 text-right font-mono">{{ number_format($d->quantity) }}</td>
                                    <td class="py-2 px-3 text-center">
                                        <span class="badge" style="font-size:var(--fs-xs);color:{{ ['sent' => 'var(--color-success)', 'failed' => 'var(--color-error)'][$d->status] ?? 'var(--color-muted)' }};">{{ \App\Models\OrderDispatch::STATUSES[$d->status] ?? $d->status }}</span>
                                    </td>
                                    <td class="py-2 px-3 text-muted-soft" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $d->response }}">
                                        {{ $d->response ?: '—' }}
                                        <div style="font-size:var(--fs-xs);">{{ $d->sent_at?->format('m.d H:i') }}</div>
                                    </td>
                                    <td class="py-2 pl-3 text-center" style="white-space:nowrap;">
                                        @if (in_array($d->status, ['failed', 'pending'], true))
                                            <form method="POST" action="{{ route('admin.orders.dispatch.retry', $d) }}" class="inline">@csrf
                                                <button type="submit" class="btn btn-secondary btn-sm">재전송</button>
                                            </form>
                                        @endif
                                        @if ($d->status !== 'canceled')
                                            <form method="POST" action="{{ route('admin.orders.dispatch.cancel', $d) }}" class="inline"
                                                  data-confirm="이 발주를 취소할까요?" data-confirm-text="{{ $d->vendor_name }} — 전송 기록이 취소로 표시됩니다.{{ $d->channel === 'gsheet' ? ' 시트에 이미 추가된 행은 자동으로 지워지지 않으니 필요하면 직접 정리하세요.' : '' }} 모든 발주가 취소되면 다시 발주할 수 있습니다." data-confirm-ok="발주 취소">@csrf
                                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">취소</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @endif
</div>
@endsection
