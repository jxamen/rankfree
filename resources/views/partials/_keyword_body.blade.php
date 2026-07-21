{{--
    키워드 분석 본문 — 콘솔(console.keyword) + 공개 공유(keyword.share) 공용.
    입력: $vm(뷰모델), $saturation, $popular, $weekday, $autocomplete, $public(공개 여부), $shareUrl(공유 URL|null).
    공개 뷰에서는 콘솔 전용 링크(연관키워드·블로그수집·인기글 지수분석·섹션배치 AJAX)를 숨긴다.
    자동완성은 공개 뷰에서도 노출하되 링크만 네이버 검색(새 창)으로 바꾼다.
--}}
@php
    $public = $public ?? false;
    $shareUrl = $shareUrl ?? null;
    $n = fn ($v) => $v === null ? '—' : number_format((int) $v);

    // 등급·상업성·경쟁강도에 색을 부여해 흑백 가독성 보완(디자인 토큰만)
    $gradeColor = match ($vm['grade']) {
        'S' => 'var(--color-success)', 'A' => 'var(--color-accent)', 'B' => 'var(--color-accent)',
        'C' => 'var(--color-badge-violet)', 'D' => 'var(--color-warning)',
        default => 'var(--color-muted)',
    };
    $compColor = match ($vm['comp_idx'] ?? null) {
        '높음' => 'var(--color-error)', '중간' => 'var(--color-warning)', '낮음' => 'var(--color-success)',
        default => 'var(--color-ink)',
    };
    $comm = $vm['commercial'];
    $cAccent = 'var(--color-accent)';
    $cGreen = 'var(--color-success)';
    $cPink = 'var(--color-badge-pink)';
    $cOrange = 'var(--color-badge-orange)';

    // 뮤트 모노크롬 라인 아이콘(currentColor) — 컬러 이모지 대체
    $icon = function (string $k) {
        $p = match ($k) {
            'pc' => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
            'mobile' => '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/>',
            'total' => '<path d="M17 5H7l6 7-6 7h10"/>',
            'blog' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>',
            'cafe' => '<path d="M21 11.5a8.38 8.38 0 0 1-9 8.5 8.5 8.5 0 0 1-3.8-.9L3 20l1.3-4.2A8.5 8.5 0 1 1 21 11.5z"/>',
            'layers' => '<path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>',
            default => '',
        };

        return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'.$p.'</svg>';
    };
@endphp

{{-- 본문 헤더 없음 — 공개 공유 뷰는 h1 라인(keyword/share)에 키워드·N통합검색이 있고,
     콘솔은 검색폼에 있어 여기서 다시 그리면 제목이 중복된다. --}}

{{-- 키워드 리스트 복사 헬퍼 — data-kw 를 줄바꿈('lines') 또는 쉼표('comma')로 구분해 복사(대량분석 입력·확장 호환) --}}
<script>
window.rfCopyKw = window.rfCopyKw || function (containerId, btn, mode) {
    var c = document.getElementById(containerId);
    if (!c) return;
    var kws = Array.prototype.slice.call(c.querySelectorAll('[data-kw]')).map(function (e) { return e.getAttribute('data-kw'); }).filter(Boolean);
    if (!kws.length) return;
    var text = mode === 'comma' ? kws.join(', ') : kws.join('\n');
    function done() { if (!btn.dataset.o) btn.dataset.o = btn.innerHTML; btn.innerHTML = '복사됨 ✓'; btn.disabled = true; setTimeout(function () { btn.innerHTML = btn.dataset.o; btn.disabled = false; }, 1400); }
    function fb() { var ta = document.createElement('textarea'); ta.value = text; ta.style.cssText = 'position:fixed;left:-9999px;top:0;'; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) {} ta.remove(); }
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, fb); } else { fb(); }
};
</script>

{{-- 키워드 인사이트(데이터 기반 요약) — 최상단(메타 스트립 위). 공유 모듈.
     공개 공유 뷰는 상단 'AEO 요약 답변' 카드가 같은 문장(insights.summary)을 이미 보여주므로 인사이트 요약은 숨긴다. --}}
@include('partials.keyword-detail', ['d' => $vm, 'only' => ['insights'], 'hideSummary' => $public])

{{-- 함께 많이 찾는 (네이버 통합검색 연관 키워드) — 인사이트 아래. 콘솔 전용, 섹션배치 AJAX 응답으로 채움 --}}
@unless ($public)
    <div id="serp-related" class="card p-5 mb-4" style="display:none;" data-url="{{ route('console.keyword') }}">
        <div class="flex items-center justify-between mb-3 gap-2 flex-wrap">
            <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">함께 많이 찾는</span>
            <div class="flex items-center gap-1">
                <button type="button" class="btn btn-ghost btn-sm" style="height:30px;" onclick="rfCopyKw('serp-related', this, 'lines')" title="줄바꿈으로 구분해 전체 복사">복사 ↵</button>
                <button type="button" class="btn btn-ghost btn-sm" style="height:30px;" onclick="rfCopyKw('serp-related', this, 'comma')" title="쉼표로 구분해 전체 복사">복사 ,</button>
            </div>
        </div>
        <div class="serp-related-list flex flex-wrap gap-2"></div>
    </div>
@endunless

{{-- 자동완성 제안어 (네이버 검색 자동완성) — 함께 많이 찾는 아래. 콘솔=재분석 링크 / 공개=네이버 검색(새 창) --}}
@if (! empty($autocomplete))
    <div class="card p-5 mb-4">
        <div class="flex items-center justify-between mb-3 gap-2 flex-wrap">
            <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">자동완성 <span class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:400;">검색 자동완성</span></span>
            <div class="flex items-center gap-1">
                <button type="button" class="btn btn-ghost btn-sm" style="height:30px;" onclick="rfCopyKw('kw-autocomplete', this, 'lines')" title="줄바꿈으로 구분해 전체 복사">복사 ↵</button>
                <button type="button" class="btn btn-ghost btn-sm" style="height:30px;" onclick="rfCopyKw('kw-autocomplete', this, 'comma')" title="쉼표로 구분해 전체 복사">복사 ,</button>
            </div>
        </div>
        <div id="kw-autocomplete" class="flex flex-wrap gap-2">
            @foreach ($autocomplete as $sug)
                <a href="{{ $public ? 'https://search.naver.com/search.naver?query='.urlencode($sug) : route('console.keyword', ['keyword' => $sug]) }}"
                   @if ($public) target="_blank" rel="noopener" @endif
                   data-kw="{{ $sug }}"
                   class="inline-flex items-center rounded-lg border border-hairline hover:border-ink transition"
                   style="padding:5px 11px;font-size:var(--fs-xs);background:var(--color-canvas);">{{ $sug }}</a>
            @endforeach
        </div>
    </div>
@endif

{{-- 메타 스트립 — 등급·경쟁강도·상업여부·모바일비중 (아이콘 카드) --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    @foreach ([
        ['키워드 등급', $vm['grade'], $gradeColor, $gradeColor, 'award'],
        ['경쟁 강도', $vm['comp_idx'] ?? '—', $compColor, $compColor, 'activity'],
        ['상업 키워드 여부', $comm['label'], 'var(--color-ink)', 'var(--color-badge-orange)', 'cart'],
        ['모바일 비중', $vm['mobile_pct'].'%', 'var(--color-ink)', 'var(--color-accent)', 'mobile'],
    ] as [$label, $value, $color, $chip, $mIcon])
        <div class="card p-5 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg flex-none flex items-center justify-center" style="background:color-mix(in srgb, {{ $chip }} 14%, transparent);color:{{ $chip }};">
                @if ($mIcon === 'award')
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
                @elseif ($mIcon === 'activity')
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                @elseif ($mIcon === 'cart')
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                @else
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/></svg>
                @endif
            </div>
            <div style="min-width:0;">
                <div class="text-body" style="font-size:var(--fs-xs);font-weight:600;">{{ $label }}</div>
                <div class="font-display" style="font-size:var(--fs-lg);color:{{ $color }};line-height:1.2;">{{ $value }}</div>
            </div>
        </div>
    @endforeach
</div>

{{-- 월간 검색량 · 누적 콘텐츠 발행량 (2-col) --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    {{-- 월간 검색량 --}}
    <div class="card p-5 relative overflow-hidden">
        {{-- 그리드 패턴 — 가로 1/3 지점부터 우측 노출 --}}
        <x-card-bg pattern="grid" color="var(--color-ink)" opacity="0.07" mask="right" />
        <div class="relative">
        <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-xs);">월간 검색량</div>
        <div class="grid grid-cols-3 gap-2 text-center">
            @foreach ([['pc', 'PC', $vm['pc']], ['mobile', 'Mobile', $vm['mobile']], ['total', 'Total', $vm['total']]] as [$ik, $lab, $val])
                <div>
                    <div style="width:42px;height:42px;border-radius:50%;background:var(--color-surface-strong);display:flex;align-items:center;justify-content:center;margin:0 auto 10px;color:var(--color-muted);">{!! $icon($ik) !!}</div>
                    <div class="font-display" style="font-size:var(--fs-xl);color:var(--color-ink);">{{ $n($val) }}</div>
                    <div class="text-muted" style="font-size:var(--fs-xs);margin-top:2px;">{{ $lab }}</div>
                </div>
            @endforeach
        </div>
        </div>
    </div>

    {{-- 누적 콘텐츠 발행량 (openapi 전체 문서 수) --}}
    <div class="card p-5 relative overflow-hidden">
        {{-- 그리드 패턴 — 월간 검색량과 동일, 1/3 지점부터 우측 노출 --}}
        <x-card-bg pattern="grid" color="var(--color-ink)" opacity="0.07" mask="right" />
        <div class="relative">
        <div class="flex items-center justify-between mb-4">
            <span class="text-ink font-semibold" style="font-size:var(--fs-xs);">누적 콘텐츠 발행량 <span class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:400;">전체 문서 수</span></span>
            @unless ($public)
                @if (! empty($saturation))
                    <a href="{{ route('console.blog', ['keyword' => $vm['keyword']]) }}" class="text-accent hover:underline" style="font-size:var(--fs-xs);">블로그 수집 →</a>
                @endif
            @endunless
        </div>
        @if (! empty($saturation))
            <div class="grid grid-cols-3 gap-2 text-center">
                @foreach ([['blog', '블로그', 'blog'], ['cafe', '카페', 'cafe'], ['layers', '전체', 'total']] as [$ik, $lab, $k])
                    <div>
                        <div style="width:42px;height:42px;border-radius:50%;background:var(--color-surface-strong);display:flex;align-items:center;justify-content:center;margin:0 auto 10px;color:var(--color-muted);">{!! $icon($ik) !!}</div>
                        <div class="font-display" style="font-size:var(--fs-xl);color:var(--color-ink);">{{ $n($saturation[$k]['volume']) }}</div>
                        <div class="text-muted" style="font-size:var(--fs-xs);margin-top:2px;">{{ $lab }}</div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-muted-soft" style="font-size:var(--fs-xs);padding:14px 0;">발행량 데이터를 조회하지 못했습니다.</p>
        @endif
        </div>
    </div>
</div>

{{-- 예상 검색량 · 콘텐츠 포화 지수 (2-col) --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
    {{-- 예상 검색량 --}}
    <div class="card p-5 relative overflow-hidden">
        <x-card-bg pattern="grid" color="var(--color-ink)" opacity="0.07" mask="right" />
        <div class="relative">
        <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-xs);">예상 검색량 <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">월간 비례 환산</span></div>
        <div class="grid grid-cols-3 gap-2 text-center">
            @foreach ([['일 환산', $vm['forecast']['daily']], ['7일 환산', $vm['forecast']['weekly']], ['30일(월) 예상', $vm['forecast']['monthly']]] as [$lab, $val])
                <div>
                    <div class="text-muted" style="font-size:var(--fs-xs);">{{ $lab }}</div>
                    <div class="font-display mt-1" style="font-size:var(--fs-xl);color:var(--color-ink);">{{ $n($val) }}</div>
                </div>
            @endforeach
        </div>
        </div>
    </div>

    {{-- 콘텐츠 포화 지수 --}}
    <div class="card p-5 relative overflow-hidden">
        <x-card-bg pattern="grid" color="var(--color-ink)" opacity="0.07" mask="right" />
        <div class="relative">
        <div class="text-ink font-semibold mb-4" style="font-size:var(--fs-xs);">콘텐츠 포화 지수 <span class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:400;">검색 수요 대비 포화도</span></div>
        @if (! empty($saturation))
            <div class="grid grid-cols-3 gap-2 text-center">
                @foreach ([['블로그', 'blog'], ['카페', 'cafe'], ['전체', 'total']] as [$srcLabel, $k])
                    @php $s = $saturation[$k]; @endphp
                    <div>
                        <div style="width:40px;height:40px;border-radius:50%;background:var(--color-surface-strong);display:flex;align-items:center;justify-content:center;margin:0 auto 10px;color:var(--color-muted);">{!! $icon('layers') !!}</div>
                        <div class="font-display" style="font-size:var(--fs-xl);color:var(--color-ink);">{{ $s['pct'] }}%</div>
                        <div class="text-muted" style="font-size:var(--fs-xs);font-weight:600;margin-top:2px;">{{ $s['label'] }}</div>
                        <div class="text-muted" style="font-size:var(--fs-xs);margin-top:4px;">{{ $srcLabel }}</div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-muted-soft" style="font-size:var(--fs-xs);padding:14px 0;">포화 지수를 계산할 발행량/검색량 데이터가 없습니다.</p>
        @endif
        </div>
    </div>
</div>

{{-- 연관 키워드 — 키워드 페이지 전용(쇼핑 미사용) --}}
<div class="card overflow-hidden mb-6">
    <div class="px-5 py-4 text-ink font-semibold" style="font-size:var(--fs-xs);">연관 키워드 <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ count($vm['related']) }}개</span> <span class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:400;">「{{ $vm['keyword'] }}」 포함</span></div>
    <div style="overflow:auto;max-height:600px;">
        <table class="w-full" style="min-width:520px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);position:sticky;top:0;background:var(--color-canvas);z-index:1;">
                    <th class="text-center px-4 py-2.5 font-semibold" style="width:52px;">No</th>
                    <th class="text-left px-3 py-2.5 font-semibold">키워드</th>
                    <th class="text-right px-3 py-2.5 font-semibold">월간 검색량</th>
                    <th class="text-right px-5 py-2.5 font-semibold">경쟁 강도</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($vm['related'] as $i => $r)
                    <tr style="border-top:1px solid var(--color-hairline-soft);">
                        <td class="text-center px-4 py-3 text-muted font-display" style="font-size:var(--fs-xs);">{{ $i + 1 }}</td>
                        <td class="px-3 py-3">
                            @if ($public)
                                <span class="text-ink" style="font-size:var(--fs-xs);">{{ $r['keyword'] }}</span>
                            @else
                                <a href="{{ route('console.keyword', ['keyword' => $r['keyword']]) }}" class="text-ink hover:underline" style="font-size:var(--fs-xs);">{{ $r['keyword'] }}</a>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format($r['total']) }}</td>
                        <td class="px-5 py-3 text-right text-muted" style="font-size:var(--fs-xs);">{{ $r['comp_idx'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center" style="padding:32px;color:var(--color-muted);font-size:var(--fs-xs);">「{{ $vm['keyword'] }}」 를 포함한 연관 키워드가 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if (empty($vm['has_detail']))
    {{-- 세션 미연결 안내 --}}
    <div class="card-soft mb-6 px-4 py-3 text-muted" style="font-size:var(--fs-xs);">
        검색량 트렌드 · 성별/연령 분포 · 월별 비율은 검색광고 웹 세션(로그인) 연결 시 제공됩니다. 현재는 검색량 · 경쟁강도 · 콘텐츠 포화 · 요일별 · 인기글 · 연관 키워드가 표시됩니다.
    </div>
@else
    {{-- 검색량 트렌드(선그래프) — 공유 모듈 --}}
    @include('partials.keyword-detail', ['d' => $vm, 'only' => ['trend']])

    {{-- 월별 · 요일별 검색 비율 (2-col, 파란 막대 + Y축) — 공유 모듈(시장 분석과 공용) --}}
    @include('partials.keyword-detail', ['d' => $vm, 'only' => ['month'], 'weekday' => $weekday])

    {{-- PC · Mobile 섹션 배치 순서 + "함께 많이 찾는" — 통합검색 실측(HTTP 비동기 로드). 콘솔 전용(AJAX 라우트) --}}
    @unless ($public)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6" id="serp-sections" data-url="{{ route('console.keyword.sections', ['keyword' => $vm['keyword']]) }}">
            @foreach (['PC' => 'pc', 'Mobile' => 'mobile'] as $label => $key)
                <div class="card p-5">
                    <div class="text-ink font-semibold mb-3" style="font-size:var(--fs-xs);">{{ $label }} 섹션 배치 순서 <span class="text-muted-soft" style="font-size:var(--fs-xs);font-weight:400;">통합검색 실측</span></div>
                    <div class="serp-list" data-key="{{ $key }}">
                        <div class="flex items-center gap-2 text-muted-soft" style="font-size:var(--fs-xs);padding:20px 0;">
                            <span style="width:14px;height:14px;border:2px solid var(--color-hairline);border-top-color:var(--color-accent);border-radius:50%;display:inline-block;animation:serpspin 0.7s linear infinite;"></span>
                            섹션 순서를 분석하고 있습니다…
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <style>@keyframes serpspin{to{transform:rotate(360deg)}}</style>
        <script>
        (function () {
            var box = document.getElementById('serp-sections');
            if (!box || box.dataset.loaded) return;
            box.dataset.loaded = '1';
            var fail = function () {
                box.querySelectorAll('.serp-list').forEach(function (l) {
                    l.innerHTML = '<p class="text-muted-soft" style="font-size:var(--fs-xs);padding:20px 0;">섹션 순서를 수집하지 못했습니다.</p>';
                });
            };
            fetch(box.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    box.querySelectorAll('.serp-list').forEach(function (list) {
                        var arr = (d && d.ok && Array.isArray(d[list.dataset.key])) ? d[list.dataset.key] : null;
                        if (arr && arr.length) {
                            list.innerHTML = arr.map(function (it, i) {
                                // 신형 {name,count} · 구형 문자열 모두 지원
                                var name = (it && typeof it === 'object') ? it.name : it;
                                var cnt = (it && typeof it === 'object') ? (it.count | 0) : 0;
                                var badge = cnt > 0
                                    ? '<span class="badge" style="font-size:var(--fs-xs);padding:1px 8px;margin-left:auto;">' + cnt + '개</span>'
                                    : '';
                                return '<div style="display:flex;align-items:center;gap:12px;padding:9px 0;border-top:1px solid var(--color-hairline-soft);">'
                                    + '<span class="font-display" style="font-size:var(--fs-xs);width:20px;color:var(--color-muted);">' + (i + 1) + '</span>'
                                    + '<span style="font-size:var(--fs-xs);color:var(--color-ink);">' + String(name).replace(/</g, '&lt;') + '</span>'
                                    + badge + '</div>';
                            }).join('');
                        } else {
                            list.innerHTML = '<p class="text-muted-soft" style="font-size:var(--fs-xs);padding:20px 0;">섹션 순서를 수집하지 못했습니다.</p>';
                        }
                    });
                    // 함께 많이 찾는 연관 키워드
                    var rel = (d && d.ok && Array.isArray(d.related)) ? d.related : [];
                    var relBox = document.getElementById('serp-related');
                    if (relBox && rel.length) {
                        var base = relBox.dataset.url;
                        relBox.querySelector('.serp-related-list').innerHTML = rel.map(function (it) {
                            var kw = (it && typeof it === 'object') ? it.keyword : it;
                            var bt = (it && typeof it === 'object' && it.badge) ? it.badge : '';
                            var safe = String(kw).replace(/</g, '&lt;');
                            var kwAttr = String(kw).replace(/"/g, '&quot;');
                            var badge = bt ? '<span style="font-size:var(--fs-xs);color:var(--color-primary);margin-left:5px;">' + bt.replace(/</g, '&lt;') + '</span>' : '';
                            return '<a href="' + base + '?keyword=' + encodeURIComponent(kw) + '" data-kw="' + kwAttr + '" class="badge border border-hairline" style="font-size:var(--fs-xs);padding:5px 11px;color:var(--color-ink);">' + safe + badge + '</a>';
                        }).join('');
                        relBox.style.display = '';
                    }
                })
                .catch(fail);
        })();
        </script>
    @endunless

    {{-- 성별·연령별 검색 비율 (연령별→성별+연령) — 공유 모듈 --}}
    @include('partials.keyword-detail', ['d' => $vm, 'only' => ['genderage']])

    {{-- 디바이스별 검색 비율 (전체·성별·연령) — 공유 모듈 --}}
    @include('partials.keyword-detail', ['d' => $vm, 'only' => ['device']])

    {{-- 성별 · 이슈성 · 정보성/상업성 (3-col 도넛) — 키워드/시장 분석 공용 partial --}}
    @include('partials.keyword-demo-donut', ['d' => $vm, 'comm' => $comm])
@endif

{{-- 인기글 TOP — openapi 블로그·카페 관련도 상위글(세션 불필요). 가장 아래 배치. 항목별 열 분리 테이블 --}}
@if (! empty($popular))
    <div class="card overflow-hidden mb-6">
        <div class="px-5 py-4 text-ink font-semibold" style="font-size:var(--fs-xs);">인기글 TOP <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">블로그·카페 상위</span></div>
        <div style="overflow-x:auto;">
            <table class="w-full" style="min-width:640px;">
                <thead>
                    <tr class="text-muted" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);">
                        <th class="text-center px-3 py-2.5 font-semibold" style="width:78px;">순위</th>
                        <th class="text-left px-3 py-2.5 font-semibold">제목</th>
                        <th class="text-left px-3 py-2.5 font-semibold" style="width:102px;">출처</th>
                        <th class="text-center px-3 py-2.5 font-semibold" style="width:60px;">등급</th>
                        <th class="text-left px-3 py-2.5 font-semibold" style="width:150px;">작성자</th>
                        <th class="text-right px-5 py-2.5 font-semibold" style="width:104px;">작성일</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($popular as $i => $p)
                        @php $isBlog = ($p['source'] ?? '') === '블로그' && ! empty($p['blog_id']); @endphp
                        <tr class="kw-pop-row" style="border-top:1px solid var(--color-hairline-soft);">
                            <td class="text-center px-3 py-3 text-muted font-display" style="font-size:var(--fs-xs);">{{ $i + 1 }}</td>
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-2" style="max-width:520px;">
                                    <a href="{{ $p['link'] }}" target="_blank" rel="noopener" class="text-ink hover:underline" style="font-size:var(--fs-xs);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $p['title'] }}</a>
                                    @if ($isBlog && ! $public)
                                        <a href="{{ route('console.blog-single', ['q' => $p['blog_id']]) }}" target="_blank" rel="noopener"
                                           class="kw-idx-btn inline-flex items-center gap-1"
                                           title="이 블로그의 지수 분석을 새 창에서 실행합니다"
                                           style="flex-shrink:0;height:22px;padding:0 9px;border-radius:6px;font-size:var(--fs-xs);font-weight:600;color:var(--color-ink);background:var(--color-surface-card);border:1px solid var(--color-hairline);text-decoration:none;">
                                            🔍 블로그 지수 분석
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="opacity:.7;"><path d="M7 17 17 7M9 7h8v8"/></svg>
                                        </a>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-3"><span class="badge" style="font-size:var(--fs-xs);padding:1px 7px;">{{ $p['source'] }}</span></td>
                            <td class="text-center px-3 py-3">
                                @php $bg = $p['grade'] ?? null; @endphp
                                @if ($bg && ! empty($p['blog_id']) && ! $public)
                                    @php $gc = match ($bg['grade']) { 'S' => 'var(--color-success)', 'A', 'B' => 'var(--color-accent)', 'C' => 'var(--color-badge-violet)', default => 'var(--color-muted)' }; @endphp
                                    <a href="{{ route('console.blog-single', ['q' => $p['blog_id']]) }}" target="_blank" rel="noopener"
                                       class="kw-grade inline-flex items-center justify-center gap-1 font-display"
                                       style="height:22px;padding:0 8px;border-radius:6px;font-size:var(--fs-xs);font-weight:700;color:#fff;background:{{ $gc }};text-decoration:none;transition:opacity .12s;"
                                       title="블로그 지수 {{ $bg['score'] }}점 · 일평균 방문 {{ number_format($bg['day_visitor']) }} · 이웃 {{ number_format($bg['subscriber']) }}{{ $bg['influencer'] ? ' · 인플루언서' : '' }}{{ $bg['power'] ? ' · 파워블로그' : '' }} — 클릭 시 블로그 수집(새 창)">
                                        {{ $bg['grade'] }}
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="opacity:.85;"><path d="M7 17 17 7M9 7h8v8"/></svg>
                                    </a>
                                @elseif ($bg)
                                    @php $gc = match ($bg['grade']) { 'S' => 'var(--color-success)', 'A', 'B' => 'var(--color-accent)', 'C' => 'var(--color-badge-violet)', default => 'var(--color-muted)' }; @endphp
                                    <span class="inline-flex items-center justify-center font-display" style="min-width:22px;height:22px;padding:0 6px;border-radius:6px;font-size:var(--fs-xs);font-weight:700;color:#fff;background:{{ $gc }};">{{ $bg['grade'] }}</span>
                                @else
                                    <span class="text-muted-soft" style="font-size:var(--fs-xs);">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-muted" style="font-size:var(--fs-xs);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:150px;">{{ $p['author'] ?: '—' }}</td>
                            <td class="px-5 py-3 text-right text-muted-soft" style="font-size:var(--fs-xs);white-space:nowrap;">{{ $p['date'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <style>
        .kw-grade:hover{opacity:.82;box-shadow:0 0 0 2px color-mix(in srgb, var(--color-ink) 15%, transparent);}
        /* 인기글 블로그 항목 — 지수 분석 칩: 행 hover 시 노출 */
        .kw-idx-btn{opacity:0;transition:opacity .12s,background .12s;white-space:nowrap;}
        .kw-pop-row:hover .kw-idx-btn{opacity:1;}
        .kw-idx-btn:hover{background:var(--color-ink);color:#fff;border-color:var(--color-ink);}
        @media (hover:none){.kw-idx-btn{opacity:1;}}
    </style>
@endif

<p class="text-muted-soft mt-4" style="font-size:var(--fs-xs);">* 등급·상업성·포화 지수·예상 검색량은 관측 신호 기반 자체 추정치입니다. 누적 발행량은 openapi 전체 문서 수(월간 발행량 아님).</p>
