@extends('console.layout')
@section('page-title', '시장 분석 — '.$a->keyword)

@section('page-actions')
    <a href="{{ route('console.market') }}" class="btn btn-secondary btn-sm">← 내역으로</a>
@endsection

@section('console-content')
    @include('console._market_body', [
        'a' => $a,
        'weekday' => $weekday ?? null,
        'shareUrl' => route('market.shared', $a->shareToken()),
        'public' => false,
    ])
@endsection
