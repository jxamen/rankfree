{{--
    순위추적 목록 탭 — 상태별로 목록을 갈라 보여준다(콘솔·어드민, 플레이스·쇼핑 공용).
    중단된 슬롯이 활성 슬롯 사이에 섞여 있으면 눈에 안 띄어 [재개]를 놓친다.

    props:
      tabs     [['key' => 'active', 'label' => '추적 중', 'count' => 3, 'url' => '...'], ...]
      current  현재 선택된 탭의 key
--}}
@props(['tabs', 'current'])
<nav class="rf-tabs" aria-label="추적 상태">
    @foreach ($tabs as $t)
        <a href="{{ $t['url'] }}"
           class="rf-tab @if ($current === $t['key']) on @endif"
           @if ($current === $t['key']) aria-current="page" @endif>
            {{ $t['label'] }}<span class="rf-tab-n font-mono">{{ number_format($t['count']) }}</span>
        </a>
    @endforeach
</nav>
