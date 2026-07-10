@extends('console.layout')
@section('page-title', '경쟁 분석 · '.$slot->keyword)

@section('console-content')
@php
    $bar = function ($v, $color = 'var(--color-primary)') {
        $w = $v === null ? 0 : max(0, min(100, (float) $v));
        return '<div style="height:6px;background:var(--color-surface-strong);border-radius:99px;overflow:hidden;"><div style="height:100%;width:'.$w.'%;background:'.$color.';"></div></div>';
    };
    $fmt = fn ($v) => $v === null ? '—' : round($v);
    $dimMeta = [
        'd1' => ['방문자 리뷰', .18], 'd2' => ['블로그 리뷰', .09], 'd3' => ['예약 리뷰', .07], 'd4' => ['평점', .12],
        'd5' => ['저장수', .08], 'd7' => ['정보 충실성', .14], 'd9' => ['최근 활동', .20], 'd10' => ['리뷰 영향력', .12],
    ];
@endphp

<div style="max-width:1040px;">
    <a href="{{ route('console.compete') }}" class="text-muted hover:text-ink" style="font-size:13px;">← 경쟁 분석 목록</a>

    <div class="flex items-end justify-between flex-wrap gap-3 mt-2 mb-5">
        <div>
            <div class="font-display text-ink" style="font-size:22px;">{{ $slot->keyword }}</div>
            <div class="text-muted-soft" style="font-size:13px;">{{ $slot->place_name ?: ('ID '.$slot->place_id) }} @if ($ymd)· 분석일 {{ \Illuminate\Support\Carbon::parse($ymd)->format('Y.m.d') }}@endif</div>
        </div>
        <form method="POST" action="{{ route('console.compete.analyze', $slot) }}" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='분석 중…';">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm">{{ $ymd ? '분석 갱신' : '분석 시작' }}</button>
        </form>
    </div>

    @unless ($ymd)
        <div class="card p-8 text-center">
            <div style="font-size:30px;opacity:.4;">📊</div>
            <p class="text-muted mt-2" style="font-size:14px;">아직 분석 데이터가 없습니다. 위 <b class="text-ink">분석 시작</b>을 눌러 경쟁사 대비 점수를 산출하세요. (20~40초 소요)</p>
        </div>
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
                    <div class="text-muted" style="font-size:12px;">{{ $label }}</div>
                    <div class="font-display text-ink mt-1" style="font-size:24px;">{{ $val }}</div>
                    @if ($sc !== null)<div class="mt-2">{!! $bar($sc) !!}</div>@endif
                </div>
            @endforeach
        </div>

        {{-- 경쟁 비교표 --}}
        <div class="card overflow-x-auto mb-6">
            <table class="w-full" style="min-width:720px;">
                <thead>
                    <tr class="text-muted" style="font-size:12px;">
                        <th class="text-right px-3 py-3 font-semibold" style="width:44px;">순위</th>
                        <th class="text-left px-3 py-3 font-semibold">매장</th>
                        <th class="text-right px-3 py-3 font-semibold">방문자</th>
                        <th class="text-right px-3 py-3 font-semibold">블로그</th>
                        <th class="text-right px-3 py-3 font-semibold">평점</th>
                        <th class="text-right px-3 py-3 font-semibold">정보충실</th>
                        <th class="text-right px-3 py-3 font-semibold">N1</th>
                        <th class="text-right px-3 py-3 font-semibold">N2</th>
                        <th class="text-right px-4 py-3 font-semibold">N3</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr style="border-top:1px solid var(--color-hairline-soft);{{ $r->is_mine ? 'background:color-mix(in srgb,var(--color-primary) 5%,#fff);' : '' }}">
                            <td class="px-3 py-2.5 text-right text-muted" style="font-size:13px;">{{ $r->rnk < 300 ? $r->rnk : '—' }}</td>
                            <td class="px-3 py-2.5">
                                <span class="text-ink" style="font-size:13px;font-weight:{{ $r->is_mine ? 700 : 500 }};">{{ $r->is_mine ? '⭐ ' : '' }}{{ $r->name }}</span>
                                @if ($r->tier == 1)<span class="text-muted-soft" style="font-size:11px;"> · 리스트</span>@endif
                            </td>
                            <td class="px-3 py-2.5 text-right text-muted" style="font-size:13px;">{{ $r->visitor !== null ? number_format($r->visitor) : '—' }}</td>
                            <td class="px-3 py-2.5 text-right text-muted" style="font-size:13px;">{{ $r->blog !== null ? number_format($r->blog) : '—' }}</td>
                            <td class="px-3 py-2.5 text-right text-muted" style="font-size:13px;">{{ $r->score !== null ? number_format($r->score, 2) : '—' }}</td>
                            <td class="px-3 py-2.5 text-right text-ink" style="font-size:13px;">{{ $fmt($r->d7) }}</td>
                            <td class="px-3 py-2.5 text-right text-ink" style="font-size:13px;">{{ $fmt($r->n1) }}</td>
                            <td class="px-3 py-2.5 text-right text-ink" style="font-size:13px;">{{ $fmt($r->n2) }}</td>
                            <td class="px-4 py-2.5 text-right text-ink" style="font-size:13px;font-weight:600;">{{ $fmt($r->n3) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- 내 매장 점수 근거 --}}
        @if ($explain)
            <div class="grid md:grid-cols-2 gap-4 mb-6">
                {{-- N1 유사도 구성 --}}
                <div class="card p-5">
                    <div class="text-ink font-semibold mb-3" style="font-size:14px;">N1 유사도 구성 <span class="text-muted-soft" style="font-weight:400;">(내 매장)</span></div>
                    @php $comp = $explain['components']; $cmpMeta = ['L' => '지역 일치', 'B' => '업종 일치', 'T' => '대표키워드', 'M' => '상호 일치']; @endphp
                    @foreach ($cmpMeta as $k => $lab)
                        <div class="flex items-center gap-3 mb-2">
                            <div class="text-muted" style="font-size:12px;width:72px;">{{ $lab }}</div>
                            <div style="flex:1;">{!! $bar($comp[$k] === null ? 0 : $comp[$k] * 100) !!}</div>
                            <div class="text-ink text-right" style="font-size:12px;width:44px;">{{ $comp[$k] === null ? 'N/A' : round($comp[$k] * 100).'%' }}</div>
                        </div>
                    @endforeach
                    <div class="text-muted-soft mt-2" style="font-size:11px;">키워드 "{{ $slot->keyword }}"와 내 플레이스의 지역·업종·대표키워드·상호 매칭.</div>
                </div>

                {{-- N2 관련성 차원 --}}
                <div class="card p-5">
                    <div class="text-ink font-semibold mb-3" style="font-size:14px;">N2 관련성 차원</div>
                    @foreach ($dimMeta as $k => [$lab, $w])
                        @php $v = $explain['dims']?->$k; @endphp
                        <div class="flex items-center gap-3 mb-1.5">
                            <div class="text-muted" style="font-size:12px;width:72px;">{{ $lab }}</div>
                            <div style="flex:1;">{!! $bar($v) !!}</div>
                            <div class="text-ink text-right" style="font-size:12px;width:36px;">{{ $v === null ? '—' : round($v) }}</div>
                            <div class="text-muted-soft text-right" style="font-size:11px;width:34px;">{{ intval($w * 100) }}%</div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- 정보 충실성 체크리스트 --}}
            <div class="card p-5 mb-6">
                <div class="text-ink font-semibold mb-3" style="font-size:14px;">정보 충실성 (D7 = {{ $fmt($mine?->d7) }})</div>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-2">
                    @foreach ($explain['seo'] as $it)
                        @if ($it['avail'])
                            <div class="flex items-center justify-between" style="font-size:12px;">
                                <span class="text-muted">{{ $it['label'] }}</span>
                                <span style="color:{{ $it['grade'] >= 0.99 ? 'var(--color-primary)' : ($it['grade'] > 0 ? 'var(--color-ink)' : 'var(--color-muted-soft)') }};">
                                    {{ $it['raw'] }} {{ $it['grade'] >= 0.99 ? '✓' : ($it['grade'] > 0 ? '·' : '✕') }}
                                </span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        {{-- 리뷰 품질 (D9 최근성 · D10 영향력 근거) --}}
        @if ($explain && $explain['daily'] && $explain['daily']->review_quality)
            @php $rq = $explain['daily']->review_quality; $au = $rq['authority'] ?? null; @endphp
            <div class="card p-5 mb-6">
                <div class="text-ink font-semibold mb-3" style="font-size:14px;">리뷰 품질 <span class="text-muted-soft" style="font-weight:400;">(최근 4주 · D9 최근성 {{ $fmt($explain['dims']?->d9) }} · D10 영향력 {{ $fmt($explain['dims']?->d10) }})</span></div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-3">
                    <div><div class="text-muted-soft" style="font-size:11px;">사진 포함</div><div class="text-ink font-display" style="font-size:18px;">{{ round(($rq['photo_ratio'] ?? 0) * 100) }}%</div></div>
                    @if ($au)
                        <div><div class="text-muted-soft" style="font-size:11px;">인플루언서 <span style="opacity:.7;">팔로워 100+</span></div><div class="text-ink font-display" style="font-size:18px;">{{ $au['infl'] }}<span style="font-size:12px;"> 명</span></div></div>
                        <div><div class="text-muted-soft" style="font-size:11px;">파워리뷰어 <span style="opacity:.7;">리뷰 100+</span></div><div class="text-ink font-display" style="font-size:18px;">{{ $au['power'] }}<span style="font-size:12px;"> 명</span></div></div>
                        <div><div class="text-muted-soft" style="font-size:11px;">평균 팔로워</div><div class="text-ink font-display" style="font-size:18px;">{{ number_format($au['avg_fol']) }}</div></div>
                    @endif
                </div>
                @if ($au && ! empty($au['top']))
                    <div class="text-muted-soft mb-1.5" style="font-size:11px;">주요 리뷰어</div>
                    <div class="flex flex-wrap gap-1.5 mb-2">
                        @foreach (array_slice($au['top'], 0, 5) as $t)
                            <span class="badge">{{ $t['n'] ?: '익명' }} · 팔로워 {{ number_format($t['f']) }}</span>
                        @endforeach
                    </div>
                @endif
                @if (! empty($rq['bloggers']))
                    <div class="text-muted-soft mb-1.5 mt-2" style="font-size:11px;">블로그 리뷰어</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($rq['bloggers'] as $bl)
                            <a href="https://blog.naver.com/{{ $bl['id'] }}" target="_blank" class="badge" style="text-decoration:none;">{{ $bl['n'] ?: $bl['id'] }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- 시계열 --}}
        @if ($series->count() >= 2)
            @php
                $w = 640; $h = 90; $n = $series->count();
                $line = function ($key, $color) use ($series, $w, $h, $n) {
                    $pts = $series->values()->map(function ($r, $i) use ($key, $w, $h, $n) {
                        $x = $n > 1 ? round($i / ($n - 1) * $w, 1) : 0;
                        $y = round($h - (max(0, min(100, (float) ($r->$key ?? 0))) / 100) * ($h - 8) - 4, 1);
                        return $x.','.$y;
                    })->implode(' ');
                    return '<polyline fill="none" stroke="'.$color.'" stroke-width="1.8" points="'.$pts.'"/>';
                };
            @endphp
            <div class="card p-5 mb-6">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-ink font-semibold" style="font-size:14px;">점수 추이</div>
                    <div class="flex gap-3" style="font-size:11px;">
                        <span style="color:var(--color-primary);">● N1</span>
                        <span style="color:#7c9cff;">● N2</span>
                        <span style="color:#f2a24d;">● N3</span>
                    </div>
                </div>
                <svg viewBox="0 0 {{ $w }} {{ $h }}" style="width:100%;height:90px;">
                    @for ($g = 0; $g <= 100; $g += 25)
                        <line x1="0" x2="{{ $w }}" y1="{{ $h - ($g / 100) * ($h - 8) - 4 }}" y2="{{ $h - ($g / 100) * ($h - 8) - 4 }}" stroke="var(--color-hairline-soft)" stroke-width="1"/>
                    @endfor
                    {!! $line('n1', 'var(--color-primary)') !!}
                    {!! $line('n2', '#7c9cff') !!}
                    {!! $line('n3', '#f2a24d') !!}
                </svg>
            </div>
        @endif

        <p class="text-muted-soft" style="font-size:11px;">N1 유사도·N2 관련성·N3 랭킹 및 세부지표(D1~D10)는 관측 신호 기반 <b>자체 추정치</b>이며 네이버 공식 점수가 아닙니다. 리뷰 최근성(D9)·영향력(D10)은 내 매장과 상위 경쟁사만 수집합니다.</p>
    @endunless
</div>
@endsection
