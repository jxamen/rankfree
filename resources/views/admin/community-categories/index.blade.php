@extends('admin.layout')
@section('page-title', '커뮤니티 카테고리')

@section('page-actions')
    <a href="{{ route('admin.community-seeds') }}" class="btn btn-secondary btn-sm">글밥 관리</a>
    <a href="{{ route('community') }}" target="_blank" class="btn btn-secondary btn-sm">커뮤니티 보기 ↗</a>
@endsection

@section('admin-content')
<p class="text-muted mb-4" style="font-size:var(--fs-xs);">
    커뮤니티 게시판의 카테고리를 추가·수정·정렬합니다. 사용을 끄면 목록·글쓰기에서 숨겨집니다.
    <b>slug</b>는 주소(<code>?cat=slug</code>)에 쓰이며 비우면 자동 생성됩니다.
</p>

@if ($errors->any())
    <div class="card-soft px-4 py-3 mb-4" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

{{-- 추가 --}}
<div class="card p-5 mb-5">
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">카테고리 추가</div>
    <form method="POST" action="{{ route('admin.community-categories.store') }}" class="grid grid-cols-1 sm:grid-cols-[64px_1fr_140px] gap-3 items-end">
        @csrf
        <div>
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">아이콘</label>
            <input name="icon" class="input" maxlength="16" placeholder="💬" style="text-align:center;" value="{{ old('icon') }}">
        </div>
        <div>
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">이름</label>
            <input name="name" class="input" maxlength="60" placeholder="예: 자유게시판" value="{{ old('name') }}" required>
        </div>
        <div>
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">slug(선택)</label>
            <input name="slug" class="input" maxlength="40" placeholder="자동" value="{{ old('slug') }}">
        </div>
        <div class="sm:col-span-3">
            <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">설명(선택)</label>
            <input name="description" class="input" maxlength="200" placeholder="카테고리 소개 문구" value="{{ old('description') }}">
        </div>
        <div class="sm:col-span-3">
            <button type="submit" class="btn btn-primary">＋ 추가</button>
        </div>
    </form>
</div>

{{-- 목록 (행별 수정) --}}
<div class="flex flex-col gap-3">
    @forelse ($categories as $cat)
        <div class="card p-4" style="{{ $cat->is_active ? '' : 'opacity:.55;' }}">
            <form method="POST" action="{{ route('admin.community-categories.update', $cat) }}" class="grid grid-cols-1 sm:grid-cols-[64px_1fr_140px_84px_auto] gap-2 items-end">
                @csrf @method('PUT')
                <div>
                    <label class="text-muted-soft block mb-1" style="font-size:var(--fs-xs);">아이콘</label>
                    <input name="icon" class="input" maxlength="16" value="{{ $cat->icon }}" style="text-align:center;">
                </div>
                <div>
                    <label class="text-muted-soft block mb-1" style="font-size:var(--fs-xs);">이름</label>
                    <input name="name" class="input" maxlength="60" value="{{ $cat->name }}" required>
                </div>
                <div>
                    <label class="text-muted-soft block mb-1" style="font-size:var(--fs-xs);">slug</label>
                    <input name="slug" class="input" maxlength="40" value="{{ $cat->slug }}">
                </div>
                <div>
                    <label class="text-muted-soft block mb-1" style="font-size:var(--fs-xs);">정렬</label>
                    <input name="sort_order" type="number" min="0" max="9999" class="input" value="{{ $cat->sort_order }}">
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="badge" style="font-size:var(--fs-xs);padding:3px 9px;" title="이 카테고리의 글 수">글 {{ number_format($cat->posts_count) }}</span>
                    <button type="submit" class="btn btn-secondary btn-sm">저장</button>
                </div>
                <div class="sm:col-span-5">
                    <input name="description" class="input" maxlength="200" placeholder="설명(선택)" value="{{ $cat->description }}" style="font-size:var(--fs-xs);">
                </div>
            </form>
            <div class="flex items-center gap-2 mt-2 pt-2" style="border-top:1px solid var(--color-hairline-soft);">
                <form method="POST" action="{{ route('admin.community-categories.toggle', $cat) }}">
                    @csrf
                    <button type="submit" class="badge" style="font-size:var(--fs-xs);padding:2px 10px;cursor:pointer;{{ $cat->is_active ? 'background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);' : '' }}">{{ $cat->is_active ? 'ON · 사용중' : 'OFF · 숨김' }}</button>
                </form>
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">/community?cat={{ $cat->slug }}</span>
                <form method="POST" action="{{ route('admin.community-categories.destroy', $cat) }}" class="ml-auto" data-confirm="이 카테고리를 삭제할까요?" data-confirm-text="글이 있으면 삭제되지 않습니다.">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-muted-soft hover:text-error" style="font-size:var(--fs-xs);background:none;border:0;cursor:pointer;">삭제</button>
                </form>
            </div>
        </div>
    @empty
        <div class="card text-center text-muted-soft" style="padding:40px;font-size:var(--fs-xs);">카테고리가 없습니다. 위에서 추가하세요.</div>
    @endforelse
</div>
@endsection
