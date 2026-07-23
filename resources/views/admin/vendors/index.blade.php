@extends('admin.layout')
@section('page-title', '업체 관리')

@section('admin-content')
<x-console.page-head title="업체 관리">
    <x-slot:desc>외부 발주 업체 등록·관리 — <b>API 호출</b> 또는 <b>구글시트 행 추가</b>로 주문을 전송합니다. 상품 편집에서 업체별 배분(비율/수량)·매핑을 설정하세요.</x-slot:desc>
    <button type="button" id="vd-open-modal" class="btn btn-primary btn-sm">＋ 업체 등록</button>
</x-console.page-head>

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

{{-- 검색 --}}
<form method="GET" class="card p-3 mb-4">
    <div class="flex items-center gap-2">
        <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
            @if ($q)<a href="{{ route('admin.vendors') }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>@endif
            <input name="q" value="{{ $q }}" class="input" style="width:260px;font-size:var(--fs-xs);" placeholder="업체명 검색">
            <button type="submit" class="btn btn-primary btn-sm" style="height:36px;">검색</button>
        </div>
    </div>
</form>

<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:900px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                    <th class="text-center px-3 py-3 font-semibold" style="width:44px;">No</th>
                    <th class="text-left px-5 py-3 font-semibold">업체명</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:190px;">채널</th>
                    <th class="text-left px-3 py-3 font-semibold">전송 대상</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:90px;">연결 상품</th>
                    <th class="text-center px-3 py-3 font-semibold" style="width:80px;">활성</th>
                    <th class="text-right px-5 py-3 font-semibold" style="width:150px;">작업</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($vendors as $v)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-3 py-3 text-center text-muted-soft" style="font-size:var(--fs-xs);">{{ $vendors->firstItem() + $loop->index }}</td>
                        <td class="px-5 py-3">
                            <div class="text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $v->name }}</div>
                            @if ($v->memo)<div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $v->memo }}</div>@endif
                        </td>
                        <td class="px-3 py-3 text-center text-body" style="font-size:var(--fs-xs);">{{ \App\Models\Vendor::CHANNELS[$v->channel] ?? $v->channel }}</td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            {{ $v->channel === 'gsheet' ? ('시트 '.$v->gsheet_id) : ($v->api_method.' '.$v->api_url) }}
                        </td>
                        <td class="px-3 py-3 text-center text-muted" style="font-size:var(--fs-xs);">{{ $v->product_vendors_count }}</td>
                        <td class="px-3 py-3 text-center">
                            <label class="rf-switch" title="{{ $v->is_active ? '활성 — 클릭하면 비활성' : '비활성 — 클릭하면 활성' }}">
                                <input type="checkbox" class="vd-toggle" data-url="{{ route('admin.vendors.toggle', $v) }}" @checked($v->is_active)>
                                <span class="rf-track"></span>
                            </label>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1" style="white-space:nowrap;">
                                <button type="button" class="btn btn-secondary btn-sm vd-edit"
                                        data-action="{{ route('admin.vendors.update', $v) }}"
                                        data-vendor="{{ json_encode(['name' => $v->name, 'channel' => $v->channel, 'api_url' => $v->api_url, 'api_method' => $v->api_method, 'api_headers' => $v->api_headers, 'api_format' => $v->api_format, 'gsheet_id' => $v->gsheet_id, 'memo' => $v->memo, 'is_active' => $v->is_active], JSON_UNESCAPED_UNICODE) }}">수정</button>
                                <form method="POST" action="{{ route('admin.vendors.destroy', $v) }}" class="inline" data-confirm="이 업체를 삭제할까요?" data-confirm-text="상품의 배분 설정도 함께 삭제됩니다(전송 이력은 보존).">@csrf @method('DELETE')
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center" style="padding:40px;color:var(--color-muted);font-size:var(--fs-xs);">등록된 업체가 없습니다. 우측 상단 "＋ 업체 등록"으로 만드세요.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $vendors->links() }}</div>

{{-- 등록/수정 모달 --}}
<div id="vd-modal" class="hidden" style="position:fixed;inset:0;z-index:50;">
    <div id="vd-modal-bg" style="position:absolute;inset:0;background:color-mix(in srgb, var(--color-ink) 40%, transparent);"></div>
    <div class="card" style="position:relative;max-width:640px;margin:7vh auto 0;max-height:84vh;overflow-y:auto;box-shadow:var(--shadow-card);">
        <div class="flex items-center justify-between px-5 border-b border-hairline-soft" style="height:52px;">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);" id="vd-modal-title">업체 등록</span>
            <button type="button" id="vd-modal-close" class="btn btn-ghost btn-sm" title="닫기">✕</button>
        </div>
        <form method="POST" action="{{ route('admin.vendors.store') }}" class="p-5" id="vd-form">
            @csrf
            <input type="hidden" name="_method" id="vd-method" value="PUT" disabled>

            <div class="flex gap-3 flex-wrap">
                <div style="flex:2;min-width:200px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">업체명</label>
                    <input name="name" id="vd-name" class="input" required maxlength="120" placeholder="예: A트래픽">
                </div>
                <div style="flex:1;min-width:130px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">채널</label>
                    <select name="channel" id="vd-channel" class="input">
                        @foreach (\App\Models\Vendor::CHANNELS as $code => $name)
                            <option value="{{ $code }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- API 채널 설정 --}}
            <div id="vd-api" class="mt-3">
                <div class="flex gap-3 flex-wrap">
                    <div style="width:110px;">
                        <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">메서드</label>
                        <select name="api_method" id="vd-api-method" class="input">
                            <option>POST</option><option>GET</option><option>PUT</option>
                        </select>
                    </div>
                    <div style="flex:1;min-width:240px;">
                        <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">API URL</label>
                        <input name="api_url" id="vd-api-url" class="input" maxlength="500" placeholder="https://vendor.example.com/api/orders">
                    </div>
                    <div style="width:110px;">
                        <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">본문 형식</label>
                        <select name="api_format" id="vd-api-format" class="input">
                            <option value="json">JSON</option><option value="form">Form</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">요청 헤더 (인증키 등, 선택) — 헤더명·값을 한 줄씩 추가</label>
                    <input type="hidden" name="api_headers" id="vd-api-headers">
                    <div id="vd-header-rows" class="flex flex-col gap-2"></div>
                    <button type="button" id="vd-header-add" class="btn btn-ghost btn-sm mt-1">＋ 헤더 추가</button>
                </div>
            </div>

            {{-- 구글시트 채널 설정 --}}
            <div id="vd-gsheet" class="mt-3" style="display:none;">
                <div>
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">스프레드시트 ID</label>
                    <input name="gsheet_id" id="vd-gsheet-id" class="input" maxlength="120" placeholder="URL의 /d/ 와 /edit 사이 값">
                </div>
                <p class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">
                    서비스 계정(.env <b>GOOGLE_SERVICE_ACCOUNT_JSON</b>=키 파일 경로)으로 인증하며, 시트를 서비스 계정 이메일에 <b>편집자로 공유</b>해야 합니다. 매핑 순서대로 열(A, B, C…)에 기록됩니다.
                    전송할 <b>탭은 상품 편집의 매핑에서 상품별로 선택</b>합니다(미선택 시 첫 번째 탭).
                </p>
            </div>

            <div class="mt-3">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">메모 (선택)</label>
                <input name="memo" id="vd-memo" class="input" maxlength="500" placeholder="담당자·정산 조건 등">
            </div>

            <div class="flex items-center justify-between mt-4">
                <span class="inline-flex items-center gap-2" style="font-size:var(--fs-xs);">
                    <input type="hidden" name="is_active" value="0">
                    <label class="rf-switch"><input type="checkbox" name="is_active" value="1" id="vd-active" checked><span class="rf-track"></span></label>
                    <span>활성</span>
                </span>
                <div class="flex items-center gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" id="vd-modal-cancel">취소</button>
                    <button type="submit" class="btn btn-primary" id="vd-submit">등록</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // ---- 활성 토글 (AJAX) ----
    document.querySelectorAll('.vd-toggle').forEach(function (el) {
        el.addEventListener('change', function () {
            el.disabled = true;
            fetch(el.dataset.url, { method: 'POST', headers: { 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) throw 0; })
                .catch(function () { el.checked = !el.checked; Swal.fire({ icon: 'error', title: '변경에 실패했습니다' }); })
                .finally(function () { el.disabled = false; });
        });
    });

    // ---- 등록/수정 모달 ----
    var modal = document.getElementById('vd-modal');
    var form = document.getElementById('vd-form');
    var method = document.getElementById('vd-method');
    var title = document.getElementById('vd-modal-title');
    var submit = document.getElementById('vd-submit');
    var storeUrl = @json(route('admin.vendors.store'));
    var channel = document.getElementById('vd-channel');

    function syncChannel() {
        document.getElementById('vd-api').style.display = channel.value === 'api' ? '' : 'none';
        document.getElementById('vd-gsheet').style.display = channel.value === 'gsheet' ? '' : 'none';
    }
    channel.addEventListener('change', syncChannel);

    // ---- 요청 헤더 — [헤더명][값] 행 단위 입력 (저장은 JSON 직렬화) ----
    var headerRows = document.getElementById('vd-header-rows');
    var headerHidden = document.getElementById('vd-api-headers');
    function addHeaderRow(k, v) {
        var row = document.createElement('div');
        row.className = 'vd-hrow flex items-center gap-2';
        row.innerHTML = '<input class="input vd-hname" placeholder="헤더명 (예: Authorization)" style="flex:1;height:34px;font-size:var(--fs-xs);">'
            + '<input class="input vd-hvalue" placeholder="값 (예: Bearer xxx)" style="flex:1.6;height:34px;font-size:var(--fs-xs);">'
            + '<button type="button" class="btn btn-ghost btn-sm vd-hdel" style="color:var(--color-error);">✕</button>';
        row.querySelector('.vd-hname').value = k || '';
        row.querySelector('.vd-hvalue').value = v || '';
        row.querySelector('.vd-hdel').addEventListener('click', function () { row.remove(); });
        headerRows.appendChild(row);
    }
    function setHeaderRows(json) {
        headerRows.innerHTML = '';
        var obj = {};
        try { obj = JSON.parse(json || '{}') || {}; } catch (e) {}
        var keys = Object.keys(obj);
        if (!keys.length) { addHeaderRow(); return; }
        keys.forEach(function (k) { addHeaderRow(k, obj[k]); });
    }
    document.getElementById('vd-header-add').addEventListener('click', function () { addHeaderRow(); });
    form.addEventListener('submit', function () {
        var obj = {};
        headerRows.querySelectorAll('.vd-hrow').forEach(function (r) {
            var k = r.querySelector('.vd-hname').value.trim();
            if (k) obj[k] = r.querySelector('.vd-hvalue').value;
        });
        headerHidden.value = Object.keys(obj).length ? JSON.stringify(obj) : '';
    });

    function openCreate() {
        form.action = storeUrl;
        method.disabled = true;
        title.textContent = '업체 등록';
        submit.textContent = '등록';
        ['vd-name', 'vd-api-url', 'vd-api-headers', 'vd-gsheet-id', 'vd-memo'].forEach(function (id) { document.getElementById(id).value = ''; });
        channel.value = 'api';
        document.getElementById('vd-api-method').value = 'POST';
        document.getElementById('vd-api-format').value = 'json';
        document.getElementById('vd-active').checked = true;
        setHeaderRows('');
        syncChannel();
        modal.classList.remove('hidden');
        setTimeout(function () { document.getElementById('vd-name').focus(); }, 50);
    }
    function openEdit(btn) {
        var d = JSON.parse(btn.dataset.vendor);
        form.action = btn.dataset.action;
        method.disabled = false;
        title.textContent = '업체 수정 — ' + (d.name || '');
        submit.textContent = '수정 저장';
        document.getElementById('vd-name').value = d.name || '';
        channel.value = d.channel || 'api';
        document.getElementById('vd-api-method').value = d.api_method || 'POST';
        document.getElementById('vd-api-url').value = d.api_url || '';
        setHeaderRows(d.api_headers || '');
        document.getElementById('vd-api-format').value = d.api_format || 'json';
        document.getElementById('vd-gsheet-id').value = d.gsheet_id || '';
        document.getElementById('vd-memo').value = d.memo || '';
        document.getElementById('vd-active').checked = !!d.is_active;
        syncChannel();
        modal.classList.remove('hidden');
    }
    function closeModal() { modal.classList.add('hidden'); }

    document.getElementById('vd-open-modal').addEventListener('click', openCreate);
    document.getElementById('vd-modal-close').addEventListener('click', closeModal);
    document.getElementById('vd-modal-cancel').addEventListener('click', closeModal);
    document.getElementById('vd-modal-bg').addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal(); });
    document.querySelectorAll('.vd-edit').forEach(function (btn) { btn.addEventListener('click', function () { openEdit(btn); }); });

    @if ($errors->any())
        openCreate();
        ['name', 'api_url', 'gsheet_id', 'memo'].forEach(function (k) {
            var el = form.querySelector('[name="' + k + '"]');
            if (el) el.value = @json(old()) [k] || '';
        });
        setHeaderRows(@json(old('api_headers', '')));
        channel.value = @json(old('channel', 'api'));
        syncChannel();
    @endif
})();
</script>
@endsection
