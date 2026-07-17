@extends('admin.layout')
@section('page-title', '신규 개업')

@php
    $placeLabel = ['found' => '플레이스 있음', 'not_found' => '플레이스 미등록', 'pending' => '미확인'];
@endphp

@section('admin-content')
<x-console.page-head title="신규 개업" desc="지방행정 인허가 공공데이터의 <b>최근 인허가 업소</b>와 네이버 플레이스 등록 여부입니다 — 확인용 열람 화면" />

@if ($sampleKey)
    <div class="card-soft px-4 py-3 mb-4" style="font-size:var(--fs-xs);background:color-mix(in srgb,var(--color-warning) 10%,var(--color-canvas));">
        인증키가 <b>sample</b> 이라 일자당 5건만 수집됩니다 —
        <a href="{{ route('admin.settings', ['tab' => 'integ']) }}" class="text-ink"><b>환경 설정 &gt; 연동 &gt; 서울 열린데이터광장</b></a> 에 인증키를 넣으세요
        (<a href="https://data.seoul.go.kr/together/mypage/actkeyMain.do" target="_blank" rel="noopener" class="text-ink">data.seoul.go.kr 마이페이지</a> 에서 즉시 발급).
    </div>
@endif

{{-- 현황 + 실행 --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
    <div class="card p-5">
        <div class="text-ink font-semibold mb-2" style="font-size:var(--fs-sm);">현황 <span class="text-muted-soft" style="font-weight:400;">최근 {{ $days }}일</span></div>
        <div class="text-muted" style="font-size:var(--fs-xs);">영업 중 신규 <b class="font-mono text-ink">{{ number_format($total) }}</b>건</div>
        <div class="flex flex-wrap gap-1.5 mt-2">
            @foreach ($placeLabel as $k => $label)
                @isset($placeCounts[$k])
                    <a href="{{ request()->fullUrlWithQuery(['place' => $place === $k ? null : $k]) }}" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;{{ $place === $k ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">
                        {{ $label }} <b class="font-mono">{{ number_format($placeCounts[$k]) }}</b>
                    </a>
                @endisset
            @endforeach
        </div>
    </div>

    <div class="card p-5">
        <div class="text-ink font-semibold mb-2" style="font-size:var(--fs-sm);">지금 수집</div>
        {{-- 수집 → 플레이스 확인까지 한 흐름. 확인은 건수 제한 없이 대상이 0 이 될 때까지 이어 돈다(진행률 표시) --}}
        <form method="POST" action="{{ route('admin.new-businesses.collect') }}" class="flex items-end gap-2" data-nb-run>
            @csrf
            <div style="width:110px;">
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">최근 일수(≤7)</label>
                <input type="number" name="days" class="input" min="1" max="7" value="3">
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="height:40px;" data-loading="수집 중…">수집 실행</button>
        </form>
        <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">인허가일자 기준(D-2부터). 수집 후 플레이스 확인까지 이어서 진행합니다.</div>
    </div>

    <div class="card p-5">
        <div class="text-ink font-semibold mb-2" style="font-size:var(--fs-sm);">플레이스 확인</div>
        <div class="flex items-end gap-2">
            <form method="POST" action="{{ route('admin.new-businesses.place-match') }}" data-nb-run>
                @csrf
                <button type="submit" class="btn btn-primary btn-sm" style="height:40px;" data-loading="확인 중…" @disabled(! $needCheck)>
                    확인 실행@if ($needCheck) <span class="font-mono">({{ number_format($needCheck) }})</span>@endif
                </button>
            </form>
            {{-- 대상이 없어도 지금 다시 보고 싶을 때 — 재확인 주기를 무시하고 미등록 전부 재조회 --}}
            <form method="POST" action="{{ route('admin.new-businesses.place-match') }}" data-nb-run data-nb-force>
                @csrf
                <input type="hidden" name="force" value="1">
                <button type="submit" class="btn btn-secondary btn-sm" style="height:40px;" data-loading="재확인 중…" @disabled(! $recheckable)>
                    전체 재확인@if ($recheckable) <span class="font-mono">({{ number_format($recheckable) }})</span>@endif
                </button>
            </form>
        </div>
        <div class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">
            <b>확인 실행</b> — 미확인 + 재확인할 때가 된 미등록을 끝까지 전부(건수 제한 없음). 미등록은 {{ $recheckDays }}일마다 자동으로 다시 찾습니다.<br>
            <b>전체 재확인</b> — 주기를 기다리지 않고 <b>미등록 전부를 지금</b> 다시 확인합니다.
        </div>
    </div>
</div>

{{-- 진행률 — 실행 중에만 보인다 --}}
<div class="card p-4 mb-4" id="nb-progress" style="display:none;">
    <div class="flex items-center gap-3">
        <span id="nb-progress-spin" style="width:14px;height:14px;border:2px solid var(--color-primary);border-top-color:transparent;border-radius:50%;display:inline-block;animation:nbspin .7s linear infinite;"></span>
        <span class="text-ink font-semibold" id="nb-progress-text" style="font-size:var(--fs-xs);">준비 중…</span>
        <span class="text-muted-soft font-mono ml-auto" id="nb-progress-num" style="font-size:var(--fs-xs);"></span>
        {{-- 중단 — 진행 중인 배치는 마무리하고(결과 보존) 다음 배치부터 멈춘다 --}}
        <button type="button" id="nb-stop" class="btn btn-ghost btn-sm" style="height:30px;display:none;">중단</button>
    </div>
    <div style="height:6px;background:var(--color-surface);border-radius:var(--radius-pill);overflow:hidden;margin-top:10px;">
        <div id="nb-progress-bar" style="height:100%;width:0;background:var(--color-primary);border-radius:var(--radius-pill);transition:width .25s;"></div>
    </div>
</div>

{{-- 필터 — 한 줄(키워드 탐색과 동일 방식) --}}
<div class="card p-4 mb-4">
    <form method="GET" action="{{ route('admin.new-businesses') }}" id="nb-form" class="flex items-center gap-2" style="flex-wrap:nowrap;overflow-x:auto;">
        <span class="text-ink font-semibold flex-none" style="font-size:var(--fs-xs);">기간</span>
        <select name="days" class="input" style="height:36px;min-width:110px;" onchange="nbGo(this, [])">
            @foreach ([7 => '최근 7일', 14 => '최근 14일', 30 => '최근 30일', 90 => '최근 90일'] as $d => $label)
                <option value="{{ $d }}" @selected($days === $d)>{{ $label }}</option>
            @endforeach
        </select>

        <span class="text-ink font-semibold flex-none" style="font-size:var(--fs-xs);padding-left:8px;">지역</span>
        <select name="sido" class="input" style="height:36px;min-width:110px;" onchange="nbGo(this, ['sgg'])">
            <option value="">시/도</option>
            @foreach ($sidos as $s => $c)
                <option value="{{ $s }}" @selected($sido === (string) $s)>{{ $s }} ({{ number_format($c) }})</option>
            @endforeach
        </select>
        <span class="text-muted-soft">›</span>
        <select name="sgg" class="input" style="height:36px;min-width:130px;" onchange="nbGo(this, [])" @disabled($sido === '')>
            <option value="">시/군/구</option>
            @foreach ($sggs as $g => $c)
                <option value="{{ $g }}" @selected($sgg === (string) $g)>{{ $g }} ({{ number_format($c) }})</option>
            @endforeach
        </select>

        <span class="text-ink font-semibold flex-none" style="font-size:var(--fs-xs);padding-left:8px;">업종</span>
        <select name="svc" class="input" style="height:36px;min-width:130px;" onchange="nbGo(this, [])">
            <option value="">전체</option>
            @foreach ($services as $k => $label)
                <option value="{{ $k }}" @selected($svc === $k)>{{ $label }}</option>
            @endforeach
        </select>

        <span class="mx-1"></span>
        <input type="search" name="q" class="input flex-none" style="height:36px;width:180px;" placeholder="상호 검색" value="{{ $q }}">
        @if ($place !== '')<input type="hidden" name="place" value="{{ $place }}">@endif
        <button type="submit" class="btn btn-secondary btn-sm flex-none" style="height:36px;">검색</button>
        @if ($sido !== '' || $sgg !== '' || $svc !== '' || $q !== '' || $place !== '')
            <a href="{{ route('admin.new-businesses') }}" class="btn btn-ghost btn-sm flex-none" style="height:36px;">초기화</a>
        @endif
    </form>
</div>

{{-- 목록 --}}
<div class="card p-5">
    <div style="overflow-x:auto;">
        <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
            <thead>
                <tr class="text-muted-soft" style="text-align:left;border-bottom:1px solid var(--color-hairline);">
                    <th style="padding:8px 6px;">인허가일</th>
                    <th style="padding:8px 6px;">상호</th>
                    <th style="padding:8px 6px;">업종·업태</th>
                    <th style="padding:8px 6px;">지역</th>
                    <th style="padding:8px 6px;">주소</th>
                    <th style="padding:8px 6px;">전화</th>
                    <th style="padding:8px 6px;">플레이스</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $b)
                    <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                        <td style="padding:7px 6px;" class="font-mono text-muted">{{ $b->apv_perm_ymd?->format('m-d') ?? '—' }}</td>
                        <td style="padding:7px 6px;" class="text-ink font-semibold">{{ $b->bplc_nm }}</td>
                        <td style="padding:7px 6px;" class="text-muted">{{ $b->svc_label }}{{ $b->uptae_nm ? ' · '.$b->uptae_nm : '' }}</td>
                        <td style="padding:7px 6px;" class="text-muted">{{ trim(($b->sgg ?? '').' '.($b->emd ?? '')) ?: '—' }}</td>
                        <td style="padding:7px 6px;" class="text-muted-soft" title="{{ $b->road_addr ?: $b->site_addr }}">{{ \Illuminate\Support\Str::limit($b->road_addr ?: $b->site_addr, 28) ?: '—' }}</td>
                        <td style="padding:7px 6px;" class="font-mono text-muted">{{ $b->site_tel ?: '—' }}</td>
                        <td style="padding:7px 6px;">
                            @if ($b->place_status === 'found')
                                <a href="{{ $b->placeUrl() }}" target="_blank" rel="noopener" class="badge border border-hairline" style="font-size:var(--fs-xs);text-decoration:none;background:color-mix(in srgb,var(--color-success) 12%,var(--color-canvas));color:var(--color-success);" title="{{ $b->place_name }}{{ $b->place_cat ? ' · '.$b->place_cat : '' }}">플레이스 ↗</a>
                            @elseif ($b->place_status === 'not_found')
                                <a href="{{ $b->mapSearchUrl() }}" target="_blank" rel="noopener" class="text-muted-soft" style="font-size:var(--fs-xs);text-decoration:none;" title="지도에서 직접 확인">미등록 · 지도검색 ↗</a>
                            @else
                                <span class="text-muted-soft" style="font-size:var(--fs-xs);">미확인</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted-soft text-center" style="padding:40px;">
                        수집된 신규 개업이 없습니다. 위 '수집 실행'을 누르거나 <code>php artisan newbiz:collect</code> 를 실행하세요.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $items->links() }}</div>
</div>

<style>@keyframes nbspin { to { transform: rotate(360deg); } }</style>
<script>
    function nbGo(sel, clear) {
        var f = document.getElementById('nb-form');
        (clear || []).forEach(function (n) { var el = f.elements[n]; if (el) el.value = ''; });
        f.submit();
    }

    // 수집·플레이스 확인 — 건수 제한 없이 대상이 0 이 될 때까지 돌린다.
    // 한 요청에서 전부 처리하면 게이트웨이 타임아웃이라, 서버는 배치만 처리하고 남은 수를 돌려주고
    // 여기서 0 이 될 때까지 이어 부르며 진행률을 보여준다.
    (function () {
        var box = document.getElementById('nb-progress');
        var txt = document.getElementById('nb-progress-text');
        var num = document.getElementById('nb-progress-num');
        var bar = document.getElementById('nb-progress-bar');
        var spin = document.getElementById('nb-progress-spin');
        var stopBtn = document.getElementById('nb-stop');
        var busy = false, stopped = false;

        // 중단은 **진행 중인 배치를 끝낸 뒤** 다음 배치부터 멈춘다 — 이미 네이버에 물어본 건의 결과를 버리지 않는다
        stopBtn.addEventListener('click', function () {
            stopped = true;
            stopBtn.disabled = true;
            stopBtn.textContent = '중단 중…';
        });

        function lock(on, btn) {
            document.querySelectorAll('form[data-nb-run] button[type=submit]').forEach(function (b) {
                b.style.pointerEvents = on ? 'none' : '';
                b.style.opacity = on ? (b === btn ? '.75' : '.5') : '';
            });
            if (btn) {
                if (on) {
                    btn.dataset.label = btn.innerHTML;
                    btn.innerHTML = '<span style="width:13px;height:13px;border:2px solid currentColor;border-top-color:transparent;'
                        + 'border-radius:50%;display:inline-block;vertical-align:-2px;margin-right:6px;animation:nbspin .7s linear infinite;"></span>'
                        + (btn.dataset.loading || '처리 중…');
                } else if (btn.dataset.label) {
                    btn.innerHTML = btn.dataset.label;
                }
            }
        }

        function show(text, done, total) {
            box.style.display = '';
            txt.textContent = text;
            num.textContent = total ? done + ' / ' + total : '';
            bar.style.width = total ? Math.round(done / total * 100) + '%' : '0';
        }

        function post(url, body) {
            return fetch(url, {
                method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            }).then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            });
        }

        // 대상이 0 이 될 때까지 배치 반복. force 면 재확인 주기를 무시하고 미등록 전부를 본다
        // (since = 서버가 잡아준 실행 시작 시각 — 이후 배치에 그대로 실어 보내야 방금 본 건이 다시 안 잡힌다)
        function matchAll(url, token, total, force) {
            var found = 0, notFound = 0, done = 0, since = null;
            function step() {
                var fd = new FormData();
                fd.append('_token', token);
                if (force) {
                    fd.append('force', '1');
                    if (since) { fd.append('since', since); }
                }
                return post(url, fd).then(function (r) {
                    since = since || r.since;
                    found += r.found; notFound += r.not_found; done += r.done;
                    total = Math.max(total, done + r.remaining);
                    show((stopped ? '중단하는 중… ' : (force ? '전체 재확인 중… ' : '플레이스 확인 중… '))
                        + '있음 ' + found + ' · 미등록 ' + notFound, done, total);
                    if (stopped || r.done === 0 || r.remaining === 0) {
                        return { found: found, not_found: notFound, done: done, stopped: stopped, remaining: r.remaining };
                    }
                    return step();   // 남은 게 있으면 계속
                });
            }
            return step();
        }

        document.querySelectorAll('form[data-nb-run]').forEach(function (f) {
            f.addEventListener('submit', function (e) {
                e.preventDefault();
                if (busy) { return; }
                busy = true;
                stopped = false;
                stopBtn.disabled = false;
                stopBtn.textContent = '중단';
                stopBtn.style.display = '';
                var btn = f.querySelector('button[type=submit]');
                var token = f.querySelector('input[name=_token]').value;
                var isCollect = f.action.indexOf('collect') !== -1;
                var force = f.hasAttribute('data-nb-force');
                lock(true, btn);
                show(isCollect ? 'API 수집 중… (인허가 데이터를 받는 중)'
                    : (force ? '전체 재확인 준비 중…' : '플레이스 확인 준비 중…'), 0, 0);

                var first = isCollect ? post(f.action, new FormData(f)) : Promise.resolve(null);
                first.then(function (c) {
                    var total = c ? c.remaining : (force ? {{ $recheckable }} : {{ $needCheck }});
                    // 수집 중에 중단을 눌렀으면 이어지는 플레이스 확인은 시작하지 않는다(수집분은 이미 저장됨)
                    if (c && stopped) { return { found: 0, not_found: 0, done: 0, stopped: true, remaining: total }; }
                    if (c) { show('수집 완료 — 신규 ' + c.created + ' · 갱신 ' + c.updated + ' → 플레이스 확인 시작', 0, total); }
                    return matchAll('{{ route('admin.new-businesses.place-match') }}', token, total, force);
                }).then(function (m) {
                    var c = null;
                    return { m: m, c: c };
                }).catch(function (err) {
                    busy = false;
                    lock(false, btn);
                    stopBtn.style.display = 'none';
                    spin.style.display = 'none';
                    show('실패 — ' + err.message + ' (다시 시도해 주세요)', 0, 0);
                });
            });
        });

        // 새로고침 뒤 결과 표시
        var flash = sessionStorage.getItem('nb-flash');
        if (flash) {
            sessionStorage.removeItem('nb-flash');
            spin.style.display = 'none';
            show('완료 — ' + flash, 1, 1);
            bar.style.background = 'var(--color-success)';
        }
    })();
</script>
@endsection
