{{--
    자체 WYSIWYG 에디터 — 외부 라이브러리 없이 contenteditable + execCommand(자체완결형, CSP 안전).
    입력: $name(제출 필드명), $value(초기 HTML), $height(최소 높이 px, 기본 240), $placeholder,
          $uploadUrl(이미지 첨부 업로드 엔드포인트 — 지정 시 '이미지'가 파일 첨부, 미지정 시 URL 입력).
    폼 제출 시 contenteditable 내용을 hidden textarea로 동기화한다.
--}}
@php
    $edId = 'ed'.substr(md5($name.uniqid('', true)), 0, 7);
    $height = $height ?? 240;
    $placeholder = $placeholder ?? '내용을 입력하세요…';
    $uploadUrl = $uploadUrl ?? null;
@endphp
<div class="rf-editor" data-ed="{{ $edId }}" @if ($uploadUrl) data-upload="{{ $uploadUrl }}" @endif>
    <div class="rf-ed-toolbar">
        <select class="rf-ed-size" title="글자 크기">
            <option value="">크기</option>
            <option value="13px">작게</option>
            <option value="16px">보통</option>
            <option value="20px">크게</option>
            <option value="26px">아주 크게</option>
        </select>
        <label class="rf-ed-color" title="글자 색">
            <span class="ic">A</span>
            <input type="color" value="#111111">
        </label>
        <span class="rf-ed-sep"></span>
        @foreach ([
            ['formatBlock:h3', 'H', '제목'],
            ['bold', '<b>B</b>', '굵게'],
            ['italic', '<i>I</i>', '기울임'],
            ['underline', '<u>U</u>', '밑줄'],
        ] as [$cmd, $label, $title])
            <button type="button" class="rf-ed-btn" data-cmd="{{ $cmd }}" title="{{ $title }}">{!! $label !!}</button>
        @endforeach
        <span class="rf-ed-sep"></span>
        @foreach ([
            ['justifyLeft', 'M6 6h16M6 12h10M6 18h13', '왼쪽 정렬'],
            ['justifyCenter', 'M4 6h16M7 12h10M5 18h14', '가운데 정렬'],
            ['justifyRight', 'M6 6h16M12 12h10M9 18h13', '오른쪽 정렬'],
        ] as [$cmd, $path, $title])
            <button type="button" class="rf-ed-btn" data-cmd="{{ $cmd }}" title="{{ $title }}"><svg width="14" height="14" viewBox="0 0 28 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">@foreach (explode('M', trim($path, 'M')) as $seg)<path d="M{{ $seg }}"/>@endforeach</svg></button>
        @endforeach
        <span class="rf-ed-sep"></span>
        @foreach ([
            ['insertUnorderedList', '• 목록', '글머리 목록'],
            ['insertOrderedList', '1. 목록', '번호 목록'],
            ['createLink', '🔗 링크', '링크 삽입'],
            ['insertImage', $uploadUrl ? '🖼 이미지 첨부' : '🖼 이미지', $uploadUrl ? '이미지 파일 첨부' : '이미지(URL) 삽입'],
            ['insertHorizontalRule', '― 구분선', '가로 구분선'],
            ['removeFormat', '✕ 서식해제', '서식 지우기'],
        ] as [$cmd, $label, $title])
            <button type="button" class="rf-ed-btn" data-cmd="{{ $cmd }}" title="{{ $title }}">{!! $label !!}</button>
        @endforeach
    </div>
    <div class="rf-ed-area" contenteditable="true" data-placeholder="{{ $placeholder }}"
         style="min-height:{{ $height }}px;">{!! $value ?? '' !!}</div>
    <textarea name="{{ $name }}" class="rf-ed-src" style="display:none;">{{ $value ?? '' }}</textarea>
    @if ($uploadUrl)
        <input type="file" class="rf-ed-file" accept="image/*" hidden>
    @endif
</div>

@once
    @push('head')
    <style>
        .rf-editor { border:1px solid var(--color-hairline); border-radius:10px; overflow:hidden; background:var(--color-canvas); }
        .rf-ed-toolbar { display:flex; flex-wrap:wrap; align-items:center; gap:2px; padding:6px; border-bottom:1px solid var(--color-hairline-soft); background:var(--color-surface-soft); }
        .rf-ed-sep { width:1px; align-self:stretch; margin:2px 4px; background:var(--color-hairline); }
        .rf-ed-btn { display:inline-flex; align-items:center; padding:4px 9px; border:1px solid transparent; border-radius:6px; background:transparent; color:var(--color-body); font-size:var(--fs-xs); cursor:pointer; line-height:1.4; }
        .rf-ed-btn:hover { background:var(--color-surface-card); border-color:var(--color-hairline); color:var(--color-ink); }
        .rf-ed-size { height:28px; border:1px solid var(--color-hairline); border-radius:6px; background:var(--color-canvas); color:var(--color-body); font-size:var(--fs-xs); padding:0 6px; cursor:pointer; }
        .rf-ed-color { position:relative; display:inline-flex; align-items:center; justify-content:center; width:30px; height:28px; border:1px solid var(--color-hairline); border-radius:6px; cursor:pointer; overflow:hidden; }
        .rf-ed-color .ic { font-weight:800; font-size:var(--fs-sm); color:var(--color-ink); pointer-events:none; }
        .rf-ed-color input[type=color] { position:absolute; inset:0; width:100%; height:100%; opacity:0; cursor:pointer; border:0; padding:0; }
        .rf-ed-area { padding:14px 16px; font-size:var(--fs-sm); line-height:1.75; color:var(--color-ink); outline:none; overflow-y:auto; max-height:620px; }
        .rf-ed-area:empty::before { content:attr(data-placeholder); color:var(--color-muted-soft); }
        .rf-ed-area h3 { font-size:var(--fs-lg); font-weight:700; margin:12px 0 6px; }
        .rf-ed-area p, .rf-ed-area div { margin:6px 0; }
        .rf-ed-area ul, .rf-ed-area ol { margin:6px 0; padding-left:22px; }
        .rf-ed-area ul { list-style:disc; }
        .rf-ed-area ol { list-style:decimal; }
        .rf-ed-area a { color:var(--color-accent); text-decoration:underline; }
        .rf-ed-area img { max-width:100%; border-radius:8px; margin:6px 0; }
        .rf-ed-area hr { border:0; border-top:1px solid var(--color-hairline); margin:14px 0; }
        .rf-ed-uploading { opacity:.6; }
    </style>
    @endpush
    @push('scripts')
    <script>
    (function () {
        function initEditor(wrap) {
            if (wrap.dataset.init) return;
            wrap.dataset.init = '1';
            var area = wrap.querySelector('.rf-ed-area');
            var src = wrap.querySelector('.rf-ed-src');
            var uploadUrl = wrap.dataset.upload;
            var fileInput = wrap.querySelector('.rf-ed-file');
            var sync = function () { src.value = area.innerHTML; };
            area.addEventListener('input', sync);
            area.addEventListener('blur', sync);

            // 선택 영역 저장/복원 — 툴바(select/color/파일) 조작 시 contenteditable 선택이 풀리므로
            var savedRange = null;
            function saveSel() {
                var sel = window.getSelection();
                if (sel && sel.rangeCount && area.contains(sel.anchorNode)) savedRange = sel.getRangeAt(0).cloneRange();
            }
            function restoreSel() {
                area.focus();
                if (!savedRange) return;
                var sel = window.getSelection();
                sel.removeAllRanges(); sel.addRange(savedRange);
            }
            area.addEventListener('keyup', saveSel);
            area.addEventListener('mouseup', saveSel);
            area.addEventListener('blur', saveSel);

            // 글자 크기 — fontSize(7)로 마킹 후 원하는 px span으로 치환
            var sizeSel = wrap.querySelector('.rf-ed-size');
            if (sizeSel) sizeSel.addEventListener('change', function () {
                var size = sizeSel.value; sizeSel.value = '';
                if (!size) return;
                restoreSel();
                document.execCommand('styleWithCSS', false, false);
                document.execCommand('fontSize', false, '7');
                area.querySelectorAll('font[size="7"]').forEach(function (f) {
                    var span = document.createElement('span');
                    span.style.fontSize = size;
                    while (f.firstChild) span.appendChild(f.firstChild);
                    f.parentNode.replaceChild(span, f);
                });
                sync();
            });

            // 글자 색 — styleWithCSS로 span style 생성
            var colorInput = wrap.querySelector('.rf-ed-color input[type=color]');
            if (colorInput) colorInput.addEventListener('input', function () {
                restoreSel();
                document.execCommand('styleWithCSS', false, true);
                document.execCommand('foreColor', false, colorInput.value);
                sync();
            });

            // 이미지 삽입 — 노드로 직접 삽입(비동기 업로드 후 선택 복원)
            function insertImage(url) {
                restoreSel();
                var img = document.createElement('img');
                img.src = url;
                var sel = window.getSelection();
                if (sel && sel.rangeCount) {
                    var range = sel.getRangeAt(0);
                    range.collapse(false);
                    range.insertNode(img);
                    range.setStartAfter(img); range.setEndAfter(img);
                    sel.removeAllRanges(); sel.addRange(range);
                } else {
                    area.appendChild(img);
                }
                saveSel(); sync();
            }
            if (fileInput) fileInput.addEventListener('change', function () {
                var file = fileInput.files && fileInput.files[0];
                if (!file) return;
                var tokenEl = (wrap.closest('form') || document).querySelector('input[name="_token"]');
                var fd = new FormData(); fd.append('image', file);
                wrap.classList.add('rf-ed-uploading');
                fetch(uploadUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': tokenEl ? tokenEl.value : '', 'Accept': 'application/json' }, body: fd })
                    .then(function (r) { return r.ok ? r.json() : r.json().then(function (e) { throw e; }); })
                    .then(function (d) { if (d && d.url) insertImage(d.url); })
                    .catch(function () { window.alert('이미지 업로드에 실패했습니다. (형식/용량 확인)'); })
                    .then(function () { wrap.classList.remove('rf-ed-uploading'); fileInput.value = ''; });
            });

            wrap.querySelectorAll('.rf-ed-btn').forEach(function (btn) {
                btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
                btn.addEventListener('click', function () {
                    var cmd = btn.dataset.cmd;
                    restoreSel();
                    if (cmd === 'createLink') {
                        var url = window.prompt('링크 URL을 입력하세요', 'https://');
                        if (url) document.execCommand('createLink', false, url);
                    } else if (cmd === 'insertImage') {
                        if (uploadUrl && fileInput) { saveSel(); fileInput.click(); return; }
                        var img = window.prompt('이미지 URL을 입력하세요', 'https://');
                        if (img) document.execCommand('insertImage', false, img);
                    } else if (cmd.indexOf('formatBlock:') === 0) {
                        document.execCommand('formatBlock', false, cmd.split(':')[1]);
                    } else if (cmd.indexOf('justify') === 0) {
                        document.execCommand('styleWithCSS', false, true);
                        document.execCommand(cmd, false, null);
                    } else {
                        document.execCommand(cmd, false, null);
                    }
                    saveSel(); sync();
                });
            });
            // 폼 제출 직전 최종 동기화
            var form = wrap.closest('form');
            if (form) form.addEventListener('submit', sync);
        }
        document.querySelectorAll('.rf-editor').forEach(initEditor);
    })();
    </script>
    @endpush
@endonce
