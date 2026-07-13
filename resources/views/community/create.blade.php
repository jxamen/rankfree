@extends('layouts.site')
@section('title', '글쓰기 · 커뮤니티 · 랭크프리')
@section('follow-theme', '1')

@section('content')
<section class="container-page py-10 lg:py-14" style="max-width:720px;">
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
                    <option value="{{ $cat->id }}" @selected(old('category_id', $selected ? optional($categories->firstWhere('slug', $selected))->id : null) == $cat->id)>{{ $cat->icon }} {{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-4">
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">제목</label>
            <input name="title" value="{{ old('title') }}" class="input" maxlength="150" placeholder="제목을 입력하세요" required>
        </div>
        <div class="mb-5">
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">내용</label>
            <textarea name="body" class="input" style="height:220px;padding:12px 14px;line-height:1.7;" maxlength="20000" placeholder="내용을 입력하세요" required>{{ old('body') }}</textarea>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary">등록</button>
            <a href="{{ route('community') }}" class="btn btn-secondary">취소</a>
        </div>
    </form>
</section>
@endsection
