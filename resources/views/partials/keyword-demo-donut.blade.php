{{--
    성별 · 이슈성 · 정보성/상업성 (3-col 도넛) — 키워드 분석(console.keyword) + 시장 분석(console.market) 공용.
    입력:
      $d    = KeywordAnalysisPresenter::detailModel() (has_demo / gender / issue 포함)
      $comm = KeywordAnalysisPresenter::commercial() (commercial_pct / info_pct) — null 허용
    색은 전부 디자인 토큰(하드코딩 hex 금지).
--}}
@php
    $comm = $comm ?? ['commercial_pct' => null, 'info_pct' => null];
    $cAccent = 'var(--color-accent)';
    $cGreen = 'var(--color-success)';
    $cPink = 'var(--color-badge-pink)';
    $cOrange = 'var(--color-badge-orange)';
@endphp
@if (! empty($d['has_demo']) || $comm['commercial_pct'] !== null)
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        {{-- 성별 검색 비율 --}}
        <div class="card p-5 text-center">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">성별 검색 비율</div>
            @if (! empty($d['has_demo']) && ($d['gender']['female_pct'] + $d['gender']['male_pct']) > 0)
                <div class="flex items-center justify-center">
                    @include('partials.donut', ['segs' => [
                        ['label' => '여성', 'value' => $d['gender']['female_pct'], 'color' => $cPink],
                        ['label' => '남성', 'value' => $d['gender']['male_pct'], 'color' => $cAccent],
                    ]])
                </div>
                <div class="flex items-center justify-center gap-4 mt-3">
                    <span style="font-size:var(--fs-xs);"><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $cPink }};margin-right:4px;"></i>여성 <b class="text-ink">{{ $d['gender']['female_pct'] }}%</b></span>
                    <span style="font-size:var(--fs-xs);"><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $cAccent }};margin-right:4px;"></i>남성 <b class="text-ink">{{ $d['gender']['male_pct'] }}%</b></span>
                </div>
            @else
                <p class="text-muted-soft" style="font-size:var(--fs-xs);padding:36px 0;">데이터 없음</p>
            @endif
        </div>

        {{-- 이슈성(시의성) — 12개월 트렌드 급등 자체 추정 --}}
        <div class="card p-5 text-center">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">이슈성 <span class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:400;">시의성 추정</span></div>
            @if (! empty($d['issue']))
                @php $iss = $d['issue']; @endphp
                <div class="flex items-center justify-center">
                    @include('partials.donut', ['segs' => [
                        ['label' => '이슈성', 'value' => $iss['pct'], 'color' => $cOrange],
                        ['label' => '기타', 'value' => max(0, 100 - $iss['pct']), 'color' => 'var(--color-surface-strong)'],
                    ], 'center' => $iss['pct'].'%', 'centerColor' => $cOrange])
                </div>
                <div style="font-size:var(--fs-xs);color:{{ $iss['color'] }};font-weight:600;margin-top:8px;">{{ $iss['label'] }}</div>
            @else
                <p class="text-muted-soft" style="font-size:var(--fs-xs);padding:36px 0;">트렌드 데이터 부족</p>
            @endif
        </div>

        {{-- 정보성 / 상업성 --}}
        <div class="card p-5 text-center">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">정보성 / 상업성</div>
            @if ($comm['commercial_pct'] !== null)
                <div class="flex items-center justify-center">
                    @include('partials.donut', ['segs' => [
                        ['label' => '상업성', 'value' => $comm['commercial_pct'], 'color' => $cAccent],
                        ['label' => '정보성', 'value' => $comm['info_pct'], 'color' => $cGreen],
                    ]])
                </div>
                <div class="flex items-center justify-center gap-4 mt-3">
                    <span style="font-size:var(--fs-xs);"><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $cAccent }};margin-right:4px;"></i>상업성 <b class="text-ink">{{ $comm['commercial_pct'] }}%</b></span>
                    <span style="font-size:var(--fs-xs);"><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $cGreen }};margin-right:4px;"></i>정보성 <b class="text-ink">{{ $comm['info_pct'] }}%</b></span>
                </div>
            @else
                <p class="text-muted-soft" style="font-size:var(--fs-xs);padding:36px 0;">경쟁강도 데이터 부족</p>
            @endif
        </div>
    </div>
@endif
