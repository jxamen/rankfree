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

    <form method="POST" action="{{ route('register') }}" class="mt-6 flex flex-col gap-4">
        @csrf
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">이름</label>
            <input name="name" type="text" class="input" value="{{ old('name') }}" required autofocus>
        </div>
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">이메일</label>
            <input name="email" type="email" class="input" value="{{ old('email') }}" required>
        </div>
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">비밀번호</label>
            <input name="password" type="password" class="input" required minlength="8">
        </div>
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">추천인 코드 <span class="text-muted-soft">(선택 · +20개)</span></label>
            <input name="referral" type="text" class="input" value="{{ old('referral') }}" placeholder="추천인 코드가 있다면 입력">
        </div>
        <button type="submit" class="btn btn-primary btn-lg mt-1">무료로 시작</button>
    </form>
@endsection

@section('auth-footer')
    이미 회원이세요? <a href="{{ route('login') }}" class="text-ink font-semibold">로그인</a>
@endsection
