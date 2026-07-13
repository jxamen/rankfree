@extends('admin.layout')
@section('page-title', '환경 설정')

@push('head')
<style>
    .rf-tabs { display:flex; gap:2px; border-bottom:1px solid var(--color-hairline); margin-bottom:20px; }
    .rf-tab { padding:9px 18px; font-size:var(--fs-sm); font-weight:600; color:var(--color-muted); background:none; border:0; border-bottom:2px solid transparent; margin-bottom:-1px; cursor:pointer; transition:color .12s ease, border-color .12s ease; }
    .rf-tab:hover { color:var(--color-ink); }
    .rf-tab.on { color:var(--color-primary); border-bottom-color:var(--color-primary); }
    .rf-tabpane[hidden] { display:none; }
</style>
@endpush

@section('admin-content')
<form method="POST" action="{{ route('admin.settings.update') }}" class="max-w-3xl">
    @csrf @method('PUT')

    <div class="rf-tabs" role="tablist">
        <button type="button" class="rf-tab on" data-tab="basic" role="tab">광고·데이터 API</button>
        <button type="button" class="rf-tab" data-tab="api" role="tab">AI API</button>
    </div>

    {{-- ── 기본: 네이버 데이터 수집 자격증명 ───────────────────────────── --}}
    <div class="rf-tabpane" data-tab="basic">
        <p class="text-muted mb-4" style="font-size:var(--fs-xs);">
            네이버 데이터 수집에 필요한 자격증명입니다. 각 항목은 <b>여러 개 등록</b>할 수 있고(조회 실패 시 자동 로테이션),
            값은 <b>암호화 저장</b>되며 <code>.env</code>보다 우선 적용됩니다.
            비밀 키·비밀번호는 기본적으로 가려져 있고 <i class="fa-regular fa-eye"></i>(보기)로 확인·<i class="fa-regular fa-copy"></i>(복사)할 수 있으며,
            <b class="text-error"><i class="fa-solid fa-xmark"></i></b>를 누르면 해당 줄이 바로 사라집니다(저장 시 반영).
        </p>

        @include('admin.settings._credgroup', [
            'g' => 'searchad',
            'title' => '네이버 검색광고 API',
            'desc' => '키워드 분석(월간 검색량·경쟁강도)에 사용 — "여름매트" 조회 실패는 이 값이 없어 발생합니다. 발급: manage.searchad.naver.com → 도구 → API 사용 관리',
            'plain' => ['api_key', 'customer_id'],
            'secret' => 'secret_key',
            'labels' => ['api_key' => 'API 키(액세스 라이선스)', 'customer_id' => 'Customer ID', 'secret_key' => '비밀 키'],
            'rows' => $searchadRows,
            'live' => $liveSearchad,
        ])

        @include('admin.settings._credgroup', [
            'g' => 'ads',
            'title' => '네이버 광고주 로그인',
            'desc' => '성별·연령별 비율, 월별 검색 트렌드(웹 크롤링 세션)에 사용. 광고주 계정 아이디/비밀번호만 필요합니다.',
            'plain' => ['id'],
            'secret' => 'pw',
            'labels' => ['id' => '광고주 아이디', 'pw' => '비밀번호'],
            'rows' => $adsRows,
            'live' => $liveAds,
        ])

        @include('admin.settings._credgroup', [
            'g' => 'openapi',
            'title' => '네이버 OpenAPI · 데이터랩(트렌드) 키',
            'desc' => '쇼핑 검색·발행량·데이터랩 트렌드에 공용. developers.naver.com 애플리케이션의 Client ID/Secret.',
            'plain' => ['id'],
            'secret' => 'secret',
            'labels' => ['id' => 'Client ID', 'secret' => 'Client Secret'],
            'rows' => $openapiRows,
            'live' => $liveOpenapi,
        ])
    </div>

    {{-- ── API 설정: AI 모델 키 ─────────────────────────────────────── --}}
    <div class="rf-tabpane" data-tab="api" hidden>
        <p class="text-muted mb-4" style="font-size:var(--fs-xs);">
            AI 모델(LLM) API 키를 관리합니다. 커뮤니티 글·댓글 자동 생성 등에 사용되며, 값은 <b>암호화 저장</b>됩니다.
            비밀 키는 기본적으로 가려져 있고 <i class="fa-regular fa-eye"></i>(보기)·<i class="fa-regular fa-copy"></i>(복사)할 수 있습니다.
        </p>

        @include('admin.settings._aigroup', [
            'rows' => $aiRows,
            'providers' => $aiProviders,
            'live' => $liveAi,
        ])
    </div>

    <div class="flex items-center gap-2">
        <button type="submit" class="btn btn-primary">저장</button>
        <a href="{{ route('admin.home') }}" class="btn btn-secondary">취소</a>
    </div>
</form>

<script>
(function () {
    // ── 탭 전환 (숨긴 탭 입력도 폼 전송됨) ──
    var tabs = document.querySelectorAll('.rf-tab');
    var panes = document.querySelectorAll('.rf-tabpane');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (x) { x.classList.toggle('on', x === t); });
            panes.forEach(function (p) { p.hidden = (p.dataset.tab !== t.dataset.tab); });
        });
    });

    // ── 줄 추가 — 그룹 템플릿 복제 후 append ──
    document.querySelectorAll('.rf-cred-add').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var g = btn.dataset.group;
            var tpl = document.querySelector('.rf-cred-tpl[data-group="' + g + '"]');
            var wrap = document.querySelector('.rf-cred-wrap[data-group="' + g + '"]');
            if (!tpl || !wrap) return;
            var row = tpl.content.firstElementChild.cloneNode(true);
            wrap.appendChild(row);
            var first = row.querySelector('input, select'); if (first) first.focus();
        });
    });

    // ── 위임: 삭제(즉시) · 보기/가리기 · 복사 ──
    document.addEventListener('click', function (e) {
        // 즉시 삭제 — 클릭하면 라인이 바로 사라짐(저장 시 반영)
        var del = e.target.closest('.rf-cred-del');
        if (del) { var r = del.closest('.rf-cred-row'); if (r) r.remove(); return; }

        // 보기/가리기 토글
        var show = e.target.closest('.rf-secret-show');
        if (show) {
            var inp = show.closest('.rf-secret').querySelector('.rf-secret-input');
            var ic = show.querySelector('i');
            if (inp.type === 'password') { inp.type = 'text'; if (ic) ic.className = 'fa-regular fa-eye-slash'; }
            else { inp.type = 'password'; if (ic) ic.className = 'fa-regular fa-eye'; }
            return;
        }

        // 복사
        var copy = e.target.closest('.rf-secret-copy');
        if (copy) {
            var target = copy.closest('.rf-secret').querySelector('.rf-secret-input');
            var val = target ? target.value : '';
            var mark = copy.querySelector('i');
            var restore = function () { if (mark) mark.className = 'fa-regular fa-copy'; };
            var ok = function () { if (mark) mark.className = 'fa-solid fa-check'; setTimeout(restore, 1200); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(val).then(ok, function () { fallbackCopy(target); ok(); });
            } else { fallbackCopy(target); ok(); }
            return;
        }
    });

    function fallbackCopy(input) {
        if (!input) return;
        var t = input.type; input.type = 'text';
        input.focus(); input.select();
        try { document.execCommand('copy'); } catch (x) {}
        input.type = t; input.blur();
    }
})();
</script>
@endsection
