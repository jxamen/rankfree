@extends('layouts.auth')
@section('title', '가입 마무리')

@php
    $__pname = ['google' => '구글', 'kakao' => '카카오'][$social['provider']] ?? '소셜';
@endphp

@section('auth-content')
    <h1 class="font-display text-ink text-center" style="font-size:var(--fs-xl);">거의 다 됐어요</h1>
    <p class="text-muted text-center mt-1" style="font-size:var(--fs-xs);">{{ $__pname }} 계정과 연결됩니다 · 전화번호 인증 후 가입 완료</p>

    @if ($errors->any())
        <div class="mt-5 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:var(--fs-xs);">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('social.complete') }}" class="mt-6 flex flex-col gap-4">
        @csrf
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">이름</label>
            <input name="name" type="text" class="input" value="{{ old('name', $social['name'] ?? '') }}" required autofocus>
        </div>
        <div>
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">이메일</label>
            @if (! empty($social['email']))
                {{-- 소셜이 제공한 이메일 — 변경 불가(name 미전송, 서버가 세션 값으로 강제) --}}
                <input type="email" class="input" value="{{ $social['email'] }}" readonly style="background:var(--color-surface-soft);color:var(--color-muted);cursor:not-allowed;">
                <p class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">{{ $__pname }} 계정 이메일로 가입됩니다 (변경 불가)</p>
            @else
                <input name="email" type="email" class="input" value="{{ old('email') }}" placeholder="이메일을 입력하세요" required>
            @endif
        </div>
        @include('auth._phone-verify')
        <button type="submit" class="btn btn-primary btn-lg mt-1">가입 완료</button>
    </form>
@endsection

@section('auth-footer')
    <a href="{{ route('register') }}" class="text-ink font-semibold">← 다른 방법으로 가입</a>
@endsection
