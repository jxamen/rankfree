{{-- 가로 막대 리스트 — 입력: $rows(['name'=>, $key=>]), $key(값 컬럼), $showPct(비율 표시) --}}
@php
    $__vals = collect($rows ?? []);
    $__max = max(1, (int) $__vals->max($key) ?: 1);
    $__total = max(1, (int) $__vals->sum($key) ?: 1);
    $__pct = $showPct ?? false;
@endphp
<div class="ga4-bars">
    @forelse ($rows as $it)
        <div class="ga4-bar">
            <span class="n" title="{{ $it['name'] }}">{{ $it['name'] }}</span>
            <span class="t"><i style="width:{{ round(($it[$key] ?? 0) / $__max * 100) }}%"></i></span>
            <span class="v">{{ \Jcurve\Ga4Insights\Support\Format::int($it[$key] ?? 0) }}@if ($__pct) <small>({{ round(($it[$key] ?? 0) / $__total * 100) }}%)</small>@endif</span>
        </div>
    @empty
        <div class="ga4-empty">데이터 없음</div>
    @endforelse
</div>
