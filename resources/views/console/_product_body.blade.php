{{--
    상품 분석 본문 — 콘솔 상세(product-show)와 공개 공유(product-share) 공용.
    입력: $a(ProductAnalysis), $shareUrl(공유 URL|null), $public(공개 뷰 여부).
    디자인: 셀러력 진단(_seller_power_body)과 동일한 언어 — 상단 3단(도넛·진단·분포) + 아이콘 칩 + 그라데이션 카드.
--}}
@php
    $public = $public ?? false;
    $won = fn ($n) => $n >= 100000000
        ? number_format($n / 100000000, 1).'억'
        : ($n >= 10000 ? number_format($n / 10000).'만' : number_format($n));
    $snap = (array) $a->snapshot;
    $dist = (array) ($snap['dist'] ?? []);
    $options = array_values((array) ($snap['options'] ?? []));  // [[name, count], ...]
    $weak = array_values((array) ($snap['weak_words'] ?? []));  // [[word, count], ...]
    $worst = array_values((array) ($snap['worst_samples'] ?? []));
    $optTotal = (int) ($snap['opt_total'] ?? array_sum(array_map(fn ($o) => (int) ($o[1] ?? 0), $options)));
    $sales6m = $a->sales_6m;
    $price = $a->price;

    $nlp = (array) ($snap['nlp'] ?? []);
    $sent = (array) ($nlp['sentiment'] ?? []);
    $pros = array_values((array) ($nlp['pros'] ?? []));
    $cons = array_values((array) ($nlp['cons'] ?? []));
    $kw = array_values((array) ($nlp['keywords'] ?? []));

    // 감정 도넛 — 긍정/중립/부정 (상태 색: 라벨·범례 병기)
    $posPct = round((float) ($sent['posPct'] ?? 0), 1);
    $neuPct = round((float) ($sent['neuPct'] ?? 0), 1);
    $negPct = round((float) ($sent['negPct'] ?? 0), 1);
    $circ = 596.9; // 2π × r(95)
    $donut = [];
    if ($sent) {
        $acc = 0.0;
        foreach ([
            ['긍정', $posPct, 'var(--color-success)'],
            ['중립', $neuPct, 'var(--color-surface-strong)'],
            ['부정', $negPct, 'var(--color-error)'],
        ] as [$lb, $pct, $col]) {
            $donut[] = ['label' => $lb, 'pct' => $pct, 'color' => $col, 'len' => $pct / 100 * $circ, 'start' => $acc / 100 * $circ];
            $acc += $pct;
        }
    }
    $posColor = $posPct >= 70 ? 'var(--color-success)' : ($posPct >= 40 ? 'var(--color-warning)' : 'var(--color-error)');
@endphp

<style>
    /* 감정 도넛 — 세그먼트 호버 시 두께 증가 + 바깥으로 살짝 팝(<title> 툴팁) */
    .pd-seg { transition: stroke-width 0.15s ease, transform 0.15s ease; cursor: pointer; transform-box: fill-box; transform-origin: center; }
    .pd-seg:hover { stroke-width: 21px; transform: scale(1.045); }
</style>

{{-- 헤더 — 셀러력과 동일 구조 --}}
<div class="flex items-start justify-between flex-wrap gap-3 mb-5">
    <div style="min-width:0;">
        <span class="badge" style="font-size:var(--fs-xs);padding:2px 9px;background:color-mix(in srgb,var(--color-accent) 12%,var(--color-canvas));color:var(--color-accent);">{{ $a->store ?: '스마트스토어' }} · 리뷰 분석</span>
        <a href="{{ $a->url }}" target="_blank" rel="noopener" class="text-ink font-display hover:underline block mt-2" style="font-size:var(--fs-xl);line-height:1.3;">{{ $a->name }}</a>
        <div class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">
            상품번호 {{ $a->origin_product_no }}
            @if ($a->price) · 판매가 {{ number_format($a->price) }}원 @endif
            @if ($a->sales_6m) · 6개월 판매량 {{ number_format($a->sales_6m) }}건 @endif
            · {{ $a->created_at->format('Y-m-d H:i') }} 분석
        </div>
    </div>
    <div class="flex items-center gap-2">
        <a href="{{ $a->url }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">상품 페이지</a>
        @unless ($public)
            <button type="button" class="btn btn-secondary btn-sm" onclick="rfCopyShare(this, @js($shareUrl ?? ''))" title="비로그인 공개 공유 링크 복사">🔗 공유</button>
        @endunless
    </div>
</div>

{{-- 상단 3단: 감정 도넛 · 진단 요약 · 평점 분포 --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4 items-stretch">

    {{-- 1) 리뷰 감정 지수 — 도넛(호버 시 비율 표시) --}}
    <div class="card p-6 flex flex-col items-center text-center">
        <div class="text-ink font-semibold self-start text-left mb-3" style="font-size:var(--fs-sm);">리뷰 감정 지수</div>
        <div class="flex-1 flex flex-col items-center justify-center">
            <div style="position:relative;width:220px;height:220px;">
                <svg width="220" height="220" viewBox="0 0 220 220" style="transform:rotate(-90deg);overflow:visible;">
                    <circle cx="110" cy="110" r="95" fill="none" stroke="var(--color-surface-strong)" stroke-width="15"></circle>
                    @foreach ($donut as $seg)
                        @if ($seg['len'] > 2.5)
                            <circle class="pd-seg" cx="110" cy="110" r="95" fill="none" stroke="{{ $seg['color'] }}" stroke-width="15"
                                    stroke-dasharray="{{ max(0.1, round($seg['len'] - 2, 2)) }} {{ round($circ - max(0.1, $seg['len'] - 2), 2) }}"
                                    stroke-dashoffset="{{ round(-($seg['start'] + 1), 2) }}">
                                <title>{{ $seg['label'] }} {{ $seg['pct'] }}% — 분석 리뷰 {{ number_format((int) ($nlp['docs'] ?? 0)) }}개 기준</title>
                            </circle>
                        @endif
                    @endforeach
                </svg>
                <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;">
                    @if ($sent)
                        <div class="font-display" style="font-size:var(--fs-3xl);line-height:.9;letter-spacing:-.03em;color:{{ $posColor }};">{{ round($posPct) }}<small style="font-size:var(--fs-md);">%</small></div>
                        <div class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:600;margin-top:3px;">긍정 리뷰 비율</div>
                    @else
                        <div class="font-display text-ink" style="font-size:var(--fs-3xl);line-height:.9;">{{ number_format($a->avg_score, 1) }}</div>
                        <div class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:600;margin-top:3px;">평균 평점 / 5</div>
                    @endif
                </div>
            </div>
            @if ($sent)
                <div class="flex flex-wrap justify-center" style="gap:7px 14px;margin-top:24px;">
                    @foreach ($donut as $seg)
                        <span class="text-muted" style="display:inline-flex;align-items:center;gap:6px;font-size:var(--fs-sm);font-weight:600;">
                            <span style="width:10px;height:10px;border-radius:3px;background:{{ $seg['color'] }};flex:none;"></span>{{ $seg['label'] }} {{ $seg['pct'] }}%
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- 2) 진단 요약 --}}
    <div class="card p-6 flex flex-col">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">진단 요약</div>
        {{-- 지금 상태 --}}
        <div class="mb-3">
            <div class="text-muted-soft mb-1" style="font-size:var(--fs-xs);font-weight:600;">지금 상태</div>
            <div class="text-ink font-display" style="font-size:22px;">{{ number_format($a->avg_score, 2) }}점 <small style="font-size:12px;color:var(--color-muted-soft);">/ 5점 · 리뷰 {{ number_format($a->total_reviews) }}개</small></div>
            <div class="text-muted" style="font-size:var(--fs-xs);margin-top:3px;">재구매 {{ number_format($a->repurchase_pct, 1) }}% · 분석 리뷰 {{ number_format($a->analyzed_reviews) }}개</div>
        </div>
        @if ($sent)
            <p class="text-muted" style="font-size:var(--fs-sm);line-height:1.7;">
                부정 리뷰 비율은 <b style="color:{{ $negPct >= 15 ? 'var(--color-error)' : 'var(--color-ink)' }};">{{ round($negPct) }}%</b>입니다.
                @if (count($weak))
                    자주 언급되는 약점은 <b class="text-ink">{{ implode('·', array_slice(array_map(fn ($w) => $w[0] ?? '', $weak), 0, 2)) }}</b>입니다.
                @endif
            </p>
        @endif
        {{-- 최근 리뷰 유입 --}}
        <div class="mt-auto pt-4">
            <div class="text-muted-soft mb-1" style="font-size:var(--fs-xs);font-weight:600;">최근 리뷰 유입</div>
            @foreach ([['최근 7일', $a->recent_7d], ['최근 1개월', $a->recent_1m], ['최근 3개월', $a->recent_3m]] as [$lb, $v])
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:var(--fs-xs);padding:4px 0;">
                    <span class="text-body" style="font-weight:600;">{{ $lb }}</span>
                    <span class="text-ink font-semibold">{{ number_format($v) }}개</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- 3) 평점 분포 --}}
    <div class="card p-6 flex flex-col">
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">평점 분포</div>
        <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">분석한 리뷰의 별점 분포입니다.</p>
        @if ($dist)
            @php $distMax = max(1, ...array_map(fn ($s) => (int) ($dist[$s] ?? 0), [5, 4, 3, 2, 1])); @endphp
            <div class="flex-1 flex flex-col justify-center" style="gap:18px;">
                @foreach ([5, 4, 3, 2, 1] as $s)
                    @php $barColor = $s >= 4 ? 'var(--color-accent)' : ($s === 3 ? 'var(--color-muted-soft)' : 'var(--color-error)'); @endphp
                    <div class="flex items-center gap-2" style="font-size:var(--fs-xs);">
                        <span class="text-muted text-right" style="width:34px;flex:none;">{{ $s }}점</span>
                        <span style="flex:1;height:9px;background:var(--color-surface-strong);border-radius:5px;overflow:hidden;">
                            <span style="display:block;height:100%;width:{{ round(((int) ($dist[$s] ?? 0)) / $distMax * 100) }}%;background:{{ $barColor }};border-radius:5px;"></span>
                        </span>
                        <span class="text-body font-semibold text-right" style="width:60px;flex:none;">{{ number_format((int) ($dist[$s] ?? 0)) }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex-1 flex items-center justify-center text-muted-soft" style="font-size:var(--fs-xs);">평점 분포 데이터가 없습니다.</div>
        @endif
    </div>

</div>{{-- /상단 3단 --}}

{{-- 인기 옵션 + 옵션별 예상 판매수량·매출 — 한 줄(2단) --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4 items-stretch">

    {{-- 인기 옵션 --}}
    @if ($options)
        @php $optMax = max(1, ...array_map(fn ($o) => (int) ($o[1] ?? 0), $options)); @endphp
        <div class="card p-5 relative overflow-hidden">
            <x-card-bg pattern="gradient" color="var(--color-badge-orange)" color2="var(--color-badge-pink)" opacity="0.12" />
            <div class="relative">
                <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">인기 옵션</div>
                <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">리뷰 {{ number_format($optTotal) }}개에 나타난 옵션 비율입니다.</p>
                @foreach ($options as $o)
                    @php $cnt = (int) ($o[1] ?? 0); @endphp
                    <div style="margin:9px 0;">
                        <div class="text-ink" style="font-size:var(--fs-xs);font-weight:600;">{{ $o[0] ?? '' }}</div>
                        <div class="flex items-center gap-2 mt-1">
                            <span style="flex:1;height:8px;background:var(--color-surface-strong);border-radius:4px;overflow:hidden;">
                                <span style="display:block;height:100%;width:{{ round($cnt / $optMax * 100) }}%;background:var(--color-accent);border-radius:4px;"></span>
                            </span>
                            <span class="text-body" style="font-size:var(--fs-xs);font-weight:600;white-space:nowrap;">{{ $optTotal > 0 ? number_format($cnt / $optTotal * 100, 1) : 0 }}% · {{ number_format($cnt) }}개</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

<div class="card p-5 relative overflow-hidden">
    <x-card-bg pattern="gradient" color="var(--color-accent)" color2="var(--color-badge-violet)" opacity="0.12" />
    <div class="relative">
        <div class="flex items-center gap-2 flex-wrap mb-1">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">옵션별 예상 판매수량 · 매출</span>
            <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">6개월 판매량 × 리뷰 옵션 비율</span>
        </div>
        <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">
            6개월 판매량과 판매가를 입력하면 리뷰에 나타난 옵션 비율로 옵션별 예상 판매수량·매출을 추정합니다.
        </p>

        <form method="GET" class="flex items-end gap-3 flex-wrap mb-5">
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">6개월 판매량(건)</label>
                <input type="number" name="sales_6m" value="{{ request('sales_6m', $sales6m) }}" min="0" class="input" style="height:38px;width:150px;" placeholder="예: 3000">
            </div>
            <div>
                <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">판매가(원)</label>
                <input type="number" name="price" value="{{ request('price', $price) }}" min="0" class="input" style="height:38px;width:150px;" placeholder="예: 15900">
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="height:38px;">적용</button>
        </form>

        @php
            $useSales = request('sales_6m', $sales6m);
            $usePrice = request('price', $price);
            $useSales = is_numeric($useSales) ? (int) $useSales : null;
            $usePrice = is_numeric($usePrice) ? (int) $usePrice : null;
        @endphp

        @if ($options && $optTotal > 0)
            <div style="overflow-x:auto;">
                <table class="w-full" style="min-width:640px;">
                    <thead>
                        <tr class="text-muted" style="font-size:var(--fs-xs);">
                            <th class="text-left px-2 py-2 font-semibold">옵션</th>
                            <th class="text-right px-3 py-2 font-semibold">리뷰 비율</th>
                            @if ($useSales !== null)<th class="text-right px-3 py-2 font-semibold">예상 판매수량</th>@endif
                            @if ($useSales !== null && $usePrice !== null)<th class="text-right px-3 py-2 font-semibold">예상 매출</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($options as $o)
                            @php
                                $optName = $o[0] ?? '';
                                $cnt = (int) ($o[1] ?? 0);
                                $ratio = $cnt / $optTotal;
                                $estQty = $useSales !== null ? (int) round($useSales * $ratio) : null;
                                $estRev = ($estQty !== null && $usePrice !== null) ? $estQty * $usePrice : null;
                            @endphp
                            <tr style="border-top:1px solid var(--color-hairline-soft);">
                                <td class="px-2 py-2.5 text-ink" style="font-size:var(--fs-xs);">{{ $optName }}</td>
                                <td class="px-3 py-2.5 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format($ratio * 100, 1) }}%</td>
                                @if ($useSales !== null)<td class="px-3 py-2.5 text-right text-ink font-semibold" style="font-size:var(--fs-xs);">{{ number_format($estQty) }}건</td>@endif
                                @if ($useSales !== null && $usePrice !== null)<td class="px-3 py-2.5 text-right text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $won($estRev) }}원</td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($useSales !== null)
                        <tfoot>
                            <tr style="border-top:2px solid var(--color-hairline);">
                                <td class="px-2 py-2.5 text-muted font-semibold" style="font-size:var(--fs-xs);">합계</td>
                                <td class="px-3 py-2.5 text-right text-muted" style="font-size:var(--fs-xs);">100%</td>
                                <td class="px-3 py-2.5 text-right text-ink font-semibold" style="font-size:var(--fs-xs);">{{ number_format($useSales) }}건</td>
                                @if ($usePrice !== null)<td class="px-3 py-2.5 text-right text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $won($useSales * $usePrice) }}원</td>@endif
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">* 리뷰에 표시된 옵션 {{ number_format($optTotal) }}개 기준 비율입니다. 리뷰 미작성·무옵션 구매가 있어 실제와 차이가 있을 수 있습니다.</p>
        @else
            <p class="text-muted-soft" style="font-size:var(--fs-xs);">옵션 정보가 있는 리뷰가 없습니다.</p>
        @endif
    </div>
</div>

</div>{{-- /인기 옵션 + 옵션별 예상 --}}

{{-- 감정 분석 — 장점 + 단점 + 주로 이야기하는 것 + 약점 (한 줄 · 4단) --}}
@php
    $posSamples = array_slice(array_values((array) ($nlp['pos_samples'] ?? [])), 0, 2);
    $negSamples = array_slice(array_values((array) ($nlp['neg_samples'] ?? [])), 0, 2);
@endphp
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4 items-stretch">

    @if ($nlp)
    {{-- 장점 --}}
    <div class="card p-5 relative overflow-hidden">
        <x-card-bg pattern="gradient" color="var(--color-badge-emerald)" color2="var(--color-accent)" opacity="0.12" />
        <div class="relative">
            <div class="flex items-center gap-2 flex-wrap mb-1">
                <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">장점</span>
                <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;background:color-mix(in srgb,var(--color-success) 12%,var(--color-canvas));color:var(--color-success);font-weight:700;">긍정 {{ number_format($posPct, 0) }}%</span>
            </div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">자주 언급된 긍정 표현입니다.</p>
            <div class="flex flex-wrap gap-2">
                @forelse ($pros as $p)
                    <span class="badge" style="font-size:var(--fs-xs);background:color-mix(in srgb,var(--color-success) 10%,transparent);">{{ $p[0] ?? '' }} <b class="text-ink">{{ (int) ($p[1] ?? 0) }}</b></span>
                @empty <span class="text-muted-soft" style="font-size:var(--fs-xs);">데이터 적음</span> @endforelse
            </div>
            @foreach ($posSamples as $s)
                <p class="text-muted" style="font-size:var(--fs-xs);margin:{{ $loop->first ? '12px' : '4px' }} 0 0;">“{{ \Illuminate\Support\Str::limit($s['text'] ?? '', 100) }}”@if (! empty($s['score'])) <b style="color:var(--color-success);">{{ $s['score'] }}점</b>@endif</p>
            @endforeach
        </div>
    </div>

    {{-- 단점 --}}
    <div class="card p-5 relative overflow-hidden">
        <x-card-bg pattern="gradient" color="var(--color-badge-pink)" color2="var(--color-badge-orange)" opacity="0.12" />
        <div class="relative">
            <div class="flex items-center gap-2 flex-wrap mb-1">
                <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">단점</span>
                <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;background:color-mix(in srgb,var(--color-error) 12%,var(--color-canvas));color:var(--color-error);font-weight:700;">부정 {{ number_format($negPct, 0) }}%</span>
            </div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">자주 언급된 부정 표현입니다.</p>
            <div class="flex flex-wrap gap-2">
                @forelse ($cons as $c)
                    <span class="badge" style="font-size:var(--fs-xs);background:color-mix(in srgb,var(--color-error) 10%,transparent);">{{ $c[0] ?? '' }} <b class="text-ink">{{ (int) ($c[1] ?? 0) }}</b></span>
                @empty <span class="text-muted-soft" style="font-size:var(--fs-xs);">데이터 적음</span> @endforelse
            </div>
            @foreach ($negSamples as $s)
                <p class="text-muted" style="font-size:var(--fs-xs);margin:{{ $loop->first ? '12px' : '4px' }} 0 0;">“{{ \Illuminate\Support\Str::limit($s['text'] ?? '', 100) }}”@if (! empty($s['score'])) <b style="color:var(--color-error);">{{ $s['score'] }}점</b>@endif</p>
            @endforeach
        </div>
    </div>

    {{-- 주로 이야기하는 것 --}}
    <div class="card p-5 relative overflow-hidden">
        <x-card-bg pattern="gradient" color="var(--color-badge-violet)" color2="var(--color-accent)" opacity="0.12" />
        <div class="relative">
            <div class="flex items-center gap-2 flex-wrap mb-1">
                <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">주로 이야기하는 것</span>
            </div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">가장 자주 언급된 주제어입니다.</p>
            <div class="flex flex-wrap gap-2">
                @forelse ($kw as $k)
                    <span class="badge" style="font-size:var(--fs-xs);">{{ $k[0] ?? '' }} <b class="text-ink">{{ (int) ($k[1] ?? 0) }}</b></span>
                @empty <span class="text-muted-soft" style="font-size:var(--fs-xs);">데이터 적음</span> @endforelse
            </div>
        </div>
    </div>
    @endif

    {{-- 약점 분석 --}}
    <div class="card p-5 relative overflow-hidden">
        <x-card-bg pattern="gradient" color="var(--color-badge-pink)" color2="var(--color-badge-orange)" opacity="0.12" />
        <div class="relative">
            <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">약점 분석</div>
            <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">평점 낮은순 · 3점 이하 {{ number_format((int) ($snap['low_reviews'] ?? 0)) }}개 기준입니다.</p>
            @if ($weak)
                <div class="flex flex-wrap gap-2 mb-3">
                    @foreach ($weak as $w)
                        <span class="badge" style="font-size:var(--fs-xs);background:color-mix(in srgb,var(--color-error) 8%,transparent);">{{ $w[0] ?? '' }} <b class="text-ink">{{ (int) ($w[1] ?? 0) }}</b></span>
                    @endforeach
                </div>
                @foreach ($worst as $s)
                    <p class="text-muted" style="font-size:var(--fs-xs);margin:4px 0;">“{{ \Illuminate\Support\Str::limit($s['text'] ?? '', 100) }}” <b style="color:var(--color-error);">{{ $s['score'] ?? '' }}점</b></p>
                @endforeach
            @else
                <p class="text-muted-soft" style="font-size:var(--fs-xs);">낮은 평점 리뷰가 거의 없습니다. 👍</p>
            @endif
        </div>
    </div>

</div>{{-- /장점 + 단점 + 주로 이야기하는 것 + 약점 --}}

{{-- 상품 문의(QnA) 분석 --}}
@php
    $qna = (array) ($snap['qna'] ?? []);
    $qnlp = (array) ($qna['nlp'] ?? []);
    if (! $qnlp && ! empty($qna['keywords'])) $qnlp = ['keywords' => $qna['keywords']]; // 구버전 호환
    $qSent = (array) ($qnlp['sentiment'] ?? []);
    $qGroups = [
        ['🔑 명사 (자주 묻는 것)', array_values((array) ($qnlp['keywords'] ?? []))],
        ['🗣 동사·형용사', array_values((array) ($qnlp['predicates'] ?? []))],
        ['👍 긍정 표현', array_values((array) ($qnlp['pros'] ?? []))],
        ['👎 부정 표현', array_values((array) ($qnlp['cons'] ?? []))],
    ];
@endphp
@if ($qna)
        <div class="card p-5 mb-4 relative overflow-hidden">
            <x-card-bg pattern="gradient" color="var(--color-badge-violet)" color2="var(--color-badge-pink)" opacity="0.12" />
            <div class="relative">
                <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">상품 문의(QnA) 분석</div>
                <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">공개 {{ number_format((int) ($qna['open'] ?? 0)) }}건 분석 · 비밀글 {{ number_format((int) ($qna['secret'] ?? 0)) }}건 제외 · 형태소 기반</p>
                @if ($qSent)
                    <div style="display:flex;height:14px;border-radius:8px;overflow:hidden;gap:2px;margin-bottom:6px;">
                        <span style="width:{{ $qSent['posPct'] ?? 0 }}%;background:var(--color-success);"></span>
                        <span style="width:{{ $qSent['neuPct'] ?? 0 }}%;background:var(--color-surface-strong);"></span>
                        <span style="width:{{ $qSent['negPct'] ?? 0 }}%;background:var(--color-error);"></span>
                    </div>
                    <div class="flex gap-4 mb-4" style="font-size:var(--fs-xs);">
                        <span>긍정 {{ number_format($qSent['posPct'] ?? 0, 0) }}%</span>
                        <span>중립 {{ number_format($qSent['neuPct'] ?? 0, 0) }}%</span>
                        <span>부정 {{ number_format($qSent['negPct'] ?? 0, 0) }}%</span>
                    </div>
                @endif
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    @foreach ($qGroups as [$label, $items])
                        <div>
                            <div class="text-muted mb-2" style="font-size:var(--fs-xs);font-weight:600;">{{ $label }}</div>
                            <div class="flex flex-wrap gap-2">
                                @forelse ($items as $it)
                                    <span class="badge" style="font-size:var(--fs-xs);">{{ $it[0] ?? '' }} <b class="text-ink">{{ (int) ($it[1] ?? 0) }}</b></span>
                                @empty <span class="text-muted-soft" style="font-size:var(--fs-xs);">데이터 적음</span> @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
                @foreach (array_values((array) ($qna['samples'] ?? [])) as $s)
                    <p class="text-muted" style="font-size:var(--fs-xs);margin:4px 0 0;">“{{ $s }}…”</p>
                @endforeach
            </div>
        </div>
@endif

<p class="text-muted-soft text-center" style="font-size:11px;line-height:1.6;">
    스마트스토어 리뷰 기준 · 옵션 비율·감정·약점 단어는 <b>자체 추정치</b>이며 실제와 차이가 있을 수 있습니다.
</p>
