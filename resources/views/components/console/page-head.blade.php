{{-- 콘솔 페이지 공통 헤더 — 메뉴명 + 설명 (저장 블로거 패턴).
     제목은 현재 라우트의 콘솔 메뉴명 우선(메뉴관리에서 이름 변경 시 자동 반영), 없으면 title 프롭.
     desc는 프롭(HTML 허용) 또는 <x-slot:desc>, 기본 슬롯은 우측 액션 버튼 영역. --}}
@props(['title' => null, 'desc' => null])
@php
    $__rn = \Illuminate\Support\Facades\Route::currentRouteName();
    $__heading = ($__rn ? \App\Models\Menu::where('area', 'console')->where('route', $__rn)->value('name') : null) ?: $title;
@endphp
<div class="rf-page-head flex items-end justify-between flex-wrap gap-2 mb-4">
    <div>
        <div class="text-ink font-display" style="font-size:var(--fs-xl);">{{ $__heading }}</div>
        @if ($desc)<div class="text-muted-soft" style="font-size:var(--fs-xs);">{!! $desc !!}</div>@endif
    </div>
    @if (! $slot->isEmpty())<div class="flex items-center gap-2">{{ $slot }}</div>@endif
</div>
