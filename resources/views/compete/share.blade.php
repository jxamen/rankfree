<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $slot->keyword }} 경쟁 분석 · rankfree</title>
    <meta property="og:title" content="{{ $slot->keyword }} — {{ $slot->place_name ?: '플레이스' }} 경쟁 분석">
    <meta property="og:description" content="네이버 플레이스 SEO 경쟁력(N1 유사도·N2 관련성·N3 랭킹) 리포트">
    {{-- 공유 리포트 — 사용자의 콘솔 테마 선택(localStorage)을 따라 다크/라이트 렌더 --}}
    <script>if (localStorage.getItem('rf-theme') === 'dark') document.documentElement.classList.add('theme-dark');</script>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-surface-page font-sans antialiased text-body">
@php
    $fmt = fn ($v) => $v === null ? '—' : round($v);
    $bar = function ($v, $color = 'var(--color-primary)') {
        $w = $v === null ? 0 : max(0, min(100, (float) $v));
        return '<div style="height:6px;background:var(--color-surface-strong);border-radius:99px;overflow:hidden;"><div style="height:100%;width:'.$w.'%;background:'.$color.';"></div></div>';
    };
@endphp

<div style="max-width:1200px;margin:0 auto;padding:20px 16px 60px;">
    {{-- 헤더 --}}
    <div class="flex items-center gap-2 mb-6" style="height:48px;">
        <span class="inline-flex items-center justify-center w-7 h-7 rounded-md bg-primary text-on-primary font-display" style="font-size:var(--fs-sm);">R</span>
        <span class="font-display text-ink" style="font-size:var(--fs-md);">rankfree</span>
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">경쟁 분석 공유 리포트</span>
    </div>

    <div class="mb-5">
        <div class="font-display text-ink" style="font-size:var(--fs-xl);">{{ $slot->keyword }}</div>
        <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $slot->place_name ?: ('ID '.$slot->place_id) }} @if ($ymd)· 분석일 {{ \Illuminate\Support\Carbon::parse($ymd)->format('Y.m.d') }}@endif</div>
    </div>

    @unless ($ymd)
        <div class="card p-8 text-center"><p class="text-muted" style="font-size:var(--fs-xs);">아직 분석 데이터가 없습니다.</p></div>
    @else
        {{-- KPI --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            @php
                $kpis = [
                    ['순위', $mine && $mine->rnk > 0 && $mine->rnk < 300 ? $mine->rnk.'위' : '300+', null],
                    ['N1 유사도', $fmt($mine?->n1), $mine?->n1],
                    ['N2 관련성', $fmt($mine?->n2), $mine?->n2],
                    ['N3 랭킹', $fmt($mine?->n3), $mine?->n3],
                ];
            @endphp
            @foreach ($kpis as [$label, $val, $sc])
                <div class="card p-4">
                    <div class="text-muted" style="font-size:var(--fs-xs);">{{ $label }}</div>
                    <div class="font-display text-ink mt-1" style="font-size:var(--fs-xl);">{{ $val }}</div>
                    @if ($sc !== null)<div class="mt-2">{!! $bar($sc) !!}</div>@endif
                </div>
            @endforeach
        </div>

        {{-- 비교표(읽기전용) --}}
        <div class="card overflow-x-auto mb-6">
            <table class="w-full" style="min-width:1080px;border-collapse:collapse;white-space:nowrap;">
                <thead>
                    <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                        <th rowspan="2" class="text-right px-2 py-2 font-semibold" style="width:40px;">순위</th>
                        <th rowspan="2" class="text-left px-3 py-2 font-semibold">매장</th>
                        <th colspan="{{ max(1, $dates->count()) }}" class="text-center px-2 py-1 font-semibold" style="border-left:1px solid var(--color-hairline-soft);">일별 순위 <span class="text-muted-soft">(좌=최신)</span></th>
                        <th colspan="5" class="text-center px-2 py-1 font-semibold" style="border-left:1px solid var(--color-hairline-soft);">영수증 리뷰 <span class="text-muted-soft">주별·총</span></th>
                        <th colspan="5" class="text-center px-2 py-1 font-semibold" style="border-left:1px solid var(--color-hairline-soft);">블로그 리뷰 <span class="text-muted-soft">주별·총</span></th>
                        <th rowspan="2" class="text-right px-2 py-2 font-semibold" style="border-left:1px solid var(--color-hairline-soft);">평점</th>
                        <th rowspan="2" class="text-right px-2 py-2 font-semibold">정보충실</th>
                        <th rowspan="2" class="text-right px-2 py-2 font-semibold">N1</th>
                        <th rowspan="2" class="text-right px-2 py-2 font-semibold">N2</th>
                        <th rowspan="2" class="text-right px-3 py-2 font-semibold">N3</th>
                    </tr>
                    <tr class="text-muted-soft" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                        @foreach ($dates as $d)
                            <th class="text-center px-1 py-1 font-medium @if($loop->first) text-ink @endif" style="@if($loop->first)border-left:1px solid var(--color-hairline-soft);@endif">{{ \Illuminate\Support\Carbon::parse($d)->format('n/j') }}</th>
                        @endforeach
                        <th class="text-center px-1 py-1" style="border-left:1px solid var(--color-hairline-soft);">1주</th><th class="px-1">2주</th><th class="px-1">3주</th><th class="px-1">4주</th><th class="px-1 text-muted">총</th>
                        <th class="text-center px-1 py-1" style="border-left:1px solid var(--color-hairline-soft);">1주</th><th class="px-1">2주</th><th class="px-1">3주</th><th class="px-1">4주</th><th class="px-1 text-muted">총</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr style="border-top:1px solid var(--color-hairline-soft);{{ $r->is_mine ? 'background:color-mix(in srgb,var(--color-primary) 5%,var(--color-canvas));' : '' }}">
                            <td class="px-2 py-2 text-right text-muted" style="font-size:var(--fs-xs);">{{ $r->rnk < 300 ? $r->rnk : '—' }}</td>
                            <td class="px-3 py-2">
                                <span class="text-ink" style="font-size:var(--fs-xs);font-weight:{{ $r->is_mine ? 700 : 500 }};">{{ $r->is_mine ? '⭐ ' : '' }}{{ $r->name }}</span>
                                @if ($r->tier == 1)<span class="text-muted-soft" style="font-size:var(--fs-xs);"> 리스트</span>@endif
                            </td>
                            @foreach ($dates as $i => $d)
                                @php
                                    $rk = $r->daily[$d] ?? null;
                                    $prevD = $dates[$i + 1] ?? null;
                                    $prk = $prevD ? ($r->daily[$prevD] ?? null) : null;
                                    $col = 'var(--color-muted)';
                                    if ($rk !== null && $prk !== null && $rk < 300 && $prk < 300) {
                                        $col = $rk < $prk ? 'var(--color-primary)' : ($rk > $prk ? 'var(--color-error)' : 'var(--color-muted)');
                                    }
                                @endphp
                                <td class="text-center px-1 py-2" style="font-size:var(--fs-xs);color:{{ $col }};@if($i===0)border-left:1px solid var(--color-hairline-soft);@endif">{{ ($rk !== null && $rk > 0 && $rk < 300) ? $rk : '·' }}</td>
                            @endforeach
                            @for ($k = 0; $k < 4; $k++)
                                <td class="text-center px-1 py-2" style="font-size:var(--fs-xs);@if($k===0)border-left:1px solid var(--color-hairline-soft);@endif">@if($r->wv && $r->wv[$k] > 0)<span class="text-ink">{{ $r->wv[$k] }}</span>@else<span class="text-muted-soft">·</span>@endif</td>
                            @endfor
                            <td class="text-center px-1 py-2 text-muted" style="font-size:var(--fs-xs);">{{ $r->visitor !== null ? number_format($r->visitor) : '—' }}</td>
                            @for ($k = 0; $k < 4; $k++)
                                <td class="text-center px-1 py-2" style="font-size:var(--fs-xs);@if($k===0)border-left:1px solid var(--color-hairline-soft);@endif">@if($r->wb && $r->wb[$k] > 0)<span class="text-ink">{{ $r->wb[$k] }}</span>@else<span class="text-muted-soft">·</span>@endif</td>
                            @endfor
                            <td class="text-center px-1 py-2 text-muted" style="font-size:var(--fs-xs);">{{ $r->blog !== null ? number_format($r->blog) : '—' }}</td>
                            <td class="px-2 py-2 text-right text-muted" style="font-size:var(--fs-xs);border-left:1px solid var(--color-hairline-soft);">{{ $r->score !== null ? number_format($r->score, 2) : '—' }}</td>
                            <td class="px-2 py-2 text-right text-ink" style="font-size:var(--fs-xs);">{{ $fmt($r->d7) }}</td>
                            <td class="px-2 py-2 text-right text-ink" style="font-size:var(--fs-xs);">{{ $fmt($r->n1) }}</td>
                            <td class="px-2 py-2 text-right text-ink" style="font-size:var(--fs-xs);">{{ $fmt($r->n2) }}</td>
                            <td class="px-3 py-2 text-right text-ink" style="font-size:var(--fs-xs);font-weight:600;">{{ $fmt($r->n3) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-muted-soft mb-6" style="font-size:var(--fs-xs);">N1 유사도·N2 관련성·N3 랭킹 및 세부지표는 관측 신호 기반 <b>자체 추정치</b>이며 네이버 공식 점수가 아닙니다. 일별 순위 색: <span style="color:var(--color-primary);">상승</span>/<span style="color:var(--color-error);">하락</span>.</p>

        <div class="text-center">
            <a href="/" class="btn btn-primary btn-sm" style="text-decoration:none;">rankfree에서 내 플레이스 무료 분석하기 →</a>
        </div>
    @endunless
</div>
</body>
</html>
