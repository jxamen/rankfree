@extends('layouts.auth')
@section('title', '로그인')

@section('auth-content')
    <h1 class="font-display text-ink text-center" style="font-size:var(--fs-xl);">다시 오신 걸 환영해요</h1>
    <p class="text-muted text-center mt-1" style="font-size:var(--fs-xs);">순위 추적을 이어가세요</p>

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

    <div class="mt-6">
        @include('auth._social-buttons')
    </div>

    <div class="flex items-center gap-3 my-5">
        <span style="flex:1;height:1px;background:var(--color-hairline);"></span>
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">또는 이메일로 로그인</span>
        <span style="flex:1;height:1px;background:var(--color-hairline);"></span>
    </div>

    <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-4">
        @csrf
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">이메일</label>
            <input name="email" type="email" class="input" value="{{ old('email') }}" required autofocus>
        </div>
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">비밀번호</label>
            <input name="password" type="password" class="input" required>
        </div>
        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-muted" style="font-size:var(--fs-xs);">
                <input type="checkbox" name="remember"> 로그인 상태 유지
            </label>
            <a href="{{ route('password.request') }}" class="text-muted hover:text-ink transition" style="font-size:var(--fs-xs);">비밀번호 찾기</a>
        </div>
        <button type="submit" class="btn btn-primary btn-lg mt-1">로그인</button>
    </form>
@endsection

@section('auth-footer')
    <a href="{{ route('find-email') }}" class="text-muted hover:text-ink transition">아이디 찾기</a>
    <span class="text-muted-soft mx-1">·</span>
    아직 회원이 아니세요? <a href="{{ route('register') }}" class="text-ink font-semibold">무료로 시작</a>
@endsection
