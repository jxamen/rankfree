@extends('admin.layout')
@section('page-title', '충전 요청 관리')
@section('admin-content')
<x-console.page-head title="충전 요청 관리" desc="실제 입금 금액·입금자명·요청 코드를 대조한 후 확인하세요." />
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
@foreach($topups as $topup)
<div class="card p-6 mb-4"><p>{{ $topup->created_at }} · {{ $topup->user->email }} · {{ $topup->code }}</p>
<p>입금자 {{ $topup->depositor }} · {{ number_format($topup->amount) }}원 · {{ ['pending'=>'입금 확인 대기','confirmed'=>'충전 완료','rejected'=>'거절'][$topup->status] }}</p>
@if($topup->status === 'pending')
<form method="POST" action="{{ route('admin.topups.review', $topup) }}">@csrf
<button class="btn btn-primary" name="status" value="confirmed">입금 확인·잔액 적립</button>
<button class="btn" name="status" value="rejected">거절</button></form>
@else <p>처리자 #{{ $topup->reviewed_by }} · {{ $topup->reviewed_at }}</p>@endif</div>
@endforeach
{{ $topups->links() }}
@endsection
