@extends('admin.layout')
@section('page-title', $popup->exists ? '팝업 수정' : '새 팝업')

@section('page-actions')
    <a href="{{ route('admin.popups') }}" class="btn btn-secondary btn-sm">← 목록</a>
@endsection

@section('admin-content')
<x-console.page-head :title="$popup->exists ? '팝업 수정' : '새 팝업'" desc="대시보드 진입 팝업 구성 · 위치·크기·노출 기간을 지정합니다" />

<form method="POST" action="{{ $popup->exists ? route('admin.popups.update', $popup) : route('admin.popups.store') }}">
    @csrf
    @if ($popup->exists) @method('PUT') @endif

    <div class="card p-6">
        <div class="mb-4">
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">제목</label>
            <input type="text" name="title" class="input mt-1" style="width:100%;" value="{{ old('title', $popup->title) }}" placeholder="팝업 제목" required>
            @error('title')<p style="color:var(--color-error);font-size:var(--fs-xs);margin-top:4px;">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">노출 위치</label>
                <select name="position" class="input mt-1" style="width:100%;">
                    @foreach (\App\Models\Popup::POSITIONS as $val => $label)
                        <option value="{{ $val }}" {{ old('position', $popup->position) === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">너비(px)</label>
                <input type="number" name="width" class="input mt-1" style="width:100%;" min="240" max="900" value="{{ old('width', $popup->width ?? 420) }}">
            </div>
            <div class="flex items-end" style="padding-bottom:4px;">
                <label class="inline-flex items-center gap-2" style="font-size:var(--fs-xs);">
                    <span class="rf-switch"><input type="checkbox" name="is_active" value="1" {{ old('is_active', $popup->is_active ?? true) ? 'checked' : '' }}><span class="rf-track"></span></span> 노출
                </label>
            </div>
            <div class="flex items-end" style="padding-bottom:4px;">
                <label class="inline-flex items-center gap-2" style="font-size:var(--fs-xs);">
                    <span class="rf-switch"><input type="checkbox" name="dismissible" value="1" {{ old('dismissible', $popup->dismissible ?? true) ? 'checked' : '' }}><span class="rf-track"></span></span> 하루 안 보기
                </label>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">노출 시작 (선택)</label>
                <input type="datetime-local" name="starts_at" class="input mt-1" style="width:100%;" value="{{ old('starts_at', optional($popup->starts_at)->format('Y-m-d\TH:i')) }}">
            </div>
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">노출 종료 (선택)</label>
                <input type="datetime-local" name="ends_at" class="input mt-1" style="width:100%;" value="{{ old('ends_at', optional($popup->ends_at)->format('Y-m-d\TH:i')) }}">
            </div>
        </div>

        <div>
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">내용</label>
            <div class="mt-1">
                @include('admin.partials.editor', ['name' => 'body', 'value' => old('body', $popup->body), 'height' => 220])
            </div>
            @error('body')<p style="color:var(--color-error);font-size:var(--fs-xs);margin-top:4px;">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="flex justify-end gap-2 mt-4">
        <a href="{{ route('admin.popups') }}" class="btn btn-secondary">취소</a>
        <button type="submit" class="btn btn-primary">{{ $popup->exists ? '수정 저장' : '등록' }}</button>
    </div>
</form>
@endsection
