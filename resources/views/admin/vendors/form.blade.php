@extends('admin.layout')
@section('page-title', $vendor->exists ? '업체 수정' : '업체 등록')
@section('crumb-parent', 'admin.vendors')
@section('crumb-title', $vendor->exists ? $vendor->name : '업체 등록')

@section('admin-content')
@php
    $isEdit = $vendor->exists;
    // 초기 행 — 검증 실패 복원(old) 우선, 없으면 저장값
    $headers = old('api_headers') !== null
        ? (json_decode((string) old('api_headers'), true) ?: [])
        : $vendor->headers();
    $patterns = array_values(array_filter(
        array_map(fn ($p) => (string) $p, (array) old('shop_url_patterns', $vendor->shop_url_patterns ?? [])),
        fn ($p) => trim($p) !== '',
    ));
    $paramKeys = array_values(array_filter(
        array_map(fn ($p) => (string) $p, (array) old('shop_param_keys', $vendor->shop_param_keys ?? [])),
        fn ($p) => trim($p) !== '',
    ));
    $channel = old('channel', $vendor->channel ?: 'api');
@endphp

<x-console.page-head :title="$isEdit ? '업체 수정' : '업체 등록'">
    <x-slot:desc>{{ $isEdit ? $vendor->name.' 업체의 전송 설정을 수정합니다' : '외부 발주 업체를 등록합니다' }} — 상품 편집에서 업체별 배분(비율/수량)·매핑을 설정하세요.</x-slot:desc>
    <a href="{{ route('admin.vendors') }}" class="btn btn-secondary btn-sm">← 목록</a>
</x-console.page-head>

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ $isEdit ? route('admin.vendors.update', $vendor) : route('admin.vendors.store') }}" id="vd-form">
    @csrf
    @if ($isEdit) @method('PUT') @endif

    {{-- 기본 --}}
    <div class="card p-6 mb-4">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">기본 정보</div>
        <div class="flex gap-3 flex-wrap">
            <div style="flex:2;min-width:240px;">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">업체명</label>
                <input name="name" class="input" required maxlength="120" placeholder="예: A트래픽" value="{{ old('name', $vendor->name) }}">
            </div>
            <div style="flex:1;min-width:160px;">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">채널</label>
                <select name="channel" id="vd-channel" class="input">
                    @foreach (\App\Models\Vendor::CHANNELS as $code => $label)
                        <option value="{{ $code }}" @selected($channel === $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-3">
            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">메모 (선택)</label>
            <input name="memo" class="input" maxlength="500" placeholder="담당자·정산 조건 등" value="{{ old('memo', $vendor->memo) }}">
        </div>
    </div>

    {{-- API 채널 --}}
    <div class="card p-6 mb-4" id="vd-api">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">API 전송 설정</div>
        <div class="flex gap-3 flex-wrap">
            <div style="width:120px;">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">메서드</label>
                <select name="api_method" class="input">
                    @foreach (['POST', 'GET', 'PUT'] as $m)
                        <option @selected(old('api_method', $vendor->api_method ?: 'POST') === $m)>{{ $m }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:1;min-width:280px;">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">API URL</label>
                <input name="api_url" class="input" maxlength="500" placeholder="https://vendor.example.com/api/orders" value="{{ old('api_url', $vendor->api_url) }}">
            </div>
            <div style="width:120px;">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">본문 형식</label>
                <select name="api_format" class="input">
                    <option value="json" @selected(old('api_format', $vendor->api_format ?: 'json') === 'json')>JSON</option>
                    <option value="form" @selected(old('api_format', $vendor->api_format) === 'form')>Form</option>
                </select>
            </div>
        </div>
        <div class="mt-3">
            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">요청 헤더 (인증키 등, 선택) — 헤더명·값을 한 줄씩 추가</label>
            <input type="hidden" name="api_headers" id="vd-api-headers">
            <div id="vd-header-rows" class="flex flex-col gap-2">
                @foreach ($headers as $k => $v)
                    <div class="vd-hrow flex items-center gap-2">
                        <input class="input vd-hname" placeholder="헤더명 (예: Authorization)" style="flex:1;height:34px;font-size:var(--fs-xs);" value="{{ $k }}">
                        <input class="input vd-hvalue" placeholder="값 (예: Bearer xxx)" style="flex:1.6;height:34px;font-size:var(--fs-xs);" value="{{ $v }}">
                        <button type="button" class="btn btn-ghost btn-sm vd-hdel" title="삭제" style="color:var(--color-error);">✕</button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="vd-header-add" class="btn btn-ghost btn-sm mt-1">＋ 헤더 추가</button>
        </div>
    </div>

    {{-- 구글시트 채널 --}}
    <div class="card p-6 mb-4" id="vd-gsheet">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">구글시트 전송 설정</div>
        <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">스프레드시트 ID</label>
        <input name="gsheet_id" class="input" maxlength="120" placeholder="URL의 /d/ 와 /edit 사이 값" value="{{ old('gsheet_id', $vendor->gsheet_id) }}">
        <p class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">
            서비스 계정(.env <b>GOOGLE_SERVICE_ACCOUNT_JSON</b>=키 파일 경로)으로 인증하며, 시트를 서비스 계정 이메일에 <b>편집자로 공유</b>해야 합니다. 매핑 순서대로 열(A, B, C…)에 기록됩니다.
            전송할 <b>탭은 상품 편집의 매핑에서 상품별로 선택</b>합니다(미선택 시 첫 번째 탭).
        </p>
    </div>

    {{-- 발주 타이밍 --}}
    <div class="card p-6 mb-4">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">발주 타이밍</div>
        <span class="inline-flex items-center gap-2" style="font-size:var(--fs-xs);">
            <input type="hidden" name="weekend_batch_dispatch" value="0">
            <label class="rf-switch"><input type="checkbox" name="weekend_batch_dispatch" value="1" id="vd-weekend" @checked(old('weekend_batch_dispatch', $vendor->weekend_batch_dispatch))><span class="rf-track"></span></label>
            <span class="text-ink font-semibold">주말 몰아 발주 (금요일 자동)</span>
        </span>
        <p class="text-muted-soft mt-1.5" style="font-size:var(--fs-xs);">켜면 이 업체는 주말에 주문을 받지 않는 것으로 보고, <b class="text-muted">토·일·월 발주분을 직전 금요일에 한꺼번에 자동 전송</b>합니다(승인·발주 및 매일 아침 스케줄러 공통 · 세부주문 회차는 그대로 유지).</p>
    </div>

    {{-- 쇼핑 주문 링크 설정(2026-07-25) — 거래처마다 주문 받는 형태가 다르다.
         ⚠️ 플레이스 주문은 방식이 다를 수 있어 여기 설정은 쇼핑 전용(필요해지면 플레이스 설정을 따로 둔다). --}}
    <div class="card p-6 mb-4">
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">쇼핑 주문 설정 <span class="text-muted-soft" style="font-weight:400;font-size:var(--fs-xs);">· 플레이스는 별도</span></div>
        <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">업체마다 주문 받는 형태가 달라 업체 단위로 지정합니다.</p>

        <label class="block text-ink font-semibold mb-1" style="font-size:var(--fs-xs);">랜딩 URL 배정 방식</label>
        <select name="shop_link_mode" id="vd-link-mode" class="input" style="max-width:420px;">
            @foreach (\App\Models\Vendor::LINK_MODES as $code => $label)
                <option value="{{ $code }}" @selected(old('shop_link_mode', $vendor->shop_link_mode ?: 'group') === $code)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="text-muted-soft mt-1.5" style="font-size:var(--fs-xs);">
            <b class="text-muted">분석 링크 순환</b> — 유입키워드 분석 링크 1개에 키워드가 묶여 있고 회차마다 돌려 씁니다(기존 방식).<br>
            <b class="text-muted">파라미터 값 변경</b> — 지정한 파라미터의 값이 바뀌는 링크를 씁니다. 아래에 <b class="text-muted">바뀌는 파라미터</b>를 적어 두세요.<br>
            <b class="text-muted">등록 URL 순서대로</b> — 아래 등록해둔 URL을 <b class="text-muted">순서대로 그대로</b> 사용합니다. 파라미터를 바꾸지 않습니다.
        </p>

        {{-- 파라미터 값 변경 방식에서 어떤 파라미터가 바뀌는지 — 설정 기록용(이름만) --}}
        <label class="block text-ink font-semibold mt-5 mb-1" style="font-size:var(--fs-xs);">바뀌는 파라미터 (선택)</label>
        <p class="text-muted-soft mb-2" style="font-size:var(--fs-xs);"><b class="text-muted">파라미터 값 변경</b> 방식일 때, 이 업체가 값을 바꾸는 파라미터 이름을 적어 둡니다(예: query).</p>
        <div id="vd-param-rows" class="flex flex-col gap-2">
            @foreach ($paramKeys as $i => $k)
                <div class="vd-krow flex items-center gap-2">
                    <span class="vd-kno text-muted-soft font-mono flex-none" style="font-size:var(--fs-xs);width:22px;text-align:right;">{{ $i + 1 }}</span>
                    <input name="shop_param_keys[]" class="input vd-kvalue" maxlength="60" placeholder="파라미터 이름" style="flex:1;height:34px;font-size:var(--fs-xs);" value="{{ $k }}">
                    <button type="button" class="btn btn-ghost btn-sm vd-kdel" title="삭제" style="color:var(--color-error);">✕</button>
                </div>
            @endforeach
        </div>
        <button type="button" id="vd-param-add" class="btn btn-ghost btn-sm mt-1">＋ 파라미터 추가</button>

        <label class="block text-ink font-semibold mt-5 mb-1" style="font-size:var(--fs-xs);">랜딩 URL / 패턴 (선택)</label>
        <p class="text-muted-soft mb-2" style="font-size:var(--fs-xs);">이 업체에 넘길 URL(또는 형식)을 적어 둡니다. <b class="text-muted">입력 순서 = 사용 순서</b>(위에서부터) — <b class="text-muted">등록 URL 순서대로</b> 방식일 때 이 목록을 씁니다.</p>
        <div id="vd-pattern-rows" class="flex flex-col gap-2">
            @foreach ($patterns as $i => $p)
                <div class="vd-prow flex items-center gap-2">
                    <span class="text-muted-soft font-mono flex-none" style="font-size:var(--fs-xs);width:22px;text-align:right;">{{ $i + 1 }}</span>
                    <input name="shop_url_patterns[]" class="input vd-pvalue" maxlength="1000" placeholder="이 업체가 요구하는 URL 형식" style="flex:1;height:34px;font-size:var(--fs-xs);" value="{{ $p }}">
                    <button type="button" class="btn btn-ghost btn-sm vd-pdel" title="삭제" style="color:var(--color-error);">✕</button>
                </div>
            @endforeach
        </div>
        <button type="button" id="vd-pattern-add" class="btn btn-ghost btn-sm mt-1">＋ 패턴 추가</button>
    </div>

    {{-- 저장 --}}
    <div class="card p-6 flex items-center justify-between flex-wrap gap-3">
        <span class="inline-flex items-center gap-2" style="font-size:var(--fs-xs);">
            <input type="hidden" name="is_active" value="0">
            <label class="rf-switch"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $isEdit ? $vendor->is_active : true))><span class="rf-track"></span></label>
            <span>활성</span>
        </span>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.vendors') }}" class="btn btn-secondary btn-sm">취소</a>
            <button type="submit" class="btn btn-primary">{{ $isEdit ? '수정 저장' : '등록' }}</button>
        </div>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('vd-form');
    var channel = document.getElementById('vd-channel');

    // 채널에 따라 해당 전송 설정 카드만 표시
    function syncChannel() {
        document.getElementById('vd-api').style.display = channel.value === 'api' ? '' : 'none';
        document.getElementById('vd-gsheet').style.display = channel.value === 'gsheet' ? '' : 'none';
    }
    channel.addEventListener('change', syncChannel);
    syncChannel();

    // ---- 요청 헤더 — [헤더명][값] 행 단위 입력 (제출 시 JSON 직렬화) ----
    var headerRows = document.getElementById('vd-header-rows');
    var headerHidden = document.getElementById('vd-api-headers');
    function bindHeaderDel(row) { row.querySelector('.vd-hdel').addEventListener('click', function () { row.remove(); }); }
    function addHeaderRow() {
        var row = document.createElement('div');
        row.className = 'vd-hrow flex items-center gap-2';
        row.innerHTML = '<input class="input vd-hname" placeholder="헤더명 (예: Authorization)" style="flex:1;height:34px;font-size:var(--fs-xs);">'
            + '<input class="input vd-hvalue" placeholder="값 (예: Bearer xxx)" style="flex:1.6;height:34px;font-size:var(--fs-xs);">'
            + '<button type="button" class="btn btn-ghost btn-sm vd-hdel" title="삭제" style="color:var(--color-error);">✕</button>';
        headerRows.appendChild(row);
        bindHeaderDel(row);
    }
    headerRows.querySelectorAll('.vd-hrow').forEach(bindHeaderDel);
    if (!headerRows.children.length) addHeaderRow();
    document.getElementById('vd-header-add').addEventListener('click', addHeaderRow);

    // ---- URL 패턴 행 (입력 순서 = 사용 순서) ----
    var patternRows = document.getElementById('vd-pattern-rows');
    function renumber() {
        patternRows.querySelectorAll('.vd-prow').forEach(function (r, i) {
            var n = r.querySelector('.vd-pno');
            if (n) n.textContent = i + 1;
        });
    }
    function bindPatternDel(row) { row.querySelector('.vd-pdel').addEventListener('click', function () { row.remove(); renumber(); }); }
    function addPatternRow() {
        var row = document.createElement('div');
        row.className = 'vd-prow flex items-center gap-2';
        row.innerHTML = '<span class="vd-pno text-muted-soft font-mono flex-none" style="font-size:var(--fs-xs);width:22px;text-align:right;"></span>'
            + '<input name="shop_url_patterns[]" class="input vd-pvalue" maxlength="1000" placeholder="이 업체가 요구하는 URL 형식" style="flex:1;height:34px;font-size:var(--fs-xs);">'
            + '<button type="button" class="btn btn-ghost btn-sm vd-pdel" title="삭제" style="color:var(--color-error);">✕</button>';
        patternRows.appendChild(row);
        bindPatternDel(row);
        renumber();
    }
    patternRows.querySelectorAll('.vd-prow').forEach(function (r) {
        // 서버 렌더 행의 번호 span 에 클래스 부여(재번호 대상)
        var s = r.querySelector('span');
        if (s) s.classList.add('vd-pno');
        bindPatternDel(r);
    });
    if (!patternRows.children.length) addPatternRow();
    document.getElementById('vd-pattern-add').addEventListener('click', addPatternRow);

    // ---- 바뀌는 파라미터 행 (이름만 기록) ----
    var paramRows = document.getElementById('vd-param-rows');
    function renumberParams() {
        paramRows.querySelectorAll('.vd-krow').forEach(function (r, i) {
            var n = r.querySelector('.vd-kno');
            if (n) n.textContent = i + 1;
        });
    }
    function bindParamDel(row) { row.querySelector('.vd-kdel').addEventListener('click', function () { row.remove(); renumberParams(); }); }
    function addParamRow() {
        var row = document.createElement('div');
        row.className = 'vd-krow flex items-center gap-2';
        row.innerHTML = '<span class="vd-kno text-muted-soft font-mono flex-none" style="font-size:var(--fs-xs);width:22px;text-align:right;"></span>'
            + '<input name="shop_param_keys[]" class="input vd-kvalue" maxlength="60" placeholder="파라미터 이름" style="flex:1;height:34px;font-size:var(--fs-xs);">'
            + '<button type="button" class="btn btn-ghost btn-sm vd-kdel" title="삭제" style="color:var(--color-error);">✕</button>';
        paramRows.appendChild(row);
        bindParamDel(row);
        renumberParams();
    }
    paramRows.querySelectorAll('.vd-krow').forEach(bindParamDel);
    if (!paramRows.children.length) addParamRow();
    document.getElementById('vd-param-add').addEventListener('click', addParamRow);

    // 제출 — 헤더 행을 JSON 으로 모아 hidden 에 담는다
    form.addEventListener('submit', function () {
        var obj = {};
        headerRows.querySelectorAll('.vd-hrow').forEach(function (r) {
            var k = r.querySelector('.vd-hname').value.trim();
            if (k) obj[k] = r.querySelector('.vd-hvalue').value;
        });
        headerHidden.value = Object.keys(obj).length ? JSON.stringify(obj) : '';
    });
})();
</script>
@endsection
