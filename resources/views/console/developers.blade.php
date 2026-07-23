@extends('console.layout')
@section('page-title', 'API 문서')

@section('page-actions')
    {{-- 외부 공유용 공개 문서 — 로그인 없이 열람 가능한 URL --}}
    <a href="{{ route('developers') }}" target="_blank" class="btn btn-secondary btn-sm" title="로그인 없이 볼 수 있는 공개 문서 — 외부 업체에 이 링크를 공유하세요">공개 문서 ↗</a>
    <a href="{{ route('console.api-keys') }}" class="btn btn-primary btn-sm">API 키 관리</a>
@endsection

@section('console-content')
<x-console.page-head title="API 문서" desc="랭크프리 오픈 API — 인증 · 순위추적 · 경쟁분석 · 키워드분석 · 마케팅 상품 주문" />

{{-- 본문은 공개 /developers 와 동일한 공용 partial --}}
<div style="max-width:880px;">
    @include('partials.developers-doc')
</div>
@endsection
