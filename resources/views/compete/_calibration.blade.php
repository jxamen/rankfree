{{-- 실측 캘리브레이션 — 내 매장 스마트플레이스 조회수(PV) ↔ N2/순위 추정치. $cal = CompeteController::buildCalibration(). --}}
@php $st = $cal['state'] ?? 'unlinked'; @endphp

@if ($st === 'unlinked')
    <div class="card p-4 mb-4 flex items-center justify-between flex-wrap gap-2">
        <div class="text-muted" style="font-size:var(--fs-xs);">
            🔗 <b class="text-ink">실측 검증</b> — 이 매장의 스마트플레이스를 연결하면 <b class="text-ink">실제 조회수</b>와 N2 추정치를 비교(캘리브레이션)할 수 있습니다.
        </div>
        <a href="{{ route('console.smartplace') }}" class="btn btn-secondary btn-sm">스마트플레이스 연결</a>
    </div>

@elseif ($st === 'no_pv')
    <div class="card p-4 mb-4 text-muted" style="font-size:var(--fs-xs);">
        🔗 스마트플레이스 계정 <b class="text-ink">{{ $cal['label'] ?? '' }}</b> 연결됨 — 아직 일자별 조회수 데이터가 없습니다.
        <a href="{{ route('console.smartplace') }}" class="text-accent">스마트플레이스</a>에서 <b>[수집]</b>을 실행하면 실측 비교가 표시됩니다.
    </div>

@else
    @php
        $ov = (int) ($cal['overlap_n'] ?? 0);
        $cN2 = $cal['corr_n2_pv'] ?? null;
        $cRk = $cal['corr_rank_pv'] ?? null;
        $fmtC = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
        // N2↔조회수 상관 해석
        $iN2 = $cN2 === null
            ? ['t' => '데이터 부족', 'c' => 'var(--color-muted)', 's' => "겹치는 분석일 {$ov}일 · 최소 3일 필요"]
            : ($cN2 >= 0.6 ? ['t' => '강한 양의 상관', 'c' => 'var(--color-success)', 's' => 'N2가 실제 조회수를 잘 따라감 ✅']
            : ($cN2 >= 0.3 ? ['t' => '약한 양의 상관', 'c' => 'var(--color-accent)', 's' => '방향은 맞으나 보강 여지 있음']
            : ($cN2 > -0.3 ? ['t' => '상관 약함', 'c' => 'var(--color-muted)', 's' => '가중치 재검토 필요']
            : ['t' => '역상관', 'c' => 'var(--color-error)', 's' => '점검 필요 — 신호 방향 확인'])));
        $iRk = $cRk === null ? ['t' => '—', 's' => "겹치는 분석일 {$ov}일 · 최소 3일 필요"]
            : ($cRk <= -0.3 ? ['t' => $fmtC($cRk), 's' => '정상 — 상위일수록 조회 많음'] : ['t' => $fmtC($cRk), 's' => '기대와 다름(보통 음수)']);

        // 오버레이 차트 데이터(최근 30일)
        $rows = array_slice($cal['rows'] ?? [], -30);
        $n = count($rows);
        $W = 720; $H = 96;
        $maxPv = 1;
        foreach ($rows as $r) { if ($r['pv'] !== null) { $maxPv = max($maxPv, (float) $r['pv']); } }
        $bw = $n ? $W / $n : $W;
        $step = max(1, (int) ceil($n / 8));
        $linePts = [];
        foreach ($rows as $i => $r) {
            if ($r['n2'] !== null) {
                $linePts[] = round($i * $bw + $bw / 2, 1) . ',' . round($H - (float) $r['n2'] / 100 * $H, 1);
            }
        }
    @endphp
    <div class="card p-4 mb-4">
        <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
            <div>
                <span class="text-ink font-medium" style="font-size:var(--fs-xs);">실측 검증 · 스마트플레이스 조회수 ↔ N2</span>
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">
                    {{ $cal['label'] ?? '' }}
                    @if (!empty($cal['period'])) · {{ $cal['period'][0] ?? '' }}~{{ $cal['period'][1] ?? '' }}@endif
                    @if (!empty($cal['collected_at'])) · 수집 {{ $cal['collected_at'] }}@endif
                </span>
            </div>
            <a href="{{ route('console.smartplace') }}" class="text-accent" style="font-size:var(--fs-xs);">기간 재수집 →</a>
        </div>

        {{-- 상관·기초 통계 --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
            <div class="p-3 rounded-lg" style="background:var(--color-surface-strong);">
                <div class="text-muted" style="font-size:var(--fs-xs);">N2 ↔ 조회수 상관</div>
                <div class="font-display" style="font-size:var(--fs-xl);color:{{ $iN2['c'] }};">{{ $fmtC($cN2) }}</div>
                <div style="font-size:var(--fs-xs);color:{{ $iN2['c'] }};">{{ $iN2['t'] }}</div>
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $iN2['s'] }}</div>
            </div>
            <div class="p-3 rounded-lg" style="background:var(--color-surface-strong);">
                <div class="text-muted" style="font-size:var(--fs-xs);">순위 ↔ 조회수 상관</div>
                <div class="font-display text-ink" style="font-size:var(--fs-xl);">{{ $iRk['t'] }}</div>
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $iRk['s'] }}</div>
            </div>
            <div class="p-3 rounded-lg" style="background:var(--color-surface-strong);">
                <div class="text-muted" style="font-size:var(--fs-xs);">기간 조회수</div>
                <div class="font-display text-ink" style="font-size:var(--fs-xl);">{{ number_format((int) ($cal['pv_total'] ?? 0)) }}</div>
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">일평균 {{ $cal['pv_avg'] !== null ? number_format((float) $cal['pv_avg'], 1) : '—' }}회 · {{ (int) ($cal['pv_days'] ?? 0) }}일</div>
            </div>
            <div class="p-3 rounded-lg" style="background:var(--color-surface-strong);">
                <div class="text-muted" style="font-size:var(--fs-xs);">겹치는 분석일</div>
                <div class="font-display text-ink" style="font-size:var(--fs-xl);">{{ $ov }}일</div>
                <div class="text-muted-soft" style="font-size:var(--fs-xs);">N2 평균 {{ $cal['n2_avg'] !== null ? $cal['n2_avg'] : '—' }}</div>
            </div>
        </div>

        {{-- 오버레이: PV 막대 + N2 선(0~100) --}}
        @if ($n)
        <div class="overflow-x-auto">
            <svg viewBox="0 0 {{ $W }} {{ $H + 24 }}" style="width:100%;max-width:680px;min-width:460px;height:auto;">
                @foreach ($rows as $i => $r)
                    @if ($r['pv'] !== null)
                        @php $bh = (float) $r['pv'] / $maxPv * $H; @endphp
                        <rect x="{{ round($i * $bw + $bw * 0.2, 1) }}" y="{{ round($H - $bh, 1) }}" width="{{ round($bw * 0.6, 1) }}" height="{{ round($bh, 1) }}" rx="1.5" fill="var(--color-accent)" opacity="0.55"/>
                    @endif
                    @if ($i % $step === 0)
                        <text x="{{ round($i * $bw + $bw / 2, 1) }}" y="{{ $H + 16 }}" text-anchor="middle" style="font-size:var(--fs-xs);fill:var(--color-muted);">{{ substr($r['ymd'], 5) }}</text>
                    @endif
                @endforeach
                @if (count($linePts) >= 2)
                    <polyline points="{{ implode(' ', $linePts) }}" fill="none" stroke="var(--color-primary)" stroke-width="2"/>
                @endif
                @foreach ($rows as $i => $r)
                    @if ($r['n2'] !== null)
                        <circle cx="{{ round($i * $bw + $bw / 2, 1) }}" cy="{{ round($H - (float) $r['n2'] / 100 * $H, 1) }}" r="2.5" fill="var(--color-primary)"/>
                    @endif
                @endforeach
            </svg>
        </div>
        <div class="flex items-center gap-4 mt-1" style="font-size:var(--fs-xs);">
            <span class="text-muted"><span style="display:inline-block;width:10px;height:10px;background:var(--color-accent);opacity:.55;border-radius:2px;vertical-align:middle;"></span> 실제 조회수(PV)</span>
            <span class="text-muted"><span style="display:inline-block;width:10px;height:2px;background:var(--color-primary);vertical-align:middle;"></span> N2 관련성(0~100)</span>
        </div>
        @endif

        <p class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">
            ※ N2는 느리게 변하는 SEO 인기도 추정치, 조회수는 요일·이벤트로 변동이 큽니다. 상관은 <b>분석일이 누적될수록</b> 신뢰도가 올라갑니다(현재 {{ $ov }}일). 강한 양의 상관이면 N2 가중치가 실측을 잘 반영하는 것이고, 약하면 D6·D9 등 가중치를 조정할 근거가 됩니다.
        </p>
    </div>
@endif
