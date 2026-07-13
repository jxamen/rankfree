{{-- 소셜 로그인 버튼 (구글/네이버/카카오) — 브랜드 색은 각사 가이드라인상 고정 --}}
<div class="flex flex-col gap-2">
    <a href="{{ route('social.redirect', 'google') }}" class="flex items-center justify-center gap-2 rounded-md transition" style="height:44px;border:1px solid var(--color-hairline);background:#fff;color:#3c4043;font-size:var(--fs-sm);font-weight:600;">
        <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
        Google로 계속하기
    </a>
    <a href="{{ route('social.redirect', 'naver') }}" class="flex items-center justify-center gap-2 rounded-md transition" style="height:44px;background:#03C75A;color:#fff;font-size:var(--fs-sm);font-weight:600;">
        <svg width="15" height="15" viewBox="0 0 20 20" fill="#fff"><path d="M13.6 10.7 6.2 0H0v20h6.4V9.3L13.8 20H20V0h-6.4v10.7z"/></svg>
        네이버로 계속하기
    </a>
    <a href="{{ route('social.redirect', 'kakao') }}" class="flex items-center justify-center gap-2 rounded-md transition" style="height:44px;background:#FEE500;color:#191600;font-size:var(--fs-sm);font-weight:600;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="#191600"><path d="M12 3.2C6.6 3.2 2.2 6.6 2.2 10.8c0 2.7 1.8 5.1 4.5 6.5-.2.7-.7 2.6-.8 3-.1.5.2.5.4.4.2-.1 2.7-1.8 3.7-2.5.5.1 1 .1 1.5.1 5.4 0 9.8-3.4 9.8-7.6S17.4 3.2 12 3.2z"/></svg>
        카카오로 계속하기
    </a>
</div>
