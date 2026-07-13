# 15. 셀러력 — 쇼핑 상품 SEO·지수 경쟁 비교

> 네이버 쇼핑 셀러가 **자기 상품 vs 검색 상위 경쟁 상품**을 5축(적합도·인기도·신뢰도·기본배송·마케팅판매자)으로 비교해, 어디서 밀리고 무엇을 고쳐야 하는지 진단받는 기능.
> **확장 프로그램에서 즉시 확인**(주 사용 흐름) + **웹 콘솔에서 이력·재확인**(보조). 원본 자산: crm `power.php`/`shopitrend.extend.php`/`naver.extend.php`(4지수 28항목).
> 점수·등급은 **관측 신호 기반 자체 추정치**(네이버 공식 지수 아님).

- UI 시안(스토리라인): https://claude.ai/code/artifact/90391f0b-d587-47f8-9e3d-0fd28d4d8c95

## 0-A. 통합 UX 재설계 (확정 · 2026-07-11)

네이버가 검색 API에 nCaptcha 토큰을, 리뷰 API에 요청별 서명(x-client-rtk)을, 상세를 SPA(`simpleProductForDetailPage.A`)로 바꿔, 셀러력 경쟁 데이터를 **검색 결과에서** 얻는 것이 정답으로 확정됨(리뷰수·구매수·찜수가 검색 응답에 있음).

**진입점: 쇼핑 검색 페이지 중심**
- `search.shopping.naver.com/search/*` 진입 시 확장이 **검색 리스트 상품을 자동 수집**(content.js `collectProducts`/`fetchApiPage` + injected.js가 후킹한 nCaptcha 토큰 재사용).
- 패널 탭: **[리뷰분석] [셀러력] [시장분석] [내역(통합·뱃지)]**. 각 탭 리스트에서 상품별로 리뷰분석/셀러력 실행(상품 행 hover 버튼 또는 리스트 항목).
- **상세 페이지도 동일 패널**. 리뷰분석은 상세에서 캡처-재현으로 동작. **셀러력은 검색 수집 데이터가 있어야** 하므로, 검색 없이 바로 상세로 오면 "먼저 검색부터 해주세요" 안내.

**셀러력 데이터 소스(검색 응답 상품 1건, content.js `normalizeItem`)**
- 리뷰수 `reviewCount` · 구매수(6개월) `purchaseCnt` · 찜수 `keepCnt` · 가격 `lowPrice` · 제목 `productTitle`(적합도) · 몰등급 `mallGradeOf`
- 무료배송·오늘출발·**N배송(도착보장)**·**npay포인트** = 키 미확정 → content.js 필드 프로빙(`[RankFree 필드프로빙]`)으로 실제 응답에서 확정 후 매핑
- **최근 판매량 추정**: 6개월 구매수만으론 최근 추세를 못 보므로 **리뷰수(최근성)를 최근 판매 프록시**로 가중. 최근 판매·리뷰가 순위에 큰 영향.

**리뷰 API(상세)**: `group-products/query-pages`(복수 `originProductNos`) + 요청별 서명 → 페이지 실제 요청을 캡처해 page/정렬만 바꿔 재현. 그룹 전체 리뷰수는 `simpleProductForDetailPage.A.simpleStandardGroupProduct.reviewAmount.totalReviewCount`.

## 0. 퍼널·역할 분담

```
셀러가 자기 상품 페이지에서 확장 실행 → 키워드 입력
 → [확장] 내 상품 + 검색 상위 10개 경쟁 상품 수집(브라우저 권한)
 → [서버] 셀러력 계산·저장, 결과 반환
 → [확장 패널] 그 자리에서 진단 리포트 즉시 표시   ← 주 흐름
 → [웹 콘솔] 저장된 비교 이력·추이 재확인          ← 보조
```

- **수집은 확장이 담당**한다(서버가 직접 네이버를 긁지 않는다 → 차단·보안 회피). 확장은 이미 상품 페이지(`smartstore/brand products`)에서 `__PRELOADED_STATE__`를 후킹 중.
- **계산은 서버가 담당**한다(공식 단일화·유지보수). 확장은 raw 데이터만 전송, 서버가 `SellerPowerScorer`로 5축 점수·격차·처방을 만들어 반환.
- 확장 패널과 웹 콘솔은 **같은 결과 JSON**을 렌더한다(블로그 지수 분석과 동일 패턴).

## 1. 지수 체계 — 5축 (원본 4지수 28항목 + 신규)

원본은 **적합도(SEO)·인기도·기본기·마케팅** 4지수. 이를 5축으로 재배치하고 신규 지표를 더한다. `수집`은 확장이 브라우저에서 뽑을 수 있는 난이도.

### 축 A. 적합도 (SEO) — 이 키워드에 맞는 상품인가
| 항목 | 출처 | 비고 |
|---|---|---|
| 상품명 핵심/일부 키워드 수 | `product.A.name` × 검색어/`terms` | 원본 |
| 설명(description) 핵심/일부 키워드 | `smartStoreV2.channel.description` | 원본 |
| 상세설명 핵심/일부 키워드 | `product.A.detailContents.detailContentText` | 원본 |
| 태그 핵심/일부 키워드 | `product.A.seoInfo.sellerTags[].text` | 원본 |
| 검색적용 태그 수 | `sellerTags[].code` 존재 여부 | 원본 |
| 몰등급 / 굿서비스 | `mallInfoCache.{mallGrade, goodService}` | 원본 |
| **키워드 스팸·반복 감지** ✨ | `name` 정규식 | 신규(어뷰징 감점) |
| **제목 길이 최적화** ✨ | `name` 길이 | 신규 |

### 축 B. 인기도 — 얼마나 잘 팔리고 반응 있나
| 항목 | 출처 | 비고 |
|---|---|---|
| 3일 판매량 / 6개월 판매량 | `productDeliveryLeadTimes[].leadTimeCount` 추정 | 원본 |
| 6개월 매출액 | `benefitsView.discountedSalePrice × cumulationSaleCount` | 원본 |
| 리뷰수 | `product.A.reviewAmount.totalReviewCount` | 원본 |
| 찜수 | `get_product_zzim` / 경쟁 `keepCnt` | 원본 |
| 방문자수 | `get_smartstore_visit().today` | 원본 |
| **구매 전환 신호** ✨ | 찜 대비 판매 비율 | 신규 |
| **리뷰 신선도** ✨ | 최근 리뷰 유입(가능 시) | 신규 |

### 축 C. 신뢰도 — 콘텐츠·리뷰를 믿을 수 있나 (원본에서 분리·강화)
| 항목 | 출처 | 비고 |
|---|---|---|
| 상세설명 길이 | `detailContentText` 글자수 | 원본(적합도에서 분리) |
| 썸네일 이미지 수 | `product.A.productImages[]` | 원본 |
| **상세 이미지 수** ✨ | 상세 파싱 | 신규 |
| **대표이미지 품질** ✨ | 흰 배경·텍스트 과다 추정 | 신규 |
| **리뷰 품질** ✨ | 평점 분포·포토리뷰 비율 | 신규 |
| **평균 판매 만족도** | `smartStoreV2.channel.averageSaleSatificationScore` | 원본(표시→채점) |

### 축 D. 기본·배송 — 구매 편의·가격
| 항목 | 출처 | 비고 |
|---|---|---|
| 네이버페이 / 네이버쇼핑 등록 | `naverPayNo` / `epInfo.naverShoppingRegistration` | 원본 |
| 오늘출발 | `productDeliveryInfo.todayDelivery` | 원본 |
| 2일내 배송완료율 | `channel.in2DaysDeliveryCompleteRatio` | 원본 |
| 평균 배송기간 | `averageDeliveryLeadTime.productAverageDeliveryLeadTime` | 원본 |
| 무료배송유형 / 배송비 / 반품비 | `productDeliveryInfo.{deliveryFeeType, baseFee}` · `claimDeliveryInfo.returnDeliveryFee` | 원본 |
| **가격 경쟁력** ✨ | 내 판매가 vs 상위 평균가 백분위 | 신규 |

### 축 E. 마케팅·판매자 — 외부 유입·판매자 신뢰
| 항목 | 출처 | 비고 |
|---|---|---|
| 블로그 운영/방문/이웃 | `exposureInfo.NAVERBLOG[0]` → 블로그 방문·이웃 | 원본 |
| 인스타 팔로워/게시물 | `exposureInfo.INSTAGRAM[0]` → 인스타 프로필 | 원본 |
| **스토어 등급** ✨ | `mallGrade` | 신규(판매자 레벨) |
| **문의 응답 속도** ✨ | 가능 시 | 신규 |
| **단골(찜) 수** ✨ | 스토어 단골 | 신규 |

## 2. 점수 설계 (자체 추정 · 경쟁 상대평가)

**핵심 원칙: 절대 임계값이 아니라 "검색 상위 10개 경쟁 평균 대비 내 위치"로 채점한다.** 셀러의 실제 관심사는 "이 시장에서 내가 몇 위인가"이기 때문.

### 2-1. 항목 점수 (0~100)
- **연속형 지표**(판매량·리뷰·찜·방문·매출·키워드 수·글자수 등): 경쟁 상위 10개 중 백분위 → 0~100.
  `itemScore = clamp( round( rank_percentile(myValue, competitors) ), 0, 100 )`
  단순화 초안: `min(100, round(myValue / max(1, top_avg) * 60 + 20))` (상위 평균이면 80점, 2배면 100점 근처, 0이면 20점).
- **불리언 지표**(네이버페이·오늘출발·굿서비스·블로그 운영 등): 있으면 만점, 없으면 0(또는 감점).
- **역방향 지표**(배송기간·배송비·반품비): 낮을수록 가점.
- **어뷰징 지표**(키워드 스팸·이미지 텍스트 과다): 위반 시 감점.

### 2-2. 축 점수 = 항목 가중 평균 (0~100)
축 내 항목 가중치는 문서 확정 시 표로 고정(초안은 균등 + 핵심 항목 가중).

### 2-3. 총점 = 5축 가중 평균
초안 가중치(네이버 랭킹 기여도 기반):
```
적합도 25% · 인기도 30% · 신뢰도 20% · 기본·배송 15% · 마케팅·판매자 10%
```

### 2-4. 등급·시장 위치 = 경쟁 상대평가
- **시장 백분위**: 내 총점을 경쟁 10개 + 나 = 11개 중 순위/백분위로.
- **등급**(경쟁 기준): 상위 10% `S` · 30% `A` · 50% `B` · 70% `C` · 그 외 `D`.
- 확장 패널에는 "시장 상위 N%", "키워드 상위 10개 중 M위"로 표기.

### 2-5. 판매량·매출 추정 (원본 공식 그대로 이식)
```
total_lead_cnt      = Σ product.A.productDeliveryLeadTimes[].leadTimeCount   // 1주 판매량
day_sale_count      = total_lead_cnt > 0 ? round(total_lead_cnt / 7, 1) : 0  // 하루
recentSaleCount     = day_sale_count × 3      // 3일
cumulationSaleCount = day_sale_count × 180    // 6개월
// 조건부무료면 배송비를 객단가에 가산
if (productDeliveryInfo.freeConditionalAmount) discountedSalePrice += baseFee;
my_sale_price       = discountedSalePrice × cumulationSaleCount
```
※ 원본은 경쟁사 인기도 표에서 `saleAmount` 직접값을 쓰기도 해 **원천 불일치** 버그가 있음 → 이식 시 내/경쟁 **모두 leadTime 추정으로 통일**한다.

### 2-6. 처방(개선 우선순위)
항목별 `격차(상위평균 - 내값)` × `개선 난이도(쉬움>보통>어려움 가중)`로 정렬 → 상위 3개를 "가장 큰 손해"로. 각 처방은 `현재값 → 목표값 + 예상 점수 상승`을 함께.

## 3. 데이터 소스 (엔드포인트·파싱)

모두 **확장이 브라우저에서** 수집(서버 직접 호출 금지).

1. **내 상품 상세**: 현재 상품 페이지의 `window.__PRELOADED_STATE__` → `product.A`, `smartStoreV2`, `mallInfoCache`, `blogInfo`. (기존 `injected-store.js`가 후킹 중)
2. **검색결과 상위 10**: `GET https://search.shopping.naver.com/api/search/all?query={kw}&pagingIndex=1&pagingSize=80&productSet=total&sort=rel&viewType=list` → `shoppingResult.products[]`, `terms[]`, `termCount`. (헤더 `logic: PART`, `sbth`, referer 필요 — 확장이 실제 브라우징 컨텍스트에서 호출하면 서명 문제 회피 가능)
3. **경쟁 상품 상세**: 상위 10개 각각의 상품 URL을 background fetch → `__PRELOADED_STATE__` 파싱. 카탈로그(`/catalog/{id}` → `__NEXT_DATA__`)·푸드윈도는 실제 스마트스토어 URL로 우회 해석.
4. **찜수**: 검색결과 `keepCnt` 우선, 없으면 `product-zzim` API.
5. **인스타/블로그/방문자**: `exposureInfo`의 계정 → 인스타 프로필·블로그 방문/이웃·스토어 방문(today).

`product.A` 주요 키(§원본 인벤토리):
`name·id·regDate · channel.{channelName,channelNo,channelSiteFullUrl} · epInfo.{syncNvMid,naverShoppingRegistration} · productDeliveryLeadTimes[].leadTimeCount · benefitsView.discountedSalePrice · productDeliveryInfo.{deliveryFeeType,baseFee,freeConditionalAmount,todayDelivery} · claimDeliveryInfo.returnDeliveryFee · averageDeliveryLeadTime.productAverageDeliveryLeadTime · reviewAmount.totalReviewCount · saleAmount.{recentSaleCount,cumulationSaleCount} · detailContents.detailContentText · seoInfo.sellerTags[].{text,code} · productImages[].url · naverShoppingSearchInfo.{manufacturerName,brandName}` · `smartStoreV2.channel.{description,naverPayNo,averageSaleSatificationScore,in2DaysDeliveryCompleteRatio,storeExposureInfo.exposureInfo.{INSTAGRAM,NAVERBLOG}}` · `mallInfoCache.{mallGrade,goodService}`.

## 4. 확장 수집 흐름

1. 셀러가 자기 상품 페이지에서 확장 패널 열기 → **키워드 입력**(경쟁 비교 기준 검색어).
2. 확장: 내 상품 `product.A` 수집.
3. 확장(background): 검색 API로 상위 10 목록 → 각 상품 상세를 **병렬 fetch**(롤링 동시 제한).
4. raw payload를 서버 `POST /api/ext/seller-power`로 전송.
5. 서버: `SellerPowerScorer`로 5축 점수·격차·처방·경쟁 포지션 계산, `seller_power_analyses`에 저장, 결과 JSON 반환.
6. 확장 패널: 결과를 스토리라인 UI로 렌더(진단→경쟁→레이더→손해→처방→CTA).
7. 웹 콘솔(`console.seller-power`): 저장 이력·상품 간 비교·점수 추이.

## 5. 서버 API 계약 (초안)

`POST /api/ext/seller-power` (확장 토큰 인증 `/api/ext/*`)
```jsonc
// 요청 (확장 → 서버, raw 수집분)
{
  "keyword": "무선이어폰",
  "my": { "product": { /* product.A */ }, "smartStoreV2": {…}, "mallInfoCache": {…},
          "zzim": 1240, "visit": 320, "blog": {…}, "insta": {…} },
  "competitors": [ { "rank": 1, "product": {…}, "smartStoreV2": {…}, "mallInfoCache": {…}, "keepCnt": … }, … ]  // 최대 10
}
```
```jsonc
// 응답 (서버 → 확장/웹, 계산 결과)
{
  "score": 72, "grade": "B", "market_percentile": 38, "rank_in_top": 7, "competitor_count": 10,
  "axes": [ { "key": "적합도", "mine": 68, "avg": 82, "gap": -14, "weight": 0.25 }, … ],   // 5축
  "losses": [ { "rank": 1, "title": "상세 설명이 짧아요", "cur": "320자", "target": "780자",
               "gain": 9, "difficulty": "easy", "rank_delta": 3 }, … ],                    // Top N
  "rx": [ { "axis": "적합도", "score": 68, "items": [ { "state": "warn", "name": "제목 키워드 위치", "tip": "…" }, … ] }, … ],
  "positions": [88,85,83,81,79,75,72,68,64,59], "my_position_index": 6,
  "analysis_id": 123
}
```
- `GET /api/ext/seller-power/{id}` — 저장 결과 재조회(확장 재열람).

## 6. DB 스키마 (초안)

`seller_power_analyses` — 사용자별 스냅샷(블로그 지수와 동일 패턴):
| 컬럼 | 내용 |
|---|---|
| `id` | PK |
| `user_id` | 사용자 |
| `keyword` | 비교 키워드 |
| `product_url` | 내 상품 URL |
| `product_name` | 내 상품명 |
| `store_id` | 내 스토어 |
| `score` / `grade` / `market_percentile` / `rank_in_top` | 요약 |
| `snapshot` | 결과 JSON(암호화 배열: axes·losses·rx·positions·raw 일부) |
| `created_at` / `updated_at` | updateOrCreate 키: (user_id, product_url, keyword) |

- 재수집 주기: 원본은 3일 캐시. rankfree는 **명시적 재수집 버튼** + PRG(새로고침 시 스냅샷).

## 7. UI 스토리라인 (확장 패널 = 웹 공용)

시안: https://claude.ai/code/artifact/90391f0b-d587-47f8-9e3d-0fd28d4d8c95

1. **진단 히어로** — 셀러력 점수 게이지 + 등급 + "시장 상위 N% · 상위 10개 중 M위" + 한 줄 판정
2. **경쟁 속 내 자리** — 상위→하위 트랙에 11개 도트, 내 위치 강조
3. **5축 레이더** — 내 상품(채움) vs 상위 평균(점선) + 축별 점수·격차
4. **가장 큰 손해부터** — 개선 우선순위(격차×난이도) 카드: 현재→목표 + `+N점`·`예상 순위 ▲` + 난이도
5. **항목별 처방** — 5축 체크리스트(✓/!/✕ + 한 줄 처방)
6. **CTA** — 저장·순위 추적 시작 / 웹에서 자세히

세만틱 색(위험 빨강·주의 주황·강점 초록)과 손실 프레이밍 사용(Cal.com 모노크롬 규칙의 예외 — 셀러 설득 목적). 브랜드 액센트는 인디고블루.

## 8. 이식 주의 (보안·버그)

- **보안**: 원본에 네이버 로그인 쿠키(`NID_AUT`/`NID_SES`)·`Sbth` 서명·검색광고 계정·FB 토큰이 평문 하드코딩 → **전량 제거**. 확장은 사용자의 브라우저 세션으로 수집하므로 서버에 자격증명 불필요. 서버 저장 시 스냅샷 암호화.
- **원본 버그 교정**: `getPower1`의 `switch($title_per)`(항상 첫 케이스), `$benefits_view` 미정의, 태그 인덱스 오사용(`$sellerTags[$i]` → `$s`), 만족도 평균 필터 누락, 내/경쟁 판매량 원천 불일치 → 재설계에서 정리.
- **깨지기 쉬운 지점**: `__PRELOADED_STATE__`·`__NEXT_DATA__` 키, 검색 API `sbth` 서명, `exposureInfo` 구조는 네이버 변경 시 파손 가능 → 파서 격리·버전 로깅.

## 9. 구현 단계

1. ✅ **설계 확정**(이 문서) — 축·항목·가중치·API·DB·UI 기준 고정
2. ✅ **서버 API + Scorer + DB** — `POST /api/ext/seller-power`, `SellerPowerScorer`(5축 상대평가·격차·처방·포지션), `seller_power_analyses`
3. ✅ **웹 콘솔** — `console.seller-power`(목록·리포트 `show`), 쇼핑 그룹 메뉴 추가
4. ✅ **확장 수집기 + 패널** — `content/seller-power.js`(내 상품 `__PRELOADED_STATE__` + 검색 상위 10 병렬 fetch → 서버), `background.js`(`spFetchHtml`·`saveSellerPower`), `content/seller-power.css`(패널). manifest host_permissions·content_scripts 등록
5. ⏳ **실데이터 검증(브라우저)** — 확장 로드 후 실제 상품 페이지에서: ① 내 상품 `__PRELOADED_STATE__` 파싱 ② 검색 API(`search.shopping.naver.com/api/search/all`)가 background fetch로 `sbth` 없이 응답하는지 ③ 경쟁 상품 상세 파싱. 안 되면 검색을 결과 페이지 HTML 파싱으로 폴백

### 확장 파일 (구현됨)
- `extension/manifest.json` — 네이버 쇼핑 host_permissions, 상품 페이지 content script에 `panel.css`+`seller-power.css` 로드
- `extension/background.js` — `spFetchHtml`(cross-origin HTML/JSON), `saveSellerPower`(서버 저장·결과 수신)
- `extension/content/product.js` — **상품 페이지 통합 패널** (버튼 라벨 "RankFree"). 패널 탭 `[상품 분석] [셀러력] [내역]`. **셀러력 탭**이 수집(내 상품 `__PRELOADED_STATE__` **괄호 균형 파싱** + 검색 상위 10 동시 4)·서버 전송·스토리라인 렌더(레이더 Canvas)
- `extension/content/seller-power.css` — 셀러력 탭 스타일(product 패널과 공용, 변수는 `#rankfree-panel`에도 정의)
- `extension/content/content.js` — 검색 페이지 시장분석 버튼 라벨도 "RankFree"로 통일

> 상품·셀러력이 **하나의 RankFree 버튼/패널**에서 탭으로 전환된다(별도 버튼 아님). `seller-power.js`(독립 패널판)는 통합 후 삭제.

---
관련: [10_EXTENSION_SHOPPING_MARKET.md](./10_EXTENSION_SHOPPING_MARKET.md) · [14_SHOPPING_RANK.md](./14_SHOPPING_RANK.md) · [13_KEYWORD_ANALYSIS.md](./13_KEYWORD_ANALYSIS.md) · [research/research-crm-smartplace-inventory.md](./research/research-crm-smartplace-inventory.md)
