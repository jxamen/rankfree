@extends('admin.layout')
@section('page-title', '자동 수집 현황')

@section('admin-content')
<x-console.page-head title="자동 수집 현황" desc="스케줄러에 등록된 자동 작업이 <b>무엇을 언제 수집하는지</b>와 데이터별 최근 수집 시각입니다 — 열람 전용" />

{{-- 자동 작업 테이블 — routes/console.php 스케줄 정의를 그대로 읽는다(별도 관리 목록 아님) --}}
<div class="card p-0 mb-4">
    <div class="px-5 pt-4 pb-3 flex items-center gap-2 flex-wrap">
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">자동 작업 <span class="font-mono text-muted-soft" style="font-weight:400;">{{ count($jobs) }}</span></div>
        <div class="text-muted-soft" style="font-size:var(--fs-xs);">시간은 KST · 서버 크론이 매분 <span class="font-mono">schedule:run</span> 을 돌려야 실행됩니다</div>
    </div>
    <div style="overflow-x:auto;">
        <table class="w-full" style="font-size:var(--fs-xs);border-collapse:collapse;">
            <thead>
                <tr class="text-muted-soft" style="border-top:1px solid var(--color-hairline);border-bottom:1px solid var(--color-hairline);">
                    <th class="text-left font-semibold px-5 py-2.5" style="white-space:nowrap;">작업</th>
                    <th class="text-left font-semibold px-3 py-2.5">수집 데이터</th>
                    <th class="text-left font-semibold px-3 py-2.5" style="white-space:nowrap;">주기</th>
                    <th class="text-left font-semibold px-3 py-2.5" style="white-space:nowrap;">다음 실행</th>
                    <th class="text-left font-semibold px-3 py-2.5" style="white-space:nowrap;">최근 데이터</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($jobs as $job)
                    <tr style="border-bottom:1px solid var(--color-hairline);">
                        <td class="px-5 py-2.5 font-mono text-ink" style="white-space:nowrap;">{{ $job['command'] }}</td>
                        <td class="px-3 py-2.5 text-muted" style="min-width:220px;line-height:1.55;">{{ $job['desc'] }}</td>
                        <td class="px-3 py-2.5 text-ink" style="white-space:nowrap;">{{ $job['freq'] }}</td>
                        <td class="px-3 py-2.5 font-mono text-muted" style="white-space:nowrap;" title="{{ $job['next']?->format('Y-m-d H:i') }}">
                            {{ $job['next']?->format('m-d H:i') ?? '—' }}
                        </td>
                        <td class="px-3 py-2.5" style="white-space:nowrap;">
                            @if ($job['last'])
                                <span class="font-mono text-ink" title="{{ $job['last']->format('Y-m-d H:i:s') }}">{{ $job['last']->format('m-d H:i') }}</span>
                                <span class="text-muted-soft">· {{ $job['last']->diffForHumans() }}</span>
                            @elseif ($job['last_note'])
                                <span class="text-muted-soft">{{ $job['last_note'] }}</span>
                            @else
                                <span class="text-muted-soft">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- 스케줄 게이트(.env 토글) — 꺼져 있으면 해당 작업은 위 목록에 아예 안 올라온다 --}}
<div class="card p-5">
    <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">자동화 스위치</div>
    <p class="text-muted-soft mb-3" style="font-size:var(--fs-xs);line-height:1.55;">
        .env 로 켜고 끄는 게이트입니다 — <b>꺼짐</b>이면 그 작업은 위 자동 작업 목록에 나타나지 않습니다. 변경은 서버 .env 수정 후 <span class="font-mono">config:cache</span> 재실행.
    </p>
    <div class="flex flex-col gap-2">
        @foreach ($gates as $gate)
            <div class="flex items-center gap-2 flex-wrap" style="font-size:var(--fs-xs);">
                @if ($gate['on'])
                    <span class="badge border border-hairline" style="color:var(--color-success);">켜짐</span>
                @else
                    <span class="badge border border-hairline text-muted-soft">꺼짐</span>
                @endif
                <span class="text-ink font-semibold">{{ $gate['label'] }}</span>
                <span class="text-muted-soft">{{ $gate['covers'] }}</span>
                <span class="font-mono text-muted-soft">{{ $gate['env'] }}</span>
            </div>
        @endforeach
    </div>
</div>
@endsection
