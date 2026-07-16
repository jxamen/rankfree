@extends('admin.layout')
@section('page-title', $banner->exists ? '배너 수정' : '새 배너')

@section('page-actions')
    <a href="{{ route('admin.banners') }}" class="btn btn-secondary btn-sm">← 목록</a>
@endsection

@section('admin-content')
<x-console.page-head :title="$banner->exists ? '배너 수정' : '새 배너'" desc="대시보드 상단 홍보 배너 구성 · 노출 순서·기간을 지정합니다" />

<form method="POST" action="{{ $banner->exists ? route('admin.banners.update', $banner) : route('admin.banners.store') }}" enctype="multipart/form-data">
    @csrf
    @if ($banner->exists) @method('PUT') @endif

    <div class="card p-6">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">유형</label>
                <select name="type" class="input mt-1" style="width:100%;">
                    @foreach (\App\Models\Banner::TYPES as $val => $label)
                        <option value="{{ $val }}" {{ old('type', $banner->type) === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">노출 순서</label>
                <input type="number" name="sort_order" class="input mt-1" style="width:100%;" value="{{ old('sort_order', $banner->sort_order ?? 0) }}">
            </div>
            <div class="flex items-end" style="padding-bottom:4px;">
                <label class="inline-flex items-center gap-2" style="font-size:var(--fs-xs);">
                    <span class="rf-switch"><input type="checkbox" name="is_active" value="1" {{ old('is_active', $banner->is_active ?? true) ? 'checked' : '' }}><span class="rf-track"></span></span> 노출
                </label>
            </div>
        </div>

        <div class="mb-4">
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">제목</label>
            <input type="text" name="title" class="input mt-1" style="width:100%;" value="{{ old('title', $banner->title) }}" placeholder="배너 제목" required>
            @error('title')<p style="color:var(--color-error);font-size:var(--fs-xs);margin-top:4px;">{{ $message }}</p>@enderror
        </div>
        <div class="mb-4">
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">부제 / 설명</label>
            <input type="text" name="subtitle" class="input mt-1" style="width:100%;" value="{{ old('subtitle', $banner->subtitle) }}" placeholder="한 줄 설명(선택)">
        </div>

        {{-- 배너 이미지 — 파일 업로드 또는 URL 직접 입력. 이미지가 있으면 배경 이미지로 표시 --}}
        <div class="mb-4">
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">배너 이미지 (선택)</label>
            @if ($banner->image_url)
                <div class="mt-2 mb-2"><img src="{{ $banner->image_url }}" alt="현재 이미지" style="max-height:88px;border-radius:8px;border:1px solid var(--color-hairline);"></div>
            @endif
            <input type="file" name="image_file" accept="image/*" class="mt-1 block" style="font-size:var(--fs-xs);">
            <input type="text" name="image_url" class="input mt-2" style="width:100%;" value="{{ old('image_url', $banner->image_url) }}" placeholder="또는 이미지 URL 직접 입력 (없으면 배경색 사용)">
            <p class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">이미지 파일 업로드 또는 URL 입력. 이미지가 있으면 배경 이미지로 표시됩니다. (jpg·png·gif·webp, 최대 4MB)</p>
            @error('image_file')<p style="color:var(--color-error);font-size:var(--fs-xs);margin-top:4px;">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">링크 URL (선택)</label>
                <input type="text" name="link_url" class="input mt-1" style="width:100%;" value="{{ old('link_url', $banner->link_url) }}" placeholder="https://…">
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">버튼 문구 (선택)</label>
                <input type="text" name="link_label" class="input mt-1" style="width:100%;" value="{{ old('link_label', $banner->link_label) }}" placeholder="자세히 보기">
            </div>
            <div class="flex gap-4">
                <div>
                    <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">배경색</label>
                    <input type="color" name="bg_color" class="mt-1" style="width:56px;height:40px;border:1px solid var(--color-hairline);border-radius:8px;background:none;" value="{{ old('bg_color', $banner->bg_color ?: '#111111') }}">
                </div>
                <div>
                    <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">글자색</label>
                    <input type="color" name="text_color" class="mt-1" style="width:56px;height:40px;border:1px solid var(--color-hairline);border-radius:8px;background:none;" value="{{ old('text_color', $banner->text_color ?: '#ffffff') }}">
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">노출 시작 (선택)</label>
                <input type="datetime-local" name="starts_at" class="input mt-1" style="width:100%;" value="{{ old('starts_at', optional($banner->starts_at)->format('Y-m-d\TH:i')) }}">
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">노출 종료 (선택)</label>
                <input type="datetime-local" name="ends_at" class="input mt-1" style="width:100%;" value="{{ old('ends_at', optional($banner->ends_at)->format('Y-m-d\TH:i')) }}">
            </div>
        </div>
    </div>

    <div class="flex justify-end gap-2 mt-4">
        <a href="{{ route('admin.banners') }}" class="btn btn-secondary">취소</a>
        <button type="submit" class="btn btn-primary">{{ $banner->exists ? '수정 저장' : '등록' }}</button>
    </div>
</form>
@endsection
