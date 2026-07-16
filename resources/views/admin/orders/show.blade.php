@extends('admin.layout')
@section('page-title', '주문 상세')

@section('page-actions')
    <a href="{{ route('admin.orders') }}" class="btn btn-secondary btn-sm">← 목록</a>
@endsection

@section('admin-content')
@php
    $statusColor = ['pending' => 'var(--color-muted)', 'processing' => 'var(--color-accent)', 'completed' => 'var(--color-success)', 'canceled' => 'var(--color-error)'];
    $fieldMap = $order->product?->fields->keyBy('field_key') ?? collect();
@endphp

<x-console.page-head :title="'주문 상세'.($order->order_no ? ' — '.$order->order_no : '')" desc="주문 정보·입력 값 확인 및 진행 상태 변경" />

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    {{-- 좌: 주문 정보 --}}
    <div class="lg:col-span-2 flex flex-col gap-4">
        <div class="card p-6">
            <div class="flex items-start justify-between flex-wrap gap-2 mb-4">
                <div>
                    <div class="text-muted-soft" style="font-size:var(--fs-xs);">주문번호</div>
                    <div class="text-ink font-display" style="font-size:var(--fs-lg);">{{ $order->order_no }}</div>
                </div>
                <span class="badge" style="font-size:var(--fs-xs);padding:3px 12px;color:{{ $statusColor[$order->status] ?? 'var(--color-muted)' }};">{{ $statuses[$order->status] ?? $order->status }}</span>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach ([
                    ['상품', $order->product?->title ?? '(삭제됨)'],
                    ['수량', number_format($order->quantity).($order->days ? ' × '.$order->days.'일' : '')],
                    ['단가', number_format($order->unit_price).'원'],
                    ['합계', number_format($order->total_price).'원'],
                ] as [$lab, $val])
                    <div>
                        <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $lab }}</div>
                        <div class="text-ink mt-0.5" style="font-size:var(--fs-sm);">{{ $val }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- 주문 입력값 (동적 필드) --}}
        <div class="card p-6">
            <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-sm);">주문 입력 정보</div>
            @if (empty($order->field_values))
                <p class="text-muted-soft" style="font-size:var(--fs-xs);">입력 항목이 없습니다.</p>
            @else
                <div class="flex flex-col gap-3">
                    @foreach ($order->field_values as $key => $val)
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

        {{-- 외부 발주 현황 — 승인 시 업체 배분대로 전송된 기록 --}}
        <div class="card p-6">
            <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-sm);">외부 발주 현황</div>
            @if ($order->dispatches->isEmpty())
                <p class="text-muted-soft" style="font-size:var(--fs-xs);">아직 발주되지 않았습니다. 우측 <b class="text-ink">[승인 · 발주]</b> 를 누르면 업체 배분 설정대로 자동 전송됩니다.</p>
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
                                    <td class="py-2 pl-3 text-center">
                                        @if ($d->status !== 'sent')
                                            <form method="POST" action="{{ route('admin.orders.dispatch.retry', $d) }}" class="inline">@csrf
                                                <button type="submit" class="btn btn-secondary btn-sm">재전송</button>
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
    </div>

    {{-- 우: 주문자 + 상태 관리 --}}
    <div class="flex flex-col gap-4">
        <div class="card p-6">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">주문자</div>
            <div class="text-ink" style="font-size:var(--fs-sm);">{{ $order->orderer_name }}</div>
            <div class="text-muted mt-0.5" style="font-size:var(--fs-xs);">{{ $order->orderer_contact }}</div>
            @if ($order->user)
                <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">회원: {{ $order->user->name }} ({{ $order->user->email }})</div>
            @else
                <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">비회원 주문</div>
            @endif
            <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">주문일시: {{ $order->created_at?->format('Y.m.d H:i') }}</div>
        </div>

        {{-- 승인 · 외부 발주 --}}
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
                @if ($order->dispatches->isEmpty())
                    <form method="POST" action="{{ route('admin.orders.approve', $order) }}"
                          data-confirm="주문을 승인하고 발주할까요?" data-confirm-text="위 배분대로 각 업체에 즉시 전송됩니다." data-confirm-ok="승인 · 발주">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm w-full">승인 · 발주</button>
                    </form>
                @else
                    <p class="text-muted-soft" style="font-size:var(--fs-xs);">발주 완료 — 결과는 좌측 "외부 발주 현황"에서 확인하세요.</p>
                @endif
            @else
                <p class="text-muted-soft" style="font-size:var(--fs-xs);">이 상품에 활성화된 업체 배분 설정이 없습니다. <a href="{{ $order->product ? route('admin.products.edit', $order->product) : '#' }}" class="text-accent hover:underline">상품 편집</a>에서 설정하세요.</p>
            @endif
        </div>

        <div class="card p-6">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">상태 변경</div>
            <form method="POST" action="{{ route('admin.orders.status', $order) }}">
                @csrf @method('PUT')
                <select name="status" class="input" style="width:100%;font-size:var(--fs-xs);">
                    @foreach ($statuses as $code => $label)
                        <option value="{{ $code }}" {{ $order->status === $code ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary btn-sm w-full mt-3">상태 저장</button>
            </form>
            <form method="POST" action="{{ route('admin.orders.destroy', $order) }}" class="mt-3" data-confirm="이 주문을 삭제할까요?" data-confirm-text="발주 이력도 함께 삭제됩니다.">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-ghost btn-sm w-full" style="color:var(--color-error);">주문 삭제</button>
            </form>
        </div>
    </div>
</div>
@endsection
