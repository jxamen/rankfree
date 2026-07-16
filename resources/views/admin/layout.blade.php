@php
    // 페이지 제목·브레드크럼 — 콘솔 레이아웃과 동일 규칙(area 만 admin).
    // 메뉴 페이지: [그룹] › [메뉴명]. 상세 페이지: 라우트명 축약(admin.orders.show → admin.orders)으로
    // 부모 메뉴를 찾고(@section('crumb-parent','라우트명')로 지정 가능), 마지막 조각은 @section('crumb-title'), 없으면 페이지 제목.
    $__rn = \Illuminate\Support\Facades\Route::currentRouteName();
    $__menu = $__rn ? \App\Models\Menu::where('area', 'admin')->where('route', $__rn)->first() : null;
    $__pageTitle = $__menu?->name ?: (trim($__env->yieldContent('page-title')) ?: '관리자');

    $__crumbs = [];
    if ($__menu) {
        if ($__menu->parent?->is_group) {
            $__crumbs[] = [$__menu->parent->name, null];
        }
        $__crumbs[] = [$__pageTitle, null];
    } else {
        $__parentMenu = null;
        $__crumbParent = trim($__env->yieldContent('crumb-parent'));
        if ($__crumbParent !== '') {
            $__parentMenu = \App\Models\Menu::where('area', 'admin')->where('route', $__crumbParent)->first();
        } else {
            $__cand = (string) $__rn;
            while (! $__parentMenu && ($__pos = strrpos($__cand, '.')) !== false) {
                $__cand = substr($__cand, 0, $__pos);
                $__parentMenu = \App\Models\Menu::where('area', 'admin')->where('route', $__cand)->first();
            }
        }
        if ($__parentMenu) {
            if ($__parentMenu->parent?->is_group) {
                $__crumbs[] = [$__parentMenu->parent->name, null];
            }
            $__crumbs[] = [$__parentMenu->name, $__parentMenu->resolvedUrl()];
        }
        $__crumbs[] = [trim($__env->yieldContent('crumb-title')) ?: $__pageTitle, null];
    }
@endphp
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $__pageTitle }} · 랭크프리 관리자</title>
    {{-- 다크모드 선적용(FOUC 방지) — 콘솔과 동일 --}}
    <script>if (localStorage.getItem('rf-theme') === 'dark') document.documentElement.classList.add('theme-dark');</script>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* 공통 토글 스위치 (모노크롬) — 어드민 폼(권한/메뉴 등)에서 사용 */
        .rf-switch { position:relative; display:inline-flex; width:36px; height:20px; flex:0 0 auto; vertical-align:middle; cursor:pointer; }
        .rf-switch input { position:absolute; inset:0; width:100%; height:100%; margin:0; opacity:0; cursor:pointer; z-index:1; }
        .rf-switch .rf-track { position:absolute; inset:0; background:var(--color-surface-strong); border-radius:9999px; transition:background .15s ease; }
        .rf-switch .rf-track::after { content:''; position:absolute; top:2px; left:2px; width:16px; height:16px; background:#fff; border-radius:50%; box-shadow:0 1px 2px rgba(17,17,17,.25); transition:transform .15s ease; }
        .rf-switch input:checked + .rf-track { background:var(--color-primary); }
        .rf-switch input:checked + .rf-track::after { transform:translateX(16px); }
        .rf-switch input:focus-visible + .rf-track { box-shadow:0 0 0 3px rgba(17,17,17,.12); }
    </style>
    @stack('head')
</head>
<body class="bg-surface-page font-sans antialiased text-body">
<div class="flex min-h-screen console-shell">
    {{-- 모바일 드로어 백드롭 --}}
    <div id="rf-sb-bg" class="hidden lg:hidden" style="position:fixed;inset:0;z-index:49;background:color-mix(in srgb, var(--color-ink) 40%, transparent);"></div>

    {{-- 사이드바 (콘솔과 동일 구조) — lg 미만은 드로어 --}}
    <aside id="rf-sidebar" class="console-sidebar w-72 flex-none bg-canvas border-r border-hairline flex flex-col lg:sticky top-0 h-screen">
        <a href="{{ route('admin.home') }}" class="flex items-center gap-2 px-5" style="height:64px;" title="관리자 홈">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-md bg-primary text-on-primary font-display" style="font-size:var(--fs-sm);">R</span>
            <span class="font-display text-ink" style="font-size:var(--fs-md);">랭크프리</span>
            <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">관리자</span>
        </a>

        {{-- 즐겨찾기(호버 팝오버) · 전체 메뉴(클릭 오버레이) — 콘솔과 동일 --}}
        <div class="px-3 pt-3 pb-1">
            <div class="sb-toolrow">
                <div class="sb-fav-wrap sb-fav-icon">
                    <button type="button" class="sb-tool" id="rf-fav-trigger" title="즐겨찾기"><span class="star">★</span></button>
                    <div class="sb-fav-pop" id="rf-fav-pop">
                        <div class="sb-fav-pop-head"><span class="star">★</span> 즐겨찾기 <span class="text-muted-soft" style="font-weight:400;font-size:var(--fs-xs);">드래그로 정렬</span></div>
                        <div id="rf-fav-list"></div>
                    </div>
                </div>
                <button type="button" class="sb-tool" id="rf-allmenu-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    전체 메뉴
                </button>
            </div>
        </div>

        @php
            $__tree = \App\Domain\Access\MenuService::sidebarTree(auth()->user(), 'admin');
            // 첫 그룹 앞의 최상위 항목만 상단 고정, 그 이후(그룹+끼어있는 항목)는 메뉴 순서대로 스크롤
            $__idx = $__tree->search(fn ($n) => $n->is_group);
            $__leading = $__idx === false ? $__tree->values() : $__tree->slice(0, $__idx)->values();
            $__rest = $__idx === false ? collect() : $__tree->slice($__idx)->values();
            // 전체 메뉴 오버레이용 — 전 항목/그룹
            $__standalone = $__tree->filter(fn ($n) => ! $n->is_group)->values();
            $__groups = $__tree->filter(fn ($n) => $n->is_group)->values();
        @endphp

        {{-- 상단 고정 항목 (첫 그룹 앞 최상위 항목) --}}
        @if ($__leading->count())
        <div id="rf-nav-top" class="px-3 pt-2 flex flex-col gap-0.5 flex-none">
            @foreach ($__leading as $node)
                @php $on = $node->route && (request()->routeIs($node->route) || request()->routeIs($node->route.'.*')); @endphp
                <a href="{{ $node->resolvedUrl() ?? '#' }}" @if ($node->target === '_blank') target="_blank" @endif
                   class="sb-link px-3 {{ $on ? 'on' : '' }}" data-item data-label="{{ $node->name }}">
                    <span class="ic">@if (trim((string) $node->icon) !== '')<x-icon :name="$node->icon" />@endif</span>{{ $node->name }}
                </a>
            @endforeach
        </div>
        <div id="rf-nav-div" class="sb-divider"></div>
        @endif

        {{-- 메뉴 트리 (첫 그룹 이후, 추가 순서대로) — 그룹은 접기/펴기 · 스크롤 영역 --}}
        <nav id="rf-nav" class="flex-1 min-h-0 px-3 pt-1 pb-2 flex flex-col gap-0.5 overflow-y-auto">
            @foreach ($__rest as $node)
                @if ($node->is_group)
                    <div class="sb-group" data-group data-group-name="{{ $node->name }}">
                        <div class="sb-group-head">
                            <span class="chev"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>
                            <span class="tx">{{ $node->name }}</span>
                        </div>
                        <div class="sb-group-items">
                            @foreach ($node->menuItems as $item)
                                @php $on = $item->route && (request()->routeIs($item->route) || request()->routeIs($item->route.'.*')); @endphp
                                <a href="{{ $item->resolvedUrl() ?? '#' }}" @if ($item->target === '_blank') target="_blank" @endif
                                   class="sb-sublink {{ $on ? 'on' : '' }}" data-item data-label="{{ $item->name }}">{{ $item->name }}</a>
                            @endforeach
                        </div>
                    </div>
                @else
                    @php $on = $node->route && (request()->routeIs($node->route) || request()->routeIs($node->route.'.*')); @endphp
                    <a href="{{ $node->resolvedUrl() ?? '#' }}" @if ($node->target === '_blank') target="_blank" @endif
                       class="sb-link px-3 {{ $on ? 'on' : '' }}" data-item data-label="{{ $node->name }}">
                        <span class="ic">@if (trim((string) $node->icon) !== '')<x-icon :name="$node->icon" />@endif</span>{{ $node->name }}
                    </a>
                @endif
            @endforeach
            <div id="rf-nav-empty" class="hidden px-3 py-4 text-muted-soft" style="font-size:var(--fs-xs);">검색 결과가 없습니다.</div>
        </nav>
    </aside>

    {{-- 전체 메뉴 오버레이 — 콘솔과 동일 --}}
    <div id="rf-allmenu" class="rf-am-overlay hidden">
        <div class="rf-am-head">
            <span class="ttl">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                전체 메뉴
            </span>
            <input id="rf-am-search" type="text" placeholder="메뉴 검색..." autocomplete="off" spellcheck="false">
            <button type="button" class="rf-am-close" id="rf-am-close" title="닫기">&times;</button>
        </div>
        <div class="rf-am-body" id="rf-am-body">
            @if ($__standalone->count())
                <div class="rf-am-group" data-amgroup>
                    <div class="rf-am-gt">바로가기</div>
                    @foreach ($__standalone as $it)
                        @php $u = $it->resolvedUrl(); @endphp
                        <div class="rf-am-item" data-amitem data-label="{{ $it->name }}">
                            <a href="{{ $u ?? '#' }}">{{ $it->name }}</a>
                            @if ($u)<button type="button" class="rf-am-star" data-key="{{ $u }}" data-label="{{ $it->name }}" data-url="{{ $u }}" title="즐겨찾기">&#9734;</button>@endif
                        </div>
                    @endforeach
                </div>
            @endif
            @foreach ($__groups as $g)
                <div class="rf-am-group" data-amgroup>
                    <div class="rf-am-gt"><x-icon :name="$g->icon" /> {{ $g->name }}</div>
                    @foreach ($g->menuItems as $it)
                        @php $u = $it->resolvedUrl(); @endphp
                        <div class="rf-am-item" data-amitem data-label="{{ $it->name }}">
                            <a href="{{ $u ?? '#' }}">{{ $it->name }}</a>
                            @if ($u)<button type="button" class="rf-am-star" data-key="{{ $u }}" data-label="{{ $it->name }}" data-url="{{ $u }}" title="즐겨찾기">&#9734;</button>@endif
                        </div>
                    @endforeach
                </div>
            @endforeach
            <div id="rf-am-empty" class="rf-am-empty hidden">검색 결과가 없습니다.</div>
        </div>
    </div>

    {{-- 메인 --}}
    <div class="flex-1 flex flex-col min-w-0">
        <header class="bg-canvas border-b border-hairline flex items-center justify-between px-4 sm:px-8 sticky top-0 z-10" style="height:64px;">
            <div class="flex items-center gap-2">
                <button type="button" id="rf-sb-toggle" class="lg:hidden btn btn-ghost btn-sm" title="메뉴" style="padding:0 10px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                {{-- 브레드크럼 — 그룹 › 메뉴(링크) › 현재. 데스크톱은 헤더 한 줄, 모바일은 콘텐츠 타이틀 위 --}}
                <div class="hidden lg:block min-w-0">
                    @include('console.partials.crumbs', ['crumbs' => $__crumbs])
                </div>
            </div>
            <div class="flex items-center gap-2">
                @yield('page-actions')
                {{-- 콘솔로 (사용자 콘솔의 '관리자' 버튼과 대칭) --}}
                <a href="{{ route('console.dashboard') }}" class="btn btn-secondary btn-sm inline-flex items-center gap-1.5" title="사용자 콘솔">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    콘솔로
                </a>
                <button type="button" id="rf-theme-toggle" class="btn btn-ghost btn-sm" title="라이트/다크 전환" style="padding:0 10px;">
                    <svg id="rf-ico-moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    <svg id="rf-ico-sun" class="hidden" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                </button>
                {{-- 유저 프로필 (상단 우측) — 콘솔과 동일 --}}
                @php $__u = auth()->user(); $__nm = $__u?->name ?? '관리자'; $__role = $__u?->operatorRole?->name ?? ($__u?->isSuperAdmin() ? '슈퍼관리자' : '운영자'); @endphp
                <div class="rf-user" id="rf-user">
                    <button type="button" class="rf-user-btn" id="rf-user-btn" title="{{ $__nm }}">
                        <span class="rf-avatar">{{ mb_substr($__nm, 0, 1) }}</span>
                    </button>
                    <div class="rf-user-pop hidden" id="rf-user-pop">
                        <div class="rf-user-info">
                            <div class="text-ink font-semibold truncate" style="font-size:var(--fs-sm);">{{ $__nm }}</div>
                            <div class="text-muted-soft truncate" style="font-size:var(--fs-xs);">{{ $__role }}</div>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-secondary btn-sm w-full">로그아웃</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>
        <main class="p-4 sm:p-8 flex-1">
            <div style="max-width:1440px;margin:0 auto;">
                {{-- 모바일: 헤더 줄이 좁아 브레드크럼을 콘텐츠 타이틀 위에 표시 --}}
                <div class="lg:hidden mb-5">
                    @include('console.partials.crumbs', ['crumbs' => $__crumbs, 'currentTag' => 'span'])
                </div>
                @if (session('status'))
                    <div class="card-soft mb-6 px-4 py-3 text-ink" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
                @endif
                @yield('admin-content')
            </div>
        </main>
    </div>
</div>

<script>
(function () {
    // 모바일 사이드바 드로어 토글
    const sb = document.getElementById('rf-sidebar');
    const bg = document.getElementById('rf-sb-bg');
    const toggle = document.getElementById('rf-sb-toggle');
    function openSb() { sb.classList.add('open'); bg.classList.remove('hidden'); }
    function closeSb() { sb.classList.remove('open'); bg.classList.add('hidden'); }
    toggle && toggle.addEventListener('click', function () {
        sb.classList.contains('open') ? closeSb() : openSb();
    });
    bg && bg.addEventListener('click', closeSb);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sb.classList.contains('open')) closeSb();
    });

    // ===== 사이드바: 그룹 접기 · 즐겨찾기 · 전체 메뉴 (콘솔과 동일, 저장 키만 admin 분리) =====
    const navEl = document.getElementById('rf-nav');
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function readJson(k) { try { return JSON.parse(localStorage.getItem(k)) || []; } catch (e) { return []; } }
    function writeJson(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }

    const FAV_KEY = 'rf-admin-favorites', COL_KEY = 'rf-admin-collapsed-groups';
    let favorites = readJson(FAV_KEY);
    let collapsed = readJson(COL_KEY);

    function isFav(k) { return favorites.some(function (f) { return f.key === k; }); }
    function syncStars() { document.querySelectorAll('.rf-am-star').forEach(function (b) { var on = isFav(b.getAttribute('data-key')); b.classList.toggle('on', on); b.innerHTML = on ? '&#9733;' : '&#9734;'; }); }
    function renderFavList() {
        var list = document.getElementById('rf-fav-list'); if (!list) return;
        if (!favorites.length) { list.innerHTML = '<div class="sb-fav-empty">즐겨찾기가 없습니다.<br>전체 메뉴에서 ★를 눌러 추가하세요.</div>'; return; }
        list.innerHTML = favorites.map(function (f) {
            return '<div class="sb-fav-row" draggable="true" data-key="' + esc(f.key) + '"><span class="hand">⠿</span><a href="' + esc(f.url) + '">' + esc(f.label) + '</a><button type="button" class="rm" title="삭제">&times;</button></div>';
        }).join('');
    }
    function toggleFav(key, label, url) {
        if (isFav(key)) favorites = favorites.filter(function (f) { return f.key !== key; });
        else favorites.push({ key: key, label: label, url: url });
        writeJson(FAV_KEY, favorites); renderFavList(); syncStars();
    }

    // 즐겨찾기 목록 — 삭제 + 드래그 정렬
    var favListEl = document.getElementById('rf-fav-list');
    if (favListEl) {
        favListEl.addEventListener('click', function (e) {
            var rm = e.target.closest('.rm'); if (!rm) return;
            var row = e.target.closest('.sb-fav-row'); if (!row) return;
            favorites = favorites.filter(function (f) { return f.key !== row.dataset.key; });
            writeJson(FAV_KEY, favorites); renderFavList(); syncStars();
        });
        favListEl.addEventListener('dragstart', function (e) { var row = e.target.closest('.sb-fav-row'); if (!row) return; row.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
        favListEl.addEventListener('dragover', function (e) {
            e.preventDefault();
            var dragging = favListEl.querySelector('.sb-fav-row.dragging'); if (!dragging) return;
            var over = e.target.closest('.sb-fav-row'); if (!over || over === dragging) return;
            var r = over.getBoundingClientRect();
            favListEl.insertBefore(dragging, (e.clientY - r.top) < r.height / 2 ? over : over.nextSibling);
        });
        favListEl.addEventListener('dragend', function () {
            var dragging = favListEl.querySelector('.sb-fav-row.dragging'); if (dragging) dragging.classList.remove('dragging');
            var order = Array.prototype.map.call(favListEl.querySelectorAll('.sb-fav-row'), function (r) { return r.dataset.key; });
            favorites.sort(function (a, b) { return order.indexOf(a.key) - order.indexOf(b.key); });
            writeJson(FAV_KEY, favorites);
        });
    }

    // 그룹 접기/펴기 (.sb-group 래퍼 단위)
    function applyCollapse() {
        if (!navEl) return;
        navEl.querySelectorAll('.sb-group').forEach(function (g) {
            g.classList.toggle('collapsed', collapsed.indexOf(g.getAttribute('data-group-name')) >= 0);
        });
    }
    if (navEl) {
        // 활성 항목이 있는 그룹은 강제 펼침
        var onSub = navEl.querySelector('.sb-sublink.on');
        if (onSub) { var og = onSub.closest('.sb-group'); if (og) { var ai = collapsed.indexOf(og.getAttribute('data-group-name')); if (ai >= 0) { collapsed.splice(ai, 1); writeJson(COL_KEY, collapsed); } } }
        navEl.querySelectorAll('.sb-group-head').forEach(function (h) {
            h.addEventListener('click', function () {
                var g = h.closest('.sb-group'); if (!g) return;
                var nm = g.getAttribute('data-group-name'); var i = collapsed.indexOf(nm);
                if (i >= 0) collapsed.splice(i, 1); else collapsed.push(nm);
                writeJson(COL_KEY, collapsed); applyCollapse();
            });
        });
    }

    // 전체 메뉴 오버레이
    var amBtn = document.getElementById('rf-allmenu-btn'), am = document.getElementById('rf-allmenu');
    function amFilter() {
        if (!am) return;
        var s = document.getElementById('rf-am-search'); var q = (s && s.value || '').trim().toLowerCase(); var any = false;
        am.querySelectorAll('[data-amgroup]').forEach(function (g) {
            var go = false;
            g.querySelectorAll('[data-amitem]').forEach(function (it) { var m = !q || (it.getAttribute('data-label') || '').toLowerCase().indexOf(q) >= 0; it.style.display = m ? '' : 'none'; if (m) go = true; });
            g.style.display = go ? '' : 'none'; if (go) any = true;
        });
        var e2 = document.getElementById('rf-am-empty'); if (e2) e2.classList.toggle('hidden', any);
    }
    function amOpen() { if (!am) return; am.classList.remove('hidden'); document.body.style.overflow = 'hidden'; var s = document.getElementById('rf-am-search'); if (s) { s.value = ''; amFilter(); setTimeout(function () { s.focus(); }, 0); } syncStars(); }
    function amClose() { if (!am) return; am.classList.add('hidden'); document.body.style.overflow = ''; }
    if (amBtn && am) {
        amBtn.addEventListener('click', amOpen);
        am.addEventListener('click', function (e) { if (e.target.closest('#rf-am-close')) { amClose(); return; } var st = e.target.closest('.rf-am-star'); if (st) { toggleFav(st.getAttribute('data-key'), st.getAttribute('data-label'), st.getAttribute('data-url')); } });
        var amS = document.getElementById('rf-am-search'); if (amS) amS.addEventListener('input', amFilter);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && am && !am.classList.contains('hidden')) amClose(); });
    }

    // 유저 프로필 드롭다운
    var userBtn = document.getElementById('rf-user-btn'), userPop = document.getElementById('rf-user-pop');
    if (userBtn && userPop) {
        userBtn.addEventListener('click', function (e) { e.stopPropagation(); userPop.classList.toggle('hidden'); });
        document.addEventListener('click', function (e) { if (!e.target.closest('#rf-user')) userPop.classList.add('hidden'); });
    }

    // 초기화
    renderFavList(); syncStars(); applyCollapse();

    // 라이트/다크 테마 토글 — <head>에서 선적용, 여기서는 전환·저장·아이콘 동기화만
    var themeBtn = document.getElementById('rf-theme-toggle');
    function syncThemeIcon() {
        var dark = document.documentElement.classList.contains('theme-dark');
        var moon = document.getElementById('rf-ico-moon'), sun = document.getElementById('rf-ico-sun');
        if (moon) moon.classList.toggle('hidden', dark);
        if (sun) sun.classList.toggle('hidden', !dark);
    }
    if (themeBtn) {
        syncThemeIcon();
        themeBtn.addEventListener('click', function () {
            var dark = document.documentElement.classList.toggle('theme-dark');
            localStorage.setItem('rf-theme', dark ? 'dark' : 'light');
            syncThemeIcon();
        });
    }
})();
</script>
@stack('scripts')
</body>
</html>
