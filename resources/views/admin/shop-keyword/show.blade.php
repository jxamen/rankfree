@extends('admin.layout')
@section('page-title', '쇼핑 노출 키워드 — '.$analysis->core_keyword)

@section('admin-content')
@php
    // 조합 재료 소스 + 참고(조합 X) 소스 라벨
    $srcLabel = [
        'title' => '제목 단어', 'title_phrase' => '제목 구절', 'seller_tag' => '상세 SEO태그', 'attribute' => '상품속성', 'suffix' => '어미',
        'autocomplete' => '자동완성', 'searchad' => '검색광고 추천', 'shopping_related' => '쇼핑 연관',
        'keyword_rec' => '쇼핑 키워드추천', 'together' => '함께 많이 찾는', 'competitor_brand' => '경쟁 브랜드',
    ];
    // 조합에 쓰는 소스 — 속성·어미는 tail(제목/브랜드 조합에 1개씩)로만 쓴다(단독 결합은 패턴 실측으로 제거)
    $comboSources = ['title', 'title_phrase', 'seller_tag', 'attribute', 'suffix'];
    $refSources = ['autocomplete', 'searchad', 'shopping_related', 'keyword_rec', 'together', 'competitor_brand'];
    // 조합 생성 유형(combo_tag) 라벨 — 패턴 카드(서버 렌더 + 실시간 갱신 공용)
    $tagLabel = ['title' => '핵심+제목 단어', 'phrase' => '제목 구절', 'tag' => 'SEO태그', 'brand' => '브랜드+제목',
        'brand_price' => '브랜드+가격', 'title_attr' => '핵심+제목+속성', 'title_suffix' => '핵심+제목+어미',
        'brand_attr' => '브랜드+제목+속성', 'brand_suffix' => '브랜드+제목+어미',
        'attr' => '브랜드+속성(구)', 'suffix' => '핵심+어미(구)', 'etc' => '기타(이전 조합)'];
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

{{-- 상단: 키워드 · 제품명 · 제품 URL — 제목/업체/가격은 확장 수집·검색결과 매칭으로 늦게 채워질 수 있어 항상 표시 --}}
<div class="card p-4 mb-4">
    {{-- 항목 간 여백은 명시적 gap 으로(유틸 클래스 미적용 환경에서 붙어 보이는 문제) --}}
    <div class="flex flex-wrap" style="font-size:var(--fs-sm);column-gap:32px;row-gap:6px;">
        <div><span class="text-muted" style="font-size:var(--fs-xs);">핵심 키워드</span><br><b class="text-ink">{{ $analysis->core_keyword }}</b></div>
        <div><span class="text-muted" style="font-size:var(--fs-xs);">업체명</span><br><b class="text-ink" id="sk-me-mall">{{ $analysis->mall_name ?: '—' }}</b></div>
        <div><span class="text-muted" style="font-size:var(--fs-xs);">가격</span><br><b class="text-ink font-mono" id="sk-me-price">{{ $analysis->product_price ? number_format($analysis->product_price).'원' : '—' }}</b></div>
    </div>
    <div class="mt-2"><span class="text-muted" style="font-size:var(--fs-xs);">제품명</span>
        <span class="text-ink" id="sk-me-title" style="font-size:var(--fs-sm);">{{ $analysis->product_title ?: '—' }}</span></div>
    @if ($analysis->product_url)
        <div class="mt-1"><span class="text-muted" style="font-size:var(--fs-xs);">제품 URL</span>
            <a href="{{ \Illuminate\Support\Str::startsWith($analysis->product_url, ['http://','https://']) ? $analysis->product_url : '#' }}" target="_blank" rel="noopener nofollow" class="text-muted-soft" style="font-size:var(--fs-xs);word-break:break-all;">{{ $analysis->product_url }}</a></div>
    @endif
</div>

<div class="flex items-center gap-2 mb-4 flex-wrap">
    <a href="{{ route('admin.shop-keyword') }}" class="btn btn-ghost btn-sm">← 목록</a>
    <button type="button" id="sk-regen" class="btn btn-primary btn-sm" data-url="{{ route('admin.shop-keyword.regenerate', $analysis) }}"
        title="노출 안 된 조합을 접고 새 조합을 생성합니다(노출 키워드는 유지)">＋ 새로 조합</button>
    <button type="button" id="sk-recheck" class="btn btn-secondary btn-sm" data-url="{{ route('admin.shop-keyword.recheck-exposed', $analysis) }}"
        title="노출 판정된 조합만 미확인으로 되돌려 다시 확인합니다 — 광고(슈퍼적립) 오판 정정용">노출 재확인</button>
    <form method="POST" action="{{ route('admin.shop-keyword.destroy', $analysis) }}" style="margin-left:auto;">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-ghost btn-sm text-error" data-confirm="이 분석을 삭제할까요?">삭제</button>
    </form>
</div>

@php
    $remaining = $combos->whereNull('rank')->count();
    // 중단은 서버에 저장된다(status=paused) — 새로고침해도 자동 재시작하지 않고 "이어서 확인"으로만 재개
    $isPaused = $analysis->status === 'paused';
@endphp
@if ($remaining > 0)
    <div id="sk-prog" class="card p-4 mb-4" data-url="{{ route('admin.shop-keyword.check', $analysis) }}">
        <div class="flex items-center justify-between mb-2">
            <span id="sk-prog-label" class="text-ink" style="font-size:var(--fs-sm);">
                @if ($isPaused)순위 확인 중단됨 — {{ $analysis->checked_count }}/{{ $analysis->combo_count }} · 상위 노출 {{ $analysis->exposed_count }} · "이어서 확인"으로 재개합니다
                @else순위 확인 중… {{ $analysis->checked_count }}/{{ $analysis->combo_count }} · 상위 {{ $th }}위 노출 {{ $analysis->exposed_count }}@endif
            </span>
            <div class="flex items-center gap-2">
                <button type="button" id="sk-stop" class="btn btn-ghost btn-sm {{ $isPaused ? 'hidden' : '' }}">중단</button>
                <a id="sk-captcha" class="btn btn-secondary btn-sm hidden" target="_blank" rel="noopener nofollow"
                    href="https://m.search.naver.com/search.naver?where=m&query={{ urlencode($analysis->core_keyword) }}">보안문자 풀기 ↗</a>
                <button type="button" id="sk-resume" class="btn btn-secondary btn-sm {{ $isPaused ? '' : 'hidden' }}">이어서 확인</button>
            </div>
        </div>
        <div style="height:8px;background:var(--color-surface-soft);border-radius:var(--radius-pill);overflow:hidden;">
            <div id="sk-prog-fill" style="height:100%;background:var(--color-primary);border-radius:var(--radius-pill);transition:width .3s;width:{{ $analysis->combo_count ? round($analysis->checked_count / $analysis->combo_count * 100) : 0 }}%;"></div>
        </div>
    </div>
@endif

{{-- 요약 --}}
<div class="card p-5 mb-4 flex flex-wrap gap-6">
    <div><div class="text-muted" style="font-size:var(--fs-xs);">상위 {{ $th }}위 노출</div><div class="font-mono text-ink" id="sk-sum-exposed" style="font-size:var(--fs-xl);">{{ $analysis->exposed_count }}</div></div>
    <div><div class="text-muted" style="font-size:var(--fs-xs);">순위 확인 조합</div><div class="font-mono text-ink" id="sk-sum-checked" style="font-size:var(--fs-xl);">{{ $analysis->checked_count }}</div></div>
    <div><div class="text-muted" style="font-size:var(--fs-xs);">추출 키워드</div><div class="font-mono text-ink" style="font-size:var(--fs-xl);">{{ $analysis->token_count }}</div></div>
    @if ($analysis->status === 'blocked')
        <div class="text-error" style="font-size:var(--fs-xs);align-self:center;">일부 조합이 미확인 상태입니다 — 랭크프리 확장이 설치돼 있으면 브라우저에서 자동으로 이어서 확인합니다.</div>
    @endif
</div>

@php
    // 노출 키워드는 발견 순서(No)로 번호를 매기고 최근 발견이 위(내림차순)로 오게 정렬한다
    $exposed = $combos->filter(fn ($c) => $c->rank !== null && $c->rank >= 1 && $c->rank <= $th)
        ->sortBy(fn ($c) => [($c->checked_at?->getTimestamp() ?? 0), $c->id])->values();
    $adKeywords = $combos->filter(fn ($c) => $c->ad_exposed)->values();
    $shortLinks = $shortLinks ?? collect();
    $shortLinksLocked = $shortLinks->contains(fn ($link) => (int) $link->hit_count > 0);
@endphp

{{-- 노출 키워드(핵심 결과) --}}
<div class="card p-5 mb-4">
    <div class="flex items-center justify-between mb-1">
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">상위 {{ $th }}위 노출 키워드</div>
        <div class="flex items-center gap-2">
            @if ($shortLinks->isNotEmpty())
                <form method="POST" action="{{ route('admin.shop-keyword.short-links.reassign', $analysis) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm" @disabled($exposed->isEmpty())>Short URL 재배정</button>
                </form>
            @endif
            <button type="button" class="btn btn-ghost btn-sm sk-copy {{ $exposed->isEmpty() ? 'hidden' : '' }}" data-copy="exposed">전체 복사</button>
        </div>
    </div>
    <div class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">이 검색어들에서 내 상품이 강합니다 — 순위 확인 중 발견되면 실시간으로 추가됩니다.</div>
    {{-- padding-right: 스크롤바가 행 우측 ✕ 버튼을 덮지 않게 --}}
    <div style="max-height:300px;overflow-y:auto;padding-right:14px;">
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr style="border-bottom:1px solid var(--color-hairline);">
                <th class="text-left text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;width:44px;">No</th>
                <th class="text-left text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">키워드</th>
                <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">노출 순위</th>
                <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;"></th>
            </tr></thead>
            <tbody id="sk-exposed-body">
            @if ($exposed->isEmpty())
                <tr id="sk-exposed-empty"><td colspan="4" class="py-3 text-muted-soft" style="font-size:var(--fs-xs);">아직 상위 {{ $th }}위 노출 조합이 없습니다 — 확인이 진행되면 여기 바로 표시됩니다.</td></tr>
            @endif
            @foreach ($exposed->reverse()->values() as $idx => $c)
                @php $no = $exposed->count() - $idx; [$rt, $rc] = $rankCell($c->rank); @endphp
                <tr style="border-bottom:1px solid var(--color-hairline-soft);" data-item="{{ $c->id }}">
                    <td class="py-2 text-muted font-mono" style="font-size:var(--fs-xs);">{{ $no }}</td>
                    <td class="py-2 text-ink" style="font-size:var(--fs-sm);">{{ $c->keyword }}
                        @if ($c->ad_exposed)<span class="badge" style="font-size:var(--fs-xs);color:var(--color-muted);" title="이 키워드에서 내 상품이 광고로도 노출 중">광고</span>@endif
                    </td>
                    <td class="py-2 text-right {{ $rc }} font-mono" style="font-size:var(--fs-sm);">{{ $rt }}</td>
                    <td class="py-2 text-right" style="white-space:nowrap;">
                        <a href="{{ $shopUrl($c->keyword) }}" target="_blank" rel="noopener nofollow" class="text-muted-soft" style="font-size:var(--fs-xs);">검색 ↗</a>
                        <button type="button" class="sk-del" data-item="{{ $c->id }}" data-kind="combo" title="삭제" style="margin-left:8px;">✕</button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Short URL — 상위 노출 키워드를 그룹으로 나눠 순차 출력 --}}
<div class="card p-5 mb-4">
    <div class="flex items-center justify-between mb-1 flex-wrap" style="gap:10px;">
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">Short URL 자동 출력</div>
        <form method="POST" action="{{ route('admin.shop-keyword.short-links.store', $analysis) }}" class="flex items-center gap-2">
            @csrf
            <input type="number" name="group_count" min="1" max="{{ max(1, $exposed->count()) }}" value="{{ old('group_count', min(10, max(1, $exposed->count()))) }}" class="input text-right" style="width:86px;height:34px;font-size:var(--fs-xs);">
            <button type="submit" class="btn btn-secondary btn-sm" @disabled($exposed->isEmpty() || $shortLinksLocked)>{{ $shortLinks->isEmpty() ? '생성' : '다시 생성' }}</button>
        </form>
    </div>
    <div class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">
        상위 {{ $th }}위 노출 키워드를 Short URL마다 하나씩 돌아가며 배정합니다. 링크를 열면 자기 그룹 안에서 순서대로 <code>query=노출키워드&amp;acq=참고키워드</code>를 싣고 이동합니다.
    </div>
    @error('group_count')
        <div class="text-error mb-3" style="font-size:var(--fs-xs);">{{ $message }}</div>
    @enderror
    @error('short_links')
        <div class="text-error mb-3" style="font-size:var(--fs-xs);">{{ $message }}</div>
    @enderror
    @if ($shortLinksLocked)
        <div class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">호출된 Short URL은 다시 생성하지 않고, 기존 URL을 유지한 채 재배정합니다.</div>
    @endif

    @if ($shortLinks->isNotEmpty())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead><tr style="border-bottom:1px solid var(--color-hairline);">
                    <th class="text-left text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;width:80px;">그룹</th>
                    <th class="text-left text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">Short URL</th>
                    <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;width:92px;">키워드</th>
                    <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;width:92px;">호출</th>
                    <th class="text-left text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;padding-left:20px;">배정 키워드</th>
                </tr></thead>
                <tbody>
                @foreach ($shortLinks as $link)
                    @php
                        $shortUrl = $link->domain
                            ? 'https://'.$link->domain.'/s/'.$link->token
                            : route('shop-keyword.short', $link->token);
                        $assigned = collect((array) $link->keywords)->filter()->values();
                    @endphp
                    <tr style="border-bottom:1px solid var(--color-hairline-soft);">
                        <td class="py-2 text-muted font-mono" style="font-size:var(--fs-xs);">{{ $link->group_no }}/{{ $link->group_count }}</td>
                        <td class="py-2" style="font-size:var(--fs-xs);min-width:240px;">
                            <a href="{{ $shortUrl }}" target="_blank" rel="noopener nofollow" class="text-ink">{{ $shortUrl }}</a>
                            <button type="button" class="sk-short-copy text-muted-soft" data-url="{{ $shortUrl }}" title="복사" style="border:0;background:none;cursor:pointer;margin-left:6px;">복사</button>
                        </td>
                        <td class="py-2 text-right text-muted font-mono" style="font-size:var(--fs-sm);">{{ number_format($assigned->count()) }}</td>
                        <td class="py-2 text-right text-muted font-mono" style="font-size:var(--fs-sm);">{{ number_format($link->hit_count) }}</td>
                        <td class="py-2" style="font-size:var(--fs-xs);min-width:260px;padding-left:20px;">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($assigned->take(8) as $kw)
                                    <span class="badge" style="font-size:var(--fs-xs);">{{ $kw }}</span>
                                @endforeach
                                @if ($assigned->count() > 8)
                                    <span class="text-muted-soft">+{{ $assigned->count() - 8 }}</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-muted-soft" style="font-size:var(--fs-xs);">아직 생성된 Short URL이 없습니다.</div>
    @endif
</div>

{{-- 광고 노출 키워드 — 내 상품이 광고 슬롯으로 노출 중인 검색어(오가닉 순위와 별개) --}}
<div class="card p-5 mb-4">
    <div class="flex items-center justify-between mb-1">
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">광고 노출 키워드 <span class="text-muted-soft font-normal" id="sk-ad-count">({{ $adKeywords->count() }})</span></div>
        <button type="button" class="btn btn-ghost btn-sm sk-copy {{ $adKeywords->isEmpty() ? 'hidden' : '' }}" data-copy="ad">전체 복사</button>
    </div>
    <div class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">이 검색어들에서 내 상품이 <b>광고(쇼핑검색광고·슈퍼적립)로 노출 중</b>입니다. 순위 숫자는 광고를 제외한 오가닉 위치 — 확인 중 발견되면 실시간으로 추가됩니다.</div>
    <div class="flex flex-wrap gap-2" id="sk-ad-list">
        @if ($adKeywords->isEmpty())
            <span class="text-muted-soft" id="sk-ad-empty" style="font-size:var(--fs-xs);">아직 광고로 노출 중인 조합이 없습니다.</span>
        @endif
        @foreach ($adKeywords as $c)
            @php [$rt, $rc] = $rankCell($c->rank); @endphp
            <span class="badge sk-badge" data-item="{{ $c->id }}" style="font-size:var(--fs-xs);display:inline-flex;align-items:center;gap:5px;">
                <a href="{{ $shopUrl($c->keyword) }}" target="_blank" rel="noopener nofollow" class="text-ink" style="text-decoration:none;">{{ $c->keyword }}</a>
                <span class="{{ $rc }} font-mono">{{ $rt }}</span>
            </span>
        @endforeach
    </div>
</div>

{{-- 전체 조합 순위 (클릭=모바일 검색 열기, ✕=조합 삭제) — 상태 필터 + 500px 스크롤, 확인 결과 실시간 갱신 --}}
@php
    $cntChecked = $combos->whereNotNull('rank')->count();
    $cntOut = $combos->filter(fn ($c) => $c->rank !== null && $c->rank <= 0)->count();
    $cntRanked = $combos->filter(fn ($c) => $c->rank !== null && $c->rank > $th)->count();
    $cntUnchecked = $combos->whereNull('rank')->count();
@endphp
<div class="card p-5 mb-4">
    <div class="text-ink font-semibold mb-2" style="font-size:var(--fs-sm);">전체 조합 순위 <span class="text-muted-soft font-normal">({{ $combos->count() }})</span>
        <span class="text-muted-soft font-normal" style="font-size:var(--fs-xs);">· 조합 클릭 시 네이버 모바일 검색이 열립니다</span>
    </div>
    <div class="flex flex-wrap gap-1 mb-3">
        <button type="button" class="sk-chip active" data-filter="all">전체 <span class="sk-chip-n font-mono">{{ $combos->count() }}</span></button>
        <button type="button" class="sk-chip" data-filter="checked">확인됨 <span class="sk-chip-n font-mono">{{ $cntChecked }}</span></button>
        <button type="button" class="sk-chip" data-filter="exposed">노출 1~{{ $th }}위 <span class="sk-chip-n font-mono">{{ $exposed->count() }}</span></button>
        <button type="button" class="sk-chip" data-filter="ranked">{{ $th }}위 밖 <span class="sk-chip-n font-mono">{{ $cntRanked }}</span></button>
        <button type="button" class="sk-chip" data-filter="out">미노출 <span class="sk-chip-n font-mono">{{ $cntOut }}</span></button>
        <button type="button" class="sk-chip" data-filter="unchecked">미확인 <span class="sk-chip-n font-mono">{{ $cntUnchecked }}</span></button>
    </div>
    <div class="flex flex-wrap gap-2" id="sk-combos" style="max-height:500px;overflow-y:auto;align-content:flex-start;padding-right:14px;">
        @foreach ($combos as $c)
            @php
                [$rt, $rc] = $rankCell($c->rank);
                $state = $c->rank === null ? 'unchecked' : ($c->rank <= 0 ? 'out' : ($c->rank <= $th ? 'exposed' : 'ranked'));
            @endphp
            <span class="badge sk-badge" data-item="{{ $c->id }}" data-state="{{ $state }}" style="font-size:var(--fs-xs);display:inline-flex;align-items:center;gap:5px;">
                <a href="{{ $shopUrl($c->keyword) }}" target="_blank" rel="noopener nofollow" class="text-ink" style="text-decoration:none;">{{ $c->keyword }}</a>
                <span class="sk-rank {{ $rc }} font-mono">{{ $rt }}</span>
                @if ($c->ad_exposed)<span class="text-muted-soft sk-ad-flag" title="광고로도 노출">광고</span>@endif
                <button type="button" class="sk-del" data-item="{{ $c->id }}" data-kind="combo" title="이 조합 삭제">✕</button>
            </span>
        @endforeach
    </div>
</div>

{{-- 조합 패턴 — 확인 결과 보관분(감춘 조합 포함)으로 어떤 유형·단어수가 노출되는지 집계. 확인 중 실시간 갱신 --}}
<div class="card p-5 mb-4">
    <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">조합 패턴</div>
    <div class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">확인한 조합(이전 라운드 보관분 포함)을 유형·단어수별로 집계 — 12회 이상 확인했는데 노출률 10% 미만인 유형은 "새로 조합" 때 자동 제외됩니다.</div>
    <table style="width:100%;border-collapse:collapse;">
        <thead><tr style="border-bottom:1px solid var(--color-hairline);">
            <th class="text-left text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">유형</th>
            <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">단어수</th>
            <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">확인</th>
            <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">노출</th>
            <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">노출률</th>
            <th class="text-right text-muted py-2" style="font-size:var(--fs-xs);font-weight:600;">광고</th>
        </tr></thead>
        <tbody id="sk-pattern-body">
        @if (($patterns ?? collect())->isEmpty())
            <tr id="sk-pattern-empty"><td colspan="6" class="py-3 text-muted-soft" style="font-size:var(--fs-xs);">아직 확인된 조합이 없습니다 — 확인이 진행되면 여기 바로 집계됩니다.</td></tr>
        @endif
        @foreach ($patterns ?? [] as $p)
            <tr style="border-bottom:1px solid var(--color-hairline-soft);" data-key="{{ $p['tag'] }}|{{ $p['len'] }}">
                <td class="py-2 text-ink" style="font-size:var(--fs-sm);">{{ $tagLabel[$p['tag']] ?? $p['tag'] }}</td>
                <td class="py-2 text-right text-muted font-mono" style="font-size:var(--fs-sm);">{{ $p['len'] }}</td>
                <td class="py-2 text-right text-muted font-mono sk-pat-checked" style="font-size:var(--fs-sm);">{{ $p['checked'] }}</td>
                <td class="py-2 text-right font-mono sk-pat-exposed {{ $p['exposed'] > 0 ? 'text-success font-semibold' : 'text-muted-soft' }}" style="font-size:var(--fs-sm);">{{ $p['exposed'] }}</td>
                <td class="py-2 text-right font-mono sk-pat-rate {{ $p['exposed'] > 0 ? 'text-success' : 'text-muted-soft' }}" style="font-size:var(--fs-sm);">{{ $p['checked'] ? round($p['exposed'] / $p['checked'] * 100) : 0 }}%</td>
                <td class="py-2 text-right text-muted font-mono sk-pat-ad" style="font-size:var(--fs-sm);">{{ $p['ad'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
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

<style>
.sk-del{border:0;background:none;cursor:pointer;color:var(--color-muted-soft);line-height:1;padding:0 1px;}.sk-del:hover{color:var(--color-error);}
.sk-chip{display:inline-flex;align-items:center;gap:4px;border:1px solid var(--color-hairline);background:none;border-radius:var(--radius-pill);padding:3px 10px;font-size:var(--fs-xs);color:var(--color-muted);cursor:pointer;}
.sk-chip:hover{color:var(--color-ink);}
.sk-chip.active{border-color:var(--color-primary);color:var(--color-primary);font-weight:600;}
</style>

{{-- 전체 복사 데이터(노출/광고 키워드) + 화면단 체크 설정 --}}
<script type="application/json" id="sk-copy-data">{!! json_encode(['exposed' => $exposed->pluck('keyword')->values(), 'ad' => $adKeywords->pluck('keyword')->values()], JSON_UNESCAPED_UNICODE) !!}</script>
@php
    // 함께많이찾는·경쟁브랜드가 빈약하면(서버 fetch 부분 실패) 확장으로 보충 수집한다
    $needSupplement = ($tokens['together'] ?? collect())->count() < 8
        || ($tokens['competitor_brand'] ?? collect())->count() < 8
        || ($tokens['attribute'] ?? collect())->count() < 5;
@endphp
<script>
window.__SK = {
    csrf: '{{ csrf_token() }}',
    core: @json($analysis->core_keyword),
    th: {{ (int) $th }},
    tagLabels: @json($tagLabel),
    productUrl: @json((string) $analysis->product_url),
    needTitle: @json(! $analysis->product_title && (string) $analysis->product_id !== ''),
    needSupplement: @json($needSupplement),
    paused: @json($analysis->status === 'paused'),
    urls: {
        check: '{{ route('admin.shop-keyword.check', $analysis) }}',
        pause: '{{ route('admin.shop-keyword.pause', $analysis) }}',
        pending: '{{ route('admin.shop-keyword.pending', $analysis) }}',
        checkHtml: '{{ route('admin.shop-keyword.check-html', $analysis) }}',
        supplement: '{{ route('admin.shop-keyword.supplement', $analysis) }}',
        productInfo: '{{ route('admin.shop-keyword.product-info', $analysis) }}',
    },
};
</script>
<script>
(function () {
    const base = "{{ url('admin/shop-keyword/'.$analysis->id.'/item') }}";
    const csrf = '{{ csrf_token() }}';

    // 전체 복사(노출/광고 키워드) — 줄바꿈 구분. 실시간 확인 결과가 push 할 수 있게 전역 노출
    const copyData = JSON.parse(document.getElementById('sk-copy-data').textContent || '{}');
    window.__skCopyData = copyData;
    document.addEventListener('click', function (e) {
        const shortCopy = e.target.closest('.sk-short-copy');
        if (shortCopy) {
            const text = shortCopy.dataset.url || '';
            if (!text) return;
            const fallback = () => {
                const ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
            };
            (navigator.clipboard ? navigator.clipboard.writeText(text).catch(fallback) : Promise.resolve(fallback()))
                .then(() => { shortCopy.textContent = '복사됨'; setTimeout(() => { shortCopy.textContent = '복사'; }, 1200); });
            return;
        }

        const btn = e.target.closest('.sk-copy');
        if (!btn) return;
        const list = copyData[btn.dataset.copy] || [];
        if (!list.length) return;
        const text = list.join('\n');
        const fallback = () => {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        };
        (navigator.clipboard ? navigator.clipboard.writeText(text).catch(fallback) : Promise.resolve(fallback()))
            .then(() => { btn.textContent = '복사됨 ✓'; setTimeout(() => { btn.textContent = '전체 복사'; }, 1500); });
    });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.sk-del');
        if (!btn) return;
        btn.disabled = true;
        fetch(base + '/' + btn.dataset.item, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
            .then((r) => r.ok ? r.json() : Promise.reject())
            .then(() => { if (btn.dataset.kind === 'token') location.reload(); else { const el = btn.closest('.sk-badge') || btn.closest('tr'); if (el) el.remove(); } })
            .catch(() => { btn.disabled = false; alert('삭제에 실패했습니다.'); });
    });

    // 노출 재확인 — 노출 판정 조합만 미확인으로 되돌려 다시 확인(광고 오판 정정)
    const recheck = document.getElementById('sk-recheck');
    if (recheck) recheck.addEventListener('click', function () {
        recheck.disabled = true;
        recheck.textContent = '재확인 준비 중…';
        fetch(recheck.dataset.url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
            .then((r) => r.ok ? r.json() : Promise.reject())
            .then((d) => {
                if ((((d || {}).data || {}).reset || 0) === 0) {
                    alert('재확인할 노출 조합이 없습니다.');
                    recheck.disabled = false;
                    recheck.textContent = '노출 재확인';
                } else {
                    location.reload();
                }
            })
            .catch(() => { recheck.disabled = false; recheck.textContent = '노출 재확인'; alert('노출 재확인에 실패했습니다.'); });
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
    // 순위 확인 — 확장(브라우저)이 있으면 m.search 를 직접 가져와 서버에 판정만 맡긴다(IP 한도 없음).
    // 확장이 없으면 종전대로 서버 배치 폴링(한도에 걸리면 중단·재개).
    const cfg = window.__SK;
    const box = document.getElementById('sk-prog');
    const fill = document.getElementById('sk-prog-fill');
    const label = document.getElementById('sk-prog-label');
    const resume = document.getElementById('sk-resume');
    const stopBtn = document.getElementById('sk-stop');
    let stopped = !!cfg.paused;   // 서버에 저장된 중단 상태 — 새로고침해도 유지
    let errCount = 0;
    let extMode = false;
    let lastD = null;          // 마지막 진행상황(중단 라벨용)
    let sessionChecked = 0;    // 이 세션에서 확인한 수 — 많아지면 간격을 늘려 보안문자 유발 완화

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    // 확장 브릿지(content script)가 data-rf-ext 를 심을 때까지 잠깐 대기 — document_idle 타이밍 레이스 방지
    function waitExt(ms) {
        return new Promise((res) => {
            const t0 = Date.now();
            (function chk() {
                if (document.documentElement.getAttribute('data-rf-ext') === '1') return res(true);
                if (Date.now() - t0 > ms) return res(false);
                setTimeout(chk, 150);
            })();
        });
    }

    // 확장 브릿지 호출(window.postMessage 왕복) — 타임아웃 시 null
    function extCall(type, payload, timeoutMs) {
        return new Promise((resolve) => {
            const timer = setTimeout(() => { window.removeEventListener('message', on); resolve(null); }, timeoutMs || 30000);
            function on(e) {
                if (e.source !== window) return;
                const m = e.data;
                if (!m || m.source !== 'rankfree-ext' || m.type !== type + 'Result') return;
                clearTimeout(timer);
                window.removeEventListener('message', on);
                resolve(m);
            }
            window.addEventListener('message', on);
            window.postMessage(Object.assign({ source: 'rankfree-console', type: type }, payload || {}), '*');
        });
    }

    function halt(msg, showResume) {
        stopped = true;
        if (label) label.textContent = msg;
        if (stopBtn) stopBtn.classList.add('hidden');
        if (showResume && resume) resume.classList.remove('hidden');
    }
    function renderProgress(d) {
        if (d.total === undefined) return;
        lastD = d;
        if (fill && label) {
            const pct = d.total ? Math.round(d.checked / d.total * 100) : 100;
            fill.style.width = pct + '%';
            if (!stopped) label.textContent = (extMode ? '브라우저에서 순위 확인 중(확장) ' : '순위 확인 ') + d.checked + '/' + d.total + ' · 상위 노출 ' + d.exposed;
        }
        // 요약 숫자 실시간 갱신
        const se = document.getElementById('sk-sum-exposed');
        if (se) se.textContent = d.exposed;
        const sc = document.getElementById('sk-sum-checked');
        if (sc) sc.textContent = d.checked;
    }

    // ── 상태 필터 칩(전체 조합) ──
    let curFilter = 'all';
    const FILTER_STATES = { all: null, checked: ['exposed', 'ranked', 'out'], exposed: ['exposed'], ranked: ['ranked'], out: ['out'], unchecked: ['unchecked'] };
    function badgeVisible(b) {
        const allow = FILTER_STATES[curFilter] || null;
        b.style.display = (!allow || allow.indexOf(b.dataset.state) >= 0) ? 'inline-flex' : 'none';
    }
    document.addEventListener('click', function (e) {
        const chip = e.target.closest('.sk-chip');
        if (!chip) return;
        curFilter = chip.dataset.filter;
        document.querySelectorAll('.sk-chip').forEach((c) => c.classList.toggle('active', c === chip));
        document.querySelectorAll('#sk-combos .sk-badge').forEach(badgeVisible);
    });
    function bumpChip(filter, delta) {
        const el = document.querySelector('.sk-chip[data-filter="' + filter + '"] .sk-chip-n');
        if (el) el.textContent = Math.max(0, (parseInt(el.textContent, 10) || 0) + delta);
    }

    // ── 확인 결과 실시간 반영 — 조합 배지 갱신 + 노출 테이블·광고 섹션 자동 등록 ──
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const shopUrl = (kw) => 'https://m.search.naver.com/search.naver?where=m&query=' + encodeURIComponent(kw);
    const rankSpan = (rank) => rank <= 0
        ? '<span class="sk-rank text-muted-soft font-mono">미노출</span>'
        : '<span class="sk-rank ' + (rank <= cfg.th ? 'text-success font-semibold' : 'text-muted') + ' font-mono">' + rank + '위</span>';

    function applyItemResult(it, res) {
        const rank = Number(res.rank) || 0;
        const state = rank <= 0 ? 'out' : (rank <= cfg.th ? 'exposed' : 'ranked');
        const badge = document.querySelector('#sk-combos .sk-badge[data-item="' + it.id + '"]');
        if (badge && badge.dataset.state === 'unchecked') {
            bumpChip('unchecked', -1);
            bumpChip('checked', 1);
            bumpChip(state === 'exposed' ? 'exposed' : state === 'ranked' ? 'ranked' : 'out', 1);
        }
        if (badge) {
            badge.dataset.state = state;
            const r = badge.querySelector('.sk-rank');
            if (r) r.outerHTML = rankSpan(rank);
            if (res.ad && !badge.querySelector('.sk-ad-flag')) {
                const flag = document.createElement('span');
                flag.className = 'text-muted-soft sk-ad-flag';
                flag.title = '광고로도 노출';
                flag.textContent = '광고';
                badge.insertBefore(flag, badge.querySelector('.sk-del'));
            }
            badgeVisible(badge);
        }
        if (state === 'exposed') addExposedRow(it, rank, res);
        if (res.ad) addAdBadge(it, rank);
        updatePattern(it, res);
    }

    // 조합 패턴 카드 실시간 집계 — 유형(combo_tag)×단어수 행을 만들거나 증가시킨다(리로드 불필요)
    function updatePattern(it, res) {
        const body = document.getElementById('sk-pattern-body');
        if (!body) return;
        const tag = res.combo_tag || 'etc';
        const len = String(it.keyword || '').trim().split(/\s+/).length;
        const key = tag + '|' + len;
        let tr = body.querySelector('tr[data-key="' + key + '"]');
        if (!tr) {
            const empty = document.getElementById('sk-pattern-empty');
            if (empty) empty.remove();
            tr = document.createElement('tr');
            tr.style.borderBottom = '1px solid var(--color-hairline-soft)';
            tr.dataset.key = key;
            tr.innerHTML = '<td class="py-2 text-ink" style="font-size:var(--fs-sm);">' + esc((cfg.tagLabels || {})[tag] || tag) + '</td>'
                + '<td class="py-2 text-right text-muted font-mono" style="font-size:var(--fs-sm);">' + len + '</td>'
                + '<td class="py-2 text-right text-muted font-mono sk-pat-checked" style="font-size:var(--fs-sm);">0</td>'
                + '<td class="py-2 text-right font-mono sk-pat-exposed text-muted-soft" style="font-size:var(--fs-sm);">0</td>'
                + '<td class="py-2 text-right font-mono sk-pat-rate text-muted-soft" style="font-size:var(--fs-sm);">0%</td>'
                + '<td class="py-2 text-right text-muted font-mono sk-pat-ad" style="font-size:var(--fs-sm);">0</td>';
            body.insertBefore(tr, body.firstChild);   // 진행 중인 새 유형이 위로 오게
        }
        const num = (el) => parseInt(el.textContent, 10) || 0;
        const cEl = tr.querySelector('.sk-pat-checked');
        cEl.textContent = num(cEl) + 1;
        const rank = Number(res.rank) || 0;
        const eEl = tr.querySelector('.sk-pat-exposed');
        if (rank >= 1 && rank <= cfg.th) {
            eEl.textContent = num(eEl) + 1;
            eEl.classList.remove('text-muted-soft');
            eEl.classList.add('text-success', 'font-semibold');
        }
        if (res.ad) {
            const aEl = tr.querySelector('.sk-pat-ad');
            aEl.textContent = num(aEl) + 1;
        }
        const rEl = tr.querySelector('.sk-pat-rate');
        rEl.textContent = Math.round(num(eEl) / Math.max(1, num(cEl)) * 100) + '%';
        if (num(eEl) > 0) {
            rEl.classList.remove('text-muted-soft');
            rEl.classList.add('text-success');
        }
    }

    // 발견 순번(No) — 최근 발견이 맨 위(내림차순)로 프리펜드된다
    let exposedNo = document.querySelectorAll('#sk-exposed-body tr[data-item]').length;
    function addExposedRow(it, rank, res) {
        const body = document.getElementById('sk-exposed-body');
        if (!body || body.querySelector('tr[data-item="' + it.id + '"]')) return;
        const empty = document.getElementById('sk-exposed-empty');
        if (empty) empty.remove();
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid var(--color-hairline-soft)';
        tr.dataset.item = it.id;
        tr.innerHTML = '<td class="py-2 text-muted font-mono" style="font-size:var(--fs-xs);">' + (++exposedNo) + '</td>'
            + '<td class="py-2 text-ink" style="font-size:var(--fs-sm);">' + esc(it.keyword)
            + (res.ad ? ' <span class="badge" style="font-size:var(--fs-xs);color:var(--color-muted);" title="광고로도 노출 중">광고</span>' : '') + '</td>'
            + '<td class="py-2 text-right text-success font-semibold font-mono" style="font-size:var(--fs-sm);">' + rank + '위</td>'
            + '<td class="py-2 text-right" style="white-space:nowrap;"><a href="' + shopUrl(it.keyword) + '" target="_blank" rel="noopener nofollow" class="text-muted-soft" style="font-size:var(--fs-xs);">검색 ↗</a>'
            + ' <button type="button" class="sk-del" data-item="' + it.id + '" data-kind="combo" title="삭제" style="margin-left:8px;">✕</button></td>';
        body.insertBefore(tr, body.firstChild);
        const btn = document.querySelector('.sk-copy[data-copy=exposed]');
        if (btn) btn.classList.remove('hidden');
        if (window.__skCopyData) (window.__skCopyData.exposed = window.__skCopyData.exposed || []).push(it.keyword);
    }

    function addAdBadge(it, rank) {
        const list = document.getElementById('sk-ad-list');
        if (!list || list.querySelector('[data-item="' + it.id + '"]')) return;
        const empty = document.getElementById('sk-ad-empty');
        if (empty) empty.remove();
        const span = document.createElement('span');
        span.className = 'badge sk-badge';
        span.dataset.item = it.id;
        span.style.cssText = 'font-size:var(--fs-xs);display:inline-flex;align-items:center;gap:5px;';
        span.innerHTML = '<a href="' + shopUrl(it.keyword) + '" target="_blank" rel="noopener nofollow" class="text-ink" style="text-decoration:none;">' + esc(it.keyword) + '</a>' + rankSpan(rank);
        list.appendChild(span);
        const cnt = document.getElementById('sk-ad-count');
        if (cnt) cnt.textContent = '(' + ((parseInt((cnt.textContent || '').replace(/[()]/g, ''), 10) || 0) + 1) + ')';
        const btn = document.querySelector('.sk-copy[data-copy=ad]');
        if (btn) btn.classList.remove('hidden');
        if (window.__skCopyData) (window.__skCopyData.ad = window.__skCopyData.ad || []).push(it.keyword);
    }
    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {}),
        });
    }

    // ── 확장 모드: pending 배치 → 조합별 m.search fetch(확장) → 서버 판정 저장 ──
    async function runExtLoop() {
        while (!stopped) {
            let d;
            try {
                const r = await fetch(cfg.urls.pending, { headers: { Accept: 'application/json' } });
                if (r.status === 401 || r.status === 403 || r.status === 404 || r.status === 419) { halt('세션이 만료됐거나 접근할 수 없습니다 — 새로고침 해주세요', false); return; }
                if (!r.ok) throw new Error('HTTP ' + r.status);
                d = (await r.json()).data;
            } catch (e) {
                if (++errCount > 6) { halt('서버 연결 오류로 중단 — 새로고침 해주세요', false); return; }
                await sleep(Math.min(8000, 1000 * errCount));
                continue;
            }
            errCount = 0;
            renderProgress(d);
            if (!d.items || !d.items.length) { location.reload(); return; }

            for (const it of d.items) {
                if (stopped) return;
                const res = await extCall('fetchShopSerp', { keyword: it.keyword }, 30000);
                if (stopped) return;   // 중단 클릭 — 진행 중이던 건은 버리고 즉시 멈춤(재개 시 다시 확인)
                if (!res) { halt('확장이 응답하지 않습니다 — chrome://extensions 에서 확장 새로고침 후 "이어서 확인"', true); return; }
                if (!res.ok) {
                    // 보안문자 페이지든 403/429 차단이든 **보안문자를 풀어야** 이어진다(실측) — 같은 안내로 통일
                    if (res.captcha || res.status === 403 || res.status === 429) {
                        halt(res.captcha
                            ? '네이버 보안문자가 떴습니다 — "보안문자 풀기"로 새 탭에서 풀고, "이어서 확인"을 눌러주세요'
                            : '네이버가 접속을 차단했습니다(' + (res.status || '차단') + ') — "보안문자 풀기"에서 보안문자를 푼 뒤 "이어서 확인"을 눌러주세요', true);
                        const cap = document.getElementById('sk-captcha');
                        if (cap) cap.classList.remove('hidden');
                        return;
                    }
                    halt('네이버 응답 오류(' + (res.status || res.message || '통신') + ') — 잠시 후 "이어서 확인"을 눌러주세요', true);
                    return;
                }
                try {
                    const cr = await post(cfg.urls.checkHtml, { item_id: it.id, html: res.html || '' });
                    if (cr.status === 401 || cr.status === 403 || cr.status === 419) { halt('세션이 만료됐습니다 — 새로고침 해주세요', false); return; }
                    if (cr.ok) {
                        const p = await cr.json();
                        renderProgress(p);
                        if (p.rank !== undefined) applyItemResult(it, p);   // 배지·노출/광고·패턴 실시간 갱신
                    }
                } catch (e) { /* 저장 실패 — 다음 pending 배치에서 재시도 */ }
                // 차단 완화 페이싱 — 기본 간격 2배 감속(≈0.9~1.6s), 250건 이후 더 느리게(≈1.8~3s),
                // 60건마다 12~20초 휴식으로 규칙적 연속 요청 패턴을 끊는다.
                // (병렬은 같은 IP 라 분당 총 요청수가 그대로여서 차단 완화 효과가 없다 — 총량 감속 + 휴식이 유효)
                sessionChecked++;
                if (!stopped && sessionChecked % 60 === 0) {
                    if (label) label.textContent = '차단 방지 휴식 중… 잠시 후 자동 재개' + (lastD ? ` (${lastD.checked}/${lastD.total})` : '');
                    await sleep(12000 + Math.random() * 8000);
                }
                await sleep(sessionChecked > 250 ? 1800 + Math.random() * 1200 : 900 + Math.random() * 700);
            }
        }
    }

    // ── 폴백: 서버 배치 폴링(확장 미설치) ──
    function retry() {
        if (++errCount > 6) { halt('연결/서버 오류로 중단 — 새로고침 해주세요', false); return; }
        setTimeout(poll, Math.min(8000, 1000 * errCount)); // 지수 백오프
    }
    async function poll() {
        if (stopped) return;
        let r;
        try {
            r = await fetch(cfg.urls.check, { method: 'POST', headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' } });
        } catch (e) { retry(); return; }
        if (r.status === 419 || r.status === 401 || r.status === 403 || r.status === 404) {
            halt('세션이 만료됐거나 접근할 수 없습니다 — 새로고침 해주세요', false);
            return;
        }
        if (r.status === 429) { retry(); return; }
        if (!r.ok) { retry(); return; }
        let d;
        try { d = await r.json(); } catch (e) { retry(); return; }
        errCount = 0;
        renderProgress(d);
        if (d.remaining <= 0) { location.reload(); return; }
        if (d.blocked) {
            halt(`서버 확인이 잠시 제한됨(${d.checked}/${d.total}) — 랭크프리 확장을 설치하면 브라우저에서 끝까지 확인됩니다. "이어서 확인"으로 재시도`, true);
            return;
        }
        setTimeout(poll, 500);
    }

    // ── 보충(확장 전용, 순위 루프와 병행) — 제품명·SEO태그 + 함께많이찾는·경쟁브랜드·속성 ──
    async function enrich() {
        try {
            if (cfg.needTitle && cfg.productUrl) {
                const pi = await extCall('collectProductPage', { url: cfg.productUrl }, 40000);
                if (pi && pi.ok) {
                    const r = await post(cfg.urls.productInfo, { info: pi.info || null });
                    if (r.ok) {
                        const d = (await r.json()).data || {};
                        if (d.title) { const el = document.getElementById('sk-me-title'); if (el) el.textContent = d.title; }
                        if (d.mall) { const el = document.getElementById('sk-me-mall'); if (el) el.textContent = d.mall; }
                        if (d.price) { const el = document.getElementById('sk-me-price'); if (el) el.textContent = Number(d.price).toLocaleString() + '원'; }
                        // 새 재료로 조합이 재편성됐으면 reload — 제목 단어 중심 조합으로 이어서 확인
                        if (d.regenerated) { stopped = true; location.reload(); return; }
                    }
                }
            }
            if (cfg.needSupplement) {
                const sig = await extCall('fetchKeywordSignals', { keyword: cfg.core }, 40000);
                if (sig && sig.ok && (sig.mshop_html || (sig.related || []).length)) {
                    await post(cfg.urls.supplement, { mshop_html: sig.mshop_html || '', related: sig.related || [] });
                }
            }
        } catch (e) { /* 보충은 best-effort — 실패해도 순위 확인엔 지장 없음 */ }
    }

    // 중단/재개 — 서버에도 저장해 새로고침해도 중단 상태가 유지된다("이어서 확인"으로만 재개)
    if (stopBtn) stopBtn.addEventListener('click', function () {
        stopped = true;
        stopBtn.classList.add('hidden');
        if (resume) resume.classList.remove('hidden');
        if (label) label.textContent = '순위 확인 중단됨' + (lastD ? ` — ${lastD.checked}/${lastD.total} · 상위 노출 ${lastD.exposed}` : '') + ' · "이어서 확인"으로 재개합니다';
        post(cfg.urls.pause, { paused: true }).catch(() => {});
    });
    if (resume) resume.addEventListener('click', function () {
        stopped = false; errCount = 0; resume.classList.add('hidden');
        const cap = document.getElementById('sk-captcha');
        if (cap) cap.classList.add('hidden');
        if (stopBtn) stopBtn.classList.remove('hidden');
        if (label) label.textContent = '순위 확인 재개 중…';
        post(cfg.urls.pause, { paused: false }).catch(() => {});
        if (extMode) runExtLoop(); else poll();
    });

    (async function boot() {
        extMode = await waitExt(1500);
        if (extMode && !stopped) enrich();   // 중단 상태에선 보충 수집(조합 재편성→reload)도 하지 않는다
        if (!box) return;   // 미확인 조합 없음 — 보충만 수행
        if (stopped) return;   // 서버 저장된 중단 — "이어서 확인" 클릭 전까지 시작하지 않는다
        if (extMode) runExtLoop(); else poll();
    })();
})();
</script>

@endsection
