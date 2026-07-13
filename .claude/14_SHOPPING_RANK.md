# 14. 쇼핑 순위추적

> 콘솔 `console.shop-rank` — 네이버 쇼핑 검색(openapi shop.json) 순위추적. **플레이스 순위추적(`console.rank`) 구조를 미러링**.
> 원본: crm `naver_shopping_rank_api()`.

## 개념

- **슬롯 = (키워드 × 대상)**. 대상 = **상품 URL(productId)** 또는 **업체명(mallName)**.
- 키워드로 `shop.json` 검색(sort=sim) → 상위 `display×max_pages`(=1000)위까지 순회하며 대상 매칭 → 순위 기록.
- 하루 1회 기록(당일 재확인 시 갱신, `updateOrCreate(slot,date)`).

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
