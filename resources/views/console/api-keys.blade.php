@extends('console.layout')
@section('page-title', 'API 키')

@section('page-actions')
    <div class="flex items-center gap-2">
        <a href="{{ route('console.developers') }}" class="btn btn-secondary btn-sm">API 문서</a>
        <button type="button" id="rf-open-modal" class="btn btn-primary btn-sm">＋ 키 발급</button>
    </div>
@endsection

@section('console-content')
<x-console.page-head title="API 키" desc="랭크프리 오픈 API 호출용 키를 발급·관리합니다 · <b>권한·허용 IP·만료일</b>로 접근을 제어할 수 있습니다" />

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md" style="background:color-mix(in srgb,var(--color-error) 8%,var(--color-canvas));color:var(--color-error);font-size:var(--fs-xs);">{{ $errors->first() }}</div>
@endif

{{-- 새로 발급된 키 — 1회만 표시 --}}
@if (session('newApiKey'))
    <div class="card mb-6 p-5" style="border-color:color-mix(in srgb, var(--color-accent) 55%, transparent);">
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-xs);">새 API 키가 발급되었습니다 — 지금 한 번만 표시됩니다</div>
        <p class="text-muted mb-3" style="font-size:var(--fs-xs);">이 키는 다시 확인할 수 없습니다. 지금 복사해 안전한 곳에 보관하세요.</p>
        <div class="flex items-center gap-2 flex-wrap">
            <code id="rf-new-key" class="px-3 py-2 rounded-md bg-surface-soft text-ink" style="font-family:var(--font-mono);font-size:var(--fs-xs);word-break:break-all;">{{ session('newApiKey') }}</code>
            <button type="button" id="rf-copy-key" class="btn btn-secondary btn-sm">복사</button>
        </div>
    </div>
@endif

{{-- 키 목록 --}}
<div class="card overflow-hidden">
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:900px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);">
                    <th class="text-left px-5 py-3 font-semibold">이름 / 키</th>
                    <th class="text-left px-3 py-3 font-semibold">권한</th>
                    <th class="text-left px-3 py-3 font-semibold">허용 IP</th>
                    <th class="text-right px-3 py-3 font-semibold">오늘 사용</th>
                    <th class="text-left px-3 py-3 font-semibold">만료</th>
                    <th class="text-left px-3 py-3 font-semibold">최근 사용</th>
                    <th class="text-right px-5 py-3 font-semibold">상태 / 삭제</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($keys as $key)
                    <tr style="border-top:1px solid var(--color-hairline-soft);{{ $key->is_active ? '' : 'opacity:.55;' }}">
                        <td class="px-5 py-3">
                            <div class="text-ink font-medium" style="font-size:var(--fs-xs);">{{ $key->name }}</div>
                            <div class="text-muted-soft" style="font-size:var(--fs-xs);font-family:var(--font-mono);">{{ $key->key_prefix }}…</div>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex gap-1 flex-wrap">
                                @foreach ((array) $key->scopes as $sc)
                                    <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ \App\Models\ApiKey::SCOPES[$sc] ?? $sc }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);max-width:160px;">
                            <span class="truncate block" title="{{ $key->allowed_ips }}">{{ $key->allowed_ips ?: '전체 허용' }}</span>
                        </td>
                        <td class="px-3 py-3 text-right" style="font-size:var(--fs-xs);">
                            <b class="text-ink">{{ number_format($key->usedToday()) }}</b>
                            <span class="text-muted-soft">/ {{ $key->daily_limit !== null ? number_format($key->daily_limit) : '무제한' }}</span>
                        </td>
                        <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);">
                            @if ($key->expires_at === null)
                                무기한
                            @elseif ($key->expires_at->isPast())
                                <span style="color:var(--color-error);">만료됨</span>
                            @else
                                {{ $key->expires_at->format('Y-m-d') }}
                            @endif
                        </td>
                        <td class="px-3 py-3 text-muted-soft" style="font-size:var(--fs-xs);">{{ $key->last_used_at?->diffForHumans() ?? '—' }}</td>
                        <td class="px-5 py-3 text-right text-nowrap">
                            @php $plain = $key->plainKey(); @endphp
                            @if ($plain)
                                <button type="button" class="btn btn-secondary btn-sm rf-row-copy" data-key="{{ $plain }}">복사</button>
                            @else
                                <form method="POST" action="{{ route('console.api-keys.regenerate', $key) }}" style="display:inline;" onsubmit="return confirm('원문이 저장되지 않은 기존 키입니다. 재발급하면 새 키가 발급되고 이전 키는 즉시 무효화됩니다. 진행할까요?')">@csrf<button type="submit" class="btn btn-secondary btn-sm" title="원문 미보관 — 복사하려면 재발급 필요">재발급</button></form>
                            @endif
                            <form method="POST" action="{{ route('console.api-keys.toggle', $key) }}" style="display:inline;">@csrf<button type="submit" class="btn btn-ghost btn-sm">{{ $key->is_active ? '비활성' : '활성' }}</button></form>
                            <form method="POST" action="{{ route('console.api-keys.destroy', $key) }}" style="display:inline;" onsubmit="return confirm('이 키를 삭제하면 해당 키로의 호출이 즉시 실패합니다. 삭제하시겠습니까?')">@csrf @method('DELETE')<button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-error);">삭제</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center" style="padding:56px 20px;color:var(--color-muted);">
                        <div style="font-size:var(--fs-2xl);opacity:.4;">🔑</div>
                        <p class="mt-2" style="font-size:var(--fs-xs);">발급된 API 키가 없습니다. 우측 상단 "＋ 키 발급"으로 시작하세요.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<p class="text-muted-soft mt-3" style="font-size:var(--fs-xs);">
    인증: <code style="font-family:var(--font-mono);">Authorization: Bearer rk_…</code> 헤더 (또는 <code style="font-family:var(--font-mono);">X-API-KEY</code>) ·
    Base URL: <code style="font-family:var(--font-mono);">{{ url('/api/v1') }}</code> ·
    자세한 사용법은 <a href="{{ route('console.developers') }}" class="text-accent">API 문서</a> 참조.
</p>

{{-- 키 발급 모달 --}}
<div id="rf-modal" class="hidden" style="position:fixed;inset:0;z-index:50;">
    <div id="rf-modal-bg" style="position:absolute;inset:0;background:color-mix(in srgb, var(--color-ink) 40%, transparent);"></div>
    <div class="card" style="position:relative;width:min(560px, calc(100vw - 24px));margin:7vh auto 0;max-height:84vh;overflow-y:auto;box-shadow:var(--shadow-card);">
        <div class="flex items-center justify-between px-5 border-b border-hairline-soft" style="height:52px;">
            <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">API 키 발급</span>
            <button type="button" id="rf-modal-close" class="btn btn-ghost btn-sm" title="닫기">✕</button>
        </div>
        <form method="POST" action="{{ route('console.api-keys.store') }}" class="p-5">
            @csrf
            <div class="mb-4">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">이름 (용도)</label>
                <input name="name" class="input" value="{{ old('name') }}" placeholder="예: 사내 대시보드 연동" required maxlength="80">
            </div>

            <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">권한 (scope)</label>
            <div class="flex gap-4 flex-wrap mb-4">
                @foreach (\App\Models\ApiKey::SCOPES as $code => $label)
                    <label class="flex items-center gap-1.5 text-ink" style="font-size:var(--fs-xs);">
                        <input type="checkbox" name="scopes[]" value="{{ $code }}" @checked(in_array($code, (array) old('scopes', ['rank'])))> {{ $label }}
                    </label>
                @endforeach
            </div>

            <div class="flex gap-3 flex-wrap mb-4">
                <div style="flex:1;min-width:160px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">허용기간 (만료일) <span class="text-muted-soft">— 비우면 무기한</span></label>
                    <input type="date" name="expires_at" class="input" value="{{ old('expires_at') }}" min="{{ now()->addDay()->toDateString() }}">
                </div>
                <div style="flex:1;min-width:160px;">
                    <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">일일 호출 한도 <span class="text-muted-soft">— 비우면 무제한</span></label>
                    <input type="number" name="daily_limit" class="input" value="{{ old('daily_limit') }}" min="1" max="1000000" placeholder="예: 1000">
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-muted mb-1" style="font-size:var(--fs-xs);">허용 IP <span class="text-muted-soft">— 줄바꿈/쉼표 구분, 비우면 전체 허용, 와일드카드 가능(예: 121.140.12.*)</span></label>
                <textarea name="allowed_ips" class="input" style="height:72px;padding-top:8px;resize:vertical;" placeholder="예)&#10;121.140.12.34&#10;10.0.0.*">{{ old('allowed_ips') }}</textarea>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('rf-modal').classList.add('hidden')">취소</button>
                <button type="submit" class="btn btn-primary btn-sm">발급</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('rf-modal');
    const openBtn = document.getElementById('rf-open-modal');
    const closeBtn = document.getElementById('rf-modal-close');
    const bg = document.getElementById('rf-modal-bg');
    function openModal() { modal.classList.remove('hidden'); }
    function closeModal() { modal.classList.add('hidden'); }
    openBtn && openBtn.addEventListener('click', openModal);
    closeBtn && closeBtn.addEventListener('click', closeModal);
    bg && bg.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
    });
    @if ($errors->any())
        openModal();
    @endif

    const copyBtn = document.getElementById('rf-copy-key');
    const keyEl = document.getElementById('rf-new-key');

    // 성공 피드백 — Swal(CDN)이 있으면 토스트, 없으면 버튼 인라인 표시(의존성 제거)
    function flashCopied() {
        if (window.Swal && Swal.fire) {
            try { Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'API 키가 복사되었습니다', showConfirmButton: false, timer: 1600 }); return; } catch (e) { /* fall through */ }
        }
        if (copyBtn) {
            const orig = copyBtn.textContent;
            copyBtn.textContent = '복사됨 ✓';
            copyBtn.disabled = true;
            setTimeout(function () { copyBtn.textContent = orig; copyBtn.disabled = false; }, 1500);
        }
    }

    // execCommand 폴백(비보안 컨텍스트/구형 브라우저)
    function execCopy(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        ta.setSelectionRange(0, text.length);
        let ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        ta.remove();
        return ok;
    }

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(flashCopied, function () {
                if (execCopy(text)) flashCopied();
                else if (keyEl) { keyEl.focus && keyEl.focus(); }
            });
        } else {
            if (execCopy(text)) flashCopied();
        }
    }

    // 새 키 복사 — 버튼 + code 클릭 모두 지원
    function copyNewKey() { if (keyEl) copyText(keyEl.textContent.trim()); }
    copyBtn && copyBtn.addEventListener('click', copyNewKey);
    if (keyEl) {
        keyEl.style.cursor = 'pointer';
        keyEl.title = '클릭하면 복사됩니다';
        keyEl.addEventListener('click', copyNewKey);
    }

    // 목록 각 행의 복사 버튼 — 저장된 원문(암호화)을 복사
    document.querySelectorAll('.rf-row-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const text = btn.getAttribute('data-key') || '';
            if (!text) return;
            const restore = btn.textContent;
            copyText(text);
            btn.textContent = '복사됨 ✓';
            btn.disabled = true;
            setTimeout(function () { btn.textContent = restore; btn.disabled = false; }, 1500);
        });
    });
})();
</script>
@endsection
