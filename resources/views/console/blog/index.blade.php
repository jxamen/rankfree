@extends('console.layout')
@section('page-title', '블로그 수집')

@section('console-content')
@php
    $gradeColor = fn ($g) => match ($g) {
        'S' => 'var(--color-success)', 'A' => 'var(--color-accent)', 'B' => 'var(--color-badge-violet)',
        'C' => 'var(--color-warning)', 'D' => 'var(--color-muted)', default => 'var(--color-muted)',
    };
    $bar = function ($v, $color = 'var(--color-accent)') {
        $w = max(0, min(100, (float) $v));
        return '<div style="height:7px;background:var(--color-surface-strong);border-radius:99px;overflow:hidden;"><div style="height:100%;width:'.$w.'%;background:'.$color.';border-radius:99px;"></div></div>';
    };
    $nf = fn ($v) => $v === null ? '—' : number_format((int) $v);
@endphp

{{-- 검색 --}}
<form method="GET" action="{{ route('console.blog') }}" class="flex items-center gap-2 mb-4" id="bi-form">
    <input type="text" name="q" value="{{ $q }}" placeholder="키워드(예: 강남 맛집) 또는 블로그 ID·URL(예: today789)"
           class="input" style="flex:1;height:44px;font-size:var(--fs-sm);" autofocus autocomplete="off">
    <button type="submit" class="btn btn-primary" style="height:44px;padding:0 22px;">분석</button>
    @if (! $result)
        {{-- 진입 화면에서도 전체 저장 블로거로 바로 이동 (결과 화면에선 액션 줄에 동일 버튼) --}}
        <a href="{{ route('console.blog-saved') }}" class="btn btn-secondary" style="height:44px;padding:0 18px;" title="모든 수집에서 저장한 블로거 전체 모아보기·엑셀">★ 저장 블로거</a>
    @endif
    @if ($result && ($result['type'] ?? '') === 'keyword')
        {{-- 네이버 블로그 검색을 새 탭으로 (N=네이버 브랜드색은 예외적 인라인) --}}
        <a href="https://search.naver.com/search.naver?ssc=tab.blog.all&sm=tab_jum&query={{ urlencode($result['keyword']['keyword']) }}" target="_blank" rel="noopener"
           class="btn btn-secondary inline-flex items-center gap-1" style="height:44px;padding:0 18px;" title="「{{ $result['keyword']['keyword'] }}」 네이버 블로그 검색 (새 창)">
            <span style="color:#03c75a;font-weight:800;font-size:var(--fs-sm);">N</span> 블로그 검색
        </a>
    @elseif ($result && ($result['type'] ?? '') === 'blog')
        @php $bid = $result['blog']['blog_id'] ?? ($result['blog']['profile']['blog_id'] ?? ''); @endphp
        @if ($bid)
            <a href="https://blog.naver.com/{{ $bid }}" target="_blank" rel="noopener"
               class="btn btn-secondary inline-flex items-center gap-1" style="height:44px;padding:0 18px;" title="블로그 열기 (새 창)">
                <span style="color:#03c75a;font-weight:800;font-size:var(--fs-sm);">N</span> 블로그 열기
            </a>
        @endif
    @endif
</form>
<p class="text-muted-soft mb-5" style="font-size:var(--fs-xs);">
    키워드를 넣으면 블로그 검색 상위 블로거들을, 블로그 ID/URL을 넣으면 그 블로그 하나를 분석합니다. 지수·등급은 관측 신호 기반 <b>자체 추정치</b>(네이버 공식 아님)입니다.
</p>

@if (! $result && $q === '')
    @if ($history->count())
        {{-- 최근 수집 내역 리스트 (클릭 시 /console/blog-index/{id}) --}}
        @include('console.blog._history_list', ['history' => $history])
    @else
        {{-- 최초 진입(내역 없음) 안내 --}}
        <div class="card p-8 text-center">
            <div style="font-size:var(--fs-2xl);opacity:.35;">✍️</div>
            <p class="text-ink font-semibold mt-3" style="font-size:var(--fs-base);">블로그 지수를 분석해 보세요</p>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);">이웃·방문·활동성에 더해 <b>게시물 품질(사진·본문·영상)</b>과 <b>전문성(빈출 주제어)</b>까지 수치화합니다.</p>
        </div>
    @endif

@elseif (! $result)
    <div class="card-soft px-4 py-4 text-muted" style="font-size:var(--fs-xs);">
        「{{ $q }}」 분석 결과를 얻지 못했습니다. 블로그 ID/키워드를 확인하거나 잠시 후 다시 시도하세요.
    </div>

@elseif ($result['type'] === 'blog')
    @include('console.blog._single', ['b' => $result['blog']])

@else
    {{-- 키워드 → 블로거 목록 --}}
    @php $kw = $result['keyword']; @endphp
    <div class="flex items-end justify-between flex-wrap gap-2 mb-4">
        <div>
            <div class="text-ink font-display" style="font-size:var(--fs-xl);">‘{{ $kw['keyword'] }}’ 블로그 상위 블로거</div>
            <div class="text-muted-soft" style="font-size:var(--fs-xs);">블로그 검색에 노출된 글 {{ count($kw['bloggers']) }}개 · 각 글의 품질·전문성 분석(합치지 않고 노출 순서대로)</div>
        </div>
        <div class="flex items-center gap-2">
            @if (! empty($exportable))
                <button type="button" id="bi-save-sel" class="btn btn-primary btn-sm" style="height:36px;" disabled
                        data-analysis="{{ $exportable->id }}" title="체크한 블로거를 이 키워드와 함께 저장">★ 선택 저장 <span id="bi-sel-count">0</span></button>
                <a href="{{ route('console.blog-saved') }}" class="btn btn-secondary btn-sm" style="height:36px;" title="모든 수집에서 저장한 블로거 전체 모아보기·엑셀">저장 블로거</a>
                <button type="button" id="bi-more" class="btn btn-secondary btn-sm"
                        data-analysis="{{ $exportable->id }}" data-next="{{ $kw['next_start'] ?? 31 }}"
                        style="height:36px;">
                    다음 페이지 수집 <span class="text-muted-soft" style="font-size:var(--fs-xs);">+30</span>
                </button>
                <button type="button" id="bi-recollect" class="btn btn-secondary btn-sm" style="height:36px;" title="현재 검색어로 처음부터 새로 수집">↻ 재수집</button>
                <a href="{{ route('console.blog.export', $exportable) }}" class="btn btn-secondary btn-sm" style="height:36px;">엑셀 다운로드</a>
            @endif
        </div>
    </div>
    {{-- 필터/정렬 툴바 (crm 필터 스타일) --}}
    <div class="card p-3 mb-3" id="bi-toolbar">
        <div class="bi-trow">
            <input type="text" id="bi-search" placeholder="블로그명·ID·제목·주제어 검색" autocomplete="off"
                   class="input" style="height:34px;width:240px;font-size:var(--fs-xs);">
            <span class="bi-div"></span>
            <div class="bi-fgroup">
                <span class="text-muted" style="font-size:var(--fs-xs);">등급</span>
                <div class="bi-chips">
                    @foreach (['S', 'A', 'B', 'C', 'D'] as $g)
                        <button type="button" class="bi-chip" data-grade="{{ $g }}">{{ $g }}</button>
                    @endforeach
                </div>
            </div>
            <span class="bi-div"></span>
            <div class="bi-fgroup">
                <span class="text-muted" style="font-size:var(--fs-xs);white-space:nowrap;">오늘 방문</span>
                <input type="number" id="bi-min-today" min="0" step="100" placeholder="이상" autocomplete="off"
                       class="input text-right" style="height:32px;width:92px;font-size:var(--fs-xs);">
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">명+</span>
            </div>
            <span class="bi-div"></span>
            <div class="bi-fgroup">
                <span class="text-muted" style="font-size:var(--fs-xs);white-space:nowrap;">어제 방문</span>
                <input type="number" id="bi-min-yesterday" min="0" step="100" placeholder="이상" autocomplete="off"
                       class="input text-right" style="height:32px;width:92px;font-size:var(--fs-xs);">
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">명+</span>
            </div>
            <span class="bi-div"></span>
            <button type="button" class="bi-chip" id="bi-saved-only" title="이 키워드로 저장한 블로거만 표시">★ 저장됨만</button>
            <span class="bi-div"></span>
            <button type="button" id="bi-reset" class="text-muted-soft hover:text-ink" style="font-size:var(--fs-xs);text-decoration:underline;">초기화</button>
            <span class="text-muted-soft" style="font-size:var(--fs-xs);margin-left:auto;white-space:nowrap;"><b id="bi-count">{{ count($kw['bloggers']) }}</b>개 표시</span>
        </div>
    </div>

    <div class="card overflow-hidden mb-4">
        <div style="overflow-x:auto;">
            <table class="w-full" style="min-width:1260px;" id="bi-table">
                <thead>
                    <tr class="text-muted" style="font-size:var(--fs-xs);">
                        <th class="text-center px-3 py-3" style="width:36px;"><input type="checkbox" id="bi-check-all" title="표시 중인 행 전체 선택" style="width:15px;height:15px;accent-color:var(--color-ink);cursor:pointer;"></th>
                        <th class="text-center px-2 py-3 font-semibold" style="width:40px;">저장</th>
                        <th class="text-left px-3 py-3 font-semibold bi-sort" data-sort="rank" style="width:56px;">순위<span class="ar"></span></th>
                        <th class="text-left px-3 py-3 font-semibold">블로그</th>
                        <th class="text-center px-3 py-3 font-semibold bi-sort" data-sort="grade">등급<span class="ar"></span></th>
                        <th class="text-right px-3 py-3 font-semibold bi-sort" data-sort="score">지수<span class="ar"></span></th>
                        <th class="text-right px-3 py-3 font-semibold bi-sort" data-sort="posts">게시물<span class="ar"></span></th>
                        <th class="text-right px-3 py-3 font-semibold bi-sort" data-sort="vis">일방문<span class="ar"></span></th>
                        <th class="text-center px-3 py-3 font-semibold bi-sort" data-sort="today">최근 5일 방문<div class="text-muted-soft" style="font-weight:400;font-size:var(--fs-xs);">오늘 기준 정렬 <span class="ar"></span></div></th>
                        <th class="text-right px-3 py-3 font-semibold bi-sort" data-sort="photo">노출글 사진<span class="ar"></span></th>
                        <th class="text-right px-3 py-3 font-semibold bi-sort" data-sort="body">노출글 본문<span class="ar"></span></th>
                        <th class="text-left px-5 py-3 font-semibold">전문 주제어</th>
                    </tr>
                </thead>
                <tbody id="bi-tbody">
                    @foreach ($kw['bloggers'] as $b)
                        @include('console.blog._kw_row', ['b' => $b, 'savedIds' => $savedIds ?? []])
                    @endforeach
                </tbody>
            </table>
        </div>
        <div id="bi-empty" class="hidden px-5 py-8 text-center text-muted-soft" style="font-size:var(--fs-xs);">조건에 맞는 블로그가 없습니다.</div>
    </div>

    <p class="text-muted-soft" style="font-size:var(--fs-xs);">각 블로거는 최근 글의 사진 수·본문 길이·영상·제목 키워드 적합도와 전문 주제어(형태소 빈출)를 종합해 지수화했습니다.</p>
@endif

<script>
(function () {
    // 지연 로딩 — 캐시/스냅샷으로 즉시 열리는 이동엔 오버레이 미표시(350ms 전 언로드). 느린 신규 수집만 표시.
    var loadTimer = null;
    function delayedLoad(title, sub) {
        loadTimer = setTimeout(function () {
            Swal.fire({ title: title, html: sub || '', allowOutsideClick: false, showConfirmButton: false, didOpen: function () { Swal.showLoading(); } });
        }, 350);
    }
    // 페이지 이탈(bfcache 진입) 시 예약 타이머 취소 — 뒤로가기 복원 시 오버레이 재등장 방지
    window.addEventListener('pagehide', function () { if (loadTimer) { clearTimeout(loadTimer); loadTimer = null; } });
    var form = document.getElementById('bi-form');
    if (form) {
        form.addEventListener('submit', function () {
            var q = (form.querySelector('input[name=q]').value || '').trim();
            if (!q) return;
            var isKw = /\s/.test(q) && !/blog\.naver\.com/.test(q);
            delayedLoad('블로그 분석 중…', '<span style="font-size:var(--fs-xs);color:var(--color-muted);">' + (isKw
                ? '‘' + q + '’ 검색에 노출된 블로거 전부의 게시물 품질·전문성을 분석합니다. 블로거 수에 따라 1~2분 걸릴 수 있습니다.'
                : '‘' + q + '’ 블로그의 최근 글 품질과 지수를 분석합니다.') + '</span>');
        });
    }
    document.querySelectorAll('a[href*="/console/blog-index/"]').forEach(function (el) {
        // 엑셀 다운로드(파일 응답)는 로딩 오버레이 제외
        if (/\/export(\?|$)/.test(el.getAttribute('href') || '')) return;
        el.addEventListener('click', function () { delayedLoad('불러오는 중…'); });
    });
    // 행(tr) 빈 영역 클릭 → 그 행의 지수분석 링크로 이동(라인 전체가 진입점). 셀 내 링크·체크박스·저장 버튼은 자체 동작.
    document.addEventListener('click', function (e) {
        if (e.target.closest('a, button, input, label')) return;
        var row = e.target.closest && e.target.closest('tr.bi-row');
        if (!row) return;
        var link = row.querySelector('.bi-analyze-link');
        if (link) link.click();
    });
    // 아이디 클릭 → 블로그 지수 분석(수집) 로딩 — 동적 추가 행 포함(이벤트 위임)
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('.bi-analyze-link');
        if (!a) return;
        delayedLoad('블로그 분석 중…', '<span style="font-size:var(--fs-xs);color:var(--color-muted);">‘' + a.textContent.trim() + '’ 블로그의 최근 글 품질과 지수를 분석합니다.</span>');
    });
    // 재수집 — 현재 검색어로 처음부터 새로 수집(폼 재제출 → 로딩 표시)
    var recollect = document.getElementById('bi-recollect');
    if (recollect && form) {
        recollect.addEventListener('click', function () {
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
        });
    }
})();

// 결과 내 검색·정렬·필터·다음 페이지 수집
(function () {
    var table = document.getElementById('bi-table');
    if (!table) return;
    var tbody = document.getElementById('bi-tbody');
    var search = document.getElementById('bi-search');
    var countEl = document.getElementById('bi-count');
    var emptyEl = document.getElementById('bi-empty');
    var toolbar = document.getElementById('bi-toolbar');
    var gradeSel = {};
    var minToday = 0, minYesterday = 0;
    var sortKey = 'rank', sortDir = 1;
    var gradeRank = { S: 5, A: 4, B: 3, C: 2, D: 1 };
    var minTodayEl = document.getElementById('bi-min-today');
    var minYesterdayEl = document.getElementById('bi-min-yesterday');
    var savedOnlyEl = document.getElementById('bi-saved-only');

    function val(tr, key) {
        if (key === 'grade') return gradeRank[tr.dataset.grade] || 0;
        return parseFloat(tr.dataset[key]) || 0;
    }
    function apply() {
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.bi-row'));
        rows.sort(function (a, b) {
            var d = (val(a, sortKey) - val(b, sortKey)) * sortDir;
            if (d) return d;
            return (parseFloat(a.dataset.rank) || 0) - (parseFloat(b.dataset.rank) || 0);
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
        var q = (search.value || '').trim().toLowerCase();
        var grades = Object.keys(gradeSel).filter(function (g) { return gradeSel[g]; });
        var savedOnly = savedOnlyEl && savedOnlyEl.classList.contains('on');
        var shown = 0;
        rows.forEach(function (tr) {
            var ok = true;
            if (q && (tr.dataset.search || '').indexOf(q) < 0) ok = false;
            if (ok && grades.length && grades.indexOf(tr.dataset.grade) < 0) ok = false;
            if (ok && minToday && (parseFloat(tr.dataset.today) || 0) < minToday) ok = false;
            if (ok && minYesterday && (parseFloat(tr.dataset.yesterday) || 0) < minYesterday) ok = false;
            if (ok && savedOnly && tr.dataset.saved !== '1') ok = false;
            tr.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        countEl.textContent = shown;
        emptyEl.classList.toggle('hidden', shown > 0);
    }
    function updateArrows() {
        table.querySelectorAll('.bi-sort').forEach(function (th) {
            var ar = th.querySelector('.ar');
            if (th.dataset.sort === sortKey) {
                th.classList.add('act');
                if (ar) ar.textContent = sortDir > 0 ? ' ▲' : ' ▼';
            } else {
                th.classList.remove('act');
                if (ar) ar.textContent = '';
            }
        });
    }

    search.addEventListener('input', apply);
    toolbar.querySelectorAll('.bi-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            chip.classList.toggle('on');
            if (chip.dataset.grade) gradeSel[chip.dataset.grade] = chip.classList.contains('on');
            apply();
        });
    });
    if (minTodayEl) minTodayEl.addEventListener('input', function () { minToday = parseFloat(this.value) || 0; apply(); });
    if (minYesterdayEl) minYesterdayEl.addEventListener('input', function () { minYesterday = parseFloat(this.value) || 0; apply(); });
    document.getElementById('bi-reset').addEventListener('click', function () {
        search.value = '';
        gradeSel = {}; minToday = 0; minYesterday = 0;
        if (minTodayEl) minTodayEl.value = '';
        if (minYesterdayEl) minYesterdayEl.value = '';
        toolbar.querySelectorAll('.bi-chip.on').forEach(function (c) { c.classList.remove('on'); });
        sortKey = 'rank'; sortDir = 1; updateArrows(); apply();
    });
    table.querySelectorAll('.bi-sort').forEach(function (th) {
        th.addEventListener('click', function () {
            var k = th.dataset.sort;
            if (sortKey === k) { sortDir = -sortDir; }
            else { sortKey = k; sortDir = (k === 'rank') ? 1 : -1; }
            updateArrows(); apply();
        });
    });
    updateArrows();

    var moreBtn = document.getElementById('bi-more');
    if (moreBtn) {
        moreBtn.addEventListener('click', function () {
            var id = moreBtn.dataset.analysis, start = moreBtn.dataset.next;
            var total = 30, cur = 0, finished = false;
            moreBtn.disabled = true;
            Swal.fire({
                title: '다음 페이지 수집 중…',
                html: '<div style="font-size:var(--fs-xs);color:var(--color-ink);"><b id="bi-prog">0 / ' + total + '</b> · <span id="bi-pct">0%</span> 진행 중</div>'
                    + '<div style="font-size:var(--fs-xs);color:var(--color-muted-soft);margin-top:4px;">노출 블로그의 프로필·방문·게시물 품질을 병렬 분석합니다.</div>',
                allowOutsideClick: false, showConfirmButton: false, didOpen: function () { Swal.showLoading(); }
            });
            var timer = setInterval(function () {
                if (finished || cur >= total - 1) return;
                cur++;
                var p = document.getElementById('bi-prog'), pc = document.getElementById('bi-pct');
                if (p) p.textContent = cur + ' / ' + total;
                if (pc) pc.textContent = (cur / total * 100).toFixed(1) + '%';
            }, 900);
            var stop = function () { finished = true; clearInterval(timer); };
            fetch('{{ url('console/blog-index') }}/' + id + '/more', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ start: start })
            }).then(function (r) { return r.json(); }).then(function (d) {
                stop();
                if (d && d.rows) {
                    var tmp = document.createElement('tbody');
                    tmp.innerHTML = d.rows;
                    Array.prototype.slice.call(tmp.querySelectorAll('tr.bi-row')).forEach(function (r) { tbody.appendChild(r); });
                }
                apply();
                var noMore = (!d || !d.added || (d.next_start && d.next_start > 1000));
                if (noMore) {
                    var span = document.createElement('span');
                    span.className = 'text-muted-soft';
                    span.style.fontSize = '12px';
                    span.textContent = '더 이상 없음';
                    moreBtn.replaceWith(span);
                } else {
                    moreBtn.dataset.next = d.next_start;
                    moreBtn.disabled = false;
                }
                Swal.fire({ icon: 'success', title: (d && d.added ? d.added : 0) + '개 추가 수집 완료', timer: 1300, showConfirmButton: false });
            }).catch(function () {
                stop(); moreBtn.disabled = false;
                Swal.fire({ icon: 'error', title: '수집 실패', text: '잠시 후 다시 시도하세요.' });
            });
        });
    }

    // 블로거 저장 — 체크박스 다중 저장 + 행별 ★ 토글. (키워드×블로그ID) 조합으로 서버 저장.
    var saveBtn = document.getElementById('bi-save-sel');
    var selCountEl = document.getElementById('bi-sel-count');
    var checkAll = document.getElementById('bi-check-all');
    var analysisId = saveBtn ? saveBtn.dataset.analysis : null;

    function updateSel() {
        var n = tbody.querySelectorAll('.bi-sel:checked').length;
        if (selCountEl) selCountEl.textContent = n;
        if (saveBtn) saveBtn.disabled = n === 0;
    }
    function setSaved(tr, on) {
        tr.dataset.saved = on ? '1' : '0';
        var st = tr.querySelector('.bi-star');
        if (st) { st.textContent = on ? '★' : '☆'; st.title = on ? '저장 해제' : '블로거 저장 (키워드+ID)'; }
    }
    function postIds(action, ids) {
        return fetch('{{ url('console/blog-index') }}/' + analysisId + '/' + action, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ blog_ids: ids })
        }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); });
    }

    tbody.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('bi-sel')) updateSel();
    });
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            tbody.querySelectorAll('tr.bi-row').forEach(function (tr) {
                if (tr.style.display === 'none') return; // 필터로 숨긴 행은 제외
                var cb = tr.querySelector('.bi-sel');
                if (cb) cb.checked = checkAll.checked;
            });
            updateSel();
        });
    }
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var checked = Array.prototype.slice.call(tbody.querySelectorAll('.bi-sel:checked'));
            var ids = checked.map(function (c) { return c.value; }).filter(Boolean);
            if (!ids.length) return;
            saveBtn.disabled = true;
            postIds('save', ids).then(function (d) {
                checked.forEach(function (c) { setSaved(c.closest('tr.bi-row'), true); c.checked = false; });
                if (checkAll) checkAll.checked = false;
                updateSel(); apply();
                Swal.fire({ icon: 'success', title: (d.saved || 0) + '개 블로거 저장 완료', text: '저장 블로거 페이지에서 모아보고 엑셀로 내려받을 수 있습니다.', timer: 1800, showConfirmButton: false });
            }).catch(function () {
                updateSel();
                Swal.fire({ icon: 'error', title: '저장 실패', text: '잠시 후 다시 시도하세요.' });
            });
        });
    }
    // ★ 토글 — 동적 추가 행 포함(이벤트 위임). 저장됨이면 해제, 아니면 저장.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.bi-star');
        if (!btn || !analysisId) return;
        var tr = btn.closest('tr.bi-row');
        var bid = tr && tr.dataset.blogid;
        if (!bid) return;
        var wasSaved = tr.dataset.saved === '1';
        btn.disabled = true;
        postIds(wasSaved ? 'unsave' : 'save', [bid]).then(function () {
            setSaved(tr, !wasSaved);
            apply(); // '저장됨만' 필터 활성 시 즉시 반영
        }).catch(function () {
            Swal.fire({ icon: 'error', title: (wasSaved ? '해제' : '저장') + ' 실패', text: '잠시 후 다시 시도하세요.' });
        }).then(function () { btn.disabled = false; });
    });

    apply();
})();
</script>

<style>
    .bi-trow { display:flex; align-items:center; flex-wrap:wrap; row-gap:12px; column-gap:20px; }
    .bi-fgroup { display:flex; align-items:center; gap:10px; }
    .bi-chips { display:flex; align-items:center; gap:6px; }
    .bi-div { width:1px; height:22px; background:var(--color-hairline); flex:none; }
    .bi-chip { display:inline-flex; align-items:center; justify-content:center; min-width:30px; height:32px; padding:0 11px; border:1px solid var(--color-hairline); border-radius:8px; background:var(--color-canvas); font-size:var(--fs-xs); font-weight:600; color:var(--color-body); cursor:pointer; transition:all .12s; }
    .bi-chip:hover { border-color:var(--color-ink); }
    .bi-chip.on { background:var(--color-ink); color:var(--color-canvas); border-color:var(--color-ink); }
    .bi-sort { cursor:pointer; user-select:none; white-space:nowrap; }
    .bi-sort:hover { color:var(--color-ink); }
    .bi-sort .ar { font-size:var(--fs-xs); }
    .bi-sort.act { color:var(--color-ink); }
    .bi-star { font-size:var(--fs-base); line-height:1; color:var(--color-muted-soft); cursor:pointer; background:none; border:none; padding:2px 4px; transition:color .12s, transform .12s; }
    .bi-star:hover { color:var(--color-warning); transform:scale(1.15); }
    tr.bi-row[data-saved="1"] .bi-star { color:var(--color-warning); }
    .bi-analyze { position:relative; display:inline-block; }
    .bi-analyze-link { cursor:pointer; }
    .bi-analyze-tip { display:none; position:absolute; left:0; bottom:calc(100% + 5px); background:var(--color-ink); color:var(--color-canvas); padding:4px 9px; border-radius:6px; font-size:var(--fs-xs); font-weight:500; white-space:nowrap; z-index:20; box-shadow:0 4px 12px rgba(0,0,0,.18); }
    .bi-analyze-tip::after { content:''; position:absolute; top:100%; left:14px; border:5px solid transparent; border-top-color:var(--color-ink); }
    /* 행(tr) 어디에 hover해도 지수분석 안내를 노출 — 아이디 셀뿐 아니라 라인 전체 반응 */
    tr.bi-row { transition:background .12s; }
    tr.bi-row:hover { background:var(--color-surface-soft); cursor:pointer; }
    tr.bi-row:hover .bi-analyze-tip { display:block; }
    tr.bi-row:hover .bi-analyze-link { color:var(--color-ink); text-decoration:underline; }
</style>
@endsection
