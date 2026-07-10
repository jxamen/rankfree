@extends('console.layout')
@section('page-title', '경쟁 분석')

@section('console-content')
<div style="max-width:1000px;">
    <p class="text-muted mb-4" style="font-size:14px;">
        순위추적 중인 <b class="text-ink">키워드 × 플레이스</b>의 SEO 경쟁력을 분석합니다. 같은 키워드 상위 경쟁사와 비교해
        <b class="text-ink">N1 유사도·N2 관련성·N3 랭킹</b> 점수를 산출합니다.
        <span class="text-muted-soft">점수는 관측 신호 기반 자체 추정치입니다.</span>
    </p>

    <div class="card overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="text-muted" style="font-size:12px;">
                    <th class="text-left px-5 py-3 font-semibold">키워드 / 플레이스</th>
                    <th class="text-right px-3 py-3 font-semibold">순위</th>
                    <th class="text-right px-3 py-3 font-semibold">N1 유사도</th>
                    <th class="text-right px-3 py-3 font-semibold">N2 관련성</th>
                    <th class="text-right px-3 py-3 font-semibold">N3 랭킹</th>
                    <th class="text-right px-5 py-3 font-semibold">분석</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($slots as $slot)
                    @php $sc = $latest[$slot->id] ?? null; @endphp
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-5 py-3">
                            <div class="text-ink font-medium" style="font-size:14px;">{{ $slot->keyword }}</div>
                            <div class="text-muted-soft" style="font-size:12px;">{{ $slot->place_name ?: ($slot->place_id ? 'ID '.$slot->place_id : '—') }}</div>
                        </td>
                        <td class="px-3 py-3 text-right">
                            @if ($sc && $sc->rnk > 0 && $sc->rnk < 300)
                                <span class="font-display text-ink" style="font-size:15px;">{{ $sc->rnk }}위</span>
                            @elseif ($sc)
                                <span class="text-muted-soft" style="font-size:13px;">300+</span>
                            @else
                                <span class="text-muted-soft" style="font-size:12px;">미분석</span>
                            @endif
                        </td>
                        @foreach (['n1', 'n2', 'n3'] as $m)
                            <td class="px-3 py-3 text-right">
                                @if ($sc && $sc->$m !== null)
                                    <span class="text-ink font-medium" style="font-size:14px;">{{ round($sc->$m) }}</span>
                                @else
                                    <span class="text-muted-soft" style="font-size:13px;">—</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="px-5 py-3 text-right text-nowrap">
                            <a href="{{ route('console.compete.show', $slot) }}" class="btn btn-ghost btn-sm" @if (! $sc) style="opacity:.45;pointer-events:none;" @endif>상세</a>
                            <form method="POST" action="{{ route('console.compete.analyze', $slot) }}" style="display:inline;" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='분석 중…';">
                                @csrf
                                <button type="submit" class="btn btn-secondary btn-sm">{{ $sc ? '재분석' : '분석' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center" style="padding:56px 20px;color:var(--color-muted);">
                        <div style="font-size:28px;opacity:.4;">📊</div>
                        <p class="mt-2" style="font-size:14px;">먼저 <a href="{{ route('console.rank') }}" style="color:var(--color-accent);">순위 추적</a>에서 키워드와 플레이스를 등록하세요.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="text-muted-soft mt-3" style="font-size:12px;">"분석"은 상위 경쟁사 상세를 수집해 점수를 산출하므로 20~40초 걸릴 수 있습니다. 결과는 매일 스냅샷으로 누적됩니다.</p>
</div>
@endsection
