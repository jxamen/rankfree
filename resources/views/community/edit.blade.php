@extends('console.layout')
@section('title', '글 수정 · 커뮤니티 · 랭크프리')
@section('page-title', '글 수정')

@section('console-content')
<section class="py-10 lg:py-14" style="padding-left:0;padding-right:0;">
    <h1 class="font-display text-ink mb-5" style="font-size:clamp(22px,2.6vw,28px);">글 수정</h1>

    @if ($errors->any())
        <div class="card-soft px-4 py-3 mb-4" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('community.update', $post) }}" class="card p-6" enctype="multipart/form-data">
        @csrf @method('PUT')
        <div class="mb-4">
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">카테고리 <span class="text-muted-soft">(변경 시 이동)</span></label>
            <select name="category_id" class="input" required>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}" @selected(old('category_id', $post->category_id) == $cat->id)>{{ trim($cat->icon.' '.$cat->name) }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-4">
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">제목</label>
            <input name="title" value="{{ old('title', $post->title) }}" class="input" maxlength="150" placeholder="제목을 입력하세요" required>
        </div>
        <div class="mb-5">
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">내용</label>
            @include('admin.partials.editor', ['name' => 'body', 'value' => \App\Support\HtmlSanitizer::clean(old('body', $post->body)), 'height' => 360, 'placeholder' => '내용을 입력하세요…', 'uploadUrl' => route('upload.image')])
        </div>
        @include('community._attachment_input')
        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary">저장</button>
            <a href="{{ route('community.show', $post) }}" class="btn btn-secondary">취소</a>
        </div>
    </form>

    {{-- 등록된 첨부 — 삭제 form 이 중첩되면 안 되므로 수정 폼 바깥에 둔다(운영자만) --}}
    @if (auth()->user()?->isOperator() && $post->attachments->isNotEmpty())
        <div class="card p-6 mt-5">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">등록된 첨부파일 {{ $post->attachments->count() }}개</div>
            @foreach ($post->attachments as $file)
                <div class="flex items-center gap-3 py-2" style="border-top:{{ $loop->first ? '0' : '1px solid var(--color-hairline-soft)' }};">
                    <a href="{{ route('community.attachment.download', $file) }}" class="text-accent hover:underline" style="font-size:var(--fs-sm);">{{ $file->original_name }}</a>
                    <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $file->sizeLabel() }}</span>
                    <form method="POST" action="{{ route('community.attachment.destroy', $file) }}" class="ml-auto"
                          data-confirm="이 첨부파일을 삭제할까요?" data-confirm-text="삭제하면 되돌릴 수 없습니다.">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-muted-soft hover:text-error" style="font-size:var(--fs-xs);background:none;border:0;cursor:pointer;">삭제</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
</section>
@endsection
