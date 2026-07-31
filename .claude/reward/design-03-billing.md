# 퀴즈농장 — 정산 · 수익 관리 · 통계 설계

> 대상: rankfree (Laravel 13.8 / PHP 8.3 / MariaDB 11.4.2)
> 작성일: 2026-07-28
> 담당 영역: **금액 체계 · 미지급 부채 · 통계 집계 · 정산 화면 · 한도 초과분 정산 · 관리자 화면**
> 선행 문서: `docs/rankfree-integration-v1-draft.md`(스키마·컨트롤러 관례), `docs/infra-constraints.md`(캐시 계층)
> rankfree 등록 시 파일명: `.claude/31_FARM_BILLING.md` (미션/한도 설계서가 29·30을 쓰는 전제)

---

## 0. 결정 요약

| # | 쟁점 | 결정 | 한 줄 근거 |
|---|---|---|---|
| 1 | 참여 1건 수입 단가 | `marketing_orders.total_price ÷ 그 주문의 세부주문서 quantity 합계` 를 **미션 생성 시 스냅샷** | 이행률(`default_fulfillment` 40%)이 반영된 실효 단가. 단가 변경·주문 수정에도 과거 정산이 안 흔들린다 |
| 2 | 금액 컬럼 타입 | 참여 단가 `unsignedInteger`(원), 집계 합계 `bigInteger`(원) | 하루 100만 행 SUM에서 DECIMAL 누산은 BIGINT보다 느리다. rankfree도 결국 `(int)`로 캐스팅해 쓴다 |
| 3 | 실시간 vs 배치 | **참여량만 실시간(한도 카운터 재사용), 금액은 5분 증분 배치** | 전사 합계 행에 하루 100만 UPDATE를 참여 트랜잭션 안에 넣으면 전 트랜잭션이 직렬화된다 |
| 4 | 부채 대상 | `completed_days >= 1` 인 growing/ready 작물만. 심기만 한 d0은 "잠재"로 분리 표시 | 심기는 무료 행위. d0까지 계상하면 사용자 100만 × 3칸 = 최대 15억 부채가 잡혀 예산 판단이 무의미해진다 |
| 5 | 부채 금액 | **gross(100% 완주 가정)를 원장 숫자**로, expected(완주율 반영)를 병기 | 과소계상하면 비즈월렛 예산 부족(에러 4112)으로 지급 실패 → 사용자 CS + 심사 리스크 |
| 6 | 부채 스냅샷 | 매일 00:20 KST, 진행도(d1~d7) × 작물 버킷 = **48행/일** | 활성 작물 300만 행을 화면에서 매번 GROUP BY 할 수 없다 |
| 7 | 시간대별 통계 | **미션별 시간 통계 테이블을 만들지 않는다.** 분산 영역의 구간 카운터를 그대로 읽고, 전사 분포만 `farm_stat_hourly_total`(24행/일) | 미션 2만 × 24시간 = 하루 48만 행. 같은 정보가 운영 카운터에 이미 있다 |
| 8 | 롤업 | 일별(영구) → 월별(영구). 주별 롤업 **안 만든다** | 주별은 일별 7행 SUM. 미션 2만 기준 14만 행 스캔 = 50ms. 테이블을 늘릴 이유가 없다 |
| 9 | 한도 초과 손실 | 기회손실(청구 못 한 매출)과 실손실(그 참여에 든 포인트)을 **분리 표시** | 초과 100건의 기회손실 3.75만원 vs 실손실 7천원. 하나만 보면 대응 우선순위가 틀어진다 |
| 10 | 로그 보존 | `farm_mission_logs` 월 RANGE 파티션 **6개월**, 정산 근거는 집계 테이블 영구 | 6개월 ≈ 81GB. 13개월은 176GB로 디스크가 감당 못 한다 |
| 11 | 마감·정정 | 월 마감(`closed_at`) 후 재계산 금지. 정정은 `farm_settlement_adjustments` 행 추가 | 로그 append-only 원칙을 정산에도 그대로 적용 |

---

## 1. 금액 체계

### 1-1. 네 가지 금액의 정의

| 이름 | 정의 | 발생 시점 | 방향 |
|---|---|---|---|
| **수입 (revenue)** | 광고주가 결제한 금액 중, **한도 내 참여 1건에 배분된 몫** | 참여 확정(정답) 시점에 **확정** | + |
| **지출 (payout)** | 사용자에게 지급하는 토스 포인트 | 수확 시점에 원장 행 생성 → 토스 지급 확정 시 현금 유출 | − |
| **수수료 (fee)** | 토스 프로모션 지급 수수료 + 플랫폼 수수료 | 지출과 동시 | − |
| **수익 (profit)** | `수입 − 지출 − 수수료` | 파생 (저장하지 않음) | = |

> 🔴 **수입과 지출은 시점이 어긋난다.** 수입은 참여 즉시 확정되지만 지출은 7일 뒤(실측으론 10일 뒤) 확정된다.
> 그래서 "오늘 수익"을 `오늘 수입 − 오늘 지출`로 계산하면 **초기에는 수익이 과대**, 서비스 종료 직전에는 **과소**로 나온다.
> → 화면의 기본 수익 지표는 `수입 − 지출**발생**액(payout_reserved) − 수수료`를 쓴다. 발생액은 "그날 참여로 늘어난 부채 + 그날 확정된 지급"이 아니라 **그날 생성된 원장 금액**이다.
> 실제 현금 기준 수익은 `payout_success` 기준 지표를 별도 칸으로 병기한다.

### 1-2. 참여당 수입 단가 — 계산식 (가장 중요)

rankfree는 **선불 구조**다. 광고주는 주문 시점에 `marketing_orders.total_price`를 이미 결제(무통장 입금)했다.
따라서 우리 쪽 "청구"는 새로 돈을 받는 행위가 아니라 **이미 받은 돈을 이행 실적에 따라 매출로 인식**하는 행위다.

여기에 rankfree의 이행률 구조가 겹친다:

```
광고주 주문:  quantity(일수량) 300 × days 5일 × unit_price 150원 = total_price 225,000원
세부주문서:  일 발주량 = floor(300 × default_fulfillment 40% ) = 120건/일
             → marketing_order_items 5행(회차) × quantity 120 = SUM 600건
```

광고주는 225,000원을 냈고 우리는 600건만 수행한다. 따라서:

```
[정산 단가 계산식]
order_total_quantity = SUM(marketing_order_items.quantity)  WHERE order_id = X   -- 모든 vendor 회차 포함
unit_revenue         = ROUND(marketing_orders.total_price / order_total_quantity)

예: 225,000 / 600 = 375원/건
```

- `total_price`는 **할인 반영 후 최종가**를 쓴다. 실제 입금액이 매출이기 때문이다.
  (할인 전 금액은 `total_price + discount_amount`로 복원 가능하지만 정산에는 쓰지 않는다.)
- **분모에 다른 vendor의 회차도 포함한다.** 세부주문서가 이미 vendor별로 쪼개져 있으므로, 퀴즈농장 회차만 세면 단가가 부풀려진다.
- `order_total_quantity = 0`이면 0으로 나눈다 → `unit_revenue = 0`으로 두고 **미션을 `draft`로 막고 운영자에게 알린다**(§6-3).

이 값은 **미션 생성 시 `farm_missions.unit_revenue`에 스냅샷**하고, **참여 확정 시 `farm_mission_logs.unit_revenue`에 다시 스냅샷**한다.
2중 스냅샷 이유: 운영자가 주문 수량을 도중에 고치면 미션 단가가 바뀌는데, **이미 발생한 참여의 정산가는 바뀌면 안 된다.**

### 1-3. 저장 위치 · 타입 · 단위

**기존 rankfree 테이블 (읽기만, 변경 없음)**

| 값 | 위치 | 타입 | 단위 |
|---|---|---|---|
| 광고주 결제 총액 | `marketing_orders.total_price` | decimal(12,2) | 원 (VAT 포함 추정 — **확인 필요** §10) |
| 주문 단가 스냅샷 | `marketing_orders.unit_price` | decimal(12,2) | 원 |
| 상품 기준 단가 | `marketing_products.min_price` | decimal(12,2) | 원 |
| 회차 목표 수량 | `marketing_order_items.quantity` | unsignedInteger | 건 |
| 원가 | `marketing_products.base_cost` | decimal(12,2) | ⚠️ **어떤 계산에도 쓰지 않는다.** rankfree에서도 표시 전용 |

**신규 컬럼 — `farm_missions` 확장** (미션 도메인 소유 테이블. 정산이 요구하는 5개 컬럼)

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `order_item_id` | `unsignedBigInteger nullable` **FK 없음** | null | `marketing_order_items.id`. 세부주문서가 지워져도 미션·정산은 남는다 |
| `order_id` | `unsignedBigInteger nullable` | null | `marketing_orders.id` 스냅샷. 광고주 조인 단축 |
| `advertiser_user_id` | `unsignedBigInteger nullable` | null | `marketing_orders.user_id` 스냅샷 |
| `unit_revenue` | `unsignedInteger` | 0 | **참여 1건 실효 수입(원)**. §1-2 계산 결과 |
| `planned_quantity` | `unsignedInteger` | 0 | `marketing_order_items.quantity` 스냅샷 = 이 미션의 전체 목표 |

인덱스: `index(['order_id','id'], 'fm_order')`, `index(['advertiser_user_id','id'], 'fm_adv')`, `index('order_item_id', 'fm_item')`

**신규 컬럼 — `farm_mission_logs` 확장** (로그 도메인 소유. 정산이 요구하는 5개 컬럼)

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `order_item_id` | `unsignedBigInteger nullable` | null | 참여 시점 스냅샷 |
| `unit_revenue` | `unsignedInteger` | 0 | **참여 시점 단가 스냅샷.** 이후 어떤 변경에도 불변 |
| `billable` | `boolean` | true | 한도 내 = true, 초과 = false (§5) |
| `seq_in_day` | `unsignedInteger` | 0 | 그 미션 그날 몇 번째 참여인지. 한도 카운터 UPDATE의 결과값 |
| `created_month` | `unsignedInteger` | — | `YYYYMM`. **월 RANGE 파티션 키** (§3-6) |

> `points`(참여 즉시 포인트)는 초안에 이미 있다. **정책상 0으로 운영한다**(§1-5).

**신규 테이블** — §3-2에서 전체 스키마

| 테이블 | 역할 | 행 수 |
|---|---|---|
| `farm_settlement_daily` | 날짜 × 미션 정산 집계 | 하루 2만, 1년 730만 |
| `farm_settlement_monthly` | 월 × 주문(광고주) 롤업 | 월 수백 |
| `farm_settlement_adjustments` | 마감 후 정정(append-only) | 극소 |
| `farm_liability_snapshots` | 날짜 × 진행도 × 작물 부채 잔액 | 48행/일 |
| `farm_stat_hourly_total` | 날짜 × 시(0~23) 전사 분포 | 24행/일 |
| `farm_rollup_cursors` | 배치 커서·최종 실행 시각 | 4행 고정 |

**타입 결정 근거 (숫자)**

- 참여 단가는 150~2,000원 범위 → `unsignedInteger`(최대 42억)로 충분.
- 일 집계 금액: 하루 100만 건 × 2,000원 = 20억. 월 600억. 연 7,300억. `unsignedInteger` 상한 42억을 **월 단위에서 이미 넘는다** → 집계 합계는 전부 `bigInteger`.
- `farm_settlement_adjustments.amount_krw`는 **음수를 허용**해야 하므로 `bigInteger`(signed).
- 소수 포인트·환율·다통화 개념 없음. KRW 단일, 정수 원.

### 1-4. 스냅샷 원칙

| 값 | 언제 스냅샷 | 이후 변경 |
|---|---|---|
| `farm_missions.unit_revenue` | 미션 생성(세부주문서 → 미션 변환) 시 | 운영자가 주문을 수정하면 `farm:resync-missions`가 **미래 미션만** 갱신. 이미 참여가 발생한 미션은 잠금 |
| `farm_mission_logs.unit_revenue` | 참여 확정 트랜잭션 안 | **절대 불변** |
| `farm_plantings.reward_points` (신규) | 심기 시 `farm_crops.points` 복사 | 불변. `required_days`와 같은 논리 |
| `farm_settlement_daily.unit_revenue` | 롤업 시 그날 최빈값 | 표시 전용. 금액 계산은 로그 SUM |

> `farm_plantings`에 `reward_points unsignedInteger` 컬럼을 **추가해야 한다.** 초안에는 `required_days`만 스냅샷돼 있다.
> 없으면 운영자가 작물 포인트를 500 → 300으로 낮춘 순간 **진행 중인 300만 작물의 부채가 하룻밤에 6억원 줄어든다.** 부채 시계열이 통째로 거짓말이 된다.

### 1-5. 수수료 · 부가세 · 참여 포인트

**수수료** — `fee_krw` 계산식:

```
fee_krw = payout_reserved_krw × config('rankfree.farm.billing.payout_fee_rate', 0.0)
        + participations       × config('rankfree.farm.billing.fee_per_participation', 0)
```

- 두 계수 모두 **기본 0**. 토스 프로모션 지급 수수료 요율은 **확인 필요**(§10-1).
- 퀴즈농장은 자사 vendor이므로 플랫폼 수수료(rankfree ↔ 퀴즈농장)는 0으로 둔다. 외주 vendor로 바뀌면 `fee_per_participation`에 넣는다.

**부가세** — `unit_revenue`는 VAT 포함가로 간주한다(`marketing_orders.total_price`가 주문 화면 최종가이므로).
화면에는 공급가액을 병기한다:

```
공급가액 = ROUND(revenue_krw / (1 + config('rankfree.farm.billing.vat_rate', 0.10)))
부가세   = revenue_krw - 공급가액
```

VAT 포함/별도 여부는 **확인 필요**(§10-2). config 한 줄로 뒤집을 수 있게 둔다.

**참여 포인트(`farm_missions.points`)는 0으로 운영한다.**
근거 3가지:
1. 참여마다 즉시 포인트를 주면 부채가 아니라 **즉시 현금 유출**이 된다. 하루 100만 건 × 10P = 1,000만원/일.
2. 초안 §7-2 검증이 이미 출석형에 포인트를 막고 있다. 참여형도 같은 논리를 확장한다.
3. 지급 API 호출이 하루 100만 회가 된다 — 토스 API 분당 한도(3,000 QPM)를 넘긴다.
→ **포인트는 수확 시점(7일 완주)에만 지급.** 관리자 폼에서 `points`는 0 고정 + 잠금, 예외는 운영자 승인 필드로.

---

## 2. 미지급 부채 (Deferred Payout Liability)

### 2-1. 부채는 두 종류다

| 종류 | 대상 | 계산 소스 | 성격 |
|---|---|---|---|
| **A. 지급 대기 부채** | 원장 행은 만들어졌으나 토스 지급이 아직 미확정 | `farm_point_ledgers` status ∈ (pending, requested, held) | **확정 채무**. 금액 확정, 시점만 미정 |
| **B. 미래 지급 의무** | 진행 중 작물(7일 미완주) | `farm_plantings` status ∈ (growing, ready) | **조건부 채무**. 완주해야 발생 |

요구사항의 "기간별 앞으로 지불할 금액"은 **A + B**다. 화면에서 반드시 분리해서 보여준다 — 성격이 완전히 다르다.
A가 늘면 지급 파이프라인 장애(예산 부족·API 오류)이고, B가 늘면 그냥 서비스가 성장 중이다.

부수적으로 **C. 선수금(광고주 미이행분)** 도 존재한다: `SUM(planned_quantity − 이행량) × unit_revenue`.
이건 사용자에게 줄 돈이 아니라 **광고주에게 진 이행 의무**다. 정산 화면에 "미이행 잔량"으로 표시한다(§4-6).

### 2-2. 부채 대상 정의

```
부채 대상 = farm_plantings
            WHERE status IN ('growing','ready')
              AND completed_days >= 1          ← 🔴 d0(심기만) 제외
```

**d0을 제외하는 이유 (숫자):**
사용자 100만 × 밭 3칸 × 작물 평균 500P = **최대 15억원**. 실제 비즈월렛 충전 가능액(초기 30만원~수천만원)과 3~4자릿수가 어긋난다.
심기는 비용 0의 행위이고 언제든 갈아엎을 수 있으므로 지급 의무의 근거가 되지 못한다.
d0은 화면에 "잠재 부채"로 **별도 칸에 회색으로** 표시한다(무시하는 게 아니라 성격을 나눈다).

### 2-3. gross vs expected — 둘 다 저장, gross를 원장으로

```
gross_krw    = SUM(farm_plantings.reward_points)                       -- 100% 완주 가정
expected_krw = SUM(farm_plantings.reward_points × 완주율[progress_day]) -- 이탈 반영
```

**gross를 원장 숫자로 쓰는 근거:**
지급 실패(에러 4112 = 프로모션 예산 부족)는 사용자에게 "포인트가 안 들어왔다"로 즉시 노출되고, 토스 심사에서 **미지급 사고**로 기록된다.
부채를 과소계상하면 충전액을 적게 잡게 되고, 그 결과가 4112다. 회계는 보수적으로 간다.

**expected는 어디에 쓰나:**
- 비즈월렛 충전 계획 (`충전 권장액 = 향후 7일 expected × 1.3`)
- 손익 예측 (수익률 계산)
- 완주율이 실측되기 전(서비스 90일 미만)에는 expected를 화면에 띄우되 "추정" 배지를 붙인다.

### 2-4. 진행도별 완주율

초기값 (config `rankfree.farm.billing.complete_rate`, 실측 전 사용):

| progress_day | 의미 | 완주율 | 근거 |
|---|---|---|---|
| 1 | 1일차 완료 | 0.40 | 1회 참여자 대부분 이탈. 리텐션 D1 40% 가정 |
| 2 | | 0.52 | |
| 3 | | 0.64 | |
| 4 | | 0.75 | |
| 5 | | 0.85 | |
| 6 | | 0.93 | 하루만 더 하면 됨 |
| 7 | ready(수확 가능) | 0.98 | 수확 버튼만 누르면 됨. 미수확 2% |

실측 갱신: `farm:calc-complete-rate` (매주 월 05:00). 코호트 기준

```sql
-- progress_day = P 에 도달한 작물 중 실제 수확된 비율
-- 대상: 90일 이전에 P에 도달한 작물 (아직 진행 중인 건 제외)
SELECT
  :P AS progress_day,
  COUNT(*)                                            AS reached,
  SUM(CASE WHEN h.id IS NOT NULL THEN 1 ELSE 0 END)   AS harvested,
  ROUND(SUM(CASE WHEN h.id IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*), 4) AS rate
FROM farm_plantings p
LEFT JOIN farm_harvests h ON h.planting_id = p.id
WHERE p.created_at <  DATE_SUB(CURDATE(), INTERVAL 90 DAY)
  AND p.id IN (SELECT planting_id FROM farm_planting_days WHERE day_no = :P);
```

결과는 `app_settings`에 JSON으로 저장(`SettingsServiceProvider`가 config를 덮어쓰는 rankfree 관례).
표본 1,000건 미만인 진행도는 **갱신하지 않고 초기값 유지**(과적합 방지).

### 2-5. 스냅샷 — 언제 · 무엇을

**언제:** 매일 **00:20 KST**. 전일(어제) 마감 시점의 잔액을 `snapshot_date = 어제`로 기록한다.
00:00이 아니라 00:20인 이유: 자정 직전 참여 트랜잭션이 완전히 커밋되기를 기다린다(20분 여유). 5분 롤업 배치도 그 사이 한 번 돈다.

**왜 스냅샷이 필요한가 (숫자):**
활성 작물 = 사용자 100만 × 밭 3칸 ≈ 300만 행.
화면에서 매번 `GROUP BY completed_days, crop_id`를 돌리면 커버링 인덱스 스캔이라도 2~5초. 관리자가 새로고침할 때마다 이 비용을 낼 수 없다.
스냅샷은 **하루 48행**(진행도 8 × 작물 6)이므로 1년 17,520행 — 화면에서 즉시 읽힌다.

**farm_liability_snapshots**

| 컬럼 | 타입 | 설명 |
|---|---|---|
| `id` | `id()` | |
| `snapshot_date` | `date` | 기준일(그날 24:00 시점 잔액) |
| `crop_id` | `string(20)` | `farm_crops.code` |
| `progress_day` | `unsignedTinyInteger` | 0~7. **0 = 잠재(심기만)**, 7 = ready |
| `plantings_cnt` | `unsignedInteger` | 작물 수 |
| `gross_krw` | `bigInteger` | `SUM(reward_points)` |
| `complete_rate` | `decimal(5,4)` | 그 시점 적용 완주율(스냅샷) |
| `expected_krw` | `bigInteger` | `ROUND(gross × complete_rate)` |
| `created_at` | `timestamp` | `timestamps()` 안 쓴다 — UPDATE 하지 않는 테이블 |

인덱스: `unique(['snapshot_date','crop_id','progress_day'], 'fls_uni')`, `index('snapshot_date', 'fls_date')`

> `complete_rate`를 행에 박는 이유: 나중에 완주율을 갱신해도 **과거 스냅샷의 expected가 소급 변경되지 않게** 하기 위함. 시계열 그래프가 흔들리면 신뢰를 잃는다.

**부채 증감(flow)은 `farm_settlement_daily`의 전사행(mission_id=0)에 넣는다** — 별도 테이블을 만들지 않는다.

| 컬럼 | 설명 |
|---|---|
| `planted_cnt` | 그날 심은 작물 수 |
| `liability_added_krw` | 그날 d0→d1 진입으로 **새로 발생한** 부채 |
| `liability_released_harvest_krw` | 그날 수확으로 해소된 부채 (= `payout_reserved_krw`) |
| `liability_released_abandon_krw` | 그날 방치 만료로 소멸한 부채 |

이 4개는 미션과 무관한 사용자 행위라 미션별로 나눌 수 없다. **전사행에만 채운다.**
스냅샷 잔액과 flow의 정합 검증:

```
어제잔액 + liability_added − liability_released_harvest − liability_released_abandon == 오늘잔액
```

배치 마지막 단계에서 이 항등식을 검사하고, **차이가 1,000원을 넘으면** `Log::error` + 잔디 알림.
(rankfree 관례: 집계 실패가 서비스를 막으면 안 되므로 `try/catch(\Throwable)`로 감싸되, 알림은 반드시 낸다.)

### 2-6. "기간별 앞으로 지불할 금액" = 지급 캘린더

단순 총액보다 **언제 얼마가 나갈지**가 실무에 필요하다(비즈월렛 충전 타이밍).

```
예상 지급일 = snapshot_date + CEIL((7 − progress_day) × days_per_progress)
days_per_progress = config('rankfree.farm.billing.days_per_progress', 1.4)
```

`1.4`의 근거: 사용자가 매일 참여하지 않는다. 7일 코스의 실제 소요를 10일로 가정(리텐션 70%).
서비스 30일 후 실측으로 교체한다: `AVG(DATEDIFF(last_day_date, first_day_date) + 1) / 7` (`farm_harvests` 기준).

```sql
-- 향후 지급 캘린더 (최신 스냅샷 기준)
SELECT
  DATE_ADD(s.snapshot_date,
           INTERVAL CEIL((7 - s.progress_day) * :days_per_progress) DAY) AS expected_pay_date,
  SUM(s.plantings_cnt) AS crops,
  SUM(s.gross_krw)     AS gross_krw,
  SUM(s.expected_krw)  AS expected_krw
FROM farm_liability_snapshots s
WHERE s.snapshot_date = :latest
  AND s.progress_day BETWEEN 1 AND 7
GROUP BY expected_pay_date
ORDER BY expected_pay_date;
```

여기에 **A. 지급 대기 부채**를 "즉시" 행으로 얹어 맨 위에 붙인다:

```sql
SELECT status, COUNT(*) AS cnt, SUM(amount) AS amt
FROM farm_point_ledgers
WHERE status IN ('pending','requested','held')
GROUP BY status;
```
→ 인덱스 `fpg_status(status, created_at)` 사용. 정상 운영 시 수백~수천 행이므로 실시간 조회로 충분하다.

### 2-7. 중도 이탈(방치) 처리

| 항목 | 결정 | 근거 |
|---|---|---|
| 방치 판정 | `status='growing'` AND `last_tended_date < 오늘 − 14일` | 7일 코스에서 14일 무활동은 명백한 이탈. 여행·질병으로 1주일 쉬는 사용자는 살린다 |
| 처리 | `status='abandoned'`, `abandoned_at` 기록. **행은 삭제하지 않는다** | CS 복구 문의 대응. 밭이 비어 새 작물을 심을 수 있게 된다 |
| 부채 | 다음 스냅샷부터 자동 제외 + `liability_released_abandon_krw`에 계상 | |
| ready(7일 완주) 작물 | **방치 만료 대상에서 제외.** 무기한 수확 대기 | 이미 조건을 채운 사용자의 권리를 회수하면 심사·CS 리스크 |
| 재개 | 불가. 다시 심어야 한다(진행도 소멸) | 부채 무한 누적 방지. 14일은 충분히 관대 |
| 배치 | `farm:expire-plantings` 매일 **04:00 KST** | 새벽 저트래픽 구간. 롤업 배치(5분)와 겹치지 않는 시각 |

```
farm:expire-plantings 의사코드
  $cutoff = today - config('rankfree.farm.billing.abandon_after_days', 14)
  FarmPlanting::where('status','growing')
      ->where('last_tended_date','<',$cutoff)
      ->chunkById(500, function($rows) {
           // 상태 테이블이므로 UPDATE 허용. 로그 테이블은 건드리지 않는다
           FarmPlanting::whereIn('id',$rows->pluck('id'))
               ->update(['status'=>'abandoned','abandoned_at'=>now()]);
      });
  // 처리 건수·금액을 그날 farm_settlement_daily 전사행에 누적
```

⚠️ `ready` 상태는 WHERE에서 빠져 있다 — 의도적이다. 코드 리뷰 체크 항목.
⚠️ `farm_plantings`에 `abandoned_at timestamp nullable` 컬럼 **추가 필요**.

### 2-8. 당일 실시간 부채 근사

스냅샷은 하루 1회라 낮 시간에는 최대 24시간 낡았다. 화면에서는 근사치를 보여준다:

```
현재부채(근사) = 최신스냅샷.gross_krw
               + 오늘 farm_settlement_daily(mission_id=0).liability_added_krw
               − 오늘 ...liability_released_harvest_krw
               − 오늘 ...liability_released_abandon_krw
```

오늘분은 5분 롤업이 채우므로 **최대 5분 지연**. 화면에 "00:20 스냅샷 + 오늘분 반영(HH:mm 기준)"으로 표기한다.

---

## 3. 통계 테이블

### 3-1. 실시간 증분 vs 배치 — **배치(5분 증분 롤업) 선택**

단, **참여량만 실시간**이다. 이유는 이미 한도 카운터가 그 값을 들고 있어서 추가 비용이 0이기 때문이다.

**배치를 고른 근거 5가지 (숫자 포함):**

1. **hot row 직렬화.** 전사 합계 행(`mission_id=0`)은 하루 100만 건 = 초당 12건(피크 50건)이 **같은 행 하나**를 UPDATE한다. InnoDB 단일 행 UPDATE 자체는 초당 수천이 가능하지만, 그게 참여 트랜잭션 **안에** 들어가면 트랜잭션 전체가 그 행의 락 대기열에 줄을 선다. 참여 트랜잭션은 이미 (한도 카운터 UPDATE + 로그 INSERT + planting_days INSERT + plantings UPDATE)로 4~6쿼리 = 5~15ms다. 여기에 전사 행 락이 붙으면 실질 처리량이 **초당 60~200건으로 천장이 생긴다.** 피크 50건을 감당하려면 안전 여유가 없다.
2. **지출이 참여 시점에 안 나온다.** 포인트는 7일 뒤 수확 시 확정된다. 실시간으로 증분해도 그 순간 쓸 수 있는 금액은 수입뿐이다. 절반짜리 실시간을 위해 위 비용을 낼 이유가 없다.
3. **재실행 가능성.** 배치는 커서를 되감아 다시 돌리면 복구된다. 실시간 증분은 트랜잭션이 롤백되거나 배포 중 예외가 나면 카운터가 어긋나고 **어긋난 사실조차 알 수 없다.** rankfree에는 이미 `User::tryConsumeUsage()`처럼 어긋나는 카운터가 있고, 그게 왜 문제인지 조사에도 남아 있다.
4. **비용이 작다.** 5분간 신규 정답 로그 = 100만 ÷ 288 ≈ **3,472행**. `WHERE id > cursor` 인덱스 스캔 + GROUP BY → 10~30ms. UPSERT 대상 = 그 5분간 참여가 발생한 미션 수 ≈ 500~2,000행 → chunk 200 × 10회 ≈ 100ms. **한 사이클 총 0.3초 미만.**
5. **정산 업무에 5분 지연은 무해하다.** 광고주 이행률·실시간 소진 확인은 한도 카운터를 직접 읽어 별도로 제공한다(§4-7).

**지출 집계는 커서로 안 된다** — `farm_point_ledgers.status`가 나중에 바뀌기 때문(pending → requested → success).
→ **최근 N시간 전량 재계산 + 그 이전은 커서 확정** 하이브리드:

| 배치 | 지출 재계산 범위 | 대상 행 수 | 소요 |
|---|---|---|---|
| 5분 배치 (매 5분) | 최근 **24시간** | 하루 수확 14.3만 행 | 0.3~0.8초 |
| 정시 배치 (매시 00분) | 최근 **72시간** | 43만 행 | 1~2.5초 |

72시간의 근거: 지급 재시도 스케줄러는 5분 간격 × 최대 20회 = 100분, 큐 backoff 최대 30분, `held` 상태 운영자 개입까지 감안해도 **3일이면 status가 더 변하지 않는다.** 3일 지난 원장의 상태 변경은 극히 드물고, 발생하면 `farm_settlement_adjustments`로 정정한다.

필요 인덱스: `farm_point_ledgers`에 `index(['created_at','status'], 'fpg_date_status')` **추가 필요**(초안에는 없다).

### 3-2. 스키마

#### farm_settlement_daily — 날짜 × 미션 정산 집계

| 컬럼 | 타입 | 설명 |
|---|---|---|
| `id` | `id()` | |
| `stat_date` | `date` | KST 기준 참여일 |
| `mission_id` | `unsignedBigInteger` | **0 = 전사 합계행**, >0 = 미션별 |
| `order_item_id` | `unsignedBigInteger nullable` | 세부주문서 |
| `order_id` | `unsignedBigInteger nullable` | 주문 |
| `advertiser_user_id` | `unsignedBigInteger nullable` | 광고주 |
| `unit_revenue` | `unsignedInteger` | 그날 적용 단가(표시 전용) |
| `attempts_cnt` | `unsignedInteger` | 시도(오답·거절 포함) |
| `participations` | `unsignedInteger` | 정답 확정 = 참여량 |
| `billable_cnt` | `unsignedInteger` | 한도 내 참여 |
| `overage_cnt` | `unsignedInteger` | 한도 초과 참여 |
| `unique_users` | `unsignedInteger` | 참여 고유 사용자 |
| `revenue_krw` | `bigInteger` | `Σ unit_revenue WHERE billable` |
| `overage_krw` | `bigInteger` | `Σ unit_revenue WHERE NOT billable` (기회손실) |
| `harvest_cnt` | `unsignedInteger` | 그날 수확 건수 (전사행만) |
| `payout_reserved_krw` | `bigInteger` | 그날 생성된 원장 금액 (전사행만) |
| `payout_success_krw` | `bigInteger` | 그날 생성분 중 지급 확정 (전사행만) |
| `payout_failed_krw` | `bigInteger` | 실패·취소 (전사행만) |
| `fee_krw` | `bigInteger` | §1-5 계산 |
| `planted_cnt` | `unsignedInteger` | 전사행만 |
| `liability_added_krw` | `bigInteger` | 전사행만 |
| `liability_released_harvest_krw` | `bigInteger` | 전사행만 |
| `liability_released_abandon_krw` | `bigInteger` | 전사행만 |
| `updated_at` | `timestamp` | 롤업 시각 |

인덱스:
- `unique(['stat_date','mission_id'], 'fsd_uni')`
- `index(['order_id','stat_date'], 'fsd_order')`
- `index(['advertiser_user_id','stat_date'], 'fsd_adv')`
- `index(['stat_date','mission_id'], 'fsd_date')` — 전사행 조회

> 🔴 **이중계상 함정.** 전사행(0)과 미션행(>0)이 같은 테이블에 있으므로 `SUM()`할 때 반드시 한쪽만 골라야 한다.
> 모델에 스코프를 두고 **raw 쿼리 금지**를 코드 리뷰 항목으로 둔다:
> `scopeTotals($q)` → `where('mission_id', 0)` / `scopeMissions($q)` → `where('mission_id', '>', 0)`

**행 수·용량:** 미션 2만 기준 하루 20,001행 × 약 140B = 2.8MB/일 = 1.0GB/년. 3년 3GB. 파티션 불필요, 인덱스로 충분.
보존 36개월. 그 이후는 월별만 남기고 DELETE.

#### farm_settlement_monthly — 월 × 주문 롤업 (청구·마감 단위)

| 컬럼 | 타입 | 설명 |
|---|---|---|
| `id` | `id()` | |
| `period` | `char(7)` | `YYYY-MM` (rankfree `feature_usages.period` 관례와 동일) |
| `order_id` | `unsignedBigInteger` | **0 = 전사 합계행** |
| `advertiser_user_id` | `unsignedBigInteger nullable` | |
| `participations` / `billable_cnt` / `overage_cnt` | `unsignedInteger` | |
| `revenue_krw` / `overage_krw` / `payout_reserved_krw` / `payout_success_krw` / `fee_krw` | `bigInteger` | |
| `planned_quantity` | `unsignedInteger` | 그 달 해당 주문의 목표 합 |
| `fulfilled_quantity` | `unsignedInteger` | `= billable_cnt` |
| `closed_at` | `timestamp nullable` | **마감 시각. NULL이면 미마감** |
| `closed_by` | `unsignedBigInteger nullable` | `users.id` |
| `timestamps` | | |

인덱스: `unique(['period','order_id'], 'fsm_uni')`, `index(['advertiser_user_id','period'], 'fsm_adv')`

주별 롤업은 만들지 않는다. 주 단위는 `farm_settlement_daily` 7행 SUM(미션 2만 × 7 = 14만 행 인덱스 스캔 ≈ 50ms)으로 충분하다.

#### farm_settlement_adjustments — 마감 후 정정 (append-only)

| 컬럼 | 타입 | 설명 |
|---|---|---|
| `id` | `id()` | |
| `period` | `char(7)` | |
| `order_id` / `mission_id` | `unsignedBigInteger nullable` | NULL = 전사 정정 |
| `kind` | `string(12)` | `revenue` / `payout` / `fee` / `overage` / `count` |
| `amount_krw` | `bigInteger` | **음수 허용** |
| `count_delta` | `integer` | 건수 정정 |
| `reason` | `string(200)` | 한글 사유 (필수) |
| `created_by` | `foreignId nullable` → `users` `nullOnDelete()` | |
| `timestamps` | | |

인덱스: `index(['period','kind'], 'fsa_period')`

마감된 월의 화면 숫자 = `farm_settlement_monthly` + `SUM(farm_settlement_adjustments)`. **월 테이블 행은 절대 고쳐 쓰지 않는다.**

#### farm_stat_hourly_total — 전사 시간대 분포

| 컬럼 | 타입 | 설명 |
|---|---|---|
| `stat_date` | `date` | |
| `hour` | `unsignedTinyInteger` | 0~23 (KST) |
| `attempts_cnt` / `participations` / `billable_cnt` / `overage_cnt` / `unique_users` | `unsignedInteger` | |
| `revenue_krw` | `bigInteger` | |
| `updated_at` | `timestamp` | |

`unique(['stat_date','hour'], 'fsh_uni')`. **24행/일, 1년 8,760행.**

#### farm_rollup_cursors — 배치 상태

| 컬럼 | 타입 | 설명 |
|---|---|---|
| `key` | `string(40)` **PK** | `revenue` / `payout` / `hourly` / `liability` |
| `last_id` | `unsignedBigInteger` | 마지막 처리 로그/원장 id |
| `last_date` | `date nullable` | 마지막 확정일 |
| `last_run_at` | `timestamp nullable` | **화면의 "집계 기준 시각"** |
| `last_duration_ms` | `unsignedInteger` | 성능 추적 |
| `last_error` | `string(200) nullable` | |

### 3-3. 롤업 배치 — `farm:rollup-stats`

```
스케줄: 매 5분 (routes/console.php, timezone Asia/Seoul, withoutOverlapping(10), runInBackground)

STEP 1. 수입·참여량 증분  [커서: revenue]
────────────────────────────────────────────────
  $from = cursor('revenue')->last_id
  $to   = FarmMissionLog::max('id')            -- 스냅샷 고정. 진행 중 INSERT를 배제
  if ($to <= $from) skip

  -- 미션별
  SELECT DATE(created_at) d, mission_id, order_item_id, unit_revenue,
         COUNT(*)                                             attempts,
         SUM(result='correct')                                participations,
         SUM(result='correct' AND billable)                   billable_cnt,
         SUM(result='correct' AND NOT billable)               overage_cnt,
         SUM(IF(result='correct' AND billable, unit_revenue, 0))     revenue_krw,
         SUM(IF(result='correct' AND NOT billable, unit_revenue, 0)) overage_krw,
         COUNT(DISTINCT IF(result='correct', farm_user_id, NULL))    unique_users
  FROM farm_mission_logs
  WHERE id > :from AND id <= :to
  GROUP BY d, mission_id
  → upsert(farm_settlement_daily, ['stat_date','mission_id'], 증분 컬럼 += )

  ⚠️ upsert 의 값 컬럼은 '덮어쓰기'가 아니라 '누적'이어야 한다.
     rankfree 관례(AiCrawlerHit::record)대로 upsert(초기 0) → update(col = col + delta) 2단계로 쓴다.
     MariaDB 의 ON DUPLICATE KEY UPDATE col = col + VALUES(col) 를 쓰면 1쿼리로 끝나지만
     sqlite(로컬/테스트)에서 안 돌아가므로 드라이버 분기한다.

  -- 전사행(mission_id=0)은 같은 결과를 d 로만 GROUP BY 해서 별도 upsert
  -- 시간대(hourly)도 같은 스캔에서 GROUP BY d, HOUR(created_at) 로 파생 → 추가 스캔 비용 0

  cursor('revenue')->update(last_id = $to, last_run_at = now())

STEP 2. 지출 재계산  [범위: 최근 24h, 매시 00분에는 72h]
────────────────────────────────────────────────
  $since = now()->subHours($isTopOfHour ? 72 : 24)->startOfDay()

  SELECT DATE(created_at) d,
         COUNT(*)                                            ledger_cnt,
         SUM(amount)                                         payout_reserved,
         SUM(IF(status='success',  amount, 0))               payout_success,
         SUM(IF(status IN ('failed','canceled'), amount, 0)) payout_failed
  FROM farm_point_ledgers
  WHERE created_at >= :since AND source='harvest'
  GROUP BY d
  → farm_settlement_daily(mission_id=0) 의 payout_* 를 '덮어쓰기'(재계산이므로 += 아님)

  ⚠️ 마감(closed_at NOT NULL)된 기간은 건너뛴다.

STEP 3. 부채 flow · 수수료 · 수확수
────────────────────────────────────────────────
  planted_cnt                      : farm_plantings WHERE DATE(created_at)=d
  harvest_cnt                      : farm_harvests  WHERE DATE(harvested_at)=d
  liability_added_krw              : farm_planting_days WHERE day_no=1 AND work_date=d
                                     → JOIN farm_plantings.reward_points 합
  liability_released_harvest_krw   : = payout_reserved_krw (수확이 곧 해소)
  liability_released_abandon_krw   : farm_plantings WHERE DATE(abandoned_at)=d 의 reward_points 합
  fee_krw                          : §1-5 공식

STEP 4. 정합 검증
────────────────────────────────────────────────
  어제스냅샷 + added − released_harvest − released_abandon ≈ 오늘예상잔액
  |차이| > 1,000원 → Log::error + 잔디 알림 (배치는 계속 진행)
```

**전체 소요 예산:** STEP1 0.3초 + STEP2 0.8초(정시 2.5초) + STEP3 0.5초 + STEP4 0.05초 ≈ **1.7초 / 사이클**.
5분(300초) 중 1.7초 = 0.6% 점유. `withoutOverlapping(10)`으로 겹침을 막는다.

**3대 서버 문제:** 스케줄러는 **1대에서만** 돌아야 한다.
현재 rankfree는 단일 서버라 문제가 없지만 3대 구성으로 가면 `withoutOverlapping()`이 쓰는 `cache_locks`가 **공유 캐시(Redis 또는 MariaDB)** 여야 한다.
`infra-constraints.md`에서 캐시가 Redis(L2)로 가더라도 **`cache_locks`는 MariaDB로 고정**한다 — Redis가 죽으면 락이 풀려 배치가 3중 실행되고 집계가 3배로 부풀기 때문이다.
→ `config/cache.php`에 `locks` 전용 스토어를 분리하고 `Cache::store('db')->lock()`을 명시적으로 쓴다. **확인 필요**(§10-6).

### 3-4. 시간대별 집계 — 미션별 테이블은 만들지 않는다

요구사항 3(시간대 분산)의 검증에는 두 가지가 필요하다:

| 필요 | 소스 | 이유 |
|---|---|---|
| **미션별** 구간 소진 현황 | 분산 영역이 만드는 구간 카운터 테이블 (가칭 `farm_mission_slot_counters(mission_id, stat_date, slot_no, quota, used)`) | **그 테이블이 이미 정답이다.** 운영에 반드시 존재하고, 정확하며, 실시간이다. 통계용으로 복제하면 두 숫자가 갈라진다 |
| **전사** 시간대 쏠림 | `farm_stat_hourly_total` (24행/일) | 미션 무관. 서비스 전체가 오전에 몰리는지 확인 |

미션별 시간 통계를 따로 만들면 미션 2만 × 24 = **하루 48만 행, 한 달 1,440만 행**이다.
같은 정보를 두 곳에 두는 비용치고 너무 크고, 무엇보다 **운영 카운터와 통계가 어긋났을 때 어느 쪽이 진실인지 판단 불가**가 된다.

→ 정산 화면의 "미션 시간대 분포" 위젯은 `farm_mission_slot_counters`를 직접 조회한다.
그 테이블의 보존 기간(권장 **90일**)이 곧 미션별 시간 분석의 가용 기간이다. 90일 = 미션 2만 × 6구간 × 90 = 1,080만 행. 그 이후는 삭제하고, 장기 추세는 전사 hourly로 본다.

### 3-5. 롤업 · 보존 정책

| 데이터 | 보존 | 이후 |
|---|---|---|
| `farm_mission_logs` | **6개월** (월 RANGE 파티션) | `DROP PARTITION` |
| `farm_mission_slot_counters` | 90일 | DELETE (분산 영역 소유) |
| `farm_stat_hourly_total` | 영구 | 24행/일 = 무시 가능 |
| `farm_settlement_daily` | 36개월 | DELETE (월별만 남김) |
| `farm_settlement_monthly` | **영구** | 정산 근거 |
| `farm_settlement_adjustments` | **영구** | |
| `farm_liability_snapshots` | **영구** | 48행/일 = 1년 17,520행 |
| `farm_point_ledgers` | **영구** | 지급 근거. 하루 14만 행 × 200B = 28MB/일 = 10GB/년 |

**로그 6개월 근거 (숫자):**
행 크기 ≈ 180B(데이터) + 120B(인덱스 4개) = **300B**.
하루 시도 로그 = 정답 100만 + 오답·거절 50만 = **150만 행** → 450MB/일 → 13.5GB/월.
- 6개월 = **81GB**
- 13개월 = 176GB ← rankfree 서버가 감당 못 한다(디스크 여유 **확인 필요** §10-4)

6개월이 지난 로그가 필요해지는 경우는 (a) 어뷰징 소급 조사 (b) 정산 분쟁이다.
(b)는 `farm_settlement_daily`(영구)로 대응 가능하고, (a)는 6개월이면 실무상 충분하다.
파티션 DROP 직전 월은 `storage/app/farm-archive/{YYYYMM}.csv.gz`로 덤프해 둔다(gzip 후 약 3GB/월).

**파티션 구현** — rankfree의 `keyword_place_ranks` 패턴을 그대로 복사:
- 파티션 키 `created_month unsignedInteger`(YYYYMM), PK를 `(id, created_month)` 복합으로 재선언
- `$t->unsignedBigInteger('id', false)->autoIncrement()->startingValue(1)` → raw `ALTER TABLE ... DROP PRIMARY KEY, ADD PRIMARY KEY (id, created_month)`
- 파티션 테이블에는 **FK를 걸 수 없다.** 초안이 이미 로그에 FK를 안 걸기로 했으므로 충돌 없다. 단 `farm_user_id`의 `constrained()`는 **제거해야 한다** ← 초안 §2-4와 충돌하는 지점, 로그 영역과 협의 필요
- 파티션 테이블에는 `timestamps()` 대신 `created_at`만 (rankfree 관례)
- 로테이션: `farm:partition-rotate` 매일 **05:55 KST** (`HubPartitionRotate` 복사, `hub:partition-rotate` 05:50과 5분 차이)
- sqlite 폴백: 드라이버 분기 + `where('created_month','<',$cutoff)->delete()`

### 3-6. 집계 지연 시 화면 표시

| 지연 | 표시 |
|---|---|
| ~5분 (정상) | 우측 상단 회색 배지 `정산 집계 14:35 기준` |
| 5~15분 | 같은 배지 + `(10분 전)` 노란색 |
| 15분 초과 | 화면 상단 노란 배너 **"정산 집계가 지연되고 있어요. 마지막 집계 14:20. 참여량은 실시간입니다."** |
| 60분 초과 | 위 배너 빨간색 + 잔디 웹훅 알림 (`SendJandiOrderNotification` 패턴) |
| 마감된 기간 조회 | 배지 대신 `2026-06 마감 (2026-07-05 확정)` 초록 배지 |

기준값은 `farm_rollup_cursors.last_run_at`. 화면 컨트롤러가 이 한 행만 읽으면 된다.

**지연 중에도 죽지 않는 값:**
- 참여량 / 소진 / 잔여 → 한도 카운터 직접 조회 (실시간)
- 지급 대기 부채 → `farm_point_ledgers` 직접 조회 (실시간)
- 수입 / 지출 / 수익 / 부채 잔액 → 배치값 (지연 표기)

이 구분을 화면에 **아이콘으로 명시**한다(⚡ 실시간 / 🕐 집계). 운영자가 "숫자가 안 맞는다"고 문의하는 상황을 원천 차단한다.

---

## 4. 정산 화면 지표와 계산식

### 4-0. 공통 필터

| 필터 | 파라미터 | 적용 |
|---|---|---|
| 기간 | `from`, `to` (기본 최근 7일) | `stat_date BETWEEN` |
| 미션 | `mission_id[]` | `mission_id IN` (전사행 제외) |
| 주문 | `order_id` | `order_id =` |
| 광고주 | `advertiser_user_id` | `advertiser_user_id =` |
| 집계 축 | `axis` = `total` / `mission` / `order` / `advertiser` | 아래 SQL의 GROUP BY |

> 🔴 필터가 하나라도 걸리면 **전사행(mission_id=0)을 쓸 수 없다.** 미션행 SUM으로 전환한다.
> 단 전사행에만 있는 지표(payout·liability·planted)는 미션별로 분해 불가 → **필터 적용 시 해당 칸은 `—`로 비운다.**
> 이건 버그가 아니라 설계다. 툴팁: "포인트 지출은 사용자 단위로 발생해 미션별로 나눌 수 없어요."

### 4-1. 기간 요약 KPI (전사, 필터 없음)

```sql
SELECT
  SUM(participations)                                      AS 참여량,
  SUM(billable_cnt)                                        AS 청구_참여량,
  SUM(overage_cnt)                                         AS 한도초과_참여량,
  SUM(unique_users)                                        AS 참여자수_중복포함,
  SUM(revenue_krw)                                         AS 수입,
  SUM(payout_reserved_krw)                                 AS 지출_발생액,
  SUM(payout_success_krw)                                  AS 지불한_금액,
  SUM(fee_krw)                                             AS 수수료,
  SUM(revenue_krw) - SUM(payout_reserved_krw) - SUM(fee_krw)      AS 수익_발생주의,
  SUM(revenue_krw) - SUM(payout_success_krw)  - SUM(fee_krw)      AS 수익_현금주의,
  ROUND(SUM(payout_reserved_krw) / NULLIF(SUM(participations),0)) AS 참여당_비용,
  ROUND(SUM(revenue_krw)         / NULLIF(SUM(billable_cnt),0))   AS 참여당_수입,
  ROUND((SUM(revenue_krw) - SUM(payout_reserved_krw) - SUM(fee_krw))
        * 100.0 / NULLIF(SUM(revenue_krw),0), 1)                  AS 수익률_퍼센트
FROM farm_settlement_daily
WHERE stat_date BETWEEN :from AND :to
  AND mission_id = 0;                       -- 🔴 전사행만
```

**"참여당 비용"의 의미를 화면에 명시한다.**
`지출 발생액 ÷ 참여량`은 **참여 1건이 유발한 평균 포인트 비용**이다.
7일 코스이므로 이 값은 대략 `작물포인트 ÷ 7 × 완주율`에 수렴한다.
예: 500P ÷ 7 × 0.60 = **약 43원/건**. 초기(수확이 없는 첫 7일)에는 0으로 나오다가 8일차부터 치솟는다 → **서비스 첫 14일은 이 지표를 신뢰하지 말라는 안내 문구를 붙인다.**

### 4-2. 일별 추이 (표 + 라인 차트)

```sql
SELECT stat_date,
       participations, billable_cnt, overage_cnt,
       revenue_krw, payout_reserved_krw, payout_success_krw, fee_krw,
       revenue_krw - payout_reserved_krw - fee_krw AS profit_krw
FROM farm_settlement_daily
WHERE stat_date BETWEEN :from AND :to AND mission_id = 0
ORDER BY stat_date;
```

### 4-3. 미션별 실적 (정렬·페이징)

```sql
SELECT
  d.mission_id, m.title, m.unit_revenue, m.planned_quantity,
  o.order_no, u.name AS advertiser,
  SUM(d.participations) AS 참여량,
  SUM(d.billable_cnt)   AS 청구_참여량,
  SUM(d.overage_cnt)    AS 초과,
  SUM(d.revenue_krw)    AS 수입,
  SUM(d.overage_krw)    AS 기회손실,
  ROUND(SUM(d.billable_cnt) * 100.0 / NULLIF(m.planned_quantity,0), 1) AS 이행률
FROM farm_settlement_daily d
JOIN farm_missions   m ON m.id = d.mission_id
LEFT JOIN marketing_orders o ON o.id = m.order_id
LEFT JOIN users            u ON u.id = m.advertiser_user_id
WHERE d.stat_date BETWEEN :from AND :to
  AND d.mission_id > 0                      -- 🔴 미션행만
GROUP BY d.mission_id
ORDER BY 수입 DESC
LIMIT 50 OFFSET :offset;
```

⚠️ `marketing_orders`/`users` 조인은 **LEFT JOIN**이다. 주문이 지워져도 정산은 남아야 한다.

### 4-4. 광고주별 집계

`GROUP BY d.advertiser_user_id`로 축만 바꾼다. 필요 인덱스 `fsd_adv(advertiser_user_id, stat_date)`.

### 4-5. 앞으로 지불할 금액

```sql
-- (A) 지급 대기 — 확정 채무, 실시간
SELECT
  SUM(IF(status='pending',   amount, 0)) AS 대기,
  SUM(IF(status='requested', amount, 0)) AS 요청중,
  SUM(IF(status='held',      amount, 0)) AS 보류,
  COUNT(*)                               AS 건수
FROM farm_point_ledgers
WHERE status IN ('pending','requested','held');

-- (B) 미래 지급 의무 — 조건부 채무, 최신 스냅샷 + 오늘분 델타
SELECT
  SUM(gross_krw)    AS 최대_부채,
  SUM(expected_krw) AS 기대_부채,
  SUM(plantings_cnt) AS 진행중_작물
FROM farm_liability_snapshots
WHERE snapshot_date = (SELECT MAX(snapshot_date) FROM farm_liability_snapshots)
  AND progress_day BETWEEN 1 AND 7;

-- (B') 잠재 부채 (심기만, 회색 표시)
--   위 쿼리에서 progress_day = 0

-- 화면 표시값
앞으로_지불할_금액 = (A).대기 + (A).요청중 + (A).보류
                    + (B).최대_부채
                    + 오늘_델타(§2-8)
```

**"기간별"** 은 §2-6의 지급 캘린더 쿼리를 쓴다 — 향후 7~10일을 날짜별 막대로 표시하고, 그 위에 **비즈월렛 잔액 라인**을 겹친다.
잔액이 캘린더 누적을 밑도는 날짜부터 빨간색으로 칠한다 → 충전 시점이 한눈에 보인다.
(비즈월렛 잔액 조회 API 유무 **확인 필요** §10-5. 없으면 운영자가 `app_settings`에 수동 입력.)

### 4-6. 미이행 잔량 (광고주 방향 부채)

```sql
SELECT
  m.order_id, o.order_no, u.name AS advertiser,
  SUM(m.planned_quantity)                       AS 목표,
  COALESCE(SUM(d.billable_cnt), 0)              AS 이행,
  SUM(m.planned_quantity) - COALESCE(SUM(d.billable_cnt),0) AS 미이행,
  (SUM(m.planned_quantity) - COALESCE(SUM(d.billable_cnt),0)) * MAX(m.unit_revenue) AS 선수금_잔액
FROM farm_missions m
LEFT JOIN (
  SELECT mission_id, SUM(billable_cnt) AS billable_cnt
  FROM farm_settlement_daily WHERE mission_id > 0 GROUP BY mission_id
) d ON d.mission_id = m.id
LEFT JOIN marketing_orders o ON o.id = m.order_id
LEFT JOIN users u ON u.id = m.advertiser_user_id
WHERE m.ends_at < NOW()                         -- 종료된 미션만 = 미이행 확정
GROUP BY m.order_id
HAVING 미이행 > 0
ORDER BY 선수금_잔액 DESC;
```

이 표가 **환불·기간 연장 대상 목록**이다. 종료 3일 전 미션에는 별도 경고(§6-3).

### 4-7. 실시간 소진 현황 (배치 무관)

```sql
-- 한도 영역의 카운터 테이블 직접 조회. 이름은 확정 필요
SELECT c.mission_id, m.title, c.daily_limit, c.used,
       c.daily_limit - c.used AS remaining,
       ROUND(c.used * 100.0 / NULLIF(c.daily_limit,0), 1) AS 소진율
FROM farm_mission_daily_counters c
JOIN farm_missions m ON m.id = c.mission_id
WHERE c.stat_date = CURDATE()
ORDER BY 소진율 DESC;
```

⚡ 아이콘 + "실시간" 표기. 미션이 샤딩돼 있으면 `SUM(used) GROUP BY mission_id`로 샤드를 합친다.

---

## 5. 한도 초과분 정산

### 5-1. 초과는 왜 생기나 — 그리고 얼마나 생겨야 정상인가

원자적 UPDATE `WHERE used < daily_limit`만 쓰면 초과는 **구조적으로 0**이다.
초과가 생기는 경로는 정확히 3가지뿐이다:

| 경로 | 설명 | 예상 초과율 |
|---|---|---|
| 샤딩 | 미션당 N샤드로 한도를 나누면 샤드 잔여의 합이 총한도를 넘을 수 있다(반올림·불균등 배분) | 샤드 수 N에 비례, N=4면 최대 3건/미션/일 |
| 구간 경계 | 시간 구간 미소진분 이월 처리 중 이중 계상 | 구간 전환 순간에만, 미션당 0~1건 |
| 캐시 스테일 | 읽기 판정을 통과했는데 쓰기 경로가 한도를 무시하면 발생 | **0이어야 한다. 발생하면 버그** |

**목표·임계 (숫자):**

| 지표 | 값 | 대응 |
|---|---|---|
| 목표 일 초과율 | **≤ 0.01%** (하루 100만 건 기준 100건) | 정상 |
| 경고 임계 | **0.05%** (500건) | 잔디 알림 + 어드민 노란 배너 |
| 심각 임계 | **0.2%** (2,000건) | 잔디 @here + 어드민 빨간 배너. 샤드 수 재조정 검토 |

0.01%가 목표인 근거: 미션 2만 개 × 샤드 4개 기준 이론 최대 초과 = 미션당 3건 × 2만 = 6만건이지만, 실제로는 **한도까지 소진되는 미션이 전체의 5% 내외**(대부분은 잔여를 남긴 채 하루가 끝난다). 6만 × 5% × 경합 발생 확률 ≈ 100~300건. 여기에 샤드 잔여 재분배 로직(분산 영역)이 붙으면 100건 이하로 떨어진다.

### 5-2. billable 판정 — 참여 확정 트랜잭션에서

```
[한도 영역이 반환하는 값]
  seq_in_day = 한도 카운터 UPDATE 후의 used 값 (그 미션 그날 몇 번째 참여인지)

[정산 영역의 판정]
  billable = (seq_in_day <= mission.daily_limit)
```

> 🔴 **초과라고 사용자를 거절하지 않는다.** 이미 정답을 맞힌 사용자에게 "한도 초과"를 내리면
> (a) 정답이 노출된 뒤라 다시 시도할 수 없고 (b) CS가 폭증하고 (c) 토스 심사에서 다크패턴으로 걸린다.
> **참여는 성공 처리하고, 정산에서만 billable=false로 표시한다.** 손실은 우리가 흡수한다.

### 5-3. 손실의 두 얼굴 — 반드시 분리 표시

| 지표 | 계산 | 하루 100건 초과 시 | 의미 |
|---|---|---|---|
| **기회손실** `overage_krw` | `Σ unit_revenue WHERE NOT billable` | 100 × 375원 = **37,500원** | 청구했다면 받았을 매출. **재고(미소진 미션)가 남아 있을 때만 실질적** |
| **실손실** `overage_cost_krw` | `overage_cnt × 참여당_비용` | 100 × 43원 = **4,300원** | 그 참여에 실제로 나간 포인트. **항상 실질적** |

`참여당_비용`은 최근 7일 실측치를 쓴다:

```sql
SELECT ROUND(SUM(payout_reserved_krw) / NULLIF(SUM(participations),0)) AS unit_cost
FROM farm_settlement_daily
WHERE stat_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND DATE_SUB(CURDATE(), INTERVAL 1 DAY)
  AND mission_id = 0;
```

`overage_cost_krw`는 **테이블에 저장하지 않고 화면에서 파생**한다. 계수(참여당 비용)가 소급 변하면 과거 값도 함께 변해야 자연스럽기 때문이다.

**화면 문구 (그대로 씀):**
> 한도 초과 100건 — 광고주에게 청구할 수 없는 참여입니다.
> · 놓친 매출(기회손실) 37,500원 — 다른 미션에 여유가 있었다면 그쪽으로 돌릴 수 있었어요
> · 실제 나간 비용(실손실) 4,300원 — 이 참여에 지급될 포인트

### 5-4. 광고주 청구분과 우리 손실분의 경계

| 구분 | 광고주 청구(매출 인식) | 우리 부담 |
|---|---|---|
| 한도 내 참여 (`billable=1`) | ✅ `unit_revenue` | 포인트 비용 |
| 한도 초과 참여 (`billable=0`) | ❌ 0원 | 포인트 비용 **전액** |
| 오답·거절 | ❌ | 없음 (포인트 미발생) |
| 미이행 잔량 | 이미 받은 선수금 → **환불 또는 기간 연장 의무** | |

> ⚠️ 광고주에게 **초과분을 서비스로 제공했다고 알리지 않는다.** "60건 했는데 50건만 청구" 구조는
> 광고주 입장에서 무료 혜택이지만, 알리는 순간 다음 주문에서 그만큼을 기대하게 된다.
> 광고주 화면(§6-5)에는 `billable_cnt`만 노출하고 `overage_cnt`는 내부 화면에만 둔다.

### 5-5. 초과 감지 배치

`farm:check-overage` — 매일 **09:30 KST**(전일 확정 후)

```
어제 = CURDATE() - 1
전사 = SELECT participations, overage_cnt FROM farm_settlement_daily
        WHERE stat_date = 어제 AND mission_id = 0
rate = overage_cnt / participations

if rate >= 0.002  → 잔디 @here "🔴 한도 초과율 {rate}% ({cnt}건). 샤드 배분 점검 필요"
elif rate >= 0.0005 → 잔디 "🟡 한도 초과율 {rate}% ({cnt}건)"

// 미션별 TOP 10 도 함께 보낸다 — 특정 미션에만 몰리면 그 미션의 샤드 설정 문제다
SELECT mission_id, overage_cnt, participations
FROM farm_settlement_daily
WHERE stat_date = 어제 AND mission_id > 0 AND overage_cnt > 0
ORDER BY overage_cnt DESC LIMIT 10;
```

---

## 6. 관리자 화면

### 6-1. 라우트 (`routes/web.php` 의 `$__admin` 그룹)

`Route::resource()`를 쓰지 않고 한 줄씩 명시(rankfree 관례). 고정 경로를 `{model}`보다 앞에 둔다.

```php
// 퀴즈농장(31) — 정산·부채·통계(2026-07-28)
Route::get('/farm-settlement',            [FarmSettlementController::class, 'index'])->name('admin.farm-settlement');
Route::get('/farm-settlement/export',     [FarmSettlementController::class, 'export'])->name('admin.farm-settlement.export');
Route::get('/farm-settlement/monthly',    [FarmSettlementController::class, 'monthly'])->name('admin.farm-settlement.monthly');
Route::post('/farm-settlement/close',     [FarmSettlementController::class, 'close'])->name('admin.farm-settlement.close');
Route::post('/farm-settlement/adjust',    [FarmSettlementController::class, 'adjust'])->name('admin.farm-settlement.adjust');
Route::post('/farm-settlement/rebuild',   [FarmSettlementController::class, 'rebuild'])->name('admin.farm-settlement.rebuild');

Route::get('/farm-liability',             [FarmLiabilityController::class, 'index'])->name('admin.farm-liability');

// 미션(세부주문서) — 정산 컬럼이 붙은 확장 목록
Route::get('/farm-missions',              [FarmMissionController::class, 'index'])->name('admin.farm-missions');
Route::get('/farm-missions/{mission}',    [FarmMissionController::class, 'show'])->name('admin.farm-missions.show');
// (create/store/edit/update/destroy/toggle 는 미션 영역 설계서 참조)
```

목록 라우트명에 `.index`를 붙이지 않는다(`route('admin.farm-settlement')`가 목록).
메뉴 마이그레이션(`2026_07_22_000200_add_coupon_menus.php`의 `insertMenu()` 복사)에 2행 추가:

| name | route | icon |
|---|---|---|
| 퀴즈농장 정산 | `admin.farm-settlement` | `fa-solid fa-won-sign` |
| 퀴즈농장 부채 | `admin.farm-liability` | `fa-solid fa-scale-balanced` |

⚠️ `menus` 행이 없으면 사이드바에 안 뜨고 `x-console.page-head`가 제목을 못 찾는다.

### 6-2. 정산 대시보드 `admin.farm-settlement`

```
┌ 퀴즈농장 정산 ──────────────── [🕐 집계 14:35 기준] [기간 ▾] [내보내기] ┐
│                                                                        │
│ ⚡ 참여량        청구 참여량      🔴 한도 초과       이행률             │
│   1,004,120        1,003,980          140 (0.014%)     98.2%           │
│                                                                        │
│ 🕐 수입          🕐 지출(발생)     🕐 수수료         🕐 수익            │
│   376,492,500원    43,180,000원        0원          333,312,500원      │
│                                     └ 수익률 88.5%                     │
│                                                                        │
│ 🕐 지불한 금액    ⚡ 지급 대기      🕐 앞으로 지불할 금액                │
│   41,900,000원     1,280,000원(42건)   187,400,000원                   │
│                                     └ 기대 121,810,000원               │
└────────────────────────────────────────────────────────────────────────┘

[일별 추이]  날짜 | 참여 | 청구 | 초과 | 수입 | 지출 | 수수료 | 수익 | 수익률
[미션별 TOP 20]  미션 | 주문번호 | 광고주 | 참여 | 청구 | 초과 | 수입 | 이행률
[한도 초과 상세]  기회손실 52,500원 / 실손실 6,020원  ← 툴팁으로 차이 설명
```

- 상단 필터: 기간(오늘/7일/30일/이번 달/직접), 미션, 주문, 광고주, 집계 축
- **⚡/🕐 아이콘으로 실시간·집계를 구분** (§3-6)
- `[내보내기]` → CSV. `chunkById(1000)` + `StreamedResponse` (rankfree에 선례 없음 — 신규)
- `[집계 다시 돌리기]`(rebuild)는 `data-confirm` SweetAlert. 커서를 되감고 `farm:rollup-stats --from=YYYY-MM-DD` 실행. **마감된 기간은 거부**
- 스타일은 `.btn/.card/.input/.badge` + `var(--fs-*)`, `var(--color-*)` 토큰만. 하드코딩 hex·12px 미만 폰트 금지

**월 마감 화면** (`/farm-settlement/monthly`)

| period | 주문수 | 참여 | 수입 | 지출 | 수수료 | 수익 | 정정 | 상태 |
|---|---|---|---|---|---|---|---|---|
| 2026-07 | 128 | 30,120,400 | 11.2억 | 1.3억 | 0 | 9.9억 | +0 | 진행중 `[마감]` |
| 2026-06 | 96 | 28,004,110 | 10.5억 | 1.2억 | 0 | 9.3억 | −120,000 | 🔒 마감 (07-05) |

- `[마감]` → `closed_at`/`closed_by` 기록. 이후 그 기간은 롤업 배치가 건너뛴다.
- 마감 후 정정은 `[정정 추가]` 모달 → `farm_settlement_adjustments` 행(kind·금액·사유 필수)
- 마감 해제는 **UI에 두지 않는다.** 필요하면 `php artisan farm:unclose 2026-06 --reason="..."` (콘솔에서만, 감사 로그 남김)

### 6-3. 미션(세부주문서) 목록 `admin.farm-missions`

**상단 경고 칩** — 순서가 곧 우선순위다:

| 칩 | 조건 | 색 | 왜 급한가 |
|---|---|---|---|
| **정답 미입력 N건** | `answer IS NULL AND question IS NOT NULL AND starts_at <= 내일` | 빨강 | 노출 불가 = 그날 이행 0 = 매출 0 |
| **단가 0원 N건** | `unit_revenue = 0` | 빨강 | 정산 불가. §1-2 분모 0 케이스 |
| 종료 임박 미이행 N건 | `ends_at <= +3일 AND 이행률 < 80%` | 주황 | 환불·연장 협상 필요 |
| 오늘 미소진 N건 | `used = 0 AND 오늘 노출 대상` | 노랑 | 노출 로직 문제 의심 |
| 한도 초과 발생 N건 | 어제 `overage_cnt > 0` | 노랑 | 샤드 설정 점검 |

**목록 컬럼**

| 컬럼 | 소스 | 비고 |
|---|---|---|
| 미션명 | `farm_missions.title` | |
| 주문번호 | `marketing_orders.order_no` | `/admin/orders/{id}` 링크 |
| 광고주 | `users.name` | |
| 기간 | `starts_at ~ ends_at` | |
| 단가 | `unit_revenue` | 0이면 빨간 배지 |
| 일 한도 | 한도 카운터 `daily_limit` | ⚡ |
| **오늘 소진** | `used / daily_limit` + 막대 | ⚡ 실시간 |
| **누적 / 목표** | `Σ billable_cnt / planned_quantity` | 🕐 |
| 이행률 | 위 비율 % | 80% 미만 주황, 100% 초록 |
| 정답 | `••••` 또는 `미입력` 빨간 배지 | **평문 절대 표시 금지** |
| 정답률 | 어제 `correct / attempts` | 급락하면 정답이 바뀐 미션 |
| 상태 / 활성 | `.rf-switch` 토글 | |

**미션 상세 `admin.farm-missions/{id}`** — 3개 탭
1. **정산**: 일별 참여·수입·초과·이행률
2. **시간대 분포**: `farm_mission_slot_counters` 직접 조회. 구간별 quota/used 막대 → 분산이 실제로 되는지 확인
3. **로그**: `farm_mission_logs` 최근 200건 (result·reject_reason·billable·seq_in_day). 정답 원문은 마스킹

### 6-4. 퀴즈 정답 등록/수정

폼 항목은 초안 §7-4를 따르고, **정산 영역이 요구하는 변경 3가지**만 얹는다:

| 변경 | 내용 |
|---|---|
| 세부주문서 연동 블록 (읽기 전용) | 주문번호 / 광고주 / 상점명 / 상품명 / 가격 / 목표 수량 / **정산 단가** / 진행 기간. `marketing_order_items` + `shop_keyword_analyses`에서 자동 채움 |
| `points` 잠금 | 0 고정 + `readonly`. 툴팁 "포인트는 수확 시점에만 지급돼요." 해제는 별도 권한 필드 |
| `answer` 저장 시 이력 | `farm_mission_answer_logs`(mission_id, old_hash, new_hash, changed_by, created_at) — 정답 변경은 정답률 급락의 원인이므로 추적. **평문은 저장하지 않고 sha256만** |

**미션 자동 생성** (세부주문서 → 미션): 세부주문서가 생기면 미션을 `draft`로 자동 생성하고, 정답 입력 후 운영자가 `active`로 올린다.
`farm:sync-missions` 매일 **08:00 KST**:
```
대상 = marketing_order_items
       WHERE vendor_id = config('rankfree.farm.vendor_id')     ← 퀴즈농장 vendor id (config 고정)
         AND status <> 'canceled'
         AND end_date >= 오늘
  → farm_missions 에 order_item_id 로 upsert
     · 신규: draft 생성 + unit_revenue/planned_quantity 스냅샷
     · 기존: 참여가 0건이면 단가·수량·기간 갱신, 1건이라도 있으면 기간만 갱신(단가 잠금)
  → 정답 미입력 & 내일 시작인 미션이 있으면 잔디 알림
```

> ⚠️ `vendors`에 code/slug 컬럼이 없고 `name`에 unique도 없다. **이름 문자열 매칭 금지.**
> `config('rankfree.farm.vendor_id')`에 vendor id를 숫자로 고정한다(조사 결과의 권고를 그대로 따름).

### 6-5. 부채 현황 `admin.farm-liability`

```
[현재 부채]  최대 187,400,000원 / 기대 121,810,000원 / 진행중 374,800 작물
[잠재 부채]  (심기만) 회색 62,300,000원 / 124,600 작물

[진행도별]      d1      d2      d3      d4      d5      d6      d7(ready)
  작물수      112,400  78,200  54,100  42,300  38,900  31,200   17,700
  최대(원)   56.2M   39.1M   27.1M   21.2M   19.5M   15.6M     8.9M
  완주율      40%     52%     64%     75%     85%     93%      98%
  기대(원)   22.5M   20.3M   17.3M   15.9M   16.5M   14.5M     8.7M

[지급 캘린더]  ← §2-6. 막대 + 비즈월렛 잔액 라인
[최근 30일 부채 추이]  ← farm_liability_snapshots 시계열
[정합 검증]  ✅ 2026-07-27 스냅샷 항등식 오차 0원
```

- 완주율이 실측 전이면 각 값에 `추정` 배지
- 정합 오차가 1,000원을 넘은 날은 빨간 행 + 사유 링크

### 6-6. 광고주 화면 (선택, 2차)

rankfree에는 광고주가 자기 전체 주문을 보는 화면이 **없다**(`order/show.blade.php`가 `product_id`로 필터됨).
퀴즈농장 실적을 광고주에게 노출하려면 신규 화면이 필요하다 → **2차 과제로 미룬다.**
1차에서는 운영자가 CSV를 내려 전달한다. 노출 컬럼은 `참여량(=billable_cnt) / 목표 / 이행률 / 기간`만. **`overage_cnt`는 절대 포함하지 않는다**(§5-4).

---

## 7. 설정 (`config/rankfree.php`)

기존 `'farm' => [...]` 블록 안에 `billing` 하위 배열을 추가한다.

```php
'farm' => [
    // …기존 plot_count / daily_mission_limit / toss …

    /*
    | 정산·통계(31) — 요율·보존기간은 운영 중 바뀔 수 있어 app_settings 로 덮어쓴다
    */
    'vendor_id' => (int) env('FARM_VENDOR_ID', 0),   // 퀴즈농장 vendors.id. 0이면 sync 배치가 중단된다

    'billing' => [
        'vat_rate'                 => 0.10,
        'vat_included'             => true,          // unit_revenue 가 VAT 포함가인가 — 확인 필요
        'payout_fee_rate'          => (float) env('FARM_PAYOUT_FEE_RATE', 0.0),
        'fee_per_participation'    => (int)   env('FARM_FEE_PER_PARTICIPATION', 0),

        'abandon_after_days'       => (int)   env('FARM_ABANDON_DAYS', 14),
        'days_per_progress'        => (float) env('FARM_DAYS_PER_PROGRESS', 1.4),
        'complete_rate'            => [1=>0.40, 2=>0.52, 3=>0.64, 4=>0.75, 5=>0.85, 6=>0.93, 7=>0.98],
        'complete_rate_min_sample' => 1000,          // 표본 미달이면 실측으로 갱신하지 않는다

        'rollup_recalc_hours'      => 24,            // 5분 배치의 지출 재계산 범위
        'rollup_recalc_hours_hourly' => 72,          // 매시 정각 배치
        'rollup_lag_warn_minutes'  => 15,
        'rollup_lag_alert_minutes' => 60,

        'overage_warn_rate'        => 0.0005,        // 0.05%
        'overage_alert_rate'       => 0.002,         // 0.2%

        'log_retention_months'     => (int) env('FARM_LOG_RETENTION_MONTHS', 6),
        'daily_retention_months'   => 36,
        'slot_retention_days'      => 90,
    ],
],
```

어드민 환경설정 화면에는 **요율·보존기간·임계값만** 노출한다. `vendor_id`는 UI에 올리지 않는다(잘못 바꾸면 전 미션이 끊긴다).

---

## 8. 스케줄 (`routes/console.php`)

전부 `->timezone('Asia/Seoul')->withoutOverlapping()->runInBackground()` + 위에 한글 주석(rankfree 관례).
새 커맨드를 추가하면 `Admin\ScheduleOverviewController::META`에 desc/note를 함께 추가한다.

| 커맨드 | 주기 | 내용 | 소요 |
|---|---|---|---|
| `farm:rollup-stats` | **매 5분** | 수입 증분 + 지출 24h 재계산 + 시간대 + flow (§3-3) | 1.7초 |
| `farm:rollup-stats --deep` | **매시 00분** | 지출 72h 재계산 | 3.5초 |
| `farm:snapshot-liability` | **매일 00:20** | 전일 부채 스냅샷 + 정합 검증 (§2-5) | 3~6초 |
| `farm:rollup-monthly` | **매일 01:00** | 당월 + 전월 monthly 재계산(마감분 제외) | 2초 |
| `farm:expire-plantings` | **매일 04:00** | 14일 방치 → abandoned (§2-7) | 5~20초 |
| `farm:partition-rotate` | **매일 05:55** | 로그 파티션 선생성/파기 (§3-5) | 1초 (DROP 시 수 초) |
| `farm:sync-missions` | **매일 08:00** | 세부주문서 → 미션 동기화 + 정답 미입력 알림 (§6-4) | 1~3초 |
| `farm:check-overage` | **매일 09:30** | 전일 초과율 점검 + 알림 (§5-5) | 0.2초 |
| `farm:calc-complete-rate` | **매주 월 05:00** | 코호트 완주율 실측 (§2-4) | 10~30초 |
| `farm:archive-logs` | **매월 1일 03:00** | 파기 예정 파티션 CSV 덤프 | 수 분 |

시각 배치 근거: 04:00~06:00은 저트래픽 구간. 기존 `hub:partition-rotate`(05:50), `orders:dispatch-due`(09:00)와 겹치지 않게 5분씩 비켜 배치했다.

**⚠️ 새 커맨드는 큐를 쓰지 않는다** — 전부 스케줄러가 직접 실행한다. 큐를 쓰면 supervisor `--queue=` 목록에 추가해야 하고, 빠뜨리면 영원히 안 돌아간다(2026-07-22 '발행 0' 실사고).

---

## 9. 구현 순서

| 단계 | 내용 | 공수 | 선행 |
|---|---|---|---|
| 1 | `farm_missions`·`farm_mission_logs`·`farm_plantings` 컬럼 추가 마이그레이션 (§1-3, §1-4) | 반나절 | 미션·로그 영역 스키마 확정 |
| 2 | `farm:sync-missions` — 세부주문서 → 미션 + 단가 스냅샷 (§1-2, §6-4) | 반나절 | vendor_id 확정 |
| 3 | 참여 확정 경로에 `unit_revenue`/`billable`/`seq_in_day` 기록 (§5-2) | 반나절 | 한도 영역이 `seq_in_day` 반환 |
| 4 | 집계 테이블 6개 + 모델 (§3-2) | 반나절 | |
| 5 | `farm:rollup-stats` (§3-3) 🔴 | 하루 | 1·3·4 |
| 6 | `farm_plantings.reward_points` 스냅샷 + `farm:snapshot-liability` + `farm:expire-plantings` (§2) 🔴 | 하루 | 1 |
| 7 | 정산 대시보드 + 부채 화면 (§6-2, §6-5) | 하루 | 5·6 |
| 8 | 미션 목록 정산 컬럼 + 경고 칩 + 상세 3탭 (§6-3) | 하루 | 5 |
| 9 | 월 마감·정정·CSV 내보내기 (§6-2) | 반나절 | 7 |
| 10 | 로그 파티션 + 로테이션 + 아카이브 (§3-5) | 반나절 | 1 |
| 11 | `farm:check-overage`·`farm:calc-complete-rate` + 잔디 알림 | 반나절 | 5·6 |

**총 8일.** 5·6이 핵심이고 나머지는 그 위의 표현 계층이다.

**테스트 (sqlite)**
- 롤업 멱등성: 같은 배치를 2번 돌려도 `farm_settlement_daily` 값이 변하지 않는다
- 커서 되감기: `--from` 지정 시 그 구간만 재계산되고 이후 구간이 망가지지 않는다
- 이중계상: `mission_id=0`과 `>0`의 `participations` 합이 서로 일치한다
- 부채 항등식: 심기 → 7일 참여 → 수확 시나리오에서 스냅샷 차이 = flow 합
- 방치: `ready` 작물이 만료 대상에서 제외된다
- billable: `seq_in_day > daily_limit`이면 `revenue_krw`에 안 잡히고 `overage_krw`에 잡힌다
- 마감: `closed_at`이 있는 기간은 롤업이 건너뛴다

---

## 10. 확인 필요 (설계 확정 불가 항목)

| # | 항목 | 왜 필요한가 | 임시 처리 |
|---|---|---|---|
| 1 | **토스 프로모션 지급 수수료 요율** | `fee_krw` 계산. 수익률이 통째로 달라진다 | `payout_fee_rate = 0.0`, 화면에 "수수료 미반영" 주석 |
| 2 | **`marketing_orders.total_price`가 VAT 포함인가** | 공급가액·부가세 분리 표시 | `vat_included = true` 가정, config 한 줄로 뒤집힘 |
| 3 | **퀴즈농장 `vendors.id`** | 세부주문서 필터의 유일한 기준. 이름 매칭은 위험(unique 없음) | `FARM_VENDOR_ID` env, 0이면 sync 배치 중단 + 알림 |
| 4 | **서버 디스크 여유 용량** | 로그 6개월 81GB + 원장 10GB/년 + 집계 3GB | 6개월 가정. 부족하면 3개월로 축소(41GB) |
| 5 | **비즈월렛 잔액 조회 API 유무** | 지급 캘린더에 잔액 라인을 겹치려면 필요 | 없으면 `app_settings`에 운영자 수동 입력 |
| 6 | **3대 서버의 스케줄러·락 구성** | 배치가 3중 실행되면 집계가 3배가 된다 | `cache_locks`를 MariaDB로 고정, 스케줄 크론은 1대에만 등록 |
| 7 | **실제 활성 미션 수** | 모든 행 수·용량 추정의 기반(2만 가정) | 2만 가정. 10만이면 `farm_settlement_daily`가 하루 10만 행 → 월 파티션 검토 |
| 8 | **`farm_crops.points` 실제 값** | 부채 총액 추정(500P 가정) | 500P 가정. §0의 모든 금액 예시가 이 값에 비례 |
| 9 | **한도 카운터 테이블·컬럼명** | `seq_in_day` 반환과 §4-7 실시간 조회 | `farm_mission_daily_counters(mission_id, stat_date, shard_no, daily_limit, used)` 가정 |
| 10 | **로그 테이블 파티션 전환 합의** | 파티션은 FK를 못 건다. 초안 §2-4의 `farm_user_id constrained()` 제거 필요 | 로그 영역과 협의 |

---

## 부록 A. 정산 복구 (`farm:rebuild-settlement`)

원장(로그·포인트 원장)만 살아 있으면 집계는 **전부 재생성 가능**하다. 이것이 3계층 원칙의 실익이다.

```
php artisan farm:rebuild-settlement --from=2026-07-01 --to=2026-07-27 [--force]

1) 마감된 기간 검사 → --force 없으면 거부
2) 대상 기간의 farm_settlement_daily / farm_stat_hourly_total 삭제
3) farm_mission_logs 를 created_at 범위로 chunkById(5000) 스캔 → 재집계
4) farm_point_ledgers 를 같은 범위로 재집계
5) farm_settlement_monthly 재계산
6) farm_rollup_cursors 를 --to 시점의 max(id) 로 재설정
```

**복구 불가한 것 하나:** `farm_liability_snapshots`는 **과거 시점의 활성 작물 상태**라 로그만으로 완전 복원되지 않는다.
`farm_planting_days`(참여일 로그) + `farm_harvests` + `farm_plantings.abandoned_at`으로 역산은 가능하지만, 방치 판정 시점의 config 값이 바뀌었다면 어긋난다.
→ **스냅샷 테이블은 절대 삭제하지 않는다.** 백업 우선순위 1등급으로 지정한다.

---

## 부록 B. 규모 요약표 (하루 100만 참여 전제)

| 항목 | 값 | 산출 |
|---|---|---|
| 평균 쓰기 QPS | **11.6** | 1,000,000 ÷ 86,400 |
| 피크 쓰기 QPS | **~50** | 평균 × 4 (저녁 집중) |
| 시도 로그 | 150만 행/일 | 정답 100만 + 오답·거절 50만 |
| 로그 용량 | 450MB/일, 13.5GB/월, **81GB/6개월** | 300B/행 |
| 수확 | 14.3만 건/일 | 100만 ÷ 7 |
| 원장 | 14.3만 행/일, 10GB/년 | 200B/행 |
| 활성 작물 | **300만 행** | 사용자 100만 × 3칸 |
| 집계 행 | 2만/일, 1.0GB/년 | 미션 2만 + 전사 1 |
| 스냅샷 행 | 48/일, 17,520/년 | 진행도 8 × 작물 6 |
| 5분 배치 입력 | 3,472행 | 1,000,000 ÷ 288 |
| 5분 배치 소요 | **1.7초** (0.6% 점유) | §3-3 |
| 수입(단가 375원) | 3.75억/일 | |
| 지출(500P, 완주 60%) | 4,300만/일 | 14.3만 × 500 × 0.6 |
| 수익률 | **약 88%** | |
| 참여당 비용 | **약 43원** | 500 ÷ 7 × 0.6 |
| 목표 초과율 | **≤ 0.01%** (100건/일) | 실손실 4,300원/일 |
