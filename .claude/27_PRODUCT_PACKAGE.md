# 27. 마케팅 상품 — 고정 수량·기간(패키지) + 복제

> "설명회에서 '이 키워드는 이 상품 사세요' 하면 고객이 광고주에게 묻지 않고 **그대로 주문**"하는 패키지 판매(2026-07-22 운영 요청).
> 값을 비우면 기존처럼 직접 입력 — 두 방식 공존.

## 고정 수량·기간

- `marketing_products.fixed_quantity` / `fixed_days`(nullable, [마이그레이션](../database/migrations/2026_07_22_001000_add_fixed_qty_days_to_marketing_products.php)) — 상품 폼 '가격 · 수량' 카드에서 입력.
- **주문 페이지**([order/show](../resources/views/order/show.blade.php)): 고정 값은 readonly + 잠금 스타일로 표시만 하고 못 바꾼다.
  - `fixed_days` + 시작/종료일 필드 상품: **고객은 시작일만 고르고 종료일은 자동**(시작+고정일-1, JS `data-fixed-days`).
- **서버 강제**([OrderController@store](../app/Http/Controllers/OrderController.php)): 화면 제출값을 믿지 않는다 — `fixed_quantity` 있으면 수량 무조건 그 값(field_values 의 일수량도 덮어씀), `fixed_days` 있으면 일수·종료일을 서버가 재계산. min/max·min_days 검증은 고정일 때 건너뜀(고정값이 우선).
- 실측(2026-07-22 Playwright): 수량 100·기간 7일 고정 상품(단가 100) → 입력 잠김·총액 70,000 자동, 쿠폰(26) 병행 65,000, 저장값 qty=100·days=7 확인.

## 상품 복제

- 목록의 **복제** 버튼 → [duplicate()](../app/Http/Controllers/Admin/MarketingProductController.php) — 상품 + 필드·단계(그룹 매핑 유지)·업체 배분까지 통째로 복사.
- 복사본은 **새 order_token**(creating 훅) + `" (복사)"` 제목 + **비활성 시작**(검수 전 노출 방지) → 편집 화면으로 이동.
- 라우트 `admin.products.duplicate` 는 [routes/web.php](../routes/web.php) 상품 그룹에 있음.
