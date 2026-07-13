{{-- 다크 푸터 — 페이지를 닫는 다크 서피스. 링크는 실제 기능 카테고리에 맞춤 --}}
<footer class="bg-surface-dark text-on-dark-soft mt-auto">
    <div class="container-page" style="padding-top:64px;padding-bottom:48px;">
        <div class="grid gap-10 md:grid-cols-[1.5fr_1fr_1fr_1fr_1fr]">
            {{-- 브랜드 --}}
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-md bg-canvas text-ink font-display" style="font-size:var(--fs-sm);">R</span>
                    <span class="font-display text-on-dark" style="font-size:var(--fs-lg);">랭크프리</span>
                </div>
                <p style="font-size:var(--fs-xs);line-height:1.6;max-width:280px;">네이버 플레이스·쇼핑·키워드·블로그 순위와 경쟁사를 무료로 분석하고, 필요한 마케팅까지 한 곳에서.</p>
            </div>

            {{-- 플레이스 --}}
            <div>
                <div class="text-on-dark font-semibold mb-3" style="font-size:var(--fs-xs);">플레이스</div>
                <ul class="flex flex-col gap-2" style="font-size:var(--fs-xs);">
                    <li><a href="/#place" class="hover:text-on-dark transition">순위 추적</a></li>
                    <li><a href="/#place" class="hover:text-on-dark transition">경쟁 분석</a></li>
                    <li><a href="/#place" class="hover:text-on-dark transition">스마트플레이스 리포트</a></li>
                    <li><a href="/rank-check" class="hover:text-on-dark transition">무료 순위체크</a></li>
                </ul>
            </div>

            {{-- 쇼핑 --}}
            <div>
                <div class="text-on-dark font-semibold mb-3" style="font-size:var(--fs-xs);">쇼핑</div>
                <ul class="flex flex-col gap-2" style="font-size:var(--fs-xs);">
                    <li><a href="/#shopping" class="hover:text-on-dark transition">쇼핑 순위추적</a></li>
                    <li><a href="/#shopping" class="hover:text-on-dark transition">쇼핑 시장 분석</a></li>
                    <li><a href="/#shopping" class="hover:text-on-dark transition">셀러력 · 상품 SEO</a></li>
                    <li><a href="/#shopping" class="hover:text-on-dark transition">상품 리뷰 분석</a></li>
                </ul>
            </div>

            {{-- 키워드·블로그·API --}}
            <div>
                <div class="text-on-dark font-semibold mb-3" style="font-size:var(--fs-xs);">키워드 · 블로그</div>
                <ul class="flex flex-col gap-2" style="font-size:var(--fs-xs);">
                    <li><a href="/#keyword" class="hover:text-on-dark transition">키워드 분석</a></li>
                    <li><a href="/#keyword" class="hover:text-on-dark transition">키워드 추천 · 대량</a></li>
                    <li><a href="/#blog" class="hover:text-on-dark transition">블로그 지수 분석</a></li>
                    <li><a href="/developers" class="hover:text-on-dark transition">순위 API</a></li>
                </ul>
            </div>

            {{-- 회사 --}}
            <div>
                <div class="text-on-dark font-semibold mb-3" style="font-size:var(--fs-xs);">회사</div>
                <ul class="flex flex-col gap-2" style="font-size:var(--fs-xs);">
                    <li><a href="/#marketing" class="hover:text-on-dark transition">마케팅 서비스</a></li>
                    <li><a href="/#pricing" class="hover:text-on-dark transition">요금</a></li>
                    <li><a href="/support" class="hover:text-on-dark transition">고객지원</a></li>
                    <li><a href="/terms" class="hover:text-on-dark transition">이용약관</a></li>
                    <li><a href="/privacy" class="hover:text-on-dark transition">개인정보처리방침</a></li>
                </ul>
            </div>
        </div>

        <div class="border-t border-white/10 mt-10 pt-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3" style="font-size:var(--fs-xs);color:var(--color-muted-soft);">
            <span>© {{ date('Y') }} 랭크프리. All rights reserved.</span>
            <span>네이버 순위·점수는 자체 추정치이며 네이버 공식 지표가 아닙니다.</span>
        </div>
    </div>
</footer>
