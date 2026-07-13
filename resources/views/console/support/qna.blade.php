@extends('console.layout')
@section('page-title', '1:1 문의')

@section('page-actions')
    <a href="{{ route('console.qna.create') }}" class="btn btn-primary btn-sm">+ 문의하기</a>
@endsection

@section('console-content')
<div class="card overflow-hidden">
    @forelse ($qnas as $qna)
        <a href="{{ route('console.qna.show', $qna) }}" class="flex items-center gap-3 px-5 hover:bg-surface-soft transition" style="border-top:{{ $loop->first ? '0' : '1px solid var(--color-hairline-soft)' }};min-height:56px;">
            {{-- No — 최신이 큰 번호(내림차순), 페이지네이션 반영 --}}
            <span class="text-muted-soft text-center" style="font-size:var(--fs-xs);width:36px;flex-shrink:0;">{{ $qnas->total() - ($qnas->firstItem() - 1) - $loop->index }}</span>
            @if ($qna->isAnswered())
                <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;flex-shrink:0;background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);">답변완료</span>
            @else
                <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;flex-shrink:0;background:color-mix(in srgb,var(--color-warning) 16%,var(--color-canvas));color:var(--color-warning);">답변대기</span>
            @endif
            <span class="text-muted" style="font-size:var(--fs-xs);flex-shrink:0;">{{ $qna->category }}</span>
            <span class="text-ink font-medium flex-1 truncate" style="font-size:var(--fs-sm);">{{ $qna->title }}@if ($qna->is_secret) 🔒@endif</span>
            <span class="text-muted-soft" style="font-size:var(--fs-xs);flex-shrink:0;">{{ $qna->created_at->format('Y-m-d') }}</span>
        </a>
    @empty
        <div class="text-center" style="padding:64px 24px;">
            <div style="font-size:var(--fs-2xl);opacity:.35;">💬</div>
            <p class="text-muted mt-3" style="font-size:var(--fs-sm);">아직 문의 내역이 없습니다</p>
            <p class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">궁금한 점을 남겨주시면 확인 후 답변드립니다.</p>
            <a href="{{ route('console.qna.create') }}" class="btn btn-primary btn-sm mt-4">문의하기</a>
        </div>
    @endforelse
</div>
<div class="mt-4">{{ $qnas->links() }}</div>
@endsection
