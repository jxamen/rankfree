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
            form.submit();
        }
    });
}, true);
