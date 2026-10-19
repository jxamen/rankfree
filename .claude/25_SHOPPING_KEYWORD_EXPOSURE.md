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

## 외부 API v1 (scope: shop_keyword) — 2026-07-26

> 분석 생성 → 순위 확인 자동 완주 → Short URL 그룹 생성을 외부에서 자동화. 인증은 기존 API 키 체계(28) 그대로.

| 메서드 | 경로 | 설명 |
|---|---|---|
| POST | `/api/v1/shop-keywords` | 분석 생성 + 확인 자동 시작 — `core_keyword`·`product`(필수), `threshold`, `check_method`, `product_info` |
| GET | `/api/v1/shop-keywords` | 내 분석 목록(page/per_page≤100) |
| GET | `/api/v1/shop-keywords/{id}` | 진행 상태 + `exposed_keywords` + `short_links` |
| POST | `/api/v1/shop-keywords/{id}/short-links` | Short URL 생성(`group_count` 1~100 — 노출 키워드를 그룹에 라운드로빈 분배) |
| GET | `/api/v1/shop-keywords/{id}/short-links` | Short URL 목록(group_no·url·keywords·hit_count) |
| POST | `/api/v1/shop-keywords/{id}/short-links/reassign` | 재배정 — URL(토큰·도메인) 유지, 키워드만 다시 분배 + cursor 0 |

- **확장 의존 처리(사용자 확정)** — 서버엔 확장을 켜 둘 수 없으므로 **브라우저로만 얻는 정보는 요청자 쪽에서 수집해 전달**한다.
  - `check_method=api`(기본): `checkBatch` 가 shop.json 으로 확인 → **확장 없이 서버 완결**(상위 40위·광고 판별 없음).
    생성 시 [ShopKeywordCheckJob](../app/Jobs/ShopKeywordCheckJob.php) 이 남은 조합 0 까지 자기 자신을 재큐(default 큐, 배치 15/약 7초 + 2초 간격).
    ⚠️ sync 커넥션에선 재큐가 곧 재귀라 **한 배치만** 돌고 끝낸다(테스트·로컬 보호).
  - `check_method=search`: 서버 IP 차단 시 `status=blocked` — 확장 켠 브라우저에서 분석 화면을 열면 이어서 처리.
  - **조합 재료(제목·브랜드·SEO태그)** 는 서버가 상품페이지를 못 여니 `product_info{title,brand,mall,price,seller_tags}` 로 받는다. 생략하면 조합이 빈약해진다(제목 기반 티어가 안 만들어짐).
- **Short URL 생성은 [ShopKeywordShortLinkService](../app/Domain/Shopping/ShopKeywordShortLinkService.php) 단일 소스** — 관리자 화면(`storeShortLinks`)과 API 가 공유(규칙 수정은 여기 한 곳).
- 소유권: API 키 소유 회원 = `analyses.user_id`(남의 분석 403). scope 없으면 403, 키 무효 401.
- 검증: [ShopKeywordApiTest](../tests/Feature/ShopKeywordApiTest.php) 7건(scope·생성/조합·서버 완결·그룹 분배·노출 0 시 422·소유자 격리·목록) + 로컬 실호출(키 발급 → 생성 217조합 → 워커 자동 재큐 확인 → show/목록/422).

## 후속 여지

- 순위 범위: m.search 첫 로드는 가격비교 슬롯 ~15개(실측) — 그 밖은 0(미노출). threshold 5 목적엔 충분.
- 조합 생성 rate limit(유저당 분석 빈도) — 쿼터 남용 방지.
- 광고 노출 키워드 활용(입찰 제안 등).


### 주문별 수집 상한 (2026-10-18)
외부 생성 API의 `order_start_date`/`order_end_date`(Y-m-d, 양끝 포함)로 `order_days`와 `keyword_target`을 저장한다. 목표는 `SHOP_KEYWORDS_PER_DAY`(기본 50) × 실제 주문 일수이며 Short URL 그룹만 100개로 제한한다. 5위 이내 고유 키워드 수가 목표에 닿으면 `target_reached`로 저장하고 큐·배치·HTML 결과 추가 저장·재조합을 중단한다. 지연 도착 HTML은 분석 행 잠금 안에서 목표를 다시 검사한다. 대상 주문의 Short URL 키워드는 5위 이내 중 목표 개수까지만 채택한다. 목록·상세와 API는 상태/목표/기간을 표시한다. API 목록 `collection_limits=true`는 부스팅샵의 배포 호환성 확인용이다. 상품정보 큐는 배송비 null도 재수집 대상으로 보되 24시간 쿨다운을 유지한다(0은 무료배송 완료).

### 2026-10-18 제한 상태 이관·해제 (rf-unblock)

- `shop-serp:server-policy.until`(초), 확장 `rfSerpPolicy.until`(밀리초)은 로드 시 현재+3분을 넘으면 현재+3분으로 제한한다. 통계·실제 차단 이벤트는 보존한다.
- 확장 큐 `rfSkwCheckSkipUntil`은 네이버 차단 사유 또는 정책 만료와 5초 이내 일치하는 예전 값만 함께 이관한다. 새 `rfSkwCheckSkipReason=permission`의 API 권한 없음 6시간 대기는 유지한다.
- 실제 정상 요청은 정책 대기, 네이버 큐 대기, 확장 배지·재확인 알람을 해제한다. 성공 이벤트는 분석의 blocked/error 및 오류 캐시를 즉시 해제하며 paused/target_reached/ad_ended는 유지한다. 당일 캐시 응답은 실제 네트워크 차단 해제 증거로 쓰지 않는다.
- 관리자 화면은 반환된 retryAt/retry_at 기준 남은 초를 매초 표시하고, 정상 응답 직후 확인 중으로 바꾼다. 실패한 통신 응답만으로 차단 상태를 해제하지 않는다.


### 2026-10-18 브라우저 차단 → 서버 수집 (rf-fallback)

- 실제 브라우저 응답의 403·429·보안문자 보고만 분석별 `shop-exposure:server-fallback:{id}`를 영구 캐시에 저장한다. 통신 오류·확장 무응답·단순 쿨다운은 전환하지 않는다. 새로고침·간격 초기화·늦은 성공 보고도 서버 선택을 유지한다.
- 관리자 화면은 즉시 기존 server lane으로 미확인 조합을 이어 확인하고 상태 줄에 `브라우저 막힘 → 서버로 진행 중`을 표시한다. API 분석은 기존 database 큐의 `ShopKeywordCheckJob`을 한 번 시작해 화면 없이 이어간다. 이후 확장 대기열과 늦은 브라우저 HTML은 그 분석을 다시 수집·저장하지 않는다.
- 전환된 분석은 slot API 대신 기존 `NaverShopExposureService::exposure()`로 모바일 검색 HTML을 수집한다. 서버 IP별 공용 lane·2초 시작·차단당 +0.3초(최대 10초)·3분 재확인·KST 날짜/관리자 간격 초기화 정책을 재사용한다. 서버도 막히면 브라우저로 되돌리지 않고 미확인 순위를 유지한 채 서버 retry_at까지 양쪽 모두 네트워크 요청을 쉬며 서버만 재확인한다.
- paused/target_reached/ad_ended와 소유자·scope 검증을 유지한다. 서버 수집 선택은 기존 캐시 저장소를 사용하므로 캐시 전체 삭제 시 사라진다. 운영 파일/DB 변경 없이 PR 병합 대기 상태로 제공한다.


## 통합검색 우선 확인·집계 동기화 (2026-10-18, rf-interval 후속)

- 통합검색은 document_start부터 표시된 쇼핑 영역/메뉴를 확인한다. 별도 체류·전체 로딩 대기는 없다. 쇼핑 영역이 있으면 순위 밖·광고만·빈 블록이어도 그 결과로 종료하며, 영역이 없을 때만 쇼핑 메뉴로 이동한다. 통합검색 스크롤 없음.
- 쇼핑은 complete + 표시된 상품 목록 + 상품 데이터를 기다린 뒤 문서당 스크롤 1회·750ms 대기 후 읽는다. 복구 probe도 같은 준비 상태를 공유한다. 빈 검색 결과는 스크롤 없이 확정한다.
- `shop_keyword_analysis_items.result_source`는 integrated/shopping/null(기존 또는 서버 결과)이며 결과 목록에 통합/쇼핑을 표시한다. 새 컬럼 migration은 병합 후 적용해야 한다.
- IP 수집 잠금은 같은 분석이라도 다른 실행자를 동시에 허용하지 않는다. 기존 주인은 갱신 가능하고, 중단·명시적 바로 이어서·TTL 경과 후 다른 실행자가 받는다.
- pending/check-html/서버 배치의 `exposed_items`는 숨김 제외·1~threshold·발견 순서의 전체 스냅샷이다. 화면은 스냅샷으로 목록·번호·복사·상단 숫자를 동기화한다. 최초 렌더 요약도 목록과 같은 현재 조합에서 계산한다.
- #71 읽기 전용 조사: 16:46:09까지 처음 확인된 조합 103개 중 상위5위 66개(고유 키워드도 66개), 숨김 없음. 16:45~16:46 관리자 화면과 확장 API의 check-html이 교차했다. 이전 잠금은 같은 분석의 실행자 교체를 무조건 허용했고, 화면은 자기 응답의 단일 결과만 목록에 추가해 외부 저장 결과가 누락됐다. 중복 카운팅이나 한 검색으로 여러 조합을 판정한 것이 아니다. 근거는 `docs/evidence/rf-wait/`에 보관한다.

## 0.6.2 실시간 진행·당일 재사용·탭 없는 수집

- 매 pending 정책 응답과 check-html 결과에서 exposed_items 전체 스냅샷으로 목록·복사·숫자를 함께 갱신한다. 결과 1건마다 반영하고, 확장 현재 키워드/화면 상태는 1초마다 읽는다. 직전 키워드·화면·순위는 별도 줄에 유지한다.
- 생성 API는 같은 사용자·핵심 키워드·기존 상품 매칭 기준에 KST 당일 created_at 범위를 적용한다. 사용자별 생성 잠금으로 중복 생성 경합을 막고, 미확인 조합만 이어간다. 완료/목표도달 결과는 유지한다. 응답 reused(boolean), reuse_mode(new/reused), reuse_label(새로/재사용(이어서)).
- AppSetting shop.exposure.collection_mode=tab(기본)/fetch를 간격 옆에서 저장한다. 서버 pending/확장 interval-policy가 같은 값을 전달한다. fetch는 통합검색 HTML 쇼핑 영역 판정 후, 영역이 없을 때만 모바일 쇼핑 HTML을 요청한다. 쇼핑 __NEXT_DATA__ 목록은 기존 판정용 _INITIAL_STATE로 변환한다.
- 두 모드 모두 공용 직렬 lane을 거쳐 이전 수집 완료 후 설정 간격 이상 대기한다. fetch의 통합→쇼핑 요청 사이에도 유효 간격을 기다린다. 차단은 3분 후 재확인·+300ms, 보안문자는 탭 모드로 바꿔 사람이 풀도록 안내한다. 자동 풀이·IP 변경·브라우저 차단 시 서버 전환을 추가하지 않는다.

## 상품정보 재수집 0.6.7.2 (2026-10-18)

- 상품 탭 대기자를 탭 생성 전에 등록하고 추출 payload는 별도 저장 API 완료 전에 전달한다. 25초 탭 대기와 30초 API 제한이 충돌하지 않는다. 상품 탭 또는 상품 큐 저장이 진행 중이면 SERP 감시자의 runtime.reload를 미룬다.
- 상품 URL 그대로 표시되는 네이버 제한 본문과 nid/captcha/ncpt 이동을 감지한다. 제한 제목은 상품명으로 저장하지 않는다. 제한 탭은 보존·재사용하고 자동 풀이는 하지 않는다.
- 상품 큐는 실패를 `collector-event(kind=client_error, stage=product)`로 보고하고 다음 3분 알람에 재시도한다. 상태는 분석별 24시간 캐시에 보관하고 소유자만 product-status로 읽는다. SERP 차단·서버 전환·간격에는 영향을 주지 않는다.
- 화면은 실패 이유를 버튼 옆에 즉시 표시하고 60초 뒤 재시도한다. 큐 상태는 15초마다 확인한다. 저장 HTTP/JSON 오류도 표시하며 성공한 저장만 새로고침한다. 이미지 누락도 태그/배송비와 같은 24시간 보충 큐 대상으로 처리한다.
- 재현: `node --test tests/extension/*.test.cjs`, `node scripts/test-product-recollect-browser.cjs` (로컬 fixture + 실제 Chromium 확장/브릿지/상품 content script).

## API 연속 분석 자동 인계 0.6.8.7 (2026-10-19)

- 상세 화면 없이 API로 시작한 분석은 로그인된 요청자 확장의 product/check 큐가 처리한다. 서버만으로 브라우저 검색을 시작할 수 없으므로 확장 업데이트가 필요하다.
- 워커 부팅 직후와 실행 중 5초마다 두 큐를 확인한다. 상품 탭이 느려도 이미 조합이 있는 분석은 독립적으로 확인한다. 빈 큐 뒤 생성되는 다음 주문도 다음 tick에서 받는다. 30초 알람은 워커 종료 후 복구용이며 기존 1분 watchdog은 유지한다. 브라우저 종료·절전·네트워크 중단 중에는 수 초 시작을 보장하지 않는다.
- 화면 요청의 분석 ID를 기억하고 최근 60초 이내 요청이 있었다면 collector-state로 상태를 확인한다. done/target_reached/ad_ended/paused일 때만 양보 시간을 해제한다. 확인 도중 새 화면 요청이 생기거나 상태 조회가 실패하면 양보를 유지한다.
- 큐 확인 주기만 바꾼다. SERP 요청은 기존 ShopSerpPolicy/Pool의 간격·동시 수·차단 대기, 서버 IP 잠금·claim, paused 및 목표 상한을 그대로 따른다. 상품 큐에도 storage await 뒤 중복 진입 검사를 둔다. 보안문자 자동 풀이·로그인 자동화·탐지 회피는 추가하지 않는다.
- 검증: `node --test tests/extension/*.test.cjs`, `node tests/extension/shop-batch-autostart.browser.cjs`, `ShopBatchAutostartTest` 및 기존 잠금/claim/목표 테스트. browser 테스트는 실제 MV3 워커·5초 타이머를 쓰고 큐 및 SERP 응답은 로컬 fixture로 제한한다.
