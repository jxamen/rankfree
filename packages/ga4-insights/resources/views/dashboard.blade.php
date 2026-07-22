@use('Jcurve\Ga4Insights\Support\Format', 'F')
@php
    $__layout = config('ga4-insights.view.layout', 'ga4-insights::layout');
    $__section = config('ga4-insights.view.section', 'content');
    $__routeName = config('ga4-insights.route.name', 'ga4-insights');
    $__site = rtrim((string) config('ga4-insights.site_url', ''), '/');
@endphp
@extends($__layout)
@section('page-title', 'GA4 방문 분석')
@section($__section)
<div class="ga4-dash">
@include('ga4-insights::partials.style')

<div class="ga4-title">방문 상세 분석 <small>GA4</small></div>
<div class="ga4-sub" style="margin:6px 0 14px;">
    누가 · 어디서 왔고 · 무엇을 보고 · 어디서 나갔는지 한눈에 봐요.
    @if ($ga['configured'] && empty($ga['error']))
        @php
            $__note = $ga['days'] === 'today' ? '오늘 · 집계 중 — 어제와 비교'
                : ($ga['days'] === 1 ? '어제 하루 · 그제와 비교' : '어제까지 · 직전 '.$ga['days'].'일과 비교');
        @endphp
        <span>· 집계 {{ $ga['range']['start'] }}{{ $ga['range']['start'] !== $ga['range']['end'] ? ' ~ '.$ga['range']['end'] : '' }} <span style="color:var(--ga4-soft)">({{ $__note }})</span></span>
    @endif
</div>

{{-- 기간 선택 · 실시간 · 새로고침 · 배치 초기화 --}}
<div class="ga4-toolbar">
    @foreach ($ga['presets'] as $p)
        <a class="ga4-btn {{ $ga['days'] === $p ? 'on' : '' }}" href="?days={{ $p }}">{{ $p === 'today' ? '오늘' : ($p === 1 ? '최근 1일' : '최근 '.$p.'일') }}</a>
    @endforeach
    <span class="sp"></span>
    @if ($ga['configured'] && empty($ga['error']))
        <span class="ga4-rt {{ ($ga['realtime']['activeUsers'] ?? 0) > 0 ? 'ga4-up' : 'ga4-flat' }}"><span class="ga4-dot"></span>지금 {{ F::int($ga['realtime']['activeUsers'] ?? 0) }}명 접속 중</span>
        <button type="button" class="ga4-btn" id="ga4-layout-reset" title="드래그로 바꾼 섹션 배치를 원래대로 되돌려요">↺ 배치 초기화</button>
    @endif
    <form method="POST" action="{{ route($__routeName.'.refresh') }}" style="display:inline;">
        @csrf<input type="hidden" name="days" value="{{ $ga['days'] }}">
        <button type="submit" class="ga4-btn" title="캐시를 비우고 GA4에서 다시 불러와요">↻ 새로고침</button>
    </form>
</div>

@if (! $ga['configured'])
    @include('ga4-insights::partials.not-configured')
@elseif (! empty($ga['error']))
    <div class="ga4-banner err"><b>GA4 데이터를 불러오지 못했습니다.</b><br>{{ $ga['error'] }}</div>
@else

{{-- 섹션 컨테이너 — 드래그앤드롭 12그리드. ⠿ 핸들을 끌어 다른 섹션 위에 놓으면 같은 줄에 나란히(균등 분할),
     줄 사이 틈에 놓으면 그 자리에 한 줄로. 배치는 localStorage(브라우저별) 저장. --}}
<div id="ga4-layout">

{{-- ① 개요 --}}
<div class="ga4-section" data-sec="overview">
    <div class="head"><span class="ga4-drag" title="끌어서 위치 이동 — 다른 섹션 위에 놓으면 같은 줄에 나란히 붙어요">⠿</span><h2>① 개요 — 핵심 지표</h2><span class="d">숫자 아래 화살표는 직전 같은 기간과 비교예요. 지표 옆 <i class="ga4-help">?</i> 에 마우스를 올리면 설명이 나와요.</span></div>
    <div class="ga4-grid ga4-kpis">
        @foreach ($ga['kpis'] as $k)
            @php
                $val = match ($k['format']) {
                    'pct' => F::pct($k['value']),
                    'duration' => F::duration($k['value']),
                    'num1' => number_format((float) $k['value'], 1),
                    default => F::int($k['value']),
                };
                $dl = F::delta($k['value'], $k['prev']);
                $isGood = ($dl['dir'] === 'up') === $k['goodUp'];
                $cls = $dl['dir'] === 'flat' ? 'ga4-flat' : ($isGood ? 'ga4-up' : 'ga4-down');
                $arrow = $dl['dir'] === 'up' ? '▲' : ($dl['dir'] === 'down' ? '▼' : '–');
            @endphp
            <div class="ga4-card ga4-kpi">
                <div class="lab">{{ $k['label'] }} <i class="ga4-help" title="{{ $k['help'] }}">?</i></div>
                <div class="val">{{ $val }}</div>
                <div class="dlt {{ $cls }}">{{ $arrow }} {{ $dl['text'] }}</div>
            </div>
        @endforeach
    </div>
</div>

{{-- ② 추이 --}}
@if (count($ga['trend']))
    <div class="ga4-section" data-sec="trend">
        <div class="head"><span class="ga4-drag" title="끌어서 위치 이동 — 다른 섹션 위에 놓으면 같은 줄에 나란히 붙어요">⠿</span><h2>② 방문 추이</h2><span class="d">날짜별 사용자 수 — 막대 위 숫자가 사용자, 올리면 세션·페이지뷰도 나와요</span></div>
        <div class="ga4-card">
            @php
                $mxT = max(1, (int) collect($ga['trend'])->max('users') ?: 1);
                $showN = count($ga['trend']) <= 31;   // 90일처럼 빽빽하면 숫자 생략(툴팁으로)
                $fmtN = fn ($n) => $n >= 10000 ? rtrim(rtrim(number_format($n / 10000, 1), '0'), '.').'만'
                    : ($n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.').'천' : (string) $n);
            @endphp
            <div class="ga4-chart {{ $showN ? 'nums' : '' }}">
                @foreach ($ga['trend'] as $t)
                    <div class="c" title="{{ $t['date'] }} · 사용자 {{ F::int($t['users']) }} · 세션 {{ F::int($t['sessions']) }} · 페이지뷰 {{ F::int($t['views']) }}">
                        @if ($showN)<span class="n">{{ $t['users'] > 0 ? $fmtN($t['users']) : '' }}</span>@endif
                        <span class="b" style="height:{{ max(2, round($t['users'] / $mxT * 100)) }}%"></span>
                    </div>
                @endforeach
            </div>
            <div class="ga4-axis"><span>{{ collect($ga['trend'])->first()['date'] ?? '' }}</span><span>{{ collect($ga['trend'])->last()['date'] ?? '' }}</span></div>
        </div>
    </div>
@endif

{{-- ③ 유입 --}}
<div class="ga4-section" data-sec="traffic">
    <div class="head"><span class="ga4-drag" title="끌어서 위치 이동 — 다른 섹션 위에 놓으면 같은 줄에 나란히 붙어요">⠿</span><h2>③ 어디서 왔나 — 유입</h2><span class="d">방문자가 우리 사이트로 들어온 경로예요</span></div>
    <div class="ga4-card" style="margin-bottom:12px;">
        <div class="ga4-card-h">유입 채널 <i class="ga4-help" title="검색·SNS·직접입력·추천링크 등 큰 분류(세션 기준)">?</i></div>
        @include('ga4-insights::partials.barlist', ['rows' => $ga['channels'], 'key' => 'sessions', 'showPct' => true])
    </div>
    <div class="ga4-grid ga4-cols2">
        <div class="ga4-card">
            <div class="ga4-card-h">소스 / 매체 <i class="ga4-help" title="정확히 어떤 사이트·어떤 방식으로 왔는지(google/organic, naver/referral 등)">?</i></div>
            <div class="ga4-scroll"><table class="ga4-tbl">
                <thead><tr><th>소스 / 매체</th><th class="num">세션</th><th class="num">사용자</th><th class="num">참여율</th></tr></thead>
                <tbody>
                    @forelse ($ga['sourceMedium'] as $s)
                        <tr><td>{{ $s['name'] }}</td><td class="num">{{ F::int($s['sessions']) }}</td><td class="num">{{ F::int($s['users']) }}</td><td class="num">{{ F::pct($s['engRate']) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="ga4-empty">데이터 없음</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>
        <div class="ga4-card">
            <div class="ga4-card-h">캠페인 <i class="ga4-help" title="광고·마케팅 캠페인(utm_campaign)별 유입. 미지정=일반 유입">?</i></div>
            <div class="ga4-scroll"><table class="ga4-tbl">
                <thead><tr><th>캠페인</th><th class="num">세션</th><th class="num">사용자</th></tr></thead>
                <tbody>
                    @forelse ($ga['campaigns'] as $c)
                        <tr><td>{{ $c['name'] }}</td><td class="num">{{ F::int($c['sessions']) }}</td><td class="num">{{ F::int($c['users']) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="ga4-empty">데이터 없음</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </div>
    </div>
</div>

{{-- ④ 랜딩 --}}
<div class="ga4-section" data-sec="landing">
    <div class="head"><span class="ga4-drag" title="끌어서 위치 이동 — 다른 섹션 위에 놓으면 같은 줄에 나란히 붙어요">⠿</span><h2>④ 어디로 들어왔나 — 랜딩 페이지</h2><span class="d">방문자가 <b>처음 도착한</b> 페이지. 이탈률이 높으면 그 페이지에서 바로 나간 사람이 많다는 뜻이에요</span></div>
    <div class="ga4-card"><div class="ga4-scroll"><table class="ga4-tbl">
        <thead><tr><th>랜딩 페이지</th><th class="num">세션</th><th class="num">참여율</th><th class="num">이탈률</th><th class="num">전환</th></tr></thead>
        <tbody>
            @forelse ($ga['landing'] as $l)
                <tr>
                    <td class="p" title="{{ $l['name'] }}">@if ($__site && str_starts_with($l['name'], '/'))<a href="{{ $__site.$l['name'] }}" target="_blank" rel="noopener">{{ $l['name'] }}</a>@else{{ $l['name'] }}@endif</td>
                    <td class="num">{{ F::int($l['sessions']) }}</td>
                    <td class="num">{{ F::pct($l['engRate']) }}</td>
                    <td class="num {{ $l['bounceRate'] >= 0.7 ? 'warn' : '' }}">{{ F::pct($l['bounceRate']) }}</td>
                    <td class="num">{{ F::int($l['keyEvents']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="ga4-empty">데이터 없음</td></tr>
            @endforelse
        </tbody>
    </table></div></div>
</div>

{{-- ⑤ 인기 페이지 --}}
<div class="ga4-section" data-sec="pages">
    <div class="head"><span class="ga4-drag" title="끌어서 위치 이동 — 다른 섹션 위에 놓으면 같은 줄에 나란히 붙어요">⠿</span><h2>⑤ 무엇을 봤나 — 인기 페이지</h2><span class="d">가장 많이 조회된 페이지와 평균 체류 시간</span></div>
    <div class="ga4-card"><div class="ga4-scroll"><table class="ga4-tbl">
        <thead><tr><th>페이지</th><th class="num">페이지뷰</th><th class="num">사용자</th><th class="num">평균 체류</th></tr></thead>
        <tbody>
            @forelse ($ga['pages'] as $p)
                <tr>
                    <td class="p" title="{{ $p['name'] }}">@if ($__site && str_starts_with($p['name'], '/'))<a href="{{ $__site.$p['name'] }}" target="_blank" rel="noopener">{{ $p['name'] }}</a>@else{{ $p['name'] }}@endif</td>
                    <td class="num">{{ F::int($p['views']) }}</td>
                    <td class="num">{{ F::int($p['users']) }}</td>
                    <td class="num">{{ F::duration(($p['engageSec'] ?? 0) / max(1, $p['users'])) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="ga4-empty">데이터 없음</td></tr>
            @endforelse
        </tbody>
    </table></div></div>
</div>

{{-- ⑥ 이탈 --}}
<div class="ga4-section" data-sec="dropoff">
    <div class="head"><span class="ga4-drag" title="끌어서 위치 이동 — 다른 섹션 위에 놓으면 같은 줄에 나란히 붙어요">⠿</span><h2>⑥ 어디서 나갔나 — 이탈 많은 유입 페이지</h2><span class="d">들어오자마자 <b>참여 없이 바로 나간</b> 비율이 높은 랜딩 페이지예요. 개선하면 붙잡을 수 있어요</span></div>
    <div class="ga4-card">
        <div class="ga4-scroll"><table class="ga4-tbl">
            <thead><tr><th>랜딩 페이지</th><th class="num">이탈률</th><th class="num">세션</th></tr></thead>
            <tbody>
                @forelse ($ga['dropoff'] as $d)
                    <tr><td class="p" title="{{ $d['name'] }}">{{ $d['name'] }}</td><td class="num warn">{{ F::pct($d['bounceRate']) }}</td><td class="num">{{ F::int($d['sessions']) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="ga4-empty">이탈이 두드러지는 페이지가 없어요 👍</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="ga4-note">※ GA4는 '이탈'을 참여 없이 끝난 세션으로 봅니다(참여율의 반대). 실제 페이지 이동 경로는 GA4 '탐색 → 경로'에서 더 자세히 볼 수 있어요.</div>
    </div>
</div>

{{-- ⑦ 누가·어떻게 --}}
<div class="ga4-section" data-sec="audience">
    <div class="head"><span class="ga4-drag" title="끌어서 위치 이동 — 다른 섹션 위에 놓으면 같은 줄에 나란히 붙어요">⠿</span><h2>⑦ 누가 · 어떻게 봤나</h2><span class="d">기기 · 신규/재방문 · 브라우저</span></div>
    <div class="ga4-grid ga4-cols2">
        <div class="ga4-card">
            <div class="ga4-card-h">기기 <i class="ga4-help" title="데스크톱·모바일·태블릿 세션 비율">?</i></div>
            @include('ga4-insights::partials.barlist', ['rows' => $ga['devices'], 'key' => 'sessions', 'showPct' => true])
            <div class="ga4-card-h" style="margin-top:18px;">신규 vs 재방문 <i class="ga4-help" title="처음 온 사람과 다시 온 사람">?</i></div>
            @include('ga4-insights::partials.barlist', ['rows' => $ga['newReturning'], 'key' => 'sessions', 'showPct' => true])
        </div>
        <div class="ga4-card">
            <div class="ga4-card-h">브라우저</div>
            @include('ga4-insights::partials.barlist', ['rows' => $ga['browsers'], 'key' => 'sessions', 'showPct' => true])
        </div>
    </div>
</div>

{{-- ⑩ 지역(도시) — ⑦에서 분리한 개별 카드(2026-07-22 요청) --}}
<div class="ga4-section" data-sec="cities">
    <div class="head"><span class="ga4-drag" title="끌어서 위치 이동 — 다른 섹션 위에 놓으면 같은 줄에 나란히 붙어요">⠿</span><h2>⑩ 어디 지역에서 왔나 — 지역(도시)</h2><span class="d">방문자가 접속한 도시(세션 기준)</span></div>
    <div class="ga4-card">
        @include('ga4-insights::partials.barlist', ['rows' => collect($ga['cities'])->take(12)->all(), 'key' => 'sessions', 'showPct' => true])
    </div>
</div>

{{-- ⑧ 이벤트 --}}
<div class="ga4-section" data-sec="events">
    <div class="head"><span class="ga4-drag" title="끌어서 위치 이동 — 다른 섹션 위에 놓으면 같은 줄에 나란히 붙어요">⠿</span><h2>⑧ 무슨 행동을 했나 — 이벤트</h2><span class="d">페이지 조회·스크롤·클릭 등 사이트에서 일어난 행동</span></div>
    <div class="ga4-card"><div class="ga4-scroll"><table class="ga4-tbl">
        <thead><tr><th>이벤트</th><th class="num">발생 수</th><th class="num">사용자</th></tr></thead>
        <tbody>
            @forelse ($ga['events'] as $e)
                <tr><td>{{ $e['name'] }}</td><td class="num">{{ F::int($e['events']) }}</td><td class="num">{{ F::int($e['users']) }}</td></tr>
            @empty
                <tr><td colspan="3" class="ga4-empty">데이터 없음</td></tr>
            @endforelse
        </tbody>
    </table></div></div>
</div>

{{-- ⑨ 시간대 --}}
<div class="ga4-section" data-sec="hours">
    <div class="head"><span class="ga4-drag" title="끌어서 위치 이동 — 다른 섹션 위에 놓으면 같은 줄에 나란히 붙어요">⠿</span><h2>⑨ 언제 오나 — 시간대</h2><span class="d">하루 중 방문이 몰리는 시간(세션 기준)</span></div>
    <div class="ga4-card">
        @php $mxH = max(1, max($ga['hours']) ?: 1); @endphp
        <div class="ga4-hours">
            @foreach ($ga['hours'] as $h => $v)
                <div title="{{ $h }}시 · 세션 {{ F::int($v) }}" style="height:{{ max(2, round($v / $mxH * 100)) }}%"></div>
            @endforeach
        </div>
        <div class="ga4-hours-axis"><span>0시</span><span>6시</span><span>12시</span><span>18시</span><span>23시</span></div>
    </div>
</div>

{{-- 실시간 상세 --}}
@if (! empty($ga['realtime']['pages']))
    <div class="ga4-section" data-sec="realtime">
        <div class="head"><span class="ga4-drag" title="끌어서 위치 이동 — 다른 섹션 위에 놓으면 같은 줄에 나란히 붙어요">⠿</span><h2>🟢 지금 접속 중 — 실시간</h2><span class="d">현재 활성 사용자가 보고 있는 페이지</span></div>
        <div class="ga4-card">
            @include('ga4-insights::partials.barlist', ['rows' => $ga['realtime']['pages'], 'key' => 'users', 'showPct' => false])
        </div>
    </div>
@endif

</div>{{-- /#ga4-layout --}}

<script>
// 섹션 드래그앤드롭 배치 — 12그리드. 같은 줄에 놓으면 균등 분할(2=6+6, 3=4+4+4, 최대 4),
// 줄 사이 틈에 놓으면 그 자리 한 줄. localStorage(브라우저별) 저장, 새 섹션은 맨 아래로.
(function () {
    var wrap = document.getElementById('ga4-layout');
    if (!wrap) return;
    var KEY = 'ga4-insights-layout-v1';
    var MAX = 4;
    var sections = {};
    wrap.querySelectorAll('[data-sec]').forEach(function (s) { sections[s.dataset.sec] = s; });
    var defaults = Object.keys(sections).map(function (k) { return [k]; });

    function load() {
        try {
            var raw = JSON.parse(localStorage.getItem(KEY) || 'null');
            if (!Array.isArray(raw)) return null;
            var seen = {}, out = [];
            raw.forEach(function (row) {
                var r = (Array.isArray(row) ? row : []).filter(function (k) {
                    return sections[k] && !seen[k] && (seen[k] = 1);
                });
                if (r.length) out.push(r);
            });
            Object.keys(sections).forEach(function (k) { if (!seen[k]) out.push([k]); });
            return out.length ? out : null;
        } catch (e) { return null; }
    }
    function save() { try { localStorage.setItem(KEY, JSON.stringify(layout)); } catch (e) {} }

    var layout = load() || defaults;

    function render() {
        wrap.innerHTML = '';
        layout.forEach(function (row, ri) {
            wrap.appendChild(gap(ri));
            var el = document.createElement('div');
            el.className = 'ga4-row cols' + Math.min(row.length, MAX);
            row.forEach(function (k) { el.appendChild(sections[k]); });
            wrap.appendChild(el);
        });
        wrap.appendChild(gap(layout.length));
    }
    function gap(i) {
        var z = document.createElement('div');
        z.className = 'ga4-rowgap';
        z.dataset.row = i;
        return z;
    }
    function findKey(k) {
        for (var i = 0; i < layout.length; i++) {
            var j = layout[i].indexOf(k);
            if (j >= 0) return [i, j];
        }
        return null;
    }
    function removeKey(k) {
        var p = findKey(k);
        if (!p) return null;
        layout[p[0]].splice(p[1], 1);
        if (!layout[p[0]].length) { layout.splice(p[0], 1); return p[0]; }   // 줄이 사라짐 → 인덱스 보정용
        return null;
    }
    function clearHints() {
        wrap.querySelectorAll('.over').forEach(function (e) { e.classList.remove('over'); });
    }

    // ── 드래그: HTML5 DnD 대신 Pointer Events 커스텀 구현 ──────────────────
    // HTML5 DnD 는 스펙대로 dragenter/dragover 를 전부 취소해도 환경에 따라 drop 이 유실된다(헤드리스 실측).
    // 포인터 기반이 데스크톱·터치 모두 안정적이고 자동화 검증도 가능하다.
    var ghost = null;

    function targetAt(x, y) {
        var el = document.elementFromPoint(x, y);   // ghost 는 pointer-events:none 이라 안 걸린다
        if (!el || !el.closest) return null;

        return el.closest('.ga4-rowgap') || el.closest('#ga4-layout [data-sec]');
    }

    function applyDrop(k, t) {
        if (!t) return;
        if (t.classList.contains('ga4-rowgap')) {              // 줄 사이 틈 → 그 자리에 한 줄로
            var at = parseInt(t.dataset.row, 10) || 0;
            var removedRow = removeKey(k);
            if (removedRow !== null && removedRow < at) at--;  // 위쪽 줄이 통째로 사라졌으면 한 칸 위로
            at = Math.max(0, Math.min(at, layout.length));
            layout.splice(at, 0, [k]);
        } else if (t.dataset.sec && t.dataset.sec !== k) {     // 섹션 위 → 그 줄에 나란히(균등 분할)
            var pos = findKey(t.dataset.sec);
            var mine = findKey(k);
            if (!pos) return;
            if (layout[pos[0]].length >= MAX && !(mine && mine[0] === pos[0])) return;   // 한 줄 최대 4
            removeKey(k);
            pos = findKey(t.dataset.sec);
            layout[pos[0]].splice(pos[1] + 1, 0, k);
        } else {
            return;
        }
        save();
        render();
    }

    wrap.addEventListener('pointerdown', function (e) {
        if (e.button !== 0) return;
        var h = e.target.closest ? e.target.closest('.ga4-drag') : null;
        if (!h) return;
        e.preventDefault();
        var sec = h.closest('[data-sec]');
        var key = sec.dataset.sec;
        var sx = e.clientX, sy = e.clientY;
        var active = false;
        var lastY = e.clientY, rafId = null;

        // 대시보드가 길어 화면 밖으로 끌 일이 많다 — 가장자리 근처에선 자동 스크롤
        function tick() {
            var m = 90, dy = 0;
            if (lastY < m) dy = -14;
            else if (lastY > window.innerHeight - m) dy = 14;
            if (dy) window.scrollBy(0, dy);
            rafId = requestAnimationFrame(tick);
        }

        function onMove(ev) {
            if (!active) {
                if (Math.abs(ev.clientX - sx) + Math.abs(ev.clientY - sy) < 5) return;   // 클릭 오인 방지
                active = true;
                wrap.classList.add('dragging');
                sec.classList.add('drag-src');
                ghost = document.createElement('div');
                ghost.className = 'ga4-ghost';
                ghost.textContent = '⠿ ' + ((sec.querySelector('h2') || {}).textContent || '섹션').trim();
                document.body.appendChild(ghost);
                rafId = requestAnimationFrame(tick);
            }
            lastY = ev.clientY;
            ghost.style.left = (ev.clientX + 14) + 'px';
            ghost.style.top = (ev.clientY + 10) + 'px';
            clearHints();
            var t = targetAt(ev.clientX, ev.clientY);
            if (t && !(t.dataset && t.dataset.sec === key)) t.classList.add('over');
            ev.preventDefault();
        }
        function onUp(ev) {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onUp);
            document.removeEventListener('pointercancel', onUp);
            if (rafId) cancelAnimationFrame(rafId);
            if (!active) return;
            var t = targetAt(ev.clientX, ev.clientY);
            wrap.classList.remove('dragging');
            clearHints();
            sec.classList.remove('drag-src');
            if (ghost) { ghost.remove(); ghost = null; }
            if (ev.type !== 'pointercancel') applyDrop(key, t);
        }
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
        document.addEventListener('pointercancel', onUp);
    });

    var reset = document.getElementById('ga4-layout-reset');
    if (reset) reset.addEventListener('click', function () {
        try { localStorage.removeItem(KEY); } catch (e) {}
        layout = Object.keys(sections).map(function (k) { return [k]; });
        render();
    });

    render();
})();
</script>

@endif
</div>
@endsection
