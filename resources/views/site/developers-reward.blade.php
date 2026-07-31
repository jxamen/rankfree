{{-- 리워드 미션 API 문서 — 매체 파트너 공유용(비로그인 열람). 광고주용 /developers 와 분리한다. --}}
@extends('layouts.site')
@section('title', '리워드 미션 API — 랭크프리')
@section('description', '랭크프리 리워드 미션 API — 오퍼월·미니앱 등 매체가 참여형 미션을 받아 노출하고 참여를 제출하는 연동 문서')

@section('content')
<section class="py-10 lg:py-14 container-page">
    <div class="mb-6">
        <h1 class="font-display text-ink" style="font-size:clamp(24px,2.8vw,32px);line-height:1.2;">리워드 미션 API</h1>
        <p class="text-muted mt-1" style="font-size:var(--fs-sm);">매체 연동 — 미션 수신 · 참여 제출 · 정산 대조</p>
    </div>
    <div style="max-width:880px;">
        @include('partials.reward-api-doc')
    </div>
</section>
@endsection
