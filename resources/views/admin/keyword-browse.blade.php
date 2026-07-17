@extends('admin.layout')
@section('page-title', '키워드 탐색')

@php
    $stLabel = ['pending' => '대기', 'approved' => '승인', 'rejected' => '보류', 'published' => '발행됨'];
    $srcLabel = ['seed' => '시드', 'related' => '연관', 'autocomplete' => '자동완성', 'user' => '사용자', 'gsc' => '검색유입', 'datalab' => '데이터랩', 'combo' => '지역조합'];
@endphp

@section('admin-content')
<x-console.page-head title="키워드 탐색" desc="수집된 키워드를 분야별로 봅니다 — 상위를 고르면 하위 분류가 채워지고, 고르지 않으면 전체 키워드가 나옵니다. 승인·발행은 키워드 콘텐츠 허브에서." />

{{-- 타입 --}}
<div class="flex items-center gap-2 mb-4">
    @foreach (['shopping' => '쇼핑', 'place' => '플레이스'] as $t => $label)
        <a href="{{ route('admin.keyword-browse', ['type' => $t]) }}" class="btn {{ $type === $t ? 'btn-primary' : 'btn-secondary' }} btn-sm">{{ $label }}</a>
    @endforeach
    <span class="text-muted ml-auto" style="font-size:var(--fs-xs);">
        @if (($refreshed ?? 0) > 0)
            <span class="badge border border-hairline" title="검색량은 {{ $volumeTtlDays ?? 7 }}일마다 자동 갱신됩니다">검색량 {{ $refreshed }}건 갱신됨</span>
        @endif
        <b class="font-mono text-ink">{{ number_format($total) }}</b>개
        @foreach ($stLabel as $k => $label)
            @isset($statusCounts[$k])<span class="text-muted-soft"> · {{ $label }} <b class="font-mono">{{ number_format($statusCounts[$k]) }}</b></span>@endisset
        @endforeach
    </span>
</div>

{{-- 분야 셀렉트 — 항상 3단 고정 표기. 상위 선택 시 하위 option 이 채워진다(미선택이면 비활성). --}}
<div class="card p-4 mb-4">
    {{-- 한 줄 고정 — 좁으면 가로 스크롤(줄바꿈 금지) --}}
    <form method="GET" action="{{ route('admin.keyword-browse') }}" id="kb-form" class="flex items-center gap-2" style="flex-wrap:nowrap;overflow-x:auto;">
        <input type="hidden" name="type" value="{{ $type }}">
        <span class="text-ink font-semibold flex-none" style="font-size:var(--fs-xs);">분야</span>

        {{-- 1단: 쇼핑=1분류 / 플레이스=업종 --}}
        <select name="c1" class="input" style="height:36px;min-width:150px;" onchange="kbGo(this, ['c2','c3','sido','sgg','rg'])">
            <option value="">{{ $type === 'shopping' ? '1분류' : '업종' }}</option>
            @foreach ($lv1 as $c)
                <option value="{{ $c->id }}" @selected($c1 === $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        <span class="text-muted-soft">›</span>

        @if ($type === 'shopping')
            {{-- 2단: 2분류 --}}
            <select name="c2" class="input" style="height:36px;min-width:150px;" onchange="kbGo(this, ['c3'])" @disabled($lv2->isEmpty())>
                <option value="">2분류</option>
                @foreach ($lv2 as $c)
                    <option value="{{ $c->id }}" @selected($c2 === $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
            <span class="text-muted-soft">›</span>
            {{-- 3단: 3분류 --}}
            <select name="c3" class="input" style="height:36px;min-width:150px;" onchange="kbGo(this, [])" @disabled($lv3->isEmpty())>
                <option value="">3분류</option>
                @foreach ($lv3 as $c)
                    <option value="{{ $c->id }}" @selected($c3 === $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        @else
            {{-- 플레이스 지역 3단 — 공개 /keywords/place 와 동일 계층(시/도 › 시/군/구 › 지역) --}}
            <select name="sido" class="input" style="height:36px;min-width:130px;" onchange="kbGo(this, ['sgg','rg'])">
                <option value="">시/도</option>
                @foreach ($sidos as $s => $cnt)
                    <option value="{{ $s }}" @selected($sido === (string) $s)>{{ $s }} ({{ number_format($cnt) }})</option>
                @endforeach
            </select>
            <span class="text-muted-soft">›</span>
            <select name="sgg" class="input" style="height:36px;min-width:150px;" onchange="kbGo(this, ['rg'])" @disabled(empty($sggs))>
                <option value="">시/군/구</option>
                @foreach ($sggs as $g => $cnt)
                    <option value="{{ $g }}" @selected($sgg === (string) $g)>{{ $g }} ({{ number_format($cnt) }})</option>
                @endforeach
            </select>
            <span class="text-muted-soft">›</span>
            <select name="rg" class="input" style="height:36px;min-width:170px;" onchange="kbGo(this, [])" @disabled(empty($regions))>
                <option value="">지역{{ $regions ? ' ('.number_format(count($regions)).'곳)' : '' }}</option>
                @foreach ($regions as $r => $cnt)
                    <option value="{{ $r }}" @selected($rg === (string) $r)>{{ $r }} ({{ number_format($cnt) }})</option>
                @endforeach
            </select>
        @endif

        <span class="mx-1"></span>
        {{-- 분류당 수천 개라(실측 패션잡화 9,588) 수집 상태로 좁혀 본다 --}}
        <select name="collected" class="input" style="height:36px;width:120px;" onchange="kbGo(this, [])" title="수집 상태">
            <option value="">수집 전체</option>
            <option value="n" @selected(($collected ?? '') === 'n')>미수집만</option>
            <option value="y" @selected(($collected ?? '') === 'y')>수집됨만</option>
        </select>
        <input type="search" name="q" class="input" style="height:36px;width:200px;" placeholder="키워드 검색" value="{{ $q }}">
        <button type="submit" class="btn btn-secondary btn-sm" style="height:36px;">검색</button>
        @if ($c1 || $c2 || $c3 || $sido !== '' || $sgg !== '' || $rg !== '' || $q !== '')
            <a href="{{ route('admin.keyword-browse', ['type' => $type]) }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>
        @endif
    </form>
</div>

{{-- 쇼핑 대량 자동 수집 — 서버는 418 이라 확장이 대기열을 받아 연속 수집한다 --}}
@if ($type === 'shopping')
<div class="card p-4 mb-4">
    <div class="flex items-center gap-2 flex-wrap">
        <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">상품 대량 수집</span>
        {{-- 수량 대신 '분류 순서대로 끝까지' — 멈춰도 미수집이 남은 첫 분류부터 이어서 시작된다 --}}
        <select id="rf-bulk-limit" class="input" style="height:32px;width:150px;font-size:var(--fs-xs);" title="분류 순서(1차→2차→3차)대로 수집합니다">
            <option value="0" selected>전체(분류 순서대로)</option>
            <option value="100">100개만</option>
            <option value="500">500개만</option>
        </select>
        <select id="rf-bulk-gap" class="input" style="height:32px;width:150px;font-size:var(--fs-xs);" title="키워드 간 간격 — 너무 빠르면 네이버가 일시 차단합니다">
            <option value="6000" selected>간격 6초 (권장)</option>
            <option value="10000">간격 10초 (안전)</option>
            <option value="4000">간격 4초 (빠름·차단 위험)</option>
        </select>
        <select id="rf-bulk-conc" class="input" style="height:32px;width:150px;font-size:var(--fs-xs);" title="동시에 돌아가는 탭 수 — 탭은 순서대로 열고 동시 수만 유지합니다">
            <option value="1">동시 1개</option>
            <option value="2" selected>동시 2개</option>
            <option value="3">동시 3개</option>
            <option value="4">동시 4개</option>
            <option value="6">동시 6개 (차단 위험)</option>
        </select>
        <button type="button" id="rf-bulk-start" class="btn btn-primary btn-sm">수집 시작</button>
        <button type="button" id="rf-bulk-stop" class="btn btn-ghost btn-sm" hidden>중단</button>
        <span id="rf-bulk-msg" class="text-muted" style="font-size:var(--fs-xs);">확장이 미수집 키워드를 검색량 순으로 자동 수집합니다(브라우저를 켜둔 채로).</span>
    </div>
</div>
<script>
(function () {
    var start = document.getElementById('rf-bulk-start'), stop = document.getElementById('rf-bulk-stop'), msg = document.getElementById('rf-bulk-msg');
    if (!start) return;
    var poll = null;
    function send(type, extra) { window.postMessage(Object.assign({ source: 'rankfree-admin', type: type }, extra || {}), '*'); }
    function hasExt() { return document.documentElement.getAttribute('data-rf-ext') === '1'; }

    window.addEventListener('message', function (e) {
        var m = e.data;
        if (!m || m.source !== 'rankfree-ext') return;
        if (m.type === 'bulkStartResult') {
            if (!m.ok) { msg.style.color = 'var(--color-error)'; msg.textContent = m.message || '시작 실패'; start.disabled = false; return; }
            msg.style.color = ''; stop.hidden = false; start.disabled = true;
            poll = setInterval(function () { send('bulkStatus'); }, 1500);
        }
        if (m.type === 'bulkStatusResult' && m.bulk) {
            var b = m.bulk;
            var waiting = b.blockedUntil && b.blockedUntil > Date.now();
            var head = !b.running ? '수집 종료 — ' : (waiting ? '차단 감지 — 대기 중… ' : '수집 중… ');
            msg.textContent = head + '성공 ' + (b.done || 0) + ' · 실패 ' + (b.failed || 0)
                + (b.category ? ' · 분류: ' + b.category + (b.categoryTotal ? ' (' + b.categoryIndex + '/' + b.categoryTotal + ')' : '') : '')
                + (b.running && b.current ? ' · 현재: ' + b.current : '')
                + (b.running && b.gap ? ' · 간격 ' + Math.round(b.gap / 1000) + '초' : '')
                + (b.remaining ? ' · 남은 ' + Number(b.remaining).toLocaleString() : '');
            if (b.failed > 0 && b.lastError) {
                msg.textContent += ' · 사유: ' + b.lastError;
                msg.style.color = waiting ? '' : 'var(--color-error)';
            }
            if (!b.running) {
                clearInterval(poll); poll = null; stop.hidden = true; start.disabled = false;
                msg.textContent += ' — 다시 시작하면 남은 분류부터 이어집니다';
            }
        }
        if (m.type === 'bulkStopResult') { msg.textContent = '중단 요청됨 — 현재 키워드까지 마치고 멈춥니다.'; }
    });

    start.addEventListener('click', function () {
        if (!hasExt()) { msg.style.color = 'var(--color-error)'; msg.textContent = '확장이 설치돼 있지 않습니다(v0.1.8 이상, 로그인 필요).'; return; }
        start.disabled = true; msg.style.color = ''; msg.textContent = '시작하는 중…';
        send('bulkStart', {
            limit: Number(document.getElementById('rf-bulk-limit').value),
            delayMs: Number(document.getElementById('rf-bulk-gap').value),
            concurrency: Number(document.getElementById('rf-bulk-conc').value),
        });
    });
    stop.addEventListener('click', function () { send('bulkStop'); });
    if (hasExt()) send('bulkStatus');   // 진행 중이면 이어서 표시
})();
</script>
@endif

{{-- 키워드 목록 --}}
<div class="card p-5">
    <div style="overflow-x:auto;">
        <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
            <thead>
                <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                    <th style="padding:8px 6px;">키워드</th>
                    <th style="padding:8px 6px;">분류</th>
                    @if ($type === 'place')<th style="padding:8px 6px;">지역</th>@endif
                    <th style="padding:8px 6px;">출처</th>
                    <th style="padding:8px 6px;text-align:right;">월 검색량</th>
                    <th style="padding:8px 6px;" title="검색량을 마지막으로 조회한 시각(주 1회 자동)">검색량 갱신</th>
                    <th style="padding:8px 6px;" title="{{ $type === 'place' ? '업체' : '상품' }} 목록을 마지막으로 수집한 시각">{{ $type === 'place' ? '업체' : '상품' }} 수집일</th>
                    <th style="padding:8px 6px;">상태</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $it)
                    <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                        <td style="padding:7px 6px;">
                            <a href="{{ route('admin.keyword-browse.detail', ['keyword' => $it->keyword]) }}"
                               class="text-ink font-semibold" style="text-decoration:none;" title="이 키워드로 노출되는 업체 보기">{{ $it->keyword }}</a>
                        </td>
                        {{-- 같은 키워드가 여러 분류에 있으면 대표 1개 + 나머지 개수 --}}
                        @php $__cc = (int) ($catCnt[$it->keyword] ?? 1); @endphp
                        <td style="padding:7px 6px;" class="text-muted">
                            {{ $it->category?->name ?? '—' }}
                            @if ($__cc > 1)
                                <span class="text-muted-soft" title="이 키워드는 {{ $__cc }}개 분류에 있습니다(수집은 1회만)">+{{ $__cc - 1 }}</span>
                            @endif
                        </td>
                        @if ($type === 'place')
                            <td style="padding:7px 6px;" class="text-muted">{{ $it->region ?: '—' }}</td>
                        @endif
                        <td style="padding:7px 6px;"><span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $srcLabel[$it->source] ?? $it->source }}</span></td>
                        <td style="padding:7px 6px;text-align:right;" class="font-mono">{{ $it->monthly_total === null ? '미상' : number_format($it->monthly_total) }}</td>
                        <td style="padding:7px 6px;" class="text-muted-soft" title="{{ $it->volume_checked_at?->format('Y-m-d H:i') ?? '조회 전' }}">
                            {{ $it->volume_checked_at ? $it->volume_checked_at->diffForHumans() : '—' }}
                        </td>
                        {{-- 업체·상품 수집일 — 수집한 키워드만 값이 있고, 클릭하면 상세로 --}}
                        @php $__sat = $serpAt[$it->keyword] ?? null; @endphp
                        <td style="padding:7px 6px;">
                            @if ($__sat)
                                @php $__c = \Carbon\Carbon::parse($__sat); $__n = (int) ($serpCnt[$it->keyword] ?? 0); @endphp
                                <a href="{{ route('admin.keyword-browse.detail', ['keyword' => $it->keyword]) }}"
                                   class="font-mono" style="text-decoration:none;color:{{ $__c->lt(now()->subDays(30)) ? 'var(--color-error)' : 'var(--color-primary)' }};"
                                   title="{{ $__c->format('Y-m-d H:i') }} 수집 · {{ $type === 'place' ? '업체' : '상품' }} {{ number_format($__n) }}개{{ $__c->lt(now()->subDays(30)) ? ' · 30일 지남' : '' }}">
                                    {{ $__c->format('m-d') }}
                                    <span class="text-muted-soft">({{ number_format($__n) }})</span>
                                </a>
                            @else
                                <span class="text-muted-soft">미수집</span>
                            @endif
                        </td>
                        <td style="padding:7px 6px;" class="text-muted">{{ $stLabel[$it->status] ?? $it->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $type === 'place' ? 8 : 7 }}" class="text-muted-soft text-center" style="padding:40px;">키워드가 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $items->links() }}</div>
</div>

<script>
    // 상위 분류를 바꾸면 하위 선택은 비우고 다시 조회(데이터랩과 동일한 연동)
    function kbGo(sel, clear) {
        var f = document.getElementById('kb-form');
        (clear || []).forEach(function (n) { var el = f.elements[n]; if (el) el.value = ''; });
        f.submit();
    }
</script>
@endsection
