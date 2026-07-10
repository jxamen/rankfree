@extends('console.layout')
@section('page-title', '순위 추적')

@section('console-content')
<div style="max-width:960px;">
    {{-- 사용량 --}}
    <div class="flex items-center justify-between mb-5 flex-wrap gap-2">
        <div class="text-muted" style="font-size:14px;">
            추적 중 <b class="text-ink">{{ $usedSlots }}</b> / {{ $maxSlots < 0 ? '무제한' : $maxSlots.'개' }}
            <span class="text-muted-soft">· 매일 자동 갱신</span>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,#fff);color:var(--color-error);font-size:13px;">{{ $errors->first() }}</div>
    @endif

    {{-- 슬롯 추가 --}}
    <form method="POST" action="{{ route('console.rank.store') }}" class="card p-4 mb-6 flex gap-2 items-end flex-wrap">
        @csrf
        <div style="flex:1;min-width:150px;"><label class="block text-muted mb-1" style="font-size:12px;">키워드</label><input name="keyword" class="input" placeholder="강남 미용실" required></div>
        <div style="flex:1;min-width:170px;"><label class="block text-muted mb-1" style="font-size:12px;">내 플레이스 (URL·ID·업체명)</label><input name="place" class="input" placeholder="플레이스 URL 또는 업체명" required></div>
        <div style="width:130px;"><label class="block text-muted mb-1" style="font-size:12px;">라벨 <span class="text-muted-soft">(선택)</span></label><input name="label" class="input" placeholder="예: 본점"></div>
        <button type="submit" class="btn btn-primary" @disabled($maxSlots >= 0 && $usedSlots >= $maxSlots)>＋ 추적 추가</button>
    </form>

    {{-- 슬롯 목록 --}}
    <div class="card overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="text-muted" style="font-size:12px;">
                    <th class="text-left px-5 py-3 font-semibold">키워드 / 플레이스</th>
                    <th class="text-right px-3 py-3 font-semibold">현재 순위</th>
                    <th class="text-right px-3 py-3 font-semibold">리뷰</th>
                    <th class="text-center px-3 py-3 font-semibold">추이</th>
                    <th class="text-right px-5 py-3 font-semibold">갱신 / 삭제</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($slots as $slot)
                    @php
                        $rs = $slot->records->filter(fn ($r) => $r->rank > 0 && $r->rank < 300)->values();
                        $spark = null;
                        if ($rs->count() >= 2) {
                            $min = $rs->min('rank'); $max = $rs->max('rank'); $span = max(1, $max - $min);
                            $w = 110; $h = 28; $n = $rs->count();
                            $spark = $rs->map(fn ($r, $i) => round(($n > 1 ? $i / ($n - 1) : 0) * $w, 1).','.round((($r->rank - $min) / $span) * ($h - 6) + 3, 1))->implode(' ');
                        }
                    @endphp
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="px-5 py-3">
                            <div class="text-ink font-medium" style="font-size:14px;">{{ $slot->keyword }}</div>
                            <div class="text-muted-soft" style="font-size:12px;">{{ $slot->label ? $slot->label.' · ' : '' }}{{ $slot->place_name ?: ($slot->place_id ? 'ID '.$slot->place_id : $slot->place_url) }}</div>
                        </td>
                        <td class="px-3 py-3 text-right">
                            @if ($slot->last_rank === null)
                                <span class="text-muted-soft" style="font-size:13px;">미확인</span>
                            @elseif ($slot->last_rank > 0 && $slot->last_rank < 300)
                                <span class="font-display text-ink" style="font-size:16px;">{{ $slot->last_rank }}위</span>
                            @elseif ($slot->last_rank < 0)
                                <span style="color:var(--color-error);font-size:13px;">차단</span>
                            @else
                                <span class="text-muted-soft" style="font-size:13px;">300+</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right text-muted" style="font-size:13px;">{{ $slot->last_review_count !== null ? number_format($slot->last_review_count) : '—' }}</td>
                        <td class="px-3 py-3 text-center">
                            @if ($spark)
                                <svg width="110" height="28" style="vertical-align:middle;"><polyline fill="none" stroke="var(--color-primary)" stroke-width="1.8" points="{{ $spark }}"/></svg>
                            @else
                                <span class="text-muted-soft" style="font-size:12px;">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right text-nowrap">
                            <form method="POST" action="{{ route('console.rank.run', $slot) }}" style="display:inline;">@csrf<button type="submit" class="btn btn-secondary btn-sm">지금 확인</button></form>
                            <form method="POST" action="{{ route('console.rank.destroy', $slot) }}" style="display:inline;" onsubmit="return confirm('삭제하시겠습니까?')">@csrf @method('DELETE')<button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center" style="padding:56px 20px;color:var(--color-muted);">
                        <div style="font-size:28px;opacity:.4;">📈</div>
                        <p class="mt-2" style="font-size:14px;">추적 중인 키워드가 없습니다. 위에서 키워드와 내 플레이스를 추가하세요.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="text-muted-soft mt-3" style="font-size:12px;">순위는 매일 자동 기록되며, 추이 그래프는 위쪽일수록 상위 순위입니다. 즉시 갱신은 "지금 확인".</p>
</div>
@endsection
