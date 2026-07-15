@extends('console.layout')
@section('page-title', '상담 리드')

@section('page-actions')
    <a href="{{ route('console.leads.export', request()->only('q', 'status')) }}" class="btn btn-secondary btn-sm">
        <i class="fa-solid fa-download"></i> CSV 내보내기
    </a>
@endsection

@section('console-content')
@php $statusColor = ['new' => 'var(--color-accent)', 'contacted' => 'var(--color-warning)', 'done' => 'var(--color-success)', 'spam' => 'var(--color-muted)']; @endphp

<x-console.page-head title="상담 리드">
    <x-slot:desc>분석 리포트 「순위 상승 문의하기」 등으로 접수된 상담 리드(슈퍼어드민 전용) · 총 <b>{{ number_format($rows->total()) }}</b>건@foreach (\App\Models\MarketingLead::STATUSES as $sk => $sl) · {{ $sl }} {{ (int) ($counts[$sk] ?? 0) }}@endforeach</x-slot:desc>
</x-console.page-head>

{{-- 상태 필터(좌) + 검색(우) — 카드 --}}
<form method="GET" class="card p-3 mb-4">
    <div class="flex items-center flex-wrap gap-2">
        <select name="status" class="input" style="height:36px;width:auto;font-size:var(--fs-xs);padding:0 10px;">
            <option value="">전체 상태</option>
            @foreach (\App\Models\MarketingLead::STATUSES as $sk => $sl)
                <option value="{{ $sk }}" @selected($status === $sk)>{{ $sl }}</option>
            @endforeach
        </select>
        @if ($q !== '' || $status !== '')
            <a href="{{ route('console.leads') }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>
        @endif
        <input name="q" value="{{ $q }}" class="input" style="width:260px;font-size:var(--fs-xs);margin-left:auto;" placeholder="성함·연락처·키워드·관심">
    </div>
</form>

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:920px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);">
                    <th class="text-left px-5 py-3 font-semibold">접수일</th>
                    <th class="text-left px-3 py-3 font-semibold">성함 / 연락처</th>
                    <th class="text-left px-3 py-3 font-semibold">키워드 · 관심</th>
                    <th class="text-left px-3 py-3 font-semibold">유입</th>
                    <th class="text-left px-3 py-3 font-semibold">메시지</th>
                    <th class="text-right px-5 py-3 font-semibold">상태</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $lead)
                    <tr style="border-top:1px solid var(--color-hairline-soft);{{ $lead->status === 'spam' ? 'opacity:.5;' : '' }}">
                        <td class="px-5 py-3 text-muted-soft" style="font-size:var(--fs-xs);white-space:nowrap;">{{ $lead->created_at->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-3">
                            <div class="text-ink font-medium" style="font-size:var(--fs-xs);">{{ $lead->name }}</div>
                            <div class="text-muted" style="font-size:var(--fs-xs);font-family:var(--font-mono);">{{ $lead->phone }}</div>
                        </td>
                        <td class="px-3 py-3" style="max-width:220px;">
                            @if ($lead->keyword)<div class="text-body truncate" style="font-size:var(--fs-xs);">{{ $lead->keyword }}</div>@endif
                            @if ($lead->interest)<div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $lead->interest }}</div>@endif
                            @if ($lead->meta['prep_months'] ?? null)
                                <div class="text-muted-soft" style="font-size:var(--fs-xs);">준비 {{ implode('·', array_map(fn ($m) => $m.'월', (array) $lead->meta['prep_months'])) }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);white-space:nowrap;">{{ $lead->sourceLabel() }}</td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);max-width:260px;">
                            <span class="truncate block" title="{{ $lead->message }}">{{ $lead->message ?: '—' }}</span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <form method="POST" action="{{ route('console.leads.status', $lead) }}" class="inline-flex items-center gap-1">
                                @csrf @method('PUT')
                                <select name="status" class="input" style="height:30px;width:auto;font-size:var(--fs-xs);padding:0 8px;color:{{ $statusColor[$lead->status] ?? 'var(--color-ink)' }};" onchange="this.form.submit()">
                                    @foreach (\App\Models\MarketingLead::STATUSES as $sk => $sl)
                                        <option value="{{ $sk }}" @selected($lead->status === $sk)>{{ $sl }}</option>
                                    @endforeach
                                </select>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center" style="padding:56px 20px;color:var(--color-muted);">
                        <div style="font-size:var(--fs-2xl);opacity:.4;">📮</div>
                        <p class="mt-2" style="font-size:var(--fs-xs);">접수된 상담 리드가 없습니다.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $rows->links() }}</div>
@endsection
