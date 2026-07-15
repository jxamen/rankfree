@extends('console.layout')
@section('page-title', '자주 묻는 질문')

@section('console-content')
<div>
    <x-console.page-head title="자주 묻는 질문" desc="자주 묻는 질문 모음 · 궁금한 내용을 검색하거나 카테고리로 찾아보세요" />

    {{-- 검색(우) — 카드 --}}
    <form method="GET" action="{{ route('console.faq') }}" class="card p-3 mb-4">
        <div class="flex items-center gap-2">
            @if ($q !== '' || $cat)
                <a href="{{ route('console.faq') }}" class="btn btn-ghost btn-sm">초기화</a>
            @endif
            <input type="text" name="q" value="{{ $q }}" placeholder="궁금한 내용을 검색하세요 (예: 순위, 결제, API)"
                   class="input" style="width:260px;font-size:var(--fs-xs);margin-left:auto;" autocomplete="off">
        </div>
    </form>

    {{-- 카테고리 필터 --}}
    <div class="flex flex-wrap gap-2 mb-5">
        <a href="{{ route('console.faq', $q !== '' ? ['q' => $q] : []) }}"
           class="badge {{ ! $cat ? '' : '' }}" style="font-size:var(--fs-xs);padding:6px 13px;{{ ! $cat ? 'background:var(--color-ink);color:#fff;' : '' }}">전체</a>
        @foreach ($categories as $c)
            <a href="{{ route('console.faq', array_filter(['q' => $q ?: null, 'cat' => $c])) }}"
               class="badge" style="font-size:var(--fs-xs);padding:6px 13px;{{ $cat === $c ? 'background:var(--color-ink);color:#fff;' : '' }}">{{ $c }}</a>
        @endforeach
    </div>

    @if ($q !== '')
        <p class="text-muted mb-3" style="font-size:var(--fs-xs);">「<b class="text-ink">{{ $q }}</b>」 검색 결과 {{ count($faqs) }}건</p>
    @endif

    {{-- 목록 (아코디언) --}}
    <div class="card overflow-hidden">
        @forelse ($faqs as $faq)
            <div class="faq-item" style="border-top:{{ $loop->first ? '0' : '1px solid var(--color-hairline-soft)' }};">
                <button type="button" class="faq-q flex items-center gap-3 w-full text-left px-5" style="min-height:56px;background:none;border:0;cursor:pointer;">
                    <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;flex-shrink:0;">{{ $faq->category }}</span>
                    <span class="text-ink font-medium flex-1" style="font-size:var(--fs-sm);">{{ $faq->question }}</span>
                    <span class="faq-arrow text-muted-soft" style="flex-shrink:0;transition:transform .15s;">▾</span>
                </button>
                <div class="faq-a" style="display:none;padding:0 20px 20px 20px;">
                    <div class="card-soft p-4 text-body" style="font-size:var(--fs-sm);line-height:1.8;">{!! $faq->answer !!}</div>
                </div>
            </div>
        @empty
            <div class="text-center text-muted-soft" style="padding:64px 24px;font-size:var(--fs-sm);">
                @if ($q !== '')「{{ $q }}」에 대한 검색 결과가 없습니다. 다른 키워드로 검색하거나 <a href="{{ route('console.qna.create') }}" class="text-accent hover:underline">1:1 문의</a>를 남겨주세요.
                @else 등록된 FAQ가 없습니다.@endif
            </div>
        @endforelse
    </div>

    <div class="card-soft p-5 mt-4 text-center">
        <p class="text-muted" style="font-size:var(--fs-xs);">찾는 답변이 없으신가요?</p>
        <a href="{{ route('console.qna.create') }}" class="btn btn-primary btn-sm mt-3">1:1 문의하기</a>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('.faq-q').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var item = btn.closest('.faq-item');
            var ans = item.querySelector('.faq-a');
            var arrow = btn.querySelector('.faq-arrow');
            var open = ans.style.display === 'block';
            ans.style.display = open ? 'none' : 'block';
            arrow.style.transform = open ? '' : 'rotate(180deg)';
        });
    });
    // 검색 결과가 1건이면 자동 펼침
    @if ($q !== '' && count($faqs) <= 3)
        document.querySelectorAll('.faq-q').forEach(function (b) { b.click(); });
    @endif
})();
</script>
@endsection
