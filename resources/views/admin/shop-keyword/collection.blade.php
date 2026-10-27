@php
    // 상세 화면(editStatus)에서만, 관리자에게만 — 목록 행은 글자만 보여 준다. 조건 없이 고른 값을 저장한다.
    $canEditStatus = ($editStatus ?? false) && auth()->user()->isAdmin();
@endphp
<div class="text-muted mt-2" style="font-size:var(--fs-xs);">
    수집 상태:
    @if ($canEditStatus)
        @php
            $statusOptions = \App\Models\ShopKeywordAnalysis::MANUAL_STATUSES;
            // 목표 도달로 멈춘 것 · 대기 · 실패는 고르는 값이 아니라 현재 표시만 한다(맨 위 비활성 항목)
            $currentStatus = (! $a->targetReached() && array_key_exists($a->status, $statusOptions)) ? $a->status : '';
        @endphp
        <select id="sk-status" class="input" style="width:auto;height:28px;padding:0 8px;font-weight:700" aria-label="수집 상태 변경"
                data-url="{{ route('admin.shop-keyword.status', $a) }}" data-current="{{ $currentStatus }}">
            @if ($currentStatus === '')
                <option value="" selected disabled>{{ $a->collectionLabel() }}</option>
            @endif
            @foreach ($statusOptions as $value => $label)
                <option value="{{ $value }}" @selected($currentStatus === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <span id="sk-status-msg" role="status"></span>
    @else
        <b class="text-ink">{{ $a->collectionLabel() }}</b>
    @endif
    · 5위 이내 <span class="font-mono"><span id="sk-top5">{{ $a->topFiveCount() }}</span> / {{ $a->keyword_target ?? '상한 없음' }}</span>
    · 주문 기간: {{ $a->order_days ? $a->order_days.'일' : '미지정' }}
    @if ($a->order_start_date) ({{ $a->order_start_date }} ~ {{ $a->order_end_date }}) @endif
</div>

<div class="text-muted mt-2" style="font-size:var(--fs-xs);">
    수집 IP: <span class="font-mono">{{ $a->collection_ip ?? '미확인' }}</span>
    @if ($a->collection_source) · {{ $a->collection_source }} @endif
    @if ($a->collection_seen_at) · 마지막 수집 요청 {{ $a->collection_seen_at->format('Y-m-d H:i:s') }} @endif
</div>
@if ($canEditStatus)
<script>
(function () {
    const select = document.getElementById('sk-status'), msg = document.getElementById('sk-status-msg');
    if (!select) return;
    select.addEventListener('change', async function () {
        const want = select.value, before = select.dataset.current;
        select.disabled = true; msg.textContent = '저장 중…';
        try {
            const r = await fetch(select.dataset.url, { method: 'POST', headers: { 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ status: want }) });
            if (r.status === 419) { location.reload(); return; }
            const body = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(body.message || ('저장하지 못했어요 (HTTP ' + r.status + ')'));
            msg.textContent = '저장했어요 — ' + body.data.label;
            location.reload();   // 수집 중으로 바꾸면 이 화면이 이어서 확인을 시작한다
        } catch (e) {
            select.value = before;   // 저장하지 못했으면 원래 값으로 되돌린다
            msg.textContent = e.message;
            select.disabled = false;
        }
    });
})();
</script>
@endif
