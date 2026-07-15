@extends('console.layout')
@section('page-title', '문의 상세')
@section('crumb-title', $qna->title)

@section('page-actions')
    <a href="{{ route('console.qna') }}" class="btn btn-secondary btn-sm">← 내역</a>
@endsection

@section('console-content')
{{-- 게시판 방식 — 제목 헤더 / 본문 / 답변을 한 카드에, 폭은 콘솔 공통(제한 없음) --}}
<div class="card overflow-hidden">
    {{-- 제목 헤더 --}}
    <div class="px-6 py-5 border-b border-hairline-soft" style="background:var(--color-surface-soft);">
        <h1 class="text-ink font-display" style="font-size:var(--fs-xl);">{{ $qna->title }}@if ($qna->is_secret) 🔒@endif</h1>
        <div class="flex items-center gap-2 flex-wrap mt-2">
            <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $qna->category }}</span>
            @if ($qna->isAnswered())
                <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);">답변완료</span>
            @else
                <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;background:color-mix(in srgb,var(--color-warning) 16%,var(--color-canvas));color:var(--color-warning);">답변대기</span>
            @endif
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $qna->created_at->format('Y-m-d H:i') }}</span>
        </div>
    </div>

    {{-- 본문 --}}
    <div class="px-6 py-6 text-body" style="font-size:var(--fs-sm);line-height:1.8;white-space:pre-wrap;">{{ $qna->body }}</div>

    {{-- 답변 --}}
    @if ($qna->isAnswered())
        <div class="px-6 py-6 border-t border-hairline-soft" style="background:color-mix(in srgb,var(--color-success) 5%,var(--color-canvas));">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">💬 운영자 답변</span>
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ optional($qna->answered_at)->format('Y-m-d H:i') }}</span>
            </div>
            <div class="text-body" style="font-size:var(--fs-sm);line-height:1.85;">{!! $qna->answer !!}</div>
        </div>
    @else
        <div class="px-6 py-4 border-t border-hairline-soft text-muted" style="font-size:var(--fs-xs);">
            아직 답변이 등록되지 않았습니다. 확인 후 순차적으로 답변드리겠습니다.
        </div>
    @endif
</div>

{{-- 하단 액션 — 목록은 우측 끝 --}}
<div class="flex items-center justify-between mt-4">
    <form method="POST" action="{{ route('console.qna.destroy', $qna) }}" onsubmit="return confirm('이 문의를 삭제할까요?');">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">문의 삭제</button>
    </form>
    <a href="{{ route('console.qna') }}" class="btn btn-secondary btn-sm">목록</a>
</div>
@endsection
