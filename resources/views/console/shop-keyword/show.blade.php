@extends('console.layout')
@section('page-title', '쇼핑 노출 키워드 — '.$analysis->core_keyword)

@section('console-content')
@php
    // 조합 재료 소스 + 참고(조합 X) 소스 라벨
    $srcLabel = [
        'title' => '제목 단어', 'seller_tag' => '상세 SEO태그', 'attribute' => '상품속성', 'suffix' => '어미',
        'autocomplete' => '자동완성', 'searchad' => '검색광고 추천', 'shopping_related' => '쇼핑 연관',
        'keyword_rec' => '쇼핑 키워드추천', 'together' => '함께 많이 찾는', 'competitor_brand' => '경쟁 브랜드',
    ];
    $comboSources = ['title', 'seller_tag', 'attribute', 'suffix'];   // 조합에 쓰는 소스
    $refSources = ['autocomplete', 'searchad', 'shopping_related', 'keyword_rec', 'together', 'competitor_brand'];
    $th = $analysis->threshold;
    $shopUrl = fn ($kw) => 'https://m.search.naver.com/search.naver?where=m&query='.urlencode($kw);
    $rankCell = function ($rank) use ($th) {
        if ($rank === null) return ['미확인', 'text-muted-soft'];
        if ($rank <= 0) return ['미노출', 'text-muted-soft'];   // 가격비교 오가닉에 없음
        if ($rank <= $th) return [$rank.'위', 'text-success font-semibold'];
        return [$rank.'위', 'text-muted'];
    };
@endphp

<x-console.page-head title="쇼핑 노출 키워드 분석 결과">
    <x-slot:desc>{{ $analysis->created_at->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</x-slot:desc>
</x-console.page-head>

{{-- 상단: 키워드 · 제품 URL · 제품명 --}}
<div class="card p-4 mb-4">
    <div class="flex flex-wrap gap-x-6 gap-y-1" style="font-size:var(--fs-sm);">
        <div><span class="text-muted" style="font-size:var(--fs-xs);">핵심 키워드</span><br><b class="text-ink">{{ $analysis->core_keyword }}</b></div>
        @if ($analysis->mall_name)<div><span class="text-muted" style="font-size:var(--fs-xs);">업체명</span><br><b class="text-ink">{{ $analysis->mall_name }}</b></div>@endif
        @if ($analysis->product_price)<div><span class="text-muted" style="font-size:var(--fs-xs);">가격</span><br><b class="text-ink font-mono">{{ number_format($analysis->product_price) }}원</b></div>@endif
    </div>
    @if ($analysis->product_title)
        <div class="mt-2"><span class="text-muted" style="font-size:var(--fs-xs);">제품명</span>
            <span class="text-ink" style="font-size:var(--fs-sm);">{{ $analysis->product_title }}</span></div>
    @endif
    @if ($analysis->product_url)
        <div class="mt-1"><span class="text-muted" style="font-size:var(--fs-xs);">제품 URL</span>
            <a href="{{ \Illuminate\Support\Str::startsWith($analysis->product_url, ['http://','https://']) ? $analysis->product_url : '#' }}" target="_blank" rel="noopener nofollow" class="text-muted-soft" style="font-size:var(--fs-xs);word-break:break-all;">{{ $analysis->product_url }}</a></div>
    @endif
</div>

<div class="flex items-center gap-2 mb-4 flex-wrap">
    <a href="{{ route('console.shop-keyword') }}" class="btn btn-ghost btn-sm">← 목록</a>
    <button type="button" id="sk-regen" class="btn btn-primary btn-sm" data-url="{{ route('console.shop-keyword.regenerate', $analysis) }}"
        title="노출 안 된 조합을 접고 새 조합을 생성합니다(노출 키워드는 유지)">＋ 새로 조합</button>
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
                <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">노출 순위</th>
                <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">월 검색량</th>
                <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;"></th>
            </tr></thead>
            <tbody>
            @foreach ($exposed as $c)
                @php [$rt, $rc] = $rankCell($c->rank); @endphp
                <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                    <td class="py-2 text-ink" style="font-size:var(--fs-sm);">{{ $c->keyword }}
                        @if ($c->ad_exposed)<span class="badge" style="font-size:var(--fs-xs);color:var(--color-muted);" title="이 키워드에서 내 상품이 광고로도 노출 중">광고</span>@endif
                    </td>
                    <td class="py-2 text-right {{ $rc }} font-mono" style="font-size:var(--fs-sm);">{{ $rt }}</td>
                    <td class="py-2 text-right text-muted font-mono" style="font-size:var(--fs-xs);">{{ $c->monthly_total !== null ? number_format($c->monthly_total) : '—' }}</td>
                    <td class="py-2 text-right" style="white-space:nowrap;">
                        <a href="{{ $shopUrl($c->keyword) }}" target="_blank" rel="noopener nofollow" class="text-muted-soft" style="font-size:var(--fs-xs);">검색 ↗</a>
                        <button type="button" class="sk-del" data-item="{{ $c->id }}" data-kind="combo" title="삭제" style="margin-left:8px;">✕</button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- 전체 조합 순위 (클릭=모바일 검색 열기, ✕=조합 삭제) --}}
<div class="card p-5 mb-4">
    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-sm);">전체 조합 순위 <span class="text-muted-soft font-normal">({{ $combos->count() }})</span>
        <span class="text-muted-soft font-normal" style="font-size:var(--fs-xs);">· 조합 클릭 시 네이버 모바일 검색이 열립니다</span>
    </div>
    <div class="flex flex-wrap gap-2">
        @foreach ($combos as $c)
            @php [$rt, $rc] = $rankCell($c->rank); @endphp
            <span class="badge sk-badge" style="font-size:var(--fs-xs);display:inline-flex;align-items:center;gap:5px;">
                <a href="{{ $shopUrl($c->keyword) }}" target="_blank" rel="noopener nofollow" class="text-ink" style="text-decoration:none;">{{ $c->keyword }}</a>
                <span class="{{ $rc }} font-mono">{{ $rt }}</span>
                @if ($c->ad_exposed)<span class="text-muted-soft" title="광고로도 노출">광고</span>@endif
                <button type="button" class="sk-del" data-item="{{ $c->id }}" data-kind="combo" title="이 조합 삭제">✕</button>
            </span>
        @endforeach
    </div>
</div>

{{-- 조합 재료(제목 단어·속성·어미·SEO태그) — ✕ 삭제 시 그 단어를 쓴 조합도 함께 사라집니다 --}}
<div class="card p-5 mb-4">
    <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">조합 재료</div>
    <div class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">이 단어들로 조합을 만듭니다. 쓸모없는 단어는 ✕로 지우세요 — 그 단어가 든 조합도 함께 제거되고, "새로 조합" 때도 다시 만들지 않습니다.</div>
    @foreach ($comboSources as $source)
        @php $list = $tokens[$source] ?? collect(); @endphp
        @if (count($list))
            <div class="mb-3">
                <div class="text-muted mb-1" style="font-size:var(--fs-xs);">{{ $srcLabel[$source] ?? $source }} <span class="text-muted-soft">({{ count($list) }})</span></div>
                <div class="flex flex-wrap gap-1">
                    @foreach ($list as $it)
                        <span class="badge sk-badge" style="font-size:var(--fs-xs);display:inline-flex;align-items:center;gap:5px;">{{ $it->keyword }}
                            <button type="button" class="sk-del" data-item="{{ $it->id }}" data-kind="token" title="이 단어·관련 조합 삭제">✕</button>
                        </span>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach
</div>

{{-- 참고 키워드(조합에는 사용 안 함 — 검색 인사이트용) --}}
<div class="card p-5">
    <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">참고 키워드 <span class="text-muted-soft font-normal">(조합엔 안 씀 · 검색 인사이트)</span></div>
    <div class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">사람들이 실제 검색하는 키워드 — 제목·태그 개선 참고용입니다.</div>
    @php $anyRef = false; @endphp
    @foreach ($refSources as $source)
        @php $list = $tokens[$source] ?? collect(); @endphp
        @if (count($list))
            @php $anyRef = true; @endphp
            <div class="mb-3">
                <div class="text-muted mb-1" style="font-size:var(--fs-xs);">{{ $srcLabel[$source] ?? $source }} <span class="text-muted-soft">({{ count($list) }})</span></div>
                <div class="flex flex-wrap gap-1">
                    @foreach ($list as $it)
                        <span class="badge" style="font-size:var(--fs-xs);">{{ $it->keyword }}</span>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach
    @unless ($anyRef)<div class="text-muted-soft" style="font-size:var(--fs-xs);">참고 키워드가 없습니다.</div>@endunless
</div>

<style>.sk-del{border:0;background:none;cursor:pointer;color:var(--color-muted-soft);line-height:1;padding:0 1px;}.sk-del:hover{color:var(--color-error);}</style>
<script>
(function () {
    const base = "{{ url('console/shop-keyword/'.$analysis->id.'/item') }}";
    const csrf = '{{ csrf_token() }}';
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.sk-del');
        if (!btn) return;
        btn.disabled = true;
        fetch(base + '/' + btn.dataset.item, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
            .then((r) => r.ok ? r.json() : Promise.reject())
            .then(() => { if (btn.dataset.kind === 'token') location.reload(); else { const el = btn.closest('.sk-badge') || btn.closest('tr'); if (el) el.remove(); } })
            .catch(() => { btn.disabled = false; alert('삭제에 실패했습니다.'); });
    });

    // 새로 조합 — 노출 실패분 접고 새 조합 생성 후 재폴링(reload)
    const regen = document.getElementById('sk-regen');
    if (regen) regen.addEventListener('click', function () {
        regen.disabled = true;
        regen.textContent = '조합 생성 중…';
        fetch(regen.dataset.url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
            .then((r) => r.ok ? r.json() : Promise.reject())
            .then((d) => { if ((d.added || 0) === 0) { alert('더 만들 새 조합이 없습니다.'); regen.disabled = false; regen.textContent = '＋ 새로 조합'; } else { location.reload(); } })
            .catch(() => { regen.disabled = false; regen.textContent = '＋ 새로 조합'; alert('새 조합 생성 실패'); });
    });
})();
</script>

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
    let errCount = 0;

    function halt(msg, showResume) {
        stopped = true;
        label.textContent = msg;
        if (showResume) resume.classList.remove('hidden');
    }
    function retry() {
        if (++errCount > 6) { halt('연결/서버 오류로 중단 — 새로고침 해주세요', false); return; }
        setTimeout(poll, Math.min(8000, 1000 * errCount)); // 지수 백오프
    }

    async function poll() {
        if (stopped) return;
        let r;
        try {
            r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } });
        } catch (e) { retry(); return; }

        // 영구 오류(세션 만료·삭제·권한)는 즉시 중단 — 무한 재시도 금지
        if (r.status === 419 || r.status === 401 || r.status === 403 || r.status === 404) {
            halt('세션이 만료됐거나 접근할 수 없습니다 — 새로고침 해주세요', false);
            return;
        }
        if (r.status === 429) { retry(); return; }   // 요청 과다 — 백오프
        if (!r.ok) { retry(); return; }

        let d;
        try { d = await r.json(); } catch (e) { retry(); return; }
        errCount = 0;

        const pct = d.total ? Math.round(d.checked / d.total * 100) : 100;
        fill.style.width = pct + '%';
        label.textContent = `순위 확인 ${d.checked}/${d.total} · 상위 노출 ${d.exposed}`;
        if (d.remaining <= 0) { location.reload(); return; }
        if (d.blocked) {
            halt(`쇼핑 API 한도/오류로 중단 — 확인 ${d.checked}/${d.total} · 잠시 후 이어서 확인하세요`, true);
            return;
        }
        setTimeout(poll, 500);
    }
    if (resume) resume.addEventListener('click', function () { stopped = false; errCount = 0; resume.classList.add('hidden'); poll(); });
    poll();
})();
</script>

@endsection
