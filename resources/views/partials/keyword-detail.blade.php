{{--
    공유 키워드 상세 모듈 — 성별·연령·성별×연령·12개월 트렌드·월별 계절성.
    **키워드 분석(console.keyword) + 시장 분석(console.market-show) 양쪽 재사용.**
    입력: $d = KeywordAnalysisPresenter::detailModel($detail). (연관 키워드는 미포함 — 쇼핑 미사용)
    옵션: $emptyNote — 상세 없음 안내 문구.
    12개월=선그래프(호버 툴팁·y축 최소값 시작·좌우 가득), 성별/연령/성별×연령=세로 막대.
--}}
@php
    $uid = 'kw'.substr(md5(uniqid('', true)), 0, 8);
    // $only: 렌더할 섹션 화이트리스트(insights·trend·genderage·device·month). null=전부. 키워드 뷰가 카드를 개별 배치.
    $only = $only ?? null;
    $show = fn ($k) => $only === null || in_array($k, (array) $only, true);
    // $weekday: 요일별 검색 비율(데이터랩) — [['w'=>,'pct'=>], …]. 키워드/시장 분석 양쪽에서 주입.
    $weekday = $weekday ?? null;
    $mlabel = fn ($l) => ltrim(substr((string) $l, 5), '0').'월';
    $emptyNote = $emptyNote ?? '성별·연령·월별 트렌드 데이터가 없습니다.';
    $trend = array_values($d['trend'] ?? []);
    $peak = null;
    foreach ($trend as $t) { if ($peak === null || $t['total'] > $peak['total']) $peak = $t; }
    $age = $d['age'] ?? [];
    $pyr = $d['pyramid'] ?? [];
    $pSum = 0;
    foreach ($pyr as $p) { $pSum += $p['male'] + $p['female']; }
    $pSum = max(1, $pSum);
    $mr = $d['month_ratio'] ?? [];

    // 색(디자인 토큰)
    $cMo = 'var(--color-badge-emerald)';                                        // 모바일 민트
    $cPc = 'color-mix(in srgb, var(--color-accent) 42%, var(--color-canvas))';  // PC 연파랑
    $cFemale = 'var(--color-badge-pink)';
    $cMale = 'var(--color-accent)';
    $cAge = 'var(--color-accent)';
    $cMonth = 'var(--color-badge-violet)';

    // 12개월 선그래프 y축(최소값 근처부터). 선은 PC/모바일 2개.
    $vals = [];
    foreach ($trend as $t) { $vals[] = (int) $t['pc']; $vals[] = (int) $t['mobile']; }
    $dmin = $vals ? min($vals) : 0;
    $dmax = $vals ? max($vals) : 1;
    $span = max(1, $dmax - $dmin);
    $mag = pow(10, floor(log10($span)));
    $niceStep = $mag * (($span / $mag) >= 5 ? 2 : (($span / $mag) >= 2 ? 1 : 0.5));
    $gridMin = max(0, floor($dmin / $niceStep) * $niceStep);
    $gridMax = ceil($dmax / $niceStep) * $niceStep;
    if ($gridMax <= $gridMin) { $gridMax = $gridMin + $niceStep; }
    $ySteps = (int) round(($gridMax - $gridMin) / $niceStep);
    $ySteps = max(2, min(6, $ySteps));
    $kfmt = fn ($v) => $v >= 1000 ? rtrim(rtrim(number_format($v / 1000, $v < 10000 ? 1 : 0), '0'), '.').'k' : (string) (int) $v;
    $VW = 1000; $VH = 200;
    $nT = count($trend);
    $sx = fn ($i) => $nT > 1 ? round($i / ($nT - 1) * $VW, 1) : 0;
    $sy = fn ($v) => round((1 - ((int) $v - $gridMin) / max(1, $gridMax - $gridMin)) * $VH, 1);
    $linePts = function ($key) use ($trend, $sx, $sy) {
        $p = [];
        foreach ($trend as $i => $t) { $p[] = $sx($i).','.$sy($t[$key] ?? 0); }
        return implode(' ', $p);
    };
@endphp

@if (empty($d['has_detail']))
    @if ($only === null)
        <div class="card-soft mb-6 px-4 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $emptyNote }}</div>
    @endif
@else
    {{-- 데이터 기반 인사이트 — 시즌 분석 · 타겟 분석 2개 카드(각 3개) + 요약. 키워드/시장 분석 공용 --}}
    @if ($show('insights') && ! empty($d['insights']))
        @php
            $ins = $d['insights'];
            $insSeason = array_values(array_filter($ins['cards'], fn ($c) => ($c['group'] ?? '') === 'season'));
            $insTarget = array_values(array_filter($ins['cards'], fn ($c) => ($c['group'] ?? '') === 'target'));
        @endphp
        <div class="flex items-center gap-2 mb-3">
            <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">키워드 인사이트</span>
            <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">데이터 기반 요약</span>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            @foreach ([['시즌 분석', $insSeason, 'var(--color-accent)', 'var(--color-badge-violet)', 'dots'], ['타겟 분석', $insTarget, 'var(--color-accent)', 'var(--color-badge-violet)', 'dots']] as [$gLabel, $gCards, $gc1, $gc2, $gPat])
                @if (count($gCards))
                    {{-- 홈페이지 스타일 — 시즌·타겟 동일(그라데이션 accent→violet + 도트) --}}
                    <div class="card p-5 relative overflow-hidden">
                        <x-card-bg pattern="gradient" color="{{ $gc1 }}" color2="{{ $gc2 }}" opacity="{{ $gPat === 'rings' ? '0.18' : '0.22' }}" />
                        <x-card-bg pattern="{{ $gPat }}" color="{{ $gc1 }}" opacity="{{ $gPat === 'rings' ? '0.3' : '0.28' }}" />
                        <div class="relative">
                            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">{{ $gLabel }}</div>
                            <div class="grid grid-cols-3 gap-3">
                                @foreach ($gCards as $c)
                                    <div class="card p-4 text-center" style="background:color-mix(in srgb, var(--color-canvas) 78%, transparent);">
                                        <div class="text-body" style="font-size:var(--fs-xs);font-weight:600;">{{ $c['label'] }}</div>
                                        <div class="font-display mt-1" style="font-size:var(--fs-base);color:var(--color-ink);line-height:1.3;">{{ $c['value'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
        {{-- 요약 — 눈에 띄게(아이콘 칩 + 그라데이션, 좌측 바 금지) --}}
        <div class="card p-5 mb-6 relative overflow-hidden">
            <x-card-bg pattern="gradient" color="var(--color-accent)" color2="var(--color-badge-violet)" opacity="0.18" />
            <div class="relative flex items-start gap-3">
                <span class="w-9 h-9 rounded-lg flex items-center justify-center flex-none" style="background:color-mix(in srgb, var(--color-accent) 15%, transparent);font-size:16px;">💡</span>
                <p class="text-ink" style="font-size:var(--fs-base);line-height:1.75;font-weight:600;">{{ $ins['summary'] }}</p>
            </div>
        </div>
    @endif

    {{-- 12개월 검색량 추이 — 선그래프(PC·모바일) + 호버 툴팁 --}}
    @if ($show('trend') && count($trend) >= 2)
        <div class="card p-5 mb-6" id="{{ $uid }}">
            <div class="flex items-center gap-2 flex-wrap mb-3">
                <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">월별 검색수 추이</span>
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">최근 {{ count($trend) }}개월</span>
                {{-- 그래프/표 탭 --}}
                <div class="ml-auto inline-flex rounded-lg border border-hairline overflow-hidden">
                    <button type="button" class="kw-tab" data-view="graph" style="padding:5px 13px;font-size:var(--fs-xs);border:0;cursor:pointer;background:var(--color-ink);color:#fff;">그래프</button>
                    <button type="button" class="kw-tab" data-view="table" style="padding:5px 13px;font-size:var(--fs-xs);border:0;cursor:pointer;background:transparent;color:var(--color-muted);">표</button>
                </div>
            </div>

            {{-- 그래프 뷰 --}}
            <div class="kw-view" data-view="graph">
            {{-- 범례 --}}
            <div class="flex items-center gap-2 justify-end mb-2">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-hairline" style="padding:4px 11px;font-size:var(--fs-xs);">
                    <i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $cPc }};"></i>desktop
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-hairline" style="padding:4px 11px;font-size:var(--fs-xs);">
                    <i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $cMo }};"></i>mobile
                </span>
            </div>
            {{-- 차트: y라벨(HTML) + SVG 선(가로 가득) + hover 오버레이 --}}
            <div style="position:relative;padding-left:46px;">
                {{-- y축 라벨 --}}
                <div style="position:absolute;left:0;top:0;width:42px;height:230px;">
                    @for ($g = $ySteps; $g >= 0; $g--)
                        @php $gv = $gridMin + ($gridMax - $gridMin) * $g / $ySteps; $topPct = (1 - $g / $ySteps) * 100; @endphp
                        <span class="text-muted-soft" style="position:absolute;right:8px;top:calc({{ $topPct }}% - 7px);font-size:var(--fs-xs);">{{ $kfmt($gv) }}</span>
                    @endfor
                </div>
                {{-- 플롯 영역 --}}
                <div style="position:relative;height:230px;" class="kw-plot">
                    <svg viewBox="0 0 {{ $VW }} {{ $VH }}" preserveAspectRatio="none" style="width:100%;height:100%;display:block;overflow:visible;">
                        @for ($g = 0; $g <= $ySteps; $g++)
                            @php $gy = round($g / $ySteps * $VH, 1); @endphp
                            <line x1="0" x2="{{ $VW }}" y1="{{ $gy }}" y2="{{ $gy }}" stroke="var(--color-hairline-soft)" stroke-width="1" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/>
                        @endfor
                        <polyline fill="none" stroke="{{ $cPc }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" points="{{ $linePts('pc') }}"/>
                        <polyline fill="none" stroke="{{ $cMo }}" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" points="{{ $linePts('mobile') }}"/>
                    </svg>
                    {{-- 세로 안내선(호버) --}}
                    <div class="kw-vline" style="position:absolute;top:0;bottom:0;width:1px;background:var(--color-hairline);display:none;"></div>
                    {{-- 포인트 마커(모바일=진한, PC=연한) — % 위치로 배치 --}}
                    @foreach ($trend as $i => $t)
                        @php $xp = $nT > 1 ? $i / ($nT - 1) * 100 : 0; @endphp
                        <span style="position:absolute;left:calc({{ $xp }}% - 3px);top:calc({{ round($sy($t['pc'] ?? 0) / $VH * 100, 2) }}% - 3px);width:6px;height:6px;border-radius:50%;background:{{ $cPc }};"></span>
                        <span style="position:absolute;left:calc({{ $xp }}% - 4px);top:calc({{ round($sy($t['mobile'] ?? 0) / $VH * 100, 2) }}% - 4px);width:8px;height:8px;border-radius:50%;background:{{ $cMo }};"></span>
                    @endforeach
                    {{-- hover 영역(월별) --}}
                    <div style="position:absolute;inset:0;display:flex;">
                        @foreach ($trend as $i => $t)
                            <div class="kw-hover" style="flex:1;height:100%;" data-i="{{ $i }}"
                                 data-label="{{ $mlabel($t['label']) }}" data-full="{{ $t['label'] }}"
                                 data-pc="{{ number_format($t['pc'] ?? 0) }}" data-mo="{{ number_format($t['mobile'] ?? 0) }}"
                                 data-x="{{ $nT > 1 ? $i / ($nT - 1) * 100 : 0 }}"></div>
                        @endforeach
                    </div>
                    {{-- 툴팁 --}}
                    <div class="kw-tip" style="position:absolute;display:none;pointer-events:none;background:var(--color-surface-dark);color:#fff;border-radius:8px;padding:8px 11px;font-size:var(--fs-xs);white-space:nowrap;transform:translateX(-50%);z-index:5;box-shadow:var(--shadow-card);"></div>
                </div>
                {{-- x축 라벨 --}}
                <div style="display:flex;margin-top:6px;">
                    @foreach ($trend as $t)
                        <span class="text-muted-soft text-center" style="flex:1;font-size:var(--fs-xs);">{{ $mlabel($t['label']) }}</span>
                    @endforeach
                </div>
            </div>
            </div>{{-- /그래프 뷰 --}}

            {{-- 표 뷰 --}}
            <div class="kw-view" data-view="table" style="display:none;">
            <div style="overflow-x:auto;margin-top:4px;">
                <table class="w-full" style="min-width:680px;border-collapse:collapse;white-space:nowrap;">
                    <thead>
                        <tr class="text-muted" style="font-size:var(--fs-xs);">
                            <th class="text-left" style="padding:7px 8px;position:sticky;left:0;background:var(--color-canvas);">구분</th>
                            @foreach ($trend as $t)<th class="text-right" style="padding:7px 8px;">{{ $mlabel($t['label']) }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody style="font-size:var(--fs-xs);">
                        <tr style="border-top:1px solid var(--color-hairline-soft);">
                            <td style="padding:7px 8px;position:sticky;left:0;background:var(--color-canvas);color:{{ $cMale }};font-weight:600;">PC</td>
                            @foreach ($trend as $t)<td class="text-right text-body" style="padding:7px 8px;">{{ number_format($t['pc'] ?? 0) }}</td>@endforeach
                        </tr>
                        <tr style="border-top:1px solid var(--color-hairline-soft);">
                            <td style="padding:7px 8px;position:sticky;left:0;background:var(--color-canvas);color:{{ $cMo }};font-weight:600;">모바일</td>
                            @foreach ($trend as $t)<td class="text-right text-body" style="padding:7px 8px;">{{ number_format($t['mobile'] ?? 0) }}</td>@endforeach
                        </tr>
                        <tr style="border-top:1px solid var(--color-hairline-soft);">
                            <td class="text-ink font-semibold" style="padding:7px 8px;position:sticky;left:0;background:var(--color-canvas);">합계</td>
                            @foreach ($trend as $t)<td class="text-right text-ink font-semibold" style="padding:7px 8px;">{{ number_format($t['total']) }}</td>@endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
            </div>{{-- /표 뷰 --}}

            @if ($peak)
                <p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">* 검색량이 가장 높은 달은 <b class="text-ink">{{ $mlabel($peak['label']) }}</b>({{ number_format($peak['total']) }})입니다. 피크 1~2개월 전부터 콘텐츠·광고를 준비하세요.</p>
            @endif
        </div>

        <script>
        (function () {
            var card = document.getElementById(@json($uid));
            if (!card) return;
            var plot = card.querySelector('.kw-plot');
            var tip = card.querySelector('.kw-tip');
            var vline = card.querySelector('.kw-vline');
            card.querySelectorAll('.kw-hover').forEach(function (h) {
                h.addEventListener('mouseenter', show);
                h.addEventListener('mousemove', show);
                h.addEventListener('mouseleave', hide);
            });
            // 그래프 / 표 탭 전환
            card.querySelectorAll('.kw-tab').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var v = btn.dataset.view;
                    card.querySelectorAll('.kw-tab').forEach(function (b) {
                        var on = b.dataset.view === v;
                        b.style.background = on ? 'var(--color-ink)' : 'transparent';
                        b.style.color = on ? '#fff' : 'var(--color-muted)';
                    });
                    card.querySelectorAll('.kw-view').forEach(function (el) {
                        el.style.display = el.dataset.view === v ? '' : 'none';
                    });
                });
            });
            function show(e) {
                var h = e.currentTarget;
                var xp = parseFloat(h.dataset.x);
                tip.innerHTML = '<div style="font-weight:700;margin-bottom:3px;">' + h.dataset.full + '</div>'
                    + '<div style="display:flex;align-items:center;gap:6px;"><i style="width:8px;height:8px;border-radius:50%;background:' + @json($cMo) + ';display:inline-block;"></i>mobile ' + h.dataset.mo + '</div>'
                    + '<div style="display:flex;align-items:center;gap:6px;"><i style="width:8px;height:8px;border-radius:50%;background:' + @json($cPc) + ';display:inline-block;"></i>PC ' + h.dataset.pc + '</div>';
                tip.style.left = xp + '%';
                tip.style.top = '2px';
                tip.style.display = 'block';
                vline.style.left = xp + '%';
                vline.style.display = 'block';
            }
            function hide() { tip.style.display = 'none'; vline.style.display = 'none'; }
        })();
        </script>
    @endif

    {{-- 성별 · 연령별 검색 비율 (연령별→성별+연령) — 세로 그룹 막대 + Y축 + 호버 툴팁 --}}
    @if ($show('genderage') && ! empty($d['has_demo']) && count($pyr))
        @php
            $pyMax = 1;
            foreach ($pyr as $p) { $pyMax = max($pyMax, round($p['female'] / $pSum * 100, 1), round($p['male'] / $pSum * 100, 1)); }
            $pyAxis = max(5, (int) (ceil($pyMax / 5) * 5));
        @endphp
        <div class="card p-5 mb-6" style="position:relative;" id="{{ $uid }}py">
            <div class="flex items-center gap-3 mb-4">
                <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">성별 · 연령별 검색 비율</span>
                <span style="font-size:var(--fs-xs);"><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $cFemale }};margin-right:4px;"></i>여성</span>
                <span style="font-size:var(--fs-xs);"><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $cMale }};margin-right:4px;"></i>남성</span>
            </div>
            <div style="position:relative;padding-left:38px;">
                <div style="position:absolute;left:0;top:0;width:34px;height:180px;">
                    @for ($g = 4; $g >= 0; $g--)
                        <span class="text-muted-soft" style="position:absolute;right:6px;top:calc({{ (1 - $g / 4) * 100 }}% - 7px);font-size:var(--fs-xs);">{{ round($pyAxis * $g / 4) }}%</span>
                    @endfor
                </div>
                <div style="position:relative;height:180px;">
                    @for ($g = 0; $g <= 4; $g++)
                        <div style="position:absolute;left:0;right:0;top:{{ $g / 4 * 100 }}%;border-top:1px dashed var(--color-hairline-soft);"></div>
                    @endfor
                    <div style="display:flex;align-items:flex-end;gap:8px;height:100%;position:relative;">
                        @foreach ($pyr as $p)
                            @php $fp = round($p['female'] / $pSum * 100, 1); $mp = round($p['male'] / $pSum * 100, 1); @endphp
                            <div class="kwpy-hover" style="flex:1;display:flex;align-items:flex-end;justify-content:center;gap:3px;height:100%;cursor:default;"
                                 data-label="{{ $p['label'] }}" data-f="{{ $fp }}" data-m="{{ $mp }}" data-fn="{{ number_format($p['female']) }}" data-mn="{{ number_format($p['male']) }}">
                                <div style="width:42%;height:{{ max(1, round($fp / $pyAxis * 100)) }}%;background:{{ $cFemale }};border-radius:3px 3px 0 0;min-height:1px;"></div>
                                <div style="width:42%;height:{{ max(1, round($mp / $pyAxis * 100)) }}%;background:{{ $cMale }};border-radius:3px 3px 0 0;min-height:1px;"></div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div style="display:flex;gap:8px;margin-top:6px;">
                    @foreach ($pyr as $p)<span class="text-muted-soft text-center" style="flex:1;font-size:var(--fs-xs);">{{ $p['label'] }}</span>@endforeach
                </div>
            </div>
            <div class="kwpy-tip" style="position:absolute;display:none;pointer-events:none;background:var(--color-surface-dark);color:#fff;border-radius:8px;padding:8px 11px;font-size:var(--fs-xs);white-space:nowrap;transform:translate(-50%,-100%);z-index:5;box-shadow:var(--shadow-card);"></div>
        </div>

        <script>
        (function () {
            var card = document.getElementById(@json($uid.'py'));
            if (!card) return;
            var tip = card.querySelector('.kwpy-tip');
            if (!tip) return;
            card.querySelectorAll('.kwpy-hover').forEach(function (h) {
                h.addEventListener('mouseenter', show);
                h.addEventListener('mousemove', show);
                h.addEventListener('mouseleave', function () { tip.style.display = 'none'; });
            });
            function show(e) {
                var h = e.currentTarget;
                tip.innerHTML = '<div style="font-weight:700;margin-bottom:3px;">' + h.dataset.label + '</div>'
                    + '<div style="display:flex;align-items:center;gap:6px;"><i style="width:8px;height:8px;border-radius:50%;background:' + @json($cFemale) + ';display:inline-block;"></i>여성 ' + h.dataset.f + '% <span style="opacity:.7;">(' + h.dataset.fn + ')</span></div>'
                    + '<div style="display:flex;align-items:center;gap:6px;"><i style="width:8px;height:8px;border-radius:50%;background:' + @json($cMale) + ';display:inline-block;"></i>남성 ' + h.dataset.m + '% <span style="opacity:.7;">(' + h.dataset.mn + ')</span></div>';
                var cr = card.getBoundingClientRect(), hr = h.getBoundingClientRect();
                tip.style.left = (hr.left - cr.left + hr.width / 2) + 'px';
                tip.style.top = (hr.top - cr.top - 6) + 'px';
                tip.style.display = 'block';
            }
        })();
        </script>
    @endif

    {{-- 디바이스별 검색 비율 — 전체(도넛)·성별별·연령별을 카드 3개로 분리. 성별/연령은 그룹 세로막대. --}}
    @if ($show('device') && ! empty($d['device']['has']))
        @php
            $dev = $d['device'];
            $cPcD = 'var(--color-accent)';
            $cMoD = 'var(--color-badge-emerald)';
            $devLegend = '<span style="font-size:var(--fs-xs);"><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:'.$cPcD.';margin-right:4px;"></i>PC</span>'
                .'<span style="font-size:var(--fs-xs);"><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:'.$cMoD.';margin-right:4px;"></i>모바일</span>';
        @endphp
        {{-- 전체 · 성별 · 연령별 디바이스 — 카드 3개 한 줄(3열) --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
            {{-- 전체 디바이스 (도넛 + 범례 아래) --}}
            <div class="card p-5 text-center">
                <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">디바이스별 검색 비율</div>
                <div class="flex items-center justify-center">
                    @include('partials.donut', ['segs' => [
                        ['label' => 'PC', 'value' => $dev['total']['pc'], 'color' => $cPcD],
                        ['label' => '모바일', 'value' => $dev['total']['mobile'], 'color' => $cMoD],
                    ]])
                </div>
                <div class="flex items-center justify-center gap-4 mt-3">
                    <span style="font-size:var(--fs-xs);"><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $cPcD }};margin-right:4px;"></i>PC <b class="text-ink">{{ $dev['total']['pc_pct'] }}%</b></span>
                    <span style="font-size:var(--fs-xs);"><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $cMoD }};margin-right:4px;"></i>모바일 <b class="text-ink">{{ $dev['total']['mobile_pct'] }}%</b></span>
                </div>
            </div>

            {{-- 성별 디바이스 (그룹 세로막대 + 호버) --}}
            <div class="card p-5">
                <div class="flex items-center gap-3 mb-4 flex-wrap">
                    <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">성별 디바이스 검색 비율</span>
                    {!! $devLegend !!}
                </div>
                @include('partials.device-bars', ['rows' => $dev['by_gender'], 'cPc' => $cPcD, 'cMo' => $cMoD])
            </div>

            {{-- 연령별 디바이스 (그룹 세로막대 + 호버) --}}
            <div class="card p-5">
                <div class="flex items-center gap-3 mb-4 flex-wrap">
                    <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">연령별 디바이스 검색 비율</span>
                    {!! $devLegend !!}
                </div>
                @include('partials.device-bars', ['rows' => $dev['by_age'], 'cPc' => $cPcD, 'cMo' => $cMoD])
            </div>
        </div>
    @endif

    {{-- 월별 · 요일별 검색 비율 — 파란 세로 막대 + Y축. $weekday(데이터랩) 있으면 2-col. 키워드/시장 공용 --}}
    @if ($show('month') && ! empty($d['has_demo']))
        @php
            $mrMax = max(1, ...array_map(fn ($m) => (float) $m['pct'], $mr ?: [['pct' => 0]]));
            $mrAxis = max(5, (int) (ceil($mrMax / 5) * 5));
            $cBar = 'color-mix(in srgb, var(--color-accent) 50%, var(--color-canvas))';
            $cBarTop = 'var(--color-accent)';
            $hasWd = ! empty($weekday);
        @endphp
        <div class="grid grid-cols-1 {{ $hasWd ? 'lg:grid-cols-2' : '' }} gap-4 mb-6">
            {{-- 월별 --}}
            <div class="card p-5">
                <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-xs);">월별 검색 비율 <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">1~12월 계절성</span></div>
                <div style="position:relative;padding-left:34px;">
                    <div style="position:absolute;left:0;top:0;width:30px;height:150px;">
                        @for ($g = 4; $g >= 0; $g--)
                            <span class="text-muted-soft" style="position:absolute;right:5px;top:calc({{ (1 - $g / 4) * 100 }}% - 7px);font-size:var(--fs-xs);">{{ round($mrAxis * $g / 4) }}%</span>
                        @endfor
                    </div>
                    <div style="position:relative;height:150px;">
                        @for ($g = 0; $g <= 4; $g++)
                            <div style="position:absolute;left:0;right:0;top:{{ $g / 4 * 100 }}%;border-top:1px dashed var(--color-hairline-soft);"></div>
                        @endfor
                        <div style="display:flex;align-items:flex-end;gap:6px;height:100%;position:relative;">
                            @foreach ($mr as $m)
                                @php $isTop = $m['pct'] >= $mrMax - 0.001; @endphp
                                <div style="flex:1;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;" title="{{ $m['m'] }}월 · {{ $m['pct'] }}%">
                                    <div style="width:70%;min-height:2px;height:{{ max(2, round($m['pct'] / $mrAxis * 100)) }}%;background:{{ $isTop ? $cBarTop : $cBar }};border-radius:3px 3px 0 0;"></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div style="display:flex;gap:6px;margin-top:6px;">
                        @foreach ($mr as $m)<span class="text-muted-soft text-center" style="flex:1;font-size:var(--fs-xs);">{{ $m['m'] }}월</span>@endforeach
                    </div>
                </div>
            </div>

            {{-- 요일별 (데이터랩 최근 90일) --}}
            @if ($hasWd)
                @php $wdMax = max(1, ...array_map(fn ($x) => (float) $x['pct'], $weekday)); $wdAxis = max(5, (int) (ceil($wdMax / 5) * 5)); @endphp
                <div class="card p-5">
                    <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-xs);">요일별 검색 비율 <span class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:400;">데이터랩 최근 90일</span></div>
                    <div style="position:relative;padding-left:34px;">
                        <div style="position:absolute;left:0;top:0;width:30px;height:150px;">
                            @for ($g = 4; $g >= 0; $g--)
                                <span class="text-muted-soft" style="position:absolute;right:5px;top:calc({{ (1 - $g / 4) * 100 }}% - 7px);font-size:var(--fs-xs);">{{ round($wdAxis * $g / 4) }}%</span>
                            @endfor
                        </div>
                        <div style="position:relative;height:150px;">
                            @for ($g = 0; $g <= 4; $g++)
                                <div style="position:absolute;left:0;right:0;top:{{ $g / 4 * 100 }}%;border-top:1px dashed var(--color-hairline-soft);"></div>
                            @endfor
                            <div style="display:flex;align-items:flex-end;gap:8px;height:100%;position:relative;">
                                @foreach ($weekday as $wd)
                                    @php $isWeekend = in_array($wd['w'], ['토', '일'], true); @endphp
                                    <div style="flex:1;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;" title="{{ $wd['w'] }} · {{ $wd['pct'] }}%">
                                        <div style="width:66%;min-height:2px;height:{{ max(2, round($wd['pct'] / $wdAxis * 100)) }}%;background:{{ $isWeekend ? $cBar : $cBarTop }};border-radius:3px 3px 0 0;"></div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:6px;">
                            @foreach ($weekday as $wd)<span class="text-muted-soft text-center" style="flex:1;font-size:var(--fs-xs);">{{ $wd['w'] }}</span>@endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif
@endif
