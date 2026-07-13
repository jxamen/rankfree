{{-- 날짜별 쇼핑 순위 카드 그리드 (최신순) — 콘솔(shop-rank)·공개 리포트 공용. 필요 스타일: .rf-cell --}}
@php
    // 기간 검색($from/$to, 'Y-m-d') — 지정 시 해당 기간의 기록만 표시
    $from = $from ?? null;
    $to = $to ?? null;
    $recs = $slot->records
        ->when($from, fn ($c) => $c->filter(fn ($r) => $r->checked_date->toDateString() >= $from))
        ->when($to, fn ($c) => $c->filter(fn ($r) => $r->checked_date->toDateString() <= $to))
        ->sortByDesc('checked_date')->values()->take($from || $to ? 120 : 60);
    $max = (int) config('rankfree.shopping.display', 100) * (int) config('rankfree.shopping.max_pages', 10);
@endphp
<div class="p-4 flex flex-wrap gap-2">
    @forelse ($recs as $i => $rec)
        @php
            $prev = $recs[$i + 1] ?? null;
            $valid = $rec->rank > 0 && $rec->rank <= $max;
            $prevValid = $prev && $prev->rank > 0 && $prev->rank <= $max;
            // 순위 변동 = 전일순위 - 오늘순위 (상승 +·초록 / 하락 −·빨강)
            $diff = ($valid && $prevValid) ? $prev->rank - $rec->rank : null;
        @endphp
        <div class="rf-cell rounded-lg border border-hairline text-center">
            <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $rec->checked_date->format('m-d') }}</div>
            <div style="font-size:var(--fs-md);line-height:1.4;white-space:nowrap;">
                @if ($valid)
                    <b class="text-ink font-display">{{ $rec->rank }}위</b>
                    @if ($diff !== null && $diff !== 0)
                        <span style="font-size:var(--fs-xs);font-weight:700;color:{{ $diff > 0 ? 'var(--color-success)' : 'var(--color-error)' }};">{{ $diff > 0 ? '+'.$diff : $diff }}</span>
                    @endif
                @elseif ($rec->rank < 0)
                    <span style="font-size:var(--fs-xs);color:var(--color-error);">차단</span>
                @else
                    <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ number_format($max) }}+</span>
                @endif
            </div>
        </div>
    @empty
        <div class="text-muted-soft" style="font-size:var(--fs-xs);padding:6px 4px;">{{ ($from || $to) ? '해당 기간의 기록이 없습니다.' : '아직 기록이 없습니다.' }}</div>
    @endforelse
</div>
