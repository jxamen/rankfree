@extends('console.layout')
@section('page-title', 'API 문서')

@section('page-actions')
    <a href="{{ route('console.api-keys') }}" class="btn btn-primary btn-sm">API 키 관리</a>
@endsection

@section('console-content')
<x-console.page-head title="API 문서" desc="랭크프리 오픈 API — 인증 · 순위추적 · 경쟁분석 · 키워드분석 · 마케팅 상품 주문" />

{{-- 본문은 공개 /developers 와 동일한 공용 partial --}}
<div style="max-width:880px;">
    @include('partials.developers-doc')
</div>
@endsection
