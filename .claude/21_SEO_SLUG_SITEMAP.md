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
- **키워드-전역 데이터는 슬러그 인수(-2 금지, 2026-07-22)**: `shareSlugTakesOver()=true` 모델(현재 **MarketAnalysis**)은 같은 키워드의 새 문서가 **기본 슬러그를 인수**하고 이전 문서는 반납(slug=null) — 같은 키워드 = 같은 데이터라 정식 URL 1개에 최신만 공개. 구 `-2` 링크·구 토큰은 `findByShareKey` 폴백 + 컨트롤러 301 로 기본 슬러그에 통합([MarketCanonicalUrlTest](../tests/Feature/MarketCanonicalUrlTest.php) 고정). 기존 파생 슬러그는 [dedupe 마이그레이션](../database/migrations/2026_07_22_003500_dedupe_market_analysis_slugs.php)으로 정리. ⚠️ **사용자 소유 데이터(순위추적 슬롯 등)엔 켜지 말 것** — 남의 공유 링크를 빼앗게 된다.
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

## AI 크롤러 파일 (llms.txt · ai.txt)

- **[public/llms.txt](../public/llms.txt)** — LLM용 사이트 개요. 공개 분석 리포트 URL 패턴(`/keyword/{키워드}` 등)·사이트맵·인용/출처 표기 안내 포함. `/llm.txt`(라우트 별칭)로도 접근.
- **[public/ai.txt](../public/ai.txt)** — AI 크롤러·생성엔진 정책(robots 유사 문법). 공개 콘텐츠 크롤·인용 허용 + 개인 영역(`/console·/admin·/order` 등) 제외 + 출처 표기 요청.
- **[public/robots.txt](../public/robots.txt)** — AI 봇 그룹(GPTBot·ClaudeBot·PerplexityBot·Google-Extended 등) 명시 허용 + `/ai.txt`·`/llms.txt` 참조. GEO/AEO 전략상 AI 인용을 **허용**하는 방향.

## AI 크롤러 집계 — UA 는 신뢰하지 않는다 (2026-08-02)

Generative Organic(AI 가 문서를 직접 읽어간 요청)은 [LogAiCrawler](../app/Http/Middleware/LogAiCrawler.php) 가 `ai_crawler_hits` 에 쌓고 [Ga4AiDiscoveryProvider](../app/Support/Ga4AiDiscoveryProvider.php) 가 대시보드로 보낸다.

- **User-Agent 는 위조가 자유롭다.** 자격증명 스캐너(`/.env`·`/.aws/credentials`·`/.git-credentials`)가 GPTBot 을 자처해 들어오며, 실제로 "AI 가 많이 읽어간 문서" 상위를 차지했다. **UA 매칭만으로 AI 유입이라 부르면 안 된다.**
- **판정 기준은 응답 상태코드다.** 본문을 내려준 **2xx 만 '읽어간 것'으로 센다**(`scopeSucceeded`). 4xx 는 기록은 하되 집계에서 빼고(`scopeFailed`) 규모만 대시보드에 한 줄로 보여준다.
- 상태코드를 남기려면 응답 생성 **뒤에** 기록해야 하므로 미들웨어는 `$next()` 이후에 쓴다. 유일키는 `(hit_date, bot, path, status)` — 같은 경로라도 200 과 404 는 다른 사건이다.
- **IP·역DNS 검증은 도입하지 않았다.** 요청마다 확인 비용이 붙고 캐시가 필요한데, 상태코드 필터만으로 지표 오염이 걷힌다. 위조 UA 자체를 차단해야 할 만큼 양이 늘면 그때 올린다.
- 마이그레이션 이전 행은 상태를 알 수 없어 200 으로 남는다 — **과거 구간 오염은 소급 교정되지 않는다.**

## 취약점 탐침 IP 자동 차단 (2026-08-02)

봇 UA 로 위장해 `/.env`·`/.aws/credentials`·`/.git-credentials` 를 훑는 요청은 자격증명 스캔이다. 해당 IP 를 기록하고 일정 시간 차단한다.
구현: [BlockProbeIps](../app/Http/Middleware/BlockProbeIps.php) · [BlockedIp](../app/Models/BlockedIp.php) · [config/security.php](../config/security.php) · 운영 명령 `security:blocked-ips`

- **판정은 경로로만 한다(UA 무관).** 스캐너가 늘 봇 UA 를 쓰는 것도 아니고, 사람이 브라우저로 이 경로를 요청할 일은 없다. 한 번만 걸려도 차단한다.
- **미들웨어 맨 앞**(`prepend`)에 둔다 — 차단된 IP 는 리다이렉트·세션 비용도 쓰지 않는다.
- **영구 차단하지 않는다.** 기본 24시간(`SECURITY_PROBE_BLOCK_HOURS`). 스캐너는 IP 를 갈아타므로 영구 목록은 커지기만 하고, 통신사 NAT 처럼 공유 IP 를 잘못 잡았을 때 피해도 시간으로 제한된다. 만료분은 매일 05:20 `--prune` 으로 정리.
- 차단 목록은 캐시에 **문자열 배열로만** 담는다(60초) — 운영 database 캐시에 Eloquent 객체를 넣으면 깨진다.

**오차단이 서비스를 죽이는 두 지점 — 반드시 유지할 것:**

| 위험 | 결과 | 방어 |
|------|------|------|
| 🔴 `.well-known/` 을 점(dot) 경로로 뭉뚱그려 차단 | Let's Encrypt ACME 갱신 실패 → **사이트 전체 HTTPS 불통** | `safe_paths` 가 `patterns` 보다 우선. 패턴을 "모든 dotfile" 로 넓히지 말 것 |
| 🔴 CDN 프록시 뒤에서 엣지 IP 를 차단 | 그 엣지를 지나는 **모든 정상 사용자**가 403 | `trust_proxy_guard` — `CF-Connecting-IP`·`X-Forwarded-For` 가 있는데 `TrustProxies` 미설정이면 차단하지 않는다 |

현재 rankfree 는 `TrustProxies` 미설정 + **Cloudflare DNS-only** 라 `REMOTE_ADDR` 이 실제 클라이언트다(차단 성립). **Cloudflare 프록시(주황 구름)를 켜려면 `TrustProxies` 설정이 선행돼야 한다.**

- 운영자 IP 는 `SECURITY_NEVER_BLOCK_IPS`(쉼표 구분, 기본 `127.0.0.1,::1`) 에 넣는다. 기능 자체는 `SECURITY_PROBE_BLOCK=false` 로 끌 수 있다.
- 오차단 복구: `php artisan security:blocked-ips`(목록) · `--unblock=IP`(해제)

## 검색엔진 발행 알림 (2026-07-17)

허브 문서 발행 시 **공식 지원 경로로만** 검색엔진에 알린다. 구현: [SearchEnginePing](../app/Domain/Seo/SearchEnginePing.php) · [config/seo-ping.php](../config/seo-ping.php)

- **폐기된 구글 sitemap ping(`google.com/ping`) 은 쓰지 않는다**(2024-01부터 404). **Indexing API 도 쓰지 않는다**(채용공고·라이브방송 전용 — 일반 콘텐츠는 정책 위반).
- **IndexNow**(네이버·빙·얀덱스 공식 참여): 발행된 문서 URL + `/keywords` + 해당 카테고리 페이지를 `api.indexnow.org` 로 일괄 제출. 한 번 보내면 참여 엔진 전체에 전파.
  - 키: `.env INDEXNOW_KEY`(영숫자 16~64자). 키 파일 `/{key}.txt` 는 라우트가 자동 서빙(web.php `indexnow.key`) — 파일 업로드 불필요. **운영 .env 에도 키 추가 필요**(로컬과 같은 키 사용 가능).
  - 네이버 반영엔 서치어드바이저에 사이트 등록이 선행되어야 함.
- **구글**: 발행 직후 사이트맵 캐시 버전 bump(새 URL·lastmod 즉시 반영) + Search Console API `sitemaps.submit` 으로 사이트맵 재제출 — 폐기된 ping 의 공식 대체.
  - 쓰기 스코프(`auth/webmasters`) 필요 — OAuth 연동 스코프를 readonly→쓰기로 변경([GoogleConnectController](../app/Http/Controllers/Admin/GoogleConnectController.php)). **기존(readonly) 연동 계정은 재연동해야 재제출까지 동작**(조회는 계속 동작).
  - [GoogleToken](../app/Support/GoogleToken.php) 스코프 판정을 부분 문자열→**정확 매칭**으로 교정(readonly 연동이 쓰기 요청에 오탑승해 403 나던 결함). 풀 스코프는 같은 API 의 `.readonly` 요청을 충족.
- **훅 위치**: 관리자 [지금 발행](../app/Http/Controllers/Admin/KeywordHubController.php)·[hub:publish](../app/Console/Commands/HubPublish.php) 의 발행 루프 **완료 후 1회 배치 호출**(`afterHubPublish`). `hub:refresh` 갱신은 알리지 않는다(사이트맵 lastmod 로 충분 — 대량 반복 제출 방지). 실패해도 발행 흐름은 막지 않고 로그+메시지만.
- **수동 실행**: `php artisan seo:ping [--url=…]` — 최초 등록·점검용(사이트맵 재제출 + 지정 URL IndexNow).
- 끄기: `SEO_PING_ENABLED=false`(전체) · `SEO_PING_GSC_SITEMAP=false`(구글만).

## 주의

- `include_analyses`(config) off 로 분석 슬러그 전체를 사이트맵에서 뺄 수 있음.
- 슬러그 소재(키워드·업체명·상품명)에 개인정보가 섞일 수 있으니, 공개 섹션에 넣는 1회성 분석도 소재 성격을 주기적으로 점검.
- 라우트 이름은 유지(keyword.shared 등) — 코드에서 `route('*.shared', ...)` 대신 `$model->shareUrl()`/`competeUrl()` 사용.
