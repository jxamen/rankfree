{{-- 카드 배경 장식 SVG (expo 스타일) — pattern: dots | grid | rings | gradient
     사용법: 부모 카드에 `relative overflow-hidden`, 텍스트 컨텐츠는 `relative`로 감싸 위에 올린다.
     색은 반드시 토큰(var(--color-*))으로 전달. gradient는 color→color2 2컬러 워시 --}}
@props([
    'pattern' => 'dots',
    'color' => 'var(--color-accent)',
    'color2' => 'var(--color-badge-violet)',
    'opacity' => null,
    'mask' => null, // null=우상단 코너 페이드(기본) | 'right'=가로 1/3 지점부터 우측으로 노출
])
@php
    $uid = uniqid('cbg');
    $op = $opacity ?? ($pattern === 'gradient' ? '0.45' : '0.25');
    if ($mask === 'right') {
        $mask = 'mask-image:linear-gradient(to right, transparent 30%, black 58%);-webkit-mask-image:linear-gradient(to right, transparent 30%, black 58%);';
    } else {
        // 패턴류는 좌하단으로 페이드아웃(우상단 코너 장식), 그라데이션은 자체 페이드
        $mask = $pattern === 'gradient'
            ? ''
            : 'mask-image:linear-gradient(215deg, black, transparent 62%);-webkit-mask-image:linear-gradient(215deg, black, transparent 62%);';
    }
@endphp
<div aria-hidden="true" {{ $attributes->merge(['class' => 'absolute inset-0 pointer-events-none']) }}
     style="color:{{ $color }};opacity:{{ $op }};{{ $mask }}">
    @if ($pattern === 'dots')
        <svg class="w-full h-full" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <pattern id="{{ $uid }}" width="18" height="18" patternUnits="userSpaceOnUse">
                    <circle cx="1.5" cy="1.5" r="1.5" fill="currentColor"/>
                </pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#{{ $uid }})"/>
        </svg>
    @elseif ($pattern === 'grid')
        <svg class="w-full h-full" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <pattern id="{{ $uid }}" width="32" height="32" patternUnits="userSpaceOnUse">
                    <path d="M32 0H0V32" fill="none" stroke="currentColor" stroke-width="1"/>
                </pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#{{ $uid }})"/>
        </svg>
    @elseif ($pattern === 'rings')
        <svg class="w-full h-full" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMaxYMin slice" viewBox="0 0 300 300">
            <g fill="none" stroke="currentColor" stroke-width="1">
                <circle cx="300" cy="0" r="50"/>
                <circle cx="300" cy="0" r="100"/>
                <circle cx="300" cy="0" r="150"/>
                <circle cx="300" cy="0" r="200"/>
                <circle cx="300" cy="0" r="250"/>
            </g>
        </svg>
    @elseif ($pattern === 'gradient')
        <svg class="w-full h-full" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <linearGradient id="{{ $uid }}" x1="1" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="{{ $color }}" stop-opacity="0.5"/>
                    <stop offset="0.5" stop-color="{{ $color2 }}" stop-opacity="0.18"/>
                    <stop offset="1" stop-color="{{ $color2 }}" stop-opacity="0"/>
                </linearGradient>
            </defs>
            <rect width="100%" height="100%" fill="url(#{{ $uid }})"/>
        </svg>
    @endif
</div>
