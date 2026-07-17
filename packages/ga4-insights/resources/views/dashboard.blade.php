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
        <span>· 집계 {{ $ga['range']['start'] }} ~ {{ $ga['range']['end'] }} <span style="color:var(--ga4-soft)">(어제까지 · 직전 {{ $ga['days'] }}일과 비교)</span></span>
    @endif
</div>

{{-- 기간 선택 · 실시간 · 새로고침 --}}
<div class="ga4-toolbar">
    @foreach ($ga['presets'] as $p)
        <a class="ga4-btn {{ $ga['days'] === $p ? 'on' : '' }}" href="?days={{ $p }}">최근 {{ $p }}일</a>
    @endforeach
    <span class="sp"></span>
    @if ($ga['configured'] && empty($ga['error']))
        <span class="ga4-rt {{ ($ga['realtime']['activeUsers'] ?? 0) > 0 ? 'ga4-up' : 'ga4-flat' }}"><span class="ga4-dot"></span>지금 {{ F::int($ga['realtime']['activeUsers'] ?? 0) }}명 접속 중</span>
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

{{-- ① 개요 --}}
<div class="ga4-section">
    <div class="head"><h2>① 개요 — 핵심 지표</h2><span class="d">숫자 아래 화살표는 직전 같은 기간과 비교예요. 지표 옆 <i class="ga4-help">?</i> 에 마우스를 올리면 설명이 나와요.</span></div>
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
    <div class="ga4-section">
        <div class="head"><h2>② 방문 추이</h2><span class="d">날짜별 사용자 수 — 막대에 올리면 세부 숫자가 나와요</span></div>
        <div class="ga4-card">
            @php $mxT = max(1, (int) collect($ga['trend'])->max('users') ?: 1); @endphp
            <div class="ga4-chart">
                @foreach ($ga['trend'] as $t)
                    <div title="{{ $t['date'] }} · 사용자 {{ F::int($t['users']) }} · 세션 {{ F::int($t['sessions']) }} · 페이지뷰 {{ F::int($t['views']) }}" style="height:{{ max(2, round($t['users'] / $mxT * 100)) }}%"></div>
                @endforeach
            </div>
            <div class="ga4-axis"><span>{{ collect($ga['trend'])->first()['date'] ?? '' }}</span><span>{{ collect($ga['trend'])->last()['date'] ?? '' }}</span></div>
        </div>
    </div>
@endif

{{-- ③ 유입 --}}
<div class="ga4-section">
    <div class="head"><h2>③ 어디서 왔나 — 유입</h2><span class="d">방문자가 우리 사이트로 들어온 경로예요</span></div>
    <div class="ga4-card" style="max-width:760px;margin-bottom:12px;">
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
<div class="ga4-section">
    <div class="head"><h2>④ 어디로 들어왔나 — 랜딩 페이지</h2><span class="d">방문자가 <b>처음 도착한</b> 페이지. 이탈률이 높으면 그 페이지에서 바로 나간 사람이 많다는 뜻이에요</span></div>
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
<div class="ga4-section">
    <div class="head"><h2>⑤ 무엇을 봤나 — 인기 페이지</h2><span class="d">가장 많이 조회된 페이지와 평균 체류 시간</span></div>
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
<div class="ga4-section">
    <div class="head"><h2>⑥ 어디서 나갔나 — 이탈 많은 유입 페이지</h2><span class="d">들어오자마자 <b>참여 없이 바로 나간</b> 비율이 높은 랜딩 페이지예요. 개선하면 붙잡을 수 있어요</span></div>
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
<div class="ga4-section">
    <div class="head"><h2>⑦ 누가 · 어떻게 봤나</h2><span class="d">기기 · 신규/재방문 · 브라우저 · 지역</span></div>
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
            <div class="ga4-card-h" style="margin-top:18px;">지역 (도시)</div>
            @include('ga4-insights::partials.barlist', ['rows' => collect($ga['cities'])->take(8)->all(), 'key' => 'sessions', 'showPct' => true])
        </div>
    </div>
</div>

{{-- ⑧ 이벤트 --}}
<div class="ga4-section">
    <div class="head"><h2>⑧ 무슨 행동을 했나 — 이벤트</h2><span class="d">페이지 조회·스크롤·클릭 등 사이트에서 일어난 행동</span></div>
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
<div class="ga4-section">
    <div class="head"><h2>⑨ 언제 오나 — 시간대</h2><span class="d">하루 중 방문이 몰리는 시간(세션 기준)</span></div>
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
    <div class="ga4-section">
        <div class="head"><h2>🟢 지금 접속 중 — 실시간</h2><span class="d">현재 활성 사용자가 보고 있는 페이지</span></div>
        <div class="ga4-card" style="max-width:760px;">
            @include('ga4-insights::partials.barlist', ['rows' => $ga['realtime']['pages'], 'key' => 'users', 'showPct' => false])
        </div>
    </div>
@endif

@endif
</div>
@endsection
