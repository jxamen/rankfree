# 26. 쿠폰 — 마케팅 상품 주문 할인

> 관리자가 금액(정액/정률) 쿠폰을 만들어 **특정 회원 발행·전체 회원 일괄 발급**하거나 회원이 **마이페이지에서 직접 다운로드**하고,
> **셀프마케팅 상품 주문 시** 할인 적용한다. (2026-07-22 구현·Playwright 검증 완료)

## ⚠️ 라우트는 web.php 가 아니라 [routes/coupon.php](../routes/coupon.php)

쿠폰 라우트 전부(콘솔 download + 관리자 9개)는 **별도 파일** — [bootstrap/app.php](../bootstrap/app.php) `withRouting(then:)` 에서 로드.
여러 CLI 세션이 web.php 를 동시 편집하다 쿠폰 라우트가 반복 유실돼(2026-07-22 실제 5회) 분리했다. **web.php 로 다시 합치지 말 것.**

## 데이터 모델

- [coupons](../app/Models/Coupon.php) — 쿠폰 정의
  - `code`(자동 CP+날짜+랜덤, CS 추적용) · `name` · `discount_type`(amount|percent) · `discount_value` · `max_discount`(정률 상한) · `min_order_amount`
  - `starts_at`/`ends_at`(사용 가능 기간) · `valid_days`(발급일로부터 N일 — **종료일과 중 이른 쪽**으로 발급분 만료 확정)
  - `is_downloadable`(쿠폰함 노출) · `max_issuance`(총 발급 수량, 직접+다운로드 합산) · `product_ids`(JSON, null=전체 상품) · `is_active`
- [user_coupons](../app/Models/UserCoupon.php) — 발급분. **unique(coupon_id, user_id) = 1인 1매**(더 주려면 쿠폰을 새로 만든다)
  - `source`(admin|download) · `issued_by` · `expires_at`(발급 시 확정) · `used_at` · `order_id`
- `marketing_orders.user_coupon_id` + `discount_amount` — **total_price 는 할인 반영 최종가**, 원가는 unit_price×수량(×일수)로 복원

## 핵심 규칙

- **할인 계산은 [Coupon::discountFor()](../app/Models/Coupon.php) 한 곳** — 원 단위 내림·주문 금액 초과 불가·최소 주문 금액 미달이면 0. 주문 페이지 JS 는 미리보기일 뿐 **최종 금액은 항상 서버 재계산**.
- **동시성**: 주문 제출 시 user_coupon 을 `lockForUpdate` + `whereNull(used_at)` 재확인(이중 사용 방지). 다운로드는 unique 제약 + 수량 제한 쿠폰이면 coupon 행 잠금 후 잔여 재확인.
- **주문 취소·삭제 → 쿠폰 자동 복원**([MarketingOrder::restoreCoupon()](../app/Models/MarketingOrder.php) — used_at·order_id 초기화, 만료됐으면 만료 상태로 복귀). ⚠️ 취소를 다시 진행중으로 되돌려도 쿠폰은 재사용 처리되지 않는다(필요하면 재발행).
- 사용 가능 판정([UserCoupon::isUsable()](../app/Models/UserCoupon.php)): 미사용 + 발급분 미만료 + **쿠폰 활성·기간 재검사**(관리자가 기간을 줄였을 수 있음).
- 사용 이력이 있는 쿠폰은 **삭제 불가**(중지로 전환) — 미사용뿐이면 삭제 시 발급분도 회수(cascade).
- ⚠️ **발송(문자·메일) 기능 금지**(24 신규개업과 동일 원칙 — 정보통신망법 제50조). 쿠폰 안내는 쿠폰함 노출로만.

## 화면·흐름

- **관리자** `/admin/coupons` ([Admin\CouponController](../app/Http/Controllers/Admin/CouponController.php) · [index](../resources/views/admin/coupons/index.blade.php)/[show](../resources/views/admin/coupons/show.blade.php))
  - 목록: 통계(활성·발급·사용·누적 할인액) + 생성/인라인 수정(_form 공용) + 중지/삭제. **'쿠폰 만들기' 버튼은 본문 쿠폰 목록 헤더 우측**(헤더 page-actions 아님 — 2026-07-22 운영 요청).
  - 발급 관리(show): 회원 검색(이름·이메일·전화) → 개별 발행 / **전체 회원 발급**(미보유 전원, chunk 500 bulk insert, 수량 제한 시 잔여까지만) / 미사용분 회수. 발급 내역(출처·만료·상태·사용 주문 링크).
- **회원 쿠폰 = 마이페이지 통합** ([ConsoleController@me](../app/Http/Controllers/ConsoleController.php) · [me](../resources/views/console/me.blade.php)) — **별도 쿠폰함 페이지·콘솔 메뉴 없음**(2026-07-22 운영 요청 "메뉴가 너무 많아" — 콘솔 사이드바에 메뉴를 늘리지 말 것). 받을 수 있는 쿠폰(카드+쿠폰 받기 POST=[CouponController@download](../app/Http/Controllers/CouponController.php)) + 내 쿠폰(상태: 사용 가능/사용됨/만료/중지).
- **주문 적용** [OrderController](../app/Http/Controllers/OrderController.php) — show 가 이 상품에 쓸 수 있는 쿠폰만 내려주고([User::usableCoupons()](../app/Models/User.php) + appliesTo), [order/_coupon](../resources/views/order/_coupon.blade.php) select(라벨 아랫줄 배치 — label 은 block) + JS 미리보기(정액/정률·최소 주문 미달 경고, [order/show](../resources/views/order/show.blade.php) calc). store 는 검증(본인·사용 가능·상품 적용·최소 주문) 후 트랜잭션으로 주문 생성+사용 처리.
- 어드민 주문 상세에 쿠폰 할인 행(-금액·쿠폰명) 표시.
- 메뉴는 마이그레이션으로 등록([2026_07_22_000200](../database/migrations/2026_07_22_000200_add_coupon_menus.php)) — **관리자 '쿠폰 관리'만**(주문 관리 다음). 콘솔 메뉴는 만들지 않는다.

## 실측 (2026-07-22, 로컬 Playwright)

정액 5,000원 발행 + 정률 10%(최대 3,000) 다운로드 → 주문(단가 10,000×1): 미선택 10,000 / 10% 9,000 / 정액 5,000 로 미리보기·서버 금액 일치.
주문 취소 → used_at NULL 복원·'사용 가능' 복귀. 전체 발급 14명(1인 1매 스킵). 마이페이지 다운로드·고정 패키지 상품(수량 100×7일 고정, [27](./27_PRODUCT_PACKAGE.md)) 주문에 쿠폰 병행(70,000→65,000) 확인.

## 남은 과제

- 회원가입 시 자동 발급(웰컴 쿠폰) 트리거 — 지금은 수동 전체 발급으로 대체 가능.
- 쿠폰 코드 직접 입력(오프라인 배포용 코드 등록) — 현재는 발행·다운로드만.
