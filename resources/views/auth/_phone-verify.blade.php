{{-- 전화번호 SMS 인증 필드 (register·social-complete 공용) --}}
<div>
    <label class="block text-muted mb-1.5" style="font-size:var(--fs-xs);font-weight:600;">전화번호 <span class="text-muted-soft">(인증 필수)</span></label>
    <div class="flex gap-2">
        <input name="phone" id="pv-phone" type="tel" class="input" value="{{ old('phone') }}" placeholder="010-0000-0000" required style="flex:1;">
        <button type="button" id="pv-send" class="btn btn-secondary" style="white-space:nowrap;flex:none;">인증번호 받기</button>
    </div>
    <div id="pv-code-row" class="flex gap-2 mt-2 hidden">
        <input id="pv-code" type="text" inputmode="numeric" maxlength="6" class="input" placeholder="인증번호 6자리" style="flex:1;">
        <button type="button" id="pv-verify" class="btn btn-secondary" style="white-space:nowrap;flex:none;">확인</button>
    </div>
    <p id="pv-msg" class="mt-1.5 hidden" style="font-size:var(--fs-xs);"></p>
</div>

@push('scripts')
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var phone = document.getElementById('pv-phone');
    var send = document.getElementById('pv-send');
    var codeRow = document.getElementById('pv-code-row');
    var code = document.getElementById('pv-code');
    var verify = document.getElementById('pv-verify');
    var msg = document.getElementById('pv-msg');
    var verified = false;

    function showMsg(text, ok) {
        msg.textContent = text;
        msg.classList.remove('hidden');
        msg.style.color = ok ? 'var(--color-success)' : 'var(--color-error)';
    }
    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify(body),
        }).then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }); });
    }

    send.addEventListener('click', function () {
        var p = phone.value.replace(/\D/g, '');
        if (!/^01[016789]\d{7,8}$/.test(p)) { showMsg('올바른 휴대폰 번호를 입력하세요.', false); return; }
        send.disabled = true; send.textContent = '발송 중…';
        post('{{ route('phone.send') }}', { phone: p }).then(function (res) {
            send.disabled = false;
            if (res.json.ok) {
                codeRow.classList.remove('hidden');
                send.textContent = '재발송';
                var extra = res.json.dev_code ? ' (개발용 코드: ' + res.json.dev_code + ')' : '';
                showMsg((res.json.message || '인증번호를 발송했습니다.') + extra, true);
                code.focus();
            } else {
                send.textContent = '인증번호 받기';
                showMsg(res.json.message || '발송에 실패했습니다.', false);
            }
        }).catch(function () { send.disabled = false; send.textContent = '인증번호 받기'; showMsg('네트워크 오류가 발생했습니다.', false); });
    });

    verify.addEventListener('click', function () {
        if (verified) return;
        var p = phone.value.replace(/\D/g, '');
        post('{{ route('phone.verify') }}', { phone: p, code: code.value.trim() }).then(function (res) {
            if (res.json.ok) {
                verified = true;
                phone.readOnly = true; code.readOnly = true;
                send.disabled = true; verify.disabled = true;
                verify.textContent = '완료';
                showMsg('✓ 전화번호가 인증되었습니다.', true);
            } else {
                showMsg(res.json.message || '인증에 실패했습니다.', false);
            }
        }).catch(function () { showMsg('네트워크 오류가 발생했습니다.', false); });
    });
})();
</script>
@endpush
