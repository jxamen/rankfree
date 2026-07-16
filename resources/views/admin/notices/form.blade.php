@extends('admin.layout')
@section('page-title', $notice->exists ? '공지사항 수정' : '새 공지사항')

@section('page-actions')
    <a href="{{ route('admin.notices') }}" class="btn btn-secondary btn-sm">← 목록</a>
@endsection

@section('admin-content')
<x-console.page-head :title="$notice->exists ? '공지사항 수정' : '새 공지사항'" desc="사용자 콘솔 공지사항 작성 · 게시/고정·카테고리를 설정합니다" />

<form method="POST" action="{{ $notice->exists ? route('admin.notices.update', $notice) : route('admin.notices.store') }}">
    @csrf
    @if ($notice->exists) @method('PUT') @endif

    <div class="card p-6">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">분류</label>
                <select name="category" class="input mt-1" style="width:100%;">
                    @foreach (\App\Models\Notice::CATEGORIES as $c)
                        <option value="{{ $c }}" {{ old('category', $notice->category) === $c ? 'selected' : '' }}>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">게시일</label>
                <input type="datetime-local" name="published_at" class="input mt-1" style="width:100%;"
                       value="{{ old('published_at', optional($notice->published_at)->format('Y-m-d\TH:i')) }}">
            </div>
            <div class="flex items-end gap-5" style="padding-bottom:4px;">
                <label class="inline-flex items-center gap-2" style="font-size:var(--fs-xs);">
                    <span class="rf-switch"><input type="checkbox" name="is_pinned" value="1" {{ old('is_pinned', $notice->is_pinned) ? 'checked' : '' }}><span class="rf-track"></span></span> 상단 고정
                </label>
                <label class="inline-flex items-center gap-2" style="font-size:var(--fs-xs);">
                    <span class="rf-switch"><input type="checkbox" name="is_published" value="1" {{ old('is_published', $notice->is_published ?? true) ? 'checked' : '' }}><span class="rf-track"></span></span> 게시
                </label>
            </div>
        </div>

        <div class="mb-4">
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">제목</label>
            <input type="text" name="title" class="input mt-1" style="width:100%;" value="{{ old('title', $notice->title) }}" placeholder="공지 제목" required>
            @error('title')<p style="color:var(--color-error);font-size:var(--fs-xs);margin-top:4px;">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">내용</label>
            <div class="mt-1">
                @include('admin.partials.editor', ['name' => 'body', 'value' => old('body', $notice->body), 'height' => 280])
            </div>
            @error('body')<p style="color:var(--color-error);font-size:var(--fs-xs);margin-top:4px;">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="flex justify-end gap-2 mt-4">
        <a href="{{ route('admin.notices') }}" class="btn btn-secondary">취소</a>
        <button type="submit" class="btn btn-primary">{{ $notice->exists ? '수정 저장' : '등록' }}</button>
    </div>
</form>
@endsection
