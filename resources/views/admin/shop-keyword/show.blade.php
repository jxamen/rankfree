@extends('admin.layout')
@section('page-title', '쇼핑 노출 키워드 — '.$analysis->core_keyword)

@section('admin-content')
<div class="flex flex-wrap items-center gap-3 mb-3">
<div>
@include('admin.shop-keyword.collection', ['a' => $analysis])
</div>
@if(auth()->user()->isAdmin())
<div class="flex flex-wrap items-center gap-2 mb-3">
    <label for="sk-interval">간격(초)</label>
    <input id="sk-interval" class="input" style="width:90px" type="number" min="1" max="30" step="0.1" required value="{{ number_format(\App\Services\ShopExposureBlockAlert::baseInterval() / 1000, 1, '.', '') }}">
    <label for="sk-parallel-tabs">동시 검색 탭 수</label>
    <input id="sk-parallel-tabs" class="input" style="width:75px" type="number" min="1" max="4" step="1" required value="{{ \App\Services\ShopExposureBlockAlert::parallelSettings()['parallel_tabs'] }}">
    <span id="sk-parallel-notice" role="status"></span>
    <label for="sk-tab-open-seconds">탭 여는 간격(초)</label>
    <input id="sk-tab-open-seconds" class="input" style="width:80px" type="number" min="0" max="60" step="0.1" required value="{{ number_format(\App\Services\ShopExposureBlockAlert::parallelSettings()['tab_open_interval_ms'] / 1000, 1, '.', '') }}">
    <label for="sk-keywords-per-day">하루 키워드 수</label>
    <input id="sk-keywords-per-day" class="input" style="width:80px" type="number" min="10" max="500" step="1" required value="{{ \App\Services\ShopExposureBlockAlert::keywordsPerDay() }}">
    <select id="sk-collection-mode" class="input" style="width:auto">
      <option value="tab" @selected(\App\Services\ShopExposureBlockAlert::collectionMode() === 'tab')>브라우저 탭으로 열기</option>
      <option value="fetch" @selected(\App\Services\ShopExposureBlockAlert::collectionMode() === 'fetch')>탭 없이 확장이 통합검색 바로 분석</option>
    </select>
    <button id="sk-interval-save" type="button" disabled class="btn btn-secondary btn-sm">저장</button>
    <button id="sk-interval-reset" type="button" disabled class="btn btn-ghost btn-sm">간격 초기화</button>
    <span id="sk-interval-notice" role="status"></span>
</div>
@endif
</div>
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
    $shortUrlOf = fn ($link) => $link->domain ? 'https://'.$link->domain.'/s/'.$link->token : route('shop-keyword.short', $link->token);
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
        <span class="text-ink" id="sk-me-title" style="font-size:var(--fs-sm);">{{ $analysis->product_title ?: '—' }}</span>
        @if ($analysis->product_title)
            <button type="button" class="sk-short-copy text-muted-soft" data-url="{{ $analysis->product_title }}" style="border:0;background:none;cursor:pointer;font-size:var(--fs-xs);">복사</button>
        @endif</div>
    @if ($analysis->product_url)
        <div class="mt-1"><span class="text-muted" style="font-size:var(--fs-xs);">제품 URL</span>
            <a href="{{ \Illuminate\Support\Str::startsWith($analysis->product_url, ['http://','https://']) ? $analysis->product_url : '#' }}" target="_blank" rel="noopener nofollow" class="text-muted-soft" style="font-size:var(--fs-xs);word-break:break-all;">{{ $analysis->product_url }}</a>
            <button type="button" class="sk-short-copy text-muted-soft" data-url="{{ $analysis->product_url }}" style="border:0;background:none;cursor:pointer;font-size:var(--fs-xs);">복사</button></div>
    @endif
    {{-- 대표이미지(썸네일) — 발주 시 필요. 확장 상품페이지 수집으로 채워진다(2026-07-22) --}}
    {{-- 한 줄 고정 — URL 이 길어도 줄바꿈하지 않고 가운데 링크만 말줄임(2026-07-25) --}}
    <div class="mt-1 flex items-center gap-2" style="flex-wrap:nowrap;">
        <span class="text-muted flex-none" style="font-size:var(--fs-xs);">대표이미지</span>
        @if ($thumbnailUrl ?? null)
            <img src="{{ $thumbnailUrl }}" alt="대표이미지" class="flex-none" style="width:36px;height:36px;object-fit:cover;border-radius:6px;border:1px solid var(--color-hairline);">
            <a href="{{ $thumbnailUrl }}" target="_blank" rel="noopener nofollow" class="text-muted-soft" title="{{ $thumbnailUrl }}"
               style="font-size:var(--fs-xs);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $thumbnailUrl }}</a>
            <button type="button" class="sk-short-copy text-muted-soft flex-none" data-url="{{ $thumbnailUrl }}" style="border:0;background:none;cursor:pointer;font-size:var(--fs-xs);">복사</button>
        @else
            <span class="text-muted-soft flex-none" style="font-size:var(--fs-xs);">— 확장이 상품페이지를 수집하면 채워집니다</span>
        @endif
    </div>
    {{-- 관련 태그(스마트스토어 상품페이지 하단) — 확장 수집분. 조합 재료(seller_tag)로도 쓰인다(2026-07-22) --}}
    <div class="mt-1 flex items-center gap-2 flex-wrap">
        <span class="text-muted" style="font-size:var(--fs-xs);">관련 태그</span>
        @if (! empty($sellerTags ?? []))
            <div class="flex items-center gap-1 flex-wrap">
                @foreach ($sellerTags as $t)
                    <span class="badge border border-hairline" style="font-size:var(--fs-xs);padding:1px 8px;">#{{ $t }}</span>
                @endforeach
            </div>
            {{-- 태그 복사는 콤마 구분(발주·시스템 입력용, # 없이) --}}
            <button type="button" class="sk-short-copy text-muted-soft" data-url="{{ collect($sellerTags)->join(',') }}" style="border:0;background:none;cursor:pointer;">복사</button>
        @else
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">— 확장이 상품페이지를 수집하면 채워집니다</span>
        @endif
    </div>
    @if ($analysis->order)
        <div class="mt-1"><span class="text-muted" style="font-size:var(--fs-xs);">연결 주문</span>
            <a href="{{ route('admin.orders.show', $analysis->order) }}" class="text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $analysis->order->order_no }} ↗</a>
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">— 발주 시 이 분석의 Short URL 사용</span></div>
    @endif
</div>

<div class="flex items-center gap-2 mb-4 flex-wrap">
    <a href="{{ route('admin.shop-keyword') }}" class="btn btn-ghost btn-sm">← 목록</a>
    <button type="button" id="sk-regen" class="btn btn-primary btn-sm" data-url="{{ route('admin.shop-keyword.regenerate', $analysis) }}"
        title="노출 안 된 조합을 접고 새 조합을 생성합니다(노출 키워드는 유지)">＋ 새로 조합</button>
    <button type="button" id="sk-recheck" class="btn btn-secondary btn-sm" data-url="{{ route('admin.shop-keyword.recheck-exposed', $analysis) }}"
        title="기존 결과는 보존하고 미확인·수집 실패 항목만 이어서 확인합니다">노출 재확인</button>
    {{-- 상품정보 재수집(2026-07-22) — 자동 수집이 빠졌거나 실패했을 때 확장으로 상품페이지 다시 수집 --}}
    <button type="button" id="sk-recollect" class="btn btn-secondary btn-sm"
        title="확장으로 상품페이지(제목·관련태그·대표이미지·가격)를 다시 수집합니다">상품정보 다시 수집</button>
    <span id="sk-product-status" role="status" aria-live="polite">{{ \Illuminate\Support\Facades\Cache::get('shop-product-status:'.$analysis->id, [])['message'] ?? '' }}</span>
    {{-- data-confirm 은 form 에 — app.js 전역 핸들러(SweetAlert2)가 form 속성만 읽는다 --}}
    <form method="POST" action="{{ route('admin.shop-keyword.destroy', $analysis) }}" style="margin-left:auto;"
          data-confirm="'{{ $analysis->core_keyword }}' 분석을 삭제할까요?" data-confirm-text="조합·확인 결과·단축 URL이 함께 삭제되며 되돌릴 수 없습니다.">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-ghost btn-sm text-error">삭제</button>
    </form>
</div>

@php
    $remaining = $analysis->status === 'ad_ended' || $analysis->targetReached() ? 0 : $combos->whereNull('rank')->count();
    // 중단은 서버에 저장된다(status=paused) — 새로고침해도 자동 재시작하지 않고 "이어서 확인"으로만 재개
    $isPaused = $analysis->status === 'paused';
@endphp
@if ($remaining > 0)
    <div id="sk-prog" class="card p-4 mb-4" data-url="{{ route('admin.shop-keyword.check', $analysis) }}">
        <div class="flex items-center justify-between mb-2">
            <span id="sk-prog-label" class="text-ink" style="font-size:var(--fs-sm);">
                @if ($isPaused && \Illuminate\Support\Facades\Cache::get('shop-exposure:tab-closed:'.$analysis->id))수집 탭을 닫아 멈췄어요 — 이어서를 누르면 다시 시작
                @elseif ($isPaused)순위 확인 중단됨 — {{ $analysis->checked_count }}/{{ $analysis->combo_count }} · 상위 노출 {{ $analysis->exposed_count }} · "이어서"로 재개합니다
                @else순위 확인 중… {{ $analysis->checked_count }}/{{ $analysis->combo_count }} · 상위 {{ $th }}위 노출 {{ $analysis->exposed_count }}@endif
            </span>
            <div class="flex items-center gap-2">
                <button type="button" id="sk-stop" class="btn btn-ghost btn-sm">{{ $isPaused ? '이어서' : '중단' }}</button>
            </div>
        </div>
        <div style="height:8px;background:var(--color-surface-soft);border-radius:var(--radius-pill);overflow:hidden;">
            <div id="sk-prog-fill" style="height:100%;background:var(--color-primary);border-radius:var(--radius-pill);transition:width .3s;width:{{ $analysis->combo_count ? round($analysis->checked_count / $analysis->combo_count * 100) : 0 }}%;"></div>
        </div>
    </div>
@endif

<p id="sk-search-judged" class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">검색 1번 = 조합 1개 판정 · 서버 배치는 여러 검색 결과를 한 번에 갱신합니다.</p>
{{-- 요약 --}}
<div class="card p-5 mb-4 flex flex-wrap gap-6">
    <div><div class="text-muted" style="font-size:var(--fs-xs);">상위 {{ $th }}위 노출</div><div class="font-mono text-ink" id="sk-sum-exposed" style="font-size:var(--fs-xl);">{{ $analysis->exposed_count }}</div></div>
    <div><div class="text-muted" style="font-size:var(--fs-xs);">순위 확인 조합</div><div class="font-mono text-ink" id="sk-sum-checked" style="font-size:var(--fs-xl);">{{ $analysis->checked_count }}</div></div>
    <div><div class="text-muted" style="font-size:var(--fs-xs);">추출 키워드</div><div class="font-mono text-ink" style="font-size:var(--fs-xl);">{{ $analysis->token_count }}</div></div>
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
            <button type="button" class="btn btn-primary btn-sm sk-copy {{ $exposed->isEmpty() ? 'hidden' : '' }}" data-copy="exposed">전체 복사 ↵</button>
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
                    <td class="py-2 text-right {{ $rc }} font-mono" style="font-size:var(--fs-sm);">{{ $rt }} @if ($c->result_source)<span class="text-muted-soft">· {{ $c->result_source === 'integrated' ? '통합' : '쇼핑' }}</span>@endif</td>
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
        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('admin.shop-keyword.short-links.store', $analysis) }}" class="flex items-center gap-2">
                @csrf
                <input type="number" name="group_count" min="1" max="{{ max(1, $exposed->count()) }}" value="{{ old('group_count', min(10, max(1, $exposed->count()))) }}" class="input text-right" style="width:86px;height:34px;font-size:var(--fs-xs);">
                <button type="submit" class="btn btn-secondary btn-sm" @disabled($exposed->isEmpty())>{{ $shortLinks->isEmpty() ? '생성' : '다시 생성' }}</button>
            </form>
            <button type="button" class="btn btn-primary btn-sm sk-copy {{ $shortLinks->isEmpty() ? 'hidden' : '' }}" data-copy="short">전체 복사 ↵</button>
        </div>
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
        <div class="text-muted-soft mb-3" style="font-size:var(--fs-xs);">호출된 Short URL은 주소를 그대로 두고, 다시 생성하면 모자란 개수만 새로 만들어 키워드를 다시 나눕니다.</div>
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
                        $shortUrl = $shortUrlOf($link);
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
        <button type="button" class="btn btn-primary btn-sm sk-copy {{ $adKeywords->isEmpty() ? 'hidden' : '' }}" data-copy="ad">전체 복사 ↵</button>
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
                <span class="{{ $rc }} font-mono">{{ $rt }}@if ($c->result_source) · {{ $c->result_source === 'integrated' ? '통합' : '쇼핑' }}@endif</span>
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
                <span class="sk-rank {{ $rc }} font-mono">{{ $rt }}@if ($c->result_source) · {{ $c->result_source === 'integrated' ? '통합' : '쇼핑' }}@endif</span>
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

<dialog id="sk-captcha-modal" aria-labelledby="sk-captcha-title" style="position:fixed;inset:0;margin:auto;box-sizing:border-box;max-height:calc(100% - 32px);overflow:auto;width:min(520px,calc(100% - 32px));border:0;border-radius:16px;padding:32px;box-shadow:0 24px 80px #0005;">
    <h2 id="sk-captcha-title" style="font-size:24px;font-weight:700;margin:0 0 16px;">네이버 보안문자가 떴어요</h2>
    <p>수집 탭에서 보안문자를 풀어 주세요. 풀면 자동으로 이어집니다.</p>
    <p style="margin:16px 0;overflow-wrap:anywhere;">지금 키워드: <strong id="sk-captcha-keyword"></strong></p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" id="sk-captcha-open">수집 탭 열기</button>
        <button type="button" class="btn" id="sk-captcha-dismiss">닫기</button>
        <button type="button" class="btn" id="sk-captcha-stop" hidden>탭 닫고 멈추기</button>
    </div>
    <p id="sk-captcha-feedback" role="status"></p>
</dialog>
<style>#sk-captcha-modal::backdrop{background:rgba(0,0,0,.5)}</style>

<style>
.sk-del{border:0;background:none;cursor:pointer;color:var(--color-muted-soft);line-height:1;padding:0 1px;}.sk-del:hover{color:var(--color-error);}
.sk-chip{display:inline-flex;align-items:center;gap:4px;border:1px solid var(--color-hairline);background:none;border-radius:var(--radius-pill);padding:3px 10px;font-size:var(--fs-xs);color:var(--color-muted);cursor:pointer;}
.sk-chip:hover{color:var(--color-ink);}
.sk-chip.active{border-color:var(--color-primary);color:var(--color-primary);font-weight:600;}
</style>

{{-- 전체 복사 데이터(노출/광고 키워드) + 화면단 체크 설정 --}}
<script type="application/json" id="sk-copy-data">{!! json_encode(['exposed' => $exposed->pluck('keyword')->values(), 'ad' => $adKeywords->pluck('keyword')->values(), 'short' => $shortLinks->map($shortUrlOf)->values()], JSON_UNESCAPED_UNICODE) !!}</script>
@php
    // 함께많이찾는·경쟁브랜드가 빈약하면(서버 fetch 부분 실패) 확장으로 보충 수집한다
    $needSupplement = ($tokens['together'] ?? collect())->count() < 8
        || ($tokens['competitor_brand'] ?? collect())->count() < 8
        || ($tokens['attribute'] ?? collect())->count() < 5;
@endphp
<script>
window.__SK = {
    csrf: '{{ csrf_token() }}',
    analysisId: {{ (int) $analysis->id }},
    core: @json($analysis->core_keyword),
    th: {{ (int) $th }},
    tagLabels: @json($tagLabel),
    productUrl: @json((string) $analysis->product_url),
    needTitle: @json(! $analysis->product_title && (string) $analysis->product_id !== ''),
    needSupplement: @json($needSupplement),
    paused: @json($analysis->collectionStopped()),
    ended: @json($analysis->status === 'ad_ended'),
    method: @json($analysis->check_method ?? 'api'),   // api=서버 slot API 폴링(상위 20위) | search=확장 통합검색 크롤링

    urls: {
        check: '{{ route('admin.shop-keyword.check', $analysis) }}',
        pause: '{{ route('admin.shop-keyword.pause', $analysis) }}',
        pending: '{{ route('admin.shop-keyword.pending', $analysis) }}',
        checkHtml: '{{ route('admin.shop-keyword.check-html', $analysis) }}',
        collectorEvent: '{{ route('admin.shop-keyword.collector-event', $analysis) }}',
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
        const label = btn.textContent;
        (navigator.clipboard ? navigator.clipboard.writeText(text).catch(fallback) : Promise.resolve(fallback()))
            .then(() => { btn.textContent = '복사됨 ✓'; setTimeout(() => { btn.textContent = label; }, 1500); });
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

    // 노출 재확인 — 기존 결과를 보존하고 미확인·수집 실패 항목만 재개
    const recheck = document.getElementById('sk-recheck');
    if (recheck) recheck.addEventListener('click', function () {
        recheck.disabled = true;
        recheck.textContent = '재확인 준비 중…';
        fetch(recheck.dataset.url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
            .then((r) => r.ok ? r.json() : Promise.reject())
            .then((d) => {
                const data = (d || {}).data || {};
                if ((data.remaining || 0) === 0 || data.status === 'target_reached') {
                    alert(data.status === 'target_reached' ? '목표에 도달했습니다. 기존 수집 결과를 유지합니다.' : '미확인·수집 실패 항목이 없습니다. 기존 수집 결과를 유지합니다.');
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
    const resume = null;
    const stopBtn = document.getElementById('sk-stop');
    let stopped = !!cfg.paused;   // 서버에 저장된 중단 상태 — 새로고침해도 유지
    let errCount = 0;
    let extMode = false;
    let lastD = null;          // 마지막 진행상황(중단 라벨용)
    // 전역 수집 잠금의 주인 ID — 페이지 로드마다 새로. 다른 곳이 수집 중이면 서버가 423 → 15초마다 줄 서서 재시도
    let RUNNER = Math.random().toString(36).slice(2) + Date.now().toString(36);
    async function waitBusy(res) {
        let msg = '다른 수집이 진행 중이에요 — 끝나면 자동으로 이어서 시작합니다';
        try { const d = await res.json(); msg = d.message || msg; if (d.taken_over) { halt(msg, true); return; } } catch (e) {}
        if (label) label.textContent = msg;
        await sleep(15000);
    }

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

    // Captcha modal controller: one notification per uninterrupted wait episode.
    const captchaModal = document.getElementById('sk-captcha-modal');
    const captchaWaits = new Map();
    let captchaDismissed = false;
    const captchaOpen = document.getElementById('sk-captcha-open');
    const captchaStop = document.getElementById('sk-captcha-stop');
    const captchaFeedback = document.getElementById('sk-captcha-feedback');
    function captchaState(key, keyword, waiting) {
        if (waiting) captchaWaits.set(key, keyword || cfg.core);
        else captchaWaits.delete(key);
        if (!captchaWaits.size) {
            captchaModal.close(); captchaDismissed = false;
            captchaStop.hidden = true; captchaFeedback.textContent = '';
            return;
        }
        document.getElementById('sk-captcha-keyword').textContent = [...captchaWaits.values()].join(', ');
        if (!captchaDismissed && !captchaModal.open) captchaModal.showModal();
    }
    document.getElementById('sk-captcha-dismiss').onclick = () => { captchaDismissed = true; captchaModal.close(); };
    captchaModal.addEventListener('cancel', () => { captchaDismissed = true; });
    captchaOpen.onclick = async () => {
        captchaOpen.disabled = true;
        try {
            const r = await extCall('controlShopSerpTab', {analysisId:cfg.analysisId, action:'focus'}, 5000);
            if (!r?.ok && captchaWaits.size) {
                captchaStop.hidden = false;
                captchaFeedback.textContent = '수집 탭을 열지 못했어요. 탭을 닫고 분석을 멈출 수 있어요.';
            }
        } finally { captchaOpen.disabled = false; }
    };
    captchaStop.onclick = async () => {
        captchaStop.disabled = true;
        halt('수집 탭을 닫아 멈췄어요 — 이어서를 누르면 다시 시작', false);
        try {
            const r = await post(cfg.urls.pause, {paused:true});
            if (!r.ok) throw new Error('중단 저장에 실패했어요. 다시 시도해 주세요.');
            const closed = await extCall('controlShopSerpTab', {analysisId:cfg.analysisId, action:'close'}, 10000);
            if (!closed?.ok) throw new Error('분석은 멈췄어요. 수집 탭은 직접 닫아 주세요.');
            captchaWaits.clear(); captchaState('server', '', false);
        } catch (e) { captchaFeedback.textContent = e.message; if (!captchaModal.open) captchaModal.showModal(); }
        finally { captchaStop.disabled = false; }
    };
    // End captcha modal controller.

    // 확장 브릿지 호출(window.postMessage 왕복) — 타임아웃 시 null
    function extCall(type, payload, timeoutMs) {
        const requestId = crypto.randomUUID();
        return new Promise((resolve) => {
            const timer = setTimeout(() => { window.removeEventListener('message', on); resolve(null); }, timeoutMs || 30000);
            function on(e) {
                if (e.source !== window) return;
                const m = e.data;
                if (!m || m.source !== 'rankfree-ext' || m.type !== type + 'Result') return;
                if (m.requestId !== requestId && (m.requestId || Number(payload?.parallel_tabs) > 1)) return;
                clearTimeout(timer);
                window.removeEventListener('message', on);
                resolve(m);
            }
            window.addEventListener('message', on);
            window.postMessage(Object.assign({ source: 'rankfree-console', type: type, requestId }, payload || {}), '*');
        });
    }

    function halt(msg, showResume) {
        stopped = true;
        captchaModal.close();
        if (label) label.textContent = msg;
        if (stopBtn) { stopBtn.classList.remove('hidden'); stopBtn.textContent = '이어서'; }
        if (showResume && resume) resume.classList.remove('hidden');
    }
    function renderProgress(d) {
        if (d.status === 'paused') halt(d.tab_closed ? '수집 탭을 닫아 멈췄어요 — 이어서를 누르면 다시 시작' : '멈춤 — 이어서를 누르면 다시 시작', false);
        const notice = document.getElementById('sk-collection-error');
        if (d.status && !['blocked', 'error'].includes(d.status) && !d.blocked) {
            if (notice) notice.remove();
            const captcha = document.getElementById('sk-captcha');
            if (captcha) captcha.classList.add('hidden');
            if (!stopped && label) label.textContent = '진행 중 — 순위 확인 중…';
        }
        if (stopped) { captchaWaits.clear(); captchaState('server', '', false); }
        else if (d.blocked || d.status === 'blocked') captchaState('server', d.current_keyword || d.keyword || d.items?.[0]?.keyword, true);
        else captchaState('server', '', false);
        if (d.total === undefined) return;
        lastD = d;
        if (Array.isArray(d.exposed_items)) { syncExposed(d.exposed_items); d.exposed = d.exposed_items.length; }
        const judged = document.getElementById('sk-search-judged');
        if (judged && d.judged_count) judged.textContent = '검색 ' + d.judged_count + '번 = 조합 ' + d.judged_count + '개 판정' + (d.judged_count > 1 ? ' (배치 갱신)' : '');
        if (fill && label) {
            const pct = d.total ? Math.round(d.checked / d.total * 100) : 100;
            fill.style.width = pct + '%';
            if (!stopped) label.textContent = (extMode && cfg.method !== 'api' ? '진행 중 — 브라우저에서 순위 확인 중(확장) ' : (cfg.method === 'api' ? '순위 확인 중(쇼핑 API) ' : '순위 확인 ')) + d.checked + '/' + d.total + ' · 상위 노출 ' + d.exposed;
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
    const sourceLabel = (source) => source === 'integrated' ? ' · 통합' : source === 'shopping' ? ' · 쇼핑' : '';
    const rankSpan = (rank, source) => rank <= 0
        ? '<span class="sk-rank text-muted-soft font-mono">미노출' + sourceLabel(source) + '</span>'
        : '<span class="sk-rank ' + (rank <= cfg.th ? 'text-success font-semibold' : 'text-muted') + ' font-mono">' + rank + '위' + sourceLabel(source) + '</span>';

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
            if (r) r.outerHTML = rankSpan(rank, res.result_source);
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
        if (res.ad) addAdBadge(it, rank, res.result_source);
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
    function syncExposed(items) {
        const body = document.getElementById('sk-exposed-body');
        if (!body) return;
        body.querySelectorAll('tr[data-item]').forEach(row => row.remove());
        exposedNo = 0;
        items.forEach(it => addExposedRow(it, Number(it.rank), it));
        // Keep discovery numbers and the summary aligned after reconnects or other runners.
        items.slice().reverse().forEach((it, idx) => {
            const row = body.querySelector('tr[data-item="' + it.id + '"]');
            if (row) { row.firstElementChild.textContent = items.length - idx; body.appendChild(row); }
        });
        exposedNo = items.length;
        if (window.__skCopyData) window.__skCopyData.exposed = items.map(it => it.keyword);
    }
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
            + '<td class="py-2 text-right text-success font-semibold font-mono" style="font-size:var(--fs-sm);">' + rank + '위' + (res.result_source ? ' · ' + (res.result_source === 'integrated' ? '통합' : '쇼핑') : '') + '</td>'
            + '<td class="py-2 text-right" style="white-space:nowrap;"><a href="' + shopUrl(it.keyword) + '" target="_blank" rel="noopener nofollow" class="text-muted-soft" style="font-size:var(--fs-xs);">검색 ↗</a>'
            + ' <button type="button" class="sk-del" data-item="' + it.id + '" data-kind="combo" title="삭제" style="margin-left:8px;">✕</button></td>';
        body.insertBefore(tr, body.firstChild);
        const btn = document.querySelector('.sk-copy[data-copy=exposed]');
        if (btn) btn.classList.remove('hidden');
        if (window.__skCopyData) (window.__skCopyData.exposed = window.__skCopyData.exposed || []).push(it.keyword);
    }

    function addAdBadge(it, rank, source) {
        const list = document.getElementById('sk-ad-list');
        if (!list || list.querySelector('[data-item="' + it.id + '"]')) return;
        const empty = document.getElementById('sk-ad-empty');
        if (empty) empty.remove();
        const span = document.createElement('span');
        span.className = 'badge sk-badge';
        span.dataset.item = it.id;
        span.style.cssText = 'font-size:var(--fs-xs);display:inline-flex;align-items:center;gap:5px;';
        span.innerHTML = '<a href="' + shopUrl(it.keyword) + '" target="_blank" rel="noopener nofollow" class="text-ink" style="text-decoration:none;">' + esc(it.keyword) + '</a>' + rankSpan(rank, source);
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

    let currentIntervalMs = {{ \App\Services\ShopExposureBlockAlert::baseInterval() }};
    let collectorIp = {{ \Illuminate\Support\Js::from(request()->ip()) }};
    const intervalLabel = () => ' · IP ' + collectorIp + ' · 간격 ' + (currentIntervalMs / 1000).toFixed(1) + '초 (최대 30초)';
    // Browser collection preferences: server values seed this browser only once.
    let browserSettings;
    let settingsInExtension = false;
    async function loadBrowserSettings() {
        const defaults = {
            interval_ms: currentIntervalMs,
            parallel_tabs: {{ \App\Services\ShopExposureBlockAlert::parallelSettings()['parallel_tabs'] }},
            tab_open_interval_ms: {{ \App\Services\ShopExposureBlockAlert::parallelSettings()['tab_open_interval_ms'] }},
            collection_mode: {{ \Illuminate\Support\Js::from(\App\Services\ShopExposureBlockAlert::collectionMode()) }}
        };
        const r = extMode ? await extCall('getShopSettings', {}, 5000) : null;
        if (extMode && !r?.ok) throw new Error('확장을 업데이트하고 새로고침해 주세요.');
        settingsInExtension = !!r?.ok;
        browserSettings = r?.settings || JSON.parse(localStorage.getItem('rfShopSettings') || 'null') || defaults;
        await persistBrowserSettings(browserSettings);
        showBrowserSettings();
        for (const id of ['sk-interval-save','sk-interval-reset']) { const button = document.getElementById(id); if (button) button.disabled = false; }
    }
    async function persistBrowserSettings(value, manual = false) {
        if (settingsInExtension) {
            const r = await extCall('setShopSettings', {settings:value, analysisId:cfg.analysisId, manual}, 5000);
            if (!r?.ok) throw new Error('이 브라우저에 설정을 저장하지 못했습니다.');
        } else {
            if (manual) { const r = await post(cfg.urls.collectorEvent, {kind:'parallel_reset'}); if (!r.ok) throw new Error('설정 적용에 실패했습니다.'); }
            localStorage.setItem('rfShopSettings', JSON.stringify(value));
        }
        browserSettings = value;
        currentIntervalMs = value.interval_ms;
        parallelTabs = value.parallel_tabs;
    }
    function showBrowserSettings() {
        for (const [id,value] of Object.entries({'sk-interval':browserSettings.interval_ms/1000,'sk-parallel-tabs':browserSettings.parallel_tabs,'sk-tab-open-seconds':browserSettings.tab_open_interval_ms/1000,'sk-collection-mode':browserSettings.collection_mode})) {
            const input = document.getElementById(id); if (input) input.value = value;
        }
    }
    function applyBrowserSettings(d) {
        if (!browserSettings) return d;
        Object.assign(d, browserSettings, {parallel_tabs:d.parallel_reduced ? 1 : browserSettings.parallel_tabs, interval_revision:'browser:' + browserSettings.interval_ms});
        parallelTabs = d.parallel_tabs;
        const notice = document.getElementById('sk-parallel-notice');
        if (notice) notice.textContent = d.parallel_reduced ? '이 분석은 차단으로 1개로 진행 중' : '';
        return d;
    }
    @if(auth()->user()->isAdmin())
    const intervalInput = document.getElementById('sk-interval');
    const notice = document.getElementById('sk-interval-notice');
    document.getElementById('sk-interval-save').onclick = async function () {
        if (![intervalInput, ...['sk-parallel-tabs','sk-tab-open-seconds','sk-keywords-per-day'].map(id => document.getElementById(id))].every(input => input.reportValidity())) return;
        this.disabled = true;
        try {
            await persistBrowserSettings({interval_ms:Number(intervalInput.value)*1000,parallel_tabs:Number(document.getElementById('sk-parallel-tabs').value),tab_open_interval_ms:Number(document.getElementById('sk-tab-open-seconds').value)*1000,collection_mode:document.getElementById('sk-collection-mode').value}, true);
            const r = await post('{{ route('admin.shop-keyword.interval', $analysis) }}', {seconds:intervalInput.value, keywords_per_day:document.getElementById('sk-keywords-per-day').value});
            if (!r.ok) throw new Error('주문 목표 저장에 실패했습니다.');
            notice.textContent = '저장했습니다 — 다음 키워드부터 적용됩니다.';
        } catch (e) { notice.textContent = e.message; }
        finally { this.disabled = false; }
    };
    document.getElementById('sk-interval-reset').onclick = async function () {
        try { await persistBrowserSettings({...browserSettings,interval_ms:4000}); showBrowserSettings(); notice.textContent = '이 브라우저 간격 초기화됨'; }
        catch(e) { notice.textContent = e.message; }
    };
    @endif

    async function waitRestriction(until, source) {
        while (!stopped) {
            const left = Math.max(0, until - Date.now());
            if (!left) break;
            if (label) label.textContent = source + ' 확인 제한 — ' + Math.ceil(left / 1000) + '초 남음' + intervalLabel();
            await sleep(Math.min(1000, left));
        }
        if (!stopped && label) label.textContent = '순위 다시 확인 중…';
    }

    // ── 확장 모드: pending 배치 → 조합별 m.search fetch(확장) → 서버 판정 저장 ──
    const failedItems = new Map();
    async function reportClientError(stage, error, keyword = '') {
        try { await post(cfg.urls.collectorEvent, {kind:'client_error', stage, keyword: String(keyword).slice(0,300), message: String(error?.message || error).slice(0,1000)}); } catch (_) {}
    }

    let parallelTabs = 4;
    function pendingUrl(waiting = false, policyOnly = false) {
        const url = new URL(cfg.urls.pending, location.href);
        url.searchParams.set('runner', RUNNER);
        url.searchParams.set('limit', parallelTabs);
        if (policyOnly) url.searchParams.set('policy_only', '1');
        if (waiting) url.searchParams.set('waiting', '1');
        for (const id of failedItems.keys()) url.searchParams.append('exclude[]', id);
        return url;
    }
    async function runExtLoop() {
        if (loopActive) return;
        loopActive = true;
        try { await collectLoop(); } catch (e) { await reportClientError('screen_loop', e); } finally { loopActive = false; }
    }
    async function collectLoop() {
        while (!stopped) {
            let d;
            try {
                const r = await fetch(pendingUrl(), { headers: { Accept: 'application/json' } });
                if (r.status === 409) { stopped = true; location.reload(); return; }
                if (r.status === 423) { await waitBusy(r); continue; }
                if (r.status === 401 || r.status === 403 || r.status === 404 || r.status === 419) { halt('세션이 만료됐거나 접근할 수 없습니다 — 새로고침 해주세요', false); return; }
                if (!r.ok) throw new Error('HTTP ' + r.status);
                d = applyBrowserSettings((await r.json()).data);
            } catch (e) {
                await reportClientError('pending', e);
                // 연결 오류로 멈추지 않는다 — 서버 재시작·잠깐 끊김에도 계속 다시 시도(대표님 10/18 22:06 「서버 오류로 멈춘건」)
                ++errCount;
                await sleep(Math.min(30000, 1000 * errCount));
                continue;
            }
            if (stopped) return;
            errCount = 0;
            renderProgress(d);
            if (stopped) return;
            if (d.parallel_reduced) {
                parallelTabs = 1;
                const parallelNotice = document.getElementById('sk-parallel-notice');
                if (parallelNotice) parallelNotice.textContent = '이 분석은 차단으로 1개로 진행 중';
            }
            currentIntervalMs = d.interval_ms || currentIntervalMs;
            if (label) label.textContent += intervalLabel();
            if (!d.items || !d.items.length) { if (d.remaining > 0 && !failedItems.size) { await sleep(1000); continue; } if (failedItems.size) { halt('멈춤 — 나머지 확인 완료 · 읽기 실패 ' + failedItems.size + '개 · ' + [...failedItems.values()].at(-1) + ' (이어서로 재시도)', true); return; } location.reload(); return; }

            const processItem = async it => {
                if (stopped) return;
                let res;
                while (!stopped) {
                    // Refresh pacing before each request, including retries of the same keyword.
                    try {
                        const policyResponse = await fetch(pendingUrl(!!res && (res.cooldown || res.captcha || [403, 429].includes(res.status)), true), { headers: { Accept: 'application/json' } });
                        if (policyResponse.status === 423) { await waitBusy(policyResponse); break; }
                        if (!policyResponse.ok) { halt('수집 정책을 읽지 못했습니다', true); return; }
                        const fresh = applyBrowserSettings((await policyResponse.json()).data);
                        renderProgress(fresh); if (stopped) return; d.parallel_revision = fresh.parallel_revision; d.collection_mode = fresh.collection_mode; d.interval_ms = fresh.interval_ms; d.interval_revision = fresh.interval_revision; d.parallel_tabs = fresh.parallel_tabs; d.tab_open_interval_ms = fresh.tab_open_interval_ms;
                    } catch (_) { halt('간격 정책을 읽지 못했습니다 — 새로고침해 주세요', false); return; }
                    if (label) label.textContent += ' · 지금: ' + it.keyword + ' · 통합검색';
                    res = await extCall('fetchShopSerp', { keyword: it.keyword, target: d.target, collection_mode: d.collection_mode, collectorIp: d.collector_ip, interval_ms: d.interval_ms, interval_revision: d.interval_revision, analysisId: {{ (int) $analysis->id }}, parallel_revision: d.parallel_revision, parallel_tabs: d.parallel_tabs, tab_open_interval_ms: d.tab_open_interval_ms }, 330000);
                    if (res?.tab_closed) {
                        captchaState(it.id, '', false);
                        if (res.paused) {
                            stopped = true;
                            if (stopBtn) { stopBtn.textContent = '이어서'; stopBtn.disabled = false; }
                            if (label) label.textContent = '수집 탭을 닫아 멈췄어요 — 이어서를 누르면 다시 시작';
                            await post(cfg.urls.collectorEvent, {kind:'tab_closed'}).catch(() => {});
                        }
                        return;
                    }
                    if (res?.parallel_reduced) {
                        parallelTabs = 1;
                        const parallelNotice = document.getElementById('sk-parallel-notice');
                        if (parallelNotice) parallelNotice.textContent = '이 분석은 차단으로 1개로 진행 중';
                    }
                    if (res && res.interval_ms) currentIntervalMs = res.interval_ms;
                    // Successful cache results also release stale waits; cooldown replies do not.
                    if (res && (!res.cached || res.ok) && !res.cooldown) {
                        const kind = res.captcha ? '보안문자' : [403, 429].includes(res.status) ? String(res.status) : res.ok ? 'success' : null;
                        if (kind) {
                            try {
                                await post(cfg.urls.collectorEvent, { kind, blocked_on_resume: !!res.blockedOnResume, interval_ms: res.interval_ms });
                            }
                            catch (e) { /* collection can continue if event delivery fails */ }
                        }
                    }
                    if (stopped) return;
                    if (!res) res = {ok:false, message:'확장 응답 시간 초과'};
                    if (res.ok && !res.cooldown) captchaState('server', '', false);
                    captchaState(it.id, it.keyword, !!(res.cooldown || res.captcha || res.status === 403 || res.status === 429));
                    if (res.cooldown || res.captcha || res.status === 403 || res.status === 429) {
                        if (label) label.textContent = d.collection_mode === 'fetch' ? '보안문자 대기 — 브라우저 탭으로 열기로 저장하고 이어서 확인해 사람이 풀어 주세요.' : (res.message || '보안문자 — 수집 탭에서 풀어 주세요 · 지금: ' + it.keyword);
                        await sleep(1500);
                        continue; // Inspect the same tab until the person clears the gate.
                    }
                    break;
                }
                if (stopped) return;
                if (!res) return;
                if (!res.ok) {
                    await reportClientError('read', res.message || String(res.status), it.keyword);
                    failedItems.set(it.id, res.message || String(res.status));
                    const badge = document.querySelector?.('#sk-combos .sk-badge[data-item="' + it.id + '"] .sk-rank');
                    if (badge) { badge.textContent = '못 읽음'; badge.title = res.message || String(res.status); }
                    if (label) label.textContent = '못 읽음 — ' + it.keyword + ' · ' + (res.message || res.status) + ' · 다음 키워드로 이동';
                    return;
                }
                if (label) label.textContent = '진행 중 — ' + it.keyword + intervalLabel() + (failedItems.size ? ' · 읽기 실패 ' + failedItems.size + '개' : '');
                const oldNotice = document.getElementById('sk-collection-error');
                if (oldNotice) oldNotice.remove();
                try {
                    const cr = await post(cfg.urls.checkHtml, { item_id: it.id, html: res.html || '', result_source: res.result_source, runner: RUNNER, claim_token: it.claim_token });
                    if (cr.status === 409) { stopped = true; location.reload(); return; }
                    if (cr.status === 423) { await waitBusy(cr); return; }   // 잠금을 잃음(다른 곳이 시작) — pending 에서 줄 선다
                    if (cr.status === 401 || cr.status === 403 || cr.status === 419) { halt('세션이 만료됐습니다 — 새로고침 해주세요', false); return; }
                    if (cr.ok) {
                        const p = await cr.json();
                        renderProgress(p);
                        if (label) label.textContent += intervalLabel();
                        if (p.rank !== undefined && !p.skipped) { applyItemResult(it, p); const lastResult = document.getElementById('sk-last-result'); if (lastResult) lastResult.textContent = '직전: ' + it.keyword + ' · ' + (res.result_source === 'integrated' ? '통합검색' : '쇼핑') + ' → ' + (p.rank > 0 ? p.rank + '위' : '미노출'); if (label) label.textContent += ' · 직전: ' + it.keyword + ' · ' + (res.result_source === 'integrated' ? '통합검색' : '쇼핑') + ' → ' + (p.rank > 0 ? p.rank + '위' : '미노출'); }
                        if (['target_reached', 'ad_ended'].includes(p.status)) { stopped = true; return; }
                    }
                } catch (e) { await reportClientError('save', e, it.keyword); }
                // 요청 간격과 실제 차단 후 대기는 확장의 공용 정책에서만 적용한다.
            };
            const heartbeat = typeof setInterval === 'function' ? setInterval(() => {
                if (!stopped) fetch(pendingUrl(false, true), {headers:{Accept:'application/json'}}).catch(() => {});
            }, 30000) : null;
            try {
                if (Number(d.parallel_tabs) > 1) await Promise.all(d.items.map(processItem));
                else for (const it of d.items) { await processItem(it); if (stopped) break; }
            } finally { if (heartbeat !== null) clearInterval(heartbeat); }

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
            r = await fetch(cfg.urls.check, { method: 'POST', headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ runner: RUNNER }) });
        } catch (e) { retry(); return; }
        if (r.status === 409) { stopped = true; location.reload(); return; }
        if (r.status === 423) { await waitBusy(r); if (!stopped) poll(); return; }
        if (r.status === 419 || r.status === 401 || r.status === 403 || r.status === 404) {
            halt('세션이 만료됐거나 접근할 수 없습니다 — 새로고침 해주세요', false);
            return;
        }
        if (r.status === 429) { retry(); return; }
        if (!r.ok) { retry(); return; }
        let d;
        try { d = await r.json(); } catch (e) { retry(); return; }
        errCount = 0;
        // 서버 배치의 건별 결과를 확장 모드와 동일하게 실시간 반영 — 요약 숫자만 늘고
        // 노출 테이블은 새로고침해야 보이던 불일치(305 vs 293) 해소
        (d.items || []).forEach(function (x) { applyItemResult({ id: x.id, keyword: x.keyword }, x); });
        collectorIp = d.collector_ip || collectorIp;
        renderProgress(d);
        currentIntervalMs = d.interval_ms || currentIntervalMs;
        if (label) label.textContent += intervalLabel();
        if (d.remaining <= 0) { location.reload(); return; }
        if (d.blocked) {
            if (d.retry_at) {
                await waitRestriction(d.retry_at, '서버');
                if (!stopped) poll();
                return;
            }
            // 서버(slot API)가 막히면 확장이 있을 때 브라우저 m.search 경로로 이어서 확인(2026-09-28 — slot API 가 빈 {} 응답)
            if (extMode) { runExtLoop(); return; }
            halt(`서버 확인 실패: ${d.error || '사유 미확인'} (${d.checked}/${d.total}) — 이어서 확인으로 재시도`, true);
            return;
        }
        setTimeout(poll, 0);
    }

    // ── 보충(확장 전용, 순위 루프와 병행) — 제품명·SEO태그 + 함께많이찾는·경쟁브랜드·속성 ──
    async function enrich() {
        try {
            if (cfg.needTitle && cfg.productUrl) {
                const pi = await extCall('collectProductPage', { url: cfg.productUrl }, 40000);
                if (!pi?.ok) { await reportProduct(pi?.message || '상품정보 응답 시간 초과 — 자동 재시도'); productRetry = setTimeout(recollectProduct, 60000); }
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
    let loopActive = false;
    if (stopBtn) stopBtn.addEventListener('click', async function () {
        if (!stopped) {
            stopped = true;
            captchaWaits.clear(); captchaState('server', '', false);
            stopBtn.textContent = '이어서';
            stopBtn.disabled = true;
            if (label) label.textContent = '멈춤 — 진행 중이던 요청까지 마칩니다';
            try { await post(cfg.urls.pause, {paused: true}); } catch (_) {}
            while (loopActive) await sleep(100);
            stopBtn.disabled = false;
            return;
        }
        stopBtn.disabled = true;
        try {
            const r = await post(cfg.urls.pause, {paused: false}).catch(() => null);
            // 화면을 오래 열어 둬 보안 토큰이 만료되면(419) 새로고침으로 바로 풀고, 그 밖의 실패도 수집은 그대로 잇는다(대표님 10/18 22:18 「재개할 수 없습니다」)
            if (r && r.status === 419) { location.reload(); return; }
            if (r && (r.status === 403 || r.status === 404 || r.status === 409)) throw new Error('재개할 수 없습니다 (HTTP ' + r.status + ') — 새로고침 해주세요');
            if (!r || !r.ok) reportClientError('resume', 'pause(false) HTTP ' + (r ? r.status : 'network'));
            if (extMode) await extCall('resumeShopSerp', {analysisId: cfg.analysisId});
            await loadBrowserSettings();
            RUNNER = Math.random().toString(36).slice(2) + Date.now().toString(36);
            failedItems.clear();
            stopped = false; errCount = 0;
            stopBtn.textContent = '중단';
            if (label) label.textContent = '진행 중 — 이어서 확인합니다';
            if (extMode && cfg.method !== 'api') runExtLoop(); else poll();
        } catch (e) { if (label) label.textContent = e.message; }
        finally { stopBtn.disabled = false; }
    });

    // 상품정보 다시 수집(2026-07-22) — 자동 수집이 빠졌거나 실패했을 때 수동 재수집(확장 필요)
    const recollect = document.getElementById('sk-recollect');
    const productStatus = document.getElementById('sk-product-status');
    let productRetry = null, productBusy = false;
    async function reportProduct(message) {
        if (productStatus) productStatus.textContent = message;
        try { await post(cfg.urls.collectorEvent, { kind: 'client_error', stage: 'product', message }); } catch (_) {}
    }
    async function recollectProduct() {
        if (cfg.ended || productBusy) return;
        clearTimeout(productRetry); productRetry = null;
        productBusy = true;
        if (recollect) { recollect.disabled = true; recollect.textContent = '수집 중…'; }
        if (productStatus) productStatus.textContent = '상품 페이지를 확인하고 있습니다…';
        let retry = false;
        try {
            if (!cfg.productUrl) throw new Error('제품 URL이 없어 수집할 수 없습니다.');
            if (!await waitExt(1500)) throw new Error('랭크프리 확장이 연결되지 않았습니다 — 확장 설치·로그인 후 새로고침하세요.');
            const pi = await extCall('collectProductPage', { url: cfg.productUrl }, 40000);
            retry = true;
            if (!pi?.ok || !pi.info) throw new Error(pi?.message || '확장 응답 시간 초과 — 자동 재시도');
            const r = await post(cfg.urls.productInfo, { info: pi.info });
            const body = await r.json().catch(() => ({}));
            if (!r.ok || body.ok === false) {
                retry = r.status === 429 || r.status >= 500;
                throw new Error(body.message || '상품정보 저장 실패 (HTTP ' + r.status + ')');
            }
            await reportProduct('');
            location.reload();
        } catch (e) {
            await reportProduct(e.message);
            if (retry) productRetry = setTimeout(recollectProduct, 60000);
        } finally {
            productBusy = false;
            if (recollect) { recollect.disabled = false; recollect.textContent = '상품정보 다시 수집'; }
        }
    }
    if (recollect) recollect.addEventListener('click', recollectProduct);
    // Queue failures are visible even when this page did not initiate collection.
    setInterval(async () => {
        if (productBusy || cfg.ended) return;
        try {
            const r = await fetch('{{ route('admin.shop-keyword.product-status', $analysis) }}', { headers: { Accept: 'application/json' } });
            if (!r.ok) return;
            const status = await r.json();
            if (productStatus) productStatus.textContent = status.message || '';
            if (status.message && !productRetry) productRetry = setTimeout(recollectProduct, 60000);
        } catch (_) {}
    }, 15000);

    (async function boot() {
        extMode = await waitExt(1500);
        try { await loadBrowserSettings(); } catch(e) { const notice = document.getElementById('sk-interval-notice'); if (notice) notice.textContent = e.message; halt(e.message, false); return; }
        if (cfg.ended) return;
        const immediate = sessionStorage.getItem('rf-continue-now-{{ (int) $analysis->id }}');
        sessionStorage.removeItem('rf-continue-now-{{ (int) $analysis->id }}');
        if (extMode && !stopped && !immediate) enrich();   // 중단 상태에선 보충 수집(조합 재편성→reload)도 하지 않는다
        if (!box) return;   // 미확인 조합 없음 — 보충만 수행
        if (stopped) return;   // 서버 저장된 중단 — "이어서 확인" 클릭 전까지 시작하지 않는다
        // api 방식은 서버 slot API 폴링으로 처리(빠름·차단 없음, 상위 20위) — 확장 크롤링 루프는 통합검색 방식 전용
        if (extMode && cfg.method !== 'api') runExtLoop(); else poll();
    })();
})();
</script>

@endsection
