{{-- 매체 설정 — 기본 정보 + 배분 규칙(어떤 미션을 어떤 비율로 줄지) --}}
@extends('admin.layout')
@section('title', $medium->exists ? '제휴 매체 설정' : '제휴 매체 등록')

@section('admin-content')
<style>
    .al-row { display:grid; grid-template-columns:120px minmax(160px,1fr) 90px 110px 110px 40px; gap:8px; align-items:center; }
    .al-head { font-size:var(--fs-xs); color:var(--color-muted); font-weight:700; }
    .pay-row { display:grid; grid-template-columns:minmax(140px,1fr) 140px 40px; gap:8px; align-items:center; }
    @media (max-width: 900px) { .al-row, .pay-row { grid-template-columns:minmax(0,1fr); } .al-head { display:none; } }
</style>

<x-console.page-head :title="$medium->exists ? $medium->name : '제휴 매체 등록'"
    desc="지급 단가·처리 능력·배분 비율 · 매체가 활성이어야 참여 API 가 동작합니다" />

@if (session('status'))
    <div class="alert alert-success mb-4" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-error mb-4" style="font-size:var(--fs-xs);">
        @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
    </div>
@endif

@if ($medium->exists)
    {{-- 매체 전용 API 키 — 고객용 회원 키(api_keys)와 별개 체계. 매체가 곧 인증 주체다 --}}
    <div class="card p-4 mb-4">
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">API 키</div>
        <p class="text-muted mb-3" style="font-size:var(--fs-xs);line-height:1.8;">
            이 제휴 매체가 미션 API 를 호출할 때 쓰는 키입니다. 고객용 회원 API 키와 별개라
            <b class="text-ink">매체는 회원가입이 필요 없습니다</b>.
            <code style="font-family:var(--font-mono);">Authorization: Bearer &lt;키&gt;</code> 헤더로 보냅니다.
        </p>

        <div class="flex items-center gap-2 flex-wrap">
            <input type="text" class="input" readonly style="flex:1;min-width:260px;font-family:var(--font-mono);"
                value="{{ $medium->plainKey() ?? ($medium->api_key_prefix ? $medium->api_key_prefix.'…' : '(발급 안 됨)') }}">
            <form method="POST" action="{{ route('admin.reward.media.regenerate-key', $medium) }}"
                @if ($medium->api_key_prefix)
                    data-confirm="API 키를 재발급할까요?" data-confirm-text="이전 키는 즉시 사용할 수 없습니다. 매체에 새 키를 전달해야 합니다."
                    data-confirm-ok="재발급"
                @endif>
                @csrf
                <button type="submit" class="btn btn-secondary">{{ $medium->api_key_prefix ? '재발급' : '키 발급' }}</button>
            </form>
        </div>
        <p class="text-muted mt-2" style="font-size:var(--fs-xs);">
            마지막 사용: {{ $medium->api_key_last_used_at ? $medium->api_key_last_used_at->format('Y-m-d H:i') : '없음' }}
        </p>
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
        {{-- 미션 유형별 지급 단가 — 지출 계산의 입력. 유형별 행이 없으면 위 '지급 단가'로 폴백 --}}
        <div class="card p-4 mb-4">
            <input type="hidden" name="payout_submitted" value="1">
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">미션 유형별 지급 단가</div>
            <p class="text-muted mb-3" style="font-size:var(--fs-xs);line-height:1.8;">
                이 제휴 매체에 <b>참여 1건당 얼마를 지급할지</b> 미션 유형별로 정합니다.
                유형별 행이 없으면 위 기본 <b>지급 단가</b>({{ number_format((int) $medium->payout_unit_price) }}원)가 적용됩니다.
            </p>

            <div class="pay-row al-head mb-1">
                <div>미션 유형</div><div>지급 단가(원)</div><div></div>
            </div>

            <div id="payout-rows" class="flex flex-col gap-2">
                @foreach ($payouts as $i => $p)
                    <div class="pay-row payout-row">
                        <input type="text" name="payout[{{ $i }}][kind]" class="input" style="font-family:var(--font-mono);"
                            value="{{ $p->kind }}" placeholder="유형 코드">
                        <input type="number" name="payout[{{ $i }}][unit_price]" class="input text-right" min="0" value="{{ $p->unit_price }}">
                        <button type="button" class="btn btn-secondary btn-sm pay-del">×</button>
                    </div>
                @endforeach
            </div>

            <button type="button" class="btn btn-secondary btn-sm mt-3" id="payout-add">유형 추가</button>

            <p class="text-muted mt-3" style="font-size:var(--fs-xs);">
                미션 유형 코드: {{ $kinds->isEmpty() ? '(아직 없음)' : $kinds->implode(' · ') }} ·
                유형 코드나 단가를 <b>비우면</b> 저장 시 그 유형은 삭제됩니다(= 기본 단가 적용).
            </p>
        </div>
    @else
        <div class="card p-4 mb-4">
            <p class="text-muted" style="font-size:var(--fs-xs);">배분 규칙과 미션 유형별 지급 단가는 매체를 등록한 뒤 설정할 수 있습니다.</p>
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

<template id="payout-tpl">
    <div class="pay-row payout-row">
        <input type="text" name="payout[__i__][kind]" class="input" style="font-family:var(--font-mono);" placeholder="유형 코드">
        <input type="number" name="payout[__i__][unit_price]" class="input text-right" min="0">
        <button type="button" class="btn btn-secondary btn-sm pay-del">×</button>
    </div>
</template>

<script>
(function () {
    const wrap = document.getElementById('payout-rows');
    if (!wrap) return;
    const tpl = document.getElementById('payout-tpl');

    document.getElementById('payout-add').addEventListener('click', function () {
        wrap.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, String(Date.now() % 100000)));
    });

    wrap.addEventListener('click', function (e) {
        if (e.target.classList.contains('pay-del')) e.target.closest('.payout-row').remove();
    });

    // 제출 직전 인덱스를 다시 매긴다(중간 삭제 대비)
    document.querySelector('form').addEventListener('submit', function () {
        wrap.querySelectorAll('.payout-row').forEach(function (row, idx) {
            row.querySelectorAll('[name^="payout["]').forEach(function (el) {
                el.name = el.name.replace(/^payout\[[^\]]+\]/, 'payout[' + idx + ']');
            });
        });
    });
})();
</script>
@endsection
