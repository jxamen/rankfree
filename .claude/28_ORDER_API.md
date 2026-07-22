# 28. 외부 주문 API — 마케팅 상품 주문 v1 (scope: order)

> /admin/products 의 활성 상품을 **외부 시스템이 API 키로 주문**한다. (2026-07-22 구현·테스트 9건·실호출 검증)
> 개발자 문서는 **본문 공용 partial** [partials/developers-doc](../resources/views/partials/developers-doc.blade.php) — 공개 [/developers](../resources/views/site/developers.blade.php) 와 **콘솔 [/console/developers](../resources/views/console/developers.blade.php)** 두 곳에서 렌더(내용 수정은 partial 한 곳만). **주제별 탭**(시작하기·순위추적·경쟁분석·키워드분석·상품 주문, 해시 딥링크 #order 등). 콘솔 사이드바 'API › API 문서' 메뉴(DB)는 콘솔 문서로 연결.
> 상품 번호(product_id)는 관리자 상품 목록의 **번호(API)** 열 = `GET /products` 의 `id`.

## 핵심 원칙 — 주문 로직 단일화

- 검증·금액 계산은 **[OrderPlacer](../app/Domain/Order/OrderPlacer.php) 한 곳** — 웹([OrderController](../app/Http/Controllers/OrderController.php))과 API([Api\OrderApiController](../app/Http/Controllers/Api/OrderApiController.php))가 공유한다. **주문 규칙을 고칠 땐 OrderPlacer 만 고친다**(컨트롤러에 규칙 넣지 말 것).
  - 동적 필드(필수·DATE 최소 시작일·contains·플레이스 URL 정규화) → 수량/기간(27번 고정값 강제 포함) → 쿠폰(26번, 잠금 재확인) → 트랜잭션 생성까지 전부.
  - 입력 오류는 [OrderInputException](../app/Domain/Order/OrderInputException.php)(field, message) — field 규약: 동적 필드 `f_{field_key}` · `quantity` · `days` · `user_coupon_id`. 웹은 폼 에러 키로, API 는 422 응답의 `field` 로 그대로 노출.
- **파일(FILE/IMAGE) 필드는 웹 전용** — 업로드 저장은 웹 컨트롤러가 하고 경로만 서비스에 전달. 필수 파일 필드가 있는 상품은 API 에서 `orderable: false` + 주문 시 422.

## 인증·엔드포인트

기존 외부 API 키 체계([AuthenticateApiKey](../app/Http/Middleware/AuthenticateApiKey.php) — Bearer `rk_…`, 활성/만료/허용 IP/일일 한도) 그대로, **scope `order`** 추가([ApiKey::SCOPES](../app/Models/ApiKey.php) — 콘솔 API 키 발급 화면에 자동 노출). 라우트는 [routes/api.php](../routes/api.php) v1 그룹.

| 메서드 | 경로 | 설명 |
|---|---|---|
| GET | `/api/v1/products` | 활성 상품 목록(단가·과금방식·min/max·고정값·earliest_start_date·orderable) |
| GET | `/api/v1/products/{id}` | 상세 + `fields` 스펙(key·type·required·options·contains·api_supported) |
| POST | `/api/v1/orders` | 주문 생성(throttle 30/분) — `product_id`·`quantity`·`days`·`fields{}`·`user_coupon_id` |
| GET | `/api/v1/orders` | 내 주문 목록(status 필터·page/per_page≤100) |
| GET | `/api/v1/orders/{orderNo}` | 주문번호로 단건 조회(본인 것만 — 남의 주문 404) |

- 주문자 = **API 키 소유 회원**. 생성 상태 `pending` → 운영자 승인(발주 18번)은 기존 흐름 그대로.
- `quantity`/`days` 는 상품에 `daily_qty`/`start_date`/`end_date` 시스템 필드가 있으면 **fields 로 대신 전달**(웹과 동일 규칙 — OrderPlacer 의 scheduleFields).
- `fields` 값은 스칼라(또는 MULTI_SELECT 용 문자열 배열)만 허용 — 파일 경로 주입 방지 필터를 컨트롤러에서 거친다.

## 검증

- 피처 테스트 [OrderApiTest](../tests/Feature/OrderApiTest.php) 9건: scope 차단(403)·비활성 상품 제외·필수 파일 상품 주문 불가·total 주문 금액·**고정 수량/기간 강제(다른 값 보내도 100×7=70,000)**·필수 필드 422(f_key)·수량 범위·쿠폰 적용/재사용 차단·목록/단건 소유자 격리(404).
- 로컬 실호출(curl): 키 발급 → 상품 조회 → 주문 생성 → 어드민 주문 목록에 표시 확인.

## 주의

- 주문 API 로 들어온 주문도 웹 주문과 같은 테이블(marketing_orders)·같은 관리 화면 — 구분 컬럼은 없다(필요해지면 `source` 컬럼 추가).
- API 키는 콘솔 → API 키에서 회원이 직접 발급 — 외부 업체에 줄 키는 **회원 계정을 만들어 그 계정으로 발급**(주문이 그 회원 소유가 된다). 일일 한도·허용 IP 설정 권장.
