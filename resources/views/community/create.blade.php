@extends('console.layout')
@php $__board = optional(($categories ?? collect())->firstWhere('slug', $selected))->name ?: '커뮤니티'; @endphp
@section('title', $__board.' 글쓰기 · 랭크프리')
@section('page-title', $__board)

@section('console-content')
<section class="py-10 lg:py-14" style="padding-left:0;padding-right:0;">
    <h1 class="font-display text-ink mb-5" style="font-size:clamp(22px,2.6vw,28px);">글쓰기</h1>

    @if ($errors->any())
        <div class="card-soft px-4 py-3 mb-4" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('community.store') }}" class="card p-6">
        @csrf
        <div class="mb-4">
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">카테고리</label>
            <select name="category_id" class="input" required>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}" @selected(old('category_id', $selected ? optional($categories->firstWhere('slug', $selected))->id : null) == $cat->id)>{{ trim($cat->icon.' '.$cat->name) }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-4">
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">제목</label>
            <input name="title" value="{{ old('title') }}" class="input" maxlength="150" placeholder="제목을 입력하세요" required>
        </div>
        <div class="mb-5">
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">내용</label>
            @include('admin.partials.editor', ['name' => 'body', 'value' => \App\Support\HtmlSanitizer::clean(old('body')), 'height' => 360, 'placeholder' => '내용을 입력하세요…', 'uploadUrl' => route('upload.image')])
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary">등록</button>
            <a href="{{ route('community') }}" class="btn btn-secondary">취소</a>
        </div>
    </form>
</section>
@endsection
