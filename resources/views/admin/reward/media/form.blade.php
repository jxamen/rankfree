{{-- 매체 설정 — 기본 정보 + 배분 규칙(어떤 미션을 어떤 비율로 줄지) --}}
@extends('admin.layout')
@section('title', $medium->exists ? '매체 설정' : '매체 등록')

@section('admin-content')
<style>
    .al-row { display:grid; grid-template-columns:120px minmax(160px,1fr) 90px 110px 110px 40px; gap:8px; align-items:center; }
    .al-head { font-size:var(--fs-xs); color:var(--color-muted); font-weight:700; }
    @media (max-width: 900px) { .al-row { grid-template-columns:minmax(0,1fr); } .al-head { display:none; } }
</style>

<x-console.page-head :title="$medium->exists ? $medium->name : '매체 등록'"
    desc="지급 단가·처리 능력·배분 비율 · 매체가 활성이어야 참여 API 가 동작합니다" />

@if (session('status'))
    <div class="alert alert-success mb-4" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-error mb-4" style="font-size:var(--fs-xs);">
        @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
    </div>
@endif

<form method="POST" action="{{ $medium->exists ? route('admin.reward.media.update', $medium) : route('admin.reward.media.store') }}">
    @csrf
    @if ($medium->exists) @method('PUT') @endif

    <div class="card p-4 mb-4">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">기본 정보</div>
        <div class="grid gap-3" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <div>
                <label class="form-label" style="font-size:var(--fs-xs);">매체명</label>
                <input type="text" name="name" class="input" style="width:100%;" required
                    value="{{ old('name', $medium->name) }}" placeholder="퀴즈농장">
            </div>
            <div>
                <label class="form-label" style="font-size:var(--fs-xs);">식별자 <span class="text-muted">(영문 소문자·숫자·하이픈)</span></label>
                <input type="text" name="slug" class="input" style="width:100%;font-family:var(--font-mono);" required
                    value="{{ old('slug', $medium->slug) }}" placeholder="quiz-farm">
            </div>
            <div>
                <label class="form-label" style="font-size:var(--fs-xs);">유형</label>
                <select name="type" class="input" style="width:100%;">
                    <option value="vendor_api" @selected(old('type', $medium->type) === 'vendor_api')>벤더 API (API 키로 연동)</option>
                    <option value="miniapp" @selected(old('type', $medium->type) === 'miniapp')>미니앱 (x-user-key)</option>
                </select>
            </div>
            <div>
                <label class="form-label" style="font-size:var(--fs-xs);">API 키 소유 회원 <span class="text-muted">(벤더 API 전용)</span></label>
                <select name="api_user_id" class="input" style="width:100%;">
                    <option value="">연결 안 함</option>
                    @foreach ($apiUsers as $u)
                        <option value="{{ $u->id }}" @selected((int) old('api_user_id', $medium->api_user_id) === $u->id)>{{ $u->name }} ({{ $u->email }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label" style="font-size:var(--fs-xs);">지급 단가 <span class="text-muted">(참여 1건당, 원)</span></label>
                <input type="number" name="payout_unit_price" class="input text-right" style="width:100%;" min="0"
                    value="{{ old('payout_unit_price', $medium->payout_unit_price ?? 0) }}">
            </div>
            <div>
                <label class="form-label" style="font-size:var(--fs-xs);">초당 호출 한도</label>
                <input type="number" name="rate_limit_rps" class="input text-right" style="width:100%;" min="1"
                    value="{{ old('rate_limit_rps', $medium->rate_limit_rps ?? 100) }}">
            </div>
            <div>
                <label class="form-label" style="font-size:var(--fs-xs);">정답 검증</label>
                <select name="verify_mode" class="input" style="width:100%;">
                    <option value="server" @selected(old('verify_mode', $medium->verify_mode) === 'server')>랭크프리가 검증</option>
                    <option value="vendor" @selected(old('verify_mode', $medium->verify_mode) === 'vendor')>매체가 자체 검증</option>
                </select>
            </div>
            <div class="flex items-end">
                <label class="inline-flex items-center gap-2" style="font-size:var(--fs-xs);">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $medium->is_active))>
                    <span class="text-ink">활성 (꺼두면 이 매체의 API 가 403)</span>
                </label>
            </div>
        </div>
    </div>

    @if ($medium->exists)
        <div class="card p-4 mb-4">
            <input type="hidden" name="alloc_submitted" value="1">
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">배분 규칙</div>
            <p class="text-muted mb-3" style="font-size:var(--fs-xs);line-height:1.8;">
                이 매체가 <b>어떤 미션을 하루 몇 건까지</b> 가져갈지 정합니다. 규칙이 없으면 <b>제한 없이</b> 공유 풀에서 가져갑니다.<br>
                범위가 겹치면 <b>좁은 쪽이 이깁니다</b>(개별 미션 &gt; 미션 유형 &gt; 전체). 비율과 상한을 함께 쓰면 <b>더 작은 값</b>이 적용됩니다.
            </p>

            <div class="al-row al-head mb-1">
                <div>범위</div><div>대상</div><div>비율(%)</div><div>일 상한(건)</div><div>최소 보장</div><div></div>
            </div>

            <div id="alloc-rows" class="flex flex-col gap-2">
                @foreach ($allocations as $i => $a)
                    <div class="al-row alloc-row">
                        <select name="alloc[{{ $i }}][scope]" class="input al-scope">
                            <option value="all" @selected($a->scope === 'all')>전체 미션</option>
                            <option value="kind" @selected($a->scope === 'kind')>미션 유형</option>
                            <option value="mission" @selected($a->scope === 'mission')>개별 미션</option>
                        </select>
                        <input type="text" name="alloc[{{ $i }}][scope_key]" class="input al-key"
                            value="{{ $a->scope_key }}" placeholder="유형 코드 또는 미션 번호" @if ($a->scope === 'all') disabled @endif>
                        <input type="number" name="alloc[{{ $i }}][ratio]" class="input text-right" min="0" max="100" value="{{ $a->ratio }}">
                        <input type="number" name="alloc[{{ $i }}][max_per_day]" class="input text-right" min="0" value="{{ $a->max_per_day }}">
                        <input type="number" name="alloc[{{ $i }}][min_per_day]" class="input text-right" min="0" value="{{ $a->min_per_day }}">
                        <button type="button" class="btn btn-secondary btn-sm al-del">×</button>
                    </div>
                @endforeach
            </div>

            <button type="button" class="btn btn-secondary btn-sm mt-3" id="alloc-add">규칙 추가</button>

            <p class="text-muted mt-3" style="font-size:var(--fs-xs);">
                미션 유형 코드: {{ $kinds->isEmpty() ? '(아직 없음)' : $kinds->implode(' · ') }} ·
                비율과 상한을 <b>둘 다 비우면</b> 저장 시 그 규칙은 삭제됩니다(= 제한 없음).
            </p>
        </div>
    @else
        <div class="card p-4 mb-4">
            <p class="text-muted" style="font-size:var(--fs-xs);">배분 규칙은 매체를 등록한 뒤 설정할 수 있습니다.</p>
        </div>
    @endif

    <div class="flex items-center gap-2">
        <button type="submit" class="btn btn-primary">저장</button>
        <a href="{{ route('admin.reward.media') }}" class="btn btn-secondary">목록</a>
    </div>
</form>

<template id="alloc-tpl">
    <div class="al-row alloc-row">
        <select name="alloc[__i__][scope]" class="input al-scope">
            <option value="all">전체 미션</option>
            <option value="kind">미션 유형</option>
            <option value="mission">개별 미션</option>
        </select>
        <input type="text" name="alloc[__i__][scope_key]" class="input al-key" placeholder="유형 코드 또는 미션 번호" disabled>
        <input type="number" name="alloc[__i__][ratio]" class="input text-right" min="0" max="100">
        <input type="number" name="alloc[__i__][max_per_day]" class="input text-right" min="0">
        <input type="number" name="alloc[__i__][min_per_day]" class="input text-right" min="0" value="0">
        <button type="button" class="btn btn-secondary btn-sm al-del">×</button>
    </div>
</template>

<script>
(function () {
    const wrap = document.getElementById('alloc-rows');
    if (!wrap) return;
    const tpl = document.getElementById('alloc-tpl');

    document.getElementById('alloc-add').addEventListener('click', function () {
        const html = tpl.innerHTML.replace(/__i__/g, String(Date.now() % 100000));
        wrap.insertAdjacentHTML('beforeend', html);
    });

    // 범위가 '전체'면 대상 입력을 잠근다 — 값이 남아 있으면 저장 시 혼선이 생긴다
    wrap.addEventListener('change', function (e) {
        if (!e.target.classList.contains('al-scope')) return;
        const key = e.target.closest('.alloc-row').querySelector('.al-key');
        key.disabled = e.target.value === 'all';
        if (key.disabled) key.value = '';
    });

    wrap.addEventListener('click', function (e) {
        if (e.target.classList.contains('al-del')) e.target.closest('.alloc-row').remove();
    });

    // 제출 직전 인덱스를 다시 매긴다(중간 삭제 대비)
    document.querySelector('form').addEventListener('submit', function () {
        wrap.querySelectorAll('.alloc-row').forEach(function (row, idx) {
            row.querySelectorAll('[name^="alloc["]').forEach(function (el) {
                el.name = el.name.replace(/^alloc\[[^\]]+\]/, 'alloc[' + idx + ']');
                el.disabled = false;   // disabled 필드는 전송되지 않아 '전체' 규칙이 사라진다
            });
        });
    });
})();
</script>
@endsection
