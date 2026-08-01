{{-- 미션 API 테스트(운영자) — 매체가 받는 응답을 실제 엔드포인트로 호출해 그대로 확인한다 --}}
@extends('admin.layout')
@section('title', '미션 API 테스트')

@section('admin-content')
<style>
    .rt-grid { display: grid; grid-template-columns: 360px minmax(0, 1fr); gap: 20px; align-items: start; }
    .rt-out {
        font-family: var(--font-mono); font-size: var(--fs-xs); line-height: 1.6;
        background: var(--color-surface-soft); border: 1px solid var(--color-hairline);
        border-radius: var(--radius-md); padding: 14px 16px; overflow: auto; max-height: 620px;
        white-space: pre-wrap; word-break: break-all; margin: 0;
    }
    .rt-chip { display:inline-block; font-family:var(--font-mono); font-size:var(--fs-xs); font-weight:700; padding:2px 8px; border-radius:4px; }
    .rt-ok { background: color-mix(in srgb, var(--color-success) 14%, transparent); color: var(--color-success); }
    .rt-warn { background: color-mix(in srgb, var(--color-error) 12%, transparent); color: var(--color-error); }
    .rt-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    @media (max-width: 1000px) { .rt-grid { grid-template-columns: minmax(0,1fr); } }
</style>

<x-console.page-head title="미션 API 테스트"
    desc="매체(오퍼월·미니앱)가 받는 응답을 실제 엔드포인트로 호출해 확인합니다 · 응답은 가공하지 않습니다" />

<div class="card p-4 mb-4">
    <div class="rt-row" style="gap:14px;">
        <span class="text-muted" style="font-size:var(--fs-xs);">농장일</span>
        <b class="text-ink" style="font-size:var(--fs-sm);font-family:var(--font-mono);">{{ $day }}</b>
        <span class="text-muted" style="font-size:var(--fs-xs);">구간</span>
        <b class="text-ink" style="font-size:var(--fs-sm);font-family:var(--font-mono);">{{ $closed ? '휴지(02~06시)' : $slotNo }}</b>
        <span class="text-muted" style="font-size:var(--fs-xs);">정답 소스</span>
        <span class="rt-chip rt-ok">{{ config('reward.answer_sources.'.$answerSource, $answerSource) }}</span>
        @if ($poolVendorId <= 0)
            <span class="rt-chip rt-warn">리워드 풀 벤더 미지정 — 동기화가 돌지 않습니다</span>
        @endif
        <button type="button" class="btn btn-secondary btn-sm" id="rt-status">노출 상태 새로고침</button>
    </div>
    <pre class="rt-out mt-3" id="rt-status-out" style="max-height:180px;">노출 상태 새로고침을 눌러 현재 미션 수·스냅샷 상태를 확인하세요.</pre>
</div>

<div class="rt-grid">
    <div class="card p-4">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">요청</div>

        <label class="form-label" style="font-size:var(--fs-xs);">연동 방식</label>
        <select id="rt-channel" class="input" style="width:100%;">
            <option value="miniapp">미니앱 (x-user-key)</option>
            <option value="vendor">벤더 API (Bearer 키)</option>
        </select>

        <div class="mt-3">
            <label class="form-label" style="font-size:var(--fs-xs);">엔드포인트</label>
            <select id="rt-endpoint" class="input" style="width:100%;"></select>
        </div>

        <div class="mt-3">
            <label class="form-label" style="font-size:var(--fs-xs);">참여자 키 <span class="text-muted">(비우면 자동 생성)</span></label>
            <div class="rt-row">
                <input type="text" id="rt-user-key" class="input" style="flex:1;" placeholder="tester-001">
                <button type="button" class="btn btn-secondary btn-sm" id="rt-new-key">새 키</button>
            </div>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);">키가 바뀌면 새 참여자가 됩니다(쿨다운·참여 이력 초기화).</p>
        </div>

        <div class="mt-3" id="rt-apikey-wrap" hidden>
            <label class="form-label" style="font-size:var(--fs-xs);">API 키 <span class="text-muted">(scope: mission)</span></label>
            <input type="text" id="rt-api-key" class="input" style="width:100%;" placeholder="rk_...">
            @if ($vendorMedia->isEmpty())
                <p class="text-muted mt-1" style="font-size:var(--fs-xs);">등록된 벤더 매체가 없습니다. 매체를 먼저 등록해야 403이 아닌 응답이 옵니다.</p>
            @else
                <p class="text-muted mt-1" style="font-size:var(--fs-xs);">
                    벤더 매체: {{ $vendorMedia->pluck('name')->implode(' · ') }}
                </p>
            @endif
        </div>

        <div class="mt-3" id="rt-mission-wrap">
            <label class="form-label" style="font-size:var(--fs-xs);">미션</label>
            <select id="rt-mission" class="input" style="width:100%;">
                <option value="">선택 안 함</option>
                @foreach ($activeMissions as $m)
                    <option value="{{ $m->id }}">#{{ $m->id }} · {{ \Illuminate\Support\Str::limit($m->title, 34) }}</option>
                @endforeach
            </select>
        </div>

        <div class="mt-3" id="rt-answer-wrap" hidden>
            <label class="form-label" style="font-size:var(--fs-xs);">정답</label>
            <div class="rt-row">
                <input type="text" id="rt-answer" class="input" style="flex:1;" placeholder="태그 입력">
                <button type="button" class="btn btn-secondary btn-sm" id="rt-reveal">정답 채우기</button>
            </div>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);">참여자마다 물어보는 태그 번호가 다릅니다. 위 참여자 키 기준으로 채웁니다.</p>
        </div>

        <div class="rt-row mt-4">
            <button type="button" class="btn btn-primary" id="rt-send">호출</button>
            <span class="text-muted" style="font-size:var(--fs-xs);" id="rt-hint"></span>
        </div>
    </div>

    <div class="card p-4">
        <div class="rt-row justify-between mb-3">
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">응답</div>
            <span id="rt-status-chip"></span>
        </div>
        <pre class="rt-out" id="rt-out">왼쪽에서 엔드포인트를 고르고 [호출]을 누르세요.</pre>
    </div>
</div>

<script>
(function () {
    const EP = {
        miniapp: [
            ['assign', 'POST /missions/assign — 미션 단건 할당'],
            ['missions', 'GET /missions — 목록'],
            ['config', 'GET /config — 앱 설정'],
            ['plant', 'POST /plots/0/plant — 작물 심기'],
            ['submit', 'POST /missions/{id}/submit — 정답 제출'],
            ['state', 'GET /me/state — 내 상태'],
        ],
        vendor: [
            ['assign', 'POST /missions/assign — 단건 할당'],
            ['missions', 'GET /missions — 목록'],
            ['show', 'GET /missions/{id} — 자격 확인+상세'],
            ['submit', 'POST /missions/{id}/participations — 참여 제출'],
            ['participations', 'GET /participations — 정산 대조'],
        ],
    };

    const $ = (id) => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';

    function fillEndpoints() {
        const ch = $('rt-channel').value;
        $('rt-endpoint').innerHTML = EP[ch].map(([v, l]) => `<option value="${v}">${l}</option>`).join('');
        $('rt-apikey-wrap').hidden = ch !== 'vendor';
        syncFields();
    }

    function syncFields() {
        const ep = $('rt-endpoint').value;
        const needsAnswer = ep === 'submit';
        $('rt-answer-wrap').hidden = !needsAnswer;
        $('rt-mission-wrap').hidden = !(needsAnswer || ep === 'show');
        $('rt-hint').textContent = needsAnswer ? '제출 전에 할당/상세를 먼저 호출해 두세요.' : '';
    }

    function newKey() {
        $('rt-user-key').value = 'tester-' + Math.random().toString(36).slice(2, 8);
    }

    async function post(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify(body),
        });
        return res.json();
    }

    $('rt-channel').addEventListener('change', fillEndpoints);
    $('rt-endpoint').addEventListener('change', syncFields);
    $('rt-new-key').addEventListener('click', newKey);

    $('rt-send').addEventListener('click', async function () {
        $('rt-out').textContent = '호출 중…';
        $('rt-status-chip').innerHTML = '';
        const data = await post('{{ route('admin.reward.api-test.call') }}', {
            channel: $('rt-channel').value,
            endpoint: $('rt-endpoint').value,
            user_key: $('rt-user-key').value,
            mission_id: $('rt-mission').value || null,
            answer: $('rt-answer').value,
            api_key: $('rt-api-key').value,
        });

        if (!data.ok) {
            $('rt-out').textContent = '호출 실패: ' + (data.error || '') + '\n\n' + JSON.stringify(data.request || {}, null, 2);
            return;
        }
        const cls = data.status >= 200 && data.status < 300 ? 'rt-ok' : 'rt-warn';
        $('rt-status-chip').innerHTML = `<span class="rt-chip ${cls}">HTTP ${data.status}</span>`;
        $('rt-out').textContent =
            data.request.method + ' ' + data.request.url + '\n' +
            (Object.keys(data.request.body || {}).length ? '요청 본문: ' + JSON.stringify(data.request.body) + '\n' : '') +
            (Object.keys(data.headers || {}).length ? '응답 헤더: ' + JSON.stringify(data.headers) + '\n' : '') +
            '\n' + JSON.stringify(data.body, null, 2);
    });

    $('rt-reveal').addEventListener('click', async function () {
        if (!$('rt-mission').value) { alert('미션을 먼저 고르세요.'); return; }
        if (!$('rt-user-key').value) { newKey(); }
        const data = await post('{{ route('admin.reward.api-test.answer') }}', {
            mission_id: $('rt-mission').value,
            user_key: $('rt-user-key').value,
            channel: $('rt-channel').value,
        });
        if (data.ok && data.answer) {
            $('rt-answer').value = data.answer;
            $('rt-hint').textContent = data.tagIndex ? `${data.tagIndex}번째 태그로 채웠습니다.` : (data.note || '');
        } else {
            $('rt-hint').textContent = data.message || data.note || '정답을 확인할 수 없습니다.';
        }
    });

    $('rt-status').addEventListener('click', async function () {
        const res = await fetch('{{ route('admin.reward.api-test.status') }}', { headers: { 'Accept': 'application/json' } });
        $('rt-status-out').textContent = JSON.stringify(await res.json(), null, 2);
    });

    fillEndpoints();
    newKey();
})();
</script>
@endsection
