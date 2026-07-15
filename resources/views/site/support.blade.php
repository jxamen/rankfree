@extends('layouts.site')
@section('title', '마케팅 상담 문의 · 랭크프리')
@section('description', '플레이스 최적화·블로그 체험단·광고 대행 상담을 신청하세요. 연락처를 남기면 담당자가 빠르게 연락드립니다.')

@section('content')
<section class="container-page py-16 lg:py-20" style="max-width:680px;">
    <div class="text-center mb-10">
        <div class="badge mb-4 border border-hairline">마케팅 상담</div>
        <h1 class="font-display text-ink" style="font-size:clamp(28px,3.2vw,40px);line-height:1.15;">분석에서 실행까지,<br>필요한 마케팅을 상담하세요.</h1>
        <p class="mt-4 text-muted" style="font-size:var(--fs-base);">플레이스 최적화 · 블로그/체험단 · 광고 대행 — 연락처를 남기시면 담당자가 빠르게 연락드립니다.</p>
    </div>

    @if (session('status'))
        <div class="card-soft mb-6 px-5 py-4 text-ink text-center" style="font-size:var(--fs-sm);">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-6 px-5 py-4 rounded-lg text-center" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-sm);">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('lead.store') }}" class="card p-7">
        @csrf
        <input type="hidden" name="source" value="support">
        {{-- 봇 허니팟(사람에겐 안 보임 — 채워지면 서버가 조용히 무시) --}}
        <input type="text" name="company" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">성함 <span class="text-error">*</span></label>
                <input name="name" value="{{ old('name') }}" required maxlength="80" class="input" style="width:100%;" placeholder="홍길동">
            </div>
            <div>
                <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">연락처 <span class="text-error">*</span></label>
                <input name="phone" value="{{ old('phone') }}" required maxlength="40" class="input" style="width:100%;" placeholder="010-0000-0000" inputmode="tel">
            </div>
        </div>
        <div class="mt-4">
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">관심 서비스</label>
            <select name="interest" class="input" style="width:100%;">
                @foreach (['플레이스 최적화', '블로그·체험단', '광고 대행', '기타'] as $opt)
                    <option value="{{ $opt }}" @selected(old('interest') === $opt)>{{ $opt }}</option>
                @endforeach
            </select>
        </div>
        <div class="mt-4">
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">주력 키워드 <span class="text-muted-soft">(선택)</span></label>
            <input name="keyword" value="{{ old('keyword') }}" maxlength="160" class="input" style="width:100%;" placeholder="예: 강남 맛집">
        </div>
        <div class="mt-4">
            <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">문의 내용 <span class="text-muted-soft">(선택)</span></label>
            <textarea name="message" rows="4" maxlength="1000" class="input" style="width:100%;resize:vertical;" placeholder="현재 상황과 목표를 간단히 적어주세요.">{{ old('message') }}</textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-full mt-6">상담 신청하기</button>
        <p class="text-muted-soft text-center mt-3" style="font-size:var(--fs-xs);">남겨주신 정보는 상담 목적으로만 사용됩니다. <a href="/privacy" class="underline">개인정보처리방침</a></p>
    </form>

    <div class="card-soft mt-6 p-5 text-center">
        <span class="text-muted" style="font-size:var(--fs-sm);">전화 상담 <b class="text-ink font-mono">02-1668-2612</b> · 이메일 <a href="mailto:jcurve19@gmail.com" class="text-accent">jcurve19@gmail.com</a></span>
    </div>
</section>
@endsection
