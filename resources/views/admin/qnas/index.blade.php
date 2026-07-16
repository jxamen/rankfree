@extends('admin.layout')
@section('page-title', '1:1 문의 관리')

@section('admin-content')
<x-console.page-head title="1:1 문의" desc="사용자 1:1 문의 답변·상태 관리 · 미답변 문의를 우선 확인하세요" />

<div class="flex items-center gap-2 mb-4">
    @foreach ([['' => '전체'], ['pending' => '미답변'], ['answered' => '답변완료']] as $opt)
        @foreach ($opt as $val => $label)
            <a href="{{ route('admin.qnas', $val ? ['status' => $val] : []) }}"
               class="btn btn-sm {{ (string) $status === (string) $val ? 'btn-primary' : 'btn-secondary' }}">
                {{ $label }}@if ($val === 'pending' && $pendingCount) <span class="badge" style="font-size:var(--fs-xs);padding:1px 7px;margin-left:4px;">{{ $pendingCount }}</span>@endif
            </a>
        @endforeach
    @endforeach
</div>

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:820px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-left px-4 py-3 font-semibold" style="width:100px;">상태</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:120px;">분류</th>
                    <th class="text-left px-3 py-3 font-semibold">제목</th>
                    <th class="text-left px-3 py-3 font-semibold" style="width:160px;">작성자</th>
                    <th class="text-right px-4 py-3 font-semibold" style="width:130px;">작성일</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($qnas as $qna)
                    <tr style="border-top:1px solid var(--color-hairline-soft);cursor:pointer;" onclick="location.href='{{ route('admin.qnas.show', $qna) }}'">
                        <td class="px-4 py-3">
                            @if ($qna->isAnswered())
                                <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);">답변완료</span>
                            @else
                                <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;background:color-mix(in srgb,var(--color-warning) 16%,var(--color-canvas));color:var(--color-warning);">미답변</span>
                            @endif
                        </td>
                        <td class="px-3 py-3"><span class="text-muted" style="font-size:var(--fs-xs);">{{ $qna->category }}</span></td>
                        <td class="px-3 py-3">
                            <span class="text-ink font-medium" style="font-size:var(--fs-xs);">{{ $qna->title }}</span>
                            @if ($qna->is_secret)<span title="비밀글" style="margin-left:4px;">🔒</span>@endif
                        </td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $qna->user?->name }} <span class="text-muted-soft">{{ $qna->user?->email }}</span></td>
                        <td class="px-4 py-3 text-right text-muted-soft" style="font-size:var(--fs-xs);">{{ $qna->created_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted-soft" style="padding:48px;font-size:var(--fs-xs);">문의가 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $qnas->links() }}</div>
@endsection
