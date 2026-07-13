@extends('layouts.site')

@section('content')

{{-- ============================ HERO ============================ --}}
<section class="relative overflow-hidden border-b border-hairline-soft">
    {{-- 배경 글로우 (라이트 톤 파스텔 워시) --}}
    <div class="glow-orb" style="width:640px;height:640px;top:-280px;left:8%;background:color-mix(in srgb, var(--color-accent) 26%, transparent);"></div>
    <div class="glow-orb" style="width:520px;height:520px;top:-200px;left:44%;background:color-mix(in srgb, var(--color-badge-violet) 20%, transparent);"></div>

    <div class="container-page relative py-20 lg:py-28">
        <div class="grid gap-12 lg:grid-cols-[1.05fr_0.95fr] lg:items-center">
            {{-- 좌: 카피 + 순위조회 폼 --}}
            <div>
                <div class="badge mb-5 border border-hairline" style="background:color-mix(in srgb, var(--color-canvas) 70%, transparent);">
                    <span class="w-1.5 h-1.5 rounded-full" style="background:var(--color-accent);"></span>
                    가입 없이 무료 · 30초 완료
                </div>
                <h1 class="font-display text-ink" style="font-size:clamp(30px,3.6vw,48px);line-height:1.1;">
                    네이버 마케팅,<br>순위부터 <span class="text-gradient">시장까지</span> 한눈에.
                </h1>
                <p class="mt-5 text-body" style="font-size:var(--fs-md);line-height:1.6;max-width:540px;">
                    <b class="text-ink">플레이스·쇼핑·키워드·블로그</b>를 한 곳에서 분석합니다.
                    키워드만 넣으면 내 순위와 경쟁사, 시장 규모까지 — 회원가입 없이 지금 바로 확인하세요.
                </p>

                <form id="hero-form" action="/rank-check" method="GET" class="mt-8 card p-3 flex flex-col sm:flex-row gap-2" style="max-width:560px;box-shadow:var(--shadow-card);">
                    <input name="keyword" class="input" style="flex:1;background:var(--color-surface-soft);" placeholder="검색 키워드 (예: 강남 미용실)" required>
                    <input name="place" class="input" style="flex:1;background:var(--color-surface-soft);" placeholder="내 업체명 또는 플레이스 URL" required>
                    <button type="submit" class="btn btn-primary">무료 순위 조회</button>
                </form>

                <p class="mt-5 text-muted-soft" style="font-size:var(--fs-xs);">
                    이미 <b class="text-muted">1,200+</b> 사장님이 순위를 확인했어요.
                </p>
            </div>

            {{-- 우: 순위 결과 목업 카드 --}}
            <div class="card overflow-hidden" style="box-shadow:0 0 80px color-mix(in srgb, var(--color-accent) 16%, transparent);">
                <div class="flex items-center justify-between px-5 border-b border-hairline" style="height:52px;">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full" style="background:var(--color-badge-orange);"></span>
                        <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">순위 결과</span>
                    </div>
                    <span class="text-muted-soft" style="font-size:var(--fs-sm);">키워드 · 강남 미용실</span>
                </div>
                <div class="p-5">
                    <div class="card-soft p-4 flex items-center justify-between mb-4">
                        <div>
                            <div class="text-muted" style="font-size:var(--fs-xs);">내 매장</div>
                            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">라온헤어 강남점</div>
                        </div>
                        <div class="text-right">
                            <div class="font-display text-ink" style="font-size:var(--fs-2xl);line-height:1;">7<span style="font-size:var(--fs-sm);" class="text-muted">위</span></div>
                            <div class="mt-1 inline-flex items-center gap-1" style="font-size:var(--fs-xs);color:var(--color-success);font-weight:700;">▲ 2</div>
                        </div>
                    </div>
                    <div class="flex flex-col">
                        @foreach ([['1','수앤수 헤어','리뷰 1,284'],['2','블로우 강남','리뷰 980'],['3','제로그램','리뷰 872'],['4','살롱드메이','리뷰 641']] as $row)
                        <div class="flex items-center gap-3 py-2.5 border-b border-hairline-soft">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-md bg-surface-card text-ink font-semibold" style="font-size:var(--fs-xs);">{{ $row[0] }}</span>
                            <span class="text-ink flex-1" style="font-size:var(--fs-xs);">{{ $row[1] }}</span>
                            <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $row[2] }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================ FEATURES — 카테고리 4분류 ============================ --}}
<section id="features" class="border-b border-hairline-soft">
    <div class="container-page py-20 lg:py-24">
        <div class="text-center mb-12">
            <h2 class="font-display text-ink" style="font-size:clamp(28px,3.5vw,40px);line-height:1.1;">순위 확인만? <span class="text-gradient">분석까지</span> 한 번에.</h2>
            <p class="mt-4 text-muted" style="font-size:var(--fs-md);">네이버 마케팅에 필요한 데이터를 카테고리별로 모두 제공합니다.</p>
        </div>
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['pin', 'var(--color-accent)', 'var(--color-badge-violet)', '플레이스', '네이버 지도 상위 노출을 위한 순위·경쟁·리포트 도구', ['순위 추적', '경쟁 분석 (SEO 점수)', '스마트플레이스 리포트'], '#place'],
                ['cart', 'var(--color-badge-orange)', 'var(--color-badge-pink)', '쇼핑', '스마트스토어 판매를 위한 순위·시장·상품 분석', ['쇼핑 순위추적', '쇼핑 시장 분석', '셀러력 · 상품 리뷰 분석'], '#shopping'],
                ['search', 'var(--color-accent)', 'var(--color-badge-emerald)', '키워드', '검색량·트렌드로 뜨는 키워드를 발굴', ['키워드 분석 (검색량·트렌드)', '키워드 추천 (황금 키워드)', '대량 분석 (엑셀)'], '#keyword'],
                ['pen', 'var(--color-badge-emerald)', 'var(--color-accent)', '블로그', '영향력 있는 블로거를 데이터로 선별', ['블로그 지수 분석', '검색 상위 블로거 수집', '저장 블로거 관리'], '#blog'],
            ] as $f)
            <a href="{{ $f[6] }}" class="card p-8 relative overflow-hidden block transition hover:-translate-y-0.5" style="text-decoration:none;">
                <x-card-bg pattern="gradient" color="{{ $f[1] }}" color2="{{ $f[2] }}" opacity="0.22" />
                <x-card-bg pattern="dots" color="{{ $f[1] }}" opacity="0.28" />
                <div class="relative">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background:color-mix(in srgb, {{ $f[1] }} 15%, transparent);color:{{ $f[1] }};">
                        @if ($f[0] === 'pin')
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        @elseif ($f[0] === 'cart')
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                        @elseif ($f[0] === 'search')
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        @else
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        @endif
                    </div>
                    <h3 class="mt-4 text-ink font-semibold" style="font-size:var(--fs-md);">{{ $f[3] }}</h3>
                    <p class="mt-2 text-muted" style="font-size:var(--fs-sm);line-height:1.6;">{{ $f[4] }}</p>
                    <ul class="mt-4 flex flex-col gap-1.5">
                        @foreach ($f[5] as $li)
                        <li class="flex items-center gap-2 text-body" style="font-size:var(--fs-xs);">
                            <span style="color:{{ $f[1] }};">✓</span> {{ $li }}
                        </li>
                        @endforeach
                    </ul>
                </div>
            </a>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================ PLACE ============================ --}}
<section id="place" class="border-b border-hairline-soft">
    <div class="container-page py-20 lg:py-24">
        <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
            <div>
                <div class="badge mb-4 border border-hairline">플레이스 · 순위 · 경쟁 · 리포트</div>
                <h2 class="font-display text-ink" style="font-size:clamp(26px,3vw,36px);line-height:1.15;">순위가 왜 떨어졌는지<br>데이터로 답합니다.</h2>
                <p class="mt-4 text-body" style="font-size:var(--fs-base);line-height:1.7;max-width:480px;">
                    매일 순위를 기록하고, 상위 경쟁사 지표와 SEO 점수(유사도·관련성·랭킹)를 함께 보여줍니다. 리뷰가 부족한지, 저장수가 밀리는지, 어떤 키워드에서 빠지는지 한눈에.
                </p>
                <ul class="mt-6 flex flex-col gap-3">
                    @foreach (['키워드별 일간 순위 추이 그래프','상위 30위 경쟁사 리뷰·저장·예약 비교','플레이스 SEO 점수 진단 (자체 지표)','스마트플레이스 통계·리뷰·예약 리포트','공유 링크로 리포트 전달 (로그인 불필요)'] as $li)
                    <li class="flex items-center gap-2 text-ink" style="font-size:var(--fs-sm);">
                        <span style="color:var(--color-success);">✓</span> {{ $li }}
                    </li>
                    @endforeach
                </ul>
            </div>
            {{-- 순위 추이 차트 목업 --}}
            <div class="card p-6" style="box-shadow:0 0 60px color-mix(in srgb, var(--color-badge-violet) 12%, transparent);">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">순위 추이 · 최근 14일</span>
                    <span class="badge border border-hairline" style="font-size:var(--fs-xs);">강남 미용실</span>
                </div>
                <svg viewBox="0 0 400 160" class="w-full" style="height:auto;">
                    <defs>
                        <linearGradient id="rank-fill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="var(--color-accent)" stop-opacity="0.30"/>
                            <stop offset="1" stop-color="var(--color-accent)" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <polygon fill="url(#rank-fill)"
                        points="0,120 33,110 66,116 99,90 132,96 165,70 198,74 231,52 264,60 297,40 330,44 363,28 400,30 400,160 0,160" />
                    <polyline fill="none" stroke="var(--color-accent)" stroke-width="2.5"
                        points="0,120 33,110 66,116 99,90 132,96 165,70 198,74 231,52 264,60 297,40 330,44 363,28 400,30" />
                    @foreach (['0,120','99,90','198,74','297,40','363,28'] as $pt)
                    <circle cx="{{ explode(',', $pt)[0] }}" cy="{{ explode(',', $pt)[1] }}" r="3" fill="var(--color-accent)" />
                    @endforeach
                </svg>
                <div class="flex justify-between mt-2 text-muted-soft" style="font-size:var(--fs-xs);">
                    <span>7/1</span><span>7/7</span><span>7/14</span>
                </div>
                {{-- SEO 점수 미니 바 --}}
                <div class="mt-5 pt-4 border-t border-hairline-soft grid grid-cols-3 gap-3">
                    @foreach ([['유사도','88'],['관련성','74'],['랭킹','91']] as $sc)
                    <div>
                        <div class="text-muted" style="font-size:var(--fs-xs);">{{ $sc[0] }}</div>
                        <div class="mt-1 font-display text-ink" style="font-size:var(--fs-lg);line-height:1;">{{ $sc[1] }}</div>
                        <div class="mt-1.5 rounded-full bg-surface-strong overflow-hidden" style="height:5px;">
                            <div class="h-full rounded-full" style="width:{{ $sc[1] }}%;background:var(--color-accent);"></div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================ SHOPPING ============================ --}}
<section id="shopping" class="border-b border-hairline-soft">
    <div class="container-page py-20 lg:py-24">
        <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
            {{-- 시장 분석 목업 (좌) --}}
            <div class="card p-6 relative overflow-hidden order-last lg:order-first" style="box-shadow:0 0 60px color-mix(in srgb, var(--color-badge-orange) 12%, transparent);">
                <x-card-bg pattern="gradient" color="var(--color-badge-orange)" color2="var(--color-badge-pink)" opacity="0.14" />
                <div class="relative">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">쇼핑 시장 분석</span>
                        <span class="badge border border-hairline" style="font-size:var(--fs-xs);">키워드 · 캠핑 의자</span>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        @foreach ([['시장 규모 (6개월)','4.2억'],['월평균 매출','7,000만'],['평균 판매가','48,900원'],['상위 10개 점유율','62%']] as $s)
                        <div class="card-soft p-4">
                            <div class="text-muted" style="font-size:var(--fs-xs);">{{ $s[0] }}</div>
                            <div class="mt-1 font-display text-ink" style="font-size:var(--fs-xl);line-height:1;">{{ $s[1] }}</div>
                        </div>
                        @endforeach
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach (['접이식 캠핑의자','경량 체어','릴렉스 체어','로우 체어'] as $kw)
                        <span class="badge border border-hairline" style="font-size:var(--fs-xs);">{{ $kw }}</span>
                        @endforeach
                    </div>
                    <p class="mt-3 text-muted-soft" style="font-size:var(--fs-xs);">* 판매량·매출은 자체 추정치입니다.</p>
                </div>
            </div>
            {{-- 카피 (우) --}}
            <div>
                <div class="badge mb-4 border border-hairline">쇼핑 · 순위 · 시장 · 셀러력 · 리뷰</div>
                <h2 class="font-display text-ink" style="font-size:clamp(26px,3vw,36px);line-height:1.15;">팔리는 시장인지,<br>뛰어들기 전에 확인하세요.</h2>
                <p class="mt-4 text-body" style="font-size:var(--fs-base);line-height:1.7;max-width:480px;">
                    크롬 확장으로 네이버 쇼핑 키워드의 시장 규모·매출·경쟁 구도를 즉시 계산합니다. 상품 순위추적, 셀러력(상품 SEO) 비교, 리뷰 감정 분석까지 판매의 전 과정을 데이터로.
                </p>
                <ul class="mt-6 flex flex-col gap-3">
                    @foreach (['상품/업체 × 키워드 쇼핑 순위추적','6개월 시장 규모·월평균 매출·예상 수익','셀러력 — 상품 SEO·지수 5축 경쟁 비교','상품 리뷰 감정·장단점·옵션별 예상 매출'] as $li)
                    <li class="flex items-center gap-2 text-ink" style="font-size:var(--fs-sm);">
                        <span style="color:var(--color-success);">✓</span> {{ $li }}
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</section>

{{-- ============================ KEYWORD ============================ --}}
<section id="keyword" class="border-b border-hairline-soft">
    <div class="container-page py-20 lg:py-24">
        <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
            {{-- 카피 (좌) --}}
            <div>
                <div class="badge mb-4 border border-hairline">키워드 · 검색량 · 트렌드 · 추천</div>
                <h2 class="font-display text-ink" style="font-size:clamp(26px,3vw,36px);line-height:1.15;">뜨는 키워드를<br>남보다 먼저 잡으세요.</h2>
                <p class="mt-4 text-body" style="font-size:var(--fs-base);line-height:1.7;max-width:480px;">
                    검색량·성별/연령·12개월 트렌드·콘텐츠 포화도를 한 화면에. 연관·자동완성을 기회 점수로 랭킹해 경쟁은 낮고 검색량은 높은 <b class="text-ink">황금 키워드</b>를 찾아드립니다. 엑셀로 수백 개를 한 번에 분석할 수도 있어요.
                </p>
                <ul class="mt-6 flex flex-col gap-3">
                    @foreach (['월간 검색량 · PC/모바일 · 경쟁강도','성별/연령 분포 · 12개월 검색 트렌드','키워드 추천 — 기회 점수로 황금 키워드 발굴','엑셀 업로드 대량 분석 · 엑셀 다운로드'] as $li)
                    <li class="flex items-center gap-2 text-ink" style="font-size:var(--fs-sm);">
                        <span style="color:var(--color-success);">✓</span> {{ $li }}
                    </li>
                    @endforeach
                </ul>
            </div>
            {{-- 키워드 분석 목업 (우) --}}
            <div class="card p-6 relative overflow-hidden" style="box-shadow:0 0 60px color-mix(in srgb, var(--color-accent) 12%, transparent);">
                <x-card-bg pattern="gradient" color="var(--color-accent)" color2="var(--color-badge-emerald)" opacity="0.12" />
                <div class="relative">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">키워드 분석 · 캠핑 의자</span>
                        <span class="badge border border-hairline" style="font-size:var(--fs-xs);">경쟁강도 낮음</span>
                    </div>
                    <div class="grid grid-cols-3 gap-3 mb-4">
                        @foreach ([['월 검색량','48,300'],['PC','12,100'],['모바일','36,200']] as $s)
                        <div class="card-soft p-3">
                            <div class="text-muted" style="font-size:var(--fs-xs);">{{ $s[0] }}</div>
                            <div class="mt-1 font-display text-ink" style="font-size:var(--fs-lg);line-height:1;">{{ $s[1] }}</div>
                        </div>
                        @endforeach
                    </div>
                    {{-- 12개월 트렌드 미니 바 --}}
                    <div class="text-muted mb-2" style="font-size:var(--fs-xs);">12개월 검색 트렌드</div>
                    <div class="flex items-end gap-1.5" style="height:60px;">
                        @foreach ([40,44,52,48,60,72,90,84,66,58,50,54] as $h)
                        <div class="flex-1 rounded-t" style="height:{{ $h }}%;background:color-mix(in srgb, var(--color-accent) {{ $h }}%, var(--color-surface-strong));"></div>
                        @endforeach
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach (['캠핑의자 추천','경량 캠핑의자','릴렉스체어','감성 캠핑'] as $kw)
                        <span class="badge border border-hairline" style="font-size:var(--fs-xs);">{{ $kw }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================ BLOG ============================ --}}
<section id="blog" class="border-b border-hairline-soft">
    <div class="container-page py-20 lg:py-24">
        <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
            {{-- 카피 (좌) --}}
            <div>
                <div class="badge mb-4 border border-hairline">블로그 · 지수 · 수집 · 저장</div>
                <h2 class="font-display text-ink" style="font-size:clamp(26px,3vw,36px);line-height:1.15;">영향력 있는 블로거를<br>데이터로 가려내세요.</h2>
                <p class="mt-4 text-body" style="font-size:var(--fs-base);line-height:1.7;max-width:480px;">
                    키워드 검색에 노출되는 블로그를 자동 수집하고, 아이디별 방문·이웃·포스팅 활동을 점수화합니다. 눈여겨본 블로거는 키워드와 함께 저장해두고, 체험단 섭외 전에 진짜 영향력을 확인하세요.
                </p>
                <ul class="mt-6 flex flex-col gap-3">
                    @foreach (['검색 상위 노출 블로그 수집·아이디 추출','방문자·이웃·포스팅 활동 지수화','키워드+아이디로 관심 블로거 저장·엑셀','체험단·리뷰어 섭외 전 영향력 판단'] as $li)
                    <li class="flex items-center gap-2 text-ink" style="font-size:var(--fs-sm);">
                        <span style="color:var(--color-success);">✓</span> {{ $li }}
                    </li>
                    @endforeach
                </ul>
            </div>
            {{-- 블로그 지수 목업 (우) --}}
            <div class="card p-6 relative overflow-hidden" style="box-shadow:0 0 60px color-mix(in srgb, var(--color-badge-emerald) 12%, transparent);">
                <x-card-bg pattern="dots" color="var(--color-badge-emerald)" opacity="0.22" />
                <div class="relative">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">블로그 지수 · 강남 미용실</span>
                        <span class="badge border border-hairline" style="font-size:var(--fs-xs);">노출 블로그 32개</span>
                    </div>
                    <div class="flex flex-col">
                        @foreach ([['hair_daily','92','최적'],['gangnam_review','81','준최적'],['beauty_note','64','일반'],['daily_zip','41','일반']] as $b)
                        <div class="flex items-center gap-3 py-2.5 border-b border-hairline-soft">
                            <span class="text-ink flex-1 truncate" style="font-size:var(--fs-xs);font-family:var(--font-mono);">{{ $b[0] }}</span>
                            <div class="rounded-full bg-surface-strong overflow-hidden" style="width:120px;height:6px;">
                                <div class="h-full rounded-full" style="width:{{ $b[1] }}%;background:var(--color-badge-emerald);"></div>
                            </div>
                            <span class="text-ink font-semibold text-right" style="font-size:var(--fs-xs);width:28px;">{{ $b[1] }}</span>
                            <span class="badge" style="font-size:var(--fs-xs);padding:2px 8px;">{{ $b[2] }}</span>
                        </div>
                        @endforeach
                    </div>
                    <p class="mt-3 text-muted-soft" style="font-size:var(--fs-xs);">* 지수는 자체 추정치입니다.</p>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================ MARKETING 중개 ============================ --}}
<section id="marketing" class="border-b border-hairline-soft">
    <div class="container-page py-20 lg:py-24">
        <div class="text-center mb-12">
            <h2 class="font-display text-ink" style="font-size:clamp(28px,3.5vw,40px);line-height:1.1;">분석에서 끝나지 않습니다.</h2>
            <p class="mt-4 text-muted" style="font-size:var(--fs-md);max-width:560px;margin-inline:auto;">약점을 찾았다면, 개선할 마케팅까지 랭크프리가 연결해드립니다.</p>
        </div>
        <div class="grid gap-6 md:grid-cols-3">
            @foreach ([
                ['플레이스 최적화','정보 충실도·키워드·사진을 진단하고 상위 노출에 맞게 정비합니다.','var(--color-accent)','var(--color-badge-violet)'],
                ['블로그·체험단','분석한 블로그 지수를 바탕으로 영향력 있는 리뷰어를 매칭합니다.','var(--color-badge-emerald)','var(--color-accent)'],
                ['광고 대행','플레이스·파워링크·쇼핑 광고를 데이터 기반으로 운영합니다.','var(--color-badge-orange)','var(--color-badge-pink)'],
            ] as $m)
            <div class="card p-8 flex flex-col relative overflow-hidden">
                <x-card-bg pattern="gradient" color="{{ $m[2] }}" color2="{{ $m[3] }}" opacity="0.26" />
                <div class="relative flex flex-col flex-1">
                    <h3 class="text-ink font-semibold" style="font-size:var(--fs-md);">{{ $m[0] }}</h3>
                    <p class="mt-2 text-muted flex-1" style="font-size:var(--fs-sm);line-height:1.6;">{{ $m[1] }}</p>
                    <a href="/support" class="btn btn-secondary btn-sm mt-5" style="align-self:flex-start;">상담 문의</a>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================ DEVELOPERS / API ============================ --}}
<section id="developers" class="border-b border-hairline-soft">
    <div class="container-page py-16 lg:py-20">
        <div class="card relative overflow-hidden" style="padding:40px 32px;">
            <x-card-bg pattern="grid" color="var(--color-ink)" opacity="0.05" />
            <div class="relative grid gap-8 lg:grid-cols-[1fr_0.9fr] lg:items-center">
                <div>
                    <div class="badge mb-4 border border-hairline">개발자 · 순위 API</div>
                    <h2 class="font-display text-ink" style="font-size:clamp(24px,2.6vw,32px);line-height:1.15;">순위 데이터를 내 서비스로.</h2>
                    <p class="mt-3 text-body" style="font-size:var(--fs-base);line-height:1.7;max-width:460px;">
                        키 발급 한 번으로 플레이스·쇼핑 순위를 API로 받아보세요. 허용 IP·일일 한도·기간을 직접 관리할 수 있습니다.
                    </p>
                    <a href="/developers" class="btn btn-secondary btn-sm mt-5">API 문서 보기 →</a>
                </div>
                <div class="rounded-lg border border-hairline bg-surface-soft overflow-hidden" style="font-family:var(--font-mono);font-size:var(--fs-xs);">
                    <div class="flex items-center gap-1.5 px-4 border-b border-hairline-soft" style="height:32px;">
                        <span class="w-2.5 h-2.5 rounded-full bg-surface-strong"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-surface-strong"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-surface-strong"></span>
                        <span class="ml-2 text-muted-soft">GET /api/v1/rank</span>
                    </div>
                    <div class="px-4 py-3 text-muted" style="line-height:1.75;">
                        <span style="color:var(--color-badge-emerald);">$</span> curl -H "X-API-KEY: ****" \<br>
                        &nbsp;&nbsp;"rankfree.kr/api/v1/rank?keyword=강남+미용실&place=1234"<br>
                        <span class="text-ink">{ "rank": 7, "list_total": 300, "checked": "2026-07-12" }</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================ PRICING ============================ --}}
<section id="pricing" class="border-b border-hairline-soft">
    <div class="container-page py-20 lg:py-24">
        <div class="text-center mb-12">
            <h2 class="font-display text-ink" style="font-size:clamp(28px,3.5vw,40px);line-height:1.1;">합리적인 요금</h2>
            <p class="mt-4 text-muted" style="font-size:var(--fs-md);">무료로 시작하고, 필요할 때만 올리세요.</p>
        </div>
        <div class="grid gap-6 md:grid-cols-3" style="max-width:960px;margin-inline:auto;">
            {{-- Free --}}
            <div class="card p-8 flex flex-col">
                <div class="text-ink font-semibold" style="font-size:var(--fs-lg);">무료</div>
                <div class="mt-3 font-display text-ink" style="font-size:var(--fs-3xl);line-height:1;">0<span style="font-size:var(--fs-base);" class="text-muted">원</span></div>
                <p class="mt-2 text-muted" style="font-size:var(--fs-xs);">순위체크로 시작</p>
                <ul class="mt-6 flex flex-col gap-2 flex-1" style="font-size:var(--fs-xs);">
                    @foreach (['순위체크 100개 무료','추천인 초대 시 최대 200개','플레이스 분석·경쟁분석 월 5회','키워드·블로그·쇼핑 분석'] as $li)
                    <li class="flex gap-2 text-body"><span style="color:var(--color-success);">✓</span>{{ $li }}</li>
                    @endforeach
                </ul>
                <a href="{{ route('register') }}" class="btn btn-secondary mt-6">무료로 시작</a>
            </div>
            {{-- Pro (featured) --}}
            <div class="card p-8 flex flex-col relative overflow-hidden" style="border-color:color-mix(in srgb, var(--color-accent) 55%, transparent);box-shadow:0 0 48px color-mix(in srgb, var(--color-accent) 14%, transparent);">
                <x-card-bg pattern="gradient" color="var(--color-accent)" color2="var(--color-badge-violet)" opacity="0.18" />
                <x-card-bg pattern="rings" color="var(--color-accent)" opacity="0.3" />
                <div class="relative flex flex-col flex-1">
                    <div class="flex items-center gap-2">
                        <span class="text-ink font-semibold" style="font-size:var(--fs-lg);">프로</span>
                        <span class="badge" style="background:color-mix(in srgb, var(--color-accent) 18%, transparent);color:var(--color-accent);font-size:var(--fs-xs);">인기</span>
                    </div>
                    <div class="mt-3 font-display text-ink" style="font-size:var(--fs-3xl);line-height:1;">29,000<span style="font-size:var(--fs-base);" class="text-muted">원/월</span></div>
                    <p class="mt-2 text-muted" style="font-size:var(--fs-xs);">본격 순위 관리</p>
                    <ul class="mt-6 flex flex-col gap-2 flex-1" style="font-size:var(--fs-xs);">
                        @foreach (['순위체크 무제한','경쟁분석 자동 추적·알림','순위·추적 API 제공','키워드 트렌드·대량 분석'] as $li)
                        <li class="flex gap-2 text-body"><span style="color:var(--color-accent);">✓</span>{{ $li }}</li>
                        @endforeach
                    </ul>
                    <a href="{{ route('register') }}" class="btn btn-accent mt-6">프로 시작</a>
                </div>
            </div>
            {{-- 대행 --}}
            <div class="card p-8 flex flex-col">
                <div class="text-ink font-semibold" style="font-size:var(--fs-lg);">마케팅 대행</div>
                <div class="mt-3 font-display text-ink" style="font-size:var(--fs-3xl);line-height:1;">맞춤<span style="font-size:var(--fs-base);" class="text-muted"> 견적</span></div>
                <p class="mt-2 text-muted" style="font-size:var(--fs-xs);">분석 + 실행까지</p>
                <ul class="mt-6 flex flex-col gap-2 flex-1" style="font-size:var(--fs-xs);">
                    @foreach (['플레이스 최적화','블로그·체험단 매칭','광고 운영 대행','전담 매니저'] as $li)
                    <li class="flex gap-2 text-body"><span style="color:var(--color-success);">✓</span>{{ $li }}</li>
                    @endforeach
                </ul>
                <a href="/support" class="btn btn-secondary mt-6">상담 문의</a>
            </div>
        </div>
    </div>
</section>

{{-- ============================ CTA BAND ============================ --}}
<section class="container-page py-20 lg:py-24">
    <div class="card text-center relative overflow-hidden" style="padding:56px 24px;">
        <x-card-bg pattern="grid" color="var(--color-ink)" opacity="0.07" />
        <div class="glow-orb" style="width:420px;height:420px;bottom:-260px;left:50%;transform:translateX(-50%);background:color-mix(in srgb, var(--color-accent) 26%, transparent);"></div>
        <div class="relative">
            <h2 class="font-display text-ink" style="font-size:clamp(26px,3vw,34px);line-height:1.2;">30초면 내 순위를 알 수 있어요.</h2>
            <p class="mt-3 text-muted" style="font-size:var(--fs-base);">가입도, 카드도 필요 없습니다. 지금 키워드만 넣어보세요.</p>
            <a href="/#hero-form" class="btn btn-primary btn-lg mt-6">무료로 순위 확인하기</a>
        </div>
    </div>
</section>

@endsection
