# 25. 쇼핑 노출 키워드 분석

> 관리자 `admin.shop-keyword`(운영자 전용 — 2026-07-21 콘솔에서 이동) — 핵심 키워드 + 상품 URL → 다중 소스 키워드 추출 → 2~5단어 조합 생성 →
> 각 조합으로 쇼핑 검색해 **내 상품이 상위 N위(기본 5)에 노출되는 키워드**를 찾는다.
> 목적: 판매자가 "어떤 롱테일에서 내 상품이 강한지" 파악하고 제품명·태그·상세페이지 SEO를 그 키워드에 맞추는 것.

## 범위 (중요)

- **분석·리서치 도구다.** 키워드 추출 → 조합 → 순위체크 → 노출 키워드 식별·저장·조회.
- **유입 트래픽 발생·회전 리다이렉트·도메인 로테이션은 범위에 없다**(네이버 순위 조작 + 탐지 회피에 해당). 노출 키워드는 판매자가 직접 확인하는 일반 쇼핑 검색 링크로만 제공한다.

## 순위 확인 방식 선택 (2026-07-22 — check_method, 기본 api)

통합검색 크롤링이 느리고 차단(429·보안문자)돼 **방식을 골라** 쓴다(입력 폼 라디오, `shop_keyword_analyses.check_method`):
- **api(기본)**: `NaverShoppingRankService::checkRank`(openapi shop.json, display 40·1페이지 1콜) — 서버 배치 폴링으로 빠르게 끝나고 차단 없음. 쇼핑 순위추적(14)과 동일 기준·**광고 판별 없음**. ⚠️ checkRank 는 일부 키 429 여도 다음 키 성공 시 `blocked=true+found` 를 함께 주므로 **found 우선 판정**(blocked 만 보면 성공 결과를 버린다 — 실측).
- **search**: 기존 통합검색(m.search) 크롤링 — 실화면 오가닉 순위·광고(슈퍼적립) 판별. 확장 화면단 체크 루프는 이 방식 전용(show 의 `cfg.method` 분기 — api 는 확장이 있어도 서버 폴링).
- prepare 의 check_method 저장은 **트랜잭션 클로저 밖에서 확정** — 클로저 `use` 누락 시 `$opts['…'] ?? ''` 가 조용히 기본값이 된다(실사고).

## 흐름 (확장 화면단 체크 기본 + 서버 폴백)

1. **prepare()** — 추출 + 조합 생성 후 저장(순위 미확인, status=`checking`). 빠르게 끝나 show로 리다이렉트.
   **조합 수 선택 없음** — 만들 수 있는 조합 전부 생성(`hard_cap` 3000 안전선).
2. **확장 화면단 체크(기본)** — show 페이지가 확장 브릿지(`data-rf-ext`)를 감지하면:
   `GET .../pending`(미확인 40개 배치) → 조합별로 확장 background 가 **브라우저(사용자 IP)에서 m.search fetch**
   (`fetchShopSerp`, `_INITIAL_STATE` script 조각만 회신) → `POST .../check-html`{item_id, html} 로 서버가 판정·저장.
   서버 IP 한도와 무관해 수백 조합도 끝까지 확인. blocked 도 진행되며 해제.
   **차단 완화 페이싱**: fetch 간 0.9~1.6s(250건 이후 1.8~3s) + 60건마다 12~20초 휴식(규칙적 패턴 끊기).
   병렬화는 같은 IP 라 분당 총 요청수가 그대로여서 채택하지 않음 — 총량 감속+휴식이 유효.
   **보안문자/차단(403·429)**: 캡차 페이지 감지(미노출 오기록 차단) 및 403/429 시 "보안문자 풀기 ↗"(새 탭
   m.search) 버튼 + 안내 → 풀고 "이어서 확인"으로 재개(실측: 수백 건 연속 확인 시 발생).
   - 매칭 슬롯을 만나면 분석 헤더의 **제목·업체명·가격 백필**(`rankFromHtml`의 `me`).
   - 페이지 로드 시 **보충 수집(enrich)**: ① 제목 없으면 `collectProductPage` — 백그라운드 탭으로 상품페이지를 열어
     product.js 자동수집 payload 를 페이지로 회신 → `POST .../product-info`(현재 사이트에 저장 — 확장 로그인·apiBase 무관)
     → 제목/구절/SEO태그 토큰 추가. ② 함께많이찾는·경쟁브랜드 빈약하면 `fetchKeywordSignals`(PC SERP→qra JSON + m.search HTML)
     → `POST .../supplement`(10분 가드) → together·competitor_brand·attribute 토큰 병합.
3. **checkBatch()(폴백)** — 확장 미설치 시 종전 서버 배치 폴링(`POST .../check`). 429면 `blocked` 중단, "이어서 확인"으로 재개.
4. **"새로 조합" / 자동 재편성** — **미확인 조합은 삭제**(정보 손실 없음)하고 확인 결과는 보관(미노출은 hidden)한 뒤
   현재 재료로 우선순위를 처음부터 다시 편성. 상품정보 백필로 새 재료(added>0)가 오면 **자동으로** 재편성 후 reload —
   속성 위주 저효율 조합(실측 251개 중 노출 1)이 제목 단어 중심으로 갈아끼워진다.
5. **UI** — 진행바에 **중단/이어서 확인** 버튼(사용자 즉시 멈춤·재개). **중단은 서버에 저장**(`POST .../pause` → status=`paused`) —
   새로고침해도 자동 재시작하지 않고 "이어서 확인"(paused=false)으로만 재개. 중단 직후 늦게 도착한 checkBatch/applyHtml 결과가
   paused 를 checking 으로 덮어쓰지 않게 저장 시 status 를 재확인한다(레이스 가드). 중단 상태에선 enrich(보충 수집→재편성 reload)도 안 돈다.
   전체 조합은 **상태 필터 칩**(전체/확인됨/노출/N위 밖/미노출/미확인)
   + **500px 스크롤 컨테이너**. 확인 결과는 reload 없이 **실시간 반영**(배지 상태·칩 카운트·요약 숫자·노출 테이블·광고 섹션 자동 등록).
   노출·광고 키워드는 **전체 복사** 버튼(줄바꿈 구분).

## 데이터 소스 (재사용 매트릭스)

| 소스 | 재사용 자산 | 경로 |
|---|---|---|
| 자동완성 | `NaverAutocompleteService::suggest` | 서버(ac.search.naver) |
| 연관(핵심 포함) | `NaverKeywordService::analyze()['related']` / `volumes()` | 서버(검색광고 keywordstool) |
| 함께 많이 찾는 | `NaverSerpService::sections()['related']` | 서버(통합검색 SERP qra) |
| 브랜드·키워드추천·상품속성 | `ShopFilterHtmlParser` | **붙여넣은 쇼핑 HTML**(서버 418) |
| 수식어(파생) | `deriveModifiers` — 추출 키워드에서 핵심어 제거 | 서버(속성 빈약 상품 대응) |
| 어미/수식어 | config `exposure.suffixes` + 입력창 | "{핵심} {어미}"(2단어) |
| 상품 URL 파싱 | `NaverShoppingRankService::resolveTarget` (+ `id_kind`) | 서버 |
| **노출 순위** | `NaverShopExposureService::exposure($kw,$target)` | 서버(m.search.naver.com 가격비교) |

### 노출 순위 = 모바일 검색 가격비교 오가닉(광고 제외)

- `https://m.search.naver.com/search.naver?where=m&query={kw}` 서버 fetch → 페이지에 박힌
  `newshopping["shopping"]._INITIAL_STATE`(initProps.pagedSlot[].slots[].data) 파싱(`NaverShopExposureService`).
  JS 리터럴 → JSON 정리(`undefined`·`new Date(...)` 제거) 후 중괄호 균형 추출.
- **광고성 슬롯 = `AD`(쇼핑검색광고) + `SUPER_POINT`(슈퍼적립 유료 프로모션)** — 오가닉이 아니다.
  실측('비타민c 유유제약'): rank 시퀀스가 AD / SUPER_POINT(1)+SAS(2~) 로 나뉘고 슈퍼적립이 화면 1위를 차지한다.
- **오가닉 순위 = SAS 슬롯의 네이버 자체 `rank` 필드**(슈퍼적립 포함 페이지 표기 순번 — 실제 화면 위치와 일치).
  rank 필드가 없으면 광고 제외 문서상 위치로 폴백. shop.json(sort=sim)이 아니다.
- **동일 상품이 광고성 슬롯에 있으면 `ad_exposed=true`**(조합별 저장·"광고" 배지·광고 노출 카드 분리 표기).
- **"노출 재확인" 버튼** — 노출(1~N위) 판정 조합만 미확인으로 리셋해 재확인(광고 판별 개선 전 오판 정정용).
- **캡차 오탐 방지** — 정상 SERP 에도 'captcha' 문자열이 있어(자산 경로) generic 매칭 금지. 차단 판별은
  캡차 URL 리다이렉트 또는 작은 페이지(<150KB)+한국어 차단 문구일 때만(실측 오탐 정정).
- **매칭**: 스마트스토어/브랜드 상품 = `channelProductId`(`id_kind=channel`), 가격비교 카탈로그(`/catalog/{nvMid}`) = `nvMid`(`id_kind=nvmid`), 그 외 = 업체명. `resolveTarget`이 URL로 `id_kind` 판별.
- **openapi 키 쿼터를 쓰지 않는다**(HTML 파싱) — fetch 주체는 기본 **확장(브라우저)**, 폴백만 서버(IP rate 보호로 배치 폴링·throttle·analysis 단위 락).
- **경쟁 브랜드**는 슬롯 `mallName`(희소) + **가격비교 브랜드 필터**(`filterSet[id=brand].values[].name`, 10개+)를 합친다(`brandNamesFromState`).
- 데스크톱 UA 로도 m.search 가 `pagedSlot` 을 그대로 내려줘(실측) 확장 background fetch 에 UA 세팅이 필요 없다.
- qra("함께 많이 찾는")는 서버/curl 에선 503 — **실제 크롬(확장 SW) fetch 로만** 안정 수집(실측).

## 조합 생성 (`buildCombos`) — 2~5단어 롱테일, 유형 태그(combo_tag) 보존

- 노출은 **내 상품 특이 재료(제목 단어·구절·SEO태그·브랜드)** 에서 나온다(실측: 속성 위주 251개 중 노출 1).
- 생성 순서 = combo_tag(각 tier 앞자리 확보, 라운드로빈에서 우선 생존):
  - A) `title` — 핵심 + 제목단어 부분집합(최우선).
  - B) `tag`/`phrase` — SEO태그 통짜·제목 연속 구절 그대로(핵심 미포함 허용 — 내 상품이 그 구절로 노출).
  - C) `brand` — 브랜드 + 핵심 + 제목단어. D) `brand_price` — +가격.
  - E) **tail 4종**(사용자 지정) — `title_attr`/`title_suffix` = 핵심+제목단어+속성/어미 1개,
    `brand_attr`/`brand_suffix` = 브랜드+핵심(+제목단어 1)+속성/어미 1개. 전부 3단어↑,
    풀 축소(제목단어 8·부분집합 ≤2)로 상위 티어를 밀어내지 않는다.
- **단독형 속성·어미 티어는 패턴 실측으로 제거** — 브랜드+핵심+속성 **0/298**, 핵심+어미 2단어 **1/20**
  (내 상품 데이터에 없는 단어 위주 조합은 매칭 실패. 참고: 제목단어 82~90%·제목구절 71~100%·브랜드+제목 68~100%).
  속성·어미는 tail 재료로만 쓴다.
- **속성 후보에서 경쟁 브랜드명 제외**(브랜드명이 여러 상품명에 반복 등장해 '속성'으로 오분류 — 실측 '고려은단').
- **제목 단어는 공백 분리**(판매자 제목 관례, 양끝 괄호·구두점만 정리). **브랜드도 제목 단어에 포함**.
  브랜드가 비면 **제목 첫 단어를 브랜드로**("유유제약 NCI200 …" 관례).
- **길이별 버킷 + 짧은 길이부터 라운드로빈**. 조합 수 선택 없음 — 전부 생성(`hard_cap` 안전선).
- **완화 정리**(`cleanLoose`): `1000mg`·`3M` 단독은 모델명 필터에 걸리지만 조합("비타민c 1000mg")은 유효 — 최종 조합만 `acceptableKeyword` 검증. `attrSubsets`는 크기 오름차순·`cap` 상한으로 폭발 방지.

### 조합 패턴 (결과 보관·학습)

- 조합마다 `combo_tag`(생성 유형)를 저장하고, **확인된 조합은 재생성 때도 삭제하지 않는다**(미노출은 hidden 보관).
- show 의 "조합 패턴" 카드가 유형×단어수별 확인/노출/노출률/광고를 집계 — 어떤 유형이 먹히는지 사용자가 보고 "새로 조합"을 돌린다.
- **패턴 기반 자동 제외**: "새로 조합" 때 이 분석에서 **12회 이상 확인됐는데 노출률 10% 미만**인 combo_tag 는
  더 만들지 않는다(예: SEO태그가 계속 0%면 자동 스킵). 보관한 확인 결과가 곧 학습 데이터.

## 데이터 모델

- `shop_keyword_analyses` — user_id, core_keyword, product_url/product_id/mall_name, threshold, token_count·combo_count·checked_count·exposed_count, status(checking|blocked|paused|done).
- `shop_keyword_analysis_items` — `kind`(token|combo) · `source`(autocomplete|related|together|brand|keyword_rec|attribute|modifier|suffix|combo) · keyword · **`combo_tag`**(title|phrase|tag|brand|brand_price|attr|suffix — 패턴 집계용) · `rank`(combo: null=미확인·0=순위밖·1~=순위) · monthly_total. **unique(analysis_id,kind,source,keyword)**(`ska_uni_src`) — 토큰은 소스 간 중복 허용(실제 검색 화면 개수와 일치), combo 는 source 가 항상 `combo` 라 종전과 동일.

## 코드

| 파일 | 역할 |
|---|---|
| [ShopKeywordExposureAnalyzer](../app/Domain/Shopping/ShopKeywordExposureAnalyzer.php) | prepare(추출·조합·저장) + checkBatch(배치 순위체크) |
| [ShopFilterHtmlParser](../app/Domain/Shopping/ShopFilterHtmlParser.php) | 쇼핑 필터 HTML → 브랜드·키워드추천(filter_value_id)·속성 |
| [ShopKeywordExposureController](../app/Http/Controllers/ShopKeywordExposureController.php) | index/store/check(폴백 폴링)/pending/checkHtml/supplement/refreshProductInfo/show/destroy |
| [admin/shop-keyword/index·show](../resources/views/admin/shop-keyword/) | 입력 폼 + 결과(확장 체크 루프·서버 폴백·노출/광고 키워드·전체 복사·전체 조합·소스별 추출) |
| [ShopKeywordAnalysis](../app/Models/ShopKeywordAnalysis.php) / [Item](../app/Models/ShopKeywordAnalysisItem.php) | 모델 |
| [extension/content/console-bridge.js](../extension/content/console-bridge.js) | 콘솔 ↔ 확장 브릿지(`rankfree-console` postMessage — fetchShopSerp·fetchKeywordSignals·collectProductPage) |
| [extension/background.js](../extension/background.js) | 위 3개 핸들러 — m.search fetch(_INITIAL_STATE 조각)·qra JSON·상품페이지 백그라운드 탭 수집(payload 회신) |

- 라우트: `admin.shop-keyword.*`(index/store/check/pending/check-html/supplement/product-info/pause/show/destroy) — [routes/web.php](../routes/web.php). `['auth','operator']` admin 그룹(운영자 전용). 공개 Short URL `/s/{token}` 은 그대로.
- **주문 연동(2026-07-22)**: 쇼핑 유입 주문(/admin/orders — field_values keyword·shop_url)에서 "수집요청" →
  `POST admin/orders/{order}/shop-keyword` 가 분석 생성 + `shop_keyword_analyses.marketing_order_id` 상호 연결(멱등).
  주문 목록 No(desc)·유입키워드 열, 주문 상세 연결 카드, 분석 상단 주문 역링크 — 발주 시 분석 Short URL 사용.
  **대표이미지(thumbnail_url)**: 확장 v0.3.9 상품페이지 수집이 채움(`shop_product_infos.thumbnail_url`, 상태 JSON→og:image→첫 이미지 폴백,
  관련 태그는 seller_tags + 상세 하단 `#태그` DOM 폴백) — 분석 상단에 미리보기·복사.
- 메뉴: 미등록이어도 접근 가능(menu.gate 기본 허용). 사이드바는 `/admin/menus`에서 **쇼핑** 그룹 하위로 수동 추가([[menus-via-admin-not-seeder]]).
- 설정: `config/rankfree.php` → `shopping.exposure`(top·**hard_cap**·max_tokens·attr_pool·batch_size·batch_sec·suffixes). 조합수 선택 UI 없음 — 전부 생성.
- 확장: manifest 0.3.6 — host `m.search.naver.com`·`s.search.naver.com` 추가, console-bridge content script 는 `admin/shop-keyword*`(+구 `console/shop-keyword*` prod 호환) 매칭. qra("함께 많이 찾는")는 **모바일+PC 합집합**. **확장 새로고침 + 페이지 새로고침 필요**.
- 테스트: `tests/Feature/ShopFilterHtmlParserTest.php` + `ShopKeywordExposureTest.php`(조합 생성·배치 노출판정·check-html·me 백필·supplement·product-info payload·어미·핵심포함·소유권).

## 후속 여지

- 순위 범위: m.search 첫 로드는 가격비교 슬롯 ~15개(실측) — 그 밖은 0(미노출). threshold 5 목적엔 충분.
- 조합 생성 rate limit(유저당 분석 빈도) — 쿼터 남용 방지.
- 광고 노출 키워드 활용(입찰 제안 등).
