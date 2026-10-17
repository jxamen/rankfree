@extends('console.layout')
@section('page-title', '잔액·충전')
@section('console-content')
<x-console.page-head title="잔액·충전" desc="입금 확인 후 잔액에 반영됩니다. 주문 시 부가세 포함 금액이 차감됩니다." />
<div class="card p-6 mb-6"><h2>사용 가능 잔액 {{ number_format($balance) }}원</h2>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
<form method="POST" action="{{ route('wallet.topups') }}">@csrf
<label>충전 금액(원) <input class="input" type="number" name="amount" min="1" max="100000000" required value="{{ old('amount') }}"></label>
<label>입금자명 <input class="input" name="depositor" maxlength="100" required value="{{ old('depositor', auth()->user()->name) }}"></label>
<button class="btn btn-primary">충전 요청</button></form>
<p class="mt-4">입금 계좌: {{ $bank['bank'] }} {{ $bank['account'] }} {{ $bank['holder'] }}</p>
<p>요청 금액 그대로 입금해 주세요. 주문 시에만 부가세 포함 금액을 차감합니다.</p></div>
<div class="card p-6 mb-6"><h2>충전 요청</h2>
@foreach($topups as $topup)<p>{{ $topup->created_at }} · {{ $topup->code }} · {{ $topup->depositor }} · {{ number_format($topup->amount) }}원 · {{ ['pending'=>'입금 확인 대기','confirmed'=>'충전 완료','rejected'=>'거절'][$topup->status] }}</p>@endforeach
{{ $topups->links() }}</div>
<div class="card p-6"><h2>잔액 내역</h2>
@foreach($entries as $entry)<p>{{ $entry->created_at }} · {{ $entry->description }} · {{ number_format($entry->amount) }}원 · 잔액 {{ number_format($entry->balance_after) }}원</p>@endforeach
{{ $entries->links() }}</div>
@endsection
