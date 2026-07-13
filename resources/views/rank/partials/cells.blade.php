{{-- 날짜별 순위 카드 그리드 (최신순) — 콘솔(rank)·공개 리포트(share) 공용.
     필요 스타일: .rf-cell (+ 콘솔은 .rf-slot.rf-collapsed .rf-metrics 접기) --}}
@php
    // 기간 검색($from/$to, 'Y-m-d') — 지정 시 해당 기간의 기록만 표시
    $from = $from ?? null;
    $to = $to ?? null;
    $recs = $slot->records
        ->when($from, fn ($c) => $c->filter(fn ($r) => $r->checked_date->toDateString() >= $from))
        ->when($to, fn ($c) => $c->filter(fn ($r) => $r->checked_date->toDateString() <= $to))
        ->sortByDesc('checked_date')->values()->take($from || $to ? 120 : 60);
    $isRestaurant = $slot->category === 'restaurant';
@endphp
<div class="p-4 flex flex-wrap gap-2">
    @forelse ($recs as $i => $rec)
        @php
            $prev = $recs[$i + 1] ?? null;
            $valid = $rec->rank > 0 && $rec->rank < 300;
            $prevValid = $prev && $prev->rank > 0 && $prev->rank < 300;
            // 순위 변동 = 전일순위 - 오늘순위 (상승 +·초록 / 하락 −·빨강. 예: 3위→72위 = -69)
            $diff = ($valid && $prevValid) ? $prev->rank - $rec->rank : null;
            // 지표 증감(전일 대비) — 0이면 표기 생략
            $delta = fn ($cur, $old) => ($cur === null || $old === null || $cur === $old) ? null : $cur - $old;
            $dRev = $delta($rec->review_count, $prev?->review_count);
            $dBlog = $delta($rec->blog_review_count, $prev?->blog_review_count);
            $dSave = $delta($rec->save_count, $prev?->save_count);
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
                    <span class="text-muted-soft" style="font-size:var(--fs-xs);">300+</span>
                @endif
            </div>
            <div class="rf-metrics text-left" style="font-size:var(--fs-xs);line-height:1.75;padding-left:10px;">
                <div><span class="text-muted-soft">영</span> <b class="text-ink">{{ $rec->review_count !== null ? number_format($rec->review_count) : '–' }}</b>@if ($dRev)<span style="font-size:var(--fs-xs);font-weight:700;color:{{ $dRev > 0 ? 'var(--color-success)' : 'var(--color-error)' }};"> {{ $dRev > 0 ? '+'.$dRev : $dRev }}</span>@endif</div>
                <div><span class="text-muted-soft">블</span> <b class="text-ink">{{ $rec->blog_review_count !== null ? number_format($rec->blog_review_count) : '–' }}</b>@if ($dBlog)<span style="font-size:var(--fs-xs);font-weight:700;color:{{ $dBlog > 0 ? 'var(--color-success)' : 'var(--color-error)' }};"> {{ $dBlog > 0 ? '+'.$dBlog : $dBlog }}</span>@endif</div>
                @if ($isRestaurant)
                    <div><span class="text-muted-soft">저장</span> <b class="text-ink">{{ $rec->save_count !== null ? number_format($rec->save_count) : '–' }}</b>@if ($dSave)<span style="font-size:var(--fs-xs);font-weight:700;color:{{ $dSave > 0 ? 'var(--color-success)' : 'var(--color-error)' }};"> {{ $dSave > 0 ? '+'.$dSave : $dSave }}</span>@endif</div>
                @endif
            </div>
        </div>
    @empty
        <div class="text-muted-soft" style="font-size:var(--fs-xs);padding:6px 4px;">{{ ($from || $to) ? '해당 기간의 기록이 없습니다.' : '아직 기록이 없습니다.' }}</div>
    @endforelse
</div>
