# 퀴즈농장 × rankfree — 마스터 설계서

> 대상: `C:\Users\jxame\Documents\project\rankfree` (Laravel 13.8 / PHP 8.3 / MariaDB 11.4.2)
> 작성일: 2026-07-28
> 하위 문서: [design-01-schema.md](./design-01-schema.md) · [design-02-runtime.md](./design-02-runtime.md) · [design-03-billing.md](./design-03-billing.md)
> 선행 문서: [rankfree-integration-v1-draft.md](./rankfree-integration-v1-draft.md) · [infra-constraints.md](./infra-constraints.md)
> rankfree 등록 시 파일명: `.claude/28_FARM_MASTER.md` (하위 문서가 29·30·31)

**이 문서의 역할**: 세 영역 설계서를 하나로 묶고, **영역 간 모순을 드러내 해결안을 확정**한다.
상세 근거·수치·SQL은 하위 문서에 있다. 여기서는 요약과 연결, 그리고 **충돌 해소**만 다룬다.

> ⚠️ **§4의 해결안이 하위 문서보다 우선한다.** 하위 문서와 §4가 다르면 §4가 맞다.
> 하위 문서를 구현할 때는 반드시 §4의 치환표를 먼저 적용할 것.

---

## 1. 한눈에 보기

### 1-1. 무엇을 만드는가

rankfree에 광고주 **주문**이 들어오면 일자별 **세부주문서**(`marketing_order_items`)가 생성된다.
퀴즈농장 vendor로 배정된 세부주문서 한 건이 곧 **미션 한 건**이다.

토스 미니앱 사용자는 밭 3칸에 작물을 심고, 하루 한 번씩 밭마다 미션에 참여한다.
미션은 "외부 안내 페이지(광고주 상품 페이지)를 열고 → 정답(가격 등)을 찾아 → 서버에 제출"이다.
정답이면 그 밭이 하루치 자란다. **7일을 채워 수확하면 토스 포인트를 지급**한다.

우리는 **참여 1건당 광고주에게 매출을 인식**하고, **수확 1건당 사용자에게 포인트를 지출**한다.
이 두 시점이 7일 이상 어긋나는 것이 정산 설계 전체의 난이도다.

세 가지 하드 제약이 설계를 지배한다.

| 제약 | 내용 | 어기면 |
|---|---|---|
| **한도** | 세부주문서의 일 주문횟수·전체 수량을 넘으면 안 된다 | 초과분은 청구 불가 = **금전 손해** |
| **쿨다운** | 한 사용자는 참여 후 약 2시간 동안 다른 미션에 참여 불가 | 어뷰징 방어 붕괴 + 시간대 쏠림 |
| **시간대 분산** | 일 한도가 오전에 다 소진되면 안 된다 | 오후 사용자 참여 불가 + 광고 노출 편중 |

여기에 규모가 얹힌다: **하루 100만 참여 / 시도 143만 / 로그 월 4천만 행 / DAU 42만.**

### 1-2. 요청 흐름 (글로 그린 그림)

```
[토스 미니앱]
   │  x-user-key 헤더 (쿠키 세션 불가)
   ▼
[auth.farm 미들웨어]  ─── farm_users 1행 로드 (PK 또는 user_key_hash 직격)
   │                       이 한 번의 쿼리에 쿨다운·오늘참여수·누적포인트가 전부 실려온다
   │                       → 이후 판정에 추가 쿼리 0
   ▼
┌─ GET /missions ─────────────────────────────────────────────┐
│  01. 휴지 시간(02:00–06:00 KST)?  → 빈 목록 + closed:true    │ DB 0 · 캐시 0
│  02. 쿨다운 중?                    → 공용 목록 + locked:true │ DB 0 · 캐시 1
│  03. 오늘 3회 소진?                → 공용 목록 + locked:true │ DB 0 · 캐시 1
│  04. 후보 미션 60개                ← C9 후보 ZSET            │ 캐시 1
│  05. 사용자 개인화 제외            ← C4 사용자×미션 Hash     │ 캐시 1 (미스 시 DB 1)
│  06. 잔여 확인                     ← C2 미션 카운터          │ 캐시 1 (파이프라인)
│  07. 상위 8개 직렬화               ← C6 미션 마스터          │ 캐시 1
└──────────────────────────────────────────────────────────────┘
   전체 요청의 55%가 01~03에서 끝난다 (DB 쿼리 0~1)

┌─ POST /missions/:id/submit ─────────────────────────────────┐
│  A. 사전 게이트 — 여기서는 정답을 절대 보지 않는다           │
│     휴지 → 쿨다운 → 제출간격 → 일 시도상한 → IP상한          │
│     → 일 참여상한 → 대상 밭 → 미션 노출상태 → 사용자×미션    │
│  B. 채점 — 사전 게이트를 전부 통과한 뒤에만                  │
│     오답이면 로그만 남기고 종료. hot row를 건드리지 않는다   │
│  C. 확정 트랜잭션 — 원자 UPDATE 4개 + 로그 INSERT 1개        │
│     ① farm_users        일3회 + 쿨다운 + 5,000P 상한         │
│     ② farm_plantings    밭 하루1회 + day_mask 비트           │
│     ③ farm_user_mission_counters  미션별 반복 상한           │
│     ④ farm_mission_daily_counters 일 한도 + 시간구간 상한    │
│     ⑤ farm_mission_logs INSERT (스냅샷 전량)                 │
│  D. 커밋 후 — 캐시 DEL. 지급 Job은 여기서 안 띄운다          │
└──────────────────────────────────────────────────────────────┘

┌─ POST /harvest ─────────────────────────────────────────────┐
│  🔴 캐시 일절 사용 금지 (금전이 직접 나가는 경로, QPS 0.55)  │
│  BIT_COUNT(day_mask) >= 7 검증 → farm_point_ledgers INSERT   │
│  unique(source, source_id)가 중복 지급 최종 방어선           │
│  커밋 후 FarmPointPayoutJob dispatch                         │
└──────────────────────────────────────────────────────────────┘
```

### 1-3. 캐시 계층

```
읽기:  L1 APCu (서버 로컬, 1~30초)
         ↓ miss
       L2 Redis (공유, 3~86400초, persistence OFF · allkeys-lru · 1GB)
         ↓ miss
       L3 MariaDB (원장)

쓰기:  MariaDB 조건부 원자 UPDATE 만.  Redis 를 절대 거치지 않는다.
무효화: 확정 후 관련 키를 DEL 한다.  SET 으로 갱신하지 않는다.
```

**세 계층의 역할 분담이 이 설계의 뼈대다.**

| 계층 | 담당 | 틀려도 되는가 |
|---|---|---|
| L1/L2 캐시 | 노출 필터·순위·UX | ✅ 틀려도 된다. 초과는 발생하지 않는다 |
| MariaDB 조건부 UPDATE | **한도 판정 그 자체** | ❌ 여기가 유일한 정확성 근거 |
| 집계 테이블 | 정산·통계 | 🔁 로그에서 언제든 재생성 |

캐시를 정확성 근거로 쓰는 순간 사고가 난다. 대조군 계산: Redis 잔여값(TTL 3초)으로 판정했다면 하루 **1.4만 건 초과 = 375원 기준 525만원/일 손해**(design-02 §4-5).

**Redis 없이도 완전 동작한다.** `config('rankfree.farm.cache.redis_enabled')=false`면 L1+파일캐시+DB로 저하 동작하며, 처리 상한이 1,000 → 350 QPS로 떨어질 뿐 **초과는 여전히 0**이다. 다만 하루 100만 건 목표에는 Redis가 필수다.

### 1-4. 3계층 원장 원칙

```
원장(append-only)          →  파생(재생성 가능)           →  표현
─────────────────────────────────────────────────────────────────────
farm_mission_logs (3개월)  →  farm_settlement_daily (36개월) →  정산 화면
farm_point_ledgers (영구)  →  farm_settlement_monthly (영구) →  월 마감
farm_plantings             →  farm_liability_snapshots (영구) →  부채 화면
farm_mission_daily_counters→  farm_mission_slot_stats (90일)  →  분산 검증
```

원장만 살아 있으면 집계는 전부 재생성된다(`farm:rebuild-settlement`).
**단 하나의 예외: `farm_liability_snapshots`는 "과거 시점의 활성 작물 상태"라 로그만으로 복원되지 않는다. 백업 우선순위 1등급.**

---

## 2. 핵심 설계 결정 요약

### 2-1. 다섯 가지 축

| 축 | 결정 | 대안과 기각 근거 | 출처 |
|---|---|---|---|
| **파티셔닝** | `farm_mission_logs`만 **월 RANGE 파티션(`stat_month`), 보관 3개월**. 나머지는 파티션 없음 | ❌ 일별 파티션: 파티션 90개 vs 4개. 핫패스가 로그를 **읽지 않도록** 설계했으므로 프루닝 정밀도가 무의미. 운영 부담만 22배<br/>❌ 물리 테이블 분리: rankfree 선례 0건, INSERT 경로가 월 경계에서 버그 지대<br/>❌ `farm_point_ledgers` 파티션: 주 조회가 `(status, updated_at)` 재시도 스캔이라 전 파티션 탐색이 되어 오히려 느려짐<br/>✅ rankfree에 `keyword_place_ranks` 등 **선례 2건 + `HubPartitionRotate` 완성형** → 복사만 하면 됨 | 01 §3 |
| **원자성 수단** | **조건부 atomic UPDATE** (`WHERE used < limit`) — 락 보유 0.15ms | ❌ Redis `DECR`: 원장 불가. 카페24 Redis 다운 후 "오늘 몇 건 나갔는지" 복원 방법 없음(실사고)<br/>❌ `SELECT … FOR UPDATE`: 락 보유 ~1.0ms(6배). rankfree 선례(`CouponController`)는 초당 1건 미만 경로<br/>❌ check-then-increment(`User::tryConsumeUsage()`): rankfree에 실재하는 **레이스 버그**. 절대 복사 금지<br/>✅ 실용 상한 1,300 QPS vs 미션당 피크 0.29~4.6 QPS = 여유 300배 | 02 D1 |
| **쿨다운 저장** | **`farm_users.cooldown_until` 컬럼 단독. 캐시하지 않는다.** 120분 ± 10분 지터 | ❌ Redis TTL 키: 소실 시 **전원 쿨다운 해제** → 하루 3회가 4시간에 몰려 분산 붕괴<br/>❌ Redis + DB 이중: 어긋날 경로만 늘고 얻는 게 없다<br/>✅ 인증 미들웨어가 **이미 매 요청 `farm_users` 행을 로드**한다 → 추가 쿼리 0, 추가 캐시 조회 0. 캐시할 이유가 애초에 없다<br/>✅ 쓰기 부하 = 참여 확정 시 1회 = 12 write/s | 02 D6 |
| **시간대 분산** | **누적 상한(cumulative cap) 방식.** 구간 카운터 테이블을 만들지 않고, 같은 일 카운터에 "현재 시각까지 허용되는 누적 상한"만 조건으로 건다 | ❌ 구간별 실시간 카운터(`farm_mission_slot_quotas`): UPDATE 2개(일+구간) · 구간 전환 시 **이월 계산 트랜잭션 필요** · 경계에서 두 카운터 불일치 가능 · `closed` 플래그 순서를 틀리면 초과 발생<br/>✅ 누적 상한은 **카운터 1개 · UPDATE 1개 · 이월 로직 0줄**로 같은 결과. 미소진분 이월이 **자동**(상한이 커지면 남은 만큼 그대로 쓸 수 있음)<br/>✅ 구간 경계 동시성이 **원천 소멸** — 카운터를 리셋·이관하지 않으므로 경계 트랜잭션이 존재하지 않는다<br/>✅ `used < daily_quota`가 항상 함께 걸려 총량 초과가 증명 없이 자명<br/>✅ `LAST_INSERT_ID(used+1)`로 **전역 순번(`seq_in_day`)을 얻는다** — 구간 카운터로는 만들 수 없고, 이게 정산 `billable` 판정의 유일한 입력 | 01 §3-3<br/>(→ §4-1 참조) |
| **집계 방식** | **5분 증분 롤업 배치.** 단 참여량·소진·잔여는 카운터 직접 조회로 실시간 | ❌ 참여 트랜잭션 내 실시간 증분: 전사 합계 행(`mission_id=0`)에 하루 100만 UPDATE → **전 참여 트랜잭션이 그 행의 락 대기열에 줄을 선다.** 실질 처리량 천장 초당 60~200건<br/>❌ 일 1회 배치: 운영자가 당일 실적을 못 본다<br/>✅ 5분 배치 입력 = 3,472행, 사이클 총 1.7초 = 5분의 0.6% 점유<br/>✅ 배치는 커서를 되감아 재실행하면 복구된다. 실시간 증분은 어긋난 사실조차 알 수 없다 | 03 §3-1 |

### 2-2. 그 외 확정 사항

| 쟁점 | 결정 | 한 줄 근거 |
|---|---|---|
| 하루의 정의 | **농장일 = KST 06:00 시작** (`FarmDay::current()`) | 자정 기준이면 23:50에 밭 3칸 채우고 00:10에 또 채워 **같은 밭이 20분에 2일치 성장**. 7일 코스가 3.5일로 단축된다 |
| 심야 처리 | **02:00–06:00 노출 중단** | 정상 트래픽 0.8%. 얻는 것은 ①어뷰징 탐지 시간대 소멸 ②**무경합 배치 창 확보**(실질 최대 이득) ③리셋 몰림 소멸 |
| 세부주문서 접근 | **미러 테이블 `farm_missions`.** 런타임에 rankfree 원본을 조회하지 않는다 | 소진량 컬럼 없음 / `regenerate()`가 행을 통째로 삭제 / 표시 정보가 4테이블+JSON에 흩어짐 / 정답 컬럼 없음 / 운영자가 수시 UPDATE |
| 미션 노출 목록 | **60초마다 배치가 스냅샷을 굽고** 각 서버가 APCu 캐싱 | 초당 1,200 읽기 × 4-way JOIN은 불가능 |
| 포인트 지급 시점 | **수확(7일 완주) 1회로 통합.** 참여 시 원장 행을 만들지 않는다 | ①토스 3,000 QPM 한도에서 참여당 지급은 피크 초과 ②원장 100만 → 17만 행/일 ③**"7일 매일 참여해야 지급"이 프로모션 심사 프레이밍과 정확히 일치** ④🔴 **가장 강한 어뷰징 방어** — 계정 1개가 첫 수익까지 21회 참여 × 7일 × 2시간 텀 |
| 5,000P 상한 | `farm_users.accrued_points` **원자 게이트** (같은 UPDATE 문 안) | 원장 SUM + `lockForUpdate`는 RTT 낭비. 적립 시점에 막으면 지급 시점엔 이미 통과 상태 |
| 7일 완주 검증 | `farm_plantings.day_mask` **비트마스크** `BIT_COUNT(day_mask) >= required_days` | 비트가 7개 켜져야 통과 → 같은 날 두 번 넣어 7을 채우는 조작이 원천 차단. `farm_planting_days` 테이블(월 3천만 행) 삭제 |
| 샤딩 | **하지 않는다 (샤드 1개)** | 미션당 피크 0.29 QPS = InnoDB 대기 관측선(500 QPS)의 1,700분의 1. 샤딩은 ①잔여 조회를 SUM으로 만들고 ②"전체는 남았는데 내 샤드는 소진"이라는 **부당 거절(=미달)**을 만들고 ③전역 순번을 파괴해 초과 감지가 깨진다. 재검토 트리거: 미션 하나의 일 `used` 5만 건 |
| 초과 시 사용자 | **구간 상한 초과는 통과시키고, 일 한도 초과만 거절** (§4-8) | 구간 초과는 청구 가능하므로 거절할 이유가 없다. 일 한도 초과만이 진짜 손해 |
| 정산 단가 | `marketing_orders.total_price ÷ 그 주문 세부주문서 quantity 합계` (다른 vendor 회차 포함) | rankfree는 선불 구조 + 이행률(`default_fulfillment` 40%)이 있어 `unit_price` 스냅샷은 매출을 왜곡한다 |
| 청구 기준 | **청구 건수 = 로그(`result='correct' AND billable=1`) COUNT.** 카운터가 아니다 | 카운터는 먹었는데 로그가 없는 경우(미달)에 청구 근거가 없다 |
| `cache_locks` | **MariaDB 고정** (Redis 아님) | Redis 다운 시 락이 풀려 롤업이 3중 실행 → 집계 3배 |
| 신규 커맨드 | **전부 스케줄러 직접 실행, 큐 미사용** | 새 큐 이름을 supervisor `--queue=`에 빠뜨려 "발행 0"이 된 실사고(2026-07-22) 회피 |

---

## 3. 세 문서 인덱스

### 3-1. 어떤 내용을 어디서 보는가

| 알고 싶은 것 | 문서 | 절 |
|---|---|---|
| **DB 스키마 · 인덱스 · 용량** | | |
| 전체 테이블 목록과 컬럼 정의 | 01 | §2 |
| 세부주문서 → 미션 매핑표 (원본 컬럼 → API 필드) | 01 | §1-4 |
| 동기화 SQL (실제 확인된 컬럼명) | 01 | §1-2 |
| 인덱스 도출 근거 (쿼리 17종 → 인덱스, 카디널리티) | 01 | §4-1, §4-2 |
| 만들지 **않는** 인덱스와 그 이유 | 01 | §2-6, §4-3 |
| 파티셔닝 전략 (월별 vs 일별, RANGE vs 물리분리) | 01 | §3-1 ~ §3-2-2 |
| MariaDB 11.4 제약 5가지와 대응 | 01 | §3-4 |
| 파티션 로테이션·아카이빙 자동화 | 01 | §3-5, §3-6 |
| 정산 스냅샷 컬럼 전체 목록 | 01 | §5-2 |
| 마이그레이션 rankfree 관례 체크리스트 | 01 | §6 |
| 참여 확정 트랜잭션 락 순서 | 01 | 부록 |
| **런타임 · 동시성 · 캐시** | | |
| 결정 카드 16개 (D1~D16) | 02 | §0 |
| 농장일·시간 구간 정의 | 02 | §2 |
| 한도 초과 5층 방어 (L0~L4) | 02 | §4-1 |
| 원자성 수단 비교 (락 보유시간 실측) | 02 | §4-2 |
| **초과 경로 전수 열거 A~I와 봉쇄 판정** | 02 | §4-5 |
| 미달률 추정과 대응 우선순위 | 02 | §4-5 |
| 쿨다운 우회 시도와 3층 방어 | 02 | §5-5 |
| 하루 참여 분포 시뮬레이션 (첫 참여 시각별) | 02 | §5-4 |
| 구간 배분 알고리즘 (소액 미션 보호 포함) | 02 | §6-1 |
| `D_eff` — 전체 수량을 일 한도에 흡수 | 02 | §6-2 |
| 캐시 키 표 C1~C12 (자료구조·TTL·무효화) | 02 | §7-2 |
| Redis 운영 설정·서킷브레이커·장애 저하 모드 | 02 | §7-3 ~ §7-5 |
| API별 처리 흐름 의사코드 | 02 | §8 |
| QPS·DB ops·Redis 메모리 추정 | 02 | §9 |
| **정산 · 부채 · 통계 · 화면** | | |
| 네 가지 금액의 정의와 시점 어긋남 | 03 | §1-1 |
| 정산 단가 계산식 (이행률 반영) | 03 | §1-2 |
| 스냅샷 원칙 (언제 찍고 언제 불변인가) | 03 | §1-4 |
| 수수료·부가세·참여 포인트 정책 | 03 | §1-5 |
| **부채 두 종류 (지급대기 vs 미래의무)** | 03 | §2-1 |
| 부채 대상 정의 (d0 제외 근거) | 03 | §2-2 |
| gross vs expected, 진행도별 완주율 | 03 | §2-3, §2-4 |
| 지급 캘린더 ("언제 얼마 나가나") | 03 | §2-6 |
| 방치(중도이탈) 처리 | 03 | §2-7 |
| 롤업 배치 4단계 상세 | 03 | §3-3 |
| 집계 지연 시 화면 표시 규칙 | 03 | §3-6 |
| 정산 화면 지표 SQL 전량 | 03 | §4 |
| 한도 초과분 정산 (기회손실 vs 실손실) | 03 | §5 |
| 관리자 화면 (라우트·대시보드·경고칩) | 03 | §6 |
| config 블록 전문 | 03 | §7 |
| 정산 복구 절차 | 03 | 부록 A |

### 3-2. 하위 문서를 읽을 때 주의

| 문서 | 그대로 믿어도 되는 것 | §4를 먼저 볼 것 |
|---|---|---|
| **01 schema** | 인덱스 근거·파티셔닝·용량·rankfree 관례·스냅샷 목록 | 시간 축(자정 기준), 슬롯 정의, 테이블·컬럼 이름, 롤업 주기 |
| **02 runtime** | 동시성·캐시·부하 추정·쿨다운·어뷰징·API 흐름 | 구간 카운터 테이블(`farm_mission_slot_quotas`), `farm_planting_days`/`farm_harvests` 참조, 포인트 원장 예약 |
| **03 billing** | 금액 체계·단가 계산식·부채·화면·보존 정책 | 참조 테이블 이름(가정값), 로그 보존 개월, 파티션 시각, `unique_users` 집계 |

---

## 4. 영역 간 정합성 검증

세 문서를 대조한 결과 **모순 18건**을 발견했다. 덮지 않고 전부 드러낸다.
각 항목은 `[C번호] 쟁점 → 각 문서의 주장 → 해결안 → 파급`으로 적는다.

---

### C1 🔴 시간대 분산 방식 — 세 문서가 서로 다른 테이블을 가정한다

| 문서 | 주장 |
|---|---|
| **01** §3-3 | 구간 카운터를 **만들지 않는다.** `farm_mission_daily_counters` 하나에 `used < slot_cap` 조건만 추가. 이월 로직 0줄 |
| **02** D2/§3-1 | **`farm_mission_slot_quotas`가 수량 한도의 유일한 원장.** 일 단위 카운터 테이블 없음. `farm:rollover-slots`가 경계마다 `closed=1` → `carry_in` 이관 |
| **03** §3-4 | 분산 영역이 만들 `farm_mission_slot_counters(mission_id, stat_date, slot_no, quota, used)`를 **정산 화면이 직접 읽는다**(가칭) |

**셋 다 다르다. 그리고 이 선택이 나머지 절반의 설계를 결정한다.**

#### ✅ 해결: **01의 누적 상한 방식을 채택한다.**

근거 5가지:

1. **테이블 1개 · UPDATE 1개 · 이월 로직 0줄.** 02는 구간 카운터를 두는 대가로 `farm:rollover-slots` 배치 + `closed` 플래그 + carry 계산 트랜잭션 + affected=0 재시도 로직을 전부 짊어진다.
2. **02 스스로가 §6-5에서 "순서가 정확성을 만든다 — 닫고 나서 읽어야 한다. 읽고 나서 닫으면 in-flight의 `used`가 carry 계산에서 빠져 초과 발생"이라고 경고한다.** 누적 상한에는 그 순서 자체가 존재하지 않는다. **위험을 제거하는 것이 위험을 관리하는 것보다 낫다.**
3. **미소진분 이월이 자동이다.** 09–12시에 10건만 나갔으면 12시 상한 27에서 17건이 그대로 남는다. 02가 §6-3에서 5줄짜리 공식 + 귀납 증명 + "성립 조건 2개"로 보장하는 것을, 누적 상한은 정의상 만족한다.
4. **`used < daily_quota`가 항상 함께 걸린다.** 서버 3대의 시계가 어긋나 `slot_cap`이 잠시 달라도 **총량은 절대 초과하지 않는다.** NTP 동기화가 권장 사항일 뿐 정합성 조건이 아니게 된다.
5. **🔴 결정타 — `seq_in_day`(전역 순번).** 03의 `billable` 판정은 `seq_in_day <= daily_quota`가 유일한 입력이고, 이 값은 `LAST_INSERT_ID(used + 1)`로 일 카운터에서만 얻을 수 있다. **02의 구간 카운터로는 그날 그 미션의 전역 순번을 만들 수 없다.** 02를 채택하면 03의 정산 근간이 무너진다.

#### 02에서 살려서 가져오는 것

| 02의 자산 | 누적 상한 방식으로 이식 |
|---|---|
| 7구간 가중치(8/13/15/14/14/21/15)와 근거 | ✅ 그대로. 단 **농장일 06:00 축으로 재정의**(C2) |
| 소액 미션 보호 (D_eff < 14일 때 균등 배분) | ✅ `slot_cap` 공식에 흡수 (아래) |
| `D_eff` — 전체 수량을 일 한도에 흡수 | ✅ 그대로 채택 (C7) |
| `farm_quota_audits` 감사 테이블 | ✅ 그대로. `slot_code` → `slot_no` |
| S7 미달 대응 (노출 가중 1.3배) | ✅ C9 후보 ZSET score에 반영 |

#### 확정 슬롯 정의 (농장일 06:00 축, `config('rankfree.farm.quota.slots')`)

| slot_no | 시각(KST) | 길이 | 구간 비율 | **누적 상한 비율** | D=50 | D=10 |
|---:|---|---:|---:|---:|---:|---:|
| 0 | 06:00–09:00 | 3h | 8% | **8%** | 4 | 2 |
| 1 | 09:00–12:00 | 3h | 13% | **21%** | 10 | 3 |
| 2 | 12:00–14:00 | 2h | 15% | **36%** | 18 | 5 |
| 3 | 14:00–18:00 | 4h | 14% | **50%** | 25 | 6 |
| 4 | 18:00–20:00 | 2h | 14% | **64%** | 32 | 8 |
| 5 | 20:00–22:00 | 2h | 21% | **85%** | 42 | 9 |
| 6 | 22:00–02:00 | 4h | 15% | **100%** | 50 | 10 |
| — | 02:00–06:00 | 4h | 0% | — (휴지) | — | — |

**`slot_cap` 계산 (애플리케이션 헬퍼 `SlotCap::for($dailyQuota, $now)`)**

```
n = 7 (구간 수), i = 현재 slot_no (0-based)

if (D >= 2n)   slot_cap = MAX( FLOOR(D × cum_ratio[i]), i + 1 )    // 각 구간 최소 1 누적 보장
else           slot_cap = CEIL( D × (i + 1) / n )                   // 소액 미션은 균등 누적
마지막 구간(i = n-1)은 항상 slot_cap = D
```

- `D=10` (< 14): 2, 3, 5, 6, 8, 9, 10 — 첫 구간부터 참여 가능
- `D=3`: 1, 1, 2, 2, 3, 3, 3
- `D=1`: 1, 1, 1, 1, 1, 1, 1 (첫 구간에 소진. 불가피)

**⚠️ 남는 리스크와 판단:** 누적 상한은 캐리 캡이 없어 앞 구간 미소진분이 뒤 구간에 전부 몰릴 수 있다(S6에서 최대 42건 = 84%). 02의 캐리 캡 1.0은 이를 2배 이내로 묶는다.
→ **당장은 받아들인다.** 앞 구간이 안 나갔다는 건 노출이 부족했다는 뜻이고, 그때 뒤에서 몰아 소진하는 것은 **미달 방지 방향**이다. 누적 상한이 막아야 할 것은 "앞으로 당겨쓰기"이며 그것은 완벽히 막힌다.
→ 편차가 실제 문제가 되면 `farm_mission_daily_counters`에 `slot_open_used`(구간 시작 시점 `used` 스냅샷) 1컬럼을 추가해 `slot_cap = MIN(누적상한, slot_open_used + 2 × 구간배정)`으로 확장한다. **Phase 2 과제.**

**파급 (반드시 반영):**
- ❌ `farm_mission_slot_quotas` 테이블 폐기 → 02 §3-1 마이그레이션 삭제
- ❌ `farm:rollover-slots` 커맨드 폐기 → 02 §6-5, §10 삭제
- ❌ `farm_mission_slot_counters` (03 가정) 폐기
- ✅ 구간 실적 통계는 `farm_mission_slot_stats`(01 §2-10) 롤업이 담당. 03 §6-3 탭2의 "시간대 분포"는 **당일 = `farm_mission_logs GROUP BY slot_no` / 과거 = `farm_mission_slot_stats`(90일)**로 조회
- ✅ 02 §4-5 초과 경로 표에서 B(구간 경계 in-flight)·C(캐리 과대 계산)·E(배치 중복 실행) **3개 경로가 소멸**한다

---

### C2 🔴 하루의 정의 — 자정 vs KST 06:00

| 문서 | 주장 |
|---|---|
| **01** | `CURDATE()`, `stat_date`(KST). slot 0 = **00:00–06:00**에 5% 배정, "0으로 막지 않는다" → **자정 기준** |
| **02** D11 | **농장일 = KST 06:00 시작.** 02:00–06:00 노출 중단 |
| **03** | `DATE(created_at)`, `CURDATE()`, 09:30 배치가 "전일 확정 후" → **자정 기준** |

#### ✅ 해결: **02의 농장일(KST 06:00) + 02–06 휴지를 채택한다.**

02의 근거가 결정적이다: 자정 리셋이면 **23:50에 밭 3칸을 채우고 00:10에 다시 3칸을 채워 같은 밭이 20분 만에 2일치 성장**한다. `unique(farm_user_id, plot_index, work_date)`는 날짜가 바뀌므로 못 막고, 7일 코스가 이론상 3.5일로 단축된다. 쿨다운 120분도 이를 완화할 뿐 막지 못한다(23:50 → 01:50).

01의 slot 0(00:00–06:00, 5% 배정)은 **농장일 축과 논리적으로 모순**이다(00:00은 전날 농장일에 속한다). 02의 "심야 휴지"가 이를 대체하며, 부수 효과로 `farm:aggregate-stats`(03:00)·`farm:prune`(04:00)·`farm:partition-rotate`(05:40)가 **무경합 창에서 실행**된다.

**심야 손실 추정:** 02–06시 정상 트래픽 0.8% = 8,000건/일. 상당수가 06:00 이후로 이연되므로 실질 손실 **3,000건/일 이하 = 0.3%.**

**파급 (치환 목록):**

| 위치 | 기존 | 변경 |
|---|---|---|
| draft §6-1/§6-3/§6-4 | `now()->toDateString()` | `FarmDay::current()` |
| 01 §1-3 노출 SQL | `CURDATE()` | `:farmDay` 바인딩 |
| 01 §2-1 UPDATE | `:today` | `:farmDay` |
| 01 §3-3 slot 표 | 00–06 slot 0 (5%) | **위 C1 표로 전면 교체** |
| 03 §3-3 STEP1 | `DATE(created_at) d` | `stat_date` 컬럼 직접 사용 (로그에 이미 있다) |
| 03 §5-5 `farm:check-overage` | `CURDATE() - 1` | `FarmDay::current(-1)` |
| 03 §4-7 실시간 소진 | `c.stat_date = CURDATE()` | `c.stat_date = :farmDay` |

**신규 산출물:** `app/Support/FarmDay.php` — `current(int $offsetDays = 0): string` · `slot(?Carbon $at = null): ?int` · `start(string $day): Carbon` · `isQuiet(): bool`
**테스트 필수:** `FarmDayBoundaryTest` — 05:59 / 06:00 / 01:59 / 02:00 각각의 `farmDay`·`slot`

---

### C3 🔴 테이블 이름이 문서마다 다르다

| 개념 | 01 | 02 | 03 | ✅ 확정 |
|---|---|---|---|---|
| 참여 로그 | `farm_participation_logs` | `farm_mission_logs` | `farm_mission_logs` | **`farm_mission_logs`** |
| 미션×일 한도 카운터 | `farm_mission_daily_counters` | (없음 — 구간 카운터) | `farm_mission_daily_counters`(가정) | **`farm_mission_daily_counters`** |
| 구간 카운터/통계 | `farm_mission_slot_stats`(롤업) | `farm_mission_slot_quotas`(원장) | `farm_mission_slot_counters`(가정) | **`farm_mission_slot_stats`** (롤업 전용, C1) |
| 미션×일 집계 | `farm_mission_daily_stats` | `farm_mission_stats` | `farm_settlement_daily`(mission_id>0) | **`farm_settlement_daily`** |
| 전사 일별 집계 | `farm_daily_stats` | — | `farm_settlement_daily`(mission_id=0) | **`farm_settlement_daily`** |
| 참여일 상세 | `farm_planting_days` **삭제** | 사용 중 | 사용 중 | **삭제** (C4) |
| 수확 | `farm_harvests` **삭제** | 사용 중 | 사용 중 | **삭제** (C4) |
| 파티션 키 | `stat_month` | `stat_month` | `created_month` | **`stat_month`** |

**로그 이름을 `farm_mission_logs`로 정한 이유:** 02·03이 이 이름으로 SQL을 작성했고, 클라이언트/도메인 용어("미션")와 일치한다. 컬럼은 **01의 스냅샷 전량 + 03의 정산 컬럼**을 합친다.

**집계 테이블을 `farm_settlement_daily` 하나로 통합한 이유:** 01의 `farm_daily_stats` + `farm_mission_daily_stats`와 03의 `farm_settlement_daily`(mission_id=0 전사행 규약)는 컬럼이 90% 겹친다. 03이 월 마감·정정·이중계상 방지 스코프까지 설계했으므로 03을 원본으로 삼는다.
→ ⚠️ 01은 `farm_mission_daily_stats`를 **무기한** 보관해 "정산의 법적 원장"으로 삼았으나 `farm_settlement_daily`는 36개월이다. **`farm_settlement_monthly`(영구) + `farm_settlement_adjustments`(영구)가 그 역할을 대신하므로 문제없다.**

**파티션 키를 `stat_month`로 정한 이유:** 농장일 축(06:00 기준)에서는 `created_at`의 월과 `stat_date`의 월이 월말 자정~06:00 구간에 어긋난다. `created_month`라는 이름은 그 오해를 부른다.

---

### C4 🔴 `farm_planting_days` / `farm_harvests` — 01은 삭제, 02·03은 사용

01 §2-0은 두 테이블을 삭제하고 **월 3천만 행 + 연 5,100만 행**을 절감했다.
그런데 02·03이 곳곳에서 이들을 참조한다.

#### ✅ 해결: **01의 삭제를 확정하고, 02·03의 참조를 전부 치환한다.**

| 위치 | 기존 | 치환 |
|---|---|---|
| 02 §8-3 06 (일 참여 상한 판정) | `COUNT(farm_planting_days WHERE farm_user_id, work_date=$day) >= 3` | `farm_users` 원자 UPDATE의 `(today_date <> :farmDay OR today_count < :daily_limit)` 조건 (C6) |
| 02 §8-3 e (INSERT) | `INSERT farm_planting_days` | `farm_plantings` day_mask 원자 UPDATE (01 §2-8) |
| 02 §8-3 11-b (재검증) | planting_days 재조회 | 불필요 — 원자 UPDATE가 곧 재검증 |
| 02 §8-4 d (7일 검증) | `COUNT(farm_planting_days) >= required_days` | `BIT_COUNT(day_mask) >= required_days` (sqlite는 `completed_days`) |
| 02 §8-4 e/g (중복 수확 방지) | `farm_harvests` EXISTS + `unique(planting_id)` | `farm_point_ledgers.unique(source, source_id)` |
| 02 §9-5 (보존) | `farm_planting_days` 90일 / `farm_harvests` 영구 | 두 행 삭제 |
| 02 §4-1 L3 방어층 | `farm_planting_days` unique 2개 | `farm_plantings` day_mask 비트 조건 + `unique(farm_user_id, plot_index, round_no)` |
| 03 §2-4 완주율 SQL | `LEFT JOIN farm_harvests` + `IN (SELECT … farm_planting_days)` | `farm_plantings.status='harvested'` + `(day_mask >> (P-1)) & 1 = 1` |
| 03 §3-3 STEP3 `harvest_cnt` | `farm_harvests WHERE DATE(harvested_at)=d` | `farm_plantings WHERE status='harvested' AND DATE(harvested_at)=d` |
| 03 §3-3 STEP3 `liability_added_krw` | `farm_planting_days WHERE day_no=1` JOIN | `farm_mission_logs WHERE result='correct' AND day_no=1 AND stat_date=d` JOIN `farm_plantings.reward_points` |
| 03 §2-6 실측 소요일 | `farm_harvests` 기준 | `farm_point_ledgers WHERE source='harvest'`의 `first_day_on`/`last_day_on` |
| 03 부록 A 복구 불가 항목 | `farm_planting_days` + `farm_harvests`로 역산 | `farm_mission_logs`(3개월) + `farm_plantings`로 역산 |

**`day_mask` 비트마스크가 두 테이블보다 강한 이유:** 7일 검증이 `BIT_COUNT(day_mask) >= 7`이므로 **비트가 7개 켜져야** 통과한다. 같은 날 두 번 넣어 카운터만 올리는 조작이 원천 차단된다(`completed_days` 단독 카운터보다 강하다). 02가 "`completed_days`(캐시)를 절대 믿지 않는다"고 경계한 그 위험이 사라진다.

---

### C5 🔴 포인트 정책 — 세 문서가 다른 말을 한다 (일부는 용어 문제)

| 문서 | 주장 |
|---|---|
| **01** | `farm_missions.payout_point` = 참여당 적립. **원장 행은 만들지 않고** `farm_plantings.accrued_points` + `farm_users.accrued_points` 원자 증가만 |
| **02** §8-3 h, §9-3 | 확정 트랜잭션에서 `PointLedgerService::reserve()` 호출. "참여 포인트 있을 때만 INSERT (~30%)" |
| **03** §1-5 | `farm_missions.points`는 **0 고정 + 폼 잠금.** 하루 100만 × 10P = 1,000만원/일 즉시 유출 |

#### ✅ 해결: **용어를 3개로 분리하면 실질 충돌은 02 하나뿐이다.**

| 용어 | 값 | 저장 | 지급 시점 |
|---|---|---|---|
| **즉시 지급 포인트** (draft `farm_missions.points`) | — | **컬럼 자체를 만들지 않는다** | 없음 |
| **참여 적립** `farm_missions.payout_point` | 운영 설정 가능 | `farm_plantings.accrued_points` + `farm_users.accrued_points` 원자 증가. **원장 행 없음** | 수확 시 합산 |
| **수확 보너스** `farm_crops.points` → `farm_plantings.reward_points` | 작물별 (500P 가정) | 심을 때 스냅샷 | 수확 시 |

→ 03이 막으려던 것은 **즉시 지급**이고, 01의 `payout_point`는 **적립**이다. 컬럼 이름과 개념을 분리하면 둘은 양립한다.
→ 🔴 **02 §8-3 h의 `PointLedgerService::reserve()` 호출을 삭제한다.** 참여 경로는 원장을 만들지 않는다.
→ 02 §9-3 표의 "6. INSERT `farm_point_ledgers`(~30%)" 행 삭제 → **참여 1건당 쓰기 5.3 → 4.3 ops** (홀드 제외 시 4.0)

**이 결정이 방어하는 것:** 어뷰징 방어의 핵심은 "계정 1개가 첫 수익을 보려면 7일 × 3회 = 21회 참여 + 하루 4시간 구속"이라는 구조다. 참여 즉시 지급으로 바꾸면 **이 방어가 통째로 사라진다.** 정책 변경 시 반드시 재검토(02 §5-5 ③).

**수확 시 원장 1건에 담기는 금액:**
```
amount = accrued_amount (= farm_plantings.accrued_points, 7일치 참여 적립 합)
       + crop_amount    (= farm_plantings.reward_points,  작물 수확 보너스)
```

---

### C6 사용자 일 3회 상한 — 권위가 어디인가

| 문서 | 주장 |
|---|---|
| **01** | `farm_users.today_count` **원자 UPDATE가 권위** |
| **02** §3-4, §7-2 C3 | `today_count`는 **캐시**. "판정은 로그" / "확정 트랜잭션에서 `farm_planting_days` COUNT로 재검증" |

#### ✅ 해결: **`farm_users.today_count`가 권위다.**

02의 "캐시라 틀려도 안전하다"는 전제는 `farm_planting_days`가 살아 있을 때만 성립한다. C4로 그 테이블이 사라졌으므로 대체 원장이 없다.
그리고 01의 방식이 구조적으로 우월하다 — **일 상한 · 쿨다운 · 5,000P 상한 3개 규칙이 한 UPDATE 문의 WHERE 절에 들어간다.** 테이블을 나누면 트랜잭션 3개 + 데드락 위험이다.

```sql
UPDATE farm_users
   SET today_count          = IF(today_date = :farmDay, today_count + 1, 1),
       today_date           = :farmDay,
       cooldown_until       = DATE_ADD(NOW(), INTERVAL :cooldown_min MINUTE),
       last_participated_at = NOW(),
       total_participations = total_participations + 1,
       accrued_points       = accrued_points + :payout_point,
       daily_ip             = :ip
 WHERE id = :farm_user_id
   AND status = 'active'
   AND (today_date <> :farmDay OR today_count < :daily_limit)   -- 일 3회
   AND (cooldown_until IS NULL OR cooldown_until <= NOW())      -- 2시간 쿨다운
   AND accrued_points + :payout_point <= :point_cap             -- 5,000P
-- affected_rows = 1 → 통과. 0 → 거절 (사유는 후속 SELECT 1회로 구분)
```

**파급:** 02 §7-2의 C3 항목 설명 수정("판정은 로그" → "판정은 이 컬럼의 원자 UPDATE"). `farm:rebuild-state`(draft 부록 A)는 로그로 이 컬럼을 정정하는 도구로 유지.

---

### C7 전체 수량 게이트 — 런타임 UPDATE vs `D_eff` 흡수

| 문서 | 주장 |
|---|---|
| **01** 부록 4단계 | `farm_missions.total_used < total_quota` 조건부 UPDATE를 트랜잭션 안에서 |
| **02** §6-2 | 런타임에 두지 않고 `D_eff`에 흡수. `total_used`는 **일 마감 배치가 갱신, 런타임에 쓰지 않는다** |

#### ✅ 해결: **02의 `D_eff` 흡수를 채택한다.**

```
D_eff = min( daily_quota,
             total_left,
             remaining_days > 0 ? ceil(total_left / remaining_days) : total_left )
total_left    = max(0, total_quota - total_used)      -- total_used는 전일 마감 배치값
remaining_days = (ends_on - farmDay) + 1
```

세 가지 이득:
1. **hot row가 하나 줄어든다.** 미션당 UPDATE 2개(일 카운터 + 미션 마스터) → 1개. 락 순서 고정 부담도 사라진다.
2. **런타임 UPDATE가 1개로 유지**되어 트랜잭션 P95가 짧아진다.
3. **기간형 계약의 취지를 지킨다.** 전체 700개·7일 미션에서 첫날 700을 다 소진하면 나머지 6일 노출이 0이다. 세 번째 항(`ceil(total_left / remaining_days)`)이 이를 평활한다.

**⚠️ 잔여 리스크:** `total_used`가 전일 마감값이므로 마지막 날 `total_quota`를 최대 하루치 초과할 수 있다.
→ 보정: `farm:plan-quota`가 매일 05:50에 `D_eff`를 계산할 때 **`total_used`를 그 시점 실측으로 갱신한 뒤 계산**한다(`SUM(used) FROM farm_mission_daily_counters WHERE mission_id=?`). 하루 안에서는 `D_eff` 자체가 상한이므로 추가 초과가 없다.

**파급:** 01 부록의 6단계 → **5단계**로 축소 (아래 C18).

---

### C8 🔴 한도 초과 시 사용자를 거절하는가

| 문서 | 주장 |
|---|---|
| **02** §8-3 c | 슬롯 확보 실패 → `rejected('quota_full')`. "정답을 맞혔지만 슬롯 없음. 사용자 보상 X, 광고주 청구 X → **금전 손해 0**" |
| **03** §5-2 | 🔴 **"초과라고 사용자를 거절하지 않는다."** 이미 정답이 노출된 뒤라 재시도 불가 + CS 폭증 + 토스 심사 다크패턴. 성공 처리하고 `billable=false`로만 표시, **손실은 우리가 흡수** |

정면 충돌이며 **사업 판단**이 걸린 항목이다. 02는 손해 0을 지키고, 03은 사용자 경험과 심사 리스크를 지킨다.

#### ✅ 해결: **2단 UPDATE로 둘 다 지킨다 — 구간 상한 초과는 통과, 일 한도 초과만 거절.**

```sql
-- 1차: 구간 상한 + 일 한도를 모두 만족
UPDATE farm_mission_daily_counters
   SET used = LAST_INSERT_ID(used + 1), last_used_at = NOW(),
       first_used_at = COALESCE(first_used_at, NOW())
 WHERE mission_id = :m AND stat_date = :farmDay
   AND used < daily_quota
   AND used < :slot_cap;
-- affected = 1 → 정상 확정. billable = true, slot_overflow = false

-- 2차 (1차 affected = 0일 때만): 구간 상한은 넘었지만 일 한도 안
UPDATE farm_mission_daily_counters
   SET used = LAST_INSERT_ID(used + 1), last_used_at = NOW(),
       slot_overflow_count = slot_overflow_count + 1
 WHERE mission_id = :m AND stat_date = :farmDay
   AND used < daily_quota;
-- affected = 1 → 확정. billable = true, slot_overflow = true  ← 🔴 청구 가능하므로 거절할 이유가 없다
-- affected = 0 → 일 한도 소진. rejected('quota_full')          ← 진짜 손해가 나는 경우만 거절
```

**왜 이게 최선인가:**
- 구간 상한 초과는 **시간대 분산 실패일 뿐 청구는 가능**하다. 사용자를 거절하면 UX만 잃고 얻는 게 없다. 03의 우려(정답 노출 후 거절)가 여기서 해소된다.
- 일 한도 초과만이 진짜 손해이고, 이 경우는 **노출 필터가 이미 걸러서 경합 건만 남는다**(하루 수십~수백 건).
- 02가 §6-5에서 설계한 "구간 경계 affected=0 → slot 재계산 후 1회 재시도"가 **불필요해진다.** 2차 UPDATE가 그 역할을 대신하고, 재시도 루프가 없으므로 무한 순회라는 초과 경로도 없다.
- 03의 `billable` 판정 입력(`seq_in_day`)은 `LAST_INSERT_ID()`로 그대로 얻는다.

**신규 컬럼:** `farm_mission_daily_counters.slot_overflow_count` (unsignedInteger, 기본 0) — 시간대 분산이 얼마나 실패했는지의 유일한 지표. `farm_mission_slot_stats.rejected_full`을 대체한다.

**신규 로그 컬럼:** `farm_mission_logs.slot_overflow` (boolean) — 분산 튜닝의 입력.

**⚠️ 그럼에도 일 한도 초과를 흡수해야 한다면:** `config('rankfree.farm.overflow_policy')` = `reject`(기본) | `absorb`. `absorb`면 2차 UPDATE의 `used < daily_quota` 조건을 제거하고 `billable=false`로 확정한다. **초기값은 `reject`.** 실측 거절률(`reject_reason='quota_full'`)이 참여의 0.5%를 넘으면 `absorb` 전환을 검토한다(02는 이를 2.1%로 추정했다 — 실측이 나오기 전에는 판단하지 않는다).

---

### C9 정산 단가 계산식

| 문서 | 주장 |
|---|---|
| **01** §1-4 | `marketing_orders.unit_price`를 `farm_missions.revenue_unit_price`에 스냅샷 |
| **03** §1-2 | `marketing_orders.total_price ÷ SUM(marketing_order_items.quantity)` (**다른 vendor 회차 포함**) → `farm_missions.unit_revenue` |

#### ✅ 해결: **03의 계산식과 컬럼명 `unit_revenue`를 채택한다.**

rankfree는 선불 구조다. 광고주는 이미 `total_price`를 결제했고, 우리 "청구"는 **이미 받은 돈을 이행 실적에 따라 매출로 인식**하는 행위다.
여기에 `default_fulfillment`(40%) 구조가 겹친다 — 광고주는 225,000원(=300×5일×150원)을 냈는데 세부주문서는 일 120건 × 5회차 = 600건만 발주된다. `unit_price`(150원)를 쓰면 **매출을 60% 과소 인식**한다. 실제 단가는 225,000 ÷ 600 = **375원**이다.

**분모에 다른 vendor 회차를 포함하는 이유:** 세부주문서가 이미 vendor별로 쪼개져 있으므로 퀴즈농장 회차만 세면 단가가 부풀려진다.

**2중 스냅샷:** `farm_missions.unit_revenue`(미션 생성 시) → `farm_mission_logs.unit_revenue`(참여 확정 시). 운영자가 주문 수량을 도중에 고쳐도 **이미 발생한 참여의 정산가는 바뀌지 않는다.**

**파급:** 01 §1-4 매핑표·§2-3·§2-6·§2-10의 `revenue_unit_price` → **`unit_revenue`**. 01 §0의 "참여 단가 150원" → **375원(예시)**. 초과 손해 추정치가 2.5배로 커진다(하루 100건 초과 = 15,000원 → **37,500원**).

---

### C10 `farm_missions` 컬럼명 — 같은 것에 세 가지 이름

| 개념 | 01 | 02 | 03 | ✅ 확정 |
|---|---|---|---|---|
| 일 주문횟수 | `daily_quota` | `daily_limit_qty` | `daily_limit`(§5-2) | **`daily_quota`** |
| 전체 수량 | `total_quota` | `total_limit_qty` | `planned_quantity` | **`total_quota`** |
| 누적 소진 | `total_used` | `total_used` | — | **`total_used`** |
| 동일 사용자 총 상한 | `user_mission_cap` | `per_user_limit` | — | **`per_user_limit`** |
| 동일 사용자 일 상한 | `user_daily_cap` | `per_user_daily_limit` | — | **`per_user_daily_limit`** |
| 청구 단가 | `revenue_unit_price` | — | `unit_revenue` | **`unit_revenue`** (C9) |
| 참여 적립 포인트 | `payout_point` | — | `points`(0 고정) | **`payout_point`** (C5) |
| 노출 시작/종료 | `starts_on` / `ends_on` (date) | `work_date` / `end_date` | `starts_at` / `ends_at` | **`starts_on` / `ends_on`** |
| 활성 여부 | `status`(draft/active/paused/ended/canceled) | `is_active`(boolean) | `status` | **`status`** (5값) |
| 광고주 | — | — | `advertiser_user_id` | **`advertiser_user_id`** |
| 샤드 수 | — | `shard_count` | — | **만들지 않는다** (샤딩 불채택) |
| 노출 가중 | — | `exposure_weight` | — | **`exposure_weight`** |

> 🔴 **02 I2가 지적한 이름 충돌은 실재한다.** draft의 `farm_missions.daily_limit`(1인 1일 한도)과 미션 일 수량은 완전히 다른 값이다. 위 표대로 `per_user_daily_limit` / `daily_quota`로 분리하면 해소된다.

> `is_active`(02)를 버리고 `status`(5값)를 택한 이유: 미션은 `draft`(정답 미입력) 상태가 반드시 필요하다. boolean으로는 "생성됐지만 노출 불가"를 표현할 수 없고, 그게 03 §6-3의 최우선 경고 칩("정답 미입력 = 매출 0")이다.
> → 02 §8-3 08·§10-3의 `farm_missions.is_active = 1` → `status = 'active'`

---

### C11 로그 보존 기간 — 3개월 vs 35일 vs 6개월

| 문서 | 보존 | 행 크기 가정 | 상시 용량 |
|---|---|---|---|
| **01** | 3개월 | 168B | 27GB |
| **02** | 35일 | 240B | 12GB |
| **03** | 6개월 | 300B | 81GB |

#### ✅ 해결: **3개월 (`config('rankfree.farm.retention.log_months', 3)`)**

- **35일(02)은 위험하다.** 월 마감이 익월 5일이고 광고주 이의 제기 관행이 익월 말이다. 6월 15일 건을 8월 초에 조회하면 이미 없다.
- **6개월(03)의 81GB는 디스크 여유가 미확인인 상태에서 감당 못 한다.** 03 스스로 §10-4에 "확인 필요"로 남겼다.
- **3개월이면 광고주 이의 제기 실무 관행을 1개월 이상 초과 커버**하고, 정산 재현은 `farm_settlement_daily`(36개월) + `farm_settlement_monthly`(영구)가 담당한다.
- 행 크기는 01의 스냅샷 컬럼 + 03의 정산 컬럼을 합친 뒤 재산정: **약 200B(데이터) + 90B(인덱스 2개) = 290B** → 하루 143만 행 × 290B = **415MB/일 · 12.5GB/월 · 3개월 37GB**.

**아카이빙:** 파티션 DROP 직전 `storage/app/farm/archive/mission_logs_{YYYYMM}.tsv.gz` (약 750MB/월, 압축 8:1). **행 수 검증 불일치 시 DROP 하지 않고 중단 + 알림.**

---

### C12 미션 동기화 주기 — 5분 vs 매일 08:00

| 문서 | 주장 |
|---|---|
| **01** §1-1, §5-4 | **5분마다 증분 upsert.** "초과가 발생하는 경로는 하나뿐 — 동기화 지연. 한도를 50→30으로 낮췄는데 미러가 아직 50인 5분 창" |
| **03** §6-4 | `farm:sync-missions` **매일 08:00.** 신규 미션 `draft` 자동 생성 + 정답 미입력 알림 |

#### ✅ 해결: **역할을 나눠 셋 다 돌린다.**

| 커맨드 | 주기 | 역할 |
|---|---|---|
| `farm:sync-missions --incremental` | **5분** | `updated_at >= :since` 증분 upsert. **한도 변경 반영이 유일한 목적** |
| `farm:sync-missions` | **매일 08:00** | 전량 대조 + 신규 `draft` 생성 + 단가 스냅샷 + 정답 미입력 잔디 알림 |
| **rankfree 어드민 저장 훅** | 즉시 | `admin.orders.items.update` 저장 시 해당 `order_id`만 즉시 재동기화 |

🔴 **어드민 저장 훅이 없으면 5분 창이 남고, 그것이 초과의 유일한 경로다.** 훅 없이는 한도 하향이 최대 5분 늦게 반영된다.

**동기화가 한도를 낮출 때:** `used > 새 daily_quota`이면 그 시점 `overflow_count = used - 새 quota`를 `farm_mission_daily_counters`에 기록하고, 그날 이후 참여는 전부 `billable=false`가 된다(`seq_in_day > daily_quota`).

---

### C13 롤업 주기 — 일 1회 vs 매일 03:00 vs 매 5분

| 문서 | 주장 |
|---|---|
| **01** | 일 마감 롤업 (부채는 00:30) |
| **02** | `farm:aggregate-stats` 매일 03:00 |
| **03** | `farm:rollup-stats` **매 5분** + 매시 deep(72h) |

#### ✅ 해결: **역할 분리 — 5분 증분(03) + 일 마감 확정(01·02)**

| 커맨드 | 주기 | 역할 |
|---|---|---|
| `farm:rollup-stats` | **매 5분** | 수입·참여량 증분(커서) + 지출 24h 재계산 + 시간대 + 부채 flow (03 §3-3) |
| `farm:rollup-stats --deep` | 매시 00분 | 지출 72h 재계산 |
| `farm:aggregate-stats` | **매일 03:00** (휴지 중) | 🔴 **5분 배치가 못 하는 것만**: ①`unique_users` 전량 재계산(C17) ②`farm_mission_slot_stats` 구간 실적 ③`farm_missions.total_used` 갱신 ④부채 항등식 검증 |
| `farm:snapshot-liability` | 매일 **03:20** | 전일 부채 스냅샷 (03은 00:20 → **농장일 마감 02:00 이후로 이동**, C2 파급) |

**⚠️ 03의 "00:20 스냅샷" 근거("자정 직전 참여가 커밋되기를 20분 기다린다")는 농장일 채택 시 무효다.** 농장일은 02:00에 끝나므로 **03:20**이 맞다.

---

### C14 `farm_users` 마이그레이션 중복

01 §2-1이 테이블 생성 시 `cooldown_until` · `today_count` · `today_date` · `last_participated_at`을 이미 포함하는데,
02 §3-4가 같은 컬럼을 **ALTER로 추가**하는 마이그레이션(`101300_add_cooldown_to_farm_users`)을 정의했다.

#### ✅ 해결: **02의 ALTER 마이그레이션에서 `daily_ip` 하나만 남긴다.**

나머지 4개는 01의 `create_farm_users_table`에 포함된다. 그대로 두면 마이그레이션이 중복 컬럼 에러로 실패한다.

**인덱스도 충돌:** 01은 "`cooldown_until` 인덱스를 만들지 않는다"(조회가 항상 PK 직격), 02는 "`index(['cooldown_until'],'fu_cooldown')` 어드민 통계용".
→ ✅ **만들지 않는다(01).** 어드민 "쿨다운 중 사용자 수"는 실시간 필요가 없고, 필요하면 `farm_settlement_daily`에서 파생한다. 초당 58 UPDATE를 받는 테이블에 자주 변하는 컬럼의 인덱스를 다는 것은 쓰기 증폭이다.

---

### C15 쿨다운 중 목록 표현 — `locked` vs `completed`

| 문서 | 주장 |
|---|---|
| **01** §2-1 | `locked: true` + `unlockAt`(ISO8601) + `lockReason` — **신규 필드** |
| **02** D8 | 전부 `completed: true` + `meta.cooldownUntil` — **클라이언트 수정 0** |

#### ✅ 해결: **둘 다 내려준다. 상호 배타가 아니다.**

```json
{
  "missions": [ { "...": "...", "completed": true, "locked": true,
                  "unlockAt": "2026-07-28T16:20:00+09:00", "lockReason": "cooldown" } ],
  "meta": { "remaining": 0, "dailyLimit": 3,
            "locked": true, "unlockAt": "2026-07-28T16:20:00+09:00",
            "lockReason": "cooldown", "cooldownUntil": "2026-07-28T16:20:00+09:00",
            "slot": 3, "closed": false }
}
```

- **클라 수정 전:** `completed:true`가 기존 잠금 UI를 그린다 → 즉시 배포 가능
- **클라 수정 후:** `locked`/`unlockAt`으로 **남은 시간 카운트다운**을 표시 → 재방문 유도

두 문서의 UX 판단은 동일하다: **숨기면 "미션이 없다"고 오해해 이탈한다.** 밭 3칸 × 2시간 텀 = 최소 4시간 재방문 유도가 이 기능의 목적인데, 숨기면 그 트리거가 사라진다.
DB 관점 추가 비용 **0** — `farm_users.cooldown_until` 한 컬럼으로 응답이 조립된다.

**`lockReason` 값:** `cooldown` / `daily_limit` / `closed`(휴지) / `mission_cap`

---

### C16 핫패스에서 로그 테이블을 읽는 코드가 남아 있다

02 §8-3의 사전 게이트 두 곳이 `farm_mission_logs`를 조회한다.

| 위치 | 기존 | 문제 | 치환 |
|---|---|---|---|
| §8-3 03 (제출 간격 3초) | `마지막 farm_mission_logs.created_at > now - 3s` | 1.2억 행 테이블을 요청마다 조회 | `farm_users.last_submit_at` 컬럼 추가 |
| §8-3 04 (일 시도 상한 10) | `COUNT(farm_mission_logs WHERE farm_user_id, created_at >= dayStart)` | 날짜 필터가 인덱스에 없어 사용자 전체 이력 스캔 | `farm_users.today_attempts` + `today_date` 컬럼 |

01 §4-1의 설계 원칙("핫패스가 로그를 읽지 않도록 설계했다")과 정면으로 어긋난다. **`farm_users` 컬럼 2개 추가로 해소한다** — 이 행은 인증 단계에서 이미 로드되므로 추가 쿼리 0이다.

`today_attempts`는 오답·거절 포함 시도 수이므로 **확정 트랜잭션 밖에서도 증가**해야 한다(거절 로그와 같은 타이밍, 별도 UPDATE 1회).

---

### C17 🔴 `unique_users`를 5분 증분으로 누적하면 틀린다 (03 내부 결함)

03 §3-3 STEP1이 `COUNT(DISTINCT IF(result='correct', farm_user_id, NULL))`를 계산한 뒤 `farm_settlement_daily`에 **`+=` 누적**한다.

**같은 사용자가 09:00과 14:00에 참여하면 5분 배치 두 사이클에 각각 잡혀 2로 세어진다.** 하루 100만 참여 / DAU 42만이면 `unique_users`가 실제의 최대 2.4배로 부풀어 오른다.
그리고 `참여자수`는 정산 화면 KPI 상단에 노출되는 값이다.

#### ✅ 해결: **`unique_users`는 5분 배치에서 채우지 않고, 일 마감 배치가 하루치를 전량 재계산한다.**

```
farm:rollup-stats (5분)        : unique_users 를 건드리지 않는다 (NULL 유지)
farm:aggregate-stats (03:00)   : 전일 농장일 전체를 한 번에
    SELECT stat_date, mission_id, COUNT(DISTINCT farm_user_id)
      FROM farm_mission_logs
     WHERE stat_date = :yesterdayFarmDay AND result = 'correct'
     GROUP BY stat_date, mission_id
    → farm_settlement_daily.unique_users 덮어쓰기 (전사행은 mission_id 없이 한 번 더)
```

**화면 처리:** 당일 행의 `unique_users`는 `—`로 표시하고 툴팁 "고유 참여자는 하루 마감 후 확정돼요." 03 §3-6의 ⚡/🕐 아이콘 규칙에 **🌙(일 마감)** 아이콘을 하나 더 추가한다.

**같은 결함을 가진 다른 지표:** 없음. 나머지는 전부 SUM/COUNT라 증분 누적이 정확하다.

---

### C18 확정 트랜잭션 락 순서 — 두 문서가 다른 순서를 제시

| 01 부록 | 02 §4-3 |
|---|---|
| ① `farm_users` 원자 UPDATE | ① `farm_users` `lockForUpdate()` |
| ② `farm_plantings` 원자 UPDATE | ② `farm_mission_slot_quotas` 조건부 UPDATE |
| ③ `farm_mission_daily_counters` UPDATE | ③ `farm_mission_logs` INSERT |
| ④ `farm_missions` UPDATE (total_quota) | ④ `farm_planting_days` INSERT |
| ⑤ `farm_user_mission_counters` 2-step | ⑤ `farm_plantings` UPDATE |
| ⑥ 로그 INSERT | ⑥ `farm_users` UPDATE |
| | ⑦ `farm_point_ledgers` INSERT |

#### ✅ 해결: **C1·C4·C5·C7 반영 후 5단계로 확정한다.**

```
DB::transaction(function () {
  ① farm_users                  원자 UPDATE  (일3회 · 쿨다운 · 5,000P · today_count · ip)
                                              → affected = 1 필수
  ② farm_plantings              원자 UPDATE  (밭 하루1회 · day_mask 비트 · accrued_points)
                                              → affected = 1 필수
  ③ farm_user_mission_counters  2-step       (per_user_limit · per_user_daily_limit)
                                              → 통과 필수
  ④ farm_mission_daily_counters 2단 UPDATE   (daily_quota · slot_cap, C8)
                                              → affected = 1, LAST_INSERT_ID() 로 seq_in_day 회수
  ⑤ farm_mission_logs           INSERT       (append-only, 스냅샷 전량 + billable + seq_in_day)
});
// 커밋 후: 캐시 DEL. 7일 완주(status='ready')면 수확 안내만. 지급은 /harvest 에서.
```

**01 부록 대비 변경 3건:**
1. ❌ `farm_missions`(total_quota) UPDATE **제거** → `D_eff`가 흡수 (C7)
2. 🔄 `farm_user_mission_counters`를 **③으로 앞당김** — 이 테이블의 키는 `(farm_user_id, mission_id)`라 경합이 사용자 단위다. "사용자 자원을 먼저, 공유 자원을 마지막에 가장 짧게"라는 01의 원칙에 따르면 ③이 맞다. 결과적으로 **공유 hot row(④)의 락 보유 시간이 UPDATE 1개 + INSERT 1개 ≈ 0.5ms 미만**으로 줄어든다.
3. ❌ 02 §4-3 ①의 `lockForUpdate()` **제거** — 원자 UPDATE가 행 락을 이미 잡는다. 명시적 SELECT는 RTT 1회 낭비이고, 02 스스로 §4-2에서 `FOR UPDATE`를 락 보유 6배로 기각했다. **자기 결정과 모순되는 코드다.**

**절대 규칙 (두 문서 공통, 유지):**
- ③ → ④ 순서를 뒤집지 않는다. 반대 순서로 잠그는 경로가 생기면 즉시 데드락.
- 트랜잭션 안에서 **외부 HTTP · `Job::dispatch()` · `Cache::` 호출 금지.**
- **거절 로그는 트랜잭션 롤백 밖에서 INSERT.** 롤백에 휩쓸리면 어뷰징 추적이 불가능해진다.
- 락 보유 P95 목표 ≤ 3ms. 초과 시 ⑤를 커밋 후 별도 트랜잭션으로 분리.

---

### 그 밖의 소규모 불일치 (해결안만)

| # | 항목 | 불일치 | ✅ 확정 |
|---|---|---|---|
| C19 | 파티션 로테이트 시각 | 01·02 = 05:40 / 03 = 05:55 | **05:40** (hub 05:50의 10분 앞. 03의 "05:50 뒤 5분"은 ALTER 겹침 위험) |
| C20 | `farm:plan-quota` 시각 | 02 = 05:50 | **05:45** (hub:partition-rotate 05:50과 충돌 회피) |
| C21 | 04:00 배치 3중 충돌 | 01 `prune-counters` / 02 `prune-logs` / 03 `expire-plantings` | **`farm:prune`(04:00) 하나로 통합** — 카운터·로그·방치 작물을 순차 처리 |
| C22 | 활성 미션 수 | 01 = 500(최대 2,000) / 02 = 1,000 / 03 = **20,000** | **확인 필요 (§6-3).** 잠정 1,000. 2만이면 `farm_settlement_daily`가 하루 2만 행 → 월 파티션 재검토 |
| C23 | 참여 단가 | 01 = 150원 / 03 = 375원 | **375원(예시)**, 실측은 주문별 (C9) |
| C24 | DAU | 01 = 40만 / 02 = 41.8만 | **41.8만** (쿨다운 120분 반영값이 더 정확) |
| C25 | 부채 항등식 | 01 = 적립 기준 / 03 = d1 진입 gross 기준 | **둘 다 필요.** `farm_liability_snapshots`에 `accrued_krw` 컬럼 추가. 항등식: `잔액 = 어제잔액 + 오늘적립 + 오늘d1진입gross − 수확지급 − 방치소멸` |
| C26 | 부채 조건부 가중 | 01 = `completed_days / required_days` 선형 / 03 = 코호트 완주율 | **03의 코호트 완주율.** 선형은 이탈률을 반영 못 한다. 단 실측 전(90일 미만)에는 `추정` 배지 |
| C27 | 작물 포인트 스냅샷 컬럼 | 01 = `expected_crop_points` / 03 = `reward_points` | **`reward_points`** (03) |
| C28 | `farm_mission_daily_counters.attempt_count` | 01만 정의. 정답 확정 시에만 증가하므로 "시도"가 아니다 | **컬럼 삭제.** 시도 수는 롤업이 로그에서 센다 |
| C29 | 로그 인덱스 개수 | 01 = 보조 2개 상한 / 03 = 4개 가정 | **보조 2개** (`fml_date(stat_date, mission_id)`, `fml_user(farm_user_id, id)`). 정산 롤업은 `WHERE id > cursor` PK range라 인덱스 불필요. Change Buffer를 살리려면 **UNIQUE 절대 금지** |
| C30 | 초과 감지 배치 | 01(동기화 훅) / 02 `farm:audit-quota`(매시) / 03 `farm:check-overage`(09:30) | **셋 다 유지, 역할 분리.** 동기화 훅=예방 / audit-quota=카운터↔로그 대조 / check-overage=전일 초과율 알림 |

---

## 5. 구현 순서

각 단계는 **"이것까지 되면 무엇을 확인할 수 있는가"**로 끝난다. 확인이 안 되면 다음으로 넘어가지 않는다.

> 🔴 2026-07-31 개정: 벤더 개방 매체(S2S 미션 API)·대량 동시성·시간대 분산 송출·식별자 한도·봇 감지가
> [design-04-vendor-api.md](./design-04-vendor-api.md) 로 추가됐다. Phase 1에 `reward_media`, Phase 3은 매체 중립
> 코어 + 식별자 한도, Phase 3.5(벤더 API v1)가 신설된다 — 각 Phase 착수 전 design-04 §6을 함께 볼 것.

### Phase 0 — 전제 확정 (반나절, 코드 없음)

| 할 일 | 산출 |
|---|---|
| 퀴즈농장 `vendors.id` 조회 → **매체 설정 테이블 `vendor_id`에 저장**(어드민 관리 — .env 아님, HANDOFF §7 정정) | 숫자 1개 |
| `marketing_order_items.quantity`가 하루치인지 총량인지 rankfree 운영자 확인 | `quota_mode` = `per_day` \| `total` |
| `df -h` + `SELECT SUM(data_length+index_length) FROM information_schema.TABLES` | 여유 GB |
| 동시 활성 세부주문서 수 실측 (`status='sent' AND end_date >= CURDATE()`) | 미션 수 |
| `farm_crops.points` 실제 값 결정 | 작물 6종 포인트 |

> ✅ **확인 가능:** 용량·샤딩·파티션 보존 결정의 입력이 전부 실측으로 바뀐다. 이 단계 없이 짜면 §6의 가정값 위에 코드가 쌓인다.
> 🔴 매체 설정의 `vendor_id`가 비어 있으면 미션 동기화가 그 매체를 **건너뛰고 알림**하도록 만든다. 이름 문자열 매칭은 금지(`vendors.name`에 unique 없음).

---

### Phase 1 — 시간 축과 사용자 신원 (반나절)

- `app/Support/FarmDay.php` — `current()` / `slot()` / `start()` / `isQuiet()`
- 마이그레이션: `farm_users` (C6·C14·C16 반영: `cooldown_until` · `today_date` · `today_count` · `today_attempts` · `last_submit_at` · `last_participated_at` · `accrued_points` · `paid_points` · `daily_ip`)
- `farm_crops` + `FarmCropSeeder`
- `auth.farm` 미들웨어 (`x-user-key` → sha256 → `findOrCreateByKey`), CORS 등록

> ✅ **확인 가능:** 05:59와 06:00의 `farmDay`가 다르고 01:59와 02:00의 `slot`이 다르다(`FarmDayBoundaryTest`). 미니앱에서 `GET /me/state`가 200을 반환하고 사용자 행이 생성된다. **쿠키 없이 헤더만으로 신원이 유지된다.**

---

### Phase 2 — 미션 미러와 동기화 (1일)

- 마이그레이션: `farm_missions` (C10 확정 컬럼명) + `farm_mission_daily_counters`(PK `(mission_id, stat_date)`, 대리키 없음, `slot_overflow_count` 포함) + `farm_mission_snapshots`
- `farm:sync-missions` — 01 §1-2 SQL + 03 §1-2 단가 계산식. `--incremental` 플래그
- rankfree 어드민 `admin.orders.items.update` 저장 훅 (C12)
- 매일 00:05 KST에 **당일 + 익일** 카운터 행 `insertOrIgnore` 선생성

> ✅ **확인 가능:** rankfree에 세부주문서를 하나 만들면 5분 안에 `farm_missions`에 `draft` 미션이 뜨고, `unit_revenue`가 `total_price ÷ Σquantity`로 채워진다. 어드민에서 일 한도를 낮추면 **훅으로 즉시** 미러에 반영된다.
> 🔴 이 단계에서 **`unit_revenue = 0`인 미션이 나오면 §6-1의 분모 0 케이스**다. `draft`로 막고 알림이 뜨는지 확인한다.

---

### Phase 3 — 참여 확정 경로 🔴 **최소 안전선** (1.5일)

- 마이그레이션: `farm_plantings`(`day_mask` · `accrued_points` · `reward_points` · `abandoned_at` · `first_tended_on`) + `farm_user_mission_counters` + `farm_mission_logs`(파티션 없이 먼저)
- `app/Domain/Farm/SlotCap.php` — C1 공식
- `app/Domain/Farm/QuotaGate.php` — C8의 2단 UPDATE + `LAST_INSERT_ID()` 회수
- `MissionSubmitService` — C18의 5단계 트랜잭션. 사전 게이트 A → 채점 B → 확정 C 순서
- 거절 로그를 **트랜잭션 밖**에서 INSERT
- `REJECT_REASONS` 확장: `cooldown` · `quota_full` · `closed` · `ip_limit` · `mission_cap` · `point_cap` · `too_fast` · `plot_done` · `plot_empty` · `blocked` · `daily_limit`

> ✅ **확인 가능 (여기가 전부다):**
> - `FarmQuotaConcurrencyTest` — 동시 100요청 → `used`가 **정확히 `daily_quota`에서 멈춘다**
> - `FarmCooldownTest` — 참여 직후 두 번째 참여 거부, 121분 후 허용
> - `FarmSlotCapTest` — 모든 `D`(1~100,000)에 대해 마지막 구간 `slot_cap == D`, 단조 증가
> - 로그의 `seq_in_day`가 1부터 빈틈없이 증가하고 `billable = (seq_in_day <= daily_quota)`
> - 오답 제출이 카운터를 건드리지 않는다
>
> 🔴 **Phase 3 없이 출시하면 한도 초과가 그대로 금전 손해가 된다.** 여기까지가 최소 안전선이다.

---

### Phase 4 — 노출·캐시 (1.5일)

- `app/Domain/Farm/FarmCache.php` — L1(APCu) → L2(Redis) 2단 + 서킷 브레이커
- `config/cache.php`에 `farm_l1` / `farm_l2` 스토어 추가 (**기본 `database` 스토어는 건드리지 않는다**)
- `farm:build-snapshot` (60초) — 노출 후보 미션을 `farm_mission_snapshots`에 JSON으로 굽는다
- `farm:warm-cache` (매분, **`withoutOverlapping()` 금지 → `flock` 로컬 락**) — C1 목록 · C9 후보 ZSET · 파일 캐시
- `GET /missions` — 휴지/쿨다운/일한도 조기 반환 + `locked`/`unlockAt`/`lockReason` (C15)

> ✅ **확인 가능:**
> - 02:00–06:00에 `GET /missions`가 **DB 쿼리 0 · 캐시 조회 0**으로 빈 목록 + `closed:true`를 반환한다
> - 쿨다운 중 요청이 **DB 쿼리 1회(인증)** 로 끝난다 — `DB::listen()`으로 계측
> - Redis를 죽여도 목록이 나온다(L1 + 파일 캐시). 브레이커가 60초 만에 열리고 닫힌다(`FarmCacheFallbackTest`)
> - 3대 서버가 **같은 스냅샷**을 본다 (`farm_mission_snapshots.built_at` 비교)

---

### Phase 5 — 수확·지급 (1일)

- `farm_point_ledgers` — `unique(source, source_id)` **중복 지급 최종 방어선**
- `POST /harvest` — 캐시 일절 금지. `BIT_COUNT(day_mask) >= required_days` 검증
- 5,000P 상한: 수확 시에도 `farm_users.accrued_points` 원자 UPDATE로 게이트, 부분 지급(`min(crop_points, cap - accrued)`)
- `FarmPointPayoutJob` (트랜잭션 밖 dispatch) + 토스 프로모션 API + 재시도 스케줄러(5분)

> ✅ **확인 가능:**
> - 7일 참여 → 수확 → 원장 1행 + 토스 지급. **같은 밭을 하루에 두 번 참여해 7일을 3.5일로 줄이는 것이 불가능**하다(day_mask 비트)
> - 수확 API를 동시에 2번 호출해도 원장이 1행이다(`unique(source, source_id)`)
> - 5,000P를 넘는 순간 `{ok:true, points:0, message:'누적 포인트 한도에…'}`가 나온다
> - **Redis를 꺼도 수확이 정상 동작한다**(캐시 미사용 경로)

---

### Phase 6 — 정산 집계 🔴 (1.5일)

- 마이그레이션: `farm_settlement_daily` · `farm_settlement_monthly` · `farm_settlement_adjustments` · `farm_liability_snapshots`(`accrued_krw` 포함) · `farm_stat_hourly_total` · `farm_rollup_cursors` · `farm_mission_slot_stats`
- `farm:rollup-stats` (5분) — 03 §3-3 STEP1~4. **`unique_users`는 채우지 않는다** (C17)
- `farm:rollup-stats --deep` (매시)
- `farm:aggregate-stats` (03:00) — `unique_users` 전량 재계산 + 구간 실적 + `total_used` 갱신 + 항등식 검증
- `farm:snapshot-liability` (03:20) — 전일 부채 스냅샷
- 모델 스코프 `scopeTotals()` / `scopeMissions()` — **raw 쿼리 금지를 코드 리뷰 항목으로**

> ✅ **확인 가능:**
> - 같은 배치를 2번 돌려도 `farm_settlement_daily` 값이 변하지 않는다(멱등)
> - `mission_id=0` 전사행의 `participations` 합 == `mission_id>0` 미션행 합 (이중계상 없음)
> - 심기 → 7일 참여 → 수확 시나리오에서 **부채 항등식 오차 0원**
> - `unique_users`가 DAU를 넘지 않는다 (C17 회귀 테스트)
> - 커서를 되감아 `--from` 재실행 시 그 구간만 재계산되고 이후가 망가지지 않는다

---

### Phase 7 — 관리자 화면 (2일)

- `admin.farm-settlement` 대시보드 — ⚡실시간 / 🕐집계 / 🌙일마감 아이콘 구분
- `admin.farm-liability` 부채 화면 — 진행도별 표 + 지급 캘린더 + 정합 검증
- `admin.farm-missions` 목록 — **경고 칩 5종** (정답 미입력 / 단가 0원 / 종료 임박 미이행 / 오늘 미소진 / 초과 발생)
- 미션 상세 3탭 (정산 / 시간대 분포 / 로그). 시간대 분포는 **당일 = 로그 `slot_no` / 과거 = `farm_mission_slot_stats`** (C1)
- 정답 등록 폼 — `answer` 평문 표시 금지, 변경 시 `farm_mission_answer_logs`에 sha256만
- `menus` 마이그레이션 (없으면 사이드바에 안 뜬다)
- 월 마감 · 정정 · CSV 내보내기

> ✅ **확인 가능:** 운영자가 화면만 보고 ①오늘 얼마 벌었나 ②앞으로 얼마 나가나 ③어떤 미션이 정답 미입력이라 매출 0인가 ④한도 초과가 얼마인가를 **문의 없이** 판단할 수 있다.
> 집계 지연 15분이면 노란 배너, 60분이면 빨간 배너 + 잔디 알림이 뜬다.

---

### Phase 8 — 감사·알림 (1일)

- `farm_quota_audits` + `farm:audit-quota` (매시 10분) — 카운터 ↔ 로그 대조
- `farm:check-overage` (09:30) — 전일 초과율 + 미션별 TOP 10
- `farm:detect-abuse` (매시 25분) — 신규 계정 IP 클러스터. **자동 차단 안 함**(통신사 NAT 오탐)
- `farm:calc-complete-rate` (매주 월 05:00) — 코호트 완주율 실측
- `SendJandiFarmAlert` Job (`SendJandiOrderNotification` 패턴 복사)

> ✅ **확인 가능:** 카운터와 로그가 어긋나면 1시간 안에 잔디 알림이 온다. `diff > 0`(로그 > 카운터)이면 **진짜 초과 = 금전 손해**이므로 즉시 조사 대상이다.
> 🔴 **자동 보정하지 않는다** — `used`를 로그 COUNT로 덮어쓰면 드리프트 원인이 "로그 중복"일 때 한도를 잘못 늘려 진짜 초과를 만든다.

---

### Phase 9 — 파티션·보존 (1일)

- `farm_mission_logs` 월 RANGE 파티션 전환: `unsignedBigInteger('id', false)->autoIncrement()` → raw `ALTER TABLE … DROP PRIMARY KEY, ADD PRIMARY KEY (id, stat_month)` → `PARTITION BY RANGE (stat_month)`
- `farm:partition-rotate` (05:40) — `HubPartitionRotate` 복제. **+2개월 선생성** + `pmax` 비어 있음 감시
- `farm:archive-logs` — TSV+gzip, **행 수 검증 불일치 시 DROP 중단**
- `farm:prune` (04:00) — `farm_user_mission_counters` 청크 삭제 + `farm_plantings` 종료분 90일 + 방치 14일 → `abandoned`(⚠️ **`ready` 작물 제외**)

> ✅ **확인 가능:**
> - `EXPLAIN PARTITIONS`로 기간 조회가 **1~2개 파티션만 탄다**
> - `DROP PARTITION`이 초 단위로 끝난다(DELETE는 수 시간)
> - `pmax`의 `TABLE_ROWS`가 0이 아니면 알림이 뜬다 → 로테이션 정지 = 프루닝 무력화 신호
> - sqlite 폴백(`where('stat_month','<',$cutoff)->delete()`)이 CI에서 통과한다

---

### Phase 10 — 부하 테스트와 인프라 조정 (1일)

- k6/ab로 스파이크 670 QPS 재현
- `pm.max_children` 20 → 40, `pm.max_requests = 500`
- `innodb_buffer_pool_size` ≥ 4GB, `Threads_running > 20` 알람
- Redis 3대 설치 (persistence OFF · `allkeys-lru` · maxmemory 1GB · phpredis 권장)
- 크론 3대 설치 (`farm:warm-cache`만 3대 실행, 나머지는 공유 `cache_locks`로 자동 1대)

> ✅ **확인 가능:** 피크 QPS에서 P95 응답 ≤ 10ms(정상) / ≤ 35ms(Redis 다운). PHP-FPM 워커가 고갈되지 않는다.
> DB 지연 200ms를 인위로 주입했을 때 워커가 버티는지 — **이게 안 되면 `max_children`이 부족한 것이다.**

---

### Phase 11 — 선점(홀드), Phase 2 기능 (1일, 선택)

- `farm_mission_holds` + `GET /api/farm/go/{mission}` (**`auth.farm` 그룹 밖** — 외부 브라우저가 헤더를 못 싣는다)
- HMAC 서명 토큰 · `farm:expire-holds`(매분, `held`를 실제 COUNT로 덮어씀)

> ✅ **확인 가능:** "5분 고생해서 정답 찾아왔는데 마감" 비율이 실측 2.1% → 0.2%로 떨어진다.
> **단, C8의 2단 UPDATE로 이미 대부분 해소된다.** Phase 3 이후 `reject_reason='quota_full'` 실측이 참여의 0.5%를 넘을 때만 착수한다.

---

### 총 공수와 순서 요약

```
Phase 0  전제 확정        0.5일  ← 여기 없이 시작하면 가정 위에 코드가 쌓인다
Phase 1  시간 축·신원     0.5일
Phase 2  미션 미러        1.0일
Phase 3  참여 확정 🔴     1.5일  ← 최소 안전선. 여기까지 4일
Phase 4  노출·캐시        1.5일
Phase 5  수확·지급        1.0일  ← 여기까지가 서비스 가능선. 7일
Phase 6  정산 집계 🔴     1.5일
Phase 7  관리자 화면      2.0일
Phase 8  감사·알림        1.0일  ← 여기까지가 운영 가능선. 11.5일
Phase 9  파티션·보존      1.0일  ← 로그 3천만 행 넘기 전
Phase 10 부하·인프라      1.0일  ← 일 30만 건 넘기 전
Phase 11 홀드 (선택)      1.0일
────────────────────────────────
총 13.5일 (Phase 11 제외 12.5일)
```

**출시 가능 최소 조합: Phase 0~5 (7일).** 단 정산 화면 없이 운영하면 손익을 모른 채 돈이 나간다 — **Phase 8까지(11.5일)를 1차 범위로 잡을 것.**

---

## 6. 미해결 과제

### 6-1. 🔴 사업 결정이 필요한 것 (기술로 못 정한다)

| # | 항목 | 왜 막혀 있나 | 결정 못 하면 | 임시 처리 |
|---|---|---|---|---|
| B1 | **토스 프로모션 지급 수수료 요율** | 계약·정책 문서에 없음 | 수익률이 통째로 달라진다. 88% vs 80%는 사업성 판단이 뒤집히는 차이 | `payout_fee_rate = 0.0`, 화면에 "수수료 미반영" 주석 |
| B2 | **`marketing_orders.total_price`가 VAT 포함인가** | 주문 화면 최종가 = 입금액이지만 세금계산서 처리 미확인 | 공급가액·부가세 분리 표시가 틀린다 | `vat_included = true` 가정. config 한 줄로 뒤집힘 |
| B3 | **`farm_crops.points` 실제 값** | 게임 밸런싱 미결 | **부채 총액 추정 전체가 이 값에 비례.** 500P 가정 시 300만 작물 = 15억 부채 | 500P 가정 |
| B4 | **DAU 42만을 달성할 수 있는가** | 🔴 쿨다운 120분이 DAU당 참여를 2.85 → 2.39회(−16%)로 낮춘다. **하루 100만 건이면 DAU 35만 → 42만이 필요하다** | 목표 미달 시 쿨다운을 90분으로 낮춰야 하는데 그건 어뷰징 방어 약화 | `cooldown_minutes` config + `app_settings` 오버라이드로 A/B |
| B5 | **한도 초과 시 사용자를 거절할 것인가** (C8) | 손해 0 vs CS·심사 리스크의 트레이드오프 | 일 한도 초과 시 정책이 안 정해진다 | `overflow_policy = 'reject'`(기본). 실측 거절률 0.5% 초과 시 재논의 |
| B6 | **비즈월렛 잔액 조회 API 유무** | 토스 문서 미확인 | 지급 캘린더에 잔액 라인을 못 겹친다 → 충전 시점을 눈으로 못 본다 | `app_settings`에 운영자 수동 입력 |
| B7 | **미달률 8~12%를 광고주에게 어떻게 설명할 것인가** | 이행률 40% 상품은 여유가 있지만 100% 상품은 클레임 대상 | 종료 미션의 미이행 잔량이 환불·연장 협상으로 이어진다 | `marketing_products.default_fulfillment` 계산에 반영. §4-6 미이행 잔량 표를 협상 자료로 |

### 6-2. 🔍 조사에서 확인 못 한 것 (실측하면 끝난다)

| # | 항목 | 왜 필요한가 | 확인 방법 | 임시 |
|---|---|---|---|---|
| U1 | **퀴즈농장 `vendors.id`** | 세부주문서 필터의 **유일한 기준.** `vendors`에 code/slug 컬럼이 없고 `name`에 unique도 없어 이름 매칭은 위험 | `SELECT id, name FROM vendors` | 매체 설정 테이블 `vendor_id` 미설정이면 sync 건너뜀 + 알림 (.env 아님 — HANDOFF §7) |
| U2 | **`marketing_order_items.quantity`가 하루치인가 기간 전체인가** | `total_quota` 산식이 뒤집힌다. 주석은 "그 날 그 업체 몫"인데 나중에 `end_date`가 추가돼 회차가 기간형이 됐다 | 운영자 확인 + 실데이터 대조 | `quota_mode` = `per_day`(기본) \| `total` |
| U3 | **서버 디스크 여유** | 로그 37GB + 원장 10GB/년 + 집계 3GB + 기존 rankfree + 백업 → **최소 150GB 여유 필요** | `df -h`, `SELECT SUM(data_length+index_length) FROM information_schema.TABLES` | 3개월 보관 가정. 부족하면 35일로 축소 |
| U4 | **동시 활성 미션 수** | 01=500 / 02=1,000 / 03=20,000으로 **40배 차이.** 용량·캐시·샤딩 판정의 입력 (C22) | `SELECT COUNT(*) FROM marketing_order_items WHERE vendor_id=? AND status='sent' AND end_date>=CURDATE()` | 1,000 가정 |
| U5 | **세부주문서 "진행중"의 정확한 값** | `MarketingOrderItem::STATUSES`에 '진행중'이 없다(pending/sent/failed/canceled). `sent`= 발주 전달 = 미션 개시로 정의했으나 실데이터 확인 필요 | 운영 데이터 샘플링 | `status='sent' AND 부모 status='processing'` |
| U6 | **3대 서버의 APCu·OPcache 설치 현황** | L1 캐시 전제. 없으면 L2만으로 동작(성능 −30%) | `php -m \| grep apcu` | APCu 있다고 가정, 없으면 `l1_enabled=false` |
| U7 | **오답률 (정답률 70% 가정)** | 로그 INSERT 부하·스토리지 추정의 입력 | 출시 후 1주 실측 | 70% |
| U8 | **카페24 2대의 PHP 버전·확장** | rankfree 서버(8.3 Remi)와 다르면 배포 파이프라인 분기 필요 | 서버 접속 | 동일 가정 |
| U9 | **`cache_locks`를 MariaDB로 고정하는 config 구조** | Redis 다운 시 락이 풀려 롤업 3중 실행 → 집계 3배 | `config/cache.php`에 `locks` 전용 스토어 분리 + `Cache::store('db')->lock()` 명시 | 설계는 확정, 구현 미검증 |
| U10 | **rankfree 어드민 세부주문서 저장 훅 삽입 지점** | 🔴 **한도 하향이 5분 늦게 반영되면 그게 초과의 유일한 경로다** (C12) | `admin.orders.items.update` 컨트롤러 확인 | 5분 증분 동기화로만 대응 |

### 6-3. ⚠️ 설계 자체에 남은 리스크 (해결책은 있으나 미착수)

| # | 리스크 | 현재 판단 | 대응 |
|---|---|---|---|
| R1 | **누적 상한에 캐리 캡이 없어 뒤 구간 쏠림 가능** (C1) | 앞 구간이 안 나갔다는 건 노출 부족이고, 뒤에서 몰아 소진하는 건 미달 방지 방향이므로 수용 | 문제가 되면 `slot_open_used` 컬럼 1개 추가로 `MIN(누적상한, slot_open_used + 2×구간배정)` 확장 |
| R2 | **S7(22–02) 미달 −14.7%** — S6 참여자가 쿨다운으로 못 온다 | 미달률 KPI 5% 초과 시 대응 | 순서: ①S7 노출 가중 1.3배 ②S6 21%→18% / S7 15%→18% ③쿨다운 90분(**어뷰징 방어 약화라 마지막**) |
| R3 | **계정을 갈아타면 쿨다운은 우회된다** | IP 단위 쿨다운은 통신사 NAT 오탐으로 채택 불가. `TrustProxies` 미설정 + Cloudflare DNS-only라 클라 IP 신뢰도도 낮다 | 3층 완화: IP 일 30건 상한 / 신규 계정 클러스터 탐지(**자동 차단 안 함**) / 경제적 상한(수확 지급 + 5,000P) |
| R4 | **미션 2만 건 초과 시 `marketing_order_items` 동기화 쿼리 지연** | 현재는 `index(['status','work_date'])`로 충분 | `index(vendor_id, status, end_date)` 추가 검토. **rankfree 원본에 인덱스 추가만 허용, 컬럼 추가는 금지** |
| R5 | **`farm_liability_snapshots`는 로그만으로 복원 불가** | 과거 시점의 활성 작물 상태이기 때문 | **절대 삭제 금지 + 백업 우선순위 1등급.** 방치 판정 시점의 config가 바뀌면 역산도 어긋난다 |
| R6 | **`farm_settlement_daily`가 활성 미션 2만이면 하루 2만 행** | 1,000 가정으로는 하루 1,001행 = 무시 가능 | U4 실측 후 2만이면 월 파티션 검토 |
| R7 | **rankfree DB가 crm(mod_php 7.2)과 공존하는 공용 서버** | 스파이크 시 총 1,700 ops/s가 여유롭지 않다 | `Threads_running > 20` 알람. 초과 지속 시 DB 분리 검토 |

---

## 부록. 최종 테이블 목록 (세 문서 통합 결과)

| # | 테이블 | 소유 문서 | 정상 행 수 | 보관 | 비고 |
|---|---|---|---:|---|---|
| 1 | `farm_users` | 01 | 300만 | 무기한 | C6·C14·C16 컬럼 반영 |
| 2 | `farm_crops` | 01 | 6 | 무기한 | |
| 3 | `farm_missions` | 01 | 20만/년 | 무기한 | C10 컬럼명 확정 |
| 4 | `farm_mission_daily_counters` | 01 | 20만/년 | 13개월 | PK `(mission_id, stat_date)` · `slot_overflow_count` 추가 · `attempt_count` 삭제 |
| 5 | `farm_mission_snapshots` | 01 | < 10 | 무기한 | |
| 6 | `farm_mission_logs` | 01+03 | 1.3억 | **3개월** ★파티션 | C3 이름 · C11 보존 · `billable`/`seq_in_day`/`slot_overflow` 추가 |
| 7 | `farm_user_mission_counters` | 01 | 3,000만 | 미션 종료 +7일 | |
| 8 | `farm_plantings` | 01+03 | 1,650만 | 종료 +90일 | `day_mask` · `reward_points` · `abandoned_at` · `first_tended_on` |
| 9 | `farm_point_ledgers` | 01 | 3,000만 | **무기한** | `index(['created_at','status'])` 추가 (03 요청) |
| 10 | `farm_settlement_daily` | 03 | 36만/년 | 36개월 | 01의 `farm_daily_stats` + `farm_mission_daily_stats` 흡수 |
| 11 | `farm_settlement_monthly` | 03 | 월 수백 | **영구** | |
| 12 | `farm_settlement_adjustments` | 03 | 극소 | **영구** | |
| 13 | `farm_liability_snapshots` | 03 | 48/일 | **영구** ★백업 1등급 | `accrued_krw` 추가 (C25) |
| 14 | `farm_stat_hourly_total` | 03 | 24/일 | 영구 | |
| 15 | `farm_rollup_cursors` | 03 | 4 | 영구 | |
| 16 | `farm_mission_slot_stats` | 01 | 128만/년 | 90일 | 구간 실적 롤업 (C1) |
| 17 | `farm_quota_audits` | 02 | 8,000/일 | 400일 | `slot_code` → `slot_no` |
| 18 | `farm_mission_answer_logs` | 03 | 극소 | 영구 | sha256만 저장 |
| 19 | `farm_recommended_apps` | 01 | < 50 | 무기한 | |
| 20 | `farm_mission_holds` | 02 | 60만 | 7일 | **Phase 11 (선택)** |
| — | ❌ `farm_planting_days` | — | — | — | **삭제** — `day_mask`가 대체 (C4) |
| — | ❌ `farm_harvests` | — | — | — | **삭제** — `farm_point_ledgers`가 흡수 (C4) |
| — | ❌ `farm_mission_slot_quotas` | — | — | — | **폐기** — 누적 상한 채택 (C1) |

**총 상시 용량 추정: 약 55GB** (로그 37GB + 카운터·재배 5GB + 원장 6GB + 사용자 0.6GB + 집계 3GB + 기타)
→ 백업 포함 **최소 150GB 여유 확보 필요** (U3 확인 필수)

---

## 부록 2. 스케줄 통합표 (충돌 해소 후)

| 커맨드 | 주기/시각(KST) | 하는 일 | 실패 방향 | 출처 |
|---|---|---|---|---|
| `farm:warm-cache` | **매분** | C1 목록 · C9 후보 ZSET · 파일 캐시. **3대 전부 실행 → `withoutOverlapping()` 금지, `flock` 로컬 락** | 캐시 미스 → DB 폴백 | 02 |
| `farm:build-snapshot` | **60초** | 노출 후보 → `farm_mission_snapshots` | 낡은 목록 | 01 |
| `farm:rollup-stats` | **매 5분** | 수입 증분 + 지출 24h 재계산 + 시간대 + 부채 flow. **`unique_users` 제외** (C17) | 집계 지연 (화면 배너) | 03 |
| `farm:sync-missions --incremental` | **매 5분** | 한도 변경 반영 (증분 upsert) | 🔴 **초과의 유일한 경로** | 01 |
| `farm:rollup-stats --deep` | 매시 00분 | 지출 72h 재계산 | 집계 지연 | 03 |
| `farm:audit-quota` | 매시 **10분** | 카운터 ↔ 로그 대조 → `farm_quota_audits` + 알림 | 감지 지연 | 02 |
| `farm:detect-abuse` | 매시 **25분** | 신규 계정 IP 클러스터 → 어드민 플래그. **자동 차단 안 함** | 탐지 지연 | 02 |
| `farm:aggregate-stats` | **03:00** (휴지 중) | `unique_users` 전량 재계산 + 구간 실적 + `total_used` + 항등식 검증 | 재실행 멱등 | 통합 |
| `farm:snapshot-liability` | **03:20** | 전일 부채 스냅샷 (03의 00:20 → 농장일 마감 02:00 이후로 이동) | 부채 시계열 결손 | 03+C2 |
| `farm:prune` | **04:00** (휴지 중) | 카운터 청크 삭제 + 종료 작물 90일 + **방치 14일 → `abandoned`**(⚠️ `ready` 제외) | 스토리지 증가만 | 통합 C21 |
| `farm:partition-rotate` | **05:40** | 월 파티션 +2개월 선생성 + 초과분 아카이브 후 `DROP` | 🔴 `pmax` 몰림 = 프루닝 무력화 | 01 |
| `farm:plan-quota` | **05:45** | 그날 미션별 `D_eff` 재계산 + `total_used` 실측 갱신 | 런타임 폴백 → **미달만** | 02+C20 |
| `farm:sync-missions` | **08:00** | 전량 대조 + 신규 `draft` + 단가 스냅샷 + **정답 미입력 알림** | 매출 0 미션 방치 | 03 |
| `farm:check-overage` | **09:30** | 전일 초과율 + 미션별 TOP 10 알림 | 감지 지연 | 03 |
| `farm:calc-complete-rate` | 매주 월 **05:00** | 코호트 완주율 실측 (표본 1,000 미만이면 갱신 안 함) | 초기값 유지 | 03 |
| `farm:archive-logs` | 매월 1일 **03:00** | 파기 예정 파티션 TSV+gzip 덤프. **행 수 불일치 시 DROP 중단** | DROP 보류 | 01 |
| `farm:flush-cache` | 수동 | 캐시 전체 비우기 (복구 도구) | — | 02 |
| `farm:rebuild-settlement` | 수동 | 원장에서 집계 전량 재생성. 마감 기간은 `--force` 필요 | — | 03 |

**시각 배치 원칙:** 02:00–06:00은 심야 휴지 창이라 참여 트래픽과 경합하지 않는다. 기존 `hub:partition-rotate`(05:50) · `orders:dispatch-due`(09:00)와 5분씩 비켜 배치했다.

**빠뜨리면 안 되는 것 3가지:**
1. `Admin\ScheduleOverviewController::META`에 신규 커맨드 전부 추가 — 어드민 '자동 수집 현황'이 이 배열을 읽는다
2. 크론을 **3대 모두**에 설치. `withoutOverlapping()`이 공유 `cache_locks`를 쓰므로 대부분 자동으로 1대만 돈다. **`farm:warm-cache`만 락 없이 3대 실행**
3. **새 커맨드는 큐를 쓰지 않는다** — 전부 스케줄러 직접 실행. supervisor `--queue=` 누락 사고(2026-07-22 '발행 0') 회피
