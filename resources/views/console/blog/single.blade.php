@extends('console.layout')
@section('page-title', '블로그 1개 분석')

@section('page-actions')
    @if ($result ?? false)
        <button type="button" id="bi-recollect" class="btn btn-secondary btn-sm" title="현재 블로그를 처음부터 새로 수집">↻ 재수집</button>
    @endif
@endsection

@section('console-content')
@php
    $gradeColor = fn ($g) => match ($g) {
        'S' => 'var(--color-success)', 'A' => 'var(--color-accent)', 'B' => 'var(--color-badge-violet)',
        'C' => 'var(--color-warning)', 'D' => 'var(--color-muted)', default => 'var(--color-muted)',
    };
@endphp

{{-- 분석 입력: 블로그 ID/URL 전용 --}}
<form method="GET" action="{{ route('console.blog-single') }}" class="flex items-center gap-2 mb-4" id="bi-form">
    <input type="text" name="q" value="{{ $q }}" placeholder="블로그 ID 또는 URL (예: today789, https://blog.naver.com/today789)"
           class="input" style="flex:1;height:44px;font-size:var(--fs-sm);" autofocus autocomplete="off">
    <button type="submit" class="btn btn-primary" style="height:44px;padding:0 22px;">분석</button>
</form>
<p class="text-muted-soft mb-5" style="font-size:var(--fs-xs);">
    블로그의 <b>이웃·방문·활동성</b>과 <b>게시물 품질(사진·본문·영상)</b>, <b>전문성(빈출 주제어)</b>을 종합해 지수화합니다.
    지수·등급은 관측 신호 기반 <b>자체 추정치</b>(네이버 공식 아님)입니다.
</p>

@if (! $result && $q === '')
    @if ($history->count())
        {{-- 최근 수집 내역 리스트 (클릭 시 /console/blog-index/{id}) --}}
        @include('console.blog._history_list', ['history' => $history])
    @else
        {{-- 최초 진입(내역 없음) 안내 --}}
        <div class="card p-8 text-center">
            <div style="font-size:var(--fs-2xl);opacity:.35;">📝</div>
            <p class="text-ink font-semibold mt-3" style="font-size:var(--fs-base);">블로그를 분석해 보세요</p>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);">블로그 ID나 주소를 입력하면 <b>지수·등급</b>과 <b>최근 글 품질</b>을 한 번에 확인합니다.</p>
        </div>
    @endif

@elseif (! $result)
    <div class="card-soft px-4 py-4 text-muted" style="font-size:var(--fs-xs);">
        「{{ $q }}」 블로그를 분석하지 못했습니다. 블로그 ID/URL을 확인하거나 잠시 후 다시 시도하세요.
    </div>

@else
    @include('console.blog._single', ['b' => $result['blog']])
@endif

<script>
(function () {
    // 지연 로딩 — 캐시/스냅샷으로 즉시 열리는 이동엔 오버레이 미표시(350ms 전 언로드). 느린 신규 수집만 표시.
    var loadTimer = null;
    function delayedLoad(title, sub) {
        loadTimer = setTimeout(function () {
            Swal.fire({ title: title, html: sub || '', allowOutsideClick: false, showConfirmButton: false, didOpen: function () { Swal.showLoading(); } });
        }, 350);
    }
    // 페이지 이탈(bfcache 진입) 시 예약 타이머 취소 — 뒤로가기 복원 시 오버레이 재등장 방지
    window.addEventListener('pagehide', function () { if (loadTimer) { clearTimeout(loadTimer); loadTimer = null; } });
    var form = document.getElementById('bi-form');
    if (form) {
        form.addEventListener('submit', function () {
            var q = (form.querySelector('input[name=q]').value || '').trim();
            if (!q) return;
            delayedLoad('블로그 분석 중…', '<span style="font-size:var(--fs-xs);color:var(--color-muted);">‘' + q + '’ 블로그의 최근 글 품질과 지수를 분석합니다.</span>');
        });
    }
    document.querySelectorAll('a[href*="/console/blog-index/"]').forEach(function (el) {
        if (/\/export(\?|$)/.test(el.getAttribute('href') || '')) return;
        el.addEventListener('click', function () { delayedLoad('불러오는 중…'); });
    });
    var recollect = document.getElementById('bi-recollect');
    if (recollect && form) {
        recollect.addEventListener('click', function () {
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
        });
    }
})();
</script>
@endsection
