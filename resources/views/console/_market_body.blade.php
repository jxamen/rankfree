{{--
    시장 분석 본문 — 콘솔 상세(market-show)와 공개 공유(market-share) 공용.
    입력: $a(MarketAnalysis), $weekday(요일 데이터랩|null), $shareUrl(공유 URL|null), $public(공개 뷰 여부).
    섹션 순서: 헤더 → 연관키워드 → 핵심지표 → 시장구성 → 매출상위 → 키워드분석 → 키워드상세.
--}}
@php
    $public = $public ?? false;
    $won = fn ($n) => $n >= 100000000
        ? number_format($n / 100000000, 1).'억'
        : ($n >= 10000 ? number_format($n / 10000).'만' : number_format($n));
    $snap = (array) $a->snapshot;
    $kd = (array) ($snap['keyword_data'] ?? []);
    $tops = (array) ($snap['top_products'] ?? []);
    $tags = (array) ($snap['related_tags'] ?? []);
    // 키워드 상세 모델 — 인사이트(맨 위)와 상세 차트(아래)에서 나눠 사용
    $dm = \App\Domain\Keyword\KeywordAnalysisPresenter::detailModel((array) ($kd['detail'] ?? []));
    // 시즌성 — 시즌 키워드면 "성수기 2개월 전부터 미리 순위작업" 리드 유도 콜아웃 표시
    $season = $dm['season'] ?? null;
    $mlabel = fn ($arr) => implode('·', array_map(fn ($m) => $m.'월', $arr));
    // 매출 상위 상품 제목 SEO — 점수·사용 키워드·추천 상품명(ShoppingTitleSeoAnalyzer)
    $seoData = ($a->keyword && $tops)
        ? app(\App\Domain\Shopping\ShoppingTitleSeoAnalyzer::class)->analyze($a->keyword, array_map(fn ($p) => ['title' => $p['title'] ?? '', 'rank' => 0, 'is_ad' => false], array_values($tops)))
        : null;
    $seoByTitle = [];
    if ($seoData) {
        foreach ($seoData['products'] as $sp) {
            $seoByTitle[$sp['title']] = $sp;
        }
    }
@endphp

{{-- 검색 키워드 + 쇼핑 검색 --}}
<div class="flex items-center gap-3 flex-wrap mb-1">
    <span class="font-display text-ink" style="font-size:var(--fs-lg);">{{ $a->keyword }}</span>
    {{-- 네이버 쇼핑 검색을 새 창으로 (N=네이버 브랜드색은 예외적 인라인) --}}
    <a href="https://search.shopping.naver.com/search/all?query={{ urlencode($a->keyword) }}" target="_blank" rel="noopener"
       class="btn btn-secondary btn-sm inline-flex items-center gap-1" title="「{{ $a->keyword }}」 네이버 쇼핑 검색 (새 창)">
        <span style="color:#03c75a;font-weight:800;font-size:var(--fs-xs);">N</span> 쇼핑 검색
    </a>
    @unless ($public)
        <button type="button" class="btn btn-secondary btn-sm" onclick="rfCopyShare(this, @js($shareUrl ?? ''))" title="비로그인 공개 공유 링크 복사">🔗 공유</button>
    @endunless
</div>
<div class="text-muted-soft mb-1" style="font-size:var(--fs-xs);">네이버 검색광고 keywordstool 기준 · 등급·상업성·포화는 자체 추정치</div>
<div class="text-muted mb-5" style="font-size:var(--fs-xs);">
    {{ $a->created_at->format('Y-m-d H:i') }} 분석 · 상위 {{ number_format($a->item_count) }}개 상품 기준
    {{ $a->include_ads ? '(광고 포함)' : '(광고 제외)' }} · 전체 상품 {{ number_format($a->total_count) }}개
</div>

{{-- 키워드 인사이트 --}}
@if ($dm['has_detail'])
    @include('partials.keyword-detail', ['d' => $dm, 'only' => ['insights'], 'weekday' => $weekday ?? null])
@endif

{{-- 시즌 타이밍 콜아웃 — 시즌 키워드면 "성수기 2개월 전부터 미리 순위작업" 강조 + 리드 유도 --}}
@if ($season && $season['is_seasonal'])
    @php
        $peakStr = $mlabel($season['peak_months']);
        $prepStr = $mlabel($season['prep_months']);
        $peakSet = $season['peak_months'];
        $prepSet = $season['prep_months'];
    @endphp
    <div class="card p-6 mb-4 relative overflow-hidden" style="border-color:color-mix(in srgb, var(--color-badge-orange) 45%, var(--color-hairline));">
        <x-card-bg pattern="gradient" color="var(--color-badge-orange)" color2="var(--color-error)" opacity="0.14" />
        <x-card-bg pattern="rings" color="var(--color-badge-orange)" opacity="0.28" mask="right" />
        <div class="relative">
            <div class="flex items-center gap-2 mb-2 flex-wrap">
                <span class="w-9 h-9 rounded-full flex items-center justify-center flex-none" style="background:color-mix(in srgb, var(--color-badge-orange) 16%, transparent);color:var(--color-badge-orange);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                </span>
                <span class="text-ink font-semibold" style="font-size:var(--fs-md);">지금이 준비할 때 — 시즌 키워드입니다</span>
                <span class="badge" style="font-size:var(--fs-xs);padding:2px 9px;background:color-mix(in srgb, {{ $season['strength_color'] }} 14%, transparent);color:{{ $season['strength_color'] }};">시즌성 {{ $season['strength_label'] }}</span>
            </div>
            <p class="text-body" style="font-size:var(--fs-sm);line-height:1.8;max-width:760px;">
                ‘<b class="text-ink">{{ $a->keyword }}</b>’은 {{ $season['strength_word'] }} 키워드로, 검색은 <b class="text-ink">{{ $peakStr }}</b>에 몰립니다.
                쇼핑·플레이스 <b class="text-ink">상위 노출은 하루아침에 되지 않습니다</b> — 통상 순위가 자리 잡는 데 2개월 안팎이 걸립니다.
                그래서 성수기 <b class="text-ink">{{ $season['lead_months'] }}개월 전인 {{ $prepStr }}부터</b> 미리 작업을 시작해
                <b style="color:var(--color-badge-orange);">성수기 전에 상위권을 선점</b>해야 그 수요를 매출로 잡을 수 있습니다.
            </p>

            {{-- 12개월 준비→성수기 타임라인 --}}
            <div class="flex gap-1 mt-4" style="max-width:560px;">
                @for ($m = 1; $m <= 12; $m++)
                    @php
                        $isPeak = in_array($m, $peakSet, true);
                        $isPrep = in_array($m, $prepSet, true);
                        $bg = $isPeak ? 'var(--color-badge-orange)' : ($isPrep ? 'var(--color-accent)' : 'var(--color-surface-strong)');
                        $fg = ($isPeak || $isPrep) ? 'var(--color-on-primary)' : 'var(--color-muted)';
                    @endphp
                    <div class="flex-1 text-center" title="{{ $m }}월{{ $isPeak ? ' · 성수기' : ($isPrep ? ' · 준비 시작' : '') }}"
                         style="border-radius:var(--radius-sm);padding:6px 0;font-size:var(--fs-xs);font-weight:600;background:{{ $bg }};color:{{ $fg }};">{{ $m }}</div>
                @endfor
            </div>
            <div class="flex items-center gap-4 mt-2" style="font-size:var(--fs-xs);color:var(--color-muted);">
                <span class="inline-flex items-center gap-1.5"><span style="width:10px;height:10px;border-radius:3px;background:var(--color-accent);"></span>준비 시작 {{ $prepStr }}</span>
                <span class="inline-flex items-center gap-1.5"><span style="width:10px;height:10px;border-radius:3px;background:var(--color-badge-orange);"></span>성수기 {{ $peakStr }}</span>
            </div>

            <div class="mt-5 flex items-center gap-3 flex-wrap">
                <button type="button" class="btn btn-primary rf-lead-open"
                        data-source="market_seasonal" data-interest="랭킹 최적화" data-keyword="{{ $a->keyword }}"
                        data-peak="{{ implode(',', $season['peak_months']) }}" data-prep="{{ implode(',', $season['prep_months']) }}" data-strength="{{ $season['strength_label'] }}">
                    랭킹 최적화 문의
                </button>
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">성수기까지 남은 시간이 곧 순위 경쟁력입니다. 지금 상담받고 미리 시작하세요.</span>
            </div>
        </div>
    </div>
@endif

{{-- 추천 상품 — 이 시장에 진입한다면 (키워드 인사이트 아래) --}}
<div class="card p-6 mb-4">
    <div class="text-ink font-semibold mb-2" style="font-size:var(--fs-sm);">이 시장에 진입한다면 — 추천 상품</div>
    <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">진입 초기에 찜·리뷰·순위 신호를 빠르게 확보할수록 상위 노출이 앞당겨집니다.</p>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        @foreach ([
            ['찜 부스팅', '실사용자 찜(위시리스트)을 늘려 인기 신호와 알고리즘 가점을 확보합니다.', 'var(--color-badge-pink)', 'var(--color-badge-orange)', 'heart'],
            ['리뷰 확보 · 체험단', '체험단 매칭으로 포토 리뷰를 쌓아 신뢰도와 구매 전환율을 높입니다.', 'var(--color-badge-emerald)', 'var(--color-accent)', 'pen'],
            ['랭킹 최적화', '검색 유입·클릭 신호를 개선해 쇼핑 검색 순위를 끌어올립니다.', 'var(--color-accent)', 'var(--color-badge-violet)', 'trend'],
        ] as [$mpName, $mpDesc, $mpC1, $mpC2, $mpIcon])
            <div class="card p-8 flex flex-col relative overflow-hidden">
                <x-card-bg pattern="gradient" color="{{ $mpC1 }}" color2="{{ $mpC2 }}" opacity="0.22" />
                <x-card-bg pattern="dots" color="{{ $mpC1 }}" opacity="0.28" />
                <div class="relative flex flex-col flex-1">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background:color-mix(in srgb, {{ $mpC1 }} 15%, transparent);color:{{ $mpC1 }};">
                        @if ($mpIcon === 'heart')
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                        @elseif ($mpIcon === 'pen')
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        @else
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                        @endif
                    </div>
                    <h3 class="mt-4 text-ink font-semibold" style="font-size:var(--fs-base);">{{ $mpName }}</h3>
                    <p class="text-body mt-2 flex-1" style="font-size:var(--fs-sm);line-height:1.65;">{{ $mpDesc }}</p>
                    <button type="button" class="btn btn-secondary btn-sm mt-5 rf-lead-open" style="align-self:flex-start;"
                            data-source="market" data-interest="{{ $mpName }}" data-keyword="{{ $a->keyword }}">상담 문의</button>
                </div>
            </div>
        @endforeach
    </div>
</div>


{{-- 연관 키워드 --}}
@if ($tags)
    <div class="card p-5 mb-6 relative overflow-hidden">
        {{-- 링 패턴 — 홈페이지 프로 카드풍 --}}
        <x-card-bg pattern="gradient" color="var(--color-accent)" color2="var(--color-badge-violet)" opacity="0.16" />
        <x-card-bg pattern="rings" color="var(--color-accent)" opacity="0.3" />
        <div class="relative">
            <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">연관 키워드 <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ count($tags) }}개</span></div>
            <div class="flex flex-wrap gap-2">
                @foreach ($tags as $tag)
                    <span class="badge">{{ $tag }}</span>
                @endforeach
            </div>
        </div>
    </div>
@endif

{{-- 핵심 지표 --}}
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
    @foreach ([
        ['6개월 시장 규모', $won($a->revenue_6m).'원'],
        ['월평균 매출', $won((int) round($a->revenue_6m / 6)).'원'],
        ['6개월 판매량', number_format($a->sales_6m).'건'],
        ['평균 판매가', number_format($a->avg_price).'원'],
        ['중앙값', number_format($a->median_price).'원'],
        ['상위 10개 점유율', number_format($a->top10_share, 1).'%'],
    ] as [$label, $value])
        <div class="card p-4">
            <div class="text-muted" style="font-size:var(--fs-xs);">{{ $label }}</div>
            <div class="text-ink font-display mt-1" style="font-size:var(--fs-lg);">{{ $value }}</div>
        </div>
    @endforeach
</div>

{{-- 시장 구성 — 1등 카테고리 · 몰 등급 · 카테고리 (키워드 분석 위) --}}
@php
    $mallGrades = array_values((array) ($snap['mall_grades'] ?? []));
    $topCats = array_values((array) ($snap['top_categories'] ?? []));
    $leadCat = $snap['top_product_category'] ?? '';
    $itemCount = max(1, (int) $a->item_count);
    $premium = 0;
    foreach ($mallGrades as $g) {
        if (in_array($g[0] ?? '', ['프리미엄', '빅파워', '브랜드스토어'], true)) $premium += (int) ($g[1] ?? 0);
    }
@endphp
@if ($mallGrades || $topCats || $leadCat)
    @if ($leadCat)
        <div class="card p-4 mb-6 flex items-center justify-between gap-3 flex-wrap">
            <span class="text-muted" style="font-size:var(--fs-xs);">1등 상품 카테고리 <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">상위 {{ number_format($a->item_count) }}개 기준</span></span>
            <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $leadCat }}</span>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        @if ($mallGrades)
            <div class="card p-5 relative overflow-hidden">
                {{-- 그리드 패턴 — 홈페이지 CTA 밴드풍 --}}
                <x-card-bg pattern="grid" color="var(--color-ink)" opacity="0.07" />
                <div class="relative">
                <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-xs);">
                    판매처 등급 분포
                    @if ($premium)<span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">상위등급 {{ $premium }}개</span>@endif
                </div>
                @foreach ($mallGrades as $g)
                    @php $cnt = (int) ($g[1] ?? 0); $pct = round($cnt / $itemCount * 100); @endphp
                    <div class="flex items-center gap-2" style="margin:7px 0;font-size:var(--fs-xs);">
                        <span class="text-muted" style="width:92px;">{{ $g[0] ?? '' }}</span>
                        <span class="text-body font-semibold text-right" style="width:86px;">{{ $cnt }}개 · {{ $pct }}%</span>
                        <span style="flex:1;height:10px;background:var(--color-surface-strong);border-radius:5px;overflow:hidden;">
                            <span style="display:block;height:100%;width:{{ max(2, $pct) }}%;background:var(--color-accent);border-radius:5px;"></span>
                        </span>
                    </div>
                @endforeach
                </div>
            </div>
        @endif

        @if ($topCats)
            <div class="card p-5 relative overflow-hidden">
                {{-- 도트 단독 — 홈페이지 목업 카드풍 --}}
                <x-card-bg pattern="dots" color="var(--color-badge-violet)" opacity="0.3" />
                <div class="relative">
                <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-xs);">주요 카테고리</div>
                @foreach ($topCats as $c)
                    <div class="flex items-center justify-between gap-2" style="margin:6px 0;font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);padding-top:7px;">
                        <span class="text-body truncate">{{ $c[0] ?? '' }}</span>
                        <span class="text-muted font-semibold" style="white-space:nowrap;">{{ (int) ($c[1] ?? 0) }}개</span>
                    </div>
                @endforeach
                </div>
            </div>
        @endif
    </div>
@endif

{{-- 추천 상품명 (매출 상위 위) — 매출 상위 공통단어 조합 --}}
@if ($seoData && !empty($seoData['suggested_titles']))
<div class="card mb-4 p-5">
    <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">추천 상품명 <span class="badge" style="font-size:var(--fs-xs);">매출 상위 공통단어 조합</span></div>
    <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">매출 상위 상품 제목에서 자주 쓰인 단어로 조합한 제안입니다.</p>
    @foreach ($seoData['suggested_titles'] as $st)
        <div class="flex items-center justify-between gap-3 py-2" style="border-top:1px solid var(--color-hairline-soft);">
            <span class="text-ink" style="font-size:var(--fs-sm);">{{ $st }}</span>
            <button type="button" class="btn btn-secondary btn-sm" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent.trim());const o=this.textContent;this.textContent='복사됨';setTimeout(()=>this.textContent=o,1000)">복사</button>
        </div>
    @endforeach
</div>
@endif

{{-- 매출 상위 상품 (키워드 분석 위) --}}
<div class="card overflow-hidden mb-6">
    <div class="px-5 py-4 text-ink font-semibold" style="font-size:var(--fs-xs);">매출 상위 상품</div>
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:760px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);">
                    <th class="text-left px-5 py-2.5 font-semibold">#</th>
                    <th class="text-left px-3 py-2.5 font-semibold">상품</th>
                    <th class="text-right px-3 py-2.5 font-semibold">판매가</th>
                    <th class="text-right px-3 py-2.5 font-semibold">6개월 판매량</th>
                    <th class="text-right px-5 py-2.5 font-semibold">6개월 매출</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tops as $i => $p)
                    @php
                        $rev = isset($p['revenue6m']) && $p['revenue6m'] !== null
                            ? (int) $p['revenue6m']
                            : (int) ($p['purchase6m'] ?? 0) * (int) ($p['price'] ?? 0);
                    @endphp
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-5 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $i + 1 }}</td>
                        <td class="px-3 py-3">
                            @if (!empty($p['link']))
                                <a href="{{ $p['link'] }}" target="_blank" rel="noopener" class="text-ink hover:underline" style="font-size:var(--fs-xs);">{{ $p['title'] ?? '' }}</a>
                            @else
                                <span class="text-ink" style="font-size:var(--fs-xs);">{{ $p['title'] ?? '' }}</span>
                            @endif
                            <div class="text-muted-soft" style="font-size:var(--fs-xs);">
                                {{ !empty($p['isCatalog']) ? '가격비교'.(!empty($p['mallCount']) ? ' · '.$p['mallCount'].'몰' : '') : ($p['mallName'] ?? '') }}
                            </div>
                            @php $sseo = $seoByTitle[$p['title'] ?? ''] ?? null; @endphp
                            @if ($sseo)
                                <div class="flex items-center gap-2 flex-wrap mt-1" style="font-size:11px;">
                                    <span style="font-weight:700;color:{{ $sseo['score'] >= 80 ? 'var(--color-success)' : ($sseo['score'] >= 60 ? 'var(--color-accent)' : 'var(--color-error)') }};">제목점수 {{ $sseo['score'] }}</span>
                                    @if (!empty($sseo['used_keywords']))
                                        <span class="text-muted">키워드 {{ implode('·', $sseo['used_keywords']) }}</span>
                                        <button type="button" class="btn btn-secondary" style="padding:1px 7px;font-size:11px;" onclick="navigator.clipboard.writeText('{{ implode(' ', $sseo['used_keywords']) }}');const o=this.textContent;this.textContent='복사됨';setTimeout(()=>this.textContent=o,1000)">복사</button>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format((int) ($p['price'] ?? 0)) }}</td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format((int) ($p['purchase6m'] ?? 0)) }}</td>
                        <td class="px-5 py-3 text-right text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $won($rev) }}원</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center" style="padding:40px;color:var(--color-muted);">상품 스냅샷이 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- 키워드 지표 — 각각 별도 카드 --}}
@if ($kd)
    @php
        $kdComp = $kd['comp_idx'] ?? null;
        $kdCompColor = match ($kdComp) {
            '높음' => 'var(--color-error)', '중간' => 'var(--color-warning)', '낮음' => 'var(--color-success)',
            default => 'var(--color-ink)',
        };
        $kdRatio = ($kd['monthly_total'] ?? 0) > 0 ? number_format($a->total_count / $kd['monthly_total'], 2) : '—';
    @endphp
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">키워드 분석 <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">네이버 검색광고 기준</span></div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div class="card p-5">
            <div class="text-muted" style="font-size:var(--fs-xs);">월간 검색량</div>
            <div class="font-display mt-1" style="font-size:var(--fs-xl);color:var(--color-ink);">{{ number_format((int) ($kd['monthly_total'] ?? 0)) }}</div>
        </div>
        <div class="card p-5">
            <div class="text-muted" style="font-size:var(--fs-xs);">PC / 모바일</div>
            <div class="font-display mt-1" style="font-size:var(--fs-xl);">
                <span style="color:var(--color-accent);">{{ number_format((int) ($kd['monthly_pc'] ?? 0)) }}</span>
                <span class="text-muted-soft" style="font-size:var(--fs-base);"> / </span>
                <span style="color:var(--color-badge-emerald);">{{ number_format((int) ($kd['monthly_mobile'] ?? 0)) }}</span>
            </div>
        </div>
        <div class="card p-5">
            <div class="text-muted" style="font-size:var(--fs-xs);">경쟁 강도</div>
            <div class="font-display mt-1" style="font-size:var(--fs-xl);color:{{ $kdCompColor }};">{{ $kdComp ?? '—' }}</div>
        </div>
        <div class="card p-5">
            <div class="text-muted" style="font-size:var(--fs-xs);">상품수/검색량</div>
            <div class="font-display mt-1" style="font-size:var(--fs-xl);color:var(--color-badge-violet);">{{ $kdRatio }}</div>
        </div>
    </div>
@endif

{{-- 키워드 상세 분석 — 성별·연령·트렌드·성별×연령·월별 (인사이트는 맨 위로 이동) --}}
@if ($dm['has_detail'])
    @include('partials.keyword-detail', ['d' => $dm, 'emptyNote' => '', 'weekday' => $weekday ?? null, 'only' => ['trend', 'genderage', 'device', 'month']])
    {{-- 성별 · 이슈성 · 정보성/상업성 요약 도넛 (키워드 분석 공용) --}}
    @include('partials.keyword-demo-donut', [
        'd' => $dm,
        'comm' => \App\Domain\Keyword\KeywordAnalysisPresenter::commercial($kd['comp_idx'] ?? null, (int) $a->total_count),
    ])
@elseif ($kd && ! $public)
    <div class="card-soft mb-6 px-4 py-3 text-muted" style="font-size:var(--fs-xs);">
        이 저장본에는 키워드 상세(검색량 추이·성별·연령) 데이터가 없습니다.
        확장 설정(⚙)에 <b>keyword_detail</b> scope가 있는 API 키를 등록하고 다시 분석하면 포함됩니다.
    </div>
@endif

<p class="text-muted-soft mt-2" style="font-size:var(--fs-xs);">* 구매건수는 네이버 노출값(최근 6개월), 시장 규모는 랭크프리 자체 추정치입니다.</p>

{{-- ── 리드(상담 문의) 모달 — 의존성 없음(공개 공유 페이지엔 Swal 미탑재). ────────── --}}
<div id="rf-lead-modal" class="hidden" style="position:fixed;inset:0;z-index:60;">
    <div class="rf-lead-close" style="position:absolute;inset:0;background:color-mix(in srgb, var(--color-ink) 45%, transparent);"></div>
    <div class="card" style="position:relative;width:min(460px, calc(100vw - 24px));margin:9vh auto 0;max-height:86vh;overflow-y:auto;box-shadow:var(--shadow-card);">
        <div class="flex items-center justify-between px-5 border-b border-hairline-soft" style="height:52px;">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">랭킹 최적화 상담 문의</span>
            <button type="button" class="btn btn-ghost btn-sm rf-lead-close" title="닫기">✕</button>
        </div>

        {{-- 폼 --}}
        <form id="rf-lead-form" method="POST" action="{{ route('lead.store') }}" class="p-5">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <input type="hidden" name="source" id="rf-lead-source" value="market">
            <input type="hidden" name="interest" id="rf-lead-interest" value="">
            <input type="hidden" name="keyword" id="rf-lead-keyword" value="">
            <input type="hidden" name="peak_months" id="rf-lead-peak" value="">
            <input type="hidden" name="prep_months" id="rf-lead-prep" value="">
            <input type="hidden" name="strength" id="rf-lead-strength" value="">
            {{-- 허니팟(봇 차단) — 사람에겐 숨김 --}}
            <div style="position:absolute;left:-9999px;top:auto;" aria-hidden="true">
                <label>회사명<input type="text" name="company" tabindex="-1" autocomplete="off"></label>
            </div>

            <div id="rf-lead-ctx" class="card-soft px-3 py-2.5 mb-4" style="font-size:var(--fs-xs);color:var(--color-muted);"></div>

            <div class="mb-3">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">성함</label>
                <input name="name" id="rf-lead-name" class="input" maxlength="80" required autocomplete="name" placeholder="홍길동">
            </div>
            <div class="mb-3">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">연락처</label>
                <input name="phone" class="input" maxlength="40" required autocomplete="tel" inputmode="tel" placeholder="010-1234-5678">
            </div>
            <div class="mb-4">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">메시지 <span class="text-muted-soft">(선택)</span></label>
                <textarea name="message" class="input" maxlength="1000" style="height:74px;padding-top:8px;resize:vertical;" placeholder="상품·목표 순위 등 원하시는 내용을 적어주세요."></textarea>
            </div>

            <div id="rf-lead-err" class="hidden mb-3" style="font-size:var(--fs-xs);color:var(--color-error);"></div>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-secondary btn-sm rf-lead-close">취소</button>
                <button type="submit" id="rf-lead-submit" class="btn btn-primary btn-sm">문의 남기기</button>
            </div>
            <p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">입력하신 연락처는 상담 목적에만 사용됩니다.</p>
        </form>

        {{-- 완료 상태 --}}
        <div id="rf-lead-done" class="hidden p-6 text-center">
            <div class="w-12 h-12 rounded-full flex items-center justify-center mx-auto" style="background:color-mix(in srgb, var(--color-success) 14%, transparent);color:var(--color-success);">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div class="text-ink font-semibold mt-3" style="font-size:var(--fs-sm);">문의가 접수되었습니다</div>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);">담당자가 빠르게 연락드리겠습니다. 시즌 키워드는 준비가 빠를수록 유리합니다.</p>
            <button type="button" class="btn btn-secondary btn-sm mt-4 rf-lead-close">닫기</button>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('rf-lead-modal');
    if (!modal) return;
    var form = document.getElementById('rf-lead-form');
    var done = document.getElementById('rf-lead-done');
    var errBox = document.getElementById('rf-lead-err');
    var ctx = document.getElementById('rf-lead-ctx');
    var submitBtn = document.getElementById('rf-lead-submit');

    function open(d) {
        // 폼 초기 상태로 복귀
        form.classList.remove('hidden');
        done.classList.add('hidden');
        errBox.classList.add('hidden');
        document.getElementById('rf-lead-source').value = d.source || 'market';
        document.getElementById('rf-lead-interest').value = d.interest || '';
        document.getElementById('rf-lead-keyword').value = d.keyword || '';
        document.getElementById('rf-lead-peak').value = d.peak || '';
        document.getElementById('rf-lead-prep').value = d.prep || '';
        document.getElementById('rf-lead-strength').value = d.strength || '';
        var line = '';
        if (d.keyword) line += '‘' + d.keyword + '’';
        if (d.interest) line += (line ? ' · ' : '') + d.interest;
        ctx.textContent = line ? (line + ' 관련 상담') : '랭킹 최적화 상담';
        ctx.style.display = line ? '' : 'none';
        modal.classList.remove('hidden');
        setTimeout(function () { var n = document.getElementById('rf-lead-name'); if (n) n.focus(); }, 50);
    }
    function close() { modal.classList.add('hidden'); }

    document.querySelectorAll('.rf-lead-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            open({
                source: btn.dataset.source, interest: btn.dataset.interest, keyword: btn.dataset.keyword,
                peak: btn.dataset.peak, prep: btn.dataset.prep, strength: btn.dataset.strength,
            });
        });
    });
    modal.querySelectorAll('.rf-lead-close').forEach(function (el) { el.addEventListener('click', close); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        errBox.classList.add('hidden');
        submitBtn.disabled = true;
        var orig = submitBtn.textContent;
        submitBtn.textContent = '접수 중…';
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, d: d }; }); })
            .then(function (res) {
                if (res.ok && res.d && res.d.ok) {
                    form.classList.add('hidden');
                    done.classList.remove('hidden');
                    form.reset();
                } else {
                    var msg = '';
                    if (res.d && res.d.errors) { msg = Object.values(res.d.errors).map(function (a) { return a[0]; }).join(' '); }
                    errBox.textContent = msg || (res.d && res.d.message) || '접수에 실패했습니다. 잠시 후 다시 시도하세요.';
                    errBox.classList.remove('hidden');
                }
            })
            .catch(function () {
                errBox.textContent = '네트워크 오류로 접수하지 못했습니다. 잠시 후 다시 시도하세요.';
                errBox.classList.remove('hidden');
            })
            .finally(function () { submitBtn.disabled = false; submitBtn.textContent = orig; });
    });
})();
</script>
