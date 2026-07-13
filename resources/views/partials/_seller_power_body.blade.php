{{--
    셀러력 진단 본문 — 콘솔 상세(seller-power.show) + 공개 공유(seller-power.share) 공용.
    입력: $a(SellerPowerAnalysis), $r(보정된 snapshot 배열).
--}}
@php
    $gc = fn ($g) => match ($g) {
        'S' => 'var(--color-success)', 'A' => 'var(--color-accent)', 'B' => 'var(--color-badge-violet)',
        'C' => 'var(--color-warning)', default => 'var(--color-muted)',
    };
    $sem = ['ok' => 'var(--color-success)', 'warn' => 'var(--color-warning)', 'bad' => 'var(--color-error)'];
    $diffLabel = ['easy' => '쉬움', 'mid' => '보통', 'hard' => '어려움'];
    $grade = $r['grade'] ?? 'D';
    $gcolor = $gc($grade);
    $score = $r['score'] ?? 0;
    $circ = 477.5;
    $gapTop = ($r['radar_avg_total'] ?? $score) - $score;
    $positions = $r['positions'] ?? [];
    $myIdx = $r['my_position_index'] ?? 0;

    // 도넛 세그먼트 = 각 축의 총점 기여(내 점수 × 가중치). 색 순서 고정(dataviz 팔레트 검증 통과 조합)
    $donutColors = ['var(--color-accent)', 'var(--color-warning)', 'var(--color-success)', 'var(--color-badge-violet)', 'var(--color-badge-pink)'];
    $donutSegs = [];
    $dAcc = 0.0;
    foreach (($r['axes'] ?? []) as $di => $ax) {
        $contrib = max(0, (float) ($ax['mine'] ?? 0) * (float) ($ax['weight'] ?? 0));
        $donutSegs[] = [
            'key' => $ax['key'],
            'color' => $donutColors[$di % count($donutColors)],
            'len' => $contrib / 100 * $circ,
            'start' => $dAcc / 100 * $circ,
            'contrib' => round($contrib, 1),
            'mine' => $ax['mine'] ?? 0,
            'weight' => round((($ax['weight'] ?? 0)) * 100),
        ];
        $dAcc += $contrib;
    }

    // 보강 필요(✕) 처방 항목 → 추천 액션(찜 늘리기 · 리뷰 늘리기 · 순위 올리기)
    $sugMap = [];
    foreach (($r['rx'] ?? []) as $rxg) {
        foreach (($rxg['items'] ?? []) as $rxItem) {
            if (($rxItem['state'] ?? '') !== 'bad') {
                continue;
            }
            $nm = (string) ($rxItem['name'] ?? '');
            if (str_contains($nm, '찜')) {
                $sugMap['찜 늘리기'] = true;
            } elseif (str_contains($nm, '리뷰') || str_contains($nm, '평점')) {
                $sugMap['리뷰 늘리기'] = true;
            } elseif (str_contains($nm, '판매') || str_contains($nm, '순위') || str_contains($nm, '키워드') || str_contains($nm, '노출') || str_contains($nm, '태그')) {
                $sugMap['순위 올리기'] = true;
            }
        }
    }
    $spSuggests = array_keys($sugMap);

    // 맞춤 추천 — 약한 축·손해 항목과 매칭되는 상품 우선 정렬
    $spWeak = collect($r['axes'] ?? [])->filter(fn ($x) => ($x['gap'] ?? 0) < 0)->sortBy('gap')->values();
    $spLoss = array_slice($r['losses'] ?? [], 0, 2);
    $spWeakText = $spWeak->pluck('key')->implode(' ').' '.implode(' ', array_column($spLoss, 'title')).' '.implode(' ', $spSuggests);
    $spProducts = [
        ['name' => '찜 부스팅', 'desc' => '실사용자 찜(위시리스트)을 늘려 인기 신호와 알고리즘 가점을 확보합니다.', 'match' => ['찜', '인기도'], 'c1' => 'var(--color-badge-pink)', 'c2' => 'var(--color-badge-orange)', 'icon' => 'heart'],
        ['name' => '리뷰 확보 · 체험단', 'desc' => '체험단 매칭으로 포토 리뷰를 쌓아 신뢰도와 구매 전환율을 높입니다.', 'match' => ['리뷰', '신뢰도', '평점'], 'c1' => 'var(--color-badge-emerald)', 'c2' => 'var(--color-accent)', 'icon' => 'pen'],
        ['name' => '랭킹 최적화', 'desc' => '검색 유입·클릭 신호를 개선해 쇼핑 검색 순위를 끌어올립니다.', 'match' => ['적합도', '순위', '키워드', '노출', '클릭'], 'c1' => 'var(--color-accent)', 'c2' => 'var(--color-badge-violet)', 'icon' => 'trend'],
    ];
    $spProducts = array_map(function ($p) use ($spWeakText) {
        $p['recommended'] = (bool) array_filter($p['match'], fn ($m) => str_contains($spWeakText, $m));

        return $p;
    }, $spProducts);
    usort($spProducts, fn ($x, $y) => $y['recommended'] <=> $x['recommended']);
@endphp

<style>
    /* 셀러력 도넛 — 세그먼트 호버 강조(<title> 툴팁으로 축별 기여 표시) */
    .sp-seg { transition: stroke-width 0.12s ease; cursor: pointer; }
    .sp-seg:hover { stroke-width: 19px; }
    /* 이미지 저장 시에만 노출되는 상하단 브랜딩 + 저장 이미지 여백 */
    #sp-report .sp-cap-only { display: none; }
    #sp-report.sp-capturing .sp-cap-only { display: flex !important; }
    #sp-report.sp-capturing { padding: 20px; }
    /* 이미지에서 동작 못 하는 요소(상담 문의 버튼 등)는 캡처에서 제외 */
    #sp-report.sp-capturing .sp-cap-hide { display: none !important; }
    /* 레이더 — 호버 움찔 */
    @media (prefers-reduced-motion: no-preference) {
        @keyframes sp-wiggle {
            0% { transform: rotate(0) scale(1.02); }
            25% { transform: rotate(-1.6deg) scale(1.04); }
            50% { transform: rotate(1.2deg) scale(1.04); }
            75% { transform: rotate(-0.5deg) scale(1.03); }
            100% { transform: rotate(0) scale(1.03); }
        }
        #spRadar { transition: transform 0.2s ease; }
        #spRadar:hover { animation: sp-wiggle 0.5s ease; transform: scale(1.03); }
    }
</style>

<div id="sp-report">

    {{-- 캡처 전용 상단 브랜딩 — 공유페이지 디자인 --}}
    <div class="sp-cap-only" style="align-items:center;justify-content:space-between;gap:8px;margin-bottom:18px;">
        <span class="badge border border-hairline">셀러력 진단 리포트 · 랭크프리</span>
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">rankfree.kr</span>
    </div>

    {{-- 헤더 --}}
    <div class="flex items-start justify-between flex-wrap gap-3 mb-5">
        <div style="min-width:0;">
            <span class="badge" style="font-size:var(--fs-xs);padding:2px 9px;background:color-mix(in srgb,var(--color-accent) 12%,var(--color-canvas));color:var(--color-accent);">‘{{ $r['keyword'] ?? $a->keyword }}’ 기준</span>
            <a href="{{ $a->product_url }}" target="_blank" rel="noopener" class="text-ink font-display hover:underline block mt-2" style="font-size:var(--fs-xl);line-height:1.3;">{{ $r['product_name'] ?? $a->product_name ?: $a->store_id }}</a>
            <div class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">{{ $a->updated_at->format('Y-m-d H:i') }} 수집 · 경쟁 상위 {{ $r['competitor_count'] ?? 0 }}개 비교</div>
        </div>
    </div>

    {{-- 상단: 도넛 · 진단 요약 · 경쟁 속 내 자리 (3단) --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4 items-stretch">

    {{-- 1) 셀러력 점수 — 축별 기여 도넛 (마우스 올리면 축별 기여 표시) --}}
    <div class="card p-6 flex flex-col items-center text-center">
        <div class="text-ink font-semibold self-start text-left mb-3" style="font-size:var(--fs-sm);">셀러력 지수</div>
        <div class="flex-1 flex flex-col items-center justify-center">
        <div style="position:relative;width:176px;height:176px;">
            <svg width="176" height="176" viewBox="0 0 176 176" style="transform:rotate(-90deg);overflow:visible;">
                <circle cx="88" cy="88" r="76" fill="none" stroke="var(--color-surface-strong)" stroke-width="14"></circle>
                @foreach ($donutSegs as $seg)
                    @if ($seg['len'] > 2.5)
                        <circle class="sp-seg" cx="88" cy="88" r="76" fill="none" stroke="{{ $seg['color'] }}" stroke-width="14"
                                stroke-dasharray="{{ max(0.1, round($seg['len'] - 2, 2)) }} {{ round($circ - max(0.1, $seg['len'] - 2), 2) }}"
                                stroke-dashoffset="{{ round(-($seg['start'] + 1), 2) }}">
                            <title>{{ $seg['key'] }} — 총점 기여 {{ $seg['contrib'] }}점 (내 {{ $seg['mine'] }}점 × 가중치 {{ $seg['weight'] }}%)</title>
                        </circle>
                    @endif
                @endforeach
            </svg>
            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;">
                <div class="font-display" style="font-size:54px;line-height:.9;letter-spacing:-.03em;color:{{ $gcolor }};">{{ round($score) }}</div>
                <div class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:600;margin-top:3px;">셀러력 / 100</div>
            </div>
        </div>
        <div class="badge mt-4" style="font-size:var(--fs-sm);padding:5px 15px;background:color-mix(in srgb,{{ $gcolor }} 14%,var(--color-canvas));color:{{ $gcolor }};font-weight:800;">{{ $grade }}등급</div>
        {{-- 범례 — 색만으로 구분하지 않도록 축명·기여 병기. 등급과 여백 분리 --}}
        <div class="flex flex-wrap justify-center" style="gap:7px 14px;margin-top:32px;">
            @foreach ($donutSegs as $seg)
                <span class="text-muted" style="display:inline-flex;align-items:center;gap:5px;font-size:11px;" title="{{ $seg['key'] }} 기여 {{ $seg['contrib'] }}점">
                    <span style="width:9px;height:9px;border-radius:3px;background:{{ $seg['color'] }};flex:none;"></span>{{ $seg['key'] }} {{ $seg['contrib'] }}
                </span>
            @endforeach
        </div>
        </div>
    </div>

    {{-- 2) 진단 요약 — 격차 + 보강 추천 + 시장 위치 --}}
    <div class="card p-6 flex flex-col">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">진단 요약</div>
        {{-- 지금 위치 — 카드 없이 텍스트 표기, 맨 위 --}}
        <div class="mb-3">
            <div class="text-muted-soft mb-1" style="font-size:var(--fs-xs);font-weight:600;">지금 위치</div>
            <div class="text-ink font-display" style="font-size:22px;">{{ $r['rank_in_top'] ?? 0 }}위 <small style="font-size:12px;color:var(--color-muted-soft);">/ {{ ($r['competitor_count'] ?? 0) + 1 }}개</small></div>
            <div class="text-muted" style="font-size:var(--fs-xs);margin-top:3px;">‘{{ $r['keyword'] ?? $a->keyword }}’ · 시장 상위 {{ $r['market_percentile'] ?? 0 }}%</div>
        </div>
        <p class="text-muted" style="font-size:var(--fs-sm);line-height:1.7;">
            @if ($gapTop > 0)
                상위권과 <b class="text-ink">{{ round($gapTop) }}점 차이</b> — 축별로 보면 아래에서 벌어졌습니다.
            @else
                이미 <b class="text-ink">상위권</b> 셀러력입니다. 강점을 유지하며 약한 축을 보완하세요.
            @endif
        </p>
        {{-- 가장 밀리는 축 — 축별 부족 점수 배지 --}}
        @if ($spWeak->count())
            <div class="mt-3">
                <div class="text-muted-soft mb-1" style="font-size:var(--fs-xs);font-weight:600;">가장 밀리는 축</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($spWeak->take(3) as $wx)
                        <span class="badge" style="font-size:var(--fs-xs);padding:3px 10px;">{{ $wx['key'] }} <b style="color:var(--color-error);">{{ $wx['gap'] }}</b></span>
                    @endforeach
                </div>
                <div class="text-muted-soft" style="font-size:var(--fs-xs);margin-top:5px;">상위 평균 대비 부족한 점수입니다.</div>
            </div>
        @endif
        {{-- 최우선 개선 --}}
        @if (count($spLoss))
            <div class="mt-3">
                <div class="text-muted-soft mb-1" style="font-size:var(--fs-xs);font-weight:600;">최우선 개선</div>
                <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $spLoss[0]['title'] }}</div>
                <div class="text-muted" style="font-size:var(--fs-xs);margin-top:2px;">{{ $spLoss[0]['cur'] }} → {{ $spLoss[0]['target'] }} <b style="color:var(--color-success);">+{{ $spLoss[0]['gain'] }}점 기대</b></div>
            </div>
        @endif
    </div>

    {{-- 3) 경쟁 속 내 자리 — 순위 사다리(1~3위 · 내 주변 · 최하위, 중간 생략) --}}
    <div class="card p-5 flex flex-col">
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">경쟁 속 내 자리</div>
        <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">같은 키워드 상위 {{ count($positions) }}개 상품을 셀러력 순으로 세운 순위표입니다.</p>
        @if (count($positions) > 1)
            @php
                $spTotal = count($positions);
                $spShow = collect([0, 1, 2, $myIdx - 1, $myIdx, $myIdx + 1, $spTotal - 1])
                    ->filter(fn ($i) => $i >= 0 && $i < $spTotal)->unique()->sort()->values();
                $spPrev = null;
            @endphp
            <div style="display:flex;flex-direction:column;gap:3px;">
                @foreach ($spShow as $i)
                    @if ($spPrev !== null && $i > $spPrev + 1)
                        <div class="text-muted-soft text-center" style="font-size:var(--fs-xs);line-height:1.1;">⋯</div>
                    @endif
                    @php $spMine = $i === $myIdx; $pt = (int) $positions[$i]; @endphp
                    <div style="display:flex;align-items:center;gap:8px;padding:4px 8px;{{ $spMine ? 'background:color-mix(in srgb,var(--color-accent) 8%,var(--color-canvas));border-radius:8px;' : '' }}">
                        <span style="width:34px;flex:none;font-size:var(--fs-xs);font-weight:{{ $spMine ? '800' : '600' }};color:{{ $spMine ? 'var(--color-accent)' : 'var(--color-muted)' }};">{{ $i + 1 }}위</span>
                        <div style="flex:1;height:7px;border-radius:99px;background:var(--color-surface-strong);position:relative;">
                            <div style="position:absolute;left:0;top:0;bottom:0;width:{{ min(100, max(2, $pt)) }}%;border-radius:99px;background:{{ $spMine ? 'var(--color-accent)' : 'var(--color-muted-soft)' }};"></div>
                        </div>
                        <span style="width:64px;flex:none;text-align:right;font-size:var(--fs-xs);font-weight:{{ $spMine ? '800' : '500' }};color:{{ $spMine ? 'var(--color-accent)' : 'var(--color-muted)' }};">{{ $pt }}점{{ $spMine ? ' · 나' : '' }}</span>
                    </div>
                    @php $spPrev = $i; @endphp
                @endforeach
            </div>
            <p class="text-muted mt-auto pt-3" style="font-size:var(--fs-xs);">경쟁 {{ $spTotal - 1 }}개 중 <b class="text-ink">{{ $r['rank_in_top'] ?? 0 }}위</b> · 상위 평균 셀러력 <b class="text-ink">{{ $r['radar_avg_total'] ?? 0 }}점</b></p>
        @else
            <div class="flex-1 flex items-center justify-center text-muted-soft" style="font-size:var(--fs-xs);padding:20px 0;">경쟁 상품 데이터가 없습니다.</div>
        @endif
    </div>

    </div>{{-- /상단 3단 --}}

    {{-- 맞춤 보강 추천 — 진단 스토리(약점 → 목표 → 상품). 등급 줄 바로 아래 --}}
    <div id="sp-recommend" class="card p-6 mb-4">
        <div class="text-ink font-semibold mb-2" style="font-size:var(--fs-sm);">이렇게 보강하세요 — 맞춤 추천</div>
        {{-- 서술 설명 — 진단을 문장으로 풀어쓴 가이드 (타일 요약은 진단 요약 카드로 이동) --}}
        <p class="text-body" style="font-size:var(--fs-sm);line-height:1.8;">
            ‘<b class="text-ink">{{ $r['keyword'] ?? $a->keyword }}</b>’ 검색에서 내 상품은 경쟁 상위 {{ ($r['competitor_count'] ?? 0) + 1 }}개 중 <b class="text-ink">{{ $r['rank_in_top'] ?? 0 }}위</b>입니다.
            @if ($spWeak->count())
                상위권과의 격차는 대부분 <b class="text-ink">{{ $spWeak->pluck('key')->take(2)->implode('·') }}</b>에서 발생했습니다.
            @endif
            @if (count($spLoss))
                특히 <b class="text-ink">{{ $spLoss[0]['title'] }}</b>이 {{ $spLoss[0]['cur'] }}에 머물러 있어, {{ $spLoss[0]['target'] }}까지 보강하면 셀러력 <b style="color:var(--color-success);">+{{ $spLoss[0]['gain'] }}점</b>이 기대됩니다.
            @endif
            아래 상품 중 <b style="color:var(--color-accent);">추천</b> 표시가 붙은 항목부터 진행하면 격차를 가장 빠르게 줄일 수 있습니다.
        </p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            @foreach ($spProducts as $p)
                {{-- 홈페이지 피처 카드 스타일 — 아이콘 칩 + 그라데이션·도트 배경 --}}
                <div class="card p-8 flex flex-col relative overflow-hidden">
                    <x-card-bg pattern="gradient" color="{{ $p['c1'] }}" color2="{{ $p['c2'] }}" opacity="0.22" />
                    <x-card-bg pattern="dots" color="{{ $p['c1'] }}" opacity="0.28" />
                    <div class="relative flex flex-col flex-1">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background:color-mix(in srgb, {{ $p['c1'] }} 15%, transparent);color:{{ $p['c1'] }};">
                            @if ($p['icon'] === 'heart')
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                            @elseif ($p['icon'] === 'pen')
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            @else
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                            @endif
                        </div>
                        <div class="mt-4 flex items-center gap-2 flex-wrap">
                            <h3 class="text-ink font-semibold" style="font-size:var(--fs-base);">{{ $p['name'] }}</h3>
                            @if ($p['recommended'])
                                <span class="badge" style="font-size:11px;padding:1px 8px;background:color-mix(in srgb,var(--color-accent) 14%,var(--color-canvas));color:var(--color-accent);font-weight:700;">이 진단에 추천</span>
                            @endif
                        </div>
                        <p class="text-body mt-2 flex-1" style="font-size:var(--fs-sm);line-height:1.65;">{{ $p['desc'] }}</p>
                        <a href="/support" class="btn btn-secondary btn-sm mt-5 sp-cap-hide" style="align-self:flex-start;">상담 문의</a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- 5축: 오각형 / 축별 비교 / 손해 — 카드 3개 분리 --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">

    {{-- 1) 오각형(레이더) — 한눈에 보는 5축 균형 --}}
    <div class="card p-5 flex flex-col">
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">어디서 밀리나 — 5축 균형</div>
        <p class="text-muted-soft mb-2" style="font-size:var(--fs-xs);">면적이 넓을수록 강합니다. 움푹 들어간 축이 약점입니다.</p>
        <div class="flex-1 flex items-center justify-center">
            {{-- 카드 폭에 맞춰 최대 420px까지 확장(정사각 유지) --}}
            <div style="position:relative;width:100%;max-width:420px;"><canvas id="spRadar" width="440" height="440" style="width:100%;height:auto;aspect-ratio:1/1;display:block;"></canvas></div>
        </div>
        <div style="display:flex;gap:14px;justify-content:center;margin-top:12px;font-size:var(--fs-xs);color:var(--color-muted);">
            <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:12px;height:3px;background:var(--color-accent);border-radius:2px;"></span>내 상품</span>
            <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:12px;height:0;border-top:2px dashed var(--color-muted-soft);"></span>상위 평균</span>
        </div>
    </div>

    {{-- 2) 축별 점수 — 내 상품/상위 평균 막대 2개 + 축 의미 + 상태 --}}
    <div class="card p-5">
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">축별 점수 비교</div>
        <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">각 축 100점 만점 · <b>반영 %</b> = 셀러력 총점에 들어가는 가중치</p>
        @php
            $axisDesc = [
                '적합도' => '제목·태그가 검색 키워드와 얼마나 일치하는지',
                '인기도' => '판매·클릭·찜 등 인기 신호',
                '신뢰도' => '리뷰 수·평점 등 구매 신뢰 신호',
                '기본·배송' => '상품정보 충실도와 배송 조건',
                '마케팅·판매자' => '판매자 등급·프로모션 등 운영 활동',
            ];
        @endphp
        <div style="display:flex;flex-direction:column;gap:13px;">
            @foreach ($r['axes'] ?? [] as $ax)
                @php
                    $gap = (int) ($ax['gap'] ?? 0);
                    [$stLbl, $stColor] = $gap > 3 ? ['우세', 'var(--color-success)'] : ($gap < -3 ? ['열세', 'var(--color-error)'] : ['비슷', 'var(--color-muted)']);
                    $mine = max(0, min(100, (int) ($ax['mine'] ?? 0)));
                    $avg = max(0, min(100, (int) ($ax['avg'] ?? 0)));
                @endphp
                <div>
                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;">
                        <span class="text-body" style="font-size:var(--fs-xs);font-weight:700;">{{ $ax['key'] }} <span class="text-muted-soft" style="font-weight:500;">· 반영 {{ round(($ax['weight'] ?? 0) * 100) }}%</span></span>
                        <span style="font-size:11px;font-weight:800;color:{{ $stColor }};">{{ $stLbl }} {{ $gap >= 0 ? '+' : '' }}{{ $gap }}</span>
                    </div>
                    @if (isset($axisDesc[$ax['key']]))
                        <div class="text-muted-soft" style="font-size:var(--fs-xs);margin-top:1px;">{{ $axisDesc[$ax['key']] }}</div>
                    @endif
                    <div style="display:grid;grid-template-columns:52px 1fr 30px;gap:6px 8px;align-items:center;margin-top:6px;font-size:11px;">
                        <span class="text-muted-soft">내 상품</span>
                        <div style="height:7px;border-radius:99px;background:var(--color-surface-strong);"><div style="height:100%;width:{{ $mine }}%;border-radius:99px;background:var(--color-accent);"></div></div>
                        <b class="text-ink" style="text-align:right;">{{ $ax['mine'] }}</b>
                        <span class="text-muted-soft">상위평균</span>
                        <div style="height:7px;border-radius:99px;background:var(--color-surface-strong);"><div style="height:100%;width:{{ $avg }}%;border-radius:99px;background:var(--color-muted-soft);"></div></div>
                        <b class="text-muted" style="text-align:right;">{{ $ax['avg'] }}</b>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- 3) 개선점 요약 — 처방(적합도·인기도·기본·배송·마케팅)에서 보강 필요 항목 6가지 --}}
    <div class="card p-5">
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">개선점 요약</div>
        <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">적합도·인기도·기본·배송·마케팅 처방 중 <b>보강이 필요한 항목</b>부터 모았어요.</p>
        @php
            $sumAxes = ['적합도', '인기도', '기본·배송', '마케팅·판매자'];
            $sumItems = [];
            foreach (($r['rx'] ?? []) as $rxg) {
                if (! in_array($rxg['axis'] ?? '', $sumAxes, true)) {
                    continue;
                }
                foreach (($rxg['items'] ?? []) as $rxIt) {
                    if (in_array($rxIt['state'] ?? '', ['bad', 'warn'], true)) {
                        $sumItems[] = ['axis' => $rxg['axis']] + $rxIt;
                    }
                }
            }
            // ✕(보강 필요) 우선, 그다음 !(주의) — 7가지까지
            usort($sumItems, fn ($x, $y) => ($x['state'] === 'bad' ? 0 : 1) <=> ($y['state'] === 'bad' ? 0 : 1));
            $sumItems = array_slice($sumItems, 0, 7);
        @endphp
        @forelse ($sumItems as $it)
            @php
                $c = $sem[$it['state']] ?? 'var(--color-muted)';
                $mk = ['ok' => '✓', 'warn' => '!', 'bad' => '✕'][$it['state']] ?? '·';
                $parts = explode(' — ', (string) ($it['tip'] ?? ''));
                $stLabel = count($parts) > 1 ? array_pop($parts) : '';
                $stVal = trim(implode(' — ', $parts));
            @endphp
            <div style="display:flex;align-items:center;gap:10px;padding:13px 2px;border-top:{{ $loop->first ? '0' : '1px solid var(--color-hairline-soft)' }};">
                <span style="width:18px;height:18px;flex:none;border-radius:5px;display:grid;place-items:center;font-size:10px;font-weight:800;color:{{ $c }};background:color-mix(in srgb,{{ $c }} 14%,var(--color-canvas));">{{ $mk }}</span>
                <div style="flex:1;min-width:0;">
                    <div class="text-ink" style="font-size:var(--fs-xs);font-weight:600;">{{ $it['name'] }} <span class="text-muted-soft" style="font-weight:500;">· {{ $it['axis'] }}</span></div>
                    @if ($stVal !== '')
                        <div class="text-muted" style="font-size:var(--fs-xs);margin-top:1px;">{{ $stVal }}</div>
                    @endif
                </div>
                @if ($stLabel !== '')
                    <span style="flex:none;font-size:11px;font-weight:700;color:{{ $c }};white-space:nowrap;">{{ $stLabel }}</span>
                @endif
            </div>
        @empty
            <div class="text-muted-soft" style="font-size:var(--fs-xs);padding:16px 0;">보강이 필요한 항목이 없습니다 — 모두 양호합니다.</div>
        @endforelse
    </div>

    </div>{{-- /5축 3카드 --}}

    {{-- 항목별 셀러력 처방 — 한 줄 5개, 높이 통일(items-stretch 기본), 배경색 없는 흰 카드 --}}
    @if (! empty($r['rx']))
        <div class="card p-5 mb-4">
            <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-sm);">항목별 셀러력 처방</div>
            {{-- 축별 그라데이션 워시 — 홈페이지 마케팅 카드 스타일 --}}
            @php
                $rxMeta = [
                    '적합도' => ['var(--color-accent)', 'var(--color-badge-violet)'],
                    '인기도' => ['var(--color-badge-orange)', 'var(--color-badge-pink)'],
                    '신뢰도' => ['var(--color-badge-emerald)', 'var(--color-accent)'],
                    '기본·배송' => ['var(--color-badge-violet)', 'var(--color-badge-pink)'],
                    '마케팅·판매자' => ['var(--color-badge-pink)', 'var(--color-badge-orange)'],
                ];
            @endphp
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                @foreach ($r['rx'] as $g)
                    @php [$rxg1, $rxg2] = $rxMeta[$g['axis']] ?? ['var(--color-accent)', 'var(--color-badge-violet)']; @endphp
                    <div class="card relative overflow-hidden" style="padding:15px 16px;border-radius:12px;">
                        <x-card-bg pattern="gradient" color="{{ $rxg1 }}" color2="{{ $rxg2 }}" opacity="0.2" />
                        <div class="relative">
                        <div class="text-ink" style="font-size:var(--fs-xs);font-weight:700;letter-spacing:-.01em;padding-bottom:10px;">{{ $g['axis'] }}</div>
                        @foreach ($g['items'] as $it)
                            @php
                                $c = $sem[$it['state']] ?? 'var(--color-muted)';
                                $mk = ['ok' => '✓', 'warn' => '!', 'bad' => '✕'][$it['state']] ?? '·';
                                // tip 형식 "값 — 상태라벨" → 값과 상태 라벨 분리
                                $parts = explode(' — ', (string) ($it['tip'] ?? ''));
                                $stLabel = count($parts) > 1 ? array_pop($parts) : '';
                                $stVal = trim(implode(' — ', $parts));
                            @endphp
                            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;padding:9px 0;border-top:1px solid var(--color-hairline-soft);">
                                <div style="min-width:0;flex:1;">
                                    <div style="display:flex;align-items:center;gap:7px;">
                                        <span style="width:16px;height:16px;flex:none;border-radius:5px;display:grid;place-items:center;font-size:10px;font-weight:800;color:{{ $c }};background:color-mix(in srgb,{{ $c }} 14%,var(--color-canvas));">{{ $mk }}</span>
                                        <span class="text-body" style="font-size:var(--fs-xs);font-weight:600;">{{ $it['name'] }}</span>
                                    </div>
                                    @if ($stVal !== '')
                                        <div class="text-muted" style="font-size:var(--fs-xs);margin-top:3px;padding-left:23px;line-height:1.4;">{{ $stVal }}</div>
                                    @endif
                                </div>
                                @if ($stLabel !== '')
                                    <span style="flex:none;font-size:11px;font-weight:700;color:{{ $c }};white-space:nowrap;margin-top:1px;">{{ $stLabel }}</span>
                                @endif
                            </div>
                        @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <p class="text-muted-soft text-center" style="font-size:11px;line-height:1.6;">
        셀러력·순위는 관측 신호 기반 <b>자체 추정치</b>이며 네이버 공식 지수가 아닙니다 · 재분석은 크롬 확장에서 상품 페이지를 열고 진행하세요.
    </p>

    {{-- 캡처 전용 하단 브랜딩 --}}
    <div class="sp-cap-only" style="flex-direction:column;align-items:center;gap:4px;margin-top:18px;border-top:1px solid var(--color-hairline);padding-top:14px;text-align:center;">
        <span class="text-muted" style="font-size:var(--fs-xs);">이 리포트는 <b class="text-ink">랭크프리</b>에서 셀러력 진단으로 생성되었습니다.</span>
        <span class="text-muted" style="font-size:var(--fs-xs);">네이버에서 <b class="text-ink">랭크프리</b>를 검색 방문하고 무료로 내 상품을 진단해보세요.</span>
    </div>
</div>

@php
    $spAxes = array_map(fn ($x) => ['key' => $x['key'], 'mine' => $x['mine'], 'avg' => $x['avg']], $r['axes'] ?? []);
@endphp
<script>
(function () {
    var AXES = @json($spAxes);
    var cv = document.getElementById('spRadar');
    if (!cv || !AXES.length) return;
    var ctx = cv.getContext('2d'), dpr = window.devicePixelRatio || 1;
    cv.width = 440 * dpr; cv.height = 440 * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    var css = getComputedStyle(document.documentElement);
    function tok(n) { return css.getPropertyValue(n).trim(); }
    var cx = 220, cy = 220, R = 160, n = AXES.length;
    var line = tok('--color-hairline'), faint = tok('--color-muted-soft'), brand = tok('--color-accent'), muted = tok('--color-muted');
    var ang = i => -Math.PI/2 + i * 2*Math.PI/n;
    function draw(p) {
        ctx.clearRect(0, 0, 440, 440);
        for (var r = 1; r <= 4; r++) { ctx.beginPath();
            for (var i = 0; i <= n; i++) { var a = ang(i % n), rr = R*r/4, x = cx+rr*Math.cos(a), y = cy+rr*Math.sin(a); i ? ctx.lineTo(x,y) : ctx.moveTo(x,y); }
            ctx.strokeStyle = line; ctx.lineWidth = 1; ctx.stroke(); }
        ctx.fillStyle = muted; ctx.font = '600 13px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        for (var i = 0; i < n; i++) { var a = ang(i);
            ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx+R*Math.cos(a), cy+R*Math.sin(a)); ctx.strokeStyle = line; ctx.lineWidth = 1; ctx.stroke();
            ctx.fillText(AXES[i].key, cx+(R+24)*Math.cos(a), cy+(R+24)*Math.sin(a)); }
        function poly(vals, stroke, fill, dash) { ctx.beginPath();
            for (var i = 0; i <= n; i++) { var idx = i%n, a = ang(idx), v = vals[idx]/100*p, x = cx+R*v*Math.cos(a), y = cy+R*v*Math.sin(a); i ? ctx.lineTo(x,y) : ctx.moveTo(x,y); }
            if (fill) { ctx.fillStyle = fill; ctx.fill(); } ctx.strokeStyle = stroke; ctx.lineWidth = 2; ctx.setLineDash(dash||[]); ctx.stroke(); ctx.setLineDash([]); }
        poly(AXES.map(a => a.avg), faint, null, [5,4]);
        poly(AXES.map(a => a.mine), brand, 'color-mix(in srgb,' + brand + ' 18%,transparent)', null);
        for (var i = 0; i < n; i++) { var a = ang(i), v = AXES[i].mine/100*p, x = cx+R*v*Math.cos(a), y = cy+R*v*Math.sin(a);
            ctx.beginPath(); ctx.arc(x, y, 3.5, 0, 2*Math.PI); ctx.fillStyle = brand; ctx.fill(); }
    }
    var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) { draw(1); return; }
    var t0 = performance.now();
    (function step(t) { var p = Math.min(1, (t - t0)/800), e = 1 - Math.pow(1 - p, 3); draw(e); if (p < 1) requestAnimationFrame(step); })(t0);
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/html-to-image@1.11.11/dist/html-to-image.js"></script>
<script>
// 리포트를 화면 그대로 PNG 저장 — 버튼에서 spSaveImage(this) 호출 (캔버스·도넛 포함 2배 해상도)
window.spSaveImage = function (btn) {
    var node = document.getElementById('sp-report');
    if (!node || !window.htmlToImage) return;
    var orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '저장 중…'; }
    node.classList.add('sp-capturing'); // 상하단 랭크프리 브랜딩 노출
    htmlToImage.toPng(node, {
        pixelRatio: 2,
        backgroundColor: getComputedStyle(document.body).backgroundColor || 'white'
    }).then(function (dataUrl) {
        var a = document.createElement('a');
        a.href = dataUrl;
        a.download = '랭크프리-셀러력-' + @json($r['keyword'] ?? $a->keyword) + '.png';
        a.click();
    }).catch(function () {
        alert('이미지 생성에 실패했습니다. 잠시 후 다시 시도하세요.');
    }).finally(function () {
        node.classList.remove('sp-capturing');
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
    });
};
</script>
