{{-- 셀프마케팅 상품 카탈로그 — 게스트(layouts.site)·회원(console.layout) 공용. 관리자 등록 상품을 카드로. --}}
<section class="{{ auth()->check() ? '' : 'py-10 lg:py-14 container-page' }}" style="padding-left:0;padding-right:0;">
    @auth
        {{-- 콘솔 뷰 — 다른 메뉴와 동일한 공통 헤더(메뉴명 + 설명) --}}
        <x-console.page-head title="셀프마케팅" desc="필요한 마케팅을 직접 골라 신청하세요. 분석으로 찾은 약점을 실행으로 연결합니다." />
    @else
        <div class="mb-5">
            <h1 class="font-display text-ink" style="font-size:clamp(24px,2.8vw,32px);line-height:1.2;">셀프마케팅</h1>
            <p class="text-muted mt-1" style="font-size:var(--fs-sm);">필요한 마케팅을 직접 골라 신청하세요. 분석으로 찾은 약점을 실행으로 연결합니다.</p>
        </div>
    @endauth

    @php
        // 카드/리스트 토글 상태 — 링크·검색 폼에 view 파라미터를 유지(카드가 기본이라 card 는 생략)
        $viewParam = ($view ?? 'card') === 'list' ? 'list' : null;
    @endphp
    {{-- 카테고리(유형) 필터(좌) + 상품명 검색·뷰 토글(우) — 카드 --}}
    <div class="card p-3 {{ auth()->check() ? 'mb-4' : 'mb-6' }}"><div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('self-marketing', array_filter(['q' => $q, 'view' => $viewParam])) }}" class="badge" style="font-size:var(--fs-xs);padding:5px 13px;{{ ! $type ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">전체</a>
        @foreach ($activeTypeCodes as $code)
            <a href="{{ route('self-marketing', array_filter(['type' => $code, 'q' => $q, 'view' => $viewParam])) }}" class="badge" style="font-size:var(--fs-xs);padding:5px 13px;{{ $type === $code ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">{{ $typeNames[$code] ?? $code }}</a>
        @endforeach
        <form method="GET" class="ml-auto flex items-center gap-2">
            @if ($type)<input type="hidden" name="type" value="{{ $type }}">@endif
            @if ($viewParam)<input type="hidden" name="view" value="list">@endif
            @if ($q !== '')<a href="{{ route('self-marketing', array_filter(['type' => $type, 'view' => $viewParam])) }}" class="btn btn-ghost btn-sm" style="height:36px;">초기화</a>@endif
            <input name="q" value="{{ $q }}" class="input" style="width:240px;font-size:var(--fs-xs);" placeholder="상품명 검색">
            <button type="submit" class="btn btn-primary btn-sm" style="height:36px;">검색</button>
        </form>
        {{-- 카드 ↔ 리스트 뷰 토글 — 유형 칩과 같은 badge 스타일 --}}
        <div class="flex items-center gap-1" style="border-left:1px solid var(--color-hairline-soft);padding-left:10px;">
            <a href="{{ route('self-marketing', array_filter(['type' => $type, 'q' => $q])) }}" class="badge" style="font-size:var(--fs-xs);padding:5px 13px;{{ ! $viewParam ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">카드</a>
            <a href="{{ route('self-marketing', array_filter(['type' => $type, 'q' => $q, 'view' => 'list'])) }}" class="badge" style="font-size:var(--fs-xs);padding:5px 13px;{{ $viewParam ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">리스트</a>
        </div>
    </div></div>

    @if ($products->isEmpty())
        <div class="card text-center text-muted-soft" style="padding:64px 24px;font-size:var(--fs-sm);">
            @if ($type || $q)
                조건에 맞는 상품이 없습니다. <a href="{{ route('self-marketing') }}" class="text-accent hover:underline">전체 보기</a>
            @else
                등록된 상품이 없습니다. 곧 다양한 셀프마케팅 상품을 만나보실 수 있어요.
            @endif
        </div>
    @elseif ($viewParam === 'list')
        {{-- 리스트(테이블) 뷰 — 상품명 클릭 시 상세(주문 페이지)로 --}}
        <div class="card overflow-hidden">
            <div style="overflow-x:auto;">
                <table class="w-full" style="min-width:760px;">
                    <thead>
                        <tr class="text-muted" style="font-size:var(--fs-xs);border-bottom:1px solid var(--color-hairline-soft);">
                            <th class="text-left px-5 py-3 font-semibold" style="width:120px;">유형</th>
                            <th class="text-left px-3 py-3 font-semibold">상품명</th>
                            <th class="text-right px-3 py-3 font-semibold" style="width:130px;">시작가</th>
                            <th class="text-right px-5 py-3 font-semibold" style="width:110px;">주문</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $product)
                            @php
                                $typeName = $typeNames[$product->product_type] ?? '마케팅';
                                $desc = trim(strip_tags((string) $product->description));
                                $price = (float) $product->min_price;
                            @endphp
                            <tr style="border-top:1px solid var(--color-hairline-soft);">
                                <td class="px-5 py-3">
                                    <span class="badge" style="font-size:var(--fs-xs);padding:3px 10px;white-space:nowrap;">{{ $typeName }}</span>
                                </td>
                                <td class="px-3 py-3">
                                    <a href="{{ $product->orderUrl() }}" class="text-ink font-medium hover:underline" style="font-size:var(--fs-sm);">{{ $product->title }}</a>
                                    @if ($desc !== '')
                                        <div class="text-muted-soft mt-0.5" style="font-size:var(--fs-xs);">{{ \Illuminate\Support\Str::limit($desc, 80) }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right text-ink font-medium" style="font-size:var(--fs-sm);white-space:nowrap;">
                                    {{ $price > 0 ? number_format($price).'원~' : '견적 문의' }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ $product->orderUrl() }}" class="btn btn-primary btn-sm flex-none">신청하기</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($products as $product)
                @php
                    $typeName = $typeNames[$product->product_type] ?? '마케팅';
                    $desc = trim(strip_tags((string) $product->description));
                    $price = (float) $product->min_price;
                @endphp
                <div class="card p-6 flex flex-col" style="box-shadow:var(--shadow-card);">
                    <span class="badge self-start" style="font-size:var(--fs-xs);padding:3px 10px;">{{ $typeName }}</span>
                    <h3 class="mt-3" style="font-size:var(--fs-md);line-height:1.35;"><a href="{{ $product->orderUrl() }}" class="text-ink font-semibold hover:underline">{{ $product->title }}</a></h3>
                    @if ($desc !== '')
                        <p class="text-muted mt-2 flex-1" style="font-size:var(--fs-sm);line-height:1.65;">{{ \Illuminate\Support\Str::limit($desc, 110) }}</p>
                    @else
                        <div class="flex-1"></div>
                    @endif
                    <div class="mt-5 flex items-end justify-between gap-3" style="border-top:1px solid var(--color-hairline-soft);padding-top:16px;">
                        <div>
                            <div class="text-muted-soft" style="font-size:var(--fs-xs);">시작가</div>
                            <div class="font-display text-ink" style="font-size:var(--fs-lg);line-height:1.1;">
                                {{ $price > 0 ? number_format($price).'원~' : '견적 문의' }}
                            </div>
                        </div>
                        <a href="{{ $product->orderUrl() }}" class="btn btn-primary btn-sm flex-none">신청하기</a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>
