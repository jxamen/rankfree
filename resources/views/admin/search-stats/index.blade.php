@extends('admin.layout')
@section('page-title', '검색 유입 분석')

@section('admin-content')
<x-console.page-head title="검색 유입 분석">
    <x-slot:desc>구글 서치 콘솔 <b>검색 성과</b>(클릭·노출·CTR·평균 순위) · 속성 <b>{{ $property }}</b>{{ $lastCollectedAt ? ' · 마지막 수집 '.\Illuminate\Support\Carbon::parse($lastCollectedAt)->timezone('Asia/Seoul')->format('m-d H:i') : '' }}</x-slot:desc>
    <form method="POST" action="{{ route('admin.search-stats.collect') }}">@csrf
        <button type="submit" class="btn btn-secondary btn-sm">지금 수집</button>
    </form>
</x-console.page-head>

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

@if ($daily->isEmpty())
    {{-- 데이터 없음 — 연동 안내 --}}
    <div class="card p-6 mb-4">
        <div class="text-ink font-semibold mb-2" style="font-size:var(--fs-sm);">서치 콘솔 연동 안내</div>
        <ol class="text-body" style="font-size:var(--fs-xs);line-height:2;padding-left:18px;list-style:decimal;">
            <li><a href="{{ route('admin.settings') }}" class="text-accent hover:underline">환경설정 › 외부 연동</a>에서 <b class="text-ink">[구글 계정으로 연동]</b>을 클릭합니다
                — 서치 콘솔 <b class="text-ink">{{ $property }}</b> 속성에 접근 가능한 구글 계정으로 동의하면 끝.
                {!! $configured ? '<span style="color:var(--color-success);">✓ 연동됨</span>' : '<span style="color:var(--color-error);">— 아직 미연동</span>' !!}
            </li>
            <li class="text-muted-soft">(대안) 서비스 계정 키를 <code>.env GOOGLE_SERVICE_ACCOUNT_JSON</code>에 설정하고 서치 콘솔 속성에 사용자로 추가해도 됩니다.{{ $serviceEmail ? ' — 서비스 계정: '.$serviceEmail : '' }}</li>
            <li>최초 적재: <code>php artisan gsc:collect --days=480</code> (이후 매일 04:00 자동 수집) 또는 우측 상단 <b class="text-ink">[지금 수집]</b>.</li>
        </ol>
    </div>
@else
    {{-- 기간 선택 --}}
    <div class="flex items-center gap-2 mb-4">
        @foreach ([7 => '7일', 28 => '28일', 90 => '90일'] as $d => $label)
            <a href="{{ route('admin.search-stats', ['days' => $d]) }}" class="btn btn-sm {{ $days === $d ? 'btn-primary' : 'btn-secondary' }}">{{ $label }}</a>
        @endforeach
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">· 구글 반영이 2~3일 늦어 최근 며칠은 비어 보일 수 있습니다.</span>
    </div>

    {{-- 요약 --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        @foreach ([
            ['총 클릭', number_format($totals['clicks']), 'var(--color-primary)'],
            ['총 노출', number_format($totals['impressions']), 'var(--color-ink)'],
            ['평균 CTR', number_format($totals['ctr'] * 100, 1).'%', 'var(--color-ink)'],
            ['평균 게재순위', number_format($totals['position'], 1), 'var(--color-ink)'],
        ] as [$lab, $val, $color])
            <div class="card p-5">
                <div class="text-muted" style="font-size:var(--fs-xs);">{{ $lab }}</div>
                <div class="font-display mt-1" style="font-size:var(--fs-xl);color:{{ $color }};font-family:var(--font-mono);">{{ $val }}</div>
            </div>
        @endforeach
    </div>

    {{-- 일별 추이 — 클릭 막대 (hover: 상세) --}}
    <div class="card p-5 mb-4">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">일별 클릭 추이</div>
        @php $maxClicks = max(1, (int) $daily->max('clicks')); @endphp
        <div style="display:flex;align-items:flex-end;gap:2px;height:160px;">
            @foreach ($daily as $d)
                <div title="{{ $d->date->format('m-d') }} · 클릭 {{ number_format($d->clicks) }} · 노출 {{ number_format($d->impressions) }} · 순위 {{ number_format($d->position, 1) }}"
                     style="flex:1;min-width:3px;background:var(--color-primary);opacity:.85;border-radius:3px 3px 0 0;height:{{ max(2, round($d->clicks / $maxClicks * 100)) }}%;"></div>
            @endforeach
        </div>
        <div class="flex justify-between text-muted-soft mt-1" style="font-size:var(--fs-xs);">
            <span>{{ $daily->first()?->date->format('m-d') }}</span>
            <span>{{ $daily->last()?->date->format('m-d') }}</span>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        {{-- 상위 검색어 --}}
        <div class="card overflow-hidden">
            <div class="px-5 py-3 border-b border-hairline-soft text-ink font-semibold" style="font-size:var(--fs-xs);">상위 검색어</div>
            <div style="overflow-x:auto;max-height:520px;overflow-y:auto;">
                <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
                    <thead>
                        <tr class="text-muted" style="border-bottom:1px solid var(--color-hairline-soft);">
                            <th class="text-left px-5 py-2 font-semibold">검색어</th>
                            <th class="text-right px-3 py-2 font-semibold">클릭</th>
                            <th class="text-right px-3 py-2 font-semibold">노출</th>
                            <th class="text-right px-3 py-2 font-semibold">CTR</th>
                            <th class="text-right px-5 py-2 font-semibold">순위</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($topQueries as $q)
                            <tr style="border-top:1px solid var(--color-hairline-soft);">
                                <td class="px-5 py-2 text-ink">{{ $q->value }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ number_format($q->clicks) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-muted">{{ number_format($q->impressions) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-muted">{{ number_format($q->ctr * 100, 1) }}%</td>
                                <td class="px-5 py-2 text-right font-mono text-muted">{{ number_format($q->position, 1) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted-soft" style="padding:24px;">데이터 없음</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 상위 페이지 --}}
        <div class="card overflow-hidden">
            <div class="px-5 py-3 border-b border-hairline-soft text-ink font-semibold" style="font-size:var(--fs-xs);">상위 유입 페이지</div>
            <div style="overflow-x:auto;max-height:520px;overflow-y:auto;">
                <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
                    <thead>
                        <tr class="text-muted" style="border-bottom:1px solid var(--color-hairline-soft);">
                            <th class="text-left px-5 py-2 font-semibold">페이지</th>
                            <th class="text-right px-3 py-2 font-semibold">클릭</th>
                            <th class="text-right px-3 py-2 font-semibold">노출</th>
                            <th class="text-right px-5 py-2 font-semibold">순위</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($topPages as $p)
                            <tr style="border-top:1px solid var(--color-hairline-soft);">
                                <td class="px-5 py-2" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <a href="{{ $p->value }}" target="_blank" class="text-accent hover:underline" title="{{ $p->value }}">{{ \Illuminate\Support\Str::after($p->value, '//') }}</a>
                                </td>
                                <td class="px-3 py-2 text-right font-mono">{{ number_format($p->clicks) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-muted">{{ number_format($p->impressions) }}</td>
                                <td class="px-5 py-2 text-right font-mono text-muted">{{ number_format($p->position, 1) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted-soft" style="padding:24px;">데이터 없음</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- 기기별 --}}
    <div class="card p-5" style="max-width:520px;">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">기기별 클릭</div>
        @php $devTotal = max(1, (int) $devices->sum('clicks')); $devNames = ['MOBILE' => '모바일', 'DESKTOP' => 'PC', 'TABLET' => '태블릿']; @endphp
        <div class="flex flex-col gap-2">
            @foreach ($devices as $dv)
                <div class="flex items-center gap-2" style="font-size:var(--fs-xs);">
                    <span class="text-muted" style="width:64px;">{{ $devNames[$dv->value] ?? $dv->value }}</span>
                    <div style="flex:1;background:var(--color-surface-strong);border-radius:99px;height:8px;overflow:hidden;">
                        <div style="width:{{ round($dv->clicks / $devTotal * 100) }}%;height:100%;background:var(--color-primary);border-radius:99px;"></div>
                    </div>
                    <span class="font-mono text-ink" style="width:90px;text-align:right;">{{ number_format($dv->clicks) }} ({{ round($dv->clicks / $devTotal * 100) }}%)</span>
                </div>
            @endforeach
        </div>
    </div>
@endif
@endsection
