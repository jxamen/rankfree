# 25. 쇼핑 노출 키워드 분석

> 콘솔 `console.shop-keyword` — 핵심 키워드 + 상품 URL → 다중 소스 키워드 추출 → 2~5단어 조합 생성 →
> 각 조합으로 쇼핑 검색해 **내 상품이 상위 N위(기본 5)에 노출되는 키워드**를 찾는다.
> 목적: 판매자가 "어떤 롱테일에서 내 상품이 강한지" 파악하고 제품명·태그·상세페이지 SEO를 그 키워드에 맞추는 것.

## 범위 (중요)

- **분석·리서치 도구다.** 키워드 추출 → 조합 → 순위체크 → 노출 키워드 식별·저장·조회.
- **유입 트래픽 발생·회전 리다이렉트·도메인 로테이션은 범위에 없다**(네이버 순위 조작 + 탐지 회피에 해당). 노출 키워드는 판매자가 직접 확인하는 일반 쇼핑 검색 링크로만 제공한다.

## 흐름 (비동기 배치)

1. **prepare()** — 추출 + 조합 생성 후 저장(순위 미확인, status=`checking`). 빠르게 끝나 show로 리다이렉트.
2. **checkBatch()** — show 페이지가 `POST .../check`를 폴링(JS)해 미확인 조합을 배치(`batch_size`, 시간예산 `batch_sec`)로 순위체크. 진행률 바 갱신, `remaining=0`이면 reload해 결과 렌더.
   - 100개 조합도 한 요청에 몰지 않아 **게이트웨이 타임아웃 없음**.
   - 429(전 키 소진)면 그 배치에서 중단(`blocked`) — 남은 건 미확인 유지, "이어서 확인" 버튼으로 재개.

## 데이터 소스 (재사용 매트릭스)

| 소스 | 재사용 자산 | 경로 |
|---|---|---|
| 자동완성 | `NaverAutocompleteService::suggest` | 서버(ac.search.naver) |
| 연관(핵심 포함) | `NaverKeywordService::analyze()['related']` / `volumes()` | 서버(검색광고 keywordstool) |
| 함께 많이 찾는 | `NaverSerpService::sections()['related']` | 서버(통합검색 SERP qra) |
| 브랜드·키워드추천·상품속성 | `ShopFilterHtmlParser` | **붙여넣은 쇼핑 HTML**(서버 418) |
| 수식어(파생) | `deriveModifiers` — 추출 키워드에서 핵심어 제거 | 서버(속성 빈약 상품 대응) |
| 어미/수식어 | config `exposure.suffixes` + 입력창 | "{핵심} {어미}"(2단어) |
| 상품 URL 파싱 | `NaverShoppingRankService::resolveTarget` | 서버 |
| 순위체크 | `NaverShoppingRankService::checkRank($kw,$target,['max_pages'=>1])` | 서버(shop.json, sort=sim) |

- **순위는 shop.json(sort=sim) 추정치** — 실제 웹 순위와 다를 수 있어 "추정"으로 표기. 정확한 웹 순위는 확장 DOM 연동 여지.
- **쿼터 보호**: 조합당 shop.json 1콜(상위 100). 다중키 429 로테이션은 checkRank가 담당.

## 조합 생성 (`buildCombos`) — 2~5단어 롱테일

- **전부 핵심 키워드 포함**(정규화 후 `str_contains`). 최대 단어수 `max_tokens`(기본 5).
- 5위 노출은 경쟁이 얇은 **롱테일(3~5단어)** 에서 나온다. 조합 재료 = **상품 속성 + 파생 수식어**(`attr_pool`개). 속성 빈약 상품도 수식어로 롱테일을 만든다.
- 생성 순서(각 tier 앞자리 확보):
  - A) `[브랜드?] + 핵심 + 재료 부분집합`(크기 0..max_tokens-1) — 상품 특이 최우선.
  - B) `핵심 + 어미`(2단어만 — 3단어 이상 곱하면 tier가 저품질로 넘쳐 특이조합을 밀어냄).
  - C) 완결 검색어(키워드추천·자동완성·연관·함께많이찾는) — 1단어여도 그대로.
- **길이별 버킷 + 짧은 길이부터 라운드로빈**으로 `max_combos`개 선별 → 모든 길이가 골고루 체크(짧을수록 검색량 큼).
- **완화 정리**(`cleanLoose`): `1000mg`·`3M` 단독은 모델명 필터에 걸리지만 조합("비타민c 1000mg")은 유효 — 최종 조합만 `acceptableKeyword` 검증. `attrSubsets`는 크기 오름차순·`cap` 상한으로 폭발 방지.

## 데이터 모델

- `shop_keyword_analyses` — user_id, core_keyword, product_url/product_id/mall_name, threshold, token_count·combo_count·checked_count·exposed_count, status(checking|blocked|done).
- `shop_keyword_analysis_items` — `kind`(token|combo) · `source`(autocomplete|related|together|brand|keyword_rec|attribute|modifier|suffix|combo) · keyword · `rank`(combo: null=미확인·0=순위밖·1~=순위) · monthly_total. unique(analysis_id,kind,keyword).

## 코드

| 파일 | 역할 |
|---|---|
| [ShopKeywordExposureAnalyzer](../app/Domain/Shopping/ShopKeywordExposureAnalyzer.php) | prepare(추출·조합·저장) + checkBatch(배치 순위체크) |
| [ShopFilterHtmlParser](../app/Domain/Shopping/ShopFilterHtmlParser.php) | 쇼핑 필터 HTML → 브랜드·키워드추천(filter_value_id)·속성 |
| [ShopKeywordExposureController](../app/Http/Controllers/ShopKeywordExposureController.php) | index/store/check(폴링)/show/destroy |
| [console/shop-keyword/index·show](../resources/views/console/shop-keyword/) | 입력 폼(조합수 select·어미·필터HTML) + 결과(진행률 폴링·노출 키워드·전체 조합·소스별 추출) |
| [ShopKeywordAnalysis](../app/Models/ShopKeywordAnalysis.php) / [Item](../app/Models/ShopKeywordAnalysisItem.php) | 모델 |

- 라우트: `console.shop-keyword.*`(index/store/check/show/destroy) — [routes/web.php](../routes/web.php).
- 메뉴: 미등록이어도 접근 가능(menu.gate 기본 허용). 사이드바는 `/admin/menus`에서 **쇼핑** 그룹 하위로 수동 추가([[menus-via-admin-not-seeder]]).
- 설정: `config/rankfree.php` → `shopping.exposure`(top·max_combos·max_tokens·attr_pool·scan_pages·batch_size·batch_sec·suffixes). 조합수는 입력창 select(30~100)로 조절.
- 테스트: `tests/Feature/ShopFilterHtmlParserTest.php` + `ShopKeywordExposureTest.php`(조합 생성·배치 노출판정·select·어미·수식어파생·핵심포함·소유권).

## 후속 여지

- 확장 DOM 실측 웹 순위 연동(정확한 5위) — 현재 shop.json 추정치.
- 상품 페이지 제품명·상세태그 자동 토큰화(현재 브랜드/속성은 붙여넣기 HTML 의존).
- 조합 생성 rate limit(유저당 분석 빈도) — 쿼터 남용 방지.
