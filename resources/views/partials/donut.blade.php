{{-- 도넛 차트(블랙키위 스타일). $segs=[['label'=>,'value'=>,'color'=>], …], $center(중앙 큰 텍스트), $centerColor --}}
@php
    $total = 0.0;
    foreach ($segs as $s) { $total += max(0, (float) $s['value']); }
    $r = 52; $cx = 70; $cy = 70; $circ = 2 * M_PI * $r; $off = 0.0;
    $size = $size ?? 160;
@endphp
<svg viewBox="0 0 140 140" style="width:{{ $size }}px;height:{{ $size }}px;">
    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" fill="none" stroke="var(--color-surface-strong)" stroke-width="15"/>
    @if ($total > 0)
        @foreach ($segs as $s)
            @php $len = max(0, (float) $s['value']) / $total * $circ; @endphp
            <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" fill="none" stroke="{{ $s['color'] }}" stroke-width="15"
                    stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$off, 2) }}"
                    transform="rotate(-90 {{ $cx }} {{ $cy }})" stroke-linecap="butt"/>
            @php $off += $len; @endphp
        @endforeach
    @endif
    @if (! empty($center))
        <text x="{{ $cx }}" y="{{ $cy + 6 }}" text-anchor="middle" style="font-size:var(--fs-lg);font-weight:800;fill:{{ $centerColor ?? 'var(--color-ink)' }};">{{ $center }}</text>
    @endif
</svg>
