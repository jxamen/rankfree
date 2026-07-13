@extends('layouts.auth')
@section('title', '로그인')

@section('auth-content')
    <h1 class="font-display text-ink text-center" style="font-size:var(--fs-xl);">다시 오신 걸 환영해요</h1>
    <p class="text-muted text-center mt-1" style="font-size:var(--fs-xs);">순위 추적을 이어가세요</p>

    @if ($errors->any())
        <div class="mt-5 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:var(--fs-xs);">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-6 flex flex-col gap-4">
        @csrf
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">이메일</label>
            <input name="email" type="email" class="input" value="{{ old('email') }}" required autofocus>
        </div>
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">비밀번호</label>
            <input name="password" type="password" class="input" required>
        </div>
        <label class="flex items-center gap-2 text-muted" style="font-size:var(--fs-xs);">
            <input type="checkbox" name="remember"> 로그인 상태 유지
        </label>
        <button type="submit" class="btn btn-primary btn-lg mt-1">로그인</button>
    </form>
@endsection

@section('auth-footer')
    아직 회원이 아니세요? <a href="{{ route('register') }}" class="text-ink font-semibold">무료로 시작</a>
@endsection
