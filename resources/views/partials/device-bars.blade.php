{{-- 디바이스 그룹 세로막대(PC/모바일) + Y축 0~100% + 호버 툴팁. 성별·연령별 검색 비율 그래프와 동일 스타일.
     입력: $rows=[['label'=>,'pc_pct'=>,'mobile_pct'=>], …], $cPc, $cMo --}}
@php $dvbId = 'dvb'.substr(md5(uniqid('', true)), 0, 8); @endphp
<div style="position:relative;padding-left:34px;" id="{{ $dvbId }}">
    <div style="position:absolute;left:0;top:0;width:30px;height:180px;">
        @for ($g = 4; $g >= 0; $g--)
            <span class="text-muted-soft" style="position:absolute;right:5px;top:calc({{ (1 - $g / 4) * 100 }}% - 7px);font-size:var(--fs-xs);">{{ 25 * $g }}%</span>
        @endfor
    </div>
    <div style="position:relative;height:180px;">
        @for ($g = 0; $g <= 4; $g++)
            <div style="position:absolute;left:0;right:0;top:{{ $g / 4 * 100 }}%;border-top:1px dashed var(--color-hairline-soft);"></div>
        @endfor
        <div style="display:flex;align-items:flex-end;gap:8px;height:100%;position:relative;">
            @foreach ($rows as $row)
                <div class="dvb-hover" style="flex:1;display:flex;align-items:flex-end;justify-content:center;gap:4px;height:100%;cursor:default;"
                     data-label="{{ $row['label'] }}" data-pc="{{ $row['pc_pct'] }}" data-mo="{{ $row['mobile_pct'] }}">
                    <div style="width:36%;height:{{ max(1, round($row['pc_pct'])) }}%;background:{{ $cPc }};border-radius:3px 3px 0 0;min-height:1px;"></div>
                    <div style="width:36%;height:{{ max(1, round($row['mobile_pct'])) }}%;background:{{ $cMo }};border-radius:3px 3px 0 0;min-height:1px;"></div>
                </div>
            @endforeach
        </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:6px;">
        @foreach ($rows as $row)<span class="text-muted-soft text-center" style="flex:1;font-size:var(--fs-xs);">{{ $row['label'] }}</span>@endforeach
    </div>
    <div class="dvb-tip" style="position:absolute;display:none;pointer-events:none;background:var(--color-surface-dark);color:#fff;border-radius:8px;padding:8px 11px;font-size:var(--fs-xs);white-space:nowrap;transform:translate(-50%,-100%);z-index:5;box-shadow:var(--shadow-card);"></div>
</div>
<script>
(function () {
    var card = document.getElementById(@json($dvbId));
    if (!card) return;
    var tip = card.querySelector('.dvb-tip');
    if (!tip) return;
    var cPc = @json($cPc), cMo = @json($cMo);
    card.querySelectorAll('.dvb-hover').forEach(function (h) {
        h.addEventListener('mouseenter', show);
        h.addEventListener('mousemove', show);
        h.addEventListener('mouseleave', function () { tip.style.display = 'none'; });
    });
    function show(e) {
        var h = e.currentTarget;
        tip.innerHTML = '<div style="font-weight:700;margin-bottom:3px;">' + h.dataset.label + '</div>'
            + '<div style="display:flex;align-items:center;gap:6px;"><i style="width:8px;height:8px;border-radius:50%;background:' + cPc + ';display:inline-block;"></i>PC ' + h.dataset.pc + '%</div>'
            + '<div style="display:flex;align-items:center;gap:6px;"><i style="width:8px;height:8px;border-radius:50%;background:' + cMo + ';display:inline-block;"></i>모바일 ' + h.dataset.mo + '%</div>';
        var cr = card.getBoundingClientRect(), hr = h.getBoundingClientRect();
        tip.style.left = (hr.left - cr.left + hr.width / 2) + 'px';
        tip.style.top = (hr.top - cr.top - 6) + 'px';
        tip.style.display = 'block';
    }
})();
</script>
