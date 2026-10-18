<div class="text-muted mt-2" style="font-size:var(--fs-xs);">
    수집 상태: <b class="text-ink">{{ $a->collectionLabel() }}</b>
    · 5위 이내 <span class="font-mono">{{ $a->topFiveCount() }} / {{ $a->keyword_target ?? '상한 없음' }}</span>
    · 주문 기간: {{ $a->order_days ? $a->order_days.'일' : '미지정' }}
    @if ($a->order_start_date) ({{ $a->order_start_date }} ~ {{ $a->order_end_date }}) @endif
</div>

<div class="text-muted mt-2" style="font-size:var(--fs-xs);">
    수집 IP: <span class="font-mono">{{ $a->collection_ip ?? '미확인' }}</span>
    @if ($a->collection_source) · {{ $a->collection_source }} @endif
    @if ($a->collection_seen_at) · 마지막 수집 요청 {{ $a->collection_seen_at->format('Y-m-d H:i:s') }} @endif
</div>
