# 21. SEO 공유 슬러그 + robots · 사이트맵

> 분석 공유 URL 을 랜덤 토큰에서 **한글/영문 SEO 슬러그**로 전환하고, robots.txt·사이트맵(인덱스+섹션)을 정비했다. (2026-07-16)

## 공유 URL — SEO 슬러그

기존 `/k/{32자토큰}` → **`/{prefix}/{슬러그}`**. 예: `/keyword/여름브라` · `/store/강남맛집`

| prefix | 모델 | 슬러그 소재 | 사이트맵 공개 |
|---|---|---|---|
| `/keyword/{slug}` | KeywordSearch | keyword | ✅ |
| `/market/{slug}` | MarketAnalysis | keyword | ✅ |
| `/product/{slug}` | ProductAnalysis | name | ✅ |
| `/seller/{slug}` | SellerPowerAnalysis | product_name | ✅ |
| `/store/{slug}` | PlaceStoreAnalysis | name | ✅ |
| `/place/{slug}` | PlaceRankSlot | place_name | ❌ 추적 대상 — 비공개 |
| `/compete/{slug}` | PlaceRankSlot | place_name(같은 슬러그) | ❌ 추적 대상 — 비공개 |
| `/shopping/{slug}` | ShopRankSlot | keyword | ❌ 추적 대상 — 비공개 |

- **슬러그 생성**: [HasShareSlug](../app/Models/Concerns/HasShareSlug.php) 트레이트. `creating` 훅에서 자동 부여, 중복이면 `-2/-3…`.
  한글·영문·숫자만 남기고(`\p{L}\p{N}`) 나머지는 `-`, 소문자화, 120자 컷.
- **하위호환**: 구 `/k /m /p /sp /ps /r /rc /sr {token}` 은 **301** 로 새 슬러그 URL 로 이동(기존 배포 링크·확장 응답 유지). `findByShareKey()` 가 slug→token 순 조회.
- **공유 버튼**: 콘솔의 공유 버튼은 `$model->shareUrl()`(슬러그) 사용. 라우트 이름(keyword.shared 등)은 그대로 두고 경로만 슬러그로 전환.
- ⚠️ **추적 슬롯 3종(/place·/compete·/shopping)은 사이트맵에 넣지 않는다** — 사용자가 추적 중인 업체/상품이 검색에 노출되면 안 됨. 개별 공유 페이지는 열리되(본인이 공유 버튼으로 수동 공유), 자동 색인만 제외.

## robots.txt

[public/robots.txt](../public/robots.txt) — `/console /admin /order/ /login /register /forgot-password /reset-password /find-email /auth/` Disallow, `Crawl-delay: 1`, `Sitemap: https://rankfree.kr/sitemap.xml`.

## 사이트맵 (인덱스 + 섹션)

[SitemapController](../app/Http/Controllers/SitemapController.php) · [config/sitemap.php](../config/sitemap.php)

- **`/sitemap.xml`** = 사이트맵 인덱스(자식 목록). **`/sitemap-{section}.xml`** = 실제 URL(대량 대비 `?page=`, chunk=20000).
- 섹션: `pages`(정적+커뮤니티 카테고리) · `community`(글, 페이징) · `products`(셀프마케팅+유형필터) · 분석 슬러그(`keyword/market/product/seller/store` — 1회성만).
- 캐시: `sitemap:vN:*` — `sitemap:refresh` 가 버전(`SitemapRefresh::version()`)을 올려 무효화. 6h TTL.
- 커뮤니티 RSS(`/community/feed`)는 그대로 유지(네이버 서치어드바이저용).

## 슬러그 백필·스케줄

- `php artisan sitemap:refresh` — 공유 슬러그 쓰는 **전체 7개 모델**(추적 슬롯 포함, 공유 버튼용)의 빈 slug 백필 + 캐시 버전 bump.
- 스케줄: 매일 05:40(KST) [routes/console.php](../routes/console.php).
- 마이그레이션: `2026_07_16_000001_add_share_slug_to_analysis_tables` (7개 테이블 `slug` unique).

## 공유 리포트 SEO/AEO/GEO (공개 색인)

- **공개 1회성 분석(keyword·market·product·seller·store) 리포트는 색인 허용** — 각 share 뷰에서 `@section('robots','noindex,nofollow')` 제거, `@section('og-type','article')` 추가.
- **추적 슬롯(place·compete·shopping)은 noindex 유지** — 사용자 추적 대상이라 개별 공유는 열리되 검색 색인 금지(rank/compete/shop-rank share).
- **구조화 데이터**: [partials/report-seo.blade.php](../resources/views/partials/report-seo.blade.php) — `BreadcrumbList` + `Article` + `FAQPage`(데이터 기반 Q&A). `@push('head')`, JSON_HEX_* 이스케이프. 5개 공개 share 뷰가 include.
  - **AEO/GEO 핵심**: FAQPage 의 질문/답변에 실제 수치를 넣어(예: "‘강남 맛집’ 월 검색량은?" → "월 약 129,100회…") 답변·생성엔진이 바로 추출.
- **가시 최적화**: 각 공개 share 뷰에 `<h1>`(리포트 제목) + 한 줄 요약(수치 포함) 추가 → 온페이지 SEO·GEO 가시 텍스트. 메타 description 도 수치로 보강.
- canonical 은 seo 파셜 기본값(`url()->current()` = 슬러그 URL)으로 자동. 구 토큰 URL 은 301 → 슬러그(정규화).

## 주의

- `include_analyses`(config) off 로 분석 슬러그 전체를 사이트맵에서 뺄 수 있음.
- 슬러그 소재(키워드·업체명·상품명)에 개인정보가 섞일 수 있으니, 공개 섹션에 넣는 1회성 분석도 소재 성격을 주기적으로 점검.
- 라우트 이름은 유지(keyword.shared 등) — 코드에서 `route('*.shared', ...)` 대신 `$model->shareUrl()`/`competeUrl()` 사용.
