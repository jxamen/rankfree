@extends('admin.layout')
@section('page-title', '판매자정보')

@section('admin-content')
<x-console.page-head title="판매자정보" desc="판매자정보 캡차 통과 후 수집된 사업자 정보입니다 — 업체명·대표자·톡톡·전화번호·스토어. 수집은 <b>수집 상품</b> 화면에서 합니다." />

<div class="card p-4 mb-4">
    <form method="GET" action="{{ route('admin.seller-infos') }}" class="flex items-center gap-2" style="flex-wrap:wrap;">
        <input type="search" name="q" value="{{ $q }}" placeholder="업체명 · 대표자 · 전화번호 · 스토어ID" class="input flex-none"
               style="height:36px;width:280px;" autocomplete="off">
        <button type="submit" class="btn btn-primary btn-sm flex-none">검색</button>
        @if ($q !== '')
            <a href="{{ route('admin.seller-infos') }}" class="btn btn-secondary btn-sm flex-none">초기화</a>
        @endif
        <span class="text-muted ml-auto flex-none" style="font-size:var(--fs-xs);">
            <b class="font-mono text-ink">{{ number_format($total) }}</b>건
        </span>
    </form>
</div>

<div class="card">
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:var(--fs-sm);">
            <thead>
                <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                    <th style="padding:8px 6px;text-align:right;width:56px;">No</th>
                    <th style="padding:8px 6px;">업체명</th>
                    <th style="padding:8px 6px;width:120px;">대표자명</th>
                    <th style="padding:8px 6px;width:130px;">톡톡아이디</th>
                    <th style="padding:8px 6px;width:140px;">전화번호</th>
                    <th style="padding:8px 6px;">스토어명</th>
                    <th style="padding:8px 6px;width:84px;">수집일</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $si)
                    @php
                        $prod = $si->store_id ? ($prodMap[$si->store_id] ?? null) : null;
                        $talkId = $prod->talk_id ?? null;
                        $storeName = $prod->mall_name ?? ($si->store_id ?: null);
                        // 스토어 홈: 매칭된 상품 링크에서 도출한 홈 우선, 없으면 store_id 로 스마트스토어 기본
                        $storeHome = $prod->home ?? ($si->store_id ? 'https://smartstore.naver.com/'.$si->store_id : null);
                    @endphp
                    <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                        <td style="padding:7px 6px;text-align:right;" class="font-mono text-muted-soft">
                            {{ number_format($items->total() - ($items->firstItem() - 1) - $loop->index) }}
                        </td>
                        <td style="padding:7px 6px;">
                            <span class="text-ink font-semibold">{{ $si->biz_name ?: '—' }}</span>
                            @if ($si->address)
                                <div class="text-muted-soft" style="font-size:var(--fs-xs);margin-top:2px;">{{ $si->address }}</div>
                            @endif
                        </td>
                        <td style="padding:7px 6px;" class="text-ink">{{ $si->representative ?: '—' }}</td>
                        <td style="padding:7px 6px;">
                            @if ($talkId)
                                <a href="https://talk.naver.com/ct/{{ $talkId }}" target="_blank" rel="noopener"
                                   class="font-mono" style="color:var(--color-primary);text-decoration:none;" title="톡톡 열기">{{ $talkId }}</a>
                            @else
                                <span class="text-muted-soft">—</span>
                            @endif
                        </td>
                        <td style="padding:7px 6px;" class="font-mono text-muted">{{ $si->customer_phone ?: '—' }}</td>
                        <td style="padding:7px 6px;">
                            @if ($storeName && $storeHome)
                                <a href="{{ $storeHome }}" target="_blank" rel="noopener" class="text-ink font-medium"
                                   style="text-decoration:none;" title="{{ $storeHome }}">{{ $storeName }}</a>
                            @elseif ($storeName)
                                <span class="text-ink">{{ $storeName }}</span>
                            @else
                                <span class="text-muted-soft">—</span>
                            @endif
                            @if ($si->store_id && $storeName !== $si->store_id)
                                <span class="font-mono text-muted-soft" style="font-size:var(--fs-xs);"> · {{ $si->store_id }}</span>
                            @endif
                        </td>
                        <td style="padding:7px 6px;" class="text-muted-soft font-mono"
                            title="{{ $si->captured_at ? $si->captured_at->format('Y-m-d H:i') : '' }}">
                            {{ $si->captured_at ? $si->captured_at->format('m-d') : ($si->created_at?->format('m-d') ?: '—') }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted-soft text-center" style="padding:40px;">
                        수집된 판매자정보가 없습니다. <b>수집 상품</b> 화면에서 판매자정보를 수집해 주세요.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($items->hasPages())
    <div class="mt-4">{{ $items->links() }}</div>
@endif
@endsection
