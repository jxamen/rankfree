import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';

window.Swal = Swal;

// 디자인 토큰 값 읽기 (하드코딩 hex 대신 --color-* 사용)
function tok(name, fallback) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
}

/**
 * 삭제 등 확인이 필요한 폼은 네이티브 confirm 대신 SweetAlert2로.
 *   <form ... data-confirm="삭제할까요?" data-confirm-text="되돌릴 수 없습니다" data-confirm-ok="삭제">
 *   data-loading="…중" 을 주면 확인 직후 전체화면 로딩을 띄운다(처리가 오래 걸리는 폼).
 * 버튼 클릭 → 폼 제출 시 모달을 띄우고, 확인 시에만 실제 제출.
 */
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    var msg = form.getAttribute('data-confirm');
    if (!msg || form.dataset.confirmed === '1') return;

    e.preventDefault();
    Swal.fire({
        title: msg,
        text: form.getAttribute('data-confirm-text') || '',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: form.getAttribute('data-confirm-ok') || '삭제',
        cancelButtonText: '취소',
        confirmButtonColor: tok('--color-error', '#cf202f'),
        cancelButtonColor: tok('--color-muted', '#8a919e'),
        reverseButtons: true,
    }).then(function (r) {
        if (r.isConfirmed) {
            form.dataset.confirmed = '1';
            // 처리에 시간이 걸리는 폼(외부 발주 등)은 확인 직후 전체화면 로딩을 띄운다 —
            // 안 그러면 확인을 눌러도 화면이 그대로라 사용자가 다시 누른다
            var loading = form.getAttribute('data-loading');
            if (!loading) {
                form.submit();
                return;
            }
            // 제출은 모달이 실제로 뜬 뒤에(didOpen) 시작한다 — 먼저 제출하면 진행 중인 화면 전환에
            // 렌더가 밀려 로딩이 아예 안 보인다(실측)
            Swal.fire({
                title: loading,
                text: form.getAttribute('data-loading-text') || '창을 닫지 말고 잠시 기다려 주세요.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: function () {
                    Swal.showLoading();
                    form.submit();
                },
            });
        }
    });
}, true);
