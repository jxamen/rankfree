@extends('layouts.auth')
@section('title', '회원가입')

@section('auth-content')
    <h1 class="font-display text-ink text-center" style="font-size:var(--fs-xl);">무료로 시작하기</h1>
    <p class="text-muted text-center mt-1" style="font-size:var(--fs-xs);">순위 추적 100개 무료 · 카드 불필요</p>

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
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">또는 이메일로 가입</span>
        <span style="flex:1;height:1px;background:var(--color-hairline);"></span>
    </div>

    <form method="POST" action="{{ route('register') }}" class="flex flex-col gap-4">
        @csrf
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">이메일</label>
            <input name="email" type="email" class="input" value="{{ old('email') }}" required autofocus>
        </div>
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">비밀번호</label>
            <input name="password" type="password" class="input" required minlength="8">
        </div>
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">이름</label>
            <input name="name" type="text" class="input" value="{{ old('name') }}" required>
        </div>
        @include('auth._phone-verify')
        {{-- 추천인: 입력칸 없음 — 추천 링크(/register?ref=CODE)로 진입하면 백엔드에서 자동 처리 --}}
        <button type="submit" class="btn btn-primary btn-lg mt-1">무료로 시작</button>
    </form>
@endsection

@section('auth-footer')
    이미 회원이세요? <a href="{{ route('login') }}" class="text-ink font-semibold">로그인</a>
@endsection
