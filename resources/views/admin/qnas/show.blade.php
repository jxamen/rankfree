@extends('admin.layout')
@section('page-title', '문의 답변')

@section('page-actions')
    <a href="{{ route('admin.qnas') }}" class="btn btn-secondary btn-sm">← 목록</a>
@endsection

@section('admin-content')
<x-console.page-head title="문의 답변" desc="사용자 문의 내용 확인·답변 등록 · 답변 시 답변완료로 표시됩니다" />

<div>
    {{-- 문의 --}}
    <div class="card p-6 mb-4">
        <div class="flex items-center gap-2 flex-wrap mb-3">
            <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $qna->category }}</span>
            @if ($qna->is_secret)<span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">🔒 비밀글</span>@endif
            @if ($qna->isAnswered())
                <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);">답변완료</span>
            @else
                <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;background:color-mix(in srgb,var(--color-warning) 16%,var(--color-canvas));color:var(--color-warning);">미답변</span>
            @endif
        </div>
        <h2 class="text-ink font-display" style="font-size:var(--fs-lg);">{{ $qna->title }}</h2>
        <div class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">{{ $qna->user?->name }} · {{ $qna->user?->email }} · {{ $qna->created_at->format('Y-m-d H:i') }}</div>
        <div class="mt-4 text-body" style="font-size:var(--fs-sm);line-height:1.8;white-space:pre-wrap;">{{ $qna->body }}</div>
    </div>

    {{-- 답변 --}}
    <div class="card p-6">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">답변</div>
        @if ($qna->isAnswered())
            <div class="card-soft p-4 mb-4">
                <div class="text-body" style="font-size:var(--fs-sm);line-height:1.8;">{!! $qna->answer !!}</div>
                <div class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">{{ $qna->answerer?->name ?? '운영자' }} · {{ optional($qna->answered_at)->format('Y-m-d H:i') }}</div>
            </div>
        @endif
        <form method="POST" action="{{ route('admin.qnas.answer', $qna) }}">
            @csrf
            @include('admin.partials.editor', ['name' => 'answer', 'value' => old('answer', $qna->answer), 'height' => 200, 'placeholder' => '답변 내용을 입력하세요…'])
            @error('answer')<p style="color:var(--color-error);font-size:var(--fs-xs);margin-top:4px;">{{ $message }}</p>@enderror
            <div class="flex justify-end mt-4">
                <button type="submit" class="btn btn-primary">{{ $qna->isAnswered() ? '답변 수정' : '답변 등록' }}</button>
            </div>
        </form>
    </div>

    {{-- 삭제(별도 폼 — 폼 중첩 방지) --}}
    <div class="mt-4 text-right">
        <form method="POST" action="{{ route('admin.qnas.destroy', $qna) }}" onsubmit="return confirm('이 문의를 삭제할까요?');">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">문의 삭제</button>
        </form>
    </div>
</div>
@endsection
