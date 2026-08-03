# 14. 쇼핑 순위추적

> 콘솔 `console.shop-rank` — 네이버 쇼핑 검색 순위추적. **플레이스 순위추적(`console.rank`) 구조를 미러링**.
> 원본: crm `naver_shopping_rank_api()`.

## 🔴 데이터 소스 붕괴 — openapi shop.json 종료 (2026-07-31)

네이버가 **검색 쇼핑/책/전문자료 API 제공을 2026-07-31 종료**했다(개발자센터 공지 32564, 등록 2026-06-30).
`openapi.naver.com/v1/search/shop.json` 은 이제 **404 `errorCode=SE05` "존재하지 않는 검색 api 입니다"** 를 돌려준다. **재시도로 복구되지 않는다.**

실측(2026-08-03, 등록 키 5개):

| 키 | 응답 |
|----|------|
| #1 | `429 {count/quota=25000/25000}` — 구 앱 잔존, 한도 소진 상태로 남음 |
| #2~#5 | `404 SE05` — API 자체가 사라짐 |

**응급 조치(2026-08-03):**
- `NaverShoppingRankService` 가 404+SE05 를 `error=api_discontinued` 로 구분한다. **429(한도)와 절대 뭉뚱그리지 않는다** — 그러면 "잠시 후 재시도"를 영원히 안내하게 된다
- 콘솔·어드민·게스트 결과 화면이 폐지 사실을 그대로 알린다
- `shop:track-run` 크론(08:00·20:00) **중단**. 되살리면 죽은 API 를 매일 두들기며 슬롯마다 '미발견'을 기록한다. `ScheduledCommandsTest` 가 스케줄에 없음을 검증한다

**영향:** 콘솔·어드민 순위추적 / 게스트 무료 쇼핑 순위조회(`/shop-check`, 퍼널 진입점) / 노출 키워드 분석의 순위체크 / `OrderRankTracker`

## 수집 경로 실측 (2026-08-03, Playwright)

후보를 전부 찔러본 결과 — **서버가 직접 부를 수 있는 경로는 없다.**

| 경로 | 비로그인 | 로그인 실브라우저 |
|------|----------|-------------------|
| `openapi /v1/search/shop.json` | 404 `SE05` (폐지) | 동일 |
| `search.shopping.naver.com/search/all` | 307 → 로그인 | **405 + 보안확인(캡차)** |
| `search.shopping.naver.com/ns/search` | **418** (봇차단) | 첫 회 성공 → **반복 시 캡차**(100초 쿨다운으로도 안 풀림) |
| `m.search.naver.com/search.naver?where=m` | 200 | 200 — 단 실사용에서 자주 차단됨 |

**418 의 정체는 nCaptcha 토큰 부재다.** 확장 시장분석 수집기 주석에 이미 적혀 있다 —
"검색 API는 nCaptcha 토큰 없으면 418". 확장은 검색 페이지에서 토큰을 캡처해
`x-wtm-ncaptcha-token` 으로 **같은 오리진**에 부르기 때문에 418·캡차를 피한다.
→ 그래서 **시장분석 수집기(`collectShopping`)를 그대로 재사용**한다(사용자 확정).

## 확장 워커 풀 (2026-08-03 구현)

```
확장(알람 1분) ──POST /api/ext/shop-rank/claim────────▶ 서버: 원자적 claim
      │                                                   (여러 대 켜져 있으면 자연 분산)
      ├─ collectShopping({keyword, count})  ← 시장분석 수집기 그대로
      │
      └──POST /api/ext/shop-rank/{job}/result──────────▶ 서버: 매칭·순위 계산 → 슬롯/일별기록
         POST /api/ext/shop-rank/{job}/fail            (캡차 → 백오프 후 재큐)
```

| 파일 | 역할 |
|------|------|
| [ShopRankJob](../app/Models/ShopRankJob.php) | 큐 · **원자적 claim**(조건부 UPDATE 영향행수로 소유권 판정) · 리스 회수 · 백오프 재시도 |
| [ShopRankFromProducts](../app/Domain/Shopping/ShopRankFromProducts.php) | 수집 목록 → 순위. **광고 제외 오가닉 위치**(광고는 ad=true 로 별도) |
| [ExtShopRankController](../app/Http/Controllers/Api/ExtShopRankController.php) | claim / result / fail |
| [ShopRankSlotService](../app/Domain/Shopping/ShopRankSlotService.php) | `enqueue()` · `applyJobResult()` |
| [background.js](../extension/background.js) | `drainShopRankQueue()` — 1분 알람 · 화면작업 양보 · 캡차 30분 백오프 · 1.2~2.1초 페이싱 |

**설계 규칙:**
- **매칭·순위 계산은 서버**에 둔다 — 확장은 수집만. 규칙이 두 곳으로 갈라지면 어긋난다
- `claim` 은 select→update 로 나누지 않는다. 워커 여러 대가 같은 작업을 가져간다(check-then-act)
- 리스(180초) 만료 시 회수 — 워커가 죽어도 작업이 영원히 묶이지 않는다
- **캡차는 그 PC 문제**다. 작업을 버리지 않고 백오프 후 재큐 → 다른 워커가 집어간다
- 늦게 도착한 결과는 `claimed_by` 불일치로 409 — 다른 워커의 결과를 덮지 않는다
- 경로 전환은 `SHOP_RANK_SOURCE`(기본 `extension`). 구 `api` 는 폐지됐지만 코드·테스트는 남겨 둔다

**한계:** 순위 범위는 확장이 수집한 페이지 수까지(1페이지=80위). 깊은 순위는 요청이 늘어 캡차 위험이 커진다.
**확장이 켜진 PC 가 한 대도 없으면 큐만 쌓인다** — 결과가 늦을 뿐 유실은 아니다.

## (참고) 전환 방향 논의 — 확장 워커 풀

**서버 크롤링은 채택하지 않는다** — 네이버 쇼핑 검색은 서버 IP 크롤링 차단이 강하다.
대신 **확장이 설치·실행 중인 PC 들을 워커 풀로** 쓴다.

- 서버가 작업(키워드 × 대상)을 큐에 넣고, **요청이 있을 때만** 확장이 백그라운드로 수집해 회신
- **여러 대가 켜져 있으면 분산** — 워커가 작업을 원자적으로 claim 해 중복 수집을 막는다
- 재사용할 선례: 쇼핑 노출 키워드 분석(25)이 이미 같은 구조로 동작 중 —
  `chrome.alarms`(3분) → `GET /api/ext/shop-keyword/check-queue` → `POST .../check-html`.
  확장은 `search.shopping.naver.com` 권한을 이미 갖고 있다

**설계 시 먼저 풀어야 할 것:**
1. **반응 속도** — 게스트 무료 조회는 사람이 결과를 기다린다. 3분 폴링으로는 못 쓴다. long-poll(확장이 대기 요청을 걸어두고 서버가 작업 생기면 즉시 응답)을 우선 검토
2. **claim 원자성** — 워커 여러 대가 같은 작업을 가져가면 안 된다. 리워드 `QuotaGate` 의 2-step 원자 UPDATE 패턴을 따른다
3. **워커 0대일 때** — 큐만 쌓이고 응답이 없다. 대기 상한과 정직한 안내가 필요하다
4. **MV3 service worker 수명** — `setInterval` 은 죽는다. `chrome.alarms` 최소 주기는 1분

## 개념

- **슬롯 = (키워드 × 대상)**. 대상 = **상품 URL(productId)** 또는 **업체명(mallName)**.
- 키워드로 `shop.json` 검색(sort=sim) → 상위 `display×max_pages`(=1000)위까지 순회하며 대상 매칭 → 순위 기록.
- 하루 1회 기록(당일 재확인 시 갱신, `updateOrCreate(slot,date)`).
- **3일 연속 미노출 자동 중단**(2026-07-24): 최근 순위 기록 3건이 모두 rank 0(=1000위 밖 미노출)이면 슬롯을 `is_active=false`로 일시정지(삭제 아님 · 목록 [재개]로 복구). 차단(-1) 기록은 판정 제외([ShopRankSlotService](../app/Domain/Shopping/ShopRankSlotService.php)). 플레이스([RankSlotService](../app/Domain/Place/RankSlotService.php), 300위 기준)와 **동일 정책**. 콘솔 안내 문구는 `console/rank`·`console/shop-rank` 뷰 하단 설명 문단.

## 데이터 소스

- `GET https://openapi.naver.com/v1/search/shop.json?query=&display=100&start=&sort=sim` · 헤더 `X-Naver-Client-Id/Secret`.
- **다중 키 로테이션**: `config('rankfree.shopping.api_keys')`(콤마 구분 `id:secret`, `.env NAVER_SHOPPING_API_KEYS`). **429**(한도) 시 다음 키로 처음부터 재스캔.
- 키워드는 **공백 제거** 후 요청. lprice=가격, productId/mallName/link 로 매칭.

## 대상 파싱 (`NaverShoppingRankService::resolveTarget`)

| 입력 | productId |
|------|-----------|
| smartstore/brand `.../products/{id}` | 경로 5번째 |
| `search.shopping.naver.com/.../{id}` | 경로 4번째 |
| URL 아님 | **업체명(mallName)** 매칭 |

매칭: 상품 = `productId==` 또는 `link` 에 id 포함 / 업체 = `mallName` 포함(공백·대소문자 정규화).

## 코드 (Place 미러)

| Place | Shopping |
|-------|----------|
| `PlaceRankChecker`(엔진) | [NaverShoppingRankService](../app/Domain/Shopping/NaverShoppingRankService.php) — `resolveTarget`·`checkRank`(순수 HTTP) |
| `RankSlotService`(오케스트레이션) | [ShopRankSlotService](../app/Domain/Shopping/ShopRankSlotService.php) — `resolve`·`addMany`·`add`·`run` |
| `RankTrackController` | [ShopRankTrackController](../app/Http/Controllers/ShopRankTrackController.php) — index/resolve/store/run/update/destroy/shared |
| `place_rank_slots/records` | `shop_rank_slots`(keyword,target_type,product_id,mall_name,product_url,product_title,last_rank,last_price,share_token) / `shop_rank_records`(rank,price,list_total,checked_date) UNIQUE(slot,date) |
| `console/rank.blade.php` | [console/shop-rank.blade.php](../resources/views/console/shop-rank.blade.php) + [shop-rank/partials/cells](../resources/views/shop-rank/partials/cells.blade.php) + [shop-rank/share](../resources/views/shop-rank/share.blade.php) |

- 라우트: `console.shop-rank.*`(index/resolve/store/run/update/destroy) + 공개 `shop-rank.shared`(`/sr/{token}`) — [routes/web.php](../routes/web.php).
- 슬롯 한도: `User::rankSlotLimit()` 공유, 사용량은 `shopRankSlotsUsed()`(별도 카운트).
- 메뉴: PermissionSeeder `console.shop-rank`(🛒).
- 차단(429 전 키 소진) 기록 rank = **-1**(cells 에서 "차단"), 범위 밖 = 0("1000+").
- 테스트: `tests/Feature/ShopRankServiceTest.php`(파싱·매칭·429 로테이션) + `ShopRankTrackTest.php`(index·store+run·중복·run JSON·403·공유).

## 한계 / 주의

- shop.json 은 상위 1000개까지만(display 100 × max 10p). 그 밖은 "1000+".
- 업체명 매칭은 동명 업체·부분일치 주의(정확 매칭 필요 시 상품 URL 권장).
- API 키는 `.env` 에만(하드코딩 금지). 429 잦으면 키 추가.
- 향후: 엑셀 export(플레이스처럼) · 자동 일배치 · 알림.
