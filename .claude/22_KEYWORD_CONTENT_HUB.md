# 22. 키워드 콘텐츠 허브 — 카테고리 → 키워드 → 분석 문서 → 상호 추천

> 카테고리별로 키워드를 모으고, 키워드마다 SEO/AEO/GEO 최적화 분석 문서를 발행하며, 문서끼리 **아고다식으로 서로 추천**해 문서 하나하나가 살아있는 검색 랜딩이 되게 한다. (2026-07-16 설계 · Phase 0 구현)

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

## Phase 1 — 카테고리 분류 + 키워드 수집 파이프라인 (설계)

### 데이터 모델

| 테이블 | 컬럼 | 비고 |
|---|---|---|
| `keyword_categories` | type(place\|shopping) · parent_id · name · slug(unique) · description · seed_keywords(json) · sort · is_active | 2계층(대분류>소분류)로 시작 |
| `keyword_candidates` | category_id · keyword · source(seed\|related\|autocomplete\|user) · monthly_total · comp_idx · status(pending\|approved\|rejected\|published) · note | (category_id, keyword) unique |
| `keyword_searches` 확장 | +category_id(nullable) · +origin('user'\|'hub') · +refreshed_at · user_id nullable화 | **허브 문서 = KeywordSearch 재사용**(아래 결정) |

- 카테고리 시드: 플레이스 = pcmap 6종(`NaverPlacePayloads::categories()`) × 지역 변형(강남 맛집…) / 쇼핑 = 네이버 1depth + `shop_rank_slots.category` 실측값 활용
- 시드 키워드 입력·카테고리 관리는 `/admin/keyword-hub`(메뉴는 admin에서 수동 추가)

### 크론 3종

1. **hub:collect** — 하루 N개 카테고리 로테이션: 시드 → `NaverKeywordService`(keywordstool 연관) + `NaverAutocompleteService` → candidates upsert. 자동 필터: 검색량 임계(기본 월 1,000+)·금칙어·브랜드/업체명 패턴·기발행 제외
2. **hub:publish** — 승인분(자동승인 규칙 옵션) → `buildAnalysis()` 재사용 → `KeywordSearch(origin=hub, category_id, snapshot)` 저장. **시간당 N건 제한**(검색광고 쿼터 보호), 핵심 데이터 없으면 발행 보류(thin content 방지)
3. **hub:refresh** — `refreshed_at` 오래된 순 하루 N건 재수집 → 사이트맵 lastmod 갱신 효과

## Phase 2 — 카테고리 허브 페이지 + 문서 강화 (설계)

- **URL**: `/keywords`(카테고리 인덱스) · `/keywords/{category-slug}`(허브: 설명 + 집계(총 검색량·평균 경쟁) + 키워드 문서 목록(검색량순) + 하위/형제 카테고리 링크). 사이트맵에 `keyword-categories` 섹션 추가([config/sitemap.php](../config/sitemap.php))
- **문서(keyword.share) SEO/AEO/GEO 강화**:
  - AEO: 상단 "요약 답변" 2~3문장(검색량·경쟁·피크 시기·주 사용층) — **데이터 기반 결정적 템플릿**(LLM 아님)
  - FAQ 확장(검색량/많이 찾는 시기/성별·연령/경쟁) — `report-seo` FAQPage와 화면 FAQ 동일 문항 유지
  - GEO: 기준일·출처 명시("네이버 검색광고 기준 · 2026-07 자체 집계"), 인용하기 좋은 수치 문장, "자체 추정" 고지 유지
  - 브레드크럼: 홈 > 키워드 > {카테고리} > {키워드} (BreadcrumbList 연동)
- **추천 고도화(아고다식 "주변")**: 같은 카테고리 인기 문서로 패딩 대체, 지역 키워드는 인근 지역 변형(강남→서초·역삼) 추천, 문서 하단 CTA(이 키워드 순위추적·시장분석 무료 실행 — 퍼널)

## Phase 3 — 자동화·확장 (설계)

- LLM 인사이트(선택): Gemini(19 크론 자산 재사용)로 검색의도·콘텐츠 방향 1문단 — **사실은 데이터, 문장만 생성**. 과장 금지
- market/store 문서에도 category_id 부여 → 카테고리 기반 크로스 추천 정밀화
- GSC(20) 피드백 루프: 노출·클릭 실적 → 갱신 우선순위·신규 키워드 발굴

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
