@extends('admin.layout')
@section('page-title', $faq->exists ? 'FAQ 수정' : '새 FAQ')

@section('page-actions')
    <a href="{{ route('admin.faqs') }}" class="btn btn-secondary btn-sm">← 목록</a>
@endsection

@section('admin-content')
<x-console.page-head :title="$faq->exists ? 'FAQ 수정' : '새 FAQ'" desc="자주 묻는 질문 작성 · 카테고리·게시 여부를 설정합니다" />

<form method="POST" action="{{ $faq->exists ? route('admin.faqs.update', $faq) : route('admin.faqs.store') }}">
    @csrf
    @if ($faq->exists) @method('PUT') @endif

    <div class="card p-6">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">카테고리</label>
                <select name="category" class="input mt-1" style="width:100%;">
                    @foreach (\App\Models\Faq::CATEGORIES as $c)
                        <option value="{{ $c }}" {{ old('category', $faq->category) === $c ? 'selected' : '' }}>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">정렬 순서</label>
                <input type="number" name="sort_order" class="input mt-1" style="width:100%;" value="{{ old('sort_order', $faq->sort_order ?? 0) }}">
            </div>
            <div class="flex items-end" style="padding-bottom:4px;">
                <label class="inline-flex items-center gap-2" style="font-size:var(--fs-xs);">
                    <span class="rf-switch"><input type="checkbox" name="is_published" value="1" {{ old('is_published', $faq->is_published ?? true) ? 'checked' : '' }}><span class="rf-track"></span></span> 게시
                </label>
            </div>
        </div>

        <div class="mb-4">
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">질문</label>
            <input type="text" name="question" class="input mt-1" style="width:100%;" value="{{ old('question', $faq->question) }}" placeholder="자주 묻는 질문" required>
            @error('question')<p style="color:var(--color-error);font-size:var(--fs-xs);margin-top:4px;">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">답변</label>
            <div class="mt-1">
                @include('admin.partials.editor', ['name' => 'answer', 'value' => old('answer', $faq->answer), 'height' => 240])
            </div>
            @error('answer')<p style="color:var(--color-error);font-size:var(--fs-xs);margin-top:4px;">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="flex justify-end gap-2 mt-4">
        <a href="{{ route('admin.faqs') }}" class="btn btn-secondary">취소</a>
        <button type="submit" class="btn btn-primary">{{ $faq->exists ? '수정 저장' : '등록' }}</button>
    </div>
</form>
@endsection
