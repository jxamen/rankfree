@extends('console.layout')
@section('page-title', '상품 분석')

@section('page-actions')
    <a href="{{ route('console.product') }}" class="btn btn-secondary btn-sm">← 내역으로</a>
@endsection

@section('console-content')
    @include('console._product_body', [
        'a' => $a,
        'shareUrl' => route('product.shared', $a->shareToken()),
        'public' => false,
    ])
@endsection
