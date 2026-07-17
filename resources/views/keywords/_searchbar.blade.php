{{-- 키워드 인사이트 공용 검색바(22) — 타입 세그먼트 + 검색 입력 + 자동완성.
     입력: $active(''|place|shopping) · $q · $big(true=허브 56px / false=결과·목록 44px)
     세그먼트는 항상 <a href> — 같은 위젯이 화면마다 다르게 동작하지 않게 하고, JS 없이도 크롤러가 따라간다. --}}
@php
    $__active = $active ?? '';
    $__q = $q ?? '';
    $__big = $big ?? false;
    // segOnly: 타입 세그먼트만 렌더(검색 입력은 그 페이지가 자체 폼에 둔다 — 예: /keywords/place 의 지역 셀렉트 줄)
    $__segOnly = $segOnly ?? false;
    $__h = $__big ? 56 : 44;
    $__segs = [
        ['', '전체', route('keywords.index')],
        ['place', '플레이스', route('keywords.type', 'place')],
        ['shopping', '쇼핑', route('keywords.type', 'shopping')],
    ];
    $__id = 'kws'.substr(md5(uniqid('', true)), 0, 6);
@endphp

<div class="flex flex-wrap items-center gap-1.5" style="margin-top:20px;">
    @foreach ($__segs as [$k, $label, $url])
        @php $__on = $__active === $k; @endphp
        <a href="{{ $__q !== '' && $k !== $__active ? route('keywords.search', array_filter(['q' => $__q, 'type' => $k ?: null])) : $url }}"
           class="badge border border-hairline" @if ($__on) aria-current="page" @endif
           style="font-size:var(--fs-xs);text-decoration:none;{{ $__on ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">{{ $label }}</a>
    @endforeach
</div>

@if ($__segOnly)
    @php return; @endphp
@endif

<form method="GET" action="{{ route('keywords.search') }}" style="margin-top:12px;position:relative;max-width:{{ $__big ? '720px' : '560px' }};" autocomplete="off">
    @if ($__active !== '')<input type="hidden" name="type" value="{{ $__active }}">@endif
    <div class="flex items-center gap-2">
        <input type="search" name="q" value="{{ $__q }}" id="{{ $__id }}-in" required
               placeholder="{{ $__active === 'place' ? '플레이스 키워드 검색 (예: 강남 맛집)' : ($__active === 'shopping' ? '쇼핑 키워드 검색 (예: 캠핑의자)' : '키워드를 검색하세요 (예: 강남 맛집, 캠핑의자)') }}"
               class="input" style="flex:1;height:{{ $__h }}px;border-radius:var(--radius-pill);padding:0 20px;font-size:var(--fs-{{ $__big ? 'md' : 'sm' }});">
        <button type="submit" class="btn btn-primary" style="height:{{ $__h }}px;padding:0 24px;">검색</button>
    </div>
    {{-- 자동완성 — JS 없으면 나타나지 않고 폼 제출만으로 동작(progressive enhancement) --}}
    <div id="{{ $__id }}-pop" class="card" style="display:none;position:absolute;left:0;right:0;top:{{ $__h + 6 }}px;z-index:30;padding:6px;max-height:340px;overflow:auto;"></div>
</form>

<script>
(function () {
    var input = document.getElementById('{{ $__id }}-in');
    var pop = document.getElementById('{{ $__id }}-pop');
    if (!input || !pop) return;
    var url = @js(route('api.keywords.suggest'));
    var type = @js($__active);
    var t = null, last = '';

    function hide() { pop.style.display = 'none'; pop.innerHTML = ''; }
    function row(href, main, meta) {
        var a = document.createElement('a');
        a.href = href;
        a.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;border-radius:8px;text-decoration:none;';
        a.onmouseenter = function () { a.style.background = 'var(--color-surface-soft)'; };
        a.onmouseleave = function () { a.style.background = 'transparent'; };
        var l = document.createElement('span');
        l.className = 'text-ink'; l.style.cssText = 'font-size:var(--fs-sm);'; l.textContent = main;
        var r = document.createElement('span');
        r.className = 'text-muted-soft font-mono'; r.style.cssText = 'font-size:var(--fs-xs);white-space:nowrap;'; r.textContent = meta;
        a.appendChild(l); a.appendChild(r);
        return a;
    }
    function render(d) {
        pop.innerHTML = '';
        var n = 0;
        (d.categories || []).forEach(function (c) {
            pop.appendChild(row(c.url, '▸ ' + c.name, '카테고리 · ' + c.docs.toLocaleString() + '건')); n++;
        });
        (d.keywords || []).forEach(function (k) {
            pop.appendChild(row(k.url, k.keyword, '월 ' + k.total.toLocaleString() + '회')); n++;
        });
        pop.style.display = n ? 'block' : 'none';
    }
    function fetchSuggest() {
        var q = input.value.trim();
        if (q.length < 2) { hide(); return; }
        if (q === last) return;
        last = q;
        fetch(url + '?q=' + encodeURIComponent(q) + (type ? '&type=' + encodeURIComponent(type) : ''), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && input.value.trim() === q) render(d); })
            .catch(function () {});
    }
    input.addEventListener('input', function () { clearTimeout(t); t = setTimeout(fetchSuggest, 250); });
    input.addEventListener('focus', fetchSuggest);
    document.addEventListener('click', function (e) { if (!pop.contains(e.target) && e.target !== input) hide(); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
})();
</script>
