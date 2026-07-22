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

{{-- 기간 선택 · 실시간 · 지표 표시 · 배치 초기화 · 새로고침 --}}
<div class="ga4-toolbar">
    @foreach ($ga['presets'] as $p)
        <a class="ga4-btn {{ $ga['days'] === $p ? 'on' : '' }}" href="?days={{ $p }}">{{ $p === 'today' ? '오늘' : ($p === 1 ? '최근 1일' : '최근 '.$p.'일') }}</a>
    @endforeach
    <span class="sp"></span>
    @if ($ga['configured'] && empty($ga['error']))
        <span class="ga4-rt {{ ($ga['realtime']['activeUsers'] ?? 0) > 0 ? 'ga4-up' : 'ga4-flat' }}"><span class="ga4-dot"></span>지금 {{ F::int($ga['realtime']['activeUsers'] ?? 0) }}명 접속 중</span>
        <span class="ga4-secmenu">
            <button type="button" class="ga4-btn" id="ga4-sec-btn" title="지표(섹션)를 켜고 끌 수 있어요">지표 표시 ▾</button>
            <div class="ga4-secmenu-panel" id="ga4-sec-panel" hidden></div>
        </span>
        <button type="button" class="ga4-btn" id="ga4-layout-reset" title="드래그 배치·숨긴 지표를 처음 상태로 되돌려요">↺ 배치 초기화</button>
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

{{-- 섹션 컨테이너 — 드래그앤드롭 12그리드(같은 줄 균등 분할·한 줄 이동·숨김). 배치는 localStorage(브라우저별). --}}
<div id="ga4-layout">

{{-- ① 개요 --}}
<div class="ga4-section" data-sec="overview">
    @include('ga4-insights::partials.sec-head', ['t' => '① 개요 — 핵심 지표', 'd' => '숫자 아래 화살표는 직전 같은 기간과 비교예요. 지표 옆 <i class="ga4-help">?</i> 에 마우스를 올리면 설명이 나와요.'])
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
        @include('ga4-insights::partials.sec-head', ['t' => '② 방문 추이', 'd' => '날짜별 사용자 수 — 막대 위 숫자가 사용자, 올리면 세션·페이지뷰도 나와요'])
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

{{-- ③ 유입 채널 --}}
<div class="ga4-section" data-sec="channels">
    @include('ga4-insights::partials.sec-head', ['t' => '③ 어디서 왔나 — 유입 채널', 'd' => '검색·SNS·직접입력·추천링크 등 큰 분류(세션 기준)'])
    <div class="ga4-card">
        @include('ga4-insights::partials.barlist', ['rows' => $ga['channels'], 'key' => 'sessions', 'showPct' => true])
    </div>
</div>

{{-- ④ 소스/매체 --}}
<div class="ga4-section" data-sec="sourceMedium">
    @include('ga4-insights::partials.sec-head', ['t' => '④ 소스 / 매체', 'd' => '정확히 어떤 사이트·어떤 방식으로 왔는지(google/organic, naver/referral 등)'])
    <div class="ga4-card">
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
</div>

{{-- ⑤ 캠페인 --}}
<div class="ga4-section" data-sec="campaigns">
    @include('ga4-insights::partials.sec-head', ['t' => '⑤ 캠페인', 'd' => '광고·마케팅 캠페인(utm_campaign)별 유입. 미지정=일반 유입'])
    <div class="ga4-card">
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

{{-- ⑥ 검색 유입 키워드 --}}
@php $sk = $ga['searchKeywords'] ?? ['queries' => [], 'landing' => []]; @endphp
<div class="ga4-section" data-sec="keywords">
    @include('ga4-insights::partials.sec-head', ['t' => '⑥ 무슨 검색어로 왔나 — 검색 유입 키워드', 'd' => '구글은 서치 콘솔의 <b>실제 검색어</b>, 네이버 등은 검색어를 안 넘겨줘서 <b>키워드 페이지 랜딩 기반 추정</b>이에요'])
    <div class="ga4-grid ga4-cols2">
        <div class="ga4-card">
            <div class="ga4-card-h">구글 실제 검색어 <i class="ga4-help" title="구글 서치 콘솔 수집분 — 원천이 2~3일 지연이라 최근 며칠·'오늘'은 비어 있을 수 있어요">?</i></div>
            @if (count($sk['queries']))
                <div class="ga4-scroll"><table class="ga4-tbl">
                    <thead><tr><th>검색어</th><th class="num">클릭</th><th class="num">노출</th><th class="num">평균순위</th></tr></thead>
                    <tbody>
                        @foreach ($sk['queries'] as $q)
                            <tr><td>{{ $q['query'] }}</td><td class="num">{{ F::int($q['clicks']) }}</td><td class="num">{{ F::int($q['impressions']) }}</td><td class="num">{{ $q['position'] !== null ? number_format($q['position'], 1) : '–' }}</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            @else
                <div class="ga4-empty">기간 내 서치 콘솔 검색어가 없어요<br><span style="font-size:.92em">(원천 2~3일 지연 — '오늘'·'최근 1일'은 비어 있을 수 있어요)</span></div>
            @endif
        </div>
        <div class="ga4-card">
            <div class="ga4-card-h">검색엔진별 키워드 페이지 유입 <i class="ga4-help" title="네이버·다음 등은 검색어를 안 넘겨줍니다. 키워드 슬러그 페이지(/keyword/… 등)로 들어온 랜딩을 키워드로 환산한 추정치예요">?</i></div>
            @if (count($sk['landing']))
                <div class="ga4-scroll"><table class="ga4-tbl">
                    <thead><tr><th>소스</th><th>키워드(랜딩)</th><th class="num">세션</th><th class="num">사용자</th></tr></thead>
                    <tbody>
                        @foreach ($sk['landing'] as $l)
                            <tr><td>{{ $l['source'] }}</td><td>{{ $l['keyword'] }}</td><td class="num">{{ F::int($l['sessions']) }}</td><td class="num">{{ F::int($l['users']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            @else
                <div class="ga4-empty">키워드 페이지로 들어온 검색 유입이 아직 없어요</div>
            @endif
        </div>
    </div>
</div>

{{-- ⑦ 랜딩 --}}
<div class="ga4-section" data-sec="landing">
    @include('ga4-insights::partials.sec-head', ['t' => '⑦ 어디로 들어왔나 — 랜딩 페이지', 'd' => '방문자가 <b>처음 도착한</b> 페이지. 이탈률이 높으면 그 페이지에서 바로 나간 사람이 많다는 뜻이에요'])
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

{{-- ⑧ 인기 페이지 --}}
<div class="ga4-section" data-sec="pages">
    @include('ga4-insights::partials.sec-head', ['t' => '⑧ 무엇을 봤나 — 인기 페이지', 'd' => '가장 많이 조회된 페이지와 평균 체류 시간'])
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

{{-- ⑨ 이탈 --}}
<div class="ga4-section" data-sec="dropoff">
    @include('ga4-insights::partials.sec-head', ['t' => '⑨ 어디서 나갔나 — 이탈 많은 유입 페이지', 'd' => '들어오자마자 <b>참여 없이 바로 나간</b> 비율이 높은 랜딩 페이지예요. 개선하면 붙잡을 수 있어요'])
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

{{-- ⑩ 기기 --}}
<div class="ga4-section" data-sec="devices">
    @include('ga4-insights::partials.sec-head', ['t' => '⑩ 어떤 기기로 봤나 — 기기', 'd' => '데스크톱·모바일·태블릿 세션 비율'])
    <div class="ga4-card">
        @include('ga4-insights::partials.barlist', ['rows' => $ga['devices'], 'key' => 'sessions', 'showPct' => true])
    </div>
</div>

{{-- ⑪ 신규 vs 재방문 --}}
<div class="ga4-section" data-sec="newret">
    @include('ga4-insights::partials.sec-head', ['t' => '⑪ 처음 왔나 다시 왔나 — 신규 vs 재방문', 'd' => '처음 온 사람과 다시 온 사람'])
    <div class="ga4-card">
        @include('ga4-insights::partials.barlist', ['rows' => $ga['newReturning'], 'key' => 'sessions', 'showPct' => true])
    </div>
</div>

{{-- ⑫ 브라우저 --}}
<div class="ga4-section" data-sec="browsers">
    @include('ga4-insights::partials.sec-head', ['t' => '⑫ 무슨 브라우저로 봤나 — 브라우저', 'd' => '크롬·사파리·웨일 등 브라우저별 세션'])
    <div class="ga4-card">
        @include('ga4-insights::partials.barlist', ['rows' => $ga['browsers'], 'key' => 'sessions', 'showPct' => true])
    </div>
</div>

{{-- ⑬ 이벤트 --}}
<div class="ga4-section" data-sec="events">
    @include('ga4-insights::partials.sec-head', ['t' => '⑬ 무슨 행동을 했나 — 이벤트', 'd' => '페이지 조회·스크롤·클릭 등 사이트에서 일어난 행동'])
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

{{-- ⑭ 시간대 --}}
<div class="ga4-section" data-sec="hours">
    @include('ga4-insights::partials.sec-head', ['t' => '⑭ 언제 오나 — 시간대', 'd' => '하루 중 방문이 몰리는 시간(세션 기준)'])
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

{{-- ⑮ 지역(도시) --}}
<div class="ga4-section" data-sec="cities">
    @include('ga4-insights::partials.sec-head', ['t' => '⑮ 어디 지역에서 왔나 — 지역(도시)', 'd' => '방문자가 접속한 도시(세션 기준)'])
    <div class="ga4-card">
        @include('ga4-insights::partials.barlist', ['rows' => collect($ga['cities'])->take(12)->all(), 'key' => 'sessions', 'showPct' => true])
    </div>
</div>

{{-- 실시간 상세 --}}
@if (! empty($ga['realtime']['pages']))
    <div class="ga4-section" data-sec="realtime">
        @include('ga4-insights::partials.sec-head', ['t' => '🟢 지금 접속 중 — 실시간', 'd' => '현재 활성 사용자가 보고 있는 페이지'])
        <div class="ga4-card">
            @include('ga4-insights::partials.barlist', ['rows' => $ga['realtime']['pages'], 'key' => 'users', 'showPct' => false])
        </div>
    </div>
@endif

</div>{{-- /#ga4-layout --}}

<script>
// 섹션 드래그앤드롭 12그리드 + 지표 표시/숨김.
//  - 섹션 가운데에 놓으면 같은 줄에 나란히(균등 분할, 최대 4)
//  - 섹션 위/아래 가장자리(22%)나 줄 사이 틈에 놓으면 그 자리에 '한 줄'로
//  - ✕ 또는 [지표 표시] 패널로 섹션 켜고 끄기 · 저장은 localStorage(브라우저별)
// ⚠️ HTML5 DnD 대신 Pointer Events — 스펙대로 전부 취소해도 환경에 따라 drop 이 유실된다(실측).
(function () {
    var wrap = document.getElementById('ga4-layout');
    if (!wrap) return;
    var KEY = 'ga4-insights-layout-v1';
    var MAX = 4;
    var sections = {};
    wrap.querySelectorAll('[data-sec]').forEach(function (s) { sections[s.dataset.sec] = s; });
    var order = Object.keys(sections);
    var titles = {};
    order.forEach(function (k) {
        var h = sections[k].querySelector('h2');
        titles[k] = h ? h.textContent.trim() : k;
    });

    var layout, hidden;

    function defaults() { return order.map(function (k) { return [k]; }); }

    function load() {
        try {
            var raw = JSON.parse(localStorage.getItem(KEY) || 'null');
            if (!raw) return false;
            var rows = Array.isArray(raw) ? raw : (Array.isArray(raw.rows) ? raw.rows : []);
            var hid = Array.isArray(raw.hidden) ? raw.hidden : [];
            hidden = hid.filter(function (k) { return sections[k]; });
            var seen = {};
            hidden.forEach(function (k) { seen[k] = 1; });
            layout = [];
            rows.forEach(function (row) {
                var r = (Array.isArray(row) ? row : []).filter(function (k) {
                    return sections[k] && !seen[k] && (seen[k] = 1);
                });
                if (r.length) layout.push(r);
            });
            order.forEach(function (k) { if (!seen[k]) layout.push([k]); });   // 새 섹션은 맨 아래
            return layout.length > 0 || hidden.length > 0;
        } catch (e) { return false; }
    }
    function save() { try { localStorage.setItem(KEY, JSON.stringify({ rows: layout, hidden: hidden })); } catch (e) {} }

    if (!load()) { layout = defaults(); hidden = []; }

    function render() {
        wrap.innerHTML = '';
        layout.forEach(function (row, ri) {
            wrap.appendChild(gapDiv(ri));
            var el = document.createElement('div');
            el.className = 'ga4-row cols' + Math.min(row.length, MAX);
            row.forEach(function (k) { el.appendChild(sections[k]); });
            wrap.appendChild(el);
        });
        wrap.appendChild(gapDiv(layout.length));
        syncPanel();
    }
    function gapDiv(i) {
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

    // ── 드롭 판정 — gap | 섹션 위/아래 가장자리(한 줄) | 섹션 가운데(같은 줄 병합) ──
    function resolveDrop(x, y, dragKey) {
        var el = document.elementFromPoint(x, y);
        if (!el || !el.closest) return null;
        var g = el.closest('.ga4-rowgap');
        if (g) return { type: 'gap', at: parseInt(g.dataset.row, 10) || 0, hint: g };
        var s = el.closest('#ga4-layout [data-sec]');
        if (!s) return null;
        var r = s.getBoundingClientRect();
        var band = (y - r.top) / Math.max(1, r.height);
        var pos = findKey(s.dataset.sec);
        if (pos && band < 0.22) {
            return { type: 'gap', at: pos[0], hint: gapEl(pos[0]) || s };
        }
        if (pos && band > 0.78) {
            return { type: 'gap', at: pos[0] + 1, hint: gapEl(pos[0] + 1) || s };
        }
        if (s.dataset.sec === dragKey) return null;   // 자기 자신 가운데는 무시
        return { type: 'merge', sec: s.dataset.sec, hint: s };
    }
    function gapEl(i) { return wrap.querySelector('.ga4-rowgap[data-row="' + i + '"]'); }

    function applyDrop(k, t) {
        if (!t) return;
        if (t.type === 'gap') {                                // 그 자리에 '한 줄'로
            var at = t.at;
            var removedRow = removeKey(k);
            if (removedRow !== null && removedRow < at) at--;  // 위쪽 줄이 통째로 사라졌으면 한 칸 위로
            at = Math.max(0, Math.min(at, layout.length));
            layout.splice(at, 0, [k]);
        } else if (t.type === 'merge' && t.sec !== k) {        // 그 줄에 나란히(균등 분할)
            var pos = findKey(t.sec);
            var mine = findKey(k);
            if (!pos) return;
            if (layout[pos[0]].length >= MAX && !(mine && mine[0] === pos[0])) return;   // 한 줄 최대 4
            removeKey(k);
            pos = findKey(t.sec);
            layout[pos[0]].splice(pos[1] + 1, 0, k);
        } else {
            return;
        }
        save();
        render();
    }

    // ── 포인터 드래그 ──
    var ghost = null;
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
                ghost.textContent = '⠿ ' + (titles[key] || '섹션');
                document.body.appendChild(ghost);
                rafId = requestAnimationFrame(tick);
            }
            lastY = ev.clientY;
            ghost.style.left = (ev.clientX + 14) + 'px';
            ghost.style.top = (ev.clientY + 10) + 'px';
            clearHints();
            var t = resolveDrop(ev.clientX, ev.clientY, key);
            if (t && t.hint) t.hint.classList.add('over');
            ev.preventDefault();
        }
        function onUp(ev) {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onUp);
            document.removeEventListener('pointercancel', onUp);
            if (rafId) cancelAnimationFrame(rafId);
            if (!active) return;
            var t = resolveDrop(ev.clientX, ev.clientY, key);
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

    // ── 지표 숨김/표시 ──
    function hide(k) {
        if (hidden.indexOf(k) >= 0) return;
        removeKey(k);
        hidden.push(k);
        save();
        render();
    }
    function show(k) {
        var i = hidden.indexOf(k);
        if (i < 0) return;
        hidden.splice(i, 1);
        layout.push([k]);   // 다시 켜면 맨 아래 한 줄로
        save();
        render();
    }
    wrap.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.ga4-hide') : null;
        if (!b) return;
        var sec = b.closest('[data-sec]');
        if (sec) hide(sec.dataset.sec);
    });

    var panel = document.getElementById('ga4-sec-panel');
    var panelBtn = document.getElementById('ga4-sec-btn');
    function syncPanel() {
        if (!panel) return;
        panel.innerHTML = '';
        order.forEach(function (k) {
            var lab = document.createElement('label');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = hidden.indexOf(k) < 0;
            cb.addEventListener('change', function () { cb.checked ? show(k) : hide(k); });
            lab.appendChild(cb);
            lab.appendChild(document.createTextNode(' ' + titles[k]));
            panel.appendChild(lab);
        });
    }
    if (panelBtn) {
        panelBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.hidden = !panel.hidden;
        });
        document.addEventListener('click', function (e) {
            if (!panel.hidden && !panel.contains(e.target) && e.target !== panelBtn) panel.hidden = true;
        });
    }

    var reset = document.getElementById('ga4-layout-reset');
    if (reset) reset.addEventListener('click', function () {
        try { localStorage.removeItem(KEY); } catch (e) {}
        layout = defaults();
        hidden = [];
        render();
    });

    render();
})();
</script>

@endif
</div>
@endsection
