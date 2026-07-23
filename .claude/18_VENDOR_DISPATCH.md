# 18. 외부 발주 — 업체 관리 · 배분 · 자동 전송

> 마케팅 상품 주문을 **관리자 승인 시 외부 업체로 자동 발주**하는 파이프라인. (2026-07-15 도입)

## 흐름

```
주문 접수(pending)
  → 관리자 승인 [주문 상세 · 승인 · 발주]
  → 상품의 업체 배분(비율/고정 수량)대로 수량 분배
  → 업체별 field_map 매핑으로 페이로드 생성
  → 채널별 전송 (API 호출 | 구글시트 append)
  → order_dispatches 기록 (sent/failed · 응답 보존 · 실패 재전송)
  → 주문 상태 pending → processing
```

## 데이터 모델 (2026_07_15_000001_create_vendor_dispatch_tables)

| 테이블 | 내용 |
|---|---|
| `vendors` | 업체 — channel(api/gsheet), api_url·method·headers(JSON)·format(json/form), gsheet_id·tab, is_active |
| `product_vendors` | 상품×업체 배분 — alloc_type(ratio %/fixed), alloc_value, field_map(JSON), sort_order, is_active |
| `order_dispatches` | 전송 기록 — vendor_name(업체 삭제 후 이력 보존), channel, quantity, payload, status(pending/sent/failed), response, sent_at |

## 배분 규칙 (`OrderDispatchService::allocate`)

- 설정 순서(sort_order)대로: **고정 수량**은 그대로, **비율**은 `floor(주문수량 × %)`. 잔여 수량 한도로 캡.
- 비율 내림 잔여분은 **마지막 비율 행**에 가산(비율 행이 없으면 미배분 허용).
- 배분 0인 업체는 발주 생략.

## 매핑 (`field_map` = `[{key, src, value}]`)

- src: `alloc:quantity`(배분 수량) · `order:quantity|order_no|days|unit_price|total_price|orderer_name|orderer_contact|created_at` · `product:title` · `field:<field_key>`(동적 필드) · `static`(고정값=value) · `skip`(**보내지 않음** — 빈 값 전송. 구글시트 전용 UI: 행 순서=열 순서라 중간 행을 지우면 뒤 열이 당겨지므로, 건너뛸 열은 삭제 대신 skip. 자동 생성 행 기본값)
- 매핑이 비어 있으면 기본 페이로드 `{order_no, product, quantity(배분), days, fields(전체)}` 전송.
- **구글시트는 매핑 순서 = 열 순서(A, B, C…)**, append(USER_ENTERED).

## 구글시트 인증

- `.env GOOGLE_SERVICE_ACCOUNT_JSON=서비스계정 키파일 경로` (config/services 미등록 시 env 직독).
- 서비스 계정 JWT(RS256, openssl) → access token → Sheets v4 append. **시트를 서비스 계정 이메일에 편집자로 공유** 필수.
- 자격증명 없으면 발주가 `failed`로 기록되고 사유 표기 — 설정 후 [재전송]으로 복구.

## 화면

- **/admin/vendors** — 업체 CRUD(모달) · 활성 토글(AJAX) · 연결 상품 수. 메뉴는 `/admin/menus`에서 수동 추가(로컬은 admin.vendors 추가됨).
  - API 요청 헤더는 **[헤더명][값] 행 단위 입력**(JSON 원문 입력 아님) — 제출 시 JSON 직렬화해 `vendors.api_headers`에 저장.
- **상품 편집 › 외부 발주 — 업체 배분** — 업체/배분방식/값/활성 행 + [매핑] 패널(보낼 키 ← 소스). `vendors_json` 직렬화 → `syncVendors()`.
  - **구글시트 열 이름 자동 로드(2026-07-22)** — 구글시트 업체의 매핑을 열면 `GET admin/vendors/{vendor}/sheet-columns`(전송과 동일한 서비스 계정·스코프)로 열 이름을 불러와 **열마다 매핑 행 자동 생성**(vm-key=시트 열 제목, 기존 행의 소스 선택은 인덱스 기준 보존, 새 행 기본 src=static 빈 값). [시트 열 다시 불러오기]로 강제 갱신, 업체별 JS 캐시. 인증 미설정/403(미공유)/404(ID 오류)는 패널에 원인 문구 표시.
  - **탭 이름 규칙(2026-07-22)** — 조회·전송 모두 **탭 미설정이면 시트의 첫 번째 탭 자동 사용**(기존 '시트1' 가정이 실제 탭과 달라 400 나던 실사고 — MDL 시트 탭은 단품·가격비교·정산). 탭이 설정돼 있는데 시트에 없으면 **사용 가능한 탭 목록을 에러로 안내**. 조회는 메타(`?fields=sheets.properties.title`)로 탭 실존 검증 후 1행을 읽는다.
  - **탭 선택 UI(2026-07-22, 당일 개정)** — 매핑 패널 시트 바에 **탭 셀렉트**(sheet-columns 응답의 `tabs`).
    ~~변경 시 업체 설정(gsheet_tab)에 저장~~ → **상품×업체 배분 단위(product_vendors.sheet_tab)로 변경**:
    같은 업체를 쓰는 상품끼리 탭이 달라야 하는데 업체 공유값을 덮어써 전 상품이 같이 바뀌던 실사고 수정.
    탭 변경은 즉시 저장하지 않고 **상품 저장 시 vendors_json 으로 함께 저장**(비면 업체 기본 탭).
    열 미리보기는 `GET admin/vendors/{vendor}/sheet-columns?tab=` 로 특정 탭 조회.
    **발주 전송 우선순위**: 배분 sheet_tab → 업체 gsheet_tab → 첫 탭. 검증 [ProductSheetTabTest](../tests/Feature/ProductSheetTabTest.php) 3건.
  - **업체 관리 탭 입력 제거(2026-07-23)** — 탭 지정은 **상품별(배분 sheet_tab)로 일원화**. 업체 관리 폼의 '시트(탭) 이름' 입력, 목록의 탭 표기, 구 탭 저장 엔드포인트(`PUT admin/vendors/{vendor}/gsheet-tab`)를 제거.
    `vendors.gsheet_tab` 컬럼과 전송·열 조회의 폴백(배분 → 업체 gsheet_tab → 첫 탭)은 **레거시 호환용으로 유지** — 기존 운영 데이터(배분 탭 미설정 + 업체 탭 설정)가 첫 탭으로 잘못 가는 사고 방지. 신규 업체는 UI 로 탭을 넣을 수 없으므로 자연히 상품별 지정만 쓰게 된다.
  - **내부(숨김) 필드 — 자동 채움 + 고정값(2026-07-22)** — `product_fields.is_hidden`(고객 주문 폼 미노출)·`autofill_source`(유입키워드 수집값 매핑: 핵심키워드/상품URL/상품ID/상품명/상점명/가격/정답태그/썸네일)·`default_value`(**고정값** — 주문 생성 시 OrderPlacer 가 field_values 에 시드, 항상 같은 값을 발주에 전달할 때. 빌더 [옵션]에서 입력). 값 채움 순서: 고정값(생성 시) → 수집 반영(OrderFieldAutofill, 빈 필드만) → 주문 상세 수동 입력/다시 채우기(강제). 발주는 기존 `field:<key>` 매핑으로 전달, **필수 내부 필드가 비면 승인 차단**.
- **주문 상세** — 우측 [승인 · 발주] 카드(배분 미리보기 → SweetAlert 확인 → 발주), 좌측 [외부 발주 현황] 테이블(상태·응답·재전송).

## 주의

- 승인은 **1회만**(이미 dispatch 존재 시 거부) — 실패 건은 개별 재전송.
- 업체 삭제 시 배분 설정은 cascade 삭제, 전송 이력은 vendor_name 으로 보존(vendor_id null).
- API 헤더에 인증키가 들어감 — 화면 노출 주의(수정 모달에서만 표시).
