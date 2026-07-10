@extends('admin.layout')
@section('page-title', '메뉴 관리')

@section('admin-content')
<div style="max-width:1040px;">
    {{-- area 탭 --}}
    <div class="flex gap-2 mb-5 items-center flex-wrap">
        @foreach (['console' => '콘솔 메뉴', 'admin' => '관리 메뉴'] as $a => $l)
            <a href="{{ route('admin.menus', ['area' => $a]) }}" class="btn btn-sm {{ $area === $a ? 'btn-primary' : 'btn-secondary' }}">{{ $l }}</a>
        @endforeach
        <span class="text-muted-soft" style="font-size:12px;">⠿ 드래그로 순서·이동. 대분류와 미분류 항목은 최상위에서 자유 배치, 항목은 그룹 간 이동 가능</span>
    </div>

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:13px;">{{ $errors->first() }}</div>
    @endif

    {{-- 메뉴 추가 --}}
    <details class="card mb-6" style="padding:16px 20px;">
        <summary class="cursor-pointer text-ink font-semibold" style="font-size:14px;">＋ 메뉴 추가</summary>
        <div class="flex gap-10 flex-wrap mt-4">
            {{-- 대분류 (아이콘 피커 포함) --}}
            <form method="POST" action="{{ route('admin.menus.store') }}" style="min-width:300px;">
                @csrf
                <input type="hidden" name="area" value="{{ $area }}"><input type="hidden" name="kind" value="group">
                <label class="block text-muted mb-1" style="font-size:12px;font-weight:600;">＋ 대분류</label>
                <input name="name" class="input mb-3" placeholder="그룹명" required>
                <label class="block text-muted mb-1" style="font-size:12px;">아이콘 <span class="text-muted-soft">(대분류 레일)</span></label>
                @include('admin.partials.icon-picker', ['name' => 'icon', 'value' => '', 'uid' => 'addgrp'])
                <button type="submit" class="btn btn-primary mt-3">대분류 추가</button>
            </form>
            {{-- 미분류 항목 --}}
            <form method="POST" action="{{ route('admin.menus.store') }}" class="flex gap-2 items-end flex-wrap" style="align-self:flex-start;">
                @csrf
                <input type="hidden" name="area" value="{{ $area }}"><input type="hidden" name="kind" value="item">
                <div><label class="block text-muted mb-1" style="font-size:12px;">＋ 미분류 항목</label><input name="name" class="input" placeholder="메뉴명" required></div>
                <div><label class="block text-muted mb-1" style="font-size:12px;">라우트명</label><input name="route" class="input" placeholder="console.rank"></div>
                <button type="submit" class="btn btn-secondary">항목</button>
            </form>
        </div>
    </details>

    {{-- 최상위 통합 트리 (대분류 + 미분류 항목, sort 순서대로) --}}
    <div data-sortable-top>
        @foreach ($roots as $node)
            @if ($node->is_group)
                @include('admin.partials.menu-group', ['g' => $node])
            @else
                <div class="card mb-3" data-id="{{ $node->id }}">
                    <div class="flex items-stretch">
                        <span class="tdrag text-muted-soft" style="cursor:move;display:flex;align-items:center;padding:0 10px;" title="드래그하여 위치 변경">⠿</span>
                        <div class="flex-1" style="border-left:1px solid var(--color-hairline-soft);">
                            @include('admin.partials.menu-item', ['item' => $node])
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
    @if (! $roots->count())
        <div class="card text-center" style="padding:40px;color:var(--color-muted);font-size:13px;">메뉴가 없습니다. 위에서 추가하세요.</div>
    @endif
</div>

{{-- 아이콘 피커 스타일 (crm 이식, Cal.com 토큰) --}}
<style>
    .icon-picker .ip-preview { width:44px; height:44px; border-radius:9px; background:var(--color-surface-card); color:var(--color-primary); display:inline-flex; align-items:center; justify-content:center; font-size:20px; flex:0 0 auto; }
    .icon-picker .ip-grid { display:grid; grid-template-columns:repeat(auto-fill, 40px); gap:6px; margin-top:10px; padding:6px; border:1px solid var(--color-hairline); border-radius:8px; max-height:240px; overflow-y:auto; }
    .icon-picker .ip-grid button { width:40px; height:40px; border:1px solid var(--color-hairline); border-radius:8px; background:var(--color-canvas); color:var(--color-muted); font-size:16px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; }
    .icon-picker .ip-grid button:hover { border-color:var(--color-primary); color:var(--color-primary); }
    .icon-picker .ip-grid button.sel { background:var(--color-primary); color:#fff; border-color:var(--color-primary); }
    .icon-picker .ip-grid .ig-empty { grid-column:1 / -1; text-align:center; color:var(--color-muted-soft); font-size:12px; padding:12px 4px; }
</style>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
    const RF_CSRF = '{{ csrf_token() }}';
    function tgl(id) { const e = document.getElementById(id); if (e) e.classList.toggle('hidden'); }
    function rfPostOrder(items) {
        fetch('{{ route('admin.menus.reorder') }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': RF_CSRF }, body: JSON.stringify({ order: items }) });
    }
    function rfOrderOf(c) {
        const parent = c.dataset.parent || '';
        return [...c.children].filter(x => x.dataset.id).map((x, i) => ({ id: x.dataset.id, parent_id: parent, sort_order: i }));
    }
    (function () {
        if (typeof window.Sortable !== 'function') { console.error('SortableJS not loaded'); return; }
        const topEl = document.querySelector('[data-sortable-top]');
        if (topEl) {
            new Sortable(topEl, { handle: '.gdrag, .tdrag', draggable: '[data-id]', animation: 150, onEnd: function () {
                rfPostOrder([...topEl.children].filter(x => x.dataset.id).map((x, i) => ({ id: x.dataset.id, parent_id: '', sort_order: i })));
            } });
        }
        document.querySelectorAll('[data-sortable-items]').forEach(function (el) {
            new Sortable(el, { group: 'menu-items', handle: '.drag', animation: 150, onEnd: function (e) { rfPostOrder(rfOrderOf(e.to)); if (e.from !== e.to) rfPostOrder(rfOrderOf(e.from)); } });
        });
    })();
    document.querySelectorAll('.menu-toggle').forEach(function (cb) {
        cb.addEventListener('change', function () {
            const fd = new URLSearchParams(); fd.append('is_active', cb.checked ? 1 : 0);
            fetch('/admin/menus/' + cb.dataset.id + '/toggle', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': RF_CSRF }, body: fd });
        });
    });

    // ===== 아이콘 피커 (crm menu_group_edit 이식, 멀티 인스턴스) =====
    (function () {
        var PRESET = ['fas fa-database','fas fa-server','fas fa-users','fas fa-user','fas fa-user-tie','fas fa-id-card','fas fa-briefcase','fas fa-building','fas fa-store','fas fa-industry','fas fa-handshake','fas fa-sitemap','fas fa-box','fas fa-boxes-stacked','fas fa-cube','fas fa-tags','fas fa-tag','fas fa-barcode','fas fa-bolt','fas fa-fire','fas fa-rocket','fas fa-truck','fas fa-truck-fast','fas fa-dolly','fas fa-credit-card','fas fa-coins','fas fa-money-bill-wave','fas fa-wallet','fas fa-calculator','fas fa-receipt','fas fa-chart-line','fas fa-chart-bar','fas fa-chart-pie','fas fa-table','fas fa-list','fas fa-clipboard','fas fa-file','fas fa-file-lines','fas fa-file-contract','fas fa-file-invoice','fas fa-folder','fas fa-folder-open','fas fa-cog','fas fa-gears','fas fa-wrench','fas fa-sliders','fas fa-toolbox','fas fa-screwdriver-wrench','fas fa-headset','fas fa-phone','fas fa-comment-dots','fas fa-comments','fas fa-envelope','fas fa-bell','fas fa-bullhorn','fas fa-globe','fas fa-map-location-dot','fas fa-calendar-days','fas fa-clock','fas fa-star','fas fa-code','fas fa-plug','fas fa-key','fas fa-lock','fas fa-shield-halved','fas fa-gauge-high','fas fa-house','fas fa-user-group','fas fa-user-plus','fas fa-address-book','fas fa-people-group','fas fa-people-arrows','fas fa-warehouse','fas fa-cart-shopping','fas fa-bag-shopping','fas fa-basket-shopping','fas fa-gift','fas fa-percent','fas fa-ticket','fas fa-money-check','fas fa-money-bill-trend-up','fas fa-piggy-bank','fas fa-vault','fas fa-landmark','fas fa-scale-balanced','fas fa-file-excel','fas fa-file-pdf','fas fa-file-export','fas fa-file-import','fas fa-print','fas fa-paperclip','fas fa-thumbtack','fas fa-pen','fas fa-pencil','fas fa-magnifying-glass','fas fa-filter','fas fa-diagram-project','fas fa-network-wired','fas fa-microchip','fas fa-hard-drive','fas fa-cloud','fas fa-cloud-arrow-up','fas fa-wifi','fas fa-signal','fas fa-tower-broadcast','fas fa-satellite-dish','fas fa-mobile-screen','fas fa-tablet-screen-button','fas fa-desktop','fas fa-laptop','fas fa-tv','fas fa-camera','fas fa-image','fas fa-video','fas fa-music','fas fa-headphones','fas fa-microphone','fas fa-volume-high','fas fa-play','fas fa-circle-check','fas fa-circle-xmark','fas fa-circle-info','fas fa-triangle-exclamation','fas fa-flag','fas fa-location-dot','fas fa-compass','fas fa-route','fas fa-car','fas fa-motorcycle','fas fa-plane','fas fa-ship','fas fa-train','fas fa-bicycle','fas fa-person-walking','fas fa-utensils','fas fa-mug-hot','fas fa-cookie','fas fa-cake-candles','fas fa-heart','fas fa-thumbs-up','fas fa-face-smile','fas fa-hand','fas fa-handshake-angle','fas fa-award','fas fa-trophy','fas fa-medal','fas fa-crown','fas fa-gem','fas fa-lightbulb','fas fa-snowflake','fas fa-sun','fas fa-moon','fas fa-droplet','fas fa-leaf','fas fa-tree','fas fa-seedling','fas fa-paw','fas fa-dog','fas fa-cat','fas fa-bug','fas fa-virus','fas fa-pills','fas fa-syringe','fas fa-stethoscope','fas fa-heart-pulse','fas fa-hospital','fas fa-user-doctor','fas fa-graduation-cap','fas fa-book','fas fa-book-open','fas fa-newspaper','fas fa-pen-nib','fas fa-palette','fas fa-shapes','fas fa-puzzle-piece','fas fa-dice','fas fa-gamepad','fas fa-chess','fas fa-football','fas fa-basketball','fas fa-dumbbell','fas fa-person-running','fas fa-flag-checkered','fas fa-bullseye','fas fa-life-ring','fas fa-anchor'];
        var FA_META = null, FA_LOADING = null;
        var FA_URL = 'https://cdn.jsdelivr.net/gh/FortAwesome/Font-Awesome@6.4.0/metadata/icons.json';
        var STYLE_PREFIX = { solid: 'fas', regular: 'far', brands: 'fab' };
        function loadMeta() {
            if (FA_META) return Promise.resolve(FA_META);
            if (FA_LOADING) return FA_LOADING;
            FA_LOADING = fetch(FA_URL).then(function (r) { return r.json(); }).then(function (j) {
                var arr = [];
                Object.keys(j).forEach(function (name) {
                    var d = j[name] || {};
                    var free = (d.free && d.free.length) ? d.free : (d.styles || []);
                    if (!free.length) return;
                    var terms = (d.search && d.search.terms) || [];
                    var label = (d.label || '').toLowerCase();
                    free.forEach(function (st) { var pfx = STYLE_PREFIX[st]; if (pfx) arr.push({ cls: pfx + ' fa-' + name, name: name, label: label, terms: terms }); });
                });
                FA_META = arr; return arr;
            }).catch(function () { FA_LOADING = null; return null; });
            return FA_LOADING;
        }
        function initPicker(root) {
            var input = root.querySelector('.ip-input');
            var preview = root.querySelector('.ip-preview');
            var search = root.querySelector('.ip-search');
            var grid = root.querySelector('.ip-grid');
            var hint = root.querySelector('.ip-hint');
            var clear = root.querySelector('.ip-clear');
            function setHint(t) { if (hint) hint.textContent = t; }
            function renderPreview() {
                var v = (input.value || '').trim();
                preview.innerHTML = (v && v.indexOf('fa-') !== -1) ? '<i class="' + v + '"></i>' : (v || '');
                grid.querySelectorAll('button').forEach(function (b) { b.classList.toggle('sel', b.dataset.cls === v); });
            }
            function renderIcons(list) {
                grid.innerHTML = '';
                if (!list.length) { var e = document.createElement('div'); e.className = 'ig-empty'; e.textContent = '검색 결과가 없습니다.'; grid.appendChild(e); return; }
                var frag = document.createDocumentFragment();
                list.forEach(function (ic) {
                    var b = document.createElement('button'); b.type = 'button'; b.dataset.cls = ic; b.title = ic;
                    b.innerHTML = '<i class="' + ic + '"></i>';
                    b.addEventListener('click', function () { input.value = ic; renderPreview(); });
                    frag.appendChild(b);
                });
                grid.appendChild(frag); renderPreview();
            }
            function runSearch() {
                var q = (search.value || '').trim().toLowerCase();
                if (!q) { renderIcons(PRESET); setHint('자주 쓰는 아이콘입니다. 검색하면 무료 아이콘 전체에서 찾습니다. (영문 키워드)'); return; }
                if (!FA_META) { setHint('아이콘 목록을 불러오는 중…'); loadMeta().then(function (m) { if (!m) { setHint('목록을 불러오지 못했습니다. 위 칸에 직접 입력하세요.'); return; } runSearch(); }); return; }
                var res = FA_META.filter(function (it) {
                    if (it.name.indexOf(q) !== -1 || it.label.indexOf(q) !== -1) return true;
                    for (var i = 0; i < it.terms.length; i++) { if (String(it.terms[i]).toLowerCase().indexOf(q) !== -1) return true; }
                    return false;
                });
                res.sort(function (a, b) { var as = a.name.indexOf(q) === 0 ? 0 : 1, bs = b.name.indexOf(q) === 0 ? 0 : 1; if (as !== bs) return as - bs; return a.name < b.name ? -1 : (a.name > b.name ? 1 : 0); });
                var capped = res.slice(0, 300);
                renderIcons(capped.map(function (x) { return x.cls; }));
                setHint(res.length + '개 결과' + (res.length > 300 ? ' (상위 300개 표시 — 키워드를 더 좁혀보세요)' : ''));
            }
            input.addEventListener('input', renderPreview);
            renderIcons(PRESET);
            var timer = null;
            search.addEventListener('input', function () { if (timer) clearTimeout(timer); timer = setTimeout(runSearch, 200); });
            if (clear) clear.addEventListener('click', function () { search.value = ''; runSearch(); search.focus(); });
        }
        document.querySelectorAll('.icon-picker').forEach(initPicker);
    })();
</script>
@endsection
