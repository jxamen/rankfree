{{-- 블로그 단건 상세 — $b = analyzeBlog 결과(profile·quality·breakdown·posts·score·grade) --}}
@php
    $gc = match ($b['grade']) {
        'S' => 'var(--color-success)', 'A' => 'var(--color-accent)', 'B' => 'var(--color-badge-violet)',
        'C' => 'var(--color-warning)', default => 'var(--color-muted)',
    };
    $p = $b['profile'];
    $q = $b['quality'];
    $bd = $b['breakdown'] ?? [];
    $nf = fn ($v) => $v === null ? '—' : number_format((int) $v);
    $bar = function ($v, $c = 'var(--color-accent)') {
        $w = max(0, min(100, (float) $v));
        return '<div style="height:7px;background:var(--color-surface-strong);border-radius:99px;overflow:hidden;"><div style="height:100%;width:'.$w.'%;background:'.$c.';border-radius:99px;"></div></div>';
    };
@endphp

@if (! empty($exportable))
    <div class="flex justify-end gap-2 mb-3">
        <button type="button" id="bi-recollect" class="btn btn-secondary btn-sm" style="height:36px;" title="현재 블로그를 처음부터 새로 수집">↻ 재수집</button>
        <a href="{{ route('console.blog.export', $exportable) }}" class="btn btn-secondary btn-sm" style="height:36px;">엑셀 다운로드</a>
    </div>
@endif

{{-- 헤더: 블로그명 + 종합 지수/등급 --}}
<div class="card p-5 mb-6">
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="https://blog.naver.com/{{ $b['blog_id'] }}" target="_blank" class="text-ink font-display hover:underline" style="font-size:var(--fs-xl);">{{ $p['blog_name'] ?: $b['blog_id'] }}</a>
                @if ($p['power_blog'] ?? false)<span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;background:color-mix(in srgb,var(--color-badge-orange) 14%,var(--color-canvas));color:var(--color-badge-orange);">파워블로그</span>@endif
                @if ($p['influencer'] ?? false)<span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">인플루언서</span>@endif
            </div>
            <div class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">{{ $b['blog_id'] }} @if (! empty($p['directory']))· {{ $p['directory'] }}@endif</div>
        </div>
        <div class="text-center" style="min-width:150px;">
            <div class="font-display" style="font-size:var(--fs-3xl);line-height:1;color:{{ $gc }};">{{ $b['score'] }}</div>
            <div class="badge mt-2" style="font-size:var(--fs-xs);padding:3px 14px;background:color-mix(in srgb,{{ $gc }} 14%,var(--color-canvas));color:{{ $gc }};font-weight:700;">{{ $b['grade'] }} 등급</div>
        </div>
    </div>
    {{-- 축별 요약 바 --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-5">
        @foreach ([['프로필(활동·반응·규모)', $bd['profile'] ?? 0], ['게시물 품질', $bd['content'] ?? 0], ['전문성(주제 집중)', $bd['focus'] ?? 0]] as [$lab, $v])
            <div>
                <div class="flex items-center justify-between mb-1"><span class="text-muted" style="font-size:var(--fs-xs);">{{ $lab }}</span><span class="text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $v }}</span></div>
                {!! $bar($v, $gc) !!}
            </div>
        @endforeach
    </div>
</div>

{{-- 프로필 지표 — 큰 카드 + 배경 SVG(도트/링/그리드 순환, 홈페이지 스타일) --}}
<div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">프로필 지표</div>
<div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
    @foreach ([
        ['이웃 수', $nf($p['subscriber_cnt'] ?? 0), 'var(--color-accent)', 'dots'],
        ['일평균 방문', $nf($p['day_visitor_avg'] ?? 0), 'var(--color-badge-emerald)', 'rings'],
        ['누적 방문', $nf($p['total_visitor'] ?? 0), 'var(--color-ink)', 'grid'],
        ['주당 포스팅', ($p['post_per_week'] ?? 0).'개', 'var(--color-badge-violet)', 'dots'],
        ['평균 댓글', ($p['avg_comment'] ?? 0).'개', 'var(--color-badge-pink)', 'rings'],
        ['총 글수', $nf($p['post_total'] ?? 0), 'var(--color-muted)', 'grid'],
    ] as [$lab, $val, $c, $pat])
        <div class="card p-5 relative overflow-hidden">
            <x-card-bg pattern="{{ $pat }}" color="{{ $pat === 'grid' ? 'var(--color-ink)' : $c }}" opacity="{{ $pat === 'grid' ? '0.07' : ($pat === 'rings' ? '0.3' : '0.28') }}" />
            <div class="relative">
                <div class="text-body" style="font-size:var(--fs-xs);font-weight:600;">{{ $lab }}</div>
                <div class="font-display mt-1" style="font-size:var(--fs-xl);color:{{ $c }};">{{ $val }}</div>
            </div>
        </div>
    @endforeach
</div>

{{-- 최근 방문자 추이(일별) — 라인 차트(y축·점선 그리드·포인트·세로 안내선 호버) --}}
@if (! empty($p['visitor5']))
    @php
        $uid = 'vis'.substr(md5(uniqid('', true)), 0, 8);
        $v5 = array_values($p['visitor5']); // 과거→오늘
        $cnts = array_map(fn ($v) => (int) $v['count'], $v5);
        $n = count($cnts);
        $fmtShort = fn ($d) => \Illuminate\Support\Carbon::hasFormat($d, 'Ymd') ? \Illuminate\Support\Carbon::createFromFormat('Ymd', $d)->format('n/j') : $d;
        $fmtFull = fn ($d) => \Illuminate\Support\Carbon::hasFormat($d, 'Ymd') ? \Illuminate\Support\Carbon::createFromFormat('Ymd', $d)->format('Y-m-d(D)') : $d;

        // y축 nice-step (0 또는 최소값 근처부터) — keyword 트렌드 차트와 동일 방식
        $dmin = $cnts ? min($cnts) : 0;
        $dmax = $cnts ? max($cnts) : 1;
        $span = max(1, $dmax - $dmin);
        $mag = pow(10, floor(log10($span)));
        $niceStep = $mag * (($span / $mag) >= 5 ? 2 : (($span / $mag) >= 2 ? 1 : 0.5));
        $gridMin = max(0, floor($dmin / $niceStep) * $niceStep);
        $gridMax = ceil($dmax / $niceStep) * $niceStep;
        if ($gridMax <= $gridMin) { $gridMax = $gridMin + $niceStep; }
        $ySteps = max(2, min(5, (int) round(($gridMax - $gridMin) / $niceStep)));
        $kfmt = fn ($v) => $v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'k' : (string) (int) $v;

        $VW = 1000; $VH = 200;
        $sx = fn ($i) => $n > 1 ? round($i / ($n - 1) * $VW, 1) : $VW / 2;
        $sy = fn ($v) => round((1 - ((int) $v - $gridMin) / max(1, $gridMax - $gridMin)) * $VH, 1);
        $line = implode(' ', array_map(fn ($i) => $sx($i).','.$sy($cnts[$i]), array_keys($cnts)));
        $peakI = 0;
        foreach ($cnts as $i => $c) { if ($c > $cnts[$peakI]) $peakI = $i; }
    @endphp
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">최근 방문자 추이 <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">일별</span> <span class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:400;">최근 {{ $n }}일</span></div>
    <div class="card p-5 mb-6" id="{{ $uid }}">
        <div style="position:relative;padding-left:42px;">
            {{-- y축 라벨 --}}
            <div style="position:absolute;left:0;top:0;width:38px;height:180px;">
                @for ($g = $ySteps; $g >= 0; $g--)
                    @php $gv = $gridMin + ($gridMax - $gridMin) * $g / $ySteps; $topPct = (1 - $g / $ySteps) * 100; @endphp
                    <span class="text-muted-soft" style="position:absolute;right:7px;top:calc({{ $topPct }}% - 7px);font-size:var(--fs-xs);">{{ $kfmt($gv) }}</span>
                @endfor
            </div>
            {{-- 플롯 영역 --}}
            <div style="position:relative;height:180px;" class="vis-plot">
                <svg viewBox="0 0 {{ $VW }} {{ $VH }}" preserveAspectRatio="none" style="width:100%;height:100%;display:block;overflow:visible;">
                    @for ($g = 0; $g <= $ySteps; $g++)
                        @php $gy = round($g / $ySteps * $VH, 1); @endphp
                        <line x1="0" x2="{{ $VW }}" y1="{{ $gy }}" y2="{{ $gy }}" stroke="var(--color-hairline-soft)" stroke-width="1" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/>
                    @endfor
                    <polyline fill="none" stroke="var(--color-accent)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" points="{{ $line }}"/>
                </svg>
                {{-- 세로 안내선(호버) --}}
                <div class="vis-vline" style="position:absolute;top:0;bottom:0;width:1px;background:var(--color-hairline);display:none;"></div>
                {{-- 포인트 마커 --}}
                @foreach ($cnts as $i => $c)
                    @php $xp = $n > 1 ? $i / ($n - 1) * 100 : 50; @endphp
                    <span style="position:absolute;left:calc({{ $xp }}% - 4px);top:calc({{ round($sy($c) / $VH * 100, 2) }}% - 4px);width:8px;height:8px;border-radius:50%;background:var(--color-accent);border:2px solid var(--color-canvas);box-shadow:0 0 0 1px var(--color-accent);"></span>
                @endforeach
                {{-- hover 영역(일별) --}}
                <div style="position:absolute;inset:0;display:flex;">
                    @foreach ($v5 as $i => $v)
                        <div class="vis-hover" style="flex:1;height:100%;" data-x="{{ $n > 1 ? $i / ($n - 1) * 100 : 50 }}"
                             data-date="{{ $fmtFull($v['date']) }}" data-count="{{ number_format((int) $v['count']) }}"></div>
                    @endforeach
                </div>
                {{-- 툴팁 --}}
                <div class="vis-tip" style="position:absolute;display:none;pointer-events:none;background:var(--color-surface-dark);color:#fff;border-radius:8px;padding:8px 11px;font-size:var(--fs-xs);white-space:nowrap;transform:translateX(-50%);z-index:5;box-shadow:var(--shadow-card);"></div>
            </div>
            {{-- x축 라벨 --}}
            <div style="display:flex;margin-top:6px;">
                @foreach ($v5 as $v)
                    <span class="text-muted-soft text-center" style="flex:1;font-size:var(--fs-xs);">{{ $fmtShort($v['date']) }}</span>
                @endforeach
            </div>
        </div>
        <p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">* 최고 방문일은 <b class="text-ink">{{ $fmtShort($v5[$peakI]['date']) }}</b>({{ number_format((int) $v5[$peakI]['count']) }}명)입니다. 방문자수는 네이버 방문자 통계(NVisitorgp) 기준이며, 조회수는 네이버가 비공개로 제공하지 않습니다.</p>
    </div>
    <script>
    (function () {
        var card = document.getElementById(@json($uid));
        if (!card) return;
        var tip = card.querySelector('.vis-tip');
        var vline = card.querySelector('.vis-vline');
        card.querySelectorAll('.vis-hover').forEach(function (h) {
            h.addEventListener('mouseenter', show);
            h.addEventListener('mousemove', show);
            h.addEventListener('mouseleave', hide);
        });
        function show(e) {
            var h = e.currentTarget, xp = parseFloat(h.dataset.x);
            tip.innerHTML = '<div style="font-weight:700;margin-bottom:3px;">' + h.dataset.date + '</div>'
                + '<div style="display:flex;align-items:center;gap:6px;"><i style="width:8px;height:8px;border-radius:50%;background:var(--color-accent);display:inline-block;"></i>방문자 ' + h.dataset.count + '명</div>';
            tip.style.left = xp + '%'; tip.style.top = '2px'; tip.style.display = 'block';
            vline.style.left = xp + '%'; vline.style.display = 'block';
        }
        function hide() { tip.style.display = 'none'; vline.style.display = 'none'; }
    })();
    </script>
@endif

{{-- 게시물 품질 --}}
<div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">게시물 품질 <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">최근 {{ $q['analyzed'] ?? 0 }}개 글 분석</span></div>
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    @foreach ([
        ['평균 사진 수', ($q['avg_photos'] ?? 0).'장', 'var(--color-accent)'],
        ['평균 본문 길이', number_format($q['avg_length'] ?? 0).'자', 'var(--color-badge-emerald)'],
        ['영상 포함', ($q['video_ratio'] ?? 0).'%', 'var(--color-badge-violet)'],
        ['최근 발행', ($p['last_post'] ?? null) ? \Illuminate\Support\Carbon::parse($p['last_post'])->diffForHumans() : '—', 'var(--color-ink)'],
    ] as [$lab, $val, $c])
        <div class="card p-5">
            <div class="text-muted" style="font-size:var(--fs-xs);">{{ $lab }}</div>
            <div class="font-display mt-1" style="font-size:var(--fs-xl);color:{{ $c }};">{{ $val }}</div>
        </div>
    @endforeach
</div>

{{-- 전문성 — 빈출 주제어 --}}
@if (! empty($q['top_words']))
    <div class="card p-5 mb-6">
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-xs);">전문성 — 자주 쓰는 주제어 <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">제목·본문 형태소 빈도</span></div>
        <div class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">주제 집중도 {{ $p['top_focus'] ?? 0 }}% — 한 주제에 집중할수록 전문 블로그입니다.</div>
        <div class="flex flex-wrap gap-2">
            @php $maxc = max(array_map(fn ($w) => $w['count'], $q['top_words'])); @endphp
            @foreach ($q['top_words'] as $w)
                @php $sz = 12 + round(($w['count'] / max(1, $maxc)) * 8); @endphp
                <span class="badge" style="font-size:{{ $sz }}px;padding:3px 11px;">{{ $w['word'] }} <span style="opacity:.55;">{{ $w['count'] }}</span></span>
            @endforeach
        </div>
    </div>
@endif

{{-- 세부 점수 --}}
@if ($bd)
    <div class="card p-5 mb-6">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">지수 세부 구성</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-2">
            @foreach ([
                '활동성(주당 포스팅)' => $bd['activity'] ?? 0, '반응(평균 댓글)' => $bd['comment'] ?? 0,
                '방문자' => $bd['visitor'] ?? 0, '이웃 규모' => $bd['subscriber'] ?? 0,
                '활동 기간' => $bd['age'] ?? 0, '사진 충실도' => $bd['photo'] ?? 0,
                '본문 충실도' => $bd['text'] ?? 0, '주제 집중도' => $bd['focus'] ?? 0,
            ] as $lab => $v)
                <div class="flex items-center gap-3" style="margin:3px 0;">
                    <span class="text-muted" style="width:130px;font-size:var(--fs-xs);">{{ $lab }}</span>
                    <div style="flex:1;">{!! $bar($v) !!}</div>
                    <span class="text-ink text-right" style="width:34px;font-size:var(--fs-xs);">{{ $v }}</span>
                </div>
            @endforeach
        </div>
        <p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">* 조회수는 네이버가 비공개(0)로 제공하는 경우가 많아 지수에 반영하지 않았습니다.</p>
    </div>
@endif

{{-- 최근 글 — 수집한 글 전부 + 글별 분석(공감·댓글·사진·본문·영상·주제어) --}}
@if (! empty($b['posts']))
    @php $wordEngine = app(\App\Domain\Blog\BlogIndexAnalyzer::class); @endphp
    <div class="card overflow-hidden">
        <div class="px-5 py-4 text-ink font-semibold" style="font-size:var(--fs-xs);">최근 글 <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ count($b['posts']) }}개 분석</span></div>
        <div style="overflow-x:auto;">
            <table class="w-full" style="min-width:900px;">
                <thead>
                    <tr class="text-muted" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);">
                        <th class="text-left px-5 py-2.5 font-semibold" style="width:52px;">No</th>
                        <th class="text-left px-3 py-2.5 font-semibold">제목 · 주제어(형태소 빈도)</th>
                        <th class="text-left px-3 py-2.5 font-semibold" style="width:130px;">작성일</th>
                        <th class="text-right px-3 py-2.5 font-semibold">공감</th>
                        <th class="text-right px-3 py-2.5 font-semibold">댓글</th>
                        <th class="text-right px-3 py-2.5 font-semibold">사진</th>
                        <th class="text-right px-3 py-2.5 font-semibold">본문</th>
                        <th class="text-center px-5 py-2.5 font-semibold">영상</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($b['posts'] as $i => $post)
                        <tr style="border-top:1px solid var(--color-hairline-soft);">
                            <td class="px-5 py-3 text-muted-soft" style="font-size:var(--fs-xs);">{{ $i + 1 }}</td>
                            <td class="px-3 py-3">
                                <a href="https://blog.naver.com/{{ $b['blog_id'] }}/{{ $post['no'] }}" target="_blank" class="text-ink hover:underline" style="font-size:var(--fs-xs);">{{ $post['title'] }}</a>
                                {{-- 글별 전문성 주제어 — 제목 형태소 빈도 상위 --}}
                                @php $pw = array_slice($wordEngine->topWords((string) $post['title'], 5, 1), 0, 4); @endphp
                                @if (count($pw))
                                    <div class="flex flex-wrap gap-1.5 mt-1.5">
                                        @foreach ($pw as $w)
                                            <span class="badge" style="font-size:var(--fs-xs);padding:3px 10px;">{{ $w['word'] }}@if (($w['count'] ?? 1) > 1) <b class="text-ink">{{ $w['count'] }}</b>@endif</span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $post['date'] ?? '—' }}</td>
                            <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ isset($post['sympathy']) ? number_format((int) $post['sympathy']) : '—' }}</td>
                            <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ $post['comment'] ?? '—' }}</td>
                            <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ isset($post['photos']) ? $post['photos'].'장' : '—' }}</td>
                            <td class="px-3 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ isset($post['length']) ? number_format((int) $post['length']).'자' : '—' }}</td>
                            <td class="px-5 py-3 text-center" style="font-size:var(--fs-xs);">{{ ($post['video'] ?? 0) ? '🎬' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-muted-soft px-5 py-3" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);">* 글별 주제어는 제목 형태소 빈도 기준입니다. 블로그 전체 전문성은 위 "전문성 — 자주 쓰는 주제어" 카드를 참고하세요.</p>
    </div>
@endif
