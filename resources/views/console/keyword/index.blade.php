@extends('console.layout')
@section('page-title', '키워드 분석')

@section('console-content')

{{-- 분석 입력 --}}
<form method="GET" action="{{ route('console.keyword') }}" class="flex items-center gap-2 mb-4" id="kw-form">
    <input type="text" name="keyword" value="{{ $keyword }}" placeholder="분석할 키워드를 입력하세요 (예: 강남 맛집)"
           class="input" style="flex:1;height:44px;font-size:var(--fs-sm);" autofocus autocomplete="off">
    <button type="submit" class="btn btn-primary" style="height:44px;padding:0 22px;">분석</button>
    @if (! empty($keyword))
        {{-- 네이버 통합검색을 새 창으로 (N=네이버 브랜드색은 예외적 인라인) --}}
        <a href="https://search.naver.com/search.naver?query={{ urlencode($keyword) }}" target="_blank" rel="noopener"
           class="btn btn-secondary inline-flex items-center gap-1" style="height:44px;padding:0 18px;" title="「{{ $keyword }}」 네이버 통합검색 (새 창)">
            <span style="color:#03c75a;font-weight:800;font-size:var(--fs-sm);">N</span> 통합검색
        </a>
        {{-- 공유: 비로그인 공개 리포트 링크(/k/{token}) 복사 --}}
        <button type="button" class="btn btn-secondary" style="height:44px;padding:0 18px;" onclick="rfCopyShare(this, @js($shareUrl ?? ''))" title="비로그인 공개 공유 링크 복사">🔗 공유</button>
        @if (! empty($vm) && ! empty($vm['has_data']))
            <button type="button" class="btn btn-secondary" style="height:44px;padding:0 18px;" onclick="rfSaveReportImage('rf-keyword-report','랭크프리-키워드분석.png',this)" title="키워드 분석 리포트를 PNG 이미지로 저장">🖼 이미지 저장</button>
        @endif
    @endif
</form>

@if (! $vm)
    @if (isset($history) && $history->count())
        {{-- 최근 검색 — 리스트(키워드·검색량·등급·경쟁·최종 분석일). 클릭 시 저장된 분석 열람(재수집·과금 없음) --}}
        <div class="card overflow-hidden">
            <div class="px-5 py-4 flex items-center justify-between gap-3">
                <div class="text-ink font-semibold" style="font-size:var(--fs-xs);">최근 검색 <span class="text-muted-soft" style="font-weight:400;">클릭하면 저장된 분석을 다시 봅니다 · 새로 수집하려면 위에서 재검색하세요</span></div>
                <input type="text" id="rf-kw-filter" class="input flex-none" style="width:200px;height:32px;font-size:var(--fs-xs);" placeholder="목록에서 키워드 검색" autocomplete="off">
            </div>
            <div style="overflow-x:auto;">
                <table class="w-full" style="min-width:560px;">
                    <thead>
                        <tr class="text-muted" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);">
                            <th class="text-left px-5 py-2.5 font-semibold" style="width:52px;">No</th>
                            <th class="text-left px-3 py-2.5 font-semibold">키워드</th>
                            <th class="text-right px-3 py-2.5 font-semibold" style="width:120px;">월간 검색량</th>
                            <th class="text-center px-3 py-2.5 font-semibold" style="width:64px;">등급</th>
                            <th class="text-center px-3 py-2.5 font-semibold" style="width:70px;">경쟁</th>
                            <th class="text-right px-5 py-2.5 font-semibold" style="width:250px;">최종 분석</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $h)
                            <tr class="rf-kw-row" data-kw="{{ \Illuminate\Support\Str::lower($h->keyword) }}" style="border-top:1px solid var(--color-hairline-soft);">
                                {{-- No — 최신이 위이므로 큰 번호부터 내림차순 --}}
                                <td class="px-5 py-3 text-muted" style="font-size:var(--fs-xs);">{{ count($history) - $loop->index }}</td>
                                <td class="px-3 py-3"><a href="{{ route('console.keyword', ['keyword' => $h->keyword, 'view' => 1]) }}" class="text-ink hover:underline font-medium" style="font-size:var(--fs-xs);">{{ $h->keyword }}</a></td>
                                <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ number_format($h->monthly_total) }}</td>
                                <td class="text-center px-3 py-3 font-display" style="font-size:var(--fs-xs);">{{ $h->grade ?? '—' }}</td>
                                <td class="text-center px-3 py-3 text-muted" style="font-size:var(--fs-xs);">{{ $h->comp_idx ?? '—' }}</td>
                                <td class="px-5 py-3 text-right text-muted-soft" style="font-size:var(--fs-xs);white-space:nowrap;">
                                    {{ $h->updated_at->format('Y-m-d H:i') }}
                                    <form method="POST" action="{{ route('console.keyword.destroy', $h) }}" style="display:inline;margin-left:10px;" data-confirm="이 검색 기록을 삭제할까요?">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-muted-soft hover:text-error" style="background:none;border:0;cursor:pointer;font-size:var(--fs-xs);">삭제</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <script>
        (function () {
            var f = document.getElementById('rf-kw-filter');
            if (!f) return;
            var rows = Array.prototype.slice.call(document.querySelectorAll('.rf-kw-row'));
            f.addEventListener('input', function () {
                var q = f.value.trim().toLowerCase();
                rows.forEach(function (r) {
                    r.style.display = (!q || (r.getAttribute('data-kw') || '').indexOf(q) >= 0) ? '' : 'none';
                });
            });
        })();
        </script>
    @else
        {{-- 안내 — 최근 검색이 없을 때만 --}}
        <div class="card p-8 text-center">
            <div style="font-size:var(--fs-2xl);opacity:.35;">🔍</div>
            <p class="text-ink font-semibold mt-3" style="font-size:var(--fs-base);">키워드를 입력해 분석을 시작하세요</p>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);">월간 검색량 · 성별/연령 분포 · 12개월 트렌드 · 콘텐츠 포화 · 연관 키워드를 한눈에 확인합니다.</p>
            <div class="flex items-center justify-center gap-2 mt-4 flex-wrap">
                @foreach (['강남 맛집', '제주 호텔', '다이어트 보조제', '강아지 사료'] as $ex)
                    <a href="{{ route('console.keyword', ['keyword' => $ex]) }}" class="badge" style="font-size:var(--fs-xs);padding:5px 12px;">{{ $ex }}</a>
                @endforeach
            </div>
        </div>
    @endif

@elseif (! $vm['has_data'])
    <div class="card-soft px-4 py-4 text-muted" style="font-size:var(--fs-xs);">
        「{{ $vm['keyword'] }}」 데이터를 조회하지 못했습니다. 검색량이 매우 적거나, 검색광고 API 자격증명이 설정되지 않았을 수 있습니다.
    </div>

@else
    @if ($fromCache ?? false)
        {{-- 캐시(스냅샷) 열람 — 재수집·과금 없음. 최신화는 검색 폼에서 다시 검색. --}}
        <div class="card-soft px-4 py-2.5 mb-4 flex items-center gap-2 flex-wrap" style="font-size:var(--fs-xs);">
            <span>📌</span>
            <span class="text-muted">저장된 분석 결과입니다(재수집·과금 없음). 최신 데이터가 필요하면 위 검색창에서 <b class="text-ink">다시 검색</b>하세요.</span>
        </div>
    @endif
    {{-- 결과 본문 — 콘솔/공개 공유 공용 partial (이미지 저장 대상) --}}
    <div id="rf-keyword-report">
        <div class="rf-cap-only" style="align-items:center;justify-content:space-between;gap:8px;margin-bottom:16px;">
            <span class="badge border border-hairline">키워드 분석 리포트 · 랭크프리</span>
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">rankfree.kr</span>
        </div>
        @include('partials._keyword_body', ['public' => false, 'shareUrl' => $shareUrl ?? null])
        <div class="rf-cap-only" style="flex-direction:column;align-items:center;gap:4px;margin-top:16px;border-top:1px solid var(--color-hairline);padding-top:14px;text-align:center;">
            <span class="text-muted" style="font-size:var(--fs-xs);">이 리포트는 <b class="text-ink">랭크프리</b>에서 키워드 분석으로 생성되었습니다.</span>
            <span class="text-muted" style="font-size:var(--fs-xs);">네이버에서 <b class="text-ink">랭크프리</b>를 검색 방문하고 무료로 키워드를 분석해보세요.</span>
        </div>
    </div>
    @include('console.partials._image-save')
@endif

<script>
(function () {
    // 진행 중 로딩 — 캐시로 즉시 열리는 이동엔 오버레이를 띄우지 않도록 지연 표시.
    //   빠른 이동은 350ms 전에 페이지가 언로드되어 타이머가 사라짐(오버레이 미표시). 느린 신규 분석만 표시.
    var loadTimer = null;
    function delayedLoad(sub) {
        loadTimer = setTimeout(function () {
            Swal.fire({
                title: '분석 중…', html: sub || '',
                allowOutsideClick: false, showConfirmButton: false, didOpen: function () { Swal.showLoading(); }
            });
        }, 350);
    }
    // 뒤로가기(bfcache 복원) 시 되살아난 로딩 오버레이 제거 + 예약 타이머 취소
    function clearLoading() {
        if (loadTimer) { clearTimeout(loadTimer); loadTimer = null; }
        try { if (window.Swal && Swal.isVisible && Swal.isVisible()) Swal.close(); } catch (x) {}
        document.querySelectorAll('.swal2-container').forEach(function (el) { el.remove(); });
        document.documentElement.classList.remove('swal2-shown', 'swal2-height-auto');
        document.body.classList.remove('swal2-shown', 'swal2-height-auto');
    }
    window.addEventListener('pageshow', function (e) { if (e.persisted) clearLoading(); });
    window.addEventListener('pagehide', function () { if (loadTimer) { clearTimeout(loadTimer); loadTimer = null; } });
    const form = document.getElementById('kw-form');
    if (form) {
        form.addEventListener('submit', function () {
            const kw = (form.querySelector('input[name=keyword]').value || '').trim();
            if (!kw) return;
            delayedLoad('<span style="font-size:var(--fs-xs);color:var(--color-muted);">‘' + kw + '’ 의 검색량·성별/연령·트렌드를 조회하고 있습니다.</span>');
        });
    }
    // 최근 검색 · 연관 · 자동완성 클릭 — 캐시되어 있으면 즉시 열림(오버레이 미표시)
    document.querySelectorAll('a[href*="keyword="]').forEach(function (el) {
        el.addEventListener('click', function () { delayedLoad(); });
    });
    // 결과 로드 시 완료/실패 토스트 — 저장된 분석 열람(스냅샷)은 토스트 없음(재수집 아님)
    @if ($vm && ($vm['has_data'] ?? false) && ! ($fromCache ?? false))
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: @json(($vm['keyword'] ?? '').' 분석 완료'), showConfirmButton: false, timer: 1500, timerProgressBar: true });
    @elseif ($vm && ! ($vm['has_data'] ?? false))
        Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: '데이터를 조회하지 못했습니다', showConfirmButton: false, timer: 2000 });
    @endif
})();
</script>
@endsection
