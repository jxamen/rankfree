{{--
    순위추적 슬롯 카드 공용 스크립트 — 콘솔(rank·shop-rank)·어드민(순위추적)이 함께 include.
      · 순위체크(.rf-run-form) AJAX — 로딩 → 토스트 → 새로고침
      · 전체 순위체크(#rf-run-all) — 화면의 rf-run-form 을 순차 호출(진행률)
      · 리뷰·저장 접기/펼치기(.rf-metrics-toggle, 플레이스만) — localStorage 기억
      · rfCopyShare — 콘솔 레이아웃에 정의돼 있으면 그걸 쓰고, 없으면(어드민) 여기서 정의
    @once 로 페이지당 1회만.
--}}
@once
<script>
(function () {
    // 공유 링크 복사 — 콘솔 레이아웃(console.layout)엔 이미 있으나 어드민엔 없어 여기서 보강
    if (!window.rfCopyShare) {
        window.rfCopyShare = function (btn, url) {
            url = url || location.href;
            function done() {
                if (!btn) return;
                if (!btn.dataset.orig) btn.dataset.orig = btn.innerHTML;
                btn.innerHTML = '복사됨 ✓';
                btn.disabled = true;
                setTimeout(function () { btn.innerHTML = btn.dataset.orig; btn.disabled = false; }, 1400);
            }
            function fallback() {
                var ta = document.createElement('textarea');
                ta.value = url; ta.style.cssText = 'position:fixed;left:-9999px;top:0;';
                document.body.appendChild(ta); ta.focus(); ta.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                ta.remove();
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done, fallback);
            } else { fallback(); }
        };
    }

    // 리뷰·저장 접기/펼치기 (플레이스 카드) — localStorage 기억
    document.querySelectorAll('.rf-slot').forEach(function (card) {
        var id = card.dataset.slot;
        var btn = card.querySelector('.rf-metrics-toggle');
        if (!btn) return;
        function apply(collapsed) {
            card.classList.toggle('rf-collapsed', collapsed);
            btn.textContent = collapsed ? '펼치기' : '접기';
            try { localStorage.setItem('rfRankCollapse.' + id, collapsed ? '1' : '0'); } catch (e) {}
        }
        var init = false;
        try { init = localStorage.getItem('rfRankCollapse.' + id) === '1'; } catch (e) {}
        apply(init);
        btn.addEventListener('click', function () { apply(!card.classList.contains('rf-collapsed')); });
    });

    // 전체 순위체크 — 화면의 슬롯별 엔드포인트를 순차 호출(진행률)
    var runAllBtn = document.getElementById('rf-run-all');
    if (runAllBtn) {
        runAllBtn.addEventListener('click', async function () {
            var forms = Array.from(document.querySelectorAll('.rf-run-form'));
            if (!forms.length) return;
            var done = 0, found = 0;
            Swal.fire({
                title: '전체 순위체크',
                html: '<div id="rf-ra-prog" style="font-size:var(--fs-xs);color:var(--color-muted);">0 / ' + forms.length + ' 확인 중…</div>',
                allowOutsideClick: false, showConfirmButton: false, didOpen: function () { Swal.showLoading(); }
            });
            var prog = document.getElementById('rf-ra-prog');
            for (var i = 0; i < forms.length; i++) {
                var f = forms[i];
                try {
                    var r = await fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    if (r.ok) { var d = await r.json(); if (d.found) found++; }
                } catch (e) {}
                done++;
                if (prog) prog.textContent = done + ' / ' + forms.length + ' 확인' + (found ? ' · 노출 ' + found : '');
            }
            await Swal.fire({ icon: 'success', title: '전체 순위체크 완료', html: '<span style="font-size:var(--fs-xs);">' + forms.length + '개 중 <b>' + found + '</b>개 노출 확인</span>', timer: 1800, showConfirmButton: false });
            location.reload();
        });
    }

    // 순위체크 AJAX — 로딩 → 토스트 안내 → 새로고침
    document.querySelectorAll('.rf-run-form').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            Swal.fire({
                title: '순위 확인 중…',
                html: '<span style="font-size:var(--fs-xs);color:var(--color-muted);">‘' + (f.dataset.keyword || '') + '’ 키워드의 순위를 조회하고 있습니다.</span>',
                allowOutsideClick: false, showConfirmButton: false, didOpen: function () { Swal.showLoading(); }
            });
            fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
                .then(function (d) {
                    Swal.fire({ toast: true, position: 'top-end', icon: d.ok ? (d.found ? 'success' : 'info') : 'warning', title: d.message, showConfirmButton: false, timer: 1600, timerProgressBar: true })
                        .then(function () { location.reload(); });
                })
                .catch(function () { Swal.fire({ icon: 'error', title: '순위 확인에 실패했습니다', text: '잠시 후 다시 시도하세요.' }); });
        });
    });
})();
</script>
@endonce
