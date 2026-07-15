{{-- 헤더 브레드크럼 조각 렌더 — 입력: $crumbs([label, url|null][]), $currentTag(현재 조각 태그, 기본 h1).
     모든 조각 동일 크기(fs-sm), 현재 조각만 진하게. 레이아웃에서 데스크톱(한 줄)·모바일(전용 줄) 두 곳에 include. --}}
@php $currentTag = $currentTag ?? 'h1'; @endphp
<nav class="flex items-center gap-1.5 min-w-0" aria-label="위치">
    @foreach ($crumbs as [$cl, $cu])
        @if (! $loop->first)<span class="text-muted-soft flex-none" style="font-size:var(--fs-sm);opacity:.55;">›</span>@endif
        @if ($loop->last)
            <{{ $currentTag }} class="font-semibold text-ink truncate" style="font-size:var(--fs-sm);">{{ $cl }}</{{ $currentTag }}>
        @elseif ($cu)
            <a href="{{ $cu }}" class="text-muted hover:text-ink flex-none transition" style="font-size:var(--fs-sm);">{{ $cl }}</a>
        @else
            <span class="text-muted flex-none" style="font-size:var(--fs-sm);">{{ $cl }}</span>
        @endif
    @endforeach
</nav>
