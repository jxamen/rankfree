# 28. 외부 주문 API — 마케팅 상품 주문 v1 (scope: order)

> /admin/products 의 활성 상품을 **외부 시스템이 API 키로 주문**한다. (2026-07-22 구현·테스트 9건·실호출 검증)
> 개발자 문서는 **본문 공용 partial** [partials/developers-doc](../resources/views/partials/developers-doc.blade.php) — 공개 [/developers](../resources/views/site/developers.blade.php) 와 **콘솔 [/console/developers](../resources/views/console/developers.blade.php)** 두 곳에서 렌더(내용 수정은 partial 한 곳만). **주제별 탭**(시작하기·순위추적·경쟁분석·키워드분석·상품 주문, 해시 딥링크 #order 등). 콘솔 사이드바 'API › API 문서' 메뉴(DB)는 콘솔 문서로 연결.
> 상품 번호(product_id)는 관리자 상품 목록의 **번호(API)** 열 = `GET /products` 의 `id`.

## 핵심 원칙 — 주문 로직 단일화

- 검증·금액 계산은 **[OrderPlacer](../app/Domain/Order/OrderPlacer.php) 한 곳** — 웹([OrderController](../app/Http/Controllers/OrderController.php))과 API([Api\OrderApiController](../app/Http/Controllers/Api/OrderApiController.php))가 공유한다. **주문 규칙을 고칠 땐 OrderPlacer 만 고친다**(컨트롤러에 규칙 넣지 말 것).
  - 동적 필드(필수·DATE 최소 시작일·contains·플레이스 URL 정규화) → 수량/기간(27번 고정값 강제 포함) → 쿠폰(26번, 잠금 재확인) → 트랜잭션 생성까지 전부.
  - 입력 오류는 [OrderInputException](../app/Domain/Order/OrderInputException.php)(field, message) — field 규약: 동적 필드 `f_{field_key}` · `quantity` · `days` · `user_coupon_id`. 웹은 폼 에러 키로, API 는 422 응답의 `field` 로 그대로 노출.
- **금액은 부가세 별도** — 상품 단가(`min_price`)와 주문 `total_price` 는 **공급가액**이다. 부가세는 저장하지 않고 [Vat](../app/Support/Vat.php)(`RATE` 10%, 원 단위 절사)로 **표시할 때만** 더한다.
  - 고객이 보는 결제·입금 금액 = 공급가액 + 부가세 — 주문 폼([order/_amount](../resources/views/order/_amount.blade.php) + show 의 calc()), 주문 완료 입금 안내, 접수 내역이 모두 부가세 포함으로 표기. 쿠폰 할인은 공급가액에서 먼저 빼고 그 위에 부가세를 붙인다.
  - 검증: [OrderVatDisplayTest](../tests/Feature/OrderVatDisplayTest.php) 4건. GTM 구매 전환 `value` 는 공급가액 유지.
- **파일(FILE/IMAGE) 필드는 웹 전용** — 업로드 저장은 웹 컨트롤러가 하고 경로만 서비스에 전달. 필수 파일 필드가 있는 상품은 API 에서 `orderable: false` + 주문 시 422.

## 회원별 기능 권한 (2026-07-26) — 다 열어주지 않는다

- `users.api_scopes`(JSON) = **관리자가 허용한 기능만** 그 회원이 쓸 수 있다. **기본값은 없음(전부 차단)**, 슈퍼관리자만 예외로 전체.
  - [User::allowedApiScopes()](../app/Models/User.php) / `canUseApiScope()` — 정의에서 사라진 scope 는 무시.
- **3중 게이트**: ① 발급 — 허용 밖 scope 는 [ApiKeyController](../app/Http/Controllers/ApiKeyController.php) 검증에서 반려(권한 0 이면 발급 자체 차단),
  ② 화면 — 콘솔 발급 폼에 허용된 기능만 노출(없으면 안내 문구), ③ **런타임** — [AuthenticateApiKey](../app/Http/Middleware/AuthenticateApiKey.php) 가
  키 scope 통과 후 회원 권한을 다시 확인해 **권한 회수 시 이미 발급된 키도 즉시 403**(`allowed_scopes` 동봉).
- 관리자 편집: `/admin/members` 회원 수정 폼의 **API 사용 권한** 체크박스([MemberController::update](../app/Http/Controllers/Admin/MemberController.php), 운영자만). 전부 해제 = 사용 불가.
- **무중단 이관**: 마이그레이션이 기존 API 키들의 scope 합집합을 각 회원 `api_scopes` 로 옮긴다(배포 순간 기존 연동이 403 되지 않게).
- 검증 [MemberApiScopeTest](../tests/Feature/MemberApiScopeTest.php) 9건(기본 차단·슈퍼 전체·발급 차단/허용·폼 노출·**권한 회수 시 기존 키 403**·관리자 편집 라운드트립).

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
