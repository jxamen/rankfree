{{-- 섹션 공통 헤더 — 드래그 핸들 + 제목 + 설명 + 숨기기. $t=제목, $d=설명(HTML 허용) --}}
<div class="head">
    <span class="ga4-drag" title="끌어서 위치 이동 — 섹션 가운데에 놓으면 같은 줄에 나란히, 위/아래 가장자리나 줄 사이 틈에 놓으면 한 줄로 들어가요">⠿</span>
    <h2>{{ $t }}</h2>
    @if (! empty($d))<span class="d">{!! $d !!}</span>@endif
    <button type="button" class="ga4-hide" title="이 지표 숨기기 — 툴바 [지표 표시]에서 다시 켤 수 있어요">✕</button>
</div>
