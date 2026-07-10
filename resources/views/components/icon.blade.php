{{-- 아이콘 렌더 — FontAwesome 클래스(fa-)면 <i>, 아니면 텍스트/이모지 (하위호환) --}}
@props(['name' => ''])
@php $n = trim((string) $name); @endphp
@if ($n === '')
@elseif (str_contains($n, 'fa-'))<i class="{{ $n }}"></i>@else{{ $n }}@endif
