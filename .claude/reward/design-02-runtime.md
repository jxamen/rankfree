# 퀴즈농장 — 런타임 설계 02: 한도 초과 방지 · 쿨다운 · 시간대 분산 · 캐싱

> 대상 저장소: `C:\Users\jxame\Documents\project\rankfree` (Laravel 13.8 / PHP 8.3 / MariaDB 11.4.2)
> 선행 문서: `docs/rankfree-integration-v1-draft.md`(스키마·컨트롤러 관례), `docs/infra-constraints.md`(캐시 계층 확정)
> rankfree 등록 시 파일명: `.claude/30_FARM_RUNTIME.md`
> 작성일: 2026-07-28

---

## 0. 결정 카드 (읽고 바로 구현할 사람용)

| # | 쟁점 | 결정 | 한 줄 근거 |
|---|---|---|---|
| D1 | 원자성 수단 | **MySQL 조건부 atomic UPDATE** (`WHERE used < limit`) | 1 RTT·락 보유 0.15ms. Redis DECR은 원장 불가(과거 장애), `FOR UPDATE`는 락 보유 6배 |
| D2 | 카운터 위치 | **`farm_mission_slot_quotas` 단일 테이블** (구간 단위). 일 단위 카운터 테이블 없음 | `Σalloc = D_eff`이므로 구간 한도만 지키면 일 한도가 수학적으로 보장됨(§6-3 증명) |
| D3 | 카운터 UPDATE 위치 | **참여 확정 트랜잭션의 첫 문장** (로그 INSERT와 같은 트랜잭션) | 카운터·로그가 절대 어긋나지 않음 → 초과·미달 모두 구조적으로 0 |
| D4 | 채점 vs 슬롯 확보 순서 | **사용자 한도 검사 → 채점 → 슬롯 확보** | 오답 트래픽이 hot row를 안 침. 슬롯 반환 로직 불필요(카운터 단조 증가) |
| D5 | 선점(홀드) | **Phase 2로 분리.** Phase 1은 제출 원샷 + 잔여율 노출 순위 | 홀드 없이도 초과는 0. 홀드는 "정답인데 마감" UX 사고(참여의 2%)를 0.2%로 줄이는 장치 |
| D6 | 쿨다운 저장 | **`farm_users.cooldown_until` 컬럼 (DB 단독, 캐시 안 함)** | 인증 미들웨어가 이미 매 요청 `farm_users` 행을 읽는다 → 추가 쿼리 0. Redis 소실 시 전원 해제되는 사고도 없음 |
| D7 | 쿨다운 값 | **120분 ± 10분 지터** | 정확한 주기는 자동화 스크립트에 유리하고 구간 경계 몰림을 만듦 |
| D8 | 쿨다운 중 목록 | **미션은 그대로 보이되 전부 `completed:true` + `meta.cooldownUntil`.** 개인화 계산은 스킵하고 공용 캐시만 반환 | 숨기면 "할 게 없다"고 이탈. 개인화를 스킵하면 전체 목록 요청의 40%가 DB 쿼리 0 |
| D9 | 시간 구간 | **7구간 (06–09/09–12/12–14/14–18/18–20/20–22/22–02)** + **02–06 휴지** | 구간을 잘게 쪼개면 한도 10짜리 미션이 구간당 0이 됨. 심야는 정상 트래픽 1% 미만 |
| D10 | 미소진분 이월 | **1단계 캐리 + 캡(자기 배정량 이내). 마지막 구간(S7)만 전량 수용** | 전량 이월은 막판 쏠림, 무이월은 미달. 1단계+캡이면 최악에도 시간당 편차 2배 이내 |
| D11 | 하루의 정의 | **농장일 = KST 06:00 시작.** 사용자 한도·미션 수량 **둘 다** 이 축 | 자정 리셋은 23:50~00:10에 밭 3칸을 2일치 성장시키는 구멍을 만듦 |
| D12 | 캐시 계층 | **L1 APCu(1~5s) → L2 Redis(3~300s) → L3 MySQL(원장)**. 쓰기는 Redis를 절대 안 거침 | infra-constraints 확정. Redis 소실 = 성능 저하일 뿐 정합성 무영향 |
| D13 | Redis persistence | **RDB/AOF 전부 OFF, `allkeys-lru`, maxmemory 1GB** | 원장이 아니므로 "복구"라는 개념 자체를 없앤다 = 과거 장애 재발 방지 |
| D14 | 캐시 무효화 방식 | **DEL만 한다. SET으로 갱신하지 않는다** | 갱신은 순서 역전으로 낡은 값이 영구히 남을 수 있음 |
| D15 | 수확 경로 캐싱 | **금지.** 항상 DB 직접 판정 | 금전이 직접 나가는 경로 + QPS 0.55라 캐시 이득 0 |
| D16 | 샤딩 | **기본 1샤드.** 미션 1건 순간 50 QPS 또는 일 한도 ≥ 10,000일 때만 4샤드 | 피크에도 인기 미션 1건이 4.6 QPS. 샤딩은 한도 배분 오차라는 새 초과 경로를 만든다 |

**예상 초과율: 0.00%** (감사 임계 0.01% = 하루 100건). **예상 미달률: 8~12%**(운영 목표 5% 이하). 상세 §4-5.

---

## 1. 전제와 범위

### 1-1. 이 문서가 다루는 것 / 안 다루는 것

| 다룸 | 안 다룸(다른 영역) |
|---|---|
| 미션 수량 한도 게이트·카운터 | 미션 마스터 스키마, 세부주문서 매핑 상세 (design-01) |
| 사용자 쿨다운·일 참여 상한 | 포인트 원장·토스 지급 상태머신 (draft §8) |
| 시간대 분산 배분 | 정산·수익 집계 화면 (design-03) |
| 캐시 계층·키·무효화 | 게임 규칙(밭 3칸·7일), 채점 로직 |
| API 흐름의 **검증 순서와 캐시 조회 지점** | 응답 JSON 필드 정의(draft §6-0이 확정) |
| 배치·파티션·용량 | 관리자 화면 |

### 1-2. 확정된 인프라 전제 (`docs/infra-constraints.md`)

| 항목 | 값 |
|---|---|
| 웹 서버 | **3대** (rankfree 1 + 카페24 2), root 있음 |
| Redis | **설치 가능**, 현재 미설치 → 이 설계는 Redis 없이도 동작해야 함 |
| DB | MariaDB 11.4.2 단일 마스터 (복제 없음) |
| 원장 | **항상 MySQL.** Redis는 가속기 |
| 과거 사고 | 카페24 Redis 다운 후 복구 불가 → **Redis를 원장으로 두지 않는다** |

> ⚠️ rankfree 조사 문서에는 "Redis 없음 / `CACHE_STORE=database`"로 기록돼 있으나, 이는 **현재 상태**이지 제약이 아니다. 본 설계는 Redis를 **선택적 L2**로 두고, `config('rankfree.farm.cache.redis_enabled')`가 false면 L1+DB만으로 완전 동작한다(§7-5).

### 1-3. 다른 영역이 확정해줘야 하는 값 (본 설계의 입력)

| 입력 | 본 설계에서 부르는 이름 | 출처 추정 | 상태 |
|---|---|---|---|
| 퀴즈농장 업체 식별 | `config('rankfree.farm.vendor_id')` | `vendors.id` 고정값 (name unique 아님이 조사로 확인됨) | **확인 필요** |
| 미션 ↔ 세부주문서 연결 | `farm_missions.order_item_id` → `marketing_order_items.id` | design-01 | **확인 필요** |
| 일 주문횟수 | `farm_missions.daily_limit_qty` ← `marketing_order_items.quantity` | 조사 확인됨 | 확정 |
| 전체 수량 | `farm_missions.total_limit_qty` ← `quantity × (end_date − work_date + 1)` | **컬럼 없음.** 계산식이 design-01 판단 | **확인 필요** |
| 진행중 상태 | `marketing_order_items.status = 'sent'` AND 부모 `marketing_orders.status = 'processing'` | `MarketingOrderItem::STATUSES`·`MarketingOrder::STATUSES` 조사 확인됨 | 확정 |
| 노출 기간 | `marketing_order_items.work_date` ~ `end_date` (포함) | 조사 확인됨 | 확정 |
| 동일 미션 반복 상한 | `farm_missions.per_user_limit` (기본 1) | draft `daily_limit` 컬럼과 이름 충돌 주의 | **확인 필요** |

---

## 2. 시간 축 정의 — 모든 계산의 기준

### 2-1. 농장일 (farm day)

```
farmDay(now) = now.copy().subHours(6).toDateString()      // KST
```

| 실제 시각(KST) | farmDay |
|---|---|
| 2026-07-28 06:00 ~ 2026-07-29 05:59 | `2026-07-28` |
| 2026-07-29 01:30 | `2026-07-28` ← 자정을 넘겨도 전날 |

**적용 범위 — 두 축을 통일한다:**

| 대상 | 기존(draft) | 본 설계 |
|---|---|---|
| 사용자 하루 3회 상한 | `now()->toDateString()` (자정 기준) | **`farmDay()`** |
| `farm_planting_days.work_date` | 자정 기준 | **`farmDay()`** |
| 미션 수량 `stat_date` | — | **`farmDay()`** |
| 미션 노출 기간 판정 (`work_date`~`end_date`) | — | **`farmDay()`** |

**근거(자정 기준을 버리는 이유):**
- 23:50에 밭 3칸을 채우고 00:10에 다시 3칸을 채우면, **같은 밭이 20분 만에 2일치 성장**한다. `unique(farm_user_id, plot_index, work_date)`는 날짜가 바뀌므로 이를 막지 못한다. 7일 코스가 이론상 3.5일로 단축된다.
- 쿨다운 120분이 이를 완화하지만 완전히 막지는 못한다(23:50 참여 → 01:50 참여, 같은 밭 2일치).
- 06:00 리셋이면 리셋 순간에 사용자가 거의 없다(심야 휴지 직후). 리셋 몰림 자체가 사라진다.

> 🔴 **다른 영역과 맞물림:** draft §6-1/§6-3/§6-4의 `now()->toDateString()`을 전부 `FarmDay::current()`로 교체해야 한다. `app/Support/FarmDay.php` 헬퍼 1개로 통일.

### 2-2. 시간 구간 (slot)

| 코드 | 시각(KST) | 길이 | 가중치 | 성격 |
|---|---|---|---|---|
| `S1` | 06:00–09:00 | 3h | **8%** | 기상·출근 |
| `S2` | 09:00–12:00 | 3h | **13%** | 오전 |
| `S3` | 12:00–14:00 | 2h | **15%** | 점심 피크 |
| `S4` | 14:00–18:00 | 4h | **14%** | 오후 |
| `S5` | 18:00–20:00 | 2h | **14%** | 퇴근 |
| `S6` | 20:00–22:00 | 2h | **21%** | 최대 피크 |
| `S7` | 22:00–02:00 | 4h | **15%** | 마지막 구간 (전량 캐리 수용) |
| — | 02:00–06:00 | 4h | **0%** | **휴지 — 노출 중단** |

합계 100%. 캐리 체인: `S1 → S2 → S3 → S4 → S5 → S6 → S7 → (소멸)`.

`config('rankfree.farm.quota.slots')`에 배열로 두어 운영 중 조정 가능. 가중치 합이 100이 아니면 부팅 시 정규화(로그 경고).

**구간 개수를 7로 정한 근거(숫자):**

| 구간 수 | 한도 D=10 미션의 구간당 배정 | 한도 D=50 | 판정 |
|---|---|---|---|
| 24 (1시간) | 0.4 → floor 0 → **0건 구간 다수** | 2.1 | ❌ 소액 미션이 죽는다 |
| 12 (2시간) | 0.8 → 0 | 4.2 | ❌ 여전히 0 발생 |
| **7 (가중)** | 최소 1 보장 시 전 구간 참여 가능 | 4~11 | ✅ |
| 3 (8시간) | 3.3 | 16.7 | ❌ 분산 효과 미미(8시간 안에 다 소진 가능) |

---

## 3. 데이터 모델 (본 설계가 추가하는 것)

파일명 대역: draft가 `2026_07_28_1000xx`를 쓰므로 **`2026_07_28_1010xx`** 를 쓴다.
전부 `return new class extends Migration` 익명 클래스 + 상단 한글 docblock("퀴즈농장(30) — …"), 컬럼 줄 끝 한글 인라인 주석.

### 3-1. `2026_07_28_101000_create_farm_mission_slot_quotas_table.php`

**`farm_mission_slot_quotas`** — 🔴 **수량 한도의 유일한 원장.** 미션 × 농장일 × 구간 × 샤드.

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `id` | `id()` | — | |
| `mission_id` | `unsignedBigInteger` | — | **FK 없음** — 미션이 지워져도 소진 기록은 남는다 |
| `stat_date` | `date` | — | 농장일(§2-1) |
| `slot_code` | `string(4)` | — | `S1`~`S7` |
| `shard_no` | `unsignedTinyInteger` | 0 | 기본 0 (샤딩 미사용) |
| `alloc` | `unsignedInteger` | 0 | 이 구간 기본 배정량 |
| `carry_in` | `unsignedInteger` | 0 | 이전 구간에서 이관받은 미소진분 |
| `carried_out` | `unsignedInteger` | 0 | 다음 구간으로 넘긴 양 (감사용) |
| `used` | `unsignedInteger` | 0 | **확정 참여 수. 단조 증가, 감소 없음** |
| `held` | `unsignedInteger` | 0 | 유효 홀드 수 (Phase 2). 배치가 실제 COUNT로 덮어씀 |
| `closed` | `boolean` | false | 1이면 이 구간에 더 이상 확정 불가 |
| `opened_at` | `timestamp nullable` | null | 구간 시작 시각 |
| `closed_at` | `timestamp nullable` | null | 롤오버 배치가 닫은 시각 |
| `created_at`/`updated_at` | `timestamps()` | — | |

인덱스
- `unique(['mission_id','stat_date','slot_code','shard_no'], 'fmsq_uni')` ← **멱등 INSERT의 최종 방어선**
- `index(['stat_date','slot_code','closed'], 'fmsq_roll')` ← 롤오버 배치가 긁는다
- `index(['stat_date','mission_id'], 'fmsq_day')` ← 일 잔여 SUM

> **일 단위 카운터 테이블을 만들지 않는 이유:** `Σ alloc(i) = D_eff` 이고 `carry_in(i) ≤ unused(i-1)` 이므로 `Σ used ≤ D_eff` 가 수학적으로 보장된다(§6-3 증명). 테이블을 하나 더 두면 두 카운터가 어긋날 새 경로가 생길 뿐이다.

### 3-2. `2026_07_28_101100_create_farm_mission_holds_table.php` (Phase 2)

**`farm_mission_holds`** — 슬롯 선점. 힌트 링크를 연 사용자에게 자리를 예약해 준다.

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `id` | `id()` | — | |
| `farm_user_id` | `unsignedBigInteger` | — | FK 없음(로그성) |
| `mission_id` | `unsignedBigInteger` | — | |
| `stat_date` | `date` | — | |
| `slot_code` | `string(4)` | — | 홀드 발급 당시 구간 |
| `shard_no` | `unsignedTinyInteger` | 0 | |
| `status` | `string(10)` | `'held'` | `held` / `consumed` / `expired` / `released` |
| `expires_at` | `timestamp` | — | 발급 + `hold_ttl_minutes`(기본 10분) |
| `consumed_at` | `timestamp nullable` | null | |
| `timestamps` | | | |

인덱스
- `unique(['farm_user_id','mission_id','stat_date'], 'fmh_uni')` ← 같은 사람이 같은 미션에 홀드 2개 못 잡음
- `index(['status','expires_at'], 'fmh_expire')` ← 만료 회수 배치
- `index(['mission_id','stat_date','slot_code','status'], 'fmh_count')` ← `held` 재계산

### 3-3. `2026_07_28_101200_create_farm_quota_audits_table.php`

**`farm_quota_audits`** — 카운터 ↔ 로그 대조 결과. **초과 감지·정산의 근거.**

| 컬럼 | 타입 | 설명 |
|---|---|---|
| `id` | `id()` | |
| `mission_id` | `unsignedBigInteger` | |
| `stat_date` | `date` | |
| `slot_code` | `string(4) nullable` | null이면 일 단위 집계 |
| `counter_used` | `integer` | `farm_mission_slot_quotas.used` |
| `log_count` | `integer` | `COUNT(farm_mission_logs WHERE result='correct')` |
| `diff` | `integer` | `log_count − counter_used`. **양수 = 진짜 초과 = 금전 손해** |
| `limit_qty` | `integer` | 그날의 `D_eff` |
| `over_limit` | `integer` | `max(0, log_count − limit_qty)` ← **광고주에게 청구 못 하는 건수** |
| `severity` | `string(8)` | `ok` / `warn` / `alert` |
| `detected_at` | `timestamp` | |
| `timestamps` | | |

인덱스: `unique(['mission_id','stat_date','slot_code'], 'fqa_uni')`, `index(['severity','detected_at'], 'fqa_sev')`

### 3-4. `2026_07_28_101300_add_cooldown_to_farm_users.php`

**`farm_users` 컬럼 추가**

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `last_participated_at` | `timestamp nullable` | null | 마지막 **정답 확정** 시각 |
| `cooldown_until` | `timestamp nullable` | null | 이 시각 이전에는 참여 불가 |
| `today_count` | `unsignedTinyInteger` | 0 | 농장일 참여 수 캐시 (판정은 로그) |
| `today_date` | `date nullable` | null | `today_count`가 가리키는 농장일 |
| `daily_ip` | `string(45) nullable` | null | 마지막 참여 IP (어뷰징 클러스터 탐지용) |

인덱스: `index(['cooldown_until'], 'fu_cooldown')` ← 어드민 통계용. 판정은 PK 조회라 인덱스 불필요.

> `today_count`/`today_date`를 두는 이유: `GET /missions`에서 "오늘 몇 번 했나"를 알기 위해 `farm_planting_days`를 COUNT 하면 요청당 쿼리 1회가 추가된다(600만/일). 인증 단계에서 이미 로드한 `farm_users` 행에 담아두면 **쿼리 0**. `today_date != farmDay()`면 0으로 간주(리셋 배치 불필요).

### 3-5. `2026_07_28_101400_add_quota_columns_to_farm_missions.php`

**`farm_missions` 컬럼 추가** (design-01과 조율 필요)

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `order_item_id` | `unsignedBigInteger nullable` | null | `marketing_order_items.id`. **FK 없음** |
| `daily_limit_qty` | `unsignedInteger` | 0 | 일 주문횟수. 0 = 무제한(내부 테스트 미션만) |
| `total_limit_qty` | `unsignedInteger` | 0 | 전체 수량. 0 = 무제한 |
| `total_used` | `unsignedInteger` | 0 | 누적 확정 수 (일 마감 배치가 갱신) |
| `per_user_limit` | `unsignedSmallInteger` | 1 | 동일 사용자가 이 미션에 참여 가능한 **총** 횟수 |
| `per_user_daily_limit` | `unsignedTinyInteger` | 1 | 동일 사용자 1일 한도 |
| `shard_count` | `unsignedTinyInteger` | 1 | 1이면 샤딩 없음 |
| `exposure_weight` | `unsignedSmallInteger` | 100 | 노출 순위 가중(운영 조정용) |

인덱스: `index(['order_item_id'], 'fm_item')`, `unique` 걸지 않음(1 세부주문서 = 1 미션이지만 재생성 여지를 남긴다)

---

## 4. 한도 초과 방지 🔴

### 4-1. 다단 방어 — 5층

| 층 | 위치 | 무엇을 막나 | 정확성 기여 | 비용 |
|---|---|---|---|---|
| **L0 노출 필터** | `GET /missions` | 잔여 0인 미션을 목록에서 제외. 잔여 적은 미션은 순위 하향 | ❌ 없음 (캐시 사본 기반) | 캐시 조회 1회 |
| **L1 선점(홀드)** | `GET /api/farm/go/{mission}` (Phase 2) | 힌트 링크를 연 사용자의 자리를 10분 예약 | ❌ 없음 (UX 장치) | UPDATE 1회 |
| **L2 확정 UPDATE** | `POST /submit` 트랜잭션 1행 | 🔴 **한도 초과 그 자체** | ✅ **100%** | UPDATE 1회 |
| **L3 DB unique** | `farm_planting_days` unique 2개 | 같은 사용자·같은 밭·같은 날 중복 | ✅ 사용자 측 | INSERT 시 |
| **L4 사후 감사** | `farm:audit-quota` 매시 | 카운터 ↔ 로그 드리프트, 청구 불가 건수 산출 | 🔎 감지·정산 | 배치 |

**핵심: 정확성은 오직 L2가 담당한다.** L0/L1은 UX(헛수고 감소)와 부하 완화 장치이며, 이들이 틀려도 초과는 발생하지 않는다. 반대로 **L0/L1을 정확성 근거로 쓰는 순간 캐시가 원장이 되어 사고가 난다**(§4-5의 비교 숫자).

### 4-2. 원자성 수단 — 선택과 근거

| 수단 | 락 보유 시간 | 원장 가능? | 소실 시 복원 | 판정 |
|---|---|---|---|---|
| Redis `DECR` | ~0 | ❌ | ❌ **불가** | ❌ **탈락.** 카페24 Redis 다운 시 "오늘 몇 건 나갔는지"를 복원할 방법이 없다. Redis↔MySQL 2PC도 없다 |
| `SELECT … FOR UPDATE` + `UPDATE` | **~1.0ms**<br/>(SELECT 0.15 + 앱 RTT 0.3×2 + 판정 + UPDATE 0.15) | ✅ | ✅ | △ rankfree 선례 있음(`CouponController`)이나 그건 **초당 1건 미만** 경로 |
| **조건부 atomic UPDATE** | **~0.15ms** | ✅ | ✅ | ✅ **채택** |

**채택 SQL (L2)**

```sql
-- 홀드 없는 일반 경로: 홀드 보유자의 자리를 침범하지 않는다
UPDATE farm_mission_slot_quotas
   SET used = used + 1, updated_at = ?
 WHERE mission_id = ? AND stat_date = ? AND slot_code = ? AND shard_no = ?
   AND closed = 0
   AND used + held < alloc + carry_in;
-- affected_rows = 1 → 슬롯 확보. 0 → 마감

-- 홀드 보유자 경로 (Phase 2): 자기 홀드를 소비하므로 held 를 침범해도 됨
UPDATE farm_mission_slot_quotas
   SET used = used + 1, held = GREATEST(held - 1, 0), updated_at = ?
 WHERE mission_id = ? AND stat_date = ? AND slot_code = ? AND shard_no = ?
   AND closed = 0
   AND used < alloc + carry_in;
```

**왜 `FOR UPDATE`보다 6배 빠른가 (숫자):**
인기 미션 단일 행의 처리 상한 = `1 / 락보유시간`.
- `FOR UPDATE`: 1/1.0ms = **1,000 QPS 이론 상한**, 안전마진 5배 적용 시 실용 200 QPS
- 조건부 UPDATE: 1/0.15ms = **6,600 QPS 이론 상한**, 실용 1,300 QPS

피크 시나리오에서 인기 미션 1건이 받는 부하는 4.6 QPS(§9)이므로 둘 다 충분하지만, **트래픽이 10배가 되어도 스키마를 안 바꿔도 되는 쪽**을 고른다.

**추가 근거 — rankfree 선례와의 정합:**
- ✅ 따를 것: `AiCrawlerHit::record()` — `upsert(초기값 0)` → `update(['hits' => DB::raw('hits + 1')])`. 존재 보장과 증가를 분리하는 이 패턴을 그대로 쓴다.
- ❌ 베끼지 말 것: `User::tryConsumeUsage()`(app/Models/User.php:109-128)와 `AuthenticateApiKey` — `firstOrCreate` → `if (count >= limit)` → `increment()`. **check-then-act라 동시 요청에 한도를 넘긴다.** 조사 문서에 "버그성 레이스"로 명시돼 있다. 이 코드를 복사하는 순간 우리 설계의 초과율 0%가 무너진다.

### 4-3. 확정 트랜잭션 — 락 순서와 범위

```
DB::transaction(function () {
    // 락 순서 고정 (전 경로 동일 — 이 순서를 어기면 데드락)
    //   ① farm_users        (사용자별, 경합 거의 없음)
    //   ② farm_mission_slot_quotas (미션별, 경합 있음)  ← 짧게 잡으려고 뒤에 둔다
    //   ③ farm_mission_holds

    ① FarmUser::whereKey($u->id)->lockForUpdate()->first();
    ② UPDATE farm_mission_slot_quotas ...  (조건부, affected 판정)
       affected = 0 → 구간 재계산 후 1회 재시도 → 그래도 0이면 rejected 반환(롤백 아님)
    ③ INSERT farm_mission_logs      (result='correct')
    ④ INSERT farm_planting_days     (unique 2개가 최종 방어)
    ⑤ UPDATE farm_plantings         (completed_days, status)
    ⑥ UPDATE farm_users             (correct_count, today_count, today_date,
                                     last_participated_at, cooldown_until, daily_ip)
    ⑦ INSERT farm_point_ledgers     (참여 포인트 있을 때만)
    ⑧ UPDATE farm_mission_holds     (status='consumed')  — Phase 2
});
// 커밋 후: 캐시 DEL, 지급 Job dispatch
```

**🔴 카운터 UPDATE를 트랜잭션 안에 넣는 이유 (D3):**
밖에 두면 "카운터는 +1 됐는데 로그 INSERT가 실패"하는 창이 생긴다. 그 건은 **광고주에게 청구할 근거(로그)가 없으면서 한도만 먹는다** → 미달. 반대 방향(로그는 있는데 카운터가 없음)은 초과. 같은 트랜잭션이면 둘 다 0.

**락 보유 시간 측정 목표:** 트랜잭션 전체 P95 ≤ 5ms. 초과하면 ⑦(원장 INSERT)을 커밋 후 별도 트랜잭션으로 분리한다(중복 방지는 `unique(source, source_id)`가 이미 담당).

**트랜잭션 안에서 절대 하지 말 것:**
- 외부 HTTP 호출(토스 API, 잔디 웹훅)
- `Job::dispatch()` — 롤백 시 유령 Job 발생 (draft §6-5 주석과 동일)
- `Cache::` 호출 — DB 캐시 드라이버면 같은 커넥션에 쓰기가 끼어든다

### 4-4. 선점(홀드) — Phase 2

**왜 필요한가 (숫자):** 홀드 없이 제출 원샷이면, 각 미션이 마감되는 순간에 "정답을 맞혔는데 슬롯 없음"이 발생한다.
- 미션 1,000개 × 구간 7개 × 마감 순간 동시 경쟁자 3명 = **21,000건/일 ≈ 참여의 2.1%**
- 외부확인형 미션은 링크 왕복에 30초~5분이 걸린다. 5분 고생 후 "마감됐어요"는 최악의 UX다.
- 홀드를 넣으면 이 2.1%가 **0.2%**로 줄어든다(홀드 보유자는 자리 보장).

**클라이언트 수정 없이 선점하는 방법 — 힌트 링크를 서버 경유로 만든다**

클라이언트 API는 `GET /missions`, `POST /missions/:id/submit`으로 고정돼 있어 `/start` 같은 엔드포인트를 추가할 수 없다. 대신 목록 응답의 `quiz.hintUrl`을 서버 경유 링크로 치환한다.

```
hintUrl = https://rankfree.kr/api/farm/go/{missionId}?t={token}
```

rankfree에 **동일 선례가 있다**: `ShopKeywordExposureController::short()` — 짧은 URL 리다이렉트 + 카운터 증가. 구조를 그대로 따른다.

**토큰 형식 (무상태 서명)**

```
t = {userKeyHashPrefix12}.{missionId}.{expUnixTs}.{hmac16}
    hmac16 = substr(hash_hmac('sha256', "{prefix}|{missionId}|{exp}", APP_KEY), 0, 16)
```

- `openURL()`은 외부 브라우저를 열어 **`x-user-key` 헤더를 실을 수 없다.** 그래서 사용자 식별을 토큰에 담는다.
- 내부 PK(`farm_users.id`)를 노출하지 않으려고 `user_key_hash` 앞 12자를 쓴다. 조회는 `where('user_key_hash','like',"{$prefix}%")` — unique 인덱스 prefix range scan이라 빠르다. 충돌 확률 = 40만 사용자 / 16^12 ≈ 1.4e-9.
- 유효 10분. 토큰을 훔쳐도 홀드가 **원 소유자에게** 귀속되므로 탈취 이득이 없다.

**`GET /api/farm/go/{mission}` 처리 (auth.farm 미들웨어 **밖**, 서명 검증 전용)**

```
1. 토큰 파싱·HMAC 검증·exp 확인
   실패 → 302 to farm_missions.hint_url (홀드 없이 통과. 링크는 살려둔다)
2. farm_users 조회 (prefix), blocked 이면 302 to 미니앱 홈
3. 홀드 발급 (조건부 UPDATE)
   UPDATE farm_mission_slot_quotas
      SET held = held + 1
    WHERE mission,date,slot,shard AND closed = 0
      AND used + held < alloc + carry_in
   affected = 1 → INSERT farm_mission_holds (unique 충돌이면 expires_at 만 연장)
   affected = 0 → 홀드 없이 진행
4. 302 Location: farm_missions.hint_url
   Cache-Control: no-store   ← 브라우저·CDN 캐시 금지(재클릭이 재선점이어야 함)
```

**홀드는 "소프트 홀드"다.** `held`는 신규 홀드 발급과 노출 필터에만 쓰이고, **확정 한도는 언제나 `used`가 결정한다**(§4-2 SQL). 홀드가 만료되거나 배치가 늦어도 초과는 발생하지 않는다.

**홀드 낭비 추정:** 외부확인형 링크 클릭 후 미제출(이탈) 비율 40% 가정. 홀드 TTL 10분이면 각 미션의 유효 한도가 순간적으로 최대 40%까지 묶인다. 이를 상쇄하려고 만료 회수 배치를 **매분** 돌린다(§10). 회수 시 `held`를 증감이 아니라 **실제 COUNT로 덮어써** 드리프트를 매분 0으로 리셋한다.

```sql
UPDATE farm_mission_slot_quotas q
   SET q.held = (SELECT COUNT(*) FROM farm_mission_holds h
                  WHERE h.mission_id = q.mission_id AND h.stat_date = q.stat_date
                    AND h.slot_code = q.slot_code AND h.shard_no = q.shard_no
                    AND h.status = 'held' AND h.expires_at > NOW())
 WHERE q.stat_date = ? AND q.closed = 0;
```

### 4-5. 초과율 추정 🔴

**초과가 발생할 수 있는 경로를 전수 열거하고 각각 봉쇄 여부를 판정한다.**

| 경로 | 발생 조건 | 봉쇄 수단 | 예상 빈도/일 | 금전 영향 |
|---|---|---|---|---|
| A. 동시 요청 경합 | 두 요청이 같은 잔여값을 보고 둘 다 통과 | 조건부 UPDATE의 `AND used < limit` | **0** | 0원 |
| B. 구간 경계 in-flight | 롤오버 직전 시작된 트랜잭션이 직후 커밋 | `closed = 0` 조건 → affected 0 → 현재 구간 재시도 | **0** | 0원 |
| C. 캐리 과대 계산 | 이전 구간 `used`를 읽는 순간 in-flight 존재 | 롤오버가 이전 구간을 `closed=1`로 **먼저** 닫고 계산 | **0** | 0원 |
| D. 클라 재시도 중복 | 커밋 후 응답 유실 → 사용자가 재제출 | `unique(farm_user_id, plot_index, work_date)` + 같은 트랜잭션 롤백 | 응답 유실 0.1% × 100만 = 1,000회 시도 → **확정 0건** | 0원 |
| E. 배치 중복 실행 | `farm:plan-quota`가 2번 돌아 alloc 2배 | `unique(mission,date,slot,shard)` + `insertOrIgnore` | **0** | 0원 |
| F. 배치 미실행 | 크론 죽음 → `carry_in` 0 유지 | Fail-safe 방향(미달만 발생) | — | 0원 (미달) |
| G. 샤드 배분 오차 | 샤드 A 소진·B 여유 | 기본 1샤드. 샤딩 시 잔여를 샤드 합으로 판정 | 샤딩 미사용이면 **0** | 0원 |
| H. 운영자 수동 조작 | 어드민에서 `alloc` 직접 편집 | 감사 로그 + `farm_quota_audits` | 운영 이슈 | 추적 가능 |
| I. DB 롤백/복제 지연 | 단일 마스터·복제 없음 | 해당 없음 | **0** | 0원 |

**종합 예상 초과율: 0.00%**
**감사 허용 임계: 0.01% (하루 100건).** 이를 넘으면 구조적 버그로 간주하고 즉시 조사.

**대조군 — 캐시(Redis)로 판정했다면 얼마나 초과하나:**

```
인기 미션 1건의 피크 부하 = 4.6 QPS  (§9)
Redis 잔여값 TTL = 3초
→ 한도 근처에서 같은 잔여값을 보는 요청 수 = 4.6 × 3 = 13.8건
→ 미션당 일 초과 ≈ 14건 (마감 순간 1회 발생 가정)
→ 활성 미션 1,000개 × 14건 = 14,000건/일
→ 광고주 단가 150원 기준 (marketing_products.min_price, '네이버 쇼핑 퀴즈' 실측값)
   14,000 × 150 = 2,100,000원/일 = 월 6,300만원 손해
```

이 숫자가 **D1·D3(조건부 UPDATE + 동일 트랜잭션)을 고른 이유 전부**다.

**미달률 추정 (초과의 반대편, 이쪽이 실제 리스크다):**

| 요인 | 미달 기여 |
|---|---|
| 심야 휴지 4시간(02–06) | 정상 트래픽의 1% 미만 → **1%** |
| 구간 캐리 캡(1단계·자기 배정량 이내) | 구간별 소화 실패분이 2구간 뒤로 못 감 → **3~5%** |
| 쿨다운 2시간으로 인한 참여 총량 감소 | DAU당 3회 → 2.53회 (§5-3) → **미션 수요 자체가 16% 감소** |
| 마지막 구간 S7 소화력 부족 | S6 참여자가 쿨다운 중 → **2~4%** |
| **합계 (수요 감소 제외)** | **6~10%**, 안전하게 **8~12%** |

운영 KPI: **미달률 5% 이하.** 초과 시 대응 순서 = ① S7 가중치 상향(15%→18%) ② `carry_cap_ratio` 1.0→1.5 ③ 쿨다운 120→90분(§5-3 참조) ④ 심야 휴지 축소(02–06 → 03–06).

### 4-6. 초과 감지 · 정산 · 보고

**감지 — `farm:audit-quota` (매시 10분)**

```
대상: stat_date = 오늘 또는 어제 인 farm_mission_slot_quotas 행 전부
for each (mission, date, slot):
    counter = q.used
    logs    = COUNT(farm_mission_logs
                    WHERE mission_id = ? AND result = 'correct'
                      AND created_at BETWEEN slot_start AND slot_end)
    diff    = logs - counter
    severity = diff == 0 ? 'ok' : (abs(diff) <= 2 ? 'warn' : 'alert')
    upsert farm_quota_audits(...)                      -- unique(mission,date,slot) 로 멱등

일 단위 행(slot_code = NULL) 도 함께 기록:
    log_count_day, D_eff, over_limit = max(0, log_count_day - D_eff)
```

| 부호 | 의미 | 금전 영향 | 조치 |
|---|---|---|---|
| `diff = 0` | 정상 | — | — |
| `diff < 0` (카운터 > 로그) | 슬롯은 먹었는데 로그가 없음 | **없음** (청구는 로그 기준) | 미달 요인으로 기록 |
| `diff > 0` (로그 > 카운터) | 🔴 **진짜 초과** | 청구 못 함 = 손해 | **즉시 잔디 알림** + 구현 버그 조사 |
| `over_limit > 0` | 일 한도 초과 확정 | `over_limit × 단가` 손해 | 정산에서 차감 대상으로 태깅 |

**정산 기준 — 절대 규칙**
- **광고주 청구 건수 = `farm_mission_logs`(result='correct') COUNT.** 카운터(`used`)가 아니다.
- **청구 가능 건수 = `min(log_count, D_eff)`.** 초과분 `over_limit`은 청구하지 않는다(계약 위반).
- **사용자 지급 포인트 = `farm_point_ledgers` 합계.** 초과분에도 포인트는 이미 나갔으므로 `over_limit × (단가 − 지급포인트)`가 아니라 **`over_limit × 지급포인트`가 순손실**이다.
- 이 세 값을 `farm_quota_audits` + 일별 집계(`farm_mission_stats`, design-03 영역)에 남긴다.

**보고**
- `severity='alert'`가 1건이라도 나오면 `SendJandiOrderNotification` 패턴을 복사한 `SendJandiFarmAlert` Job으로 즉시 알림.
- 어드민 화면 `admin.farm-audits` — 날짜·미션·severity 필터, `over_limit` 합계 상단 KPI.

### 4-7. 샤딩 — 조건부, 기본 OFF

**임계값**

| 조건 | 샤드 수 |
|---|---|
| 기본 | **1** (샤딩 없음) |
| 미션 1건 순간 ≥ **50 QPS** 관측 | 4 |
| 미션 일 한도 ≥ **10,000** | 4 |
| 미션 일 한도 ≥ **100,000** | 16 |

**50 QPS를 임계로 잡은 근거:** 단일 행 조건부 UPDATE의 실용 상한은 그룹 커밋 특성상 대략 1,300 QPS(§4-2). 안전 마진을 **26배**로 두면 50 QPS. rankfree DB는 crm과 공존하는 공용 서버이므로 마진을 크게 잡는다.

**샤드 배분 규칙 (켤 때)**
```
shard_no  = crc32(user_key_hash) % shard_count
alloc(shard) = floor(alloc_slot / shard_count), 잔여는 shard_no 0부터 1씩
잔여 조회 = SUM over shards        ← 노출 필터는 합계로 본다
확정      = 자기 샤드 행에만 UPDATE
```

**🔴 샤딩이 만드는 새 초과 경로:** 샤드 A는 소진, B는 여유인데 사용자 해시가 A로만 몰리면 **미달**이 생긴다(초과는 아님). 이를 보정하려고 "A 실패 시 B 재시도"를 넣으면 순회 중 초과가 발생한다. **재시도를 넣지 않는다.** 미달은 롤오버 배치가 다음 구간 캐리로 흡수한다.

---

## 5. 사용자 쿨다운 (2시간) ⚠️

### 5-1. 저장 위치와 판정 방법

**저장: `farm_users.cooldown_until` (DB 컬럼 단독). 캐시하지 않는다.**

| 후보 | 판정 |
|---|---|
| Redis TTL 키만 | ❌ Redis 소실 시 **전원 쿨다운 해제**. 하루 3회가 즉시 4시간에 몰려 광고 노출이 붕괴. 원장 원칙 위반 |
| Redis + DB 이중 | ❌ 두 값이 어긋날 경로가 생기는데, 얻는 게 없다(아래) |
| **DB 컬럼 단독** | ✅ **채택** |

**채택 근거 (결정적):** `auth.farm` 미들웨어가 이미 **매 요청** `FarmUser::findOrCreateByKey()`로 `farm_users` 행을 로드한다(draft §5-2). `cooldown_until`은 그 행에 이미 실려 온다 → **추가 쿼리 0, 추가 캐시 조회 0.** 캐시할 이유가 애초에 없다.

쓰기 부하도 무시 가능: 참여 확정 시에만 UPDATE = 100만/일 = **12 write/s**.

**판정 위치: 미들웨어가 아니라 서비스 계층.**
미들웨어에서 막으면 `GET /me/state`(농장 화면)까지 막혀 앱이 먹통이 된다.

| 엔드포인트 | 쿨다운 처리 |
|---|---|
| `GET /me/state` | 영향 없음. 응답에 `cooldownUntil` 추가만 |
| `GET /missions` | 목록은 주되 전부 잠금 표시 + `meta.cooldownUntil` (§5-2) |
| `POST /missions/:id/submit` | `200 {correct:false, message:'다음 미션은 21:30에 열려요.'}` + `farm_mission_logs(result='rejected', reject_reason='cooldown')` |
| `POST /harvest` | **영향 없음.** 수확은 쿨다운과 무관 (7일 완주 보상이지 참여가 아니다) |

> `FarmMissionLog::REJECT_REASONS`에 `'cooldown' => '쿨다운 중'`, `'quota_full' => '미션 수량 마감'`, `'closed' => '휴지 시간'`, `'ip_limit' => 'IP 일 상한'` 4개를 추가한다(draft §3-6 상수 확장).

### 5-2. 쿨다운 값 — 120분 ± 10분 지터

```
cooldown_until = now()
               + config('rankfree.farm.cooldown_minutes', 120) 분
               + random_int(-10, +10) 분
```

**지터를 넣는 이유 3가지:**
1. **구간 경계 몰림 방지.** 정확히 120분이면 20:00에 참여한 사용자 전원이 22:00 정각(S6→S7 경계)에 동시 복귀한다. ±10분이면 20분 창에 분산된다.
2. **자동화 방어.** 정확한 주기는 스크립트가 알람으로 잡기 쉽다. 매번 달라지면 폴링 비용이 든다(= 폴링이 늘면 IP 상한에 걸린다).
3. **사용자 체감 무영향.** 클라이언트는 서버가 준 절대 시각만 표시한다.

**절대 시각으로 저장하므로 판정은 `cooldown_until > now()` 단순 비교.** 남은 시간 계산도 뺄셈 1회.

### 5-3. 쿨다운 중 목록 노출 방식 — 선택과 근거

**선택: 미션 목록은 그대로 내려주되 전부 `completed: true`로 표시하고, `meta.cooldownUntil`을 함께 준다. 개인화 계산은 전부 스킵하고 공용 캐시(C1)를 그대로 반환한다.**

| 후보 | UX | 서버 부하 | 판정 |
|---|---|---|---|
| A. 빈 목록 반환 | ❌ "할 게 없네" → 앱 종료. 재방문 유인 소멸 | ✅ 최소 | ❌ |
| B. 목록 + 각 미션에 `locked` 신규 필드 | ✅ 최선 | ✅ 개인화 스킵 가능 | △ **클라 수정 필요** |
| **C. 목록 + 전부 `completed:true` + `meta.cooldownUntil`** | ✅ 클라가 이미 잠금 UI를 그림 | ✅ 개인화 스킵 | ✅ **채택 (클라 수정 0)** |

**UX 근거:** 숨기면 사용자는 "미션이 없다"고 해석해 이탈한다. 보이되 잠긴 상태 + 남은 시간을 보여주면 재방문 유인이 된다. 토스 정책상 "나갈 선택지가 없는 구조"는 다크패턴이지만, **카운트다운은 정보 제공이지 강제가 아니므로** 문제없다(`docs/guide/03-policy-and-review.md` 다크패턴 항목).

**부하 근거 (숫자):**
```
쿨다운 중 비율 = (하루 3회 × 2시간 쿨다운) / 활동 20시간 = 6/20 = 30%
쿨다운 중 사용자는 "언제 열리나" 확인하려고 목록을 더 자주 새로고침 → 가중 1.4배
→ 전체 GET /missions 요청의 약 40%가 쿨다운 상태 요청

이 40%를 개인화 없이 공용 캐시로 처리하면:
  - 사용자×미션 누적 조회(C4)     : 240만 회/일 절약
  - 구간 잔여 조회(C2 × 후보 60개): 1.4억 회/일 절약
  - DB 폴백 쿼리                  : 캐시 미스 5% 기준 12만 쿼리/일 절약
→ 응답 시간 25ms → 3ms, DB 쿼리 0
```

**추가 규칙:** 쿨다운 중 응답에는 `Cache-Control: private, max-age=30`을 붙인다. 클라이언트가 초 단위로 폴링해도 30초는 로컬 캐시가 받아낸다.

**단, `meta.cooldownUntil`은 클라이언트가 무시해도 깨지지 않는다.** 표시하려면 1곳 수정. 우선순위 낮음.

### 5-4. 하루 참여 분포 시뮬레이션

**전제:** 밭 3칸, 쿨다운 120±10분, 농장일 06:00–02:00 (활동 20시간, 02–06 휴지).

**한 사용자의 이론적 최단 완주:**
```
1회차 08:00 → 2회차 10:00 → 3회차 12:00        (총 4시간)
```

**실제 분포 — 첫 참여 시각별 완주 가능 횟수**

3회를 채우려면 첫 참여가 **마감(02:00) − 4시간 = 22:00** 이전이어야 한다. 다만 첫 참여가 늦을수록 세션 유지가 어렵다.

| 첫 참여 시각 | 이론 완주 | 실측 가정 (세션 이탈 반영) | 유입 비중 |
|---|---|---|---|
| 06–09 (S1) | 3회 | 2.9회 | 8% |
| 09–12 (S2) | 3회 | 2.9회 | 13% |
| 12–14 (S3) | 3회 | 2.8회 | 15% |
| 14–18 (S4) | 3회 | 2.7회 | 14% |
| 18–20 (S5) | 3회 | 2.5회 | 14% |
| 20–22 (S6) | 3회 (22:00 → 00:00 → 02:00, 마감 경계) | 2.1회 | 21% |
| 22–02 (S7) | 1~2회 | 1.3회 | 15% |

```
가중 평균 = 0.08×2.9 + 0.13×2.9 + 0.15×2.8 + 0.14×2.7 + 0.14×2.5 + 0.21×2.1 + 0.15×1.3
          = 0.232 + 0.377 + 0.420 + 0.378 + 0.350 + 0.441 + 0.195
          = 2.39 회/DAU
```

| 시나리오 | 평균 참여/DAU | 100만 건에 필요한 DAU |
|---|---|---|
| 쿨다운 없음 (밭 3칸 제약만) | 2.85 | **35.1만** |
| **쿨다운 120분 (채택)** | **2.39** | **41.8만** |
| 쿨다운 90분 | 2.62 | 38.2만 |
| 쿨다운 60분 | 2.76 | 36.2만 |

🔴 **사업 수치: 쿨다운 120분은 DAU당 참여를 16% 줄인다. 하루 100만 건 목표라면 DAU가 35만 → 42만으로 늘어야 한다.**

**시간대별 참여 분포 (쿨다운 반영 후, 100만 건 기준)**

| 구간 | 배분 목표(가중치) | 쿨다운 반영 실제 예상 | 편차 |
|---|---|---|---|
| S1 06–09 | 80,000 (8%) | 78,000 | −2.5% |
| S2 09–12 | 130,000 (13%) | 136,000 | +4.6% |
| S3 12–14 | 150,000 (15%) | 152,000 | +1.3% |
| S4 14–18 | 140,000 (14%) | 148,000 | +5.7% |
| S5 18–20 | 140,000 (14%) | 138,000 | −1.4% |
| S6 20–22 | 210,000 (21%) | 196,000 | −6.7% |
| S7 22–02 | 150,000 (15%) | 128,000 | **−14.7%** ← 미달 집중 |

**해석:** 쿨다운은 앞 구간의 참여를 뒤 구간으로 밀어내지만, 마지막 구간 S7은 밀어낼 곳이 없어 미달이 집중된다. S6(20–22) 참여자는 쿨다운이 22:00–00:00에 걸려 S7 전반부에 못 온다.

**대응:** S7이 전량 캐리를 수용하므로 배정량은 커지지만 소화 주체가 없다. 따라서 **S7 가중치를 15%로 낮게 잡고 캐리로 채우는 현재 설계가 옳다.** 그럼에도 미달이 5%를 넘으면 쿨다운을 90분으로 낮추는 게 가장 효과가 크다(S7 미달 −14.7% → −8% 추정).

**설정으로 뺀다:** `config('rankfree.farm.cooldown_minutes')` — 운영 중 A/B 조정 가능. `app_settings` 오버라이드 대상(draft §8-7 패턴).

### 5-5. 쿨다운 우회 시도 방어

| 우회 수법 | 방어 | 유효성 |
|---|---|---|
| **키 재발급** (앱 재설치) | `getAnonymousKey()`는 재설치·기기 변경에도 **안정적인 사용자 해시**를 반환(토스 SDK 특성) | ✅ 근본 차단 |
| **키 위조** | `TossPromotion::verifyAnonKey()` mTLS 검증. 결과 6시간 캐시(C8)로 3,000 QPM 한도 절약 | ✅ (draft §5-2) |
| **여러 기기 / 여러 토스 계정** | ❌ 계정 단위 쿨다운은 우회된다 | ⚠️ 아래 경제적 방어로 대응 |
| **여러 계정 대량 생성** | ① IP 단위 일 참여 상한 ② 신규 계정 클러스터 탐지 ③ 경제적 상한 | △ 완화 |

**🔴 계정을 갈아타면 쿨다운은 우회된다. 이를 IP 단위 쿨다운으로 막지 않는다.**
근거: 통신사 NAT·회사 네트워크·공유기 환경에서 정상 사용자 대량 오탐. rankfree는 `TrustProxies` 미설정 + Cloudflare DNS-only라 실제 클라이언트 IP 신뢰도도 낮다(조사 확인).

**대신 3층으로 완화한다:**

**① IP 단위 일 참여 상한 (극단만 차단)**
```
config('rankfree.farm.ip_daily_limit', 30)
판정: COUNT(farm_mission_logs WHERE ip = ? AND result='correct' AND created_at >= farmDayStart)
       ≥ 30 → reject('ip_limit')
캐시: farm:ipd:{ipHash}:{farmDay}, L2 TTL 300s, 확정 시 DEL
```
근거 숫자: 1가구 최대 5인 × 3회 = 15건, 안전 마진 2배 = **30건**. 회사 공용 IP는 오탐 가능 → `farm_ip_allowlist` 없이 시작하되, alert 로그로 오탐 사례를 모아 사후 조정.

**② 신규 계정 클러스터 탐지 (배치, 자동 차단 안 함)**
```
farm:detect-abuse (매시)
동일 /24 대역에서 1시간 내 신규 farm_users ≥ 20건  → farm_quota_audits 와 별개로
                                                     admin.farm-users 화면에 플래그
※ 자동 blocked 처리하지 않는다 — 통신사 NAT 오탐 시 정상 사용자 대량 차단 사고가 난다
```

**③ 경제적 상한 — 실질적으로 가장 강한 방어**

| 장치 | 값 | 어뷰저 관점 |
|---|---|---|
| 참여 포인트 | 0 또는 소액 | 즉시 수익 거의 없음 |
| 포인트 지급 시점 | **수확(7일 완주) 시** | 계정 1개당 **7일 × 3회 = 21회 참여**를 해야 첫 수익 |
| 1인 누적 상한 | 5,000P | 계정 1개의 상한이 명확 |
| 쿨다운 | 120분 | 계정 1개당 하루 4시간 이상 구속 |

계정 100개를 만들어도 각각 7일간 하루 3회, 2시간 간격으로 돌려야 한다 = **인당 2,100회 참여 × 4시간/일 × 7일**. 자동화해도 IP 상한·mTLS 키 검증에 걸린다.

> 🔴 **설계상 가장 중요한 어뷰징 방어는 "포인트를 수확 시점에 몰아둔 것"이다.** 참여 즉시 포인트를 주는 정책으로 바꾸면 이 방어가 통째로 사라진다. 정책 변경 시 반드시 재검토.

---

## 6. 미션별 시간대 분산 ⚠️

### 6-1. 배분 알고리즘

**입력:** `D_eff` (그날 그 미션의 유효 일 한도), 구간 가중치 `w(i)` (§2-2)

```
function allocate(D_eff, slots):
    n = count(slots)                                    # 7

    # ── 1단계: 소액 미션 보호 ─────────────────────────
    if D_eff < n:
        # 가중치 상위 D_eff 개 구간에만 1씩
        return top_by_weight(slots, D_eff) → each 1, rest 0

    if D_eff < n * 2:                                   # D_eff < 14
        # 전 구간 최소 1 보장 후 잔여를 가중치 순으로
        base   = [1] * n
        remain = D_eff - n
        distribute(remain, by=weight desc)
        return base + distributed

    # ── 2단계: 가중 배분 (D_eff >= 14) ────────────────
    alloc = [ floor(D_eff * w(i) / 100) for i in slots ]
    alloc = [ max(1, a) for a in alloc ]                # 최소 1 보장

    total = sum(alloc)
    if total > D_eff:
        # 최소 1 보장으로 넘쳤으면 가중치 낮은 구간부터 1씩 회수 (단, 0으로는 안 내림)
        reclaim(total - D_eff, by=weight asc, floor=1)
    elif total < D_eff:
        # 잔여는 가중치 높은 구간부터 1씩
        distribute(D_eff - total, by=weight desc)

    assert sum(alloc) == D_eff                          # 🔴 이 불변식이 초과 방지의 근거
    return alloc
```

**`assert sum(alloc) == D_eff`가 전부다.** 이 등식이 깨지면 §6-3의 보장이 무너진다. 배치에서 검증 후 불일치 시 `Log::error` + 해당 미션 전 구간 `alloc=0`(안전 정지).

**배분 예시**

| D_eff | S1(8%) | S2(13%) | S3(15%) | S4(14%) | S5(14%) | S6(21%) | S7(15%) | 합 |
|---|---|---|---|---|---|---|---|---|
| 50 | 4 | 6 | 7 | 7 | 7 | 12 | 7 | **50** |
| 500 | 40 | 65 | 75 | 70 | 70 | 105 | 75 | **500** |
| 20 | 1 | 2 | 3 | 2 | 2 | 6 | 4 | **20** |
| 10 | 1 | 1 | 1 | 1 | 1 | 3 | 2 | **10** |
| 5 | 0 | 1 | 1 | 0 | 0 | 2 | 1 | **5** |
| 3 | 0 | 0 | 1 | 0 | 0 | 1 | 1 | **3** |

### 6-2. `D_eff` — 전체 수량을 일 한도에 흡수한다

```
D_eff = min(
    farm_missions.daily_limit_qty,                                    # 일 주문횟수
    farm_missions.total_limit_qty - farm_missions.total_used,         # 전체 수량 잔여
    remaining_days > 0 ? ceil(전체잔여 / remaining_days) : 전체잔여    # 남은 일수로 평활
)
remaining_days = (marketing_order_items.end_date - farmDay) + 1
```

**왜 전체 수량을 런타임 게이트로 두지 않고 `D_eff`에 흡수하나:**
- 런타임에 두면 미션당 hot row가 하나 더 생기고(`farm_missions.total_used`), 두 UPDATE의 락 순서를 또 고정해야 한다.
- `D_eff`에 흡수하면 **런타임 UPDATE는 여전히 1개**. 그날 안에 전체 잔여가 바닥나는 상황은 `D_eff`가 이미 반영했으므로 발생하지 않는다.
- `total_used`는 일 마감 배치(`farm:aggregate-stats`)가 `SUM(used)`로 갱신한다. **런타임에 쓰지 않는다.**

세 번째 항(`ceil(전체잔여 / 남은일수)`)을 넣는 이유: 전체 700개·7일 미션에서 첫날에 700을 다 소진하면 나머지 6일 노출이 0이 된다. 이건 광고 계약(기간형 노출)의 취지 위반이다.

### 6-3. 미소진분 이월 — 1단계 캐리 + 캡

**결정: 다음 구간으로만 1단계 이월. 캡은 자기 배정량(`carry_cap_ratio` 기본 1.0). 마지막 구간 S7만 전량 수용.**

```
unused(i)     = max(0, alloc(i) + carry_in(i) - used(i))
cap(i+1)      = (i+1 == S7) ? ∞ : alloc(i+1) * carry_cap_ratio
carry_in(i+1) = min(unused(i), cap(i+1))
effective(i)  = alloc(i) + carry_in(i)
```

| 정책 | 장점 | 단점 | 판정 |
|---|---|---|---|
| 전량 이월(누적) | 미달 최소 | **막판 쏠림** — S7에 하루치가 몰려 분산 실패 | ❌ |
| 이월 없음 | 완벽 분산 | 미달 15~20% | ❌ 매출 손실 + 이행률 클레임 |
| **1단계 + 캡 1.0** | 편차 2배 이내로 통제 | 미달 8~12% | ✅ **채택** |

**"1단계만"인 이유:** 2단계 이상 누적을 허용하면 결국 전량 이월과 같아진다. 1단계 + 캡 1.0이면 `effective(i) ≤ 2 × alloc(i)` 이므로 **시간당 노출 편차가 최대 2배**로 제한된다.

**🔴 일 총합 보장 증명**

```
주장: 모든 k에 대해  Σ_{i=1..k} used(i)  ≤  Σ_{i=1..k} alloc(i)

k=1: used(1) ≤ effective(1) = alloc(1) + carry_in(1) = alloc(1) + 0 = alloc(1)   ✓

k → k+1 (귀납):
  used(k+1) ≤ effective(k+1)
            = alloc(k+1) + carry_in(k+1)
            ≤ alloc(k+1) + unused(k)                        (carry_in ≤ unused, 캡 무관)
            = alloc(k+1) + (alloc(k) + carry_in(k) - used(k))
            = alloc(k+1) + effective(k) - used(k)

  Σ_{i≤k+1} used(i) = Σ_{i≤k} used(i) + used(k+1)
                    ≤ Σ_{i≤k} used(i) + alloc(k+1) + effective(k) - used(k)

  effective(k) - used(k) = unused(k) 이고, 귀납가정에서
  Σ_{i≤k-1} used(i) ≤ Σ_{i≤k-1} alloc(i) 이므로 전개하면

  Σ_{i≤k+1} used(i) ≤ Σ_{i≤k+1} alloc(i)                    ✓

k=7 에서:  Σ used ≤ Σ alloc = D_eff                          ∎
```

**따라서 일 단위 카운터 UPDATE가 불필요하다.** 이것이 D2의 근거다.

**증명이 성립하기 위한 필수 조건 2개:**
1. `Σ alloc = D_eff` (§6-1의 assert)
2. `carry_in(i+1) ≤ unused(i)` — **`unused(i)`를 계산하는 순간 구간 i에 추가 확정이 없어야 한다.** → §6-5의 `closed` 플래그가 이를 보장한다.

**마지막 구간 S7의 전량 수용 (`cap = ∞`)**
- 근거: 당일 소진이 광고 계약의 핵심이고, 22:00–02:00은 4시간 + 피크 인접이라 실제 소화 여력이 있다.
- 편차 상한이 S7에서만 깨지지만(최대 D_eff까지 가능), **하루의 마지막이라 뒤로 밀 곳이 없으므로 분산을 해칠 대상이 없다.**
- 02:00 마감 시 남은 `unused(S7)`은 **소멸한다.** 다음 날로 넘기지 않는다 — 넘기면 `Σ alloc = D_eff` 불변식이 날짜를 넘어 깨진다.

### 6-4. 심야(02:00–06:00) 처리 — 노출 중단

**결정: 02:00–06:00은 어떤 구간에도 속하지 않는다. `GET /missions`가 빈 목록 + `meta.closed=true`, `POST /submit`은 `rejected('closed')`.**

| 후보 | 판정 |
|---|---|
| 심야 구간에 소량 배정(1~3건) | ❌ 정상 사용자 1% 미만인데 어뷰징 탐지·야간 장애 대응 부담이 100% |
| 심야도 정상 운영 | ❌ 광고주 관점에서 새벽 3시 노출은 가치가 낮다 |
| **02–06 노출 중단** | ✅ **채택** |

**손실 추정:** 02–06시 정상 트래픽 비중 = 전체의 **0.8%** (미니앱 일반 패턴). 100만 건 중 8,000건. 이 중 상당수는 06:00 이후로 이연되므로 **실질 손실 3,000건/일 이하 = 0.3%.**

**얻는 것:**
- 어뷰징 탐지 대상 시간대가 사라진다(새벽 참여 = 거의 전부 자동화).
- 배치 창(window)이 생긴다 — `farm:aggregate-stats`(03:00), `farm:partition-rotate`(05:40), `farm:plan-quota`(05:50)를 무경합 상태에서 돌릴 수 있다. **이게 실질적으로 가장 큰 이득이다.**
- 사용자 하루가 06:00에 리셋되므로 리셋 몰림도 없다.

**휴지 시간 응답 (DB 쿼리 0)**
```json
{
  "missions": [],
  "meta": {
    "closed": true,
    "opensAt": "2026-07-29T06:00:00+09:00",
    "message": "새로운 미션은 아침 6시에 열려요."
  }
}
```
`Cache-Control: private, max-age=60`.

### 6-5. 구간 경계 동시성

**경계 시각:** 09:00, 12:00, 14:00, 18:00, 20:00, 22:00, 02:00(마감), 06:00(시작)

**롤오버 배치 `farm:rollover-slots` — 매시 정각 실행, 경계 시각에만 동작**

```
T = 현재 정시
if T not in 경계목록: return

prev = T 직전 구간, next = T 시작 구간, day = farmDay(T - 1초)

DB::transaction(function () {
    # ── ① 이전 구간을 먼저 닫는다 (이게 핵심) ──────────
    UPDATE farm_mission_slot_quotas
       SET closed = 1, closed_at = NOW()
     WHERE stat_date = day AND slot_code = prev AND closed = 0;
    # 이 시점 이후 prev 에 대한 확정 UPDATE 는 전부 affected=0 이 된다

    # ── ② 닫힌 값으로 carry 를 계산 ────────────────────
    for each row in prev:
        unused = max(0, alloc + carry_in - used)
        cap    = (next == 'S7') ? PHP_INT_MAX : floor(alloc_next * carry_cap_ratio)
        carry  = min(unused, cap)
        UPDATE prev SET carried_out = carry
        UPDATE next SET carry_in = carry, opened_at = NOW()
});
```

**🔴 순서가 정확성을 만든다: "닫고 나서 읽는다".**
- 읽고 나서 닫으면, 읽는 순간과 닫는 순간 사이에 커밋되는 in-flight 트랜잭션의 `used`가 `carry` 계산에서 빠진다 → `carry_in`이 과대 → **§6-3 증명의 조건 2가 깨져 초과 발생.**
- 먼저 닫으면 in-flight는 `closed=0` 조건에서 걸려 affected=0이 된다.

**affected=0을 받은 in-flight 요청의 처리 (§4-3의 재시도)**
```
1차 UPDATE (prev slot) affected = 0
  → 현재 시각으로 slot 재계산 → next
  → 2차 UPDATE (next slot) 시도
  → 그래도 0 이면 rejected('quota_full')
```
재시도는 **1회만**. 무한 순회는 금지(초과 경로가 된다).

**경계에서 밀려나는 요청 수 추정:** 피크 미션 4.6 QPS × 트랜잭션 5ms = **0.023건**. 사실상 0. 미션 1,000개 전체로도 경계당 23건, 하루 7경계 × 23 = 161건이 1회 재시도한다.

**배치가 안 돌면 (fail-safe 확인)**

| 상황 | 결과 | 방향 |
|---|---|---|
| `farm:rollover-slots` 미실행 | `carry_in` = 0 유지, 이전 구간이 `closed=0`으로 남아 계속 소진 가능 | ⚠️ 이전 구간이 안 닫히면 **그 구간이 계속 열려 있다** → 분산 실패지만 `Σ alloc` 은 지켜지므로 **초과는 없음** |
| `farm:plan-quota` 미실행 | 그날 구간 행이 없음 → 런타임 폴백 upsert(`alloc = floor(D_eff × w)`, `carry_in = 0`) | ✅ 초과 없음, 미달만 |
| `farm:expire-holds` 미실행 | `held`가 안 줄어 신규 홀드 발급이 막힘 | ✅ 노출 축소 = 안전 방향 |

**모든 배치 실패가 "미달" 방향이다.** 이것이 설계의 안전 속성이다.

**런타임 폴백 upsert (rankfree `AiCrawlerHit::record()` 패턴)**
```
행이 없으면:
  insertOrIgnore(farm_mission_slot_quotas, [
      mission_id, stat_date, slot_code, shard_no,
      alloc = allocate(D_eff, slots)[slot],
      carry_in = 0, carried_out = 0, used = 0, held = 0, closed = 0,
  ])
  → unique(mission,date,slot,shard) 가 중복을 흡수 (동시 요청 다수여도 1행)
그 다음 조건부 UPDATE 실행
```

### 6-6. 쿨다운 × 시간대 분산의 상호작용

| 상호작용 | 효과 | 판정 |
|---|---|---|
| 쿨다운 120분 ≈ 구간 길이(2~4h) | 한 사용자는 한 구간에 최대 1회 → **구간 내 사용자 다양성 자동 확보** | ✅ 좋은 궁합 |
| 쿨다운이 참여를 뒤 구간으로 밀어냄 | S2·S4가 목표 대비 +5% 초과 흡수 | ✅ 캐리와 방향이 같음 |
| **S6(20–22) 참여자가 S7에 못 옴** | S7 −14.7% 미달 (§5-3 표) | 🔴 **최대 리스크** |
| S7 전량 캐리 수용 | 배정은 늘지만 소화 주체 부족 | ⚠️ 배정만으로는 해결 안 됨 |
| 심야 휴지(02–06) + 06:00 리셋 | 리셋 직후 S1에 몰림? → S1 가중치 8%로 낮게 잡아 흡수 | ✅ |

**S7 미달 대응 우선순위**
1. **S7에 노출 우선권 부여** — `farm:warm-cache`가 후보 ZSET을 만들 때 S7 구간에는 `잔여율 × 1.3` 가중을 적용해 상위 노출. (구현 비용 최소, 효과 중)
2. **쿨다운 90분** — S6 참여자가 21:30 이후 S7 복귀 가능. 미달 −14.7% → −8% 추정. (사업 효과 최대, 어뷰징 방어 약화)
3. **S6 가중치 21% → 18%, S7 15% → 18%** — 애초에 S6에 덜 넣는다. (분산 목표에는 부합, 피크 트래픽을 버림)

**운영 순서: 1 → 3 → 2.** 2는 어뷰징 방어를 약화시키므로 마지막.

---

## 7. 캐싱 설계

### 7-1. 계층 구조

```
읽기:  L1 APCu (서버 로컬, 1~5초)
         ↓ miss
       L2 Redis (공유, 3~300초)
         ↓ miss
       L3 MySQL (원장)

쓰기:  MySQL 원자적 UPDATE 만.  Redis 를 절대 거치지 않는다.
무효화: 확정 후 관련 키를 **DEL** 한다.  SET 으로 갱신하지 않는다.
```

**DEL만 하고 SET을 안 하는 이유 (D14):** 두 요청 A(값 10)·B(값 11)가 있을 때 SET 순서가 뒤집히면 10이 영구히 남는다(TTL 만료까지). DEL은 순서가 뒤집혀도 결과가 같다(둘 다 삭제) → 다음 읽기가 DB에서 정확한 값을 채운다. **멱등한 무효화가 순서 문제를 없앤다.**

**Laravel 통합**
```
config/cache.php 에 스토어 2개 추가 (기본 스토어 'database' 는 건드리지 않는다 — rankfree 기존 기능 무영향)
  'farm_l1' => ['driver' => 'apc']
  'farm_l2' => ['driver' => 'redis', 'connection' => 'farm', 'lock_connection' => 'farm']

app/Domain/Farm/FarmCache.php  — 2단 조회 헬퍼
  get(key, l1Ttl, l2Ttl, callback)
  forget(key)                 → L1 DEL + L2 DEL
  forgetMany(keys)            → L2 는 파이프라인 DEL
```

> ⚠️ `config/cache.php`의 `'serializable_classes' => false` — **캐시에 Eloquent 모델/Collection을 넣으면 복원 시 `__PHP_Incomplete_Class`로 500이 난다**(rankfree 실사고 주석, `KeywordBrowseController`). 반드시 `->all()`로 순수 배열 변환 후 저장. 이 규칙은 Redis 스토어에서도 동일하게 적용한다.

### 7-2. 캐시 키 표 🔴

| # | 대상 | 키 형식 | 계층 | L1 TTL | L2 TTL | 자료구조 | 무효화 시점 |
|---|---|---|---|---|---|---|---|
| **C1** | 공용 미션 목록(개인화 전) | `farm:ml:{farmDay}:{slot}:v{ver}` | L1+L2+파일 | **5s** | **60s** | Redis **String** (JSON, 2KB↑면 gzip) | `farm:warm-cache` 매분 재생성 / 미션 CRUD 시 `ver`+1 |
| **C2** | 미션별 구간 잔여 | `farm:sq:{missionId}:{farmDay}:{slot}` | L1+L2 | **2s** | **3s** | Redis **Hash** `{alloc, carry, used, held, closed}` | 확정 UPDATE 성공 시 **DEL** |
| **C3** | 사용자 오늘 참여 수 | — | **캐시 안 함** | — | — | — | `farm_users.today_count`/`today_date` 컬럼이 대체 (§3-4) |
| **C4** | 사용자×미션 누적 | `farm:um:{farmUserId}` | L2 | — | **86400s** | Redis **Hash** (field=`{missionId}`, value=누적수) | 확정 시 **HDEL** 해당 field |
| **C5** | 사용자 쿨다운 | — | **캐시 안 함** | — | — | — | `farm_users.cooldown_until` (인증 단계에 이미 로드) |
| **C6** | 미션 마스터(정답 제외) | `farm:m:{missionId}:v{ver}` | L1+L2 | **30s** | **300s** | Redis **String** (JSON) | 미션 CRUD 시 `ver`+1 (개별 DEL 안 함) |
| **C7** | 사용자 밭 상태 | `farm:st:{farmUserId}` | L2 | — | **60s** | Redis **String** (JSON) | 심기·제출·수확 시 **DEL** |
| **C8** | 익명키 검증 결과 | `farm:anon:{userKeyHash}` | L2 | — | **21600s (6h)** | Redis **String** (`"1"`/`"0"`) | TTL만 |
| **C9** | 노출 후보 집합 | `farm:cand:{farmDay}:{slot}` | L1+L2 | **5s** | **30s** | Redis **Sorted Set** (score = 잔여율×가중) | `farm:warm-cache` 매분 재생성 |
| **C10** | IP 일 참여 수 | `farm:ipd:{md5(ip)}:{farmDay}` | L2 | — | **300s** | Redis **String** (int) | 확정 시 **DEL** |
| **C11** | 미션 목록 버전 | `farm:ver` | L1+L2 | **10s** | **600s** | Redis **String** (int) | 미션·세부주문서 변경 시 INCR |
| **C12** | Redis 서킷 브레이커 | `farm:breaker` | **L1만** | 60s | — | APCu int | 연속 실패 5회 시 SET, 60초 후 자동 해제 |

**자료구조 선택 근거**

| 구조 | 어디에 | 왜 |
|---|---|---|
| **String** | C1, C6, C7, C8, C10, C11 | 통짜 JSON을 `GET` 1회로 가져온다. RTT가 지배적이므로 필드 분해 이득 없음 |
| **Hash** | C2, C4 | **필드 단위 무효화**가 필요. C4는 사용자당 키 1개(33만 키)로 압축 — 미션별 키를 만들면 33만 × 8 = 264만 키가 된다. `HDEL` 1개 필드만 지우면 나머지 캐시가 살아남는다 |
| **Sorted Set** | C9 | `ZREVRANGE 0 59`로 **잔여율 상위 60개**를 O(log N + 60)에 뽑는다. 여유 있는 미션을 먼저 노출해 소진을 균등화하는 게 핵심 |
| List / Set | **미사용** | 순서·집합 연산이 필요한 데이터가 없다 |

**C9의 score 계산**
```
score = (effective - used - held) / max(1, effective)          # 잔여율 0.0 ~ 1.0
      × (farm_missions.exposure_weight / 100)                  # 운영 가중
      × (slot == 'S7' ? 1.3 : 1.0)                             # S7 미달 대응 (§6-6)
잔여 ≤ 0 인 미션은 ZSET에서 제거(ZREM)
```

**C3를 캐시하지 않는 이유:** `farm_users.today_count`/`today_date` 컬럼이 인증 단계에서 이미 로드된다. 캐시를 두면 컬럼과 캐시가 어긋날 경로만 늘어난다. 판정 자체는 확정 트랜잭션 안에서 `farm_planting_days` COUNT로 재검증하므로 컬럼이 틀려도 안전하다.

### 7-3. Redis 운영 설정

| 항목 | 값 | 근거 |
|---|---|---|
| `maxmemory` | **1GB** | 추정 사용량 250MB(§9-4)의 4배 |
| `maxmemory-policy` | **`allkeys-lru`** | 모든 키가 DB에서 재생성 가능. `volatile-lru`면 TTL 없는 키가 실수로 생겼을 때 OOM |
| `save` (RDB) | **비활성 (`save ""`)** | 원장이 아니므로 스냅샷 불필요. 디스크 IO 절약 |
| `appendonly` | **`no`** | 동일 |
| `databases` | 1 (`db 0`) | 논리 DB 분리 대신 키 prefix `farm:` 사용 |
| `timeout` | 300 | 유휴 연결 정리 |
| PHP 클라이언트 | **`predis/predis`** (composer) 또는 phpredis 확장 | 조사상 rankfree에 predis 없음 → `composer require predis/predis` 또는 서버 3대에 `php83-php-pecl-redis` 설치. **phpredis 권장**(C 확장이라 2~3배 빠름) |
| 연결 타임아웃 | **connect 200ms / read 300ms** | 이보다 길면 Redis 장애 시 PHP-FPM 워커가 고갈된다 |

**🔴 `maxmemory-policy allkeys-lru` + persistence OFF의 의미:** Redis를 재시작하면 캐시가 전부 비고, 앱은 DB에서 다시 채운다. **"Redis 복구"라는 작업이 존재하지 않는다.** 이것이 카페24 사고(복구 불가)의 근본 대책이다.

**prefix:** `config('database.redis.options.prefix')`가 rankfree 전역에 걸려 있으면 `farm:` 앞에 붙는다. 전용 connection(`farm`)을 정의해 prefix를 `''`로 두고 키에 직접 `farm:`을 쓴다.

### 7-4. Redis 장애 처리 — 서킷 브레이커

```
FarmCache::l2get(key):
    if (apcu_fetch('farm:breaker')) return MISS;          # 브레이커 열림 → Redis 스킵
    try {
        $v = Redis::connection('farm')->get($key);
        apcu_delete('farm:breaker:fail');                 # 성공 시 실패 카운터 리셋
        return $v;
    } catch (\Throwable $e) {
        $n = apcu_inc('farm:breaker:fail');
        if ($n >= 5) {
            apcu_store('farm:breaker', 1, 60);            # 60초간 Redis 스킵
            Log::warning('퀴즈농장(30) Redis 차단 — 60초간 DB 폴백', ['error' => $e->getMessage()]);
        }
        return MISS;
    }
```

**브레이커 없이 try/catch만 두면 안 되는 이유:** Redis가 응답 없이 타임아웃(300ms)만 내면 매 요청이 300ms를 낭비한다. 피크 583 QPS × 3키 × 300ms = **워커 524개분 대기** → PHP-FPM(60 워커) 즉시 고갈 = 전면 장애. 브레이커가 이를 60초 단위로 끊는다.

🔴 **브레이커 상태는 요청 간 살아남아야 한다**(2026-07-31 구현 확정 — 위 의사코드가 전부 `apcu_*`인 이유):
"연속 5회 실패"는 여러 요청에 걸친 누적이다. APCu가 없는 서버에서는 L1이 프로세스 static이라 php-fpm 요청마다
초기화되고, 한 요청이 L2를 만지는 횟수는 많아야 2~4회 — **임계 5에 영원히 도달하지 못해 브레이커가 열리지 않는다.**
즉 브레이커가 가장 필요한 환경(APCu 없음 + Redis 무응답)에서 정확히 무력화된다. 그래서 구현은 APCu가 없으면
브레이커 상태를 **공유 스토어**(`reward.cache.shared_store`, 기본 `database` — L2와 독립)에 둔다.

**장애 시 저하 모드 성능**

| 상태 | 응답 P95 | DB 쿼리/요청 | 처리 가능 QPS |
|---|---|---|---|
| 정상 (L1 70% + L2 30%) | 8ms | 0.15 | 1,000+ |
| Redis 다운 (L1만 + DB) | 35ms | 3.2 | **~350** |
| Redis + APCu 다운 (DB만) | 60ms | 5.0 | **~200** |
| Redis + DB 다운 | 파일 캐시로 목록만 제공, 제출은 503 | — | 읽기만 |

피크 스파이크 583 QPS는 Redis 다운 시 감당 못 한다(350). → **`farm:warm-cache`가 굽는 파일 캐시가 마지막 방어선**(§7-6).

### 7-5. Redis 없이 시작하기

Redis 설치 전에도 서비스가 동작해야 한다(`config('rankfree.farm.cache.redis_enabled') = false`).

| 대상 | Redis 있을 때 | Redis 없을 때 | 한계 |
|---|---|---|---|
| C1 목록 | L1+L2+파일 | **L1 + 파일 캐시** | 서버 3대 간 최대 60초 불일치(파일이 서버별) |
| C2 구간 잔여 | L2 Hash 3s | **DB 직접 조회 + L1 2s** | 미션 8개 × 조회 = 요청당 쿼리 1회(IN 절) 추가 |
| C4 사용자×미션 | L2 Hash | **DB 직접 + L1 5s** | 요청당 쿼리 1회 추가 |
| C7 밭 상태 | L2 60s | **캐시 없음** | `/me/state`가 항상 DB (13.8 QPS라 감당 가능) |
| C8 익명키 검증 | L2 6h | **`cache` 테이블(DB 캐시) 6h** | 6시간 TTL이라 쓰기 부하 낮음 → DB로 충분 |
| C9 후보 ZSET | L2 ZSET | **파일 캐시 JSON(정렬 완료) 60s** | 60초 낡음 |
| C10 IP 카운트 | L2 300s | **DB 캐시 300s** | 쓰기 100만/일 → `cache` 테이블 부담. **IP 상한을 끄는 것도 선택지** |

**한계 종합:**
- 처리 가능 QPS가 **1,000 → 350**으로 떨어진다 → 하루 100만 건 목표에는 **Redis 필수.** 현재 트래픽(수십만)까지는 Redis 없이 가능.
- 서버 3대의 카운터 사본이 최대 60초 어긋난다 → **노출 필터만 부정확해지고 초과는 여전히 0**(L2가 DB이므로).
- `Cache::lock()`이 `cache_locks` 테이블을 쓰므로 락 경합이 DB 부하로 직결된다(조사 확인) → 런타임 경로에서 `Cache::lock`을 **쓰지 않는다.** 배치에서만 사용.

**전환 시점:** 일 참여 30만 건 돌파 또는 P95 응답 100ms 초과 시 Redis 도입.

🔴 **기본 캐시 스토어를 "서버 간 공유"로 가정하지 않는다**(2026-07-31 구현 확정): 운영은 `CACHE_STORE=file`
(서버 로컬)이다. 서버를 늘리면 C11 버전 카운터(`reward:ver`)가 서버마다 따로 놀아 `bumpVersion`이 다른 서버의
`v{ver}` 키를 무효화하지 못하고, C11 즉시 무효화가 TTL 수렴으로 격하된다. 같은 이유로 스케줄
`withoutOverlapping()` 뮤텍스도 서버별이라 "공유 cache_locks라 1대만"이라는 주석은 현재 설정에서 거짓이다.
→ 공유가 필요한 값(버전·브레이커)만 `reward.cache.shared_store`(기본 `database`)에 고정한다.
**다중 서버 확장 시 체크리스트**: ① `Schedule::useCache('database')` 또는 `onOneServer()` 적용 여부 결정
② `reward:warm-cache`는 전 서버에 크론 설치(서버 로컬 파일을 굽는 커맨드라 의도된 중복)
③ `reward:build-snapshot`은 1대만 돌면 충분(DB 원장).

### 7-6. 파일 캐시 (최후 폴백)

```
경로: storage/app/farm/missions-{farmDay}-{slot}.json
생성: farm:warm-cache 가 매분 (서버 3대 각각 자체 생성)
크기: 미션 8개 × ~600B = 5KB
```

- **공유 스토리지를 가정하지 않는다.** 3대가 각자 굽는다.
- ⚠️ **`farm:warm-cache`만은 `withoutOverlapping()`을 쓰면 안 된다.** `withoutOverlapping()`은 `cache_locks` 테이블(공유 DB)을 쓰므로 **3대 중 1대만 실행된다.** 나머지 2대의 파일 캐시가 영원히 낡는다. → `flock(storage/framework/farm-warm.lock)` **로컬 파일 락**을 커맨드 안에서 직접 건다.
- 이 함정은 rankfree 조사에서 확인된 "새 큐 이름을 supervisor conf에 안 넣어 발행 0" 사고와 같은 계열(공유 자원과 로컬 자원의 혼동)이다.

### 7-7. 캐시 ↔ DB 불일치 복구

**원칙: 모든 캐시 값은 DB에서 재생성 가능하다. 복구 절차 = "비우고 다시 채우기"로 끝난다.**

```
php artisan farm:flush-cache [--layer=all|l1|l2|file] [--mission=ID]
  l1   : apcu_delete 정규식 'farm:*'   (서버별 실행 필요 → 3대 순회 스크립트)
  l2   : Redis SCAN + UNLINK 'farm:*'  (KEYS 금지 — 블로킹)
  file : storage/app/farm/*.json 삭제
```

**불일치 유형별 대응**

| 유형 | 감지 | 대응 |
|---|---|---|
| 캐시가 DB보다 낡음 | 정상(TTL 범위 내) | 없음 — 노출 필터만 부정확, 초과 0 |
| 캐시 값과 확정 결과 불일치 | 확정 UPDATE affected=0인데 캐시엔 잔여 있음 | **DB가 항상 이긴다.** 그 즉시 해당 키 DEL |
| `slot_quotas.used` ↔ 로그 드리프트 | `farm:audit-quota` 매시 | `farm_quota_audits` 기록 + `diff>0`이면 잔디 알림. **자동 보정하지 않는다** |
| `farm_users.today_count` ↔ 로그 드리프트 | 확정 트랜잭션에서 항상 재검증 | 드리프트가 있어도 판정은 로그 기준이라 무해. `farm:rebuild-state`(draft 부록 A)가 정정 |
| `slot_quotas.held` ↔ 홀드 행 | `farm:expire-holds` 매분 COUNT 덮어쓰기 | 자동 수렴 (증감이 아니라 절대값 대입이므로) |

**🔴 자동 보정을 하지 않는 이유:** `used`를 로그 COUNT로 덮어쓰면, 드리프트 원인이 "로그 중복"일 때 한도를 잘못 늘려 **진짜 초과를 만든다.** 사람이 원인을 확인한 뒤 어드민에서 수동 조정한다.

---

## 8. API별 처리 흐름 (의사코드)

### 8-1. `GET /api/farm/missions`

```
[미들웨어 auth.farm]
  x-user-key → hash → FarmUser 로드 (DB 1회, 이 요청의 유일한 필수 쿼리)
  blocked → 403

[컨트롤러 FarmMissionController@index]

01. $day  = FarmDay::current()                                   # 순수 계산
    $slot = FarmDay::slot()                                      # null = 휴지

02. if ($slot === null):                                         # ── 휴지 시간 ──
        return 200 {missions: [], meta: {closed:true, opensAt, message}}
        # 🔵 DB 쿼리 0, 캐시 조회 0

03. if ($u->cooldown_until && $u->cooldown_until > now()):        # ── 쿨다운 ──
        $list = FarmCache::get("farm:ml:{$day}:{$slot}:v{$ver}")  # C1, L1 히트 시 0.1ms
        return 200 {
            missions: $list.map(m => m + {completed: true}),
            meta: {remaining:0, dailyLimit:3, cooldownUntil, cooldownSeconds}
        }
        # 🔵 DB 쿼리 0, 캐시 1회. 전체 요청의 ~40%가 여기서 끝난다

04. $done = ($u->today_date == $day) ? $u->today_count : 0        # 컬럼, 쿼리 0
    if ($done >= config('daily_mission_limit', 3)):               # ── 일 한도 소진 ──
        return 200 {missions: $list(completed:true), meta:{remaining:0, ...}}
        # 🔵 DB 쿼리 0

05. $candidates = FarmCache::zrevrange("farm:cand:{$day}:{$slot}", 0, 59)   # C9, 후보 60개
    if (empty) → DB 폴백: slot_quotas 에서 잔여>0 미션 60개 (인덱스 fmsq_day)

06. # ── 사용자 개인화 필터 ──
    $userMission = FarmCache::hgetall("farm:um:{$u->id}")         # C4, Hash 1회
    if (miss):
        DB 1회: SELECT mission_id, COUNT(*) FROM farm_mission_logs
                 WHERE farm_user_id=? AND result='correct'
                   AND mission_id IN (후보 60개)
                 GROUP BY mission_id
        → HMSET + EXPIRE 86400

    $todayDone = 오늘 정답 처리한 mission_id 집합                  # 위 결과에서 파생 불가
                 → farm_planting_days(farm_user_id, work_date=day) 의 mission_id
                 → C7(밭 상태 캐시)에 함께 담아 재사용

    필터: total >= per_user_limit          → 제외
          todayCount >= per_user_daily_limit → 제외

07. # ── 구간 잔여 확인 ──
    $quotas = FarmCache::hmget("farm:sq:{missionId}:{$day}:{$slot}" × 후보)   # C2, 파이프라인 1회
    필터: (effective - used - held) <= 0   → 제외
          잔여 <= max(3, effective × 3%)   → 제외하지 않고 순위 최하위 + isClosingSoon

08. # ── 노출 선택 ──
    상위 config('exposure_limit', 8) 개 선택 (C9 score 순, 06번 필터 통과분만)

09. # ── 직렬화 ──
    각 미션 = FarmCache::get("farm:m:{$id}:v{$ver}")              # C6, JSON
    🔴 answer / answer_type / tolerance_percent 절대 포함 금지
    hintUrl 치환: https://rankfree.kr/api/farm/go/{id}?t={token}  # Phase 2

10. return 200 {
        missions: [...],
        meta: {remaining: 3-$done, dailyLimit: 3, cooldownUntil: null,
               slot: $slot, closingSoon: [missionIds]}
    }
```

**요청당 비용 요약**

| 경로 | 비중 | DB 쿼리 | 캐시 조회 | 목표 P95 |
|---|---|---|---|---|
| 휴지 시간 | 3% | **0** | 0 | 2ms |
| 쿨다운 중 | 40% | **1** (인증) | 1 | 4ms |
| 일 한도 소진 | 12% | **1** | 1 | 4ms |
| 정상 (캐시 히트) | 43% | **1** | 4 | 10ms |
| 정상 (캐시 미스) | 2% | **4** | 4 | 35ms |
| **가중 평균** | | **1.06** | **2.2** | **6ms** |

### 8-2. `GET /api/farm/go/{mission}` (Phase 2, 슬롯 선점)

```
[auth.farm 밖 — 서명 토큰 검증 전용, throttle:30,1]

01. $t = request('t'); 파싱 → [prefix12, missionId, exp, hmac16]
02. hash_equals(계산 hmac, hmac16) 실패 OR exp < now
        → 302 to farm_missions.hint_url   (홀드 없이 링크는 살린다)
03. $u = FarmUser::where('user_key_hash','like',"{$prefix12}%")->first()
    null OR blocked → 302 to 미니앱 홈
04. $day = FarmDay::current(); $slot = FarmDay::slot()
    $slot === null → 302 to hint_url (휴지 시간엔 홀드 없이)
05. 홀드 발급:
    UPDATE farm_mission_slot_quotas
       SET held = held + 1
     WHERE mission_id=? AND stat_date=? AND slot_code=? AND shard_no=?
       AND closed=0 AND used + held < alloc + carry_in
    affected=1 →
        insertOrIgnore farm_mission_holds(
            farm_user_id, mission_id, stat_date, slot_code, shard_no,
            status='held', expires_at = now + hold_ttl_minutes(10)
        )
        # unique(farm_user_id, mission_id, stat_date) 충돌 시 = 재클릭
        #   → UPDATE expires_at 만 연장, held 는 이미 +1 했으므로 -1 되돌림
    affected=0 → 홀드 없이 진행
06. FarmCache::forget("farm:sq:{$missionId}:{$day}:{$slot}")     # C2 DEL
07. return 302 Location: farm_missions.hint_url
    Cache-Control: no-store, Pragma: no-cache
```

### 8-3. `POST /api/farm/missions/{mission}/submit` 🔴

```
[미들웨어 auth.farm] FarmUser 로드, blocked → 403
[throttle:20,1]

── A. 사전 게이트 (여기서는 정답을 절대 보지 않는다) ──────────────

01. $day = FarmDay::current(); $slot = FarmDay::slot()
    $slot === null       → log(rejected,'closed')      → 200 {correct:false, message:'미션은 아침 6시에 열려요.'}

02. 쿨다운:  $u->cooldown_until > now()
             → log(rejected,'cooldown')                → 200 {correct:false, message:'다음 미션은 HH:MM에 열려요.'}

03. 제출 간격: 마지막 farm_mission_logs.created_at > now - 3s
             → log(rejected,'too_fast')                → 200 {correct:false, message:'잠시 후 다시 시도해 주세요.'}

04. 오늘 시도 상한: COUNT(farm_mission_logs WHERE farm_user_id, created_at >= dayStart) >= 10
             → log(rejected,'too_fast')                → 200 {correct:false, message:'오늘은 더 시도할 수 없어요.'}

05. IP 상한: FarmCache::get("farm:ipd:{md5(ip)}:{$day}") >= 30      # C10
             → log(rejected,'ip_limit')                → 200 {correct:false, message:'잠시 후 다시 시도해 주세요.'}

06. 일 한도: $done = farm_planting_days COUNT(farm_user_id, work_date=$day) >= 3
             → log(rejected,'daily_limit')             → 200 {correct:false, message:'오늘 참여를 모두 마쳤어요.'}

07. 대상 밭 결정: farm_plantings(status='growing', plot_index NOT IN 오늘참여밭) 중 선택
             없음 → log(rejected,'plot_empty'|'plot_done')

08. 미션 노출 상태:
    farm_missions.is_active
    AND 세부주문서 status='sent' AND 부모 주문 status='processing'      # 확인 필요(design-01)
    AND $day BETWEEN work_date AND end_date
             → log(rejected,'mission_closed')

09. 사용자×미션 한도:
    total(FarmCache C4) >= per_user_limit      → log(rejected,'already_done')
    todayCount >= per_user_daily_limit         → log(rejected,'already_done')

── B. 채점 (모든 사전 게이트 통과 후에만) ────────────────────────

10. $norm = MissionGrader::normalize($mission, $answer)
    if (! MissionGrader::matches($mission, $norm)):
        log(wrong, answer_raw, answer_norm)             → 200 {correct:false, message:'다시 한 번 확인해 주세요.'}
    # 🔵 오답은 카운터를 건드리지 않는다 → hot row 부담 없음

── C. 확정 (트랜잭션) ──────────────────────────────────────────

11. DB::transaction:
    a) FarmUser::whereKey($u->id)->lockForUpdate()->first()       # 락 ①
    b) 06·07·09 재검증 (락 획득 사이에 상태가 바뀌었을 수 있다)
    c) 🔴 슬롯 확보:
       - 홀드 보유 시(Phase 2):
           UPDATE slot_quotas SET used=used+1, held=GREATEST(held-1,0)
            WHERE ... AND closed=0 AND used < alloc + carry_in
       - 홀드 없을 때:
           UPDATE slot_quotas SET used=used+1
            WHERE ... AND closed=0 AND used + held < alloc + carry_in
       affected=0 → $slot 재계산 후 **1회만** 재시도
       최종 affected=0 →
           log(rejected,'quota_full')
           return 200 {correct:false, message:'방금 마감됐어요. 다른 미션을 해보세요.'}
           # 🔴 정답을 맞혔지만 슬롯 없음. 사용자 보상 X, 광고주 청구도 X → 금전 손해 0
    d) INSERT farm_mission_logs(result='correct', ...)
    e) INSERT farm_planting_days(...)          # unique 2개가 최종 방어
       QueryException → 동시 요청 경합 → rollback → 200 {correct:false, message:'이 밭은 오늘 이미 돌봤어요.'}
    f) UPDATE farm_plantings(completed_days, last_tended_date=$day,
                             status = done >= required_days ? 'ready' : 'growing')
    g) UPDATE farm_users:
           correct_count + 1
           today_date = $day, today_count = (today_date==$day ? today_count+1 : 1)
           last_participated_at = now()
           cooldown_until = now() + 120분 + rand(-10,+10)분        # 🔴 쿨다운 설정
           daily_ip = $ip
    h) 참여 포인트 원장 예약 (PointLedgerService::reserve)          # 다른 영역
    i) UPDATE farm_mission_holds SET status='consumed'              # Phase 2

── D. 커밋 이후 ────────────────────────────────────────────────

12. 캐시 무효화 (실패해도 무시, try/catch):
    FarmCache::forgetMany([
        "farm:sq:{$missionId}:{$day}:{$slot}",       # C2
        "farm:st:{$u->id}",                          # C7
        "farm:ipd:{md5($ip)}:{$day}",                # C10
    ])
    Redis::hdel("farm:um:{$u->id}", $missionId)      # C4 필드만
    # C9(후보 ZSET)는 갱신하지 않는다 — farm:warm-cache 가 매분 재생성

13. FarmPointPayoutJob::dispatch($ledgerId)          # 트랜잭션 밖

14. return 200 {correct:true, reward:{item,count}, points:N}
```

**검증 순서가 곧 보안이다 (A → B → C):**
- 사전 게이트(A)를 통과하지 못하면 **정답 여부를 알 수 없다.** 한도가 찬 뒤에도 채점해 주면 사용자가 오늘 미리 정답을 탐색해 내일 즉시 맞힌다.
- 슬롯 확보(C-c)를 채점(B) 뒤에 두는 이유는 §D4.

### 8-4. `POST /api/farm/harvest`

```
[미들웨어 auth.farm]
[throttle:20,1]

00. 🔴 이 경로는 캐시를 일절 쓰지 않는다 (D15)
    - 금전이 직접 나가는 경로 → 지연된 사본으로 판정 금지
    - QPS 0.55 (하루 4.7만 건) → 캐시 이득 0

01. validate: plotIndex(0..2), cropId(string)

02. DB::transaction:
    a) FarmUser::whereKey()->lockForUpdate()                         # 락 ①
    b) FarmPlanting::where(farm_user_id, plot_index)
                   ->whereIn('status',['growing','ready'])
                   ->lockForUpdate()->first()
       null → {ok:false, message:'수확할 작물이 없어요.'}
    c) 클라 cropId 대조 → 불일치 시 {ok:false, message:'작물 정보가 맞지 않아요.'}
    d) 🔴 7일 검증 = COUNT(farm_planting_days WHERE planting_id) >= required_days
       ※ farm_plantings.completed_days(캐시)를 절대 믿지 않는다
       미달 → {ok:false, message:'아직 다 자라지 않았어요.'}
    e) 중복 방지 1차: farm_harvests WHERE planting_id EXISTS
       → {ok:false, message:'이미 수확한 작물이에요.'}
    f) 포인트 상한: SUM(farm_point_ledgers.amount WHERE counted) — 락 안에서
       $grantable = max(0, min($cropPoints, 5000 - $used))
    g) INSERT farm_harvests   # unique(planting_id) 가 중복 방지 최종 방어
       QueryException → {ok:false, message:'이미 수확한 작물이에요.'}
    h) UPDATE farm_plantings SET status='harvested', harvested_at=now()
    i) INSERT farm_point_ledgers  # unique(source, source_id) 가 중복 지급 최종 방어
       $grantable == 0 → 원장 행 만들지 않음

03. 커밋 후: FarmCache::forget("farm:st:{$u->id}")   # C7
04. FarmPointPayoutJob::dispatch($ledgerId)          # 트랜잭션 밖
05. return 200 $grantable > 0
        ? {ok:true, points: $grantable}
        : {ok:true, points:0, message:'누적 포인트 한도에 도달해 포인트는 지급되지 않아요.'}
```

**쿨다운 · 수량 한도 · 시간대 분산은 수확에 적용하지 않는다.**
수확은 "7일 참여를 마친 것에 대한 보상"이지 참여가 아니다. 여기에 미션 수량 게이트를 걸면 광고주가 산 것과 무관하게 사용자 보상이 막힌다.

---

## 9. 부하 · 용량 추정

### 9-1. 전제 수치

| 항목 | 값 | 산출 |
|---|---|---|
| 일 참여(정답 확정) | **100만** | 목표 |
| 일 시도(오답 포함) | **143만** | 정답률 70% 가정 |
| DAU | **41.8만** | 100만 ÷ 2.39 (§5-3) |
| 활동 시간 | **20시간** (06:00–02:00) | 심야 휴지 4시간 제외 |
| 피크 구간 | **S6 20:00–22:00, 21%** | §2-2 |
| 스파이크 배수 | **×2.5** | 푸시 발송·피크 내 쏠림 |

### 9-2. QPS

| 엔드포인트 | 일 요청 | 평균 QPS | 피크 QPS<br/>(S6 2시간) | 스파이크 QPS |
|---|---|---|---|---|
| `POST /submit` | 143만 | 19.9 | **41.7** | **104** |
| ├ 확정(쓰기) | 100만 | 13.9 | 29.2 | 73 |
| └ 거절·오답(로그만) | 43만 | 6.0 | 12.5 | 31 |
| `GET /missions` | 600만 | 83.3 | **175** | **438** |
| `GET /me/state` | 105만 | 14.6 | 30.6 | 77 |
| `GET /go/{id}` (Phase 2) | 60만 | 8.3 | 17.5 | 44 |
| `POST /harvest` | 4.7만 | 0.65 | 1.4 | 3.4 |
| `POST /plant` | 4.7만 | 0.65 | 1.4 | 3.4 |
| **합계 HTTP** | **917만** | **127** | **267** | **670** |

`GET /missions` 600만 = 참여 100만 × 6회(앱 진입 1 + 미션 선택 1 + 오답 후 재조회 0.4 + 쿨다운 폴링 3.6).

### 9-3. DB 부하

**쓰기 (참여 1건당)**

| # | 작업 | 테이블 | 비고 |
|---|---|---|---|
| 1 | UPDATE 조건부 | `farm_mission_slot_quotas` | 🔴 hot row |
| 2 | INSERT | `farm_mission_logs` | |
| 3 | INSERT | `farm_planting_days` | unique 2개 체크 |
| 4 | UPDATE | `farm_plantings` | |
| 5 | UPDATE | `farm_users` | |
| 6 | INSERT | `farm_point_ledgers` | 참여 포인트 있을 때만 (~30%) |
| 7 | UPDATE | `farm_mission_holds` | Phase 2 |
| | **합계** | | **5.3 ops/참여** |

| 지표 | 평균 | 피크 | 스파이크 |
|---|---|---|---|
| 확정 write ops | 74/s | 155/s | **387/s** |
| 거절·오답 INSERT | 6/s | 13/s | 31/s |
| 홀드 UPDATE (Phase 2) | 8/s | 18/s | 44/s |
| **총 write ops** | **88/s** | **186/s** | **462/s** |

**읽기 (캐시 미스만)**

| 경로 | 캐시 히트율 목표 | 스파이크 시 DB 쿼리 |
|---|---|---|
| 인증 (`farm_users` PK) | — (항상 DB) | 670/s ← **최대 부하** |
| `GET /missions` 개인화 | 95% | 438 × 0.05 × 3 = 66/s |
| `GET /me/state` | 60% (C7) | 77 × 0.4 × 3 = 92/s |
| `POST /submit` 사전 게이트 | — (항상 DB) | 104 × 4 = 416/s |
| **총 read ops** | | **~1,244/s** |

🔴 **인증의 `farm_users` PK 조회 670/s가 단일 최대 부하다.** PK 조회라 InnoDB 버퍼풀에 다 들어간다(41.8만 행 × 200B = 84MB). `innodb_buffer_pool_size`를 최소 4GB로 잡을 것.

**총 DB ops: 스파이크 시 write 462 + read 1,244 = ~1,700 ops/s.**
MariaDB 11.4 단일 인스턴스로 처리 가능하나, **crm(mod_php 7.2)과 공존하는 공용 서버**라는 조사 결과를 고려하면 여유가 크지 않다. 모니터링 필수: `Threads_running > 20` 알람.

### 9-4. PHP-FPM · Redis 용량

**PHP-FPM**
```
현재: 서버당 pm.max_children = 20, 3대 = 60 워커
필요: 스파이크 670 QPS × 평균 응답 15ms = 10.1 동시 워커
      P95 응답 50ms 기준 = 33.5 동시 워커
      DB 지연 시(200ms) = 134 동시 워커  ← 🔴 즉시 고갈
```
**권고: `pm.max_children` 20 → 40** (서버당 메모리 40 × 40MB = 1.6GB). 3대 = 120 워커.
`pm.max_requests = 500` 추가(APCu 파편화 방지).

**Redis 메모리**

| 키 | 개수 | 키당 크기 | 소계 |
|---|---|---|---|
| C1 목록 | 7구간 × 2일 = 14 | 6KB | 84KB |
| C2 구간 잔여 | 미션 1,000 × 7구간 = 7,000 | 220B | 1.5MB |
| C4 사용자×미션 Hash | 41.8만 | 필드 3개 × 40B + 100B = 220B | **92MB** |
| C6 미션 마스터 | 1,000 | 800B | 0.8MB |
| C7 밭 상태 | 41.8만 | 450B | **188MB** |
| C8 익명키 검증 | 41.8만 | 90B | **38MB** |
| C9 후보 ZSET | 7 | 60 × 50B = 3KB | 21KB |
| C10 IP 카운트 | ~15만 | 80B | 12MB |
| **합계** | | | **~333MB** |

`maxmemory 1GB`는 3배 여유. **`allkeys-lru`이므로 초과해도 오래된 키부터 evict되고 정합성은 무영향.**

**Redis QPS**
```
스파이크 670 QPS × L1 미스율 30% × 요청당 평균 3키 = 603 ops/s
파이프라인(C2 HMGET 60개)을 1 ops로 세면 실제는 더 낮다
→ Redis 단일 인스턴스(10만 ops/s급) 대비 사용률 0.6%. 여유 충분
```

### 9-5. 스토리지

| 테이블 | 행/일 | 행 크기<br/>(데이터+인덱스) | 일 증가 | 월 증가 | 보존 | 상시 크기 |
|---|---|---|---|---|---|---|
| `farm_mission_logs` | 143만 | 240B | **343MB** | 10.3GB | **35일** | **12.0GB** |
| `farm_planting_days` | 100만 | 180B | 180MB | 5.4GB | **90일** | **16.2GB** |
| `farm_mission_holds` | 60만 | 120B | 72MB | 2.2GB | **7일** | **0.5GB** |
| `farm_mission_slot_quotas` | 7,000 | 150B | 1MB | 32MB | **400일** | **0.4GB** |
| `farm_point_ledgers` | 34.7만 | 200B | 69MB | 2.1GB | **영구** | 25GB/년 |
| `farm_harvests` | 4.7만 | 150B | 7MB | 0.2GB | **영구** | 2.6GB/년 |
| `farm_quota_audits` | 8,000 | 120B | 1MB | 29MB | **400일** | 0.4GB |
| `farm_users` | +2만(신규) | 200B | 4MB | 0.1GB | **영구** | 1.5GB/년 |
| **합계** | | | **~677MB/일** | **~20.3GB/월** | | **~58GB 상시** |

**보존 정책 근거**

| 테이블 | 보존 | 근거 |
|---|---|---|
| `farm_mission_logs` | **35일** | 월 마감 정산(익월 5일) + 여유. 그 전에 `farm_mission_stats`(일별 집계)로 접어 영구 보존 |
| `farm_planting_days` | **90일** | 7일 코스 + 중단된 밭(최대 60일 방치) + 여유. **수확 판정의 원장이라 짧게 못 잡는다** |
| `farm_mission_holds` | **7일** | 감사용. 홀드는 금전과 무관 |
| `farm_point_ledgers` | **영구** | 지급 이력. 법적 보존 |

**파티셔닝**

| 테이블 | 파티션 | 이유 |
|---|---|---|
| `farm_mission_logs` | ✅ **RANGE `stat_month`(YYYYMM)** | 143만 행/일. `DROP PARTITION`으로 즉시 삭제. unique 제약이 없어 파티션 키 제약을 안 받는다 |
| `farm_planting_days` | ❌ | `unique(planting_id, day_no)`·`unique(farm_user_id, plot_index, work_date)`에 파티션 키를 넣으면 **월 경계를 넘는 7일 코스에서 중복이 허용돼 게임 규칙이 깨진다.** 대신 `chunkById` DELETE 배치 |
| `farm_mission_holds` | ❌ | `unique(farm_user_id, mission_id, stat_date)` 동일 문제. 7일 DELETE |
| `farm_mission_slot_quotas` | ❌ | 연 250만 행. 파티션 불필요 |

🔴 **`farm_mission_logs` 파티셔닝의 전제:** MySQL/MariaDB 파티션 테이블은 **외래키를 쓸 수 없다.** draft §2-4는 `farm_user_id`를 `foreignId()->constrained()`로 선언했다 → **plain `unsignedBigInteger` + index로 바꿔야 한다.** (draft §1-4가 이미 "로그 테이블에 FK 없음"을 원칙으로 세웠으므로 방향은 일치한다.)

파티션 구현은 rankfree `HubPartitionRotate`(`keyword_place_ranks`) 패턴을 그대로 따른다:
```
$t->unsignedBigInteger('id', false)->autoIncrement()->startingValue(1);
...
if (DB::connection()->getDriverName() === 'mysql') {
    ALTER TABLE farm_mission_logs DROP PRIMARY KEY, ADD PRIMARY KEY (id, stat_month);
    ALTER TABLE ... ADD INDEX fml_user (farm_user_id, stat_month);
    ALTER TABLE ... PARTITION BY RANGE (stat_month) (p202607 ..., pmax MAXVALUE);
} else {
    // sqlite: 인덱스만
}
```
파티션 테이블에는 `timestamps()`를 넣지 않는다(rankfree 관례). `created_at` 하나만 둔다.

---

## 10. 배치

### 10-1. 스케줄 표

| 커맨드 | 주기 | 시각(KST) | 하는 일 | 실패 시 방향 |
|---|---|---|---|---|
| `farm:plan-quota` | 매일 | **05:50** | 그날 활성 미션의 `D_eff` 계산 → 7구간 `alloc` `insertOrIgnore` | 런타임 폴백 upsert(carry 0) → **미달만** |
| `farm:rollover-slots` | 매시 정각 | `0 * * * *` | 경계 시각이면 이전 구간 `closed=1` → `carry_in` 이관 | 이전 구간이 안 닫혀 계속 열림 → **분산 실패, 초과 없음** |
| `farm:expire-holds` | 매분 | `* * * * *` | 만료 홀드 `status='expired'` + `held`를 실제 COUNT로 덮어씀 | `held` 과대 → **노출 축소(안전)** |
| `farm:warm-cache` | 매분 | `* * * * *` | C1 목록·C9 후보 ZSET 재생성 + 파일 캐시. **3대 전부 실행** | 캐시 미스 → DB 폴백 |
| `farm:audit-quota` | 매시 | `10 * * * *` | 카운터 ↔ 로그 대조 → `farm_quota_audits` + 알림 | 감지 지연만 |
| `farm:aggregate-stats` | 매일 | **03:00** (휴지 중) | 일별 집계 → `farm_mission_stats` + `farm_missions.total_used` 갱신 | 재실행 멱등 |
| `farm:prune-logs` | 매일 | **04:00** (휴지 중) | `farm_planting_days` 90일·`farm_mission_holds` 7일 `chunkById` DELETE | 스토리지 증가만 |
| `farm:partition-rotate` | 매일 | **05:40** | `farm_mission_logs` 월 파티션 선생성(+2개월) + 35일 초과 `DROP PARTITION` | 신규 월이 `pmax`로 몰려 프루닝 무력화 🔴 |
| `farm:detect-abuse` | 매시 | `25 * * * *` | 신규 계정 IP 클러스터 탐지 → 어드민 플래그 | 탐지 지연만 |
| `farm:flush-cache` | 수동 | — | 캐시 전체 비우기(복구 도구) | — |

### 10-2. 등록법 (`routes/console.php`)

rankfree 관례를 그대로 따른다 — `Schedule::command()` 직접 등록(Console/Kernel.php 없음), 시각 지정은 **반드시** `->timezone('Asia/Seoul')`, 위에 한글 주석으로 목적·시각·의존관계 기재.

```php
/*
| 퀴즈농장(30) — 구간 수량 배분
| 05:50 KST. 06:00 농장일 시작 10분 전에 그날의 미션별 D_eff 와 7구간 alloc 을 미리 만든다.
| 이 배치가 실패해도 런타임이 폴백 upsert 로 행을 만들지만 carry_in 이 0 이라 미달이 커진다.
| 의존: marketing_order_items(status='sent') + 부모 marketing_orders(status='processing')
*/
Schedule::command('farm:plan-quota')
    ->dailyAt('05:50')->timezone('Asia/Seoul')->withoutOverlapping()->runInBackground();

/*
| 퀴즈농장(30) — 구간 롤오버
| 매시 정각. 경계(09/12/14/18/20/22/02시)일 때만 동작한다.
| 🔴 반드시 '이전 구간을 먼저 closed=1 로 닫은 뒤' carry 를 계산한다(설계 §6-5).
|    순서를 바꾸면 in-flight 트랜잭션이 carry 계산에서 빠져 일 한도 초과가 발생한다.
*/
Schedule::command('farm:rollover-slots')
    ->hourly()->timezone('Asia/Seoul')->withoutOverlapping()->runInBackground();

/*
| 퀴즈농장(30) — 홀드 만료 회수
| 매분. held 를 증감이 아니라 '실제 COUNT 로 덮어써' 드리프트를 매분 0 으로 리셋한다.
*/
Schedule::command('farm:expire-holds')
    ->everyMinute()->timezone('Asia/Seoul')->withoutOverlapping()->runInBackground();

/*
| 퀴즈농장(30) — 캐시 워밍
| 매분. ⚠️ withoutOverlapping() 을 붙이지 않는다 — 그 락은 공유 DB(cache_locks)를 쓰므로
|      서버 3대 중 1대만 실행되어 나머지 2대의 파일 캐시가 영원히 낡는다.
|      대신 커맨드 안에서 flock(storage/framework/farm-warm.lock) 로컬 락을 건다.
*/
Schedule::command('farm:warm-cache')
    ->everyMinute()->timezone('Asia/Seoul')->runInBackground();

/*
| 퀴즈농장(30) — 수량 감사
| 매시 10분. slot_quotas.used 와 farm_mission_logs COUNT 를 대조한다.
| diff > 0 (로그가 더 많음) = 진짜 초과 = 금전 손해 → 즉시 잔디 알림.
*/
Schedule::command('farm:audit-quota')
    ->hourlyAt(10)->timezone('Asia/Seoul')->withoutOverlapping()->runInBackground();

// 03:00 / 04:00 / 05:40 은 심야 휴지(02–06) 창이라 참여 트래픽과 경합하지 않는다
Schedule::command('farm:aggregate-stats')
    ->dailyAt('03:00')->timezone('Asia/Seoul')->withoutOverlapping()->runInBackground();
Schedule::command('farm:prune-logs')
    ->dailyAt('04:00')->timezone('Asia/Seoul')->withoutOverlapping()->runInBackground();
Schedule::command('farm:partition-rotate')
    ->dailyAt('05:40')->timezone('Asia/Seoul')->withoutOverlapping()->runInBackground();
Schedule::command('farm:detect-abuse')
    ->hourlyAt(25)->timezone('Asia/Seoul')->withoutOverlapping()->runInBackground();
```

**추가 작업 3가지 (빠뜨리면 안 됨)**

1. **`ScheduleOverviewController::META` 배열에 9개 항목 추가.** 어드민 '자동 수집 현황' 화면이 스케줄 정의를 그대로 읽는다(rankfree 관례).
2. **크론이 3대 모두에 있어야 한다.** 현재 rankfree 크론은 1대(`jcurve` crontab)에만 확인됐다. `farm:warm-cache`만 3대, 나머지는 1대에서만 돌아야 한다 → **크론을 3대에 깔되, `withoutOverlapping()`이 있는 커맨드는 자동으로 1대만 실행된다**(공유 `cache_locks` 덕분). `farm:warm-cache`만 락이 없어 3대 모두 실행된다. 설계상 자연스럽게 해결된다.
3. **`farm:partition-rotate`가 안 돌면 신규 월이 전부 `pmax`로 몰려 프루닝이 무력화된다** — `HubPartitionRotate` docblock에 명시된 함정. `REORGANIZE PARTITION pmax`는 **pmax가 비어 있을 때만 즉시 완료**되므로 반드시 선생성해야 한다.

### 10-3. `farm:plan-quota` 상세

```
$day = FarmDay::current(+1일)            # 05:50 실행이므로 곧 시작할 농장일
$vendorId = config('rankfree.farm.vendor_id')

대상 미션 조회:
  farm_missions
    JOIN marketing_order_items oi ON oi.id = farm_missions.order_item_id
    JOIN marketing_orders o       ON o.id  = oi.order_id
  WHERE farm_missions.is_active = 1
    AND oi.vendor_id = $vendorId
    AND oi.status    = 'sent'
    AND o.status     = 'processing'
    AND $day BETWEEN oi.work_date AND oi.end_date

for each mission (chunkById 500):
    remaining_days = (oi.end_date - $day) + 1
    total_left     = max(0, total_limit_qty - total_used)
    D_eff = min(daily_limit_qty,
                total_left,
                remaining_days > 0 ? ceil(total_left / remaining_days) : total_left)
    if (D_eff <= 0) continue

    $alloc = allocate($D_eff, slots)                    # §6-1
    assert array_sum($alloc) === $D_eff  else Log::error + skip

    rows[] = 7행 (mission, $day, S1..S7, shard 0..shard_count-1)

array_chunk(rows, 500) → insertOrIgnore(farm_mission_slot_quotas)
# 🔴 insert 가 아니라 insertOrIgnore — unique(mission,date,slot,shard) 로 재실행 멱등

첫 구간(S1) 만 opened_at = $day 06:00 으로 세팅
```

**멱등성:** 두 번 실행해도 `insertOrIgnore`가 무시한다. 단 `alloc`이 바뀌었다면(관리자가 세부주문서 수량 수정) 갱신되지 않는다 → **`--force` 옵션으로 `used = 0`인 미래 구간만 UPDATE**하는 경로를 따로 둔다. 이미 소진된 구간의 `alloc`은 절대 건드리지 않는다.

### 10-4. `farm:audit-quota` 상세

```
$dates = [FarmDay::current(), FarmDay::current(-1)]

for each (mission, date, slot) in farm_mission_slot_quotas WHERE stat_date IN $dates:
    [$from, $to] = slot 시각 범위 (KST 절대 시각)
    $logs = COUNT(farm_mission_logs
                  WHERE mission_id = ? AND result = 'correct'
                    AND created_at >= $from AND created_at < $to)
    $diff = $logs - $used
    $sev  = $diff === 0 ? 'ok' : (abs($diff) <= 2 ? 'warn' : 'alert')
    upsert farm_quota_audits(mission, date, slot, counter_used, log_count, diff, severity)

# 일 단위 행 (slot_code = NULL)
for each (mission, date):
    $logsDay = COUNT(... 농장일 전체 범위)
    $D_eff   = SUM(alloc) over 7구간
    $over    = max(0, $logsDay - $D_eff)         # 🔴 청구 못 하는 건수
    upsert farm_quota_audits(mission, date, NULL, SUM(used), $logsDay, ..., limit_qty=$D_eff, over_limit=$over)

if (any severity = 'alert' OR SUM(over_limit) > 0):
    SendJandiFarmAlert::dispatch(요약)
```

---

## 11. 설정 (`config/rankfree.php`)

draft §8-7의 `'farm'` 블록에 아래를 **추가**한다.

```php
'farm' => [
    // ── (draft §8-7 의 기존 키 유지) ────────────────────────
    'plot_count'              => 3,
    'daily_mission_limit'     => 3,
    'submit_cooldown_seconds' => 3,
    'max_attempts_per_day'    => 10,
    // ...

    // ── 시간 축 (설계 §2) ──────────────────────────────────
    'day_start_hour' => 6,                     // 농장일 시작 시각(KST). 자정이 아니다

    // ── 사용자 쿨다운 (설계 §5) ────────────────────────────
    'cooldown_minutes'        => (int) env('FARM_COOLDOWN_MIN', 120),
    'cooldown_jitter_minutes' => 10,           // ±10분 지터
    'ip_daily_limit'          => (int) env('FARM_IP_DAILY', 30),

    // ── 노출 (설계 §8-1) ───────────────────────────────────
    'exposure_limit' => 8,                     // 목록에 내려주는 미션 개수
    'candidate_pool' => 60,                    // ZSET 에서 뽑는 후보 수

    // ── 수량 배분 (설계 §6) ────────────────────────────────
    'quota' => [
        'slots' => [
            ['code' => 'S1', 'from' => '06:00', 'to' => '09:00', 'weight' => 8],
            ['code' => 'S2', 'from' => '09:00', 'to' => '12:00', 'weight' => 13],
            ['code' => 'S3', 'from' => '12:00', 'to' => '14:00', 'weight' => 15],
            ['code' => 'S4', 'from' => '14:00', 'to' => '18:00', 'weight' => 14],
            ['code' => 'S5', 'from' => '18:00', 'to' => '20:00', 'weight' => 14],
            ['code' => 'S6', 'from' => '20:00', 'to' => '22:00', 'weight' => 21],
            ['code' => 'S7', 'from' => '22:00', 'to' => '02:00', 'weight' => 15],
        ],
        'quiet_from'        => '02:00',         // 심야 휴지 시작
        'quiet_to'          => '06:00',         // 심야 휴지 종료
        'carry_cap_ratio'   => 1.0,             // 캐리 상한 = alloc × 이 값
        'last_slot_carry_all' => true,          // S7 은 전량 수용
        'hold_enabled'      => (bool) env('FARM_HOLD', false),   // Phase 2 스위치
        'hold_ttl_minutes'  => 10,
        'shard_threshold_qps' => 50,            // 이 QPS 넘으면 샤딩 검토
    ],

    // ── 캐시 (설계 §7) ─────────────────────────────────────
    'cache' => [
        'redis_enabled'    => (bool) env('FARM_REDIS', false),
        'l1_enabled'       => (bool) env('FARM_APCU', true),
        'ttl' => [
            'mission_list_l1' => 5,    'mission_list_l2' => 60,
            'slot_quota_l1'   => 2,    'slot_quota_l2'   => 3,
            'mission_l1'      => 30,   'mission_l2'      => 300,
            'user_mission_l2' => 86400,
            'state_l2'        => 60,
            'anon_verify_l2'  => 21600,
            'candidate_l1'    => 5,    'candidate_l2'    => 30,
            'ip_daily_l2'     => 300,
        ],
        'breaker_failures' => 5,
        'breaker_cooldown' => 60,
        'connect_timeout'  => 0.2,
        'read_timeout'     => 0.3,
    ],

    // ── 보존 (설계 §9-5) ───────────────────────────────────
    'retention' => [
        'mission_log_days'   => 35,
        'planting_day_days'  => 90,
        'hold_days'          => 7,
        'slot_quota_days'    => 400,
    ],
],
```

`.env` (운영)
```
FARM_COOLDOWN_MIN=120
FARM_IP_DAILY=30
FARM_REDIS=false        # Redis 설치 후 true
FARM_APCU=true
FARM_HOLD=false         # Phase 2 에서 true
```

> ⚠️ 운영은 `config:cache` 상태다. `.env`를 바꾸면 반드시 `php83 artisan config:cache`를 다시 돌려야 한다(rankfree 조사 확인).

**`app_settings` 오버라이드 대상** (`SettingsServiceProvider` 패턴, 어드민에서 조정):
`cooldown_minutes` · `daily_mission_limit` · `exposure_limit` · `ip_daily_limit` · `quota.carry_cap_ratio` · `quota.slots[].weight`

---

## 12. 구현 순서

| 단계 | 내용 | 산출물 | 예상 |
|---|---|---|---|
| **R1** | `FarmDay` 헬퍼 + `farm_users` 컬럼 추가 | `app/Support/FarmDay.php`, 마이그레이션 1 | 반나절 |
| **R2** | `farm_mission_slot_quotas` + `farm:plan-quota` + 배분 알고리즘 | 마이그레이션 1, 커맨드 1, `app/Domain/Farm/QuotaAllocator.php` | 1일 |
| **R3** | 확정 경로에 조건부 UPDATE 삽입 + 락 순서 고정 | `app/Domain/Farm/QuotaGate.php`, `MissionSubmitService` 수정 | 1일 |
| **R4** | 쿨다운 (설정·판정·응답) | `MissionSubmitService`·`FarmMissionController` 수정 | 반나절 |
| **R5** | `farm:rollover-slots` + `closed` 플래그 + 재시도 | 커맨드 1 | 반나절 |
| **R6** | 캐시 계층 (`FarmCache`, L1만 → L2 추가) | `app/Domain/Farm/FarmCache.php`, `config/cache.php` | 1일 |
| **R7** | `farm:warm-cache` + 파일 캐시 + 후보 정렬 | 커맨드 1 | 반나절 |
| **R8** | `farm_quota_audits` + `farm:audit-quota` + 어드민 화면 | 마이그레이션 1, 커맨드 1, blade 1 | 1일 |
| **R9** | 파티셔닝 + `farm:partition-rotate` + `farm:prune-logs` | 마이그레이션 1, 커맨드 2 | 1일 |
| **R10** | 부하 테스트 (k6/ab) → `pm.max_children` 조정 | 리포트 | 반나절 |
| **R11** | (Phase 2) 홀드 + `/go/{id}` 리다이렉트 | 마이그레이션 1, 컨트롤러 1, 커맨드 1 | 1일 |

**R3까지가 최소 안전선.** R3 없이 출시하면 한도 초과가 그대로 금전 손해가 된다.

**테스트 필수 항목 (rankfree 관례: `tests/Feature/`)**

| 테스트 | 검증 |
|---|---|
| `FarmQuotaConcurrencyTest` | 동시 100요청 → `used`가 정확히 `limit`에서 멈춤 |
| `FarmSlotAllocationTest` | 모든 `D_eff`(1~100000)에 대해 `Σ alloc == D_eff` |
| `FarmRolloverTest` | closed 후 이전 구간 UPDATE가 affected=0 |
| `FarmCarryBoundTest` | 7구간 전체 시뮬 → `Σ used ≤ D_eff` (§6-3 증명 검증) |
| `FarmCooldownTest` | 참여 직후 두 번째 참여 거부, 121분 후 허용 |
| `FarmDayBoundaryTest` | 05:59 / 06:00 / 01:59 / 02:00 각각의 `farmDay`·`slot` |
| `FarmCacheFallbackTest` | Redis 예외 시 브레이커 동작 + DB 폴백으로 정상 응답 |

⚠️ 로컬/CI는 sqlite다. 파티션·`insertOrIgnore`의 MySQL 전용 동작은 `DB::connection()->getDriverName()` 분기 + sqlite 폴백 테스트를 함께 작성한다(rankfree 필수 관례).

---

## 부록 A. 다른 영역과 맞물리는 지점 (인터페이스 계약)

| # | 상대 영역 | 내용 | 방향 |
|---|---|---|---|
| **I1** | 스키마 (design-01) | `farm_missions`에 `order_item_id`·`daily_limit_qty`·`total_limit_qty`·`total_used`·`per_user_limit`·`per_user_daily_limit`·`shard_count`·`exposure_weight` 8컬럼 필요 | 본 설계 → design-01 |
| **I2** | 스키마 (design-01) | draft `farm_missions.daily_limit`(1인 1일 한도)와 본 설계 `daily_limit_qty`(미션 일 수량)는 **다른 값이다.** 이름 충돌 → `per_user_daily_limit`으로 개명 요청 | 본 설계 → design-01 |
| **I3** | 스키마 (design-01) | 전체 수량 계산식 확정 필요: `quantity × (end_date − work_date + 1)`인지, `quantity`가 이미 총량인지 | design-01 → 본 설계 |
| **I4** | 스키마 (design-01) | 퀴즈농장 `vendors.id`를 `config('rankfree.farm.vendor_id')`에 고정. 이름 문자열 매칭 금지(name unique 아님) | 공동 |
| **I5** | 게임 규칙 (draft §6) | 🔴 **`work_date`·`todayMissionIds`·`whereDate('created_at')`를 전부 `FarmDay::current()`(06:00 기준)로 교체.** 자정 기준을 남기면 23:50~00:10에 밭 3칸을 2일치 성장시키는 구멍이 남는다 | 본 설계 → draft |
| **I6** | 게임 규칙 (draft §2-4) | `farm_mission_logs`의 `farm_user_id`를 `foreignId()->constrained()`에서 **plain `unsignedBigInteger` + index**로 변경. 파티션 테이블은 FK 불가 | 본 설계 → draft |
| **I7** | 게임 규칙 (draft §3-6) | `REJECT_REASONS`에 `cooldown` · `quota_full` · `closed` · `ip_limit` 4개 추가 | 본 설계 → draft |
| **I8** | 응답 계약 (draft §6-0) | `GET /missions`의 `meta`에 `cooldownUntil` · `closed` · `opensAt` · `slot` · `closingSoon` 추가. **클라가 무시해도 안 깨진다**(쿨다운 표현은 기존 `completed:true`로 폴백) | 본 설계 → 클라이언트 |
| **I9** | 라우트 (draft §4-2) | Phase 2에서 `GET /api/farm/go/{mission}`을 `routes/farm.php`의 **`auth.farm` 그룹 밖**에 추가. 외부 브라우저가 열어 헤더를 못 싣기 때문 | 본 설계 → draft |
| **I10** | 정산 (design-03) | **청구 건수 = `farm_mission_logs`(correct) COUNT**, 카운터가 아니다. **청구 가능 = `min(log_count, D_eff)`**, 초과분 `over_limit`은 청구 불가. 세 값은 `farm_quota_audits`가 제공 | 본 설계 → design-03 |
| **I11** | 정산 (design-03) | `farm_mission_stats`(일별 집계)를 `farm:aggregate-stats`가 만든다. `farm_mission_logs`는 35일 후 삭제되므로 **집계가 영구 원장이다** | 본 설계 → design-03 |
| **I12** | 정산 (design-03) | 미달률 8~12%가 이행률(`marketing_products.default_fulfillment`)에 반영돼야 한다. 이행률 40% 상품은 여유가 있지만 100% 상품은 클레임 대상 | 본 설계 → design-03 |
| **I13** | 사업 (전체) | 🔴 **쿨다운 120분은 DAU당 참여를 2.85 → 2.39회(−16%)로 낮춘다. 하루 100만 건 목표라면 DAU 35만 → 42만이 필요하다** | 본 설계 → 전체 |
| **I14** | 인프라 | Redis 설치(3대) + `pm.max_children` 20 → 40 + `innodb_buffer_pool_size` ≥ 4GB. Redis 없이는 처리 상한이 350 QPS라 100만 건 불가 | 본 설계 → 인프라 |
| **I15** | 인프라 | 크론을 3대 모두에 설치. `withoutOverlapping()`이 공유 `cache_locks`를 쓰므로 대부분 자동으로 1대만 돈다. `farm:warm-cache`만 락 없이 3대 실행 | 본 설계 → 인프라 |
| **I16** | 보안 (draft §8) | 🔴 **포인트를 수확(7일) 시점에 몰아둔 현 설계가 가장 강한 어뷰징 방어다.** 참여 즉시 지급으로 정책을 바꾸면 계정 대량 생성 방어가 통째로 사라진다 | 본 설계 → 정책 |

## 부록 B. 확인 필요 목록

| # | 항목 | 왜 필요한가 |
|---|---|---|
| U1 | 실 서버 3대의 APCu·OPcache 설치 현황 | L1 캐시 전제. 없으면 L2만으로 동작(성능 −30%) |
| U2 | 3대 로드밸런싱 방식 | 세션 없음(헤더 토큰)이라 sticky 불필요하지만 헬스체크 경로가 필요 |
| U3 | 공유 스토리지(NFS) 유무 | 없다고 가정하고 파일 캐시를 서버별 생성으로 설계함. 있으면 단순화 가능 |
| U4 | 카페24 2대의 PHP 버전·확장 | rankfree 서버(PHP 8.3 Remi)와 다르면 배포 파이프라인 분기 필요 |
| U5 | `marketing_order_items.quantity`의 기간형 의미 | 일수량인지 총량인지 (§I3) |
| U6 | 세부주문서 "진행중" 상태의 정확한 값 | `status='sent'` 가정. 운영 실데이터로 확인 필요 |
| U7 | 실제 활성 미션 수 · 미션당 일 한도 분포 | 샤딩 필요 여부·hot row 판정의 입력 |
| U8 | 오답률 (정답률 70% 가정) | 로그 INSERT 부하·스토리지 추정의 입력 |
| U9 | 외부확인형 미션의 링크 클릭 후 이탈률 (40% 가정) | 홀드 TTL·유효 한도 손실 추정의 입력 |
