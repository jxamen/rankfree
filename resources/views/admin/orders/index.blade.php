@extends('admin.layout')
@section('page-title', '주문 관리')

@section('admin-content')
@php
    $statusColor = ['pending' => 'var(--color-muted)', 'processing' => 'var(--color-accent)', 'completed' => 'var(--color-success)', 'canceled' => 'var(--color-error)'];
    $total = $counts->sum();
@endphp
<x-console.page-head title="주문 관리" desc="셀프마케팅 상품 주문 접수·진행 상태 관리 · 상태별로 필터링할 수 있습니다" />

@if (session('status'))
    <div class="card-soft px-4 py-3 mb-4 text-muted" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

{{-- 상태 필터 pill --}}
<div class="flex items-center gap-2 mb-4 flex-wrap">
    <a href="{{ route('admin.orders', array_filter(['product' => $filters['product'], 'q' => $filters['q']])) }}"
       class="badge border border-hairline" style="font-size:var(--fs-xs);padding:5px 12px;{{ ! $filters['status'] ? 'background:var(--color-primary);color:var(--color-on-primary);' : '' }}">
        전체 <span style="opacity:.7;">{{ $total }}</span>
    </a>
    @foreach ($statuses as $code => $label)
        <a href="{{ route('admin.orders', array_filter(['status' => $code, 'product' => $filters['product'], 'q' => $filters['q']])) }}"
           class="badge border border-hairline" style="font-size:var(--fs-xs);padding:5px 12px;{{ $filters['status'] === $code ? 'background:var(--color-primary);color:var(--color-on-primary);' : '' }}">
            {{ $label }} <span style="opacity:.7;">{{ $counts[$code] ?? 0 }}</span>
        </a>
    @endforeach
</div>

{{-- 상품 필터(좌) + 검색(우) — 카드 --}}
<form method="GET" action="{{ route('admin.orders') }}" class="card p-3 mb-4">
    <div class="flex items-center flex-wrap gap-2">
        @if ($filters['status'])<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
        <select name="product" class="input" style="width:220px;font-size:var(--fs-xs);height:36px;" onchange="this.form.submit()">
            <option value="">전체 상품</option>
            @foreach ($products as $p)
                <option value="{{ $p->id }}" {{ (string) $filters['product'] === (string) $p->id ? 'selected' : '' }}>{{ $p->title }}</option>
            @endforeach
        </select>
        @if ($filters['q'] || $filters['product'])<a href="{{ route('admin.orders', array_filter(['status' => $filters['status']])) }}" class="btn btn-ghost btn-sm">초기화</a>@endif
        <input name="q" value="{{ $filters['q'] }}" placeholder="주문번호 · 주문자 · 연락처" class="input" style="width:260px;font-size:var(--fs-xs);margin-left:auto;">
    </div>
</form>

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:900px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-left px-5 py-3 font-semibold" style="width:56px;">No</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:150px;">주문번호</th>
                    <th class="text-left px-3 py-3 font-semibold">상품 · 키워드</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:150px;">주문자</th>
                    <th class="text-right px-3 py-3 font-semibold" style="width:130px;">수량 · 금액</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:80px;">상태</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:120px;">유입키워드</th>
                    <th class="text-right px-5 py-3 font-semibold" style="width:130px;">주문일시</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $o)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        {{-- No — 최신 주문이 가장 큰 번호(desc, 페이지 걸쳐 연속) --}}
                        <td class="px-5 py-3 text-muted font-mono" style="font-size:var(--fs-xs);">{{ $orders->total() - $orders->firstItem() + 1 - $loop->index }}</td>
                        <td class="px-3 py-3">
                            <a href="{{ route('admin.orders.show', $o) }}" class="text-ink font-medium hover:underline" style="font-size:var(--fs-xs);">{{ $o->order_no }}</a>
                        </td>
                        @php
                            // 수집 정보(2026-07-23) — 연결 분석 + 확장 수집 상품정보에서 상점명·가격·상품ID·이미지
                            $ska = $o->shopKeywordAnalyses->first();
                            $pi = $ska && $ska->product_id ? ($productInfos[$ska->user_id.'|'.$ska->product_id] ?? null) : null;
                            $dMall = $ska?->mall_name ?: $pi?->mall_name;
                            $dPrice = $ska?->product_price ?: $pi?->price;
                            $dPid = $ska?->product_id;
                            $dThumb = $pi?->thumbnail_url;
                        @endphp
                        <td class="px-3 py-3" style="font-size:var(--fs-xs);">
                            <div class="text-body">{{ $o->product?->title ?? '(삭제된 상품)' }}</div>
                            {{-- 주문 키워드(입력값에서 추출, 2026-07-22) --}}
                            @if ($kw = $o->keywordFromFields())
                                <div class="text-muted-soft">키워드: <b class="text-muted">{{ $kw }}</b></div>
                            @endif
                            {{-- 수집 완료 정보 — 상점명 · 가격 · 상품ID · 대표이미지(2026-07-23) --}}
                            @if ($dMall || $dPrice || $dPid || $dThumb)
                                <div class="flex items-center gap-2 mt-1 flex-wrap">
                                    @if ($dThumb)
                                        <a href="{{ $dThumb }}" target="_blank" rel="noopener nofollow"><img src="{{ $dThumb }}" alt="" style="width:28px;height:28px;object-fit:cover;border-radius:6px;border:1px solid var(--color-hairline);vertical-align:middle;"></a>
                                    @endif
                                    <span class="text-muted-soft">
                                        @if ($dMall)상점 <b class="text-muted">{{ $dMall }}</b>@endif
                                        @if ($dPrice) · <b class="text-muted font-mono">{{ number_format($dPrice) }}원</b>@endif
                                        @if ($dPid) · ID
                                            @if ($ska?->product_url)
                                                <a href="{{ $ska->product_url }}" target="_blank" rel="noopener nofollow" class="font-mono text-accent hover:underline" title="상품 페이지 새창 열기">{{ $dPid }} ↗</a>
                                            @else
                                                <span class="font-mono">{{ $dPid }}</span>
                                            @endif
                                        @endif
                                    </span>
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-3" style="font-size:var(--fs-xs);">
                            <div class="text-ink">{{ $o->orderer_name }}</div>
                            <div class="text-muted-soft">{{ $o->orderer_contact }}</div>
                        </td>
                        {{-- 수량·기간 + 금액 — 한 열 2줄(2026-07-22) --}}
                        <td class="px-3 py-3 text-right" style="font-size:var(--fs-xs);">
                            <div class="text-muted">{{ number_format($o->quantity) }}@if ($o->days) <span class="text-muted-soft">× {{ $o->days }}일</span>@endif</div>
                            <div class="text-ink font-medium">{{ number_format($o->total_price) }}원</div>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <span class="badge" style="font-size:var(--fs-xs);padding:2px 9px;color:{{ $statusColor[$o->status] ?? 'var(--color-muted)' }};">{{ $statuses[$o->status] ?? $o->status }}</span>
                        </td>
                        {{-- 쇼핑 유입키워드 — 수집요청 → 분석 링크 → 접수 상태면 '주문넣기'(매핑 업체 발주, 2026-07-23) --}}
                        <td class="px-3 py-3 text-center" style="font-size:var(--fs-xs);white-space:nowrap;">
                            @if ($a = $o->shopKeywordAnalyses->first())
                                <a href="{{ route('admin.shop-keyword.show', $a) }}" class="text-ink font-semibold" title="연결된 노출 키워드 분석 열기">
                                    노출 {{ number_format($a->exposed_count) }} ↗
                                </a>
                                @if ($o->status === 'pending')
                                    <form method="POST" action="{{ route('admin.orders.place', $o) }}" class="mt-1"
                                          data-confirm="매핑된 업체로 발주할까요?" data-confirm-text="{{ $o->order_no }} — 업체 배분 설정대로 자동 전송됩니다. 필수 값이 비어 있으면 발주되지 않습니다." data-confirm-ok="발주">
                                        @csrf
                                        <button type="submit" class="btn btn-primary btn-sm" style="height:26px;padding:0 10px;font-size:var(--fs-xs);">주문넣기</button>
                                    </form>
                                @endif
                            @elseif ($o->shopKeywordSource())
                                <form method="POST" action="{{ route('admin.orders.shop-keyword', $o) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm" style="height:26px;padding:0 10px;font-size:var(--fs-xs);">수집요청</button>
                                </form>
                            @else
                                <span class="text-muted-soft">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right text-muted-soft" style="font-size:var(--fs-xs);">{{ $o->created_at?->format('y.m.d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center" style="padding:40px;color:var(--color-muted);font-size:var(--fs-xs);">주문이 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $orders->links() }}</div>
@endsection
