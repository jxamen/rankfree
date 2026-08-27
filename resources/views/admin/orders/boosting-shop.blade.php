@extends('admin.layout')
@section('page-title', '부스팅샵 주문')

@section('admin-content')
@php
    // 전송값 초안 — 다시 그릴 때(검증 실패)는 방금 입력한 값을 우선한다
    $v = fn ($k) => old($k, $draft[$k] ?? '');
@endphp
<x-console.page-head title="부스팅샵 주문"
    desc="주문 {{ $order->order_no }} 을(를) 부스팅샵 플레이스 주문으로 접수합니다 · 전송값을 확인·보완한 뒤 보내세요" />

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">
        @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

@if (! $configured)
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">
        부스팅샵 API 키가 설정되지 않았습니다 — 운영 서버 <b>.env</b> 의 <b>BOOSTINGSHOP_API_KEY</b> 를 채우고 <b>config:cache</b> 를 다시 실행하세요.
    </div>
@endif

@if ($sentDispatch)
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-success) 8%,var(--color-canvas));color:var(--color-success);font-size:var(--fs-xs);">
        이미 부스팅샵으로 접수된 주문입니다 — {{ $sentDispatch->response }}<br>
        다시 넣으려면 <a href="{{ route('admin.orders.show', $order) }}" class="underline">주문 상세</a>의 외부 발주 현황에서 이 발주를 취소하세요.
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    {{-- 전송값 --}}
    <form method="POST" action="{{ route('admin.orders.boosting-shop.place', $order) }}" class="card p-6 lg:col-span-2 flex flex-col gap-4"
          data-confirm="부스팅샵으로 주문할까요?" data-confirm-text="접수되면 부스팅샵 적립금이 차감됩니다. 전송값을 다시 한 번 확인하세요." data-confirm-ok="주문">
        @csrf
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">부스팅샵 전송값</div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">부스팅샵 상품번호 <span style="color:var(--color-error);">*</span></span>
                <input name="product_no" value="{{ $v('product_no') }}" required inputmode="numeric" class="input font-mono" style="font-size:var(--fs-xs);">
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">47~50 유입 · 52~56 저장 (프리미엄 50은 스마트콜 URL 필수)</span>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">상호명 <span style="color:var(--color-error);">*</span></span>
                <input name="product_name" value="{{ $v('product_name') }}" required class="input" style="font-size:var(--fs-xs);" placeholder="플레이스에 등록된 업체명">
            </label>
        </div>

        <label class="flex flex-col gap-1">
            <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">플레이스 주소 <span style="color:var(--color-error);">*</span></span>
            <input name="link" value="{{ $v('link') }}" required type="url" class="input" style="font-size:var(--fs-xs);" placeholder="https://m.place.naver.com/place/1011101134/home">
        </label>

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">순위체크 키워드 <span style="color:var(--color-error);">*</span></span>
                <input name="keyword" value="{{ $v('keyword') }}" required class="input" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">추가 키워드 2</span>
                <input name="keyword2" value="{{ $v('keyword2') }}" class="input" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">추가 키워드 3</span>
                <input name="keyword3" value="{{ $v('keyword3') }}" class="input" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">플레이스 고유번호(pid)</span>
                <input name="pid" value="{{ $v('pid') }}" inputmode="numeric" class="input font-mono" style="font-size:var(--fs-xs);">
            </label>
        </div>

        <label class="flex flex-col gap-1">
            <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">유입 키워드 <span style="color:var(--color-error);">*</span></span>
            <textarea name="search_keywords" rows="5" required class="input" style="font-size:var(--fs-xs);line-height:1.6;">{{ $v('search_keywords') }}</textarea>
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">한 줄에 하나씩(쉼표도 가능) · 1~30개 — 미션 참여자가 검색할 키워드입니다</span>
        </label>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">1일 수량 <span style="color:var(--color-error);">*</span></span>
                <input name="day_quantity" value="{{ $v('day_quantity') }}" required inputmode="numeric" class="input font-mono" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">시작일 <span style="color:var(--color-error);">*</span></span>
                <input name="fr_date" value="{{ $v('fr_date') }}" required type="date" class="input font-mono" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">종료일 <span style="color:var(--color-error);">*</span></span>
                <input name="to_date" value="{{ $v('to_date') }}" required type="date" class="input font-mono" style="font-size:var(--fs-xs);">
            </label>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">스마트콜 URL</span>
                <input name="smartcall_url" value="{{ $v('smartcall_url') }}" type="url" class="input" style="font-size:var(--fs-xs);" placeholder="프리미엄(50) 상품 필수">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">업체 전화번호</span>
                <input name="place_tel" value="{{ $v('place_tel') }}" class="input" style="font-size:var(--fs-xs);">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">대표 사진 URL</span>
                <input name="image_url" value="{{ $v('image_url') }}" type="url" class="input" style="font-size:var(--fs-xs);">
            </label>
        </div>

        <div class="flex items-center gap-2 justify-end" style="border-top:1px solid var(--color-hairline-soft);padding-top:14px;">
            <a href="{{ route('admin.orders') }}" class="btn btn-ghost btn-sm">취소</a>
            <button type="submit" class="btn btn-primary btn-sm" @disabled(! $configured)>부스팅샵으로 주문</button>
        </div>
    </form>

    {{-- 주문 원본 — 전송값이 주문과 맞는지 눈으로 대조 --}}
    <div class="card p-6 flex flex-col gap-3" style="height:fit-content;">
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">주문 정보</div>
        <div class="flex flex-col gap-2" style="font-size:var(--fs-xs);">
            <div class="flex justify-between gap-2"><span class="text-muted">주문번호</span>
                <a href="{{ route('admin.orders.show', $order) }}" class="text-accent hover:underline font-mono">{{ $order->order_no }} ↗</a></div>
            <div class="flex justify-between gap-2"><span class="text-muted">상품</span><span class="text-ink text-right">{{ $order->product?->title ?? '(삭제된 상품)' }}</span></div>
            <div class="flex justify-between gap-2"><span class="text-muted">주문자</span><span class="text-ink text-right">{{ $order->orderer_name }} · {{ $order->orderer_contact }}</span></div>
            <div class="flex justify-between gap-2"><span class="text-muted">수량 · 기간</span>
                <span class="text-ink font-mono">{{ number_format($order->quantity) }}@if ($order->days) × {{ $order->days }}일 @endif</span></div>
            <div class="flex justify-between gap-2"><span class="text-muted">상태</span><span class="text-ink">{{ \App\Models\MarketingOrder::STATUSES[$order->status] ?? $order->status }}</span></div>
        </div>

        @if ($order->product?->fields->isNotEmpty())
            <div class="text-muted font-semibold mt-2" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);padding-top:10px;">주문 입력값</div>
            <div class="flex flex-col gap-2" style="font-size:var(--fs-xs);">
                @foreach ($order->product->fields->where('is_active', true) as $f)
                    @php $fval = $order->field_values[$f->field_key] ?? null; @endphp
                    <div class="flex justify-between gap-3">
                        <span class="text-muted" style="white-space:nowrap;">{{ $f->label }}</span>
                        <span class="text-ink text-right" style="word-break:break-all;">{{ is_array($fval) ? implode(', ', $fval) : ($fval ?: '—') }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="text-muted-soft mt-2" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);padding-top:10px;">
            접수되면 부스팅샵 <b class="text-muted">적립금이 차감</b>되고 주문이 <b class="text-muted">진행중</b>으로 바뀝니다.
            결과는 주문 상세의 <b class="text-muted">외부 발주 현황</b>에 부스팅샵 주문번호와 함께 남습니다.
        </p>
    </div>
</div>
@endsection
