{{-- 대시보드 팝업 — 위치·크기·기간·닫기옵션. 입력: $popups (Popup 컬렉션). --}}
@if ($popups->isNotEmpty())
    @php
        $posStyle = [
            'center' => 'top:50%;left:50%;transform:translate(-50%,-50%);',
            'top-left' => 'top:80px;left:24px;',
            'top-right' => 'top:80px;right:24px;',
            'bottom-left' => 'bottom:24px;left:24px;',
            'bottom-right' => 'bottom:24px;right:24px;',
        ];
    @endphp
    @foreach ($popups as $p)
        <div class="rf-popup" data-id="{{ $p->id }}" data-dismissible="{{ $p->dismissible ? 1 : 0 }}"
             style="display:none;position:fixed;z-index:60;width:min({{ $p->width }}px, calc(100vw - 32px));
                    max-height:calc(100vh - 48px);overflow:hidden;background:var(--color-canvas);
                    border:1px solid var(--color-hairline);border-radius:14px;box-shadow:var(--shadow-card);{{ $posStyle[$p->position] ?? $posStyle['center'] }}">
            <div class="flex items-center justify-between px-5" style="height:50px;border-bottom:1px solid var(--color-hairline-soft);">
                <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $p->title }}</span>
                <button type="button" class="rf-popup-x" style="background:none;border:0;cursor:pointer;color:var(--color-muted);font-size:var(--fs-lg);line-height:1;">×</button>
            </div>
            <div class="text-body" style="padding:18px 20px;font-size:var(--fs-sm);line-height:1.8;overflow-y:auto;max-height:60vh;">{!! $p->body !!}</div>
            @if ($p->dismissible)
                <div class="flex items-center justify-between px-5" style="height:48px;border-top:1px solid var(--color-hairline-soft);">
                    <label class="inline-flex items-center gap-2 text-muted" style="font-size:var(--fs-xs);cursor:pointer;">
                        <input type="checkbox" class="rf-popup-today"> 오늘 하루 보지 않기
                    </label>
                    <button type="button" class="btn btn-secondary btn-sm rf-popup-x">닫기</button>
                </div>
            @endif
        </div>
    @endforeach

    <script>
    (function () {
        function todayKey() { var d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
        document.querySelectorAll('.rf-popup').forEach(function (pop) {
            var id = pop.dataset.id;
            var key = 'rfpopup_' + id;
            // '오늘 하루 보지 않기'로 오늘 닫았으면 표시 안 함
            try { if (localStorage.getItem(key) === todayKey()) return; } catch (e) {}
            pop.style.display = 'block';
            function close() {
                var today = pop.querySelector('.rf-popup-today');
                if (today && today.checked) { try { localStorage.setItem(key, todayKey()); } catch (e) {} }
                pop.style.display = 'none';
            }
            pop.querySelectorAll('.rf-popup-x').forEach(function (b) { b.addEventListener('click', close); });
        });
    })();
    </script>
@endif
