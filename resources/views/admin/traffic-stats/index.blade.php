@extends('admin.layout')
@section('page-title', '방문 분석')

@section('admin-content')
<x-console.page-head title="방문 분석">
    <x-slot:desc>GA4 <b>방문 통계</b>(사용자·세션·페이지뷰·유입 채널){{ $propertyId ? ' · 속성 '.$propertyId : '' }}{{ $lastCollectedAt ? ' · 마지막 수집 '.\Illuminate\Support\Carbon::parse($lastCollectedAt)->timezone('Asia/Seoul')->format('m-d H:i') : '' }}</x-slot:desc>
    <form method="POST" action="{{ route('admin.traffic-stats.collect') }}">@csrf
        <button type="submit" class="btn btn-secondary btn-sm">지금 수집</button>
    </form>
</x-console.page-head>

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

@if ($daily->isEmpty())
    {{-- 데이터 없음 — 연동 안내 --}}
    <div class="card p-6 mb-4">
        <div class="text-ink font-semibold mb-2" style="font-size:var(--fs-sm);">GA4 연동 안내</div>
        <ol class="text-body" style="font-size:var(--fs-xs);line-height:2;padding-left:18px;list-style:decimal;">
            <li><a href="{{ route('admin.settings') }}" class="text-accent hover:underline">환경설정 › 외부 연동</a>에서 <b class="text-ink">[구글 계정으로 연동]</b>을 클릭합니다
                — GA4 속성에 접근 가능한 구글 계정으로 동의하면 끝.
                {!! \App\Support\GoogleToken::available() ? '<span style="color:var(--color-success);">✓ 연동됨</span>' : '<span style="color:var(--color-error);">— 아직 미연동</span>' !!}
            </li>
            <li>GA4 관리 → 속성 설정의 <b class="text-ink">속성 ID(숫자)</b>를 <a href="{{ route('admin.settings') }}" class="text-accent hover:underline">환경설정 › 외부 연동</a>에 등록합니다. {{ $propertyId ? '✓ 등록됨('.$propertyId.')' : '— 미등록' }}</li>
            <li class="text-muted-soft">(대안) 서비스 계정 키 사용 시 GA4 속성 액세스 관리에 서비스 계정 이메일을 뷰어로 추가하세요.{{ $serviceEmail ? ' — 서비스 계정: '.$serviceEmail : '' }}</li>
            <li>최초 적재: <code>php artisan ga:collect --days=400</code> (이후 매일 04:10 자동 수집) 또는 우측 상단 <b class="text-ink">[지금 수집]</b>.</li>
        </ol>
    </div>
@else
    {{-- 기간 선택 --}}
    <div class="flex items-center gap-2 mb-4">
        @foreach ([7 => '7일', 28 => '28일', 90 => '90일'] as $d => $label)
            <a href="{{ route('admin.traffic-stats', ['days' => $d]) }}" class="btn btn-sm {{ $days === $d ? 'btn-primary' : 'btn-secondary' }}">{{ $label }}</a>
        @endforeach
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">· 어제까지의 확정 데이터 기준(일별 사용자 합산)</span>
    </div>

    {{-- 요약 --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        @foreach ([
            ['사용자', number_format($totals['users']), 'var(--color-primary)'],
            ['신규 사용자', number_format($totals['new_users']), 'var(--color-ink)'],
            ['세션', number_format($totals['sessions']), 'var(--color-ink)'],
            ['페이지뷰', number_format($totals['pageviews']), 'var(--color-ink)'],
        ] as [$lab, $val, $color])
            <div class="card p-5">
                <div class="text-muted" style="font-size:var(--fs-xs);">{{ $lab }}</div>
                <div class="font-display mt-1" style="font-size:var(--fs-xl);color:{{ $color }};font-family:var(--font-mono);">{{ $val }}</div>
            </div>
        @endforeach
    </div>

    {{-- 일별 사용자 추이 --}}
    <div class="card p-5 mb-4">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">일별 사용자 추이</div>
        @php $maxUsers = max(1, (int) $daily->max('users')); @endphp
        <div style="display:flex;align-items:flex-end;gap:2px;height:160px;">
            @foreach ($daily as $d)
                <div title="{{ $d->date->format('m-d') }} · 사용자 {{ number_format($d->users) }} · 세션 {{ number_format($d->sessions) }} · 페이지뷰 {{ number_format($d->pageviews) }}"
                     style="flex:1;min-width:3px;background:var(--color-primary);opacity:.85;border-radius:3px 3px 0 0;height:{{ max(2, round($d->users / $maxUsers * 100)) }}%;"></div>
            @endforeach
        </div>
        <div class="flex justify-between text-muted-soft mt-1" style="font-size:var(--fs-xs);">
            <span>{{ $daily->first()?->date->format('m-d') }}</span>
            <span>{{ $daily->last()?->date->format('m-d') }}</span>
        </div>
    </div>

    {{-- 유입 채널 --}}
    <div class="card p-5 mb-4" style="max-width:640px;">
        <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">유입 채널 (세션)</div>
        @php $chTotal = max(1, (int) $channels->sum('sessions')); @endphp
        <div class="flex flex-col gap-2">
            @foreach ($channels as $ch)
                <div class="flex items-center gap-2" style="font-size:var(--fs-xs);">
                    <span class="text-muted truncate" style="width:130px;">{{ $ch->value }}</span>
                    <div style="flex:1;background:var(--color-surface-strong);border-radius:99px;height:8px;overflow:hidden;">
                        <div style="width:{{ round($ch->sessions / $chTotal * 100) }}%;height:100%;background:var(--color-primary);border-radius:99px;"></div>
                    </div>
                    <span class="font-mono text-ink" style="width:110px;text-align:right;">{{ number_format($ch->sessions) }} ({{ round($ch->sessions / $chTotal * 100) }}%)</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- 유입 소스 --}}
        <div class="card overflow-hidden">
            <div class="px-5 py-3 border-b border-hairline-soft text-ink font-semibold" style="font-size:var(--fs-xs);">유입 소스</div>
            <div style="overflow-x:auto;max-height:480px;overflow-y:auto;">
                <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
                    <thead>
                        <tr class="text-muted" style="border-bottom:1px solid var(--color-hairline-soft);">
                            <th class="text-left px-5 py-2 font-semibold">소스</th>
                            <th class="text-right px-3 py-2 font-semibold">사용자</th>
                            <th class="text-right px-5 py-2 font-semibold">세션</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sources as $s)
                            <tr style="border-top:1px solid var(--color-hairline-soft);">
                                <td class="px-5 py-2 text-ink">{{ $s->value }}</td>
                                <td class="px-3 py-2 text-right font-mono text-muted">{{ number_format($s->users) }}</td>
                                <td class="px-5 py-2 text-right font-mono">{{ number_format($s->sessions) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted-soft" style="padding:24px;">데이터 없음</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 인기 페이지 --}}
        <div class="card overflow-hidden">
            <div class="px-5 py-3 border-b border-hairline-soft text-ink font-semibold" style="font-size:var(--fs-xs);">인기 페이지</div>
            <div style="overflow-x:auto;max-height:480px;overflow-y:auto;">
                <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
                    <thead>
                        <tr class="text-muted" style="border-bottom:1px solid var(--color-hairline-soft);">
                            <th class="text-left px-5 py-2 font-semibold">페이지</th>
                            <th class="text-right px-3 py-2 font-semibold">사용자</th>
                            <th class="text-right px-5 py-2 font-semibold">페이지뷰</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pages as $p)
                            <tr style="border-top:1px solid var(--color-hairline-soft);">
                                <td class="px-5 py-2" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <a href="{{ 'https://rankfree.kr'.$p->value }}" target="_blank" class="text-accent hover:underline" title="{{ $p->value }}">{{ $p->value }}</a>
                                </td>
                                <td class="px-3 py-2 text-right font-mono text-muted">{{ number_format($p->users) }}</td>
                                <td class="px-5 py-2 text-right font-mono">{{ number_format($p->pageviews) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted-soft" style="padding:24px;">데이터 없음</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
@endsection
