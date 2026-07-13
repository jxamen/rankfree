@extends('console.layout')
@section('page-title', '1:1 문의하기')

@section('page-actions')
    <a href="{{ route('console.qna') }}" class="btn btn-secondary btn-sm">← 내역</a>
@endsection

@section('console-content')
<form method="POST" action="{{ route('console.qna.store') }}" style="max-width:760px;">
    @csrf
    <div class="card p-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">문의 유형</label>
                <select name="category" class="input mt-1" style="width:100%;">
                    @foreach (\App\Models\Qna::CATEGORIES as $c)
                        <option value="{{ $c }}" {{ old('category', $qna->category) === $c ? 'selected' : '' }}>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end" style="padding-bottom:6px;">
                <label class="inline-flex items-center gap-2" style="font-size:var(--fs-xs);">
                    <span class="rf-switch"><input type="checkbox" name="is_secret" value="1" {{ old('is_secret') ? 'checked' : '' }}><span class="rf-track"></span></span> 비밀글 (나와 운영자만 열람)
                </label>
            </div>
        </div>

        <div class="mb-4">
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">제목</label>
            <input type="text" name="title" class="input mt-1" style="width:100%;" value="{{ old('title') }}" placeholder="문의 제목" required>
            @error('title')<p style="color:var(--color-error);font-size:var(--fs-xs);margin-top:4px;">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;">문의 내용</label>
            <textarea name="body" class="input mt-1" style="width:100%;min-height:200px;padding:12px 14px;font-size:var(--fs-sm);line-height:1.7;" placeholder="문의하실 내용을 자세히 적어주세요." required>{{ old('body') }}</textarea>
            @error('body')<p style="color:var(--color-error);font-size:var(--fs-xs);margin-top:4px;">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="flex justify-end gap-2 mt-4">
        <a href="{{ route('console.qna') }}" class="btn btn-secondary">취소</a>
        <button type="submit" class="btn btn-primary">문의 등록</button>
    </div>
</form>
@endsection
