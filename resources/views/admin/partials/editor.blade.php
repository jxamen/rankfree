{{--
    자체 WYSIWYG 에디터 — 외부 라이브러리 없이 contenteditable + execCommand(자체완결형, CSP 안전).
    입력: $name(제출 필드명), $value(초기 HTML), $height(최소 높이 px, 기본 240), $placeholder.
    폼 제출 시 contenteditable 내용을 hidden textarea로 동기화한다.
--}}
@php
    $edId = 'ed'.substr(md5($name.uniqid('', true)), 0, 7);
    $height = $height ?? 240;
    $placeholder = $placeholder ?? '내용을 입력하세요…';
@endphp
<div class="rf-editor" data-ed="{{ $edId }}">
    <div class="rf-ed-toolbar">
        @foreach ([
            ['formatBlock:h3', 'H', '제목'],
            ['bold', '<b>B</b>', '굵게'],
            ['italic', '<i>I</i>', '기울임'],
            ['underline', '<u>U</u>', '밑줄'],
            ['insertUnorderedList', '• 목록', '글머리 목록'],
            ['insertOrderedList', '1. 목록', '번호 목록'],
            ['createLink', '🔗 링크', '링크 삽입'],
            ['insertImage', '🖼 이미지', '이미지(URL) 삽입'],
            ['insertHorizontalRule', '― 구분선', '가로 구분선'],
            ['removeFormat', '✕ 서식해제', '서식 지우기'],
        ] as [$cmd, $label, $title])
            <button type="button" class="rf-ed-btn" data-cmd="{{ $cmd }}" title="{{ $title }}">{!! $label !!}</button>
        @endforeach
    </div>
    <div class="rf-ed-area" contenteditable="true" data-placeholder="{{ $placeholder }}"
         style="min-height:{{ $height }}px;">{!! $value ?? '' !!}</div>
    <textarea name="{{ $name }}" class="rf-ed-src" style="display:none;">{{ $value ?? '' }}</textarea>
</div>

@once
    @push('head')
    <style>
        .rf-editor { border:1px solid var(--color-hairline); border-radius:10px; overflow:hidden; background:var(--color-canvas); }
        .rf-ed-toolbar { display:flex; flex-wrap:wrap; gap:2px; padding:6px; border-bottom:1px solid var(--color-hairline-soft); background:var(--color-surface-soft); }
        .rf-ed-btn { padding:4px 9px; border:1px solid transparent; border-radius:6px; background:transparent; color:var(--color-body); font-size:var(--fs-xs); cursor:pointer; line-height:1.4; }
        .rf-ed-btn:hover { background:var(--color-surface-card); border-color:var(--color-hairline); color:var(--color-ink); }
        .rf-ed-area { padding:14px 16px; font-size:var(--fs-sm); line-height:1.75; color:var(--color-ink); outline:none; overflow-y:auto; max-height:560px; }
        .rf-ed-area:empty::before { content:attr(data-placeholder); color:var(--color-muted-soft); }
        .rf-ed-area h3 { font-size:var(--fs-lg); font-weight:700; margin:12px 0 6px; }
        .rf-ed-area p { margin:6px 0; }
        .rf-ed-area ul, .rf-ed-area ol { margin:6px 0; padding-left:22px; }
        .rf-ed-area ul { list-style:disc; }
        .rf-ed-area ol { list-style:decimal; }
        .rf-ed-area a { color:var(--color-accent); text-decoration:underline; }
        .rf-ed-area img { max-width:100%; border-radius:8px; margin:6px 0; }
        .rf-ed-area hr { border:0; border-top:1px solid var(--color-hairline); margin:14px 0; }
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
            var sync = function () { src.value = area.innerHTML; };
            area.addEventListener('input', sync);
            area.addEventListener('blur', sync);
            wrap.querySelectorAll('.rf-ed-btn').forEach(function (btn) {
                btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
                btn.addEventListener('click', function () {
                    var cmd = btn.dataset.cmd;
                    area.focus();
                    if (cmd === 'createLink') {
                        var url = window.prompt('링크 URL을 입력하세요', 'https://');
                        if (url) document.execCommand('createLink', false, url);
                    } else if (cmd === 'insertImage') {
                        var img = window.prompt('이미지 URL을 입력하세요', 'https://');
                        if (img) document.execCommand('insertImage', false, img);
                    } else if (cmd.indexOf('formatBlock:') === 0) {
                        document.execCommand('formatBlock', false, cmd.split(':')[1]);
                    } else {
                        document.execCommand(cmd, false, null);
                    }
                    sync();
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
