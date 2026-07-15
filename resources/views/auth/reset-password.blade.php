@extends('layouts.auth')
@section('robots', 'noindex') {{-- 계정 유틸 페이지 — 색인 불필요 --}}
@section('title', '비밀번호 재설정')

@section('auth-content')
    <h1 class="font-display text-ink text-center" style="font-size:var(--fs-xl);">새 비밀번호 설정</h1>
    <p class="text-muted text-center mt-1" style="font-size:var(--fs-xs);">사용할 새 비밀번호를 입력해 주세요</p>

    @if ($errors->any())
        <div class="mt-5 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:var(--fs-xs);">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 flex flex-col gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">이메일</label>
            <input name="email" type="email" class="input" value="{{ old('email', $email) }}" required>
        </div>
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">새 비밀번호</label>
            <input name="password" type="password" class="input" required minlength="8">
        </div>
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">새 비밀번호 확인</label>
            <input name="password_confirmation" type="password" class="input" required minlength="8">
        </div>
        <button type="submit" class="btn btn-primary btn-lg mt-1">비밀번호 변경</button>
    </form>
@endsection

@section('auth-footer')
    <a href="{{ route('login') }}" class="text-ink font-semibold">← 로그인으로</a>
@endsection
