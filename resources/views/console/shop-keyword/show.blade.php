@extends('console.layout')
@section('page-title', '쇼핑 노출 키워드 — '.$analysis->core_keyword)

@section('console-content')
@php
    $srcLabel = ['autocomplete' => '자동완성', 'related' => '연관(검색광고)', 'together' => '함께 많이 찾는', 'brand' => '브랜드', 'keyword_rec' => '키워드추천', 'attribute' => '상품속성', 'modifier' => '수식어(추출)', 'suffix' => '어미/수식어'];
    $th = $analysis->threshold;
    $shopUrl = fn ($kw) => 'https://search.shopping.naver.com/search/all?query='.urlencode($kw);
    $rankCell = function ($rank) use ($th) {
        if ($rank === null) return ['미확인', 'text-muted-soft'];
        if ($rank === -1) return ['차단', 'text-error'];
        if ($rank === 0) return ['100위 밖', 'text-muted-soft'];
        if ($rank <= $th) return [$rank.'위', 'text-success font-semibold'];
        return [$rank.'위', 'text-muted'];
    };
@endphp

<x-console.page-head title="쇼핑 노출 키워드 분석 결과">
    <x-slot:desc>핵심 <b>{{ $analysis->core_keyword }}</b> · {{ $analysis->product_id ? '상품 '.$analysis->product_id : ($analysis->mall_name ?: '대상 미상') }} · {{ $analysis->created_at->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</x-slot:desc>
</x-console.page-head>

<div class="flex items-center gap-2 mb-4 flex-wrap">
    <a href="{{ route('console.shop-keyword') }}" class="btn btn-ghost btn-sm">← 목록</a>
    @if ($analysis->product_url)
        <a href="{{ $analysis->product_url }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">상품 페이지</a>
    @endif
    <form method="POST" action="{{ route('console.shop-keyword.destroy', $analysis) }}" style="margin-left:auto;">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-ghost btn-sm text-error" data-confirm="이 분석을 삭제할까요?">삭제</button>
    </form>
</div>

@php $remaining = $combos->whereNull('rank')->count(); @endphp
@if ($remaining > 0)
    <div id="sk-prog" class="card p-4 mb-4" data-url="{{ route('console.shop-keyword.check', $analysis) }}">
        <div class="flex items-center justify-between mb-2">
            <span id="sk-prog-label" class="text-ink" style="font-size:var(--fs-sm);">순위 확인 중… {{ $analysis->checked_count }}/{{ $analysis->combo_count }} · 상위 {{ $th }}위 노출 {{ $analysis->exposed_count }}</span>
            <button type="button" id="sk-resume" class="btn btn-secondary btn-sm hidden">이어서 확인</button>
        </div>
        <div style="height:8px;background:var(--color-surface-soft);border-radius:var(--radius-pill);overflow:hidden;">
            <div id="sk-prog-fill" style="height:100%;background:var(--color-primary);border-radius:var(--radius-pill);transition:width .3s;width:{{ $analysis->combo_count ? round($analysis->checked_count / $analysis->combo_count * 100) : 0 }}%;"></div>
        </div>
    </div>
@endif

{{-- 요약 --}}
<div class="card p-5 mb-4 flex flex-wrap gap-6">
    <div><div class="text-muted" style="font-size:var(--fs-xs);">상위 {{ $th }}위 노출</div><div class="font-mono text-ink" style="font-size:var(--fs-xl);">{{ $analysis->exposed_count }}</div></div>
    <div><div class="text-muted" style="font-size:var(--fs-xs);">순위 확인 조합</div><div class="font-mono text-ink" style="font-size:var(--fs-xl);">{{ $analysis->checked_count }}</div></div>
    <div><div class="text-muted" style="font-size:var(--fs-xs);">추출 키워드</div><div class="font-mono text-ink" style="font-size:var(--fs-xl);">{{ $analysis->token_count }}</div></div>
    @if ($analysis->status === 'blocked')
        <div class="text-error" style="font-size:var(--fs-xs);align-self:center;">쇼핑 API 한도(429)로 일부 조합이 미확인 상태입니다. 잠시 후 다시 시도하세요.</div>
    @endif
</div>

{{-- 노출 키워드(핵심 결과) --}}
<div class="card p-5 mb-4">
    <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">상위 {{ $th }}위 노출 키워드</div>
    <div class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">이 검색어들에서 내 상품이 강합니다 — 제품명·태그·상세페이지를 이 키워드에 맞춰 유지·강화하세요.</div>
    @php $exposed = $combos->filter(fn ($c) => $c->rank !== null && $c->rank >= 1 && $c->rank <= $th); @endphp
    @if ($exposed->isEmpty())
        <div class="text-muted-soft" style="font-size:var(--fs-xs);">상위 {{ $th }}위에 노출되는 조합이 없습니다. 노출 기준을 넓히거나 필터 HTML을 추가해 조합을 늘려보세요.</div>
    @else
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr style="border-bottom:1px solid var(--color-hairline);">
                <th class="text-left text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">키워드</th>
                <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">추정 순위</th>
                <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">월 검색량</th>
                <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;"></th>
            </tr></thead>
            <tbody>
            @foreach ($exposed as $c)
                @php [$rt, $rc] = $rankCell($c->rank); @endphp
                <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                    <td class="py-2 text-ink" style="font-size:var(--fs-sm);">{{ $c->keyword }}</td>
                    <td class="py-2 text-right {{ $rc }} font-mono" style="font-size:var(--fs-sm);">{{ $rt }}</td>
                    <td class="py-2 text-right text-muted font-mono" style="font-size:var(--fs-xs);">{{ $c->monthly_total !== null ? number_format($c->monthly_total) : '—' }}</td>
                    <td class="py-2 text-right"><a href="{{ $shopUrl($c->keyword) }}" target="_blank" rel="noopener" class="text-muted-soft" style="font-size:var(--fs-xs);">쇼핑에서 보기 ↗</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- 전체 조합 순위 --}}
<div class="card p-5 mb-4">
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">전체 조합 순위 <span class="text-muted-soft font-normal">({{ $combos->count() }})</span></div>
    <div class="flex flex-wrap gap-2">
        @foreach ($combos as $c)
            @php [$rt, $rc] = $rankCell($c->rank); @endphp
            <span class="badge" style="font-size:var(--fs-xs);">{{ $c->keyword }} <span class="{{ $rc }} font-mono">{{ $rt }}</span></span>
        @endforeach
    </div>
</div>

{{-- 추출 키워드(소스별) --}}
<div class="card p-5">
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">추출된 키워드 <span class="text-muted-soft font-normal">(소스별)</span></div>
    @forelse ($tokens as $source => $list)
        <div class="mb-3">
            <div class="text-muted mb-1" style="font-size:var(--fs-xs);">{{ $srcLabel[$source] ?? $source }} <span class="text-muted-soft">({{ count($list) }})</span></div>
            <div class="flex flex-wrap gap-1">
                @foreach ($list as $it)
                    <span class="badge" style="font-size:var(--fs-xs);">{{ $it->keyword }}</span>
                @endforeach
            </div>
        </div>
    @empty
        <div class="text-muted-soft" style="font-size:var(--fs-xs);">추출된 키워드가 없습니다.</div>
    @endforelse
</div>

<script>
(function () {
    const box = document.getElementById('sk-prog');
    if (!box) return;
    const url = box.dataset.url;
    const csrf = '{{ csrf_token() }}';
    const fill = document.getElementById('sk-prog-fill');
    const label = document.getElementById('sk-prog-label');
    const resume = document.getElementById('sk-resume');
    let stopped = false;

    async function poll() {
        if (stopped) return;
        try {
            const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } });
            if (!r.ok) throw new Error('http ' + r.status);
            const d = await r.json();
            const pct = d.total ? Math.round(d.checked / d.total * 100) : 100;
            fill.style.width = pct + '%';
            label.textContent = `순위 확인 ${d.checked}/${d.total} · 상위 노출 ${d.exposed}`;
            if (d.remaining <= 0) { location.reload(); return; }
            if (d.blocked) {
                stopped = true;
                label.textContent = `쇼핑 API 한도로 중단 — 확인 ${d.checked}/${d.total} · 잠시 후 이어서 확인하세요`;
                resume.classList.remove('hidden');
                return;
            }
            setTimeout(poll, 400);
        } catch (e) {
            setTimeout(poll, 2500); // 일시 오류 재시도
        }
    }
    if (resume) resume.addEventListener('click', function () { stopped = false; resume.classList.add('hidden'); poll(); });
    poll();
})();
</script>

@endsection
