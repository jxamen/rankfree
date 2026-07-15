@extends('layouts.auth')
@section('robots', 'noindex') {{-- 계정 유틸 페이지 — 색인 불필요 --}}
@section('title', '비밀번호 찾기')

@section('auth-content')
    <h1 class="font-display text-ink text-center" style="font-size:var(--fs-xl);">비밀번호 재설정</h1>
    <p class="text-muted text-center mt-1" style="font-size:var(--fs-xs);">가입한 이메일로 재설정 링크를 보내드려요</p>

    @if (session('status'))
        <div class="mt-5 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-success) 10%,#fff);color:var(--color-success);font-size:var(--fs-xs);">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mt-5 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:var(--fs-xs);">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 flex flex-col gap-4">
        @csrf
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">이메일</label>
            <input name="email" type="email" class="input" value="{{ old('email') }}" required autofocus>
        </div>
        <button type="submit" class="btn btn-primary btn-lg mt-1">재설정 링크 받기</button>
    </form>

    <p class="text-muted-soft text-center mt-4" style="font-size:var(--fs-xs);">
        구글·카카오로 가입하셨다면 소셜 로그인을 사용하시거나, 여기서 비밀번호를 새로 설정할 수 있어요.
    </p>
@endsection

@section('auth-footer')
    <a href="{{ route('login') }}" class="text-ink font-semibold">← 로그인으로</a>
@endsection
