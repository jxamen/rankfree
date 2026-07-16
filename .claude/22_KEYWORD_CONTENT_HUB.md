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

- **hub:place-seed** ([HubPlaceSeed](../app/Console/Commands/HubPlaceSeed.php)) — 플레이스 지역×업종 조합 생성기. pcmap 업종 6종별 카테고리(맛집·음식점/병원·의원/헤어샵/네일·뷰티/숙박·여행/생활·플레이스)를 만들고, [database/data/place_keyword_matrix.php](../database/data/place_keyword_matrix.php)(지역 ~380개: 핫플레이스·구·시·주요 동·여행지 큐레이션 × 업종 패턴 ~60개)를 조합해 "{지역} {패턴}"(강남 치과, 성수동 맛집, 제주도 호텔 …) 후보(source=combo)를 만든다. 조합 가능 총량 ~1.5만, `--limit`(기본 2,000)씩 증분 실행. **검색량 미상(pending) → 발행 시 분석이 볼륨 판정(없으면 자동 보류)** 이라 저품질 대량 발행이 안 된다. 전체 행정동 확장은 매트릭스 파일에 공공데이터 CSV 변환분을 붙이면 됨
- **hub:shopping-collect** ([HubShoppingCollect](../app/Console/Commands/HubShoppingCollect.php) · [NaverDataLabShoppingService](../app/Domain/Keyword/NaverDataLabShoppingService.php)) — **데이터랩 쇼핑인사이트**(비공식·무로그인, referer+XHR+전체 UA 필수) 분야별 인기검색어 수집. 1·2분류를 KeywordCategory 로 동기화(`naver_cid` 매핑, 2026_07_16_000020 마이그레이션), 2분류+3분류 인기검색어(최근 30일, 분야당 20×pages)를 **소속 2분류 카테고리** 후보(source=datalab)로 적재(트리는 2계층 유지). 카테고리 24h·랭킹 12h 캐시, 요청 간 딜레이. 스케줄: 주 1회(월 06:50, hub 활성 시). 실측: 패션잡화 1분류에서 신규 2,461건
- 검증: [tests/Feature/KeywordHubSeedTest.php](../tests/Feature/KeywordHubSeedTest.php) 4건 + 실 API 실행(데이터랩 2,461건·조합 1,000건) + 관리자 큐 Playwright(데이터랩/지역조합 배지·카테고리 트리 필터)

### 파이프라인 (크론 3종 + 서비스)

1. **hub:collect** ([HubCollect](../app/Console/Commands/HubCollect.php) · [KeywordHubCollector](../app/Domain/Keyword/KeywordHubCollector.php)) — 카테고리를 `collected_at` 오래된 순으로 N개 로테이션(기본 3): 시드 → keywordstool 연관 + 자동완성 → candidates upsert(pending). **자동 필터**: 길이 2~60자 · `min_volume`(기본 월 1,000) 미만 제외(시드는 면제 — 운영자 의도 존중, 자동완성 등 볼륨 미상은 pending 통과) · `banned_patterns` 정규식 · 기발행(origin=hub) 제외. 재수집 시 승인/거부 **상태는 보존**하고 지표만 갱신
2. **hub:publish** ([HubPublish](../app/Console/Commands/HubPublish.php) · [KeywordHubPublisher](../app/Domain/Keyword/KeywordHubPublisher.php)) — 승인 후보를 검색량 큰 순으로 상한(기본 10)만큼 발행: `KeywordReportBuilder::build()` → `KeywordSearch(origin=hub, user_id=NULL, category_id, snapshot, refreshed_at)`. **thin content 방지**: has_volume 없으면 발행하지 않고 rejected + '데이터 부족' 사유
3. **hub:refresh** ([HubRefresh](../app/Console/Commands/HubRefresh.php)) — `refresh_after_days`(기본 30일) 지난 문서를 오래된 순 상한(기본 20)만큼 재수집 → 사이트맵 lastmod 갱신 효과. 볼륨이 안 나오면 기존 스냅샷 유지·커서만 전진

- **스케줄**: [routes/console.php](../routes/console.php) — 수집 06:10 · 발행 매시 · 갱신 06:40(KST). **기본 off** — `.env HUB_SCHEDULE_ENABLED=true` 로 활성(검색광고 쿼터 보호). 상한·임계값은 `config('rankfree.hub.*')`
- **관리자** `/admin/keyword-hub` ([KeywordHubController](../app/Http/Controllers/Admin/KeywordHubController.php) · [index.blade.php](../resources/views/admin/keyword-hub/index.blade.php)) — 카테고리·시드 인라인 CRUD, 후보 큐(상태 탭·카테고리 필터·대량 승인/거부/삭제), 지금 수집(동기 1카테고리)·지금 발행(≤10건), 최근 발행 문서. **메뉴는 /admin/menus 에서 수동 등록**(시더 금지 규칙)
- 검증: [tests/Feature/KeywordHubTest.php](../tests/Feature/KeywordHubTest.php) 8건(수집 필터·상태 보존·발행/보류·볼륨순 상한·권한·승인 큐) + **Playwright E2E**(실 API): 시드 '캠핑의자' 1개 → 후보 207건 수집(필터 729건) → 승인 2건 → 발행 2건(`/keyword/캠핑의자` 월 52,700) → 문서 렌더 + Phase 0 추천 블록 확인

## Phase 2 — 카테고리 허브 페이지 + 문서 강화 (✅ 2026-07-16 구현·검증 완료)

- **공개 허브** ([KeywordInsightController](../app/Http/Controllers/KeywordInsightController.php)):
  - `/keywords` ([keywords/index.blade.php](../resources/views/keywords/index.blade.php)) — 대분류>소분류 카드(문서 수 합산) + 인기 리포트 12 + CollectionPage JSON-LD
  - `/keywords/{slug}` ([keywords/category.blade.php](../resources/views/keywords/category.blade.php)) — 집계(리포트 수·검색량 합계) + 문서 목록(검색량순, 24 페이징) + 하위/형제 카테고리 + CTA + BreadcrumbList·CollectionPage(ItemList) JSON-LD. **대분류는 하위 카테고리 문서 합산**. 비활성/미존재 404
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

## 코드 (Phase 0)

| 파일 | 역할 |
|---|---|
| [app/Domain/Seo/RelatedDocsService.php](../app/Domain/Seo/RelatedDocsService.php) | 관련 문서 추천 — 매칭·중복제거·캐시 |
| [resources/views/partials/related-docs.blade.php](../resources/views/partials/related-docs.blade.php) | "함께 보면 좋은 분석" 카드 그리드 |
| [tests/Feature/RelatedDocsTest.php](../tests/Feature/RelatedDocsTest.php) | 크로스 추천·추적슬롯 제외·폴백·중복 제거 |
| 수정: KeywordAnalysis/Market/Product/SellerPower 컨트롤러 `shared()` · [routes/web.php](../routes/web.php) `/store` 클로저 · share 뷰 5종 | 추천 데이터 주입 + 블록 include |
