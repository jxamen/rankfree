{{-- 매체(벤더) 목록 — 단가·처리 능력·배분이 매체마다 다르다(design-04 §2-1) --}}
@extends('admin.layout')
@section('title', '제휴 매체 관리')

@section('admin-content')
<x-console.page-head title="제휴 매체 관리"
    desc="참여를 공급하는 제휴 채널 · 매체마다 지급 단가와 처리 능력이 달라 배분 비율을 따로 겁니다" />

@if (session('status'))
    <div class="alert alert-success mb-4" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif

<div class="flex items-center justify-between mb-3">
    <span class="text-muted" style="font-size:var(--fs-xs);">오늘({{ $day }}) 기준 참여 집계</span>
    <a href="{{ route('admin.reward.media.create') }}" class="btn btn-primary btn-sm">제휴 매체 등록</a>
</div>

<div class="card" style="overflow-x:auto;">
    <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
        <thead>
            <tr style="background:var(--color-surface-soft);">
                <th class="text-left" style="padding:10px 12px;">매체</th>
                <th class="text-left" style="padding:10px 12px;width:110px;">유형</th>
                <th class="text-left" style="padding:10px 12px;width:150px;">API 키</th>
                <th class="text-right" style="padding:10px 12px;width:110px;">지급 단가</th>
                <th class="text-right" style="padding:10px 12px;width:100px;">초당 한도</th>
                <th class="text-left" style="padding:10px 12px;width:100px;">검증</th>
                <th class="text-right" style="padding:10px 12px;width:90px;">배분 규칙</th>
                <th class="text-right" style="padding:10px 12px;width:90px;">오늘 참여</th>
                <th class="text-center" style="padding:10px 12px;width:90px;">상태</th>
                <th style="width:70px;"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($media as $m)
                <tr style="border-top:1px solid var(--color-hairline-soft);">
                    <td style="padding:10px 12px;">
                        <div class="text-ink font-semibold">{{ $m->name }}</div>
                        <div class="text-muted" style="font-family:var(--font-mono);">{{ $m->slug }}</div>
                    </td>
                    <td style="padding:10px 12px;">{{ $m->type === 'miniapp' ? '미니앱' : '벤더 API' }}</td>
                    <td style="padding:10px 12px;">
                        @if ($m->api_key_prefix)
                            <div style="font-family:var(--font-mono);">{{ $m->api_key_prefix }}…</div>
                            <div class="text-muted">{{ $m->api_key_last_used_at ? $m->api_key_last_used_at->format('m-d H:i').' 사용' : '미사용' }}</div>
                        @else
                            <span class="text-muted">미발급</span>
                        @endif
                    </td>
                    <td class="text-right" style="padding:10px 12px;font-variant-numeric:tabular-nums;">
                        @if ($m->payout_unit_price > 0)
                            {{ number_format($m->payout_unit_price) }}원
                        @else
                            <span class="text-muted">미설정</span>
                        @endif
                    </td>
                    <td class="text-right" style="padding:10px 12px;font-variant-numeric:tabular-nums;">{{ number_format($m->rate_limit_rps) }}</td>
                    <td style="padding:10px 12px;">{{ $m->verify_mode === 'server' ? '랭크프리' : '매체 자체' }}</td>
                    <td class="text-right" style="padding:10px 12px;font-variant-numeric:tabular-nums;">
                        @if (($allocCounts[$m->id] ?? 0) > 0)
                            {{ $allocCounts[$m->id] }}건
                        @else
                            <span class="text-muted">제한 없음</span>
                        @endif
                    </td>
                    <td class="text-right" style="padding:10px 12px;font-variant-numeric:tabular-nums;">{{ number_format($todayUsed[$m->id] ?? 0) }}</td>
                    <td class="text-center" style="padding:10px 12px;">
                        <form method="POST" action="{{ route('admin.reward.media.toggle', $m) }}" style="display:inline;">
                            @csrf
                            <button type="submit" class="badge {{ $m->is_active ? 'badge-success' : '' }}"
                                style="border:0;cursor:pointer;font-size:var(--fs-xs);">{{ $m->is_active ? '활성' : '중지' }}</button>
                        </form>
                    </td>
                    <td class="text-right" style="padding:10px 12px;">
                        <a href="{{ route('admin.reward.media.edit', $m) }}" class="btn btn-secondary btn-sm">설정</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-muted text-center" style="padding:28px;">
                    등록된 제휴 매체가 없습니다. <b class="text-ink">제휴 매체 등록</b>으로 추가하면 API 키가 함께 발급됩니다.
                </td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<p class="text-muted mt-3" style="font-size:var(--fs-xs);line-height:1.8;">
    <b class="text-ink">지급 단가</b>는 참여 1건당 매체에 지급하는 금액입니다(지출 계산 입력값).
    미션 유형마다 금액이 다르면 각 매체의 설정에서 <b class="text-ink">미션 유형별 지급 단가</b>를 걸 수 있고, 유형별 행이 없으면 이 기본 단가가 쓰입니다.
    <b class="text-ink">배분 규칙</b>이 없으면 그 매체는 제한 없이 공유 풀에서 가져갑니다 —
    단가가 비싼 매체가 물량을 다 가져가지 않게 하려면 비율이나 상한을 거세요.
</p>
@endsection
