@extends('layouts.auth')
@section('robots', 'noindex') {{-- 계정 유틸 페이지 — 색인 불필요 --}}
@section('title', '아이디 찾기')

@section('auth-content')
    <h1 class="font-display text-ink text-center" style="font-size:var(--fs-xl);">아이디(이메일) 찾기</h1>
    <p class="text-muted text-center mt-1" style="font-size:var(--fs-xs);">가입 시 인증한 전화번호로 이메일을 찾습니다</p>

    <div class="mt-6 flex flex-col gap-4">
        @include('auth._phone-verify')
        <button type="button" id="fe-find" class="btn btn-primary btn-lg" disabled>이메일 찾기</button>
        <div id="fe-result" class="card-soft p-5 text-center hidden"></div>
    </div>
@endsection

@section('auth-footer')
    <a href="{{ route('login') }}" class="text-ink font-semibold">← 로그인으로</a>
@endsection

@push('scripts')
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var findBtn = document.getElementById('fe-find');
    var result = document.getElementById('fe-result');
    var pname = { google: '구글', kakao: '카카오' };

    document.addEventListener('phone-verified', function () { findBtn.disabled = false; });

    findBtn.addEventListener('click', function () {
        var p = document.getElementById('pv-phone').value.replace(/\D/g, '');
        findBtn.disabled = true; findBtn.textContent = '조회 중…';
        fetch('{{ route('find-email.find') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify({ phone: p }),
        }).then(function (r) { return r.json(); }).then(function (j) {
            findBtn.textContent = '이메일 찾기'; findBtn.disabled = false;
            result.classList.remove('hidden');
            if (j.found) {
                var prov = j.provider ? '<div class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">' + (pname[j.provider] || '소셜') + ' 계정으로 가입됨</div>' : '';
                result.innerHTML = '<div class="text-muted" style="font-size:var(--fs-xs);">가입된 이메일</div>'
                    + '<div class="text-ink font-semibold mt-1" style="font-size:var(--fs-md);">' + j.email + '</div>' + prov
                    + '<a href="{{ route('login') }}" class="btn btn-primary mt-4">로그인하기</a>';
            } else {
                result.innerHTML = '<div class="text-muted" style="font-size:var(--fs-sm);">' + (j.message || '가입된 계정이 없습니다.') + '</div>'
                    + '<a href="{{ route('register') }}" class="btn btn-secondary mt-3">무료로 가입</a>';
            }
        }).catch(function () {
            findBtn.textContent = '이메일 찾기'; findBtn.disabled = false;
            result.classList.remove('hidden');
            result.innerHTML = '<div class="text-muted" style="font-size:var(--fs-sm);">오류가 발생했습니다. 다시 시도해 주세요.</div>';
        });
    });
})();
</script>
@endpush
