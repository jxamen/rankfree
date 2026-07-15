{{--
    리포트 영역을 PNG 이미지로 저장 (공용) — 셀러력 이미지 저장과 동일 방식.
    사용: 캡처 대상에 id 부여 → 버튼 onclick="rfSaveReportImage('대상id','파일명.png', this)".
    .rf-cap-only  : 평소 숨김, 캡처 시에만 노출(상/하단 브랜딩·홍보 문구)
    .rf-cap-hide  : 캡처에서 제외(순위체크·공유 등 인터랙션 버튼)
    .rf-capturing : 캡처 순간 부여 — 상하좌우 20px 여백 + 위 두 규칙 적용
--}}
@once
<style>
    .rf-cap-only { display: none; }
    .rf-capturing .rf-cap-only { display: flex !important; }
    .rf-capturing { padding: 20px; background: var(--color-canvas); }
    .rf-capturing .rf-cap-hide { display: none !important; }
</style>
<script src="https://cdn.jsdelivr.net/npm/html-to-image@1.11.11/dist/html-to-image.js"></script>
<script>
window.rfSaveReportImage = function (reportId, filename, btn) {
    var node = document.getElementById(reportId);
    if (!node || !window.htmlToImage) { alert('이미지 저장을 사용할 수 없습니다. 잠시 후 다시 시도하세요.'); return; }
    var orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '저장 중…'; }
    node.classList.add('rf-capturing');
    // 렌더 반영을 한 프레임 기다린 뒤 캡처
    requestAnimationFrame(function () {
        htmlToImage.toPng(node, { pixelRatio: 2, backgroundColor: getComputedStyle(document.body).backgroundColor || '#ffffff' })
            .then(function (dataUrl) {
                var a = document.createElement('a');
                a.href = dataUrl; a.download = filename; a.click();
            })
            .catch(function () { alert('이미지 생성에 실패했습니다. 잠시 후 다시 시도하세요.'); })
            .then(function () { node.classList.remove('rf-capturing'); if (btn) { btn.disabled = false; btn.innerHTML = orig; } });
    });
};
</script>
@endonce
