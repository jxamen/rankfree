{{-- 셀프마케팅 상품 카탈로그 — 게스트(layouts.site)·회원(console.layout) 공용. 관리자 등록 상품을 카드로. --}}
<section class="py-10 lg:py-14 {{ auth()->check() ? '' : 'container-page' }}" style="padding-left:0;padding-right:0;">
    <div class="mb-5">
        <h1 class="font-display text-ink" style="font-size:clamp(24px,2.8vw,32px);line-height:1.2;">셀프마케팅</h1>
        <p class="text-muted mt-1" style="font-size:var(--fs-sm);">필요한 마케팅을 직접 골라 신청하세요. 분석으로 찾은 약점을 실행으로 연결합니다.</p>
    </div>

    {{-- 카테고리(유형) 필터(좌) + 상품명 검색(우) — 카드 --}}
    <div class="card p-3 mb-6"><div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('self-marketing', array_filter(['q' => $q])) }}" class="badge" style="font-size:var(--fs-xs);padding:5px 13px;{{ ! $type ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">전체</a>
        @foreach ($activeTypeCodes as $code)
            <a href="{{ route('self-marketing', array_filter(['type' => $code, 'q' => $q])) }}" class="badge" style="font-size:var(--fs-xs);padding:5px 13px;{{ $type === $code ? 'background:var(--color-ink);color:var(--color-canvas);' : '' }}">{{ $typeNames[$code] ?? $code }}</a>
        @endforeach
        <form method="GET" class="ml-auto">
            @if ($type)<input type="hidden" name="type" value="{{ $type }}">@endif
            <input name="q" value="{{ $q }}" class="input" style="width:240px;font-size:var(--fs-xs);" placeholder="상품명 검색">
        </form>
    </div></div>

    @if ($products->isEmpty())
        <div class="card text-center text-muted-soft" style="padding:64px 24px;font-size:var(--fs-sm);">
            @if ($type || $q)
                조건에 맞는 상품이 없습니다. <a href="{{ route('self-marketing') }}" class="text-accent hover:underline">전체 보기</a>
            @else
                등록된 상품이 없습니다. 곧 다양한 셀프마케팅 상품을 만나보실 수 있어요.
            @endif
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
                    <h3 class="text-ink font-semibold mt-3" style="font-size:var(--fs-md);line-height:1.35;">{{ $product->title }}</h3>
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
