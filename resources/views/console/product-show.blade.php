@extends('console.layout')
@section('page-title', '상품 분석')
@section('crumb-title', $a->name)

@section('page-actions')
    <a href="{{ route('console.product') }}" class="btn btn-secondary btn-sm">← 내역으로</a>
@endsection

@section('console-content')
    @include('console._product_body', [
        'a' => $a,
        'shareUrl' => $a->shareUrl(),
        'public' => false,
    ])
@endsection
