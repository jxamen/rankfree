@extends('admin.layout')
@section('page-title','충전 요청 관리')
@section('admin-content')
@php
$rows=$topups;
@endphp

<style>
.topup-admin{width:100%;font-size:14px;line-height:1.7;color:#172033}
.topup-admin h1{font-size:24px;font-weight:700;margin-bottom:8px}.topup-admin h2{font-size:17px;font-weight:700}
.topup-admin form{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:12px 0}
.topup-admin input,.topup-admin select{border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;background:white;color:#172033}
.topup-admin button{border:1px solid #aab7c8;border-radius:6px;padding:6px 12px;background:#f1f5f9;color:#172033;cursor:pointer}
.topup-admin button:hover{background:#dbeafe}.topup-admin summary{cursor:pointer;margin:8px 0}.topup-admin [role=alert]{color:#b91c1c}
.topup-admin details p{overflow-wrap:anywhere}.topup-admin article{border-radius:10px}.topup-admin label{display:inline-flex;align-items:center;gap:6px}
</style>
<section class="topup-admin">
<h1>충전 요청 관리 · 입금 확인</h1>
<p>은행 내역과 요청 번호·코드·금액·입금자명을 대조하세요. 계산서 발행 표시는 실제 발행 연동이 아닙니다.</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
<form method="GET">
<label>시작일 <input type="date" name="from" value="{{ request('from') }}"></label>
<label>종료일 <input type="date" name="to" value="{{ request('to') }}"></label>
<label>검색 <input name="q" maxlength="160" value="{{ request('q') }}" placeholder="회원·입금자·업체·코드"></label>
<label>상태 <select name="status"><option value="">전체</option>
@foreach(['pending'=>'대기','confirmed'=>'승인','rejected'=>'취소/거절'] as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach
</select></label><button>검색</button></form>
<p>검색 결과 입금완료 {{ number_format($totals['received']) }}원 / 입금대기 {{ number_format($totals['waiting']) }}원</p>
@forelse($rows as $row)
@php
$t=(object)$row;
$pending=$t->status==='pending'; $cancelled=$t->status==='rejected';
$manage=route('admin.topups.manage',$t->id);
$depositor=$t->depositor;
@endphp
<article style="border:1px solid #d1d5db;padding:20px;margin:16px 0;background:white;color:#111827">
<h2>#{{ $t->id }} · 회원 {{ $t->user->email }} · 코드 {{ $t->code }}</h2>
<p>요청 {{ $t->created_at }} · {{ number_format($t->amount) }}원 · 입금자 {{ $depositor ?: '미기록' }} · 계산서 업체 {{ $t->company ?: '미기록' }}</p>
<p>승인 상태 {{ ['waiting'=>'승인 대기','pending'=>'승인 대기','confirmed'=>'승인 완료','cancelled'=>'취소','rejected'=>'취소/거절'][$t->status] ?? $t->status }} / 입금 {{ $t->bank_received ? '완료':'대기' }} / 부가세 {{ $t->vat_included === null ? '미확인' : ($t->vat_included ? '포함':'미포함') }} / 계산서 {{ $t->invoice_issued ? '발행완료':'대기' }}</p>
@if($pending)
<form method="POST" action="{{ route('admin.topups.review',$t->id) }}">@csrf

<button name="status" value="confirmed" onclick="return confirm('실제 입금을 대조했습니까? 잔액을 적립합니다.')">입금 확인 · 잔액 적립</button>
</form>
@endif
<form method="POST" action="{{ $manage }}">@csrf
@if($t->status==='confirmed')<button name="action" value="revoke" onclick="return confirm('승인을 취소하고 충전 금액을 잔액에서 차감합니까? 잔액 부족 시 차단됩니다.')">승인 취소</button>@endif
@if($pending)<button name="action" value="cancel" onclick="return confirm('충전 요청을 취소합니까?')">요청 취소</button>@endif
@if($cancelled)<button name="action" value="restore">요청 복원</button> <button name="action" value="delete" onclick="return confirm('목록에서 삭제합니까? 원장과 감사 이력은 보존됩니다.')">삭제</button>@endif
</form>
@foreach(['bank'=>'입금 여부','vat'=>'부가세 포함 여부','invoice'=>'계산서 발행 여부'] as $action=>$label)
@php
$field=['bank'=>'bank_received','vat'=>'vat_included','invoice'=>'invoice_issued'][$action];
@endphp
<form method="POST" action="{{ $manage }}" style="display:inline-block;margin:8px 12px 8px 0">@csrf
<input type="hidden" name="action" value="{{ $action }}"><input type="hidden" name="value" value="{{ $t->$field ? 0:1 }}">
<button>{{ $label }}: {{ $t->$field ? '완료 → 취소':'대기 → 완료' }}</button></form>
@endforeach
@if($pending && (int)$t->approval_cycle===0)
<details><summary>입금자 · 금액 · 계산서 업체 수정</summary>
<form method="POST" action="{{ $manage }}">@csrf<input type="hidden" name="action" value="edit">
<label>입금자명 <input name="depositor" value="{{ $depositor }}" maxlength="60" required></label>
<label>금액 <input type="number" name="amount" value="{{ $t->amount }}" min="1000" max="100000000" required></label>
<label>계산서 업체 <input name="company" value="{{ $t->company }}" maxlength="160"></label><button>저장</button>
</form></details>
@endif
<form method="POST" action="{{ $manage }}">@csrf<button name="action" value="vat_sms" onclick="return confirm('회원에게 부가세 입금 요청 문자를 발송합니까?')">부가세 요청 문자</button></form>
<details><summary>처리자 · 시각 · 변경 이력</summary>
@forelse(($audits[$t->id] ?? []) as $entry)
@php
$a=(object)$entry;
@endphp
<p>{{ $a->created_at }} · {{ $a->actor }} · {{ ['confirm'=>'입금 확인','confirmed'=>'입금 확인','revoke'=>'승인 취소','cancel'=>'요청 취소','rejected'=>'요청 거절','restore'=>'요청 복원','delete'=>'목록 삭제','edit'=>'요청 수정','bank'=>'입금 표시 변경','vat'=>'부가세 표시 변경','invoice'=>'계산서 표시 변경','vat_sms'=>'부가세 문자 요청','confirmed_sms'=>'충전 완료 문자'][$a->action] ?? $a->action }}
<details><summary>변경 전후 값</summary><p>이전 {{ $a->before_state }}</p><p>이후 {{ $a->after_state }}</p></details></p>
@empty<p>관리자 처리 이력이 없습니다.</p>@endforelse
</details>
</article>
@empty<p>충전 요청이 없습니다.</p>@endforelse
{{ $topups->links() }}
</section>
@endsection
