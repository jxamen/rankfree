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

@if (session('status'))
    <div class="card-soft px-4 py-3 mb-4 text-muted" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4" style="max-width:1040px;">
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
            <form method="POST" action="{{ route('admin.orders.destroy', $order) }}" class="mt-3" onsubmit="return confirm('이 주문을 삭제할까요?')">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-ghost btn-sm w-full" style="color:var(--color-error);">주문 삭제</button>
            </form>
        </div>
    </div>
</div>
@endsection
