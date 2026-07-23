# 22. 키워드 콘텐츠 허브 — 카테고리 → 키워드 → 분석 문서 → 상호 추천

> 카테고리별로 키워드를 모으고, 키워드마다 SEO/AEO/GEO 최적화 분석 문서를 발행하며, 문서끼리 **아고다식으로 서로 추천**해 문서 하나하나가 살아있는 검색 랜딩이 되게 한다. (2026-07-16 설계 · **Phase 0~3 구현 완료**)

## 컨셉

아고다의 '제주도 호텔' 페이지처럼 — 문서 하나가 ① 검색 유입 랜딩이면서 ② 주변 여행지·렌터카·음식점처럼 **관련 문서·서비스로 이어지는 허브** 역할을 한다.

- **퍼널**: 검색/AI 유입(키워드 문서) → 관련 문서 탐색(내부 링크 그물) → 무료 분석 체험 → 회원가입 → 마케팅 중개
- **개별 문서의 의미**: 실측 데이터(검색량·성별연령·트렌드·발행량·시장규모)가 본체 — 얇은 AI 문서가 아니라 데이터 리포트

```
카테고리(keyword_categories)
  → 키워드 후보 수집·승인(keyword_candidates)
    → 발행(KeywordSearch origin=hub + 분석 snapshot)
      → 문서(/keyword/{슬러그}) ⇄ 상호 추천(RelatedDocsService)   ← Phase 0 구현됨
        → 사이트맵/robots(21) → GSC 성과 피드백(20)
```

## Phase 0 — 관련 문서 추천 (✅ 2026-07-16 구현·검증 완료)

공개 색인 문서 5종(`/keyword /market /product /seller /store`) 하단에 **"함께 보면 좋은 분석"** 섹션.

- [app/Domain/Seo/RelatedDocsService.php](../app/Domain/Seo/RelatedDocsService.php) — `sectionsFor($doc, $extraKeywords)`
  - 매칭: 슬러그 소재(키워드·상호·상품명)를 토큰화(한글/영문 2자+, 공백제거 전체 문구 포함, 최대 6개) → 5종 모델 컬럼 LIKE + `$extraKeywords` 정확일치(`orWhereIn`)
  - `$extraKeywords`: 키워드 문서는 `vm.related`(연관키워드), 매장/셀러력 문서는 분석 `keyword`
  - 같은 타입 최대 6·교차 타입 3, **제목 기준 중복 제거**(타 사용자 동일 키워드 저장분 합침), 자기 자신 제외
  - 같은 타입은 매칭 없으면 제목을 "최신 …"으로 바꾸고 최근·인기(키워드는 `monthly_total` 순) 문서로 채움(빈 섹션 방지). **교차 타입은 매칭분만**(무관 문서 안 섞음)
  - 캐시 `related:v1:{prefix}:{id}:{terms md5}` 6h
- ⚠️ **추적 슬롯 3종(PlaceRankSlot·ShopRankSlot — /place /compete /shopping)은 어떤 경우에도 추천 소스 제외**(21의 비공개 원칙 — 사용자 추적 대상 노출 금지)
- 뷰: [resources/views/partials/related-docs.blade.php](../resources/views/partials/related-docs.blade.php) — 타입 배지 + 제목(앵커텍스트 "{키워드} 키워드 분석" 형태) + 지표 메타(`font-mono`) 카드 그리드
- 연결: 각 컨트롤러 `shared()`(Keyword/Market/Product/SellerPower) + `/store` 클로저([routes/web.php](../routes/web.php))
- 검증: [tests/Feature/RelatedDocsTest.php](../tests/Feature/RelatedDocsTest.php) 4건(크로스 추천·추적슬롯 제외·폴백·중복 제거) + Playwright 실화면 4종(store/market/product/seller) 확인
- **한계(후속에서 해소)**: LIKE 토큰 매칭이라 동의어·붙여쓰기 변형 인식 한계, 패딩 문서가 "관련" 제목 아래 섞일 수 있음 → Phase 2 카테고리 도입 시 "같은 카테고리 인기 문서"로 대체

## Phase 1 — 카테고리 분류 + 키워드 수집 파이프라인 (✅ 2026-07-16 구현·검증 완료)

### 데이터 모델

| 테이블 | 컬럼 | 비고 |
|---|---|---|
| `keyword_categories` | type(place\|shopping) · parent_id · name · slug(unique) · description · seed_keywords(json) · sort · is_active · **collected_at**(수집 로테이션 커서) | 2계층(대분류>소분류) |
| `keyword_candidates` | category_id · keyword · source(seed\|related\|autocomplete\|user) · monthly_total(자동완성은 null=미상) · comp_idx · status(pending\|approved\|rejected\|published) · note | (category_id, keyword) unique |
| `keyword_searches` 확장 | +category_id(nullable) · +origin('user'\|'hub') · +refreshed_at · user_id nullable화 | **허브 문서 = KeywordSearch 재사용**. 유일성은 코드에서 (origin=hub, keyword) `updateOrCreate` |

- 마이그레이션: `2026_07_16_000010~000012`. 모델 [KeywordCategory](../app/Models/KeywordCategory.php) · [KeywordCandidate](../app/Models/KeywordCandidate.php)
- 카테고리 시드(운영 입력 가이드): 플레이스 = pcmap 6종 × 지역 변형(강남 맛집…) / 쇼핑 = 네이버 1depth + `shop_rank_slots.category` 실측값 활용
- `buildAnalysis()` 는 [KeywordReportBuilder](../app/Domain/Keyword/KeywordReportBuilder.php) 로 추출 — 콘솔(index)·공유(shared)·허브 발행 3곳 공용(동작 동일)

### 대량 시드 생성기 2종 (✅ 2026-07-16 추가)

- **hub:place-seed** ([HubPlaceSeed](../app/Console/Commands/HubPlaceSeed.php)) — 플레이스 지역×업종 조합 생성기. pcmap 업종 6종별 카테고리(맛집·음식점/병원·의원/헤어샵/네일·뷰티/숙박·여행/생활·플레이스) × "{지역} {패턴}"(강남 치과, 성수동 맛집, 제주도 호텔 …) 후보(source=combo).
  - **지역 풀** = 큐레이션([place_keyword_matrix.php](../database/data/place_keyword_matrix.php): 핫플레이스 69·여행지 53·구 33·시 70·주요 동 150) + **전국 행정구역**([regions_kr.php](../database/data/regions_kr.php): 시군구 364·읍면동 2,436 — [scripts/generate-regions-kr.php](../scripts/generate-regions-kr.php) 가 행정동 GeoJSON(vuski/admdongkor)에서 자동 생성, "역삼1동"→"역삼동" 검색형 정규화)
  - **패턴 128종**(맛집 40·병원 26·생활 34·숙박 12·헤어 8·네일 8) → **조합 가능 총량 343,932개**(실측 계산)
  - 대량 처리: 기존 키워드 사전 로드 + 500건 배치 insert(3만 건 수 초). `--limit`(기본 2,000)씩 증분. **검색량 미상(pending) → 발행 시 분석이 볼륨 판정(없으면 자동 보류)** 이라 저품질 대량 발행이 안 된다
- **hub:shopping-collect** ([HubShoppingCollect](../app/Console/Commands/HubShoppingCollect.php) · [NaverDataLabShoppingService](../app/Domain/Keyword/NaverDataLabShoppingService.php)) — **데이터랩 쇼핑인사이트**(비공식·무로그인, referer+XHR+전체 UA 필수) 분야별 인기검색어 수집. 1·2분류를 KeywordCategory 로 동기화(`naver_cid` 매핑, 2026_07_16_000020), 2분류+3분류 인기검색어를 **소속 2분류 카테고리** 후보(source=datalab)로 적재(트리는 2계층 유지).
  - **분야당 최대 500위(25페이지 × 20개, 실측 26페이지부터 빈 배열)** — 기본 `config rankfree.hub.datalab_pages=25`
  - ⚠️ **429 레이트리밋**(실측: 150ms 간격 연속 요청 차단) — 페이지 간 600ms + 429 백오프(4·8·12s) 재시도, **부분 수집은 캐시하지 않음**(완주만 12h 캐시). 전체 실행(1분류 10개 × 2·3분류 × 25p)은 수 시간 — 주 1회 크론(월 06:50)
- **운영 도구(대량 후보)**: 승인 큐 **키워드 검색(q)** + **필터 전체 일괄 승인/거부/삭제**(`bulk-all` — 페이지 선택이 아니라 상태·카테고리·출처·검색어 필터 전체에 적용, SweetAlert 확인)
- 검증: [tests/Feature/KeywordHubSeedTest.php](../tests/Feature/KeywordHubSeedTest.php) 7건(트리·페이지 누적·전국 풀 2만+·일괄 처리) + 실 API(패션잡화 500위 심층 수집·조합 3.1만 건 생성) + 관리자 큐 Playwright

### 파이프라인 (크론 3종 + 서비스)

1. **hub:collect** ([HubCollect](../app/Console/Commands/HubCollect.php) · [KeywordHubCollector](../app/Domain/Keyword/KeywordHubCollector.php)) — 카테고리를 `collected_at` 오래된 순으로 N개 로테이션(기본 3): 시드 → keywordstool 연관 + 자동완성 → candidates upsert(pending). **자동 필터**: 길이 2~60자 · `min_volume`(기본 월 1,000) 미만 제외(시드는 면제 — 운영자 의도 존중, 자동완성 등 볼륨 미상은 pending 통과) · `banned_patterns` 정규식 · 기발행(origin=hub) 제외. 재수집 시 승인/거부 **상태는 보존**하고 지표만 갱신
2. **hub:publish** ([HubPublish](../app/Console/Commands/HubPublish.php) · [KeywordHubPublisher](../app/Domain/Keyword/KeywordHubPublisher.php)) — 승인 후보를 검색량 큰 순으로 상한(기본 10)만큼 발행: `KeywordReportBuilder::build()` → `KeywordSearch(origin=hub, user_id=NULL, category_id, snapshot, refreshed_at)`. **thin content 방지**: has_volume 없으면 발행하지 않고 rejected + '데이터 부족' 사유
   - **허브 목록의 쇼핑 키워드 링크는 시장분석(/market) 우선**(2026-07-22): `KeywordSearch::publicUrl()` — 같은 키워드의 MarketAnalysis 가 있으면 `/market/{slug}`, 없으면 키워드 문서 폴백(슬러그 6h 캐시). 카드 라벨은 `publicLabel()`('시장 분석'/'키워드 분석'). 공개 화면·AEO 문구의 '네이버' 단어는 전부 제거(2026-07-22, /keywords·/keyword·공용 헤더푸터·Presenter)
   - **쇼핑 시장 분석은 확장 플로 수집 데이터로만**(사용자 확정 2026-07-22): 발행은 `latestMarketSource()`(확장 수집 MarketAnalysis) 복제 방식만. 서버 SERP(keyword_shop_ranks) 기반 자동 생성은 판매량·매출이 빠진 껍데기라 **금지** — 소스 없으면 '확장 수집 데이터 필요' 보류. 한때 있던 `hub:shopping-market-backfill`(서버 생성 백필)은 같은 이유로 제거·운영 생성분(~323건) 삭제 정리
   - **확장 벌크 수집 → 시장분석 대량 생성**(2026-07-22, 확장 v0.3.7+): rfcollect 상품에 이미 있던 `purchase6m`(6개월 구매건수)·`revenue6m`(카탈로그 보강 매출)·mallGrade·category 를 background 가 서버로 전달 → `POST /api/ext/keyword-shop-serp` 가 SERP 저장에 더해 [MarketAnalysisFromSerp](../app/Domain/Shopping/MarketAnalysisFromSerp.php)(C1 computeMarket 서버 포트, 광고 제외 오가닉 기준)로 **수집 유저 소유 시장분석을 키워드당 1건 생성/갱신** → 허브 발행이 이를 복제. 구버전 확장(purchase6m 필드 없음) 수집은 시장분석을 만들지 않는다(껍데기 방지). 검증: [ExtBulkMarketAnalysisTest](../tests/Feature/ExtBulkMarketAnalysisTest.php) 3건 + 실확장 로드 E2E(파서→매핑→서버 계산→발행→/market 렌더)
3. **hub:refresh** ([HubRefresh](../app/Console/Commands/HubRefresh.php)) — `refresh_after_days`(기본 30일) 지난 문서를 오래된 순 상한(기본 20)만큼 재수집 → 사이트맵 lastmod 갱신 효과. 볼륨이 안 나오면 기존 스냅샷 유지·커서만 전진

- **스케줄**: [routes/console.php](../routes/console.php) — **발행과 발굴을 분리**한다.
  - **발행(hub:publish)**: 관리자 승인분만 처리(도어웨이·쿼터 리스크 없음) → **기본 on**(`hub.publish_enabled`, `.env HUB_PUBLISH_ENABLED=false` 로 끔). `hub.publish_interval`(분, 기본 10)마다 실행해 승인 후보 ≤`publish_per_run`(10) 발행. **승인 큐가 빌 때까지 자동으로 계속 드레인**하고, 없으면 idle. `withoutOverlapping`.
  - **발굴(hub:collect 06:10 · hub:discover 06:20 · hub:refresh 06:40 · hub:shopping-collect 월 06:50)**: 후보 대량 생성이라 쿼터 보호로 **기본 off** — `.env HUB_SCHEDULE_ENABLED=true` 로 활성.
  - ⚠️ 전제: 서버에 `* * * * * php artisan schedule:run` 크론이 매분 돌아야 자동 발행이 동작한다. 상한·간격은 `config('rankfree.hub.*')`
- **관리자** `/admin/keyword-hub` ([KeywordHubController](../app/Http/Controllers/Admin/KeywordHubController.php) · [index.blade.php](../resources/views/admin/keyword-hub/index.blade.php)) — 카테고리·시드 인라인 CRUD, 후보 큐(상태 탭·카테고리 필터·대량 승인/거부/삭제), 지금 수집(동기 1카테고리)·지금 발행(≤10건), 최근 발행 문서. **메뉴는 /admin/menus 에서 수동 등록**(시더 금지 규칙)
- 검증: [tests/Feature/KeywordHubTest.php](../tests/Feature/KeywordHubTest.php) 8건(수집 필터·상태 보존·발행/보류·볼륨순 상한·권한·승인 큐) + **Playwright E2E**(실 API): 시드 '캠핑의자' 1개 → 후보 207건 수집(필터 729건) → 승인 2건 → 발행 2건(`/keyword/캠핑의자` 월 52,700) → 문서 렌더 + Phase 0 추천 블록 확인

## Phase 2 — 카테고리 허브 페이지 + 문서 강화 (✅ 2026-07-16 구현·검증 완료)

> ⚠️ **허브 IA 는 2026-07-17 재편됐다 — 최신 기준은 아래 Phase 4.** `/keywords` 는 더 이상 카테고리를 나열하지 않는다(검색 진입점).

- **공개 허브** ([KeywordInsightController](../app/Http/Controllers/KeywordInsightController.php)):
  - `/keywords` ([keywords/index.blade.php](../resources/views/keywords/index.blade.php)) — ~~대분류>소분류 카드~~ → Phase 4 에서 검색 진입점으로 전환
  - `/keywords/{slug}` ([keywords/category.blade.php](../resources/views/keywords/category.blade.php)) — 문서 목록(검색량순, 24 페이징) + 검색·지역 필터 + 하위/형제 카테고리 + CTA + BreadcrumbList·CollectionPage(ItemList) JSON-LD. **대분류는 하위 카테고리 문서 합산**. 비활성/미존재 404
    - **헤더는 3요소만**(2026-07-17): 브레드크럼 → h1 → 한 줄 설명. 집계 카드 3종(리포트 수·검색량 합계·기준)과 타입 배지("플레이스 키워드 인사이트")는 **제거** — 브레드크럼·h1과 같은 말을 4줄 반복해 가독성이 떨어졌다. 문서 수(`docTotal`)는 화면 비표시, ItemList `numberOfItems`·메타 설명에만 사용
    - 지역 필터 시 h1 에 지역 반영("가경동 맛집·음식점 키워드 인사이트"). `?region=`·`?q=` 변형은 canonical(`url()->current()` — 쿼리 제외)이 기본 카테고리 URL 로 정규화하므로 색인 중복 없음
  - 헤더 내비 '분석 도구 > 키워드 인사이트' 링크([site-header](../resources/views/partials/site-header.blade.php))
- **사이트맵 `keywords` 섹션** ([SitemapController](../app/Http/Controllers/SitemapController.php)) — `/keywords` + 발행 문서 있는 활성 카테고리(lastmod=문서 최신 refreshed_at). 발행 문서 0이면 섹션 미노출. ⚠️ 인덱스 캐시는 버전 키 — 신규 섹션은 `sitemap:refresh`(매일 05:40) 후 반영
- **문서(keyword.share) SEO/AEO/GEO 강화** ([share.blade.php](../resources/views/keyword/share.blade.php)):
  - AEO "요약 답변" 카드 — [KeywordAnalysisPresenter::aeo()](../app/Domain/Keyword/KeywordAnalysisPresenter.php) **데이터 기반 결정적 템플릿**(LLM 아님): 검색량·경쟁·등급 + insights 요약(주 타겟·시즌) 문장 재사용
  - FAQ 확장 — 검색량(항상)·많이 찾는 시기(season)·성별연령(demo)·경쟁강도, **데이터 있는 문항만** 생성. 화면 FAQ = FAQPage JSON-LD 동일 문항(aeo() 단일 소스)
  - GEO — 출처("네이버 검색광고·데이터랩 기반 자체 집계")·기준일(refreshed_at)·자체 추정 고지
  - 브레드크럼 — 홈 > 키워드 인사이트 > {카테고리} > {키워드}: 가시 링크 + BreadcrumbList([report-seo](../resources/views/partials/report-seo.blade.php) `seoCrumbs` 파라미터 신설, 타 문서 5종은 기존 동작 유지)
  - 퍼널 CTA — '무료로 시작'(로그인 시 콘솔) 1개(pill, 희소성 유지)
- **추천 고도화** — [RelatedDocsService](../app/Domain/Seo/RelatedDocsService.php): 허브 키워드 문서는 **"'{카테고리}' 카테고리 인기 키워드" 섹션을 맨 앞에**(검색량순) + **페이지 전체(섹션 간) 제목 중복 제거**. 지역 변형(강남→서초) 추천은 Phase 3 과제로 이월
- 검증: [tests/Feature/KeywordInsightTest.php](../tests/Feature/KeywordInsightTest.php) 6건 + [tests/Unit/KeywordAeoTest.php](../tests/Unit/KeywordAeoTest.php) 2건 + **Playwright**(실 API, 로컬): /keywords·/keywords/캠핑용품·/keyword/캠핑의자(AEO 요약·FAQ·브레드크럼·카테고리 추천·CTA)·사이트맵 섹션 전부 확인

## Phase 4 — 타입 우선 IA(검색 진입 + 카테고리 메뉴 분리) (✅ 2026-07-17 구현·검증 완료)

> 배경: `/keywords` 가 카테고리를 통째로 나열해 "너무 복잡"했다. 설계안 3종(미니멀검색·타입우선·색인보존)을 4개 렌즈(디자인시스템·IA·SEO·구현리스크)로 심사해 **타입 우선안** 채택 + 각 안의 강점 이식.

### 라우트 (순서 의존 — routes/web.php)

```php
Route::get('/keywords',        …'index');                       // 검색 진입점
Route::get('/keywords/search', …'search');                      // 결과(noindex, follow)
Route::get('/keywords/{type}', …'typeHome')->whereIn('type', ['place','shopping']);
Route::get('/keywords/{slug}', …'category');                    // 한글 슬러그 — 위 3개 뒤
Route::get('/api/keywords/suggest', …)->middleware('throttle:60,1');   // routes/api.php
```
- **고정 경로는 반드시 `{slug}` 앞**. `{type}` 은 `whereIn` 제약이라 `/keywords/맛집-음식점` 은 통과해 카테고리로 내려간다. 회귀는 `test_static_routes_win_over_category_slug` 가 고정.
- 2중 방어: [KeywordCategory::RESERVED_SLUGS](../app/Models/KeywordCategory.php) = `place·shopping·search` → `makeSlug()` 가 `-cat` 접미(예: `place-cat`). 운영 배포 전 `select slug from keyword_categories where slug in ('place','shopping','search')` 확인(로컬 0건).

### 페이지

| URL | 내용 | 색인 |
|---|---|---|
| `/keywords` | h1 → 1줄 설명 → **세그먼트(전체/플레이스/쇼핑) + 큰 pill 검색창(56px)** → 인기 칩 → **타입 카드 2장(=카테고리 메뉴 진입)** → 인기 리포트 12 → CTA. **카테고리 나열 없음** | index + `WebSite.potentialAction=SearchAction` |
| `/keywords/place` | **업종 칩 1줄**(전체/맛집·음식점/…) + **지역 3단계 드릴다운**(시/도 → 시/군/구 → 동·상권) → 고른 지역의 키워드 목록(24 페이징). 업종 카드·건수 표기·"지역으로 찾기" 블록은 제거(2026-07-17) | index (해당 타입 문서 0건이면 `noindex, follow`) |
| `/keywords/shopping` | **대분류 섹션 + 소분류 텍스트 인덱스**(다열 `column-width:220px`, 카드 아님) + 인기 리포트 | index (동일 가드) |
| `/keywords/search?q=&type=` | 매칭 **카테고리 블록(결과 위 — 색인 자산으로 되돌리는 깔때기)** + 문서 카드 24 페이징 + 0건 폴백(타입 유지) | **`noindex, follow`** + 정규화 자기참조 canonical |
| `/keywords/{slug}` | (기존) + **타입 브레드크럼**·공용 검색바 | index (`?q=` 있으면 `noindex, follow`) |
| `/api/keywords/suggest` | 접두 우선 매칭 8 + 카테고리 3, 5분 캐시 | `X-Robots-Tag: noindex` |

- **타입별 나열이 다르다**(사용자 요구): 플레이스=업종 플랫 카드 + 지역 축 / 쇼핑=대>소분류 텍스트 인덱스. 쇼핑 `<details>`·즉시 필터는 **v1 미도입**(실측 L1당 L2=18 — 없는 문제). L1당 L2 > 40 에서 재검토.
- ⚠️ **소분류 링크를 JS lazy-fetch 로 '최적화'하지 말 것** — 내부 링크 그물이 통째로 사라진다.
- 세그먼트·검색바는 [keywords/_searchbar.blade.php](../resources/views/keywords/_searchbar.blade.php) 공용. 세그먼트는 **항상 `<a href>`**(+`aria-current`), 자동완성은 JS 없으면 사라지고 **폼 제출로 동작**(progressive enhancement, Playwright 로 검증).
- **모든 공개 쿼리에 `origin='hub'` 강제** — 빠지면 타 사용자 검색 내역(origin=user)이 검색·자동완성에 노출된다(21). 회귀 테스트 2건이 고정.

### 이번에 고친 실제 결함

- **canonical 이중 이스케이프**([seo.blade.php](../resources/views/partials/seo.blade.php)): Blade `@section('canonical', $url)` 은 내용을 `e()` 로 저장 → 파셜이 `{{ }}` 로 재이스케이프 → 쿼리 2개 이상 URL 이 `&amp;amp;` 로 깨짐(기존엔 `?cat=qna` 처럼 파라미터 1개뿐이라 무증상). 파셜에서 `html_entity_decode` 후 출력하도록 수정 — 전 페이지 공통 이득.
- 검색 0건 폴백이 타입 필터를 무시해 플레이스 검색에 쇼핑 리포트가 섞이던 문제(테스트가 검출).
- **금지**: `noindex` + 타 URL canonical 조합(되돌리기 가장 비싼 사고). utm 은 canonical 에 싣지 않되 **리다이렉트로 벗기지도 않는다**(GA/GSC 캠페인 귀속 소실).

### 사이트맵

`keywords` 섹션 = `/keywords` + **타입 홈(문서 있는 타입만)** + 카테고리. `/keywords/search`·suggest 는 **절대 미포함**. `keywordHubCategories()` 는 `type` 까지 select.

### 이월 과제

- **지역 축 색인 미스매치**: 플레이스의 실질 2축은 지역인데 `?region=` 은 canonical 병합이라 색인 자산 0. `/keywords/{slug}/{region}` 승격은 GSC(20) 노출 상위 50 조합만 선별 승격하는 경로로 판단.
- **롤백 기준**: `/keywords` 에서 카테고리 덤프를 뺀 뒤 GSC 로 4~8주 추적 — 노출/클릭 하락 시에만 요약 블록 복원.

## Phase 3 — 자동화·확장 (✅ 2026-07-16 구현·검증 완료)

- **AI 인사이트(선택 보강)** — [KeywordAiInsight](../app/Domain/Keyword/KeywordAiInsight.php): 발행/갱신 시점에 aeo() 사실 목록을 근거로 Gemini(→Claude 폴백, `services.gemini/anthropic` 키 재사용) 호출 → `snapshot.ai_insight` 저장. **열람 시 LLM 재호출 없음**(shared 는 저장분만 표시). 프롬프트로 새 수치·과장 금지 강제, 화면엔 "AI 생성" 배지 + 참고용 고지. 키 없으면 조용히 생략(문서는 AI 없이도 완결)
- **크로스 카테고리 추천 정밀화** — [RelatedDocsService::resolveCategory()](../app/Domain/Seo/RelatedDocsService.php): 시장/셀러력/매장 문서도 **같은 키워드의 허브 문서를 찾아 그 카테고리의 인기 키워드 섹션**을 맨 앞에 표시(스키마 변경 없이 읽기 시점 해석·6h 캐시). market/store 에 category_id 컬럼을 직접 두는 설계는 보류(불필요해짐)
- **GSC(20) 피드백 루프**:
  - [hub:discover](../app/Console/Commands/HubDiscover.php) — 최근 28일 GSC 쿼리 중 노출 ≥ `discover_min_impressions`(기본 30)이고 허브 문서/후보에 없는 검색어 → `.env HUB_DISCOVER_CATEGORY`(카테고리 슬러그) 카테고리에 **source=gsc pending 후보**로 적재(미설정 시 건너뜀). 스케줄 06:20(gsc:collect 04:00 이후)
  - [hub:refresh](../app/Console/Commands/HubRefresh.php) 우선순위 — 갱신 주기 지난 문서 중 **GSC 페이지 클릭(최근 28일) 많은 순 → 오래된 순**(한글 슬러그 URL 인코딩/원문 모두 매칭). 실제 유입 있는 문서가 먼저 신선해진다
- 검증: [tests/Feature/KeywordHubPhase3Test.php](../tests/Feature/KeywordHubPhase3Test.php) 6건(Gemini Http::fake·키 없음 생략·스냅샷 저장·문서 표시·크로스 카테고리·discover 필터·refresh 우선순위) + **Playwright**: '선풍기' 후보 실발행(슬러그 충돌 시 `-2` 자동 처리 확인) → `/keyword/선풍기-2` AI 카드 렌더 + `/market/선풍기` 에 "'생활가전' 카테고리 인기 키워드" 크로스 섹션 확인
- **남은 과제(후속)**: 지역 키워드 인근 지역 변형(강남→서초·역삼) 추천 — 지역 인접 매핑 필요, 별도 설계 후 진행

## 품질 가드레일 (중요)

- **도어웨이 스팸 방지**: 실데이터 충분한 문서만 발행, 일 발행 상한(기본 50), GSC로 색인 품질 모니터링. 대량 저품질 발행은 도메인 전체 평가를 깎는다
- **추적 슬롯·개인정보**: 추천/색인 어디에도 추적 슬롯 노출 금지(21 원칙). 업체명·상품명이 소재인 시드는 후보에서 제외
- 점수·추정치는 "자체 추정" 표기(N1/N2/N3·D 규칙과 동일 톤)

## 결정 사항

- 허브 문서는 새 모델이 아니라 **KeywordSearch 재사용**(origin=hub) — 공유 슬러그·사이트맵(keyword 섹션)·분석 파이프라인·공유 뷰를 그대로 씀
- 문서 본문은 1차 **결정적 템플릿**(데이터→문장), LLM은 선택 보강만
- 추천은 실시간 쿼리 + 6h 캐시(사전 계산 컬럼 없음) — 문서 수가 커지면(1만+) 재검토
- **규모 대응은 샤딩이 아니라 파티션 로테이션 + 인덱스**(2026-07-18 결정): 순위 매핑(keyword_place_ranks·keyword_shop_ranks)은 이미 마스터 정규화+월별 RANGE 파티션. 여기에 `hub:partition-rotate`(매일 05:50 KST)가 이번 달~2개월 뒤 파티션 선생성(pmax REORGANIZE) + 보존 개월 수(`HUB_RANK_RETENTION_MONTHS`, 기본 13, 0 이하=파기 안 함) 지난 월 DROP PARTITION. sqlite(로컬/테스트)는 DELETE 폴백. 공개 허브 인기순은 `ks_origin_vol(origin, monthly_total)` 인덱스가 filesort 제거
- **공유 페이지 첫 로드는 요청 안에서 외부 크롤 금지**(2026-07-23): /market 첫 열람 6초+ 실사고 — ① RelatedDocsService LIKE 매칭이 PRIMARY(평균 17KB 행) 풀스캔을 타던 것을 **2단계 조회**(무정렬 id 커버링 스캔 13~35ms + whereIn 경량 컬럼 로드, `COLS` 상수)로 수정(ORDER BY id 를 SQL 에 두면 옵티마이저가 PRIMARY 를 고른다 — 실측 2.2s), ② 시장분석 keyword_data 보강(검색광고 크롤, 실측 15초)은 `EnrichMarketKeywordData` 큐 잡으로 — 열람 시 `ensureAsync()` 가 잡만 예약(15분 중복 가드)하고 즉시 렌더, 요일 비율(데이터랩 24h 캐시)도 잡에서 예열. 실측: 콜드 1.4~1.8s → 워밍 후 46~116ms
- **쇼핑 수집 조회수 게이트**(2026-07-23 사용자 확정): 수집 대기열이 **검색량부터 확인**해 월 조회수 10 이하·무데이터(keywordstool 응답 생략) 키워드는 **후보 리스트에서 삭제**하고 수집하지 않는다(플레이스 발행 has_volume 거부와 짝). `ExtKeywordShopSerpController::volumeGate()` — 미상은 keywordstool 5개 배치로 조회해 후보에 저장(volume_checked_at), 청크 통실패(API 장애)는 삭제 없이 통과. 픽 쿼리·remaining 카운트도 `monthly_total > 10 OR null` 조건. 기존 저조 후보 105건은 2026-07-23 일괄 삭제

## 코드 (Phase 0)

| 파일 | 역할 |
|---|---|
| [app/Domain/Seo/RelatedDocsService.php](../app/Domain/Seo/RelatedDocsService.php) | 관련 문서 추천 — 매칭·중복제거·캐시 |
| [resources/views/partials/related-docs.blade.php](../resources/views/partials/related-docs.blade.php) | "함께 보면 좋은 분석" 카드 그리드 |
| [tests/Feature/RelatedDocsTest.php](../tests/Feature/RelatedDocsTest.php) | 크로스 추천·추적슬롯 제외·폴백·중복 제거 |
| 수정: KeywordAnalysis/Market/Product/SellerPower 컨트롤러 `shared()` · [routes/web.php](../routes/web.php) `/store` 클로저 · share 뷰 5종 | 추천 데이터 주입 + 블록 include |

## 관리자 화면 분리 — 발행 전용 허브 + 후보·수집 관리 (2026-07-17)

`/admin/keyword-hub` 가 대량 시딩(후보 4.6만+) 이후 화면 폭주해 역할을 둘로 나눴다.

- **`/admin/keyword-hub` = 발행 전용**: 후보 현황(상태별 카운트 → 관리 페이지 링크) · **연속 발행** · 최근 발행 문서.
  - 연속 발행: [발행 시작] → JS 루프가 `POST keyword-hub/publish-batch`(요청당 **1건** — 웹 타임아웃 회피)를 반복 호출, 발행/보류/남은 승인 진행 표시. [중단] 은 진행 중인 1건까지만 하고 멈춘다. 승인 소진(remaining=0) 시 자동 완료. 발행 성공 배치마다 검색엔진 알림(21, SearchEnginePing) 동작.
- **`/admin/keyword-hub/candidates` = 후보·수집 관리**: 후보 큐(상태·출처·카테고리·지역 필터, 선택/전체 일괄 처리) · 카테고리·시드 관리 · 수동 수집 실행.
- **시드 카드·수집 로테이션은 수동 카테고리(naver_cid 없음)만** — 데이터랩 쇼핑 1~3분류(2천여 개, hub:shopping-collect 자동 동기화)는 시드 카드로 펼치지 않고, 관리자 수집 버튼·hub:collect 크론 로테이션에서도 제외(시드가 없어 헛돎). 데이터랩 분류 조회는 키워드 탐색(admin.keyword-browse).
