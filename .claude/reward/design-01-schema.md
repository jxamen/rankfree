# 퀴즈농장 × rankfree — DB 스키마 · 파티셔닝 · 인덱스 설계

> 대상: `C:\Users\jxame\Documents\project\rankfree` (Laravel 13.8 / PHP 8.3 / MariaDB 11.4.2)
> 작성일: 2026-07-28 · 영역: **DB 스키마 · 파티셔닝 · 인덱스**
> 선행 문서: [rankfree-integration-v1-draft.md](./rankfree-integration-v1-draft.md) · [infra-constraints.md](./infra-constraints.md)
> rankfree 등록 시 파일명: `.claude/29_TOSS_FARM_MINIAPP.md` §2 를 이 문서로 대체

---

## 0. 전제 수치 (모든 설계 판단의 근거)

| 항목 | 값 | 산출 근거 |
|---|---|---|
| 일 참여 확정 | **100만 건** | 요구사항 |
| 일 참여 **시도** | **130만 건** | 확정 100만 + 오답·거절 30% |
| 평균 쓰기 QPS | **11.6 / 초** | 1,000,000 ÷ 86,400 |
| 피크 쓰기 QPS | **58 / 초** | 평균 × 5 (출근·점심·퇴근 집중) |
| 읽기 QPS | **120 ~ 1,200 / 초** | 참여의 10~100배 (infra-constraints §2) |
| DAU | **40만** | 100만 ÷ 평균 2.5회(밭 3칸 중 2.5칸 가동) |
| 누적 사용자 | **300만 / 1년** | |
| 동시 활성 미션 | **500개** (최대 2,000) | 세부주문서 기준 추정 — **확인 필요** |
| 미션당 피크 QPS | **0.29 / 초** | 58 ÷ 200(피크에 실제로 열려 있는 미션 수) |
| 일 수확 건수 | **17만 건** | DAU 40만 × 밭3 ÷ 7일 |
| 참여 단가(수입) | **150원** | `marketing_products.min_price` 실측(네이버 쇼핑 퀴즈) |
| 1인 누적 포인트 상한 | **5,000P** | 요구사항 |

> **미션당 피크 0.29 QPS** 라는 숫자가 이 설계의 가장 중요한 분기점이다. → §4-4 (샤딩 불채택) 참조.

---

## 1. 세부주문서 → 미션 매핑

### 1-1. 결론: 직접 조인하지 않고 **미러 테이블 `farm_missions`** 를 둔다

`marketing_order_items`(세부주문서)를 미니앱 요청 경로에서 직접 조회하지 **않는다.** 5가지 이유:

| # | 이유 | 근거 |
|---|---|---|
| 1 | **소진량 컬럼이 없다** | 조사 결과 `marketing_order_items`에 실적 카운터 0건. 어딘가엔 만들어야 함 |
| 2 | **재생성이 행을 통째로 지운다** | `OrderItemPlanner::regenerate()`는 `sent` 회차가 없으면 전부 delete 후 재생성. 실적을 여기 두면 날아간다 |
| 3 | **미션 표시 정보가 4개 테이블에 흩어져 있다** | `marketing_orders.field_values`(JSON) + `shop_keyword_analyses` + `shop_product_infos` + `marketing_products`. 초당 1,200 읽기에 4-way JOIN + JSON 파싱은 불가능 |
| 4 | **정답(answer) 컬럼이 없다** | rankfree 주문 도메인에 퀴즈 개념 자체가 없음 |
| 5 | **운영자가 수시로 UPDATE 한다** | `admin.orders.items.update`가 일괄 저장 → 미션 노출이 요청 중간에 흔들린다 |

→ **`farm:sync-missions` 커맨드가 5분마다 `marketing_order_items` → `farm_missions` 로 upsert.**
런타임 판정은 전부 `farm_missions` + `farm_mission_daily_counters` 안에서 끝난다. rankfree 원본 테이블은 **읽기만 하고 절대 쓰지 않는다.**

### 1-2. 동기화 쿼리 (rankfree 원본 → 미러) — 확인된 실제 컬럼명

```sql
SELECT moi.id            AS order_item_id,
       moi.order_id, moi.day_no, moi.work_date, moi.end_date,
       moi.quantity, moi.short_url, moi.vendor_id, moi.status AS item_status,
       mo.product_id, mo.unit_price, mo.field_values, mo.status AS order_status,
       mp.min_price, mp.default_fulfillment,
       ska.mall_name, ska.brand, ska.product_title, ska.product_price,
       ska.core_keyword, ska.product_url, ska.product_id AS channel_product_id,
       spi.thumbnail_url
  FROM marketing_order_items moi
  JOIN marketing_orders      mo  ON mo.id  = moi.order_id
  JOIN marketing_products    mp  ON mp.id  = mo.product_id
  LEFT JOIN shop_keyword_analyses ska ON ska.marketing_order_id = mo.id
  LEFT JOIN shop_product_infos    spi ON spi.channel_product_id = ska.product_id
 WHERE moi.vendor_id = :quiz_farm_vendor_id     -- ① 업체 = 퀴즈농장
   AND moi.status    = 'sent'                   -- ② 진행중
   AND mo.status     = 'processing'
   AND moi.end_date >= CURDATE()                -- 종료된 회차는 동기화 제외
   AND moi.updated_at >= :since                 -- 증분(마지막 동기화 시각 − 10분)
```

**인덱스 활용**: rankfree에 이미 있는 `index(['status','work_date'])` 를 탄다. `vendor_id` 단독 인덱스가 없으므로 `status='sent'` 로 먼저 좁힌 뒤 vendor 필터가 적용된다. 활성 회차 수가 수천 단위면 문제없다. 미션이 2만 건을 넘기면 `marketing_order_items`에 `index(vendor_id, status, end_date)` 추가를 검토한다(**rankfree 원본에 인덱스만 추가하는 것은 허용 — 컬럼 추가는 금지**).

### 1-3. 노출 조건 5가지 — 런타임 SQL (미러 기준)

```sql
SELECT m.id, m.title, m.description, m.kind, m.shop_name, m.product_title,
       m.product_price, m.product_image_url, m.product_emoji, m.landing_url,
       m.guide, m.question, m.placeholder, m.reward_item, m.reward_count,
       m.payout_point, m.user_mission_cap, m.user_daily_cap, m.sort_order
  FROM farm_missions m
  JOIN farm_mission_daily_counters c
    ON c.mission_id = m.id
   AND c.stat_date  = CURDATE()
 WHERE m.status      = 'active'          -- ①업체=퀴즈농장(동기화 필터) ②진행중
   AND m.starts_on  <= CURDATE()         -- ③ 시작일 ~
   AND m.ends_on    >= CURDATE()         -- ③ ~ 종료일(포함)
   AND c.used        < c.daily_quota     -- ④ 일 주문횟수 잔여
   AND m.total_used  < m.total_quota     -- ⑤ 전체 수량 잔여
 ORDER BY m.sort_order, m.id
 LIMIT 200
```

| 조건 | 어디서 판정 | 왜 |
|---|---|---|
| ① 업체 = 퀴즈농장 | **동기화 시점** (`moi.vendor_id = config('rankfree.farm.vendor_id')`) | `vendors`에 code/slug 컬럼이 없고 `name`에 unique도 없어 문자열 매칭이 위험. **vendor id 를 config에 고정** |
| ② 상태 = 진행중 | 동기화 시점 (`moi.status='sent'` AND `mo.status='processing'`) | `MarketingOrderItem::STATUSES`에 '진행중'이 없다(pending/sent/failed/canceled). **`sent`= 퀴즈농장에 발주가 전달됨 = 미션 개시** 로 정의. 미션 런타임 상태는 `farm_missions.status`(별개 축)로 관리 |
| ③ 시작일~종료일 | 런타임 (`starts_on`/`ends_on`) | `moi.work_date` / `moi.end_date` 스냅샷 |
| ④ 일 잔여 | 런타임 + **원자 UPDATE** | `farm_mission_daily_counters.used < daily_quota` |
| ⑤ 전체 잔여 | 런타임 + **원자 UPDATE** | `farm_missions.total_used < total_quota` |

> 이 쿼리는 **사용자 요청마다 돌지 않는다.** 60초마다 배치가 1회 실행해 `farm_mission_snapshots`에 JSON으로 굽고, 각 서버가 APCu로 캐싱한다. 사용자별 제외(쿨다운·누적 상한)만 앱 레이어에서 뺀다. → 초당 1,200 읽기 × 이 쿼리 = 절대 금지.

### 1-4. 세부주문서 컬럼 → 미션 응답 필드 매핑표

| rankfree 원본 | → `farm_missions` 컬럼 | → API 응답 필드 | 비고 |
|---|---|---|---|
| `marketing_order_items.id` | `order_item_id` (unique) | — | 정산 귀속 키. 응답 미노출 |
| `marketing_order_items.order_id` | `order_id` | — | |
| `marketing_order_items.day_no` | `day_no` | — | 회차 |
| `marketing_order_items.work_date` | `starts_on` | — | 노출 시작 |
| `marketing_order_items.end_date` | `ends_on` | — | 노출 종료(포함) |
| `marketing_order_items.quantity` | `daily_quota` | — | **일 주문횟수** |
| `quantity × (ends_on−starts_on+1)` | `total_quota` | — | **전체 수량**. §1-5 참조 |
| `marketing_order_items.short_url` | `landing_url` | `quiz.hintUrl` | 안내 페이지 외부 링크 |
| `marketing_order_items.vendor_id` | `vendor_id` | — | 정산 스냅샷 |
| `marketing_orders.unit_price` | `revenue_unit_price` | — | **광고주 청구 단가 스냅샷** |
| `marketing_orders.product_id` | `product_id` | — | |
| `shop_keyword_analyses.mall_name` | `shop_name` | `title`·`description` 조립 소재 | **상점명** |
| `shop_keyword_analyses.product_title` | `product_title` | `quiz.product.name` | |
| `shop_keyword_analyses.product_price` | `product_price` | `quiz.product.price` | **가격** |
| `shop_keyword_analyses.core_keyword` | `keyword` | `quiz.keyword` | |
| `shop_keyword_analyses.product_url` | `product_url` | — | landing_url 이 비었을 때 폴백 |
| `shop_product_infos.thumbnail_url` | `product_image_url` | `quiz.product.imageUrl` | |
| `shop_product_infos.seller_tags` | `tags` | — (**절대 미노출**) | 🔴 **해시태그형 미션의 정답**. 자동 수집이라 운영자 입력 불필요 |
| (운영자 입력, 선택) | `title` `description` `guide` `question` `answer` `answer_type` `tolerance_percent` `placeholder` `product_emoji` | `title` `description` `quiz.guide` `quiz.question` `quiz.placeholder` — **answer 계열은 절대 미노출** | 어드민 화면 |
| (운영자 입력) | `reward_item` `reward_count` `payout_point` | `reward.item` `reward.count` `points` | `payout_point` 는 매체별 설정 페이지에서 관리(Phase 7) |

🔴 **정답은 운영자가 입력하지 않는다** (2026-07-31 지시): 해시태그형 미션은 `tags`(상품 seller_tags 스냅샷)가
곧 정답이고, 출제 번호는 사용자별로 결정된다(`TagIndex`). 문제 표시·답 입력은 **퀴즈농장(매체) 화면**이 한다.
`answer` 컬럼은 태그가 없는 상품의 **고정 정답형 폴백**일 뿐이다. 그래서 미션 승격 판정은 `answer` 유무가 아니라
**채점 가능 여부**(`MissionSync::isGradable` — 태그 또는 고정 정답)여야 한다. `answer`만 보면 태그형 미션이
영영 draft에 갇힌다.

**미션 제목·설명 자동 조립 규칙** (운영자가 비워두면 동기화가 채운다):
- `title` = `"{shop_name} {product_title} 최저가 찾기"` → 80자 초과 시 `Str::limit`
- `description` = `"{keyword} 검색 결과에서 {shop_name} 상품 가격을 확인하고 오면 {reward_item} {reward_count}개를 받아요."`

### 1-5. 일별 / 전체 소진량 — 계산 위치

| 항목 | 저장 위치 | 갱신 방식 | 조회 |
|---|---|---|---|
| **일별 소진량** | `farm_mission_daily_counters.used` | 참여 확정 시 원자 UPDATE (`used < daily_quota` 조건) | PK 직격 |
| **전체 소진량** | `farm_missions.total_used` | 같은 트랜잭션 원자 UPDATE (`total_used < total_quota` 조건) | PK 직격 |
| **시간구간 소진량** | 실시간: `used` vs `slot_cap`(누적 상한, §3-3) / 통계: `farm_mission_slot_stats.used` | 실시간은 별도 저장 없음 · 통계는 일 마감 롤업 | |
| **초과분(손해)** | `farm_participation_logs.is_overflow` + `farm_mission_daily_stats.overflow_count` | `mission_seq_no > daily_quota` 판정 | 롤업 |

> ⚠️ **`total_quota` 산식은 확인 필요.** `marketing_order_items.quantity` 주석은 "그 회차 = 그 날 그 업체 몫"인데, 나중에 `end_date`가 추가돼 회차가 기간형이 됐다. `quantity`가 (a) 하루치인지 (b) 기간 전체인지 rankfree 운영자 확인이 필요하다. 설계는 config 스위치로 대응한다:
> `config('rankfree.farm.quota_mode')` = `'per_day'`(기본, total = quantity × 기간일수) | `'total'`(daily = quantity ÷ 기간일수, total = quantity)

---

## 2. 전체 테이블 목록

마이그레이션 10개. rankfree 관례 준수: `return new class extends Migration` 익명 클래스 + 상단 한글 docblock("퀴즈농장(29) — …") + 컬럼 줄 끝 `// 한글 설명` + 연관 테이블은 한 파일(`create_x_tables` 복수형) + `down()`은 생성 역순 `dropIfExists`.

| # | 파일명 | 테이블 |
|---|---|---|
| 1 | `2026_07_28_100000_create_farm_users_table.php` | `farm_users` |
| 2 | `2026_07_28_100100_create_farm_crops_table.php` | `farm_crops` |
| 3 | `2026_07_28_100200_create_farm_mission_tables.php` | `farm_missions` `farm_mission_daily_counters` `farm_mission_snapshots` |
| 4 | `2026_07_28_100300_create_farm_participation_logs_table.php` | `farm_participation_logs` ★파티션 |
| 5 | `2026_07_28_100400_create_farm_user_mission_counters_table.php` | `farm_user_mission_counters` |
| 6 | `2026_07_28_100500_create_farm_plantings_table.php` | `farm_plantings` |
| 7 | `2026_07_28_100600_create_farm_point_ledgers_table.php` | `farm_point_ledgers` |
| 8 | `2026_07_28_100700_create_farm_stat_tables.php` | `farm_daily_stats` `farm_mission_daily_stats` `farm_mission_slot_stats` |
| 9 | `2026_07_28_100800_create_farm_recommended_apps_table.php` | `farm_recommended_apps` |
| 10 | `2026_07_28_100900_add_farm_admin_menus.php` | `menus` insert (마이그레이션, 테이블 아님) |

### 2-0. 초안(v1-draft §2) 대비 변경 — 테이블 2개 삭제, 3개 추가

| 변경 | 대상 | 근거 |
|---|---|---|
| ❌ **삭제** | `farm_planting_days` | 하루 100만 행 / 월 3천만 행짜리 테이블이 통째로 사라진다. "며칠 참여했나"는 `farm_plantings.day_mask`(비트마스크) 원자 UPDATE 한 문장으로 대체되고, 사실 기록은 `farm_participation_logs`가 이미 갖는다. 중복 저장 제거 → **월 3천만 행 · 6GB 절감** |
| ❌ **삭제** | `farm_harvests` | `farm_point_ledgers`와 1:1(수확 1건 = 지급 1건)이고 `unique(source, source_id)`가 `unique(planting_id)`와 동치. 수확 상세 컬럼을 원장으로 흡수 → **연 5,100만 행 절감** |
| ➕ 추가 | `farm_missions` | 세부주문서 미러 + 미션 마스터 (§1-1) |
| ➕ 추가 | `farm_mission_daily_counters` | 일 한도 게이트 |
| ➕ 추가 | `farm_user_mission_counters` `farm_mission_snapshots` `farm_daily_stats` `farm_mission_daily_stats` `farm_mission_slot_stats` | 사전 필터링 · 캐시 · 통계 |
| 🔄 변경 | 포인트 지급 단위 | 참여마다 → **수확(7일 완주) 1회로 통합**. 근거: ①토스 프로모션 3,000 QPM 제한에서 참여당 지급은 피크에 초과 ②원장 행이 하루 100만 → 17만으로 감소 ③"7일 동안 매일 참여해야 지급"이라는 **프로모션 심사 프레이밍과 정확히 일치**(게임 결과 기반 보상 회피) |

---

### 2-1. `farm_users` — 미니앱 사용자 신원 + **일 한도 · 쿨다운 · 포인트 상한 게이트**

| 컬럼 | 타입 | NULL | 기본값 | 설명 |
|---|---|---|---|---|
| `id` | `id()` | N | — | PK |
| `user_key_hash` | `string(64)` | N | — | `hash('sha256', x-user-key)`. **평문 미저장** |
| `key_type` | `string(8)` | N | `'anon'` | `anon` / `toss` |
| `anon_key_enc` | `text` | Y | null | 지급 재시도용 익명키(`encrypted` cast). 지급 확정 시 NULL |
| `status` | `string(12)` | N | `'active'` | `active` / `blocked` |
| `blocked_reason` | `string(120)` | Y | null | |
| `today_date` | `date` | Y | null | `today_count`의 기준일(KST) |
| `today_count` | `unsignedTinyInteger` | N | 0 | **오늘 참여 횟수** (상한 3) |
| `cooldown_until` | `timestamp` | Y | null | ★**쿨다운 만료 시각** (참여 시 `now()+2h`) |
| `last_participated_at` | `timestamp` | Y | null | |
| `total_participations` | `unsignedInteger` | N | 0 | 누적 참여 |
| `accrued_points` | `unsignedInteger` | N | 0 | ★**적립 누적(미지급 포함)** — 5,000P 상한 게이트 |
| `paid_points` | `unsignedInteger` | N | 0 | 지급 확정 누적 |
| `harvest_count` | `unsignedSmallInteger` | N | 0 | |
| `last_seen_at` | `timestamp` | Y | null | 1분 단위로만 갱신 |
| `created_at`/`updated_at` | `timestamps()` | | | KST |

**인덱스**: `unique('user_key_hash','fu_key')` · `index(['status','id'],'fu_status')` — 2개.
`cooldown_until` 인덱스는 **만들지 않는다**. 조회가 언제나 PK 또는 `user_key_hash` 직격이고, "쿨다운 중인 사용자 목록"을 뽑는 쿼리가 없다.

**예상 행 수**: 300만(1년) / 600만(2년) · 약 600MB
**보관**: 무기한. `last_seen_at < now()-2년` 인 행은 연 1회 익명화 삭제(개인정보 없음이므로 법적 의무 아님, 용량 관리 목적)

**★ 핵심 — 참여 슬롯 확보 원자 UPDATE (제약 2 쿨다운 + 일 상한 + 포인트 상한을 한 문장에)**

```sql
UPDATE farm_users
   SET today_count          = IF(today_date = :today, today_count + 1, 1),
       today_date           = :today,
       cooldown_until       = DATE_ADD(NOW(), INTERVAL :cooldown_min MINUTE),
       last_participated_at = NOW(),
       total_participations = total_participations + 1,
       accrued_points       = accrued_points + :payout_point
 WHERE id = :farm_user_id
   AND status = 'active'
   AND (today_date <> :today OR today_count < :daily_limit)      -- 일 3회 상한
   AND (cooldown_until IS NULL OR cooldown_until <= NOW())        -- ★2시간 쿨다운
   AND accrued_points + :payout_point <= :point_cap               -- 5,000P 상한
-- affected_rows = 1 → 슬롯 확보. 0 → 거절(사유는 후속 SELECT 1회로 구분)
```

**왜 별도 `farm_user_daily_counters` 테이블을 만들지 않는가**
1. 사용자 1명당 하루 최대 3회 → **자기 자신 외에는 경합이 없다**(hot row 아님)
2. 행 수가 사용자 수(300만)로 **고정**된다. 일별 테이블이면 40만/일 × 90일 = **3,600만 행**이 추가로 생긴다
3. 일 상한·쿨다운·포인트 상한 **3개 규칙을 한 UPDATE로 원자화**할 수 있다. 테이블을 나누면 트랜잭션 3개 + 데드락 위험
4. rankfree의 `User::tryConsumeUsage()`(check-then-increment)가 동시 요청에 한도를 넘기는 실제 버그다 — 그 패턴을 복사하지 않는다

**일별 활성 사용자(DAU) 통계**는 이 테이블이 아니라 `farm_participation_logs` 롤업에서 뽑는다(§2-10).

**쿨다운 UI 노출 설계 (요구사항: "숨김? 남은 시간 표시?")**
→ **숨기지 않고 전부 내려주되 `locked: true` + `unlockAt`(ISO8601 KST) 를 붙인다.**
근거: 숨기면 사용자가 "미션이 없어졌다"고 오해해 이탈한다(밭 3칸 × 2시간 텀 = 최소 4시간 재방문 유도가 이 기능의 목적인데, 숨기면 재방문 트리거가 사라진다). 응답 예:
```json
{ "missions": [...], "meta": { "remaining": 2, "dailyLimit": 3,
  "locked": true, "unlockAt": "2026-07-28T16:20:00+09:00", "lockReason": "cooldown" } }
```
DB 관점 추가 비용 **0** — `farm_users.cooldown_until` 한 컬럼으로 응답이 조립된다.

---

### 2-2. `farm_crops` — 작물 마스터

| 컬럼 | 타입 | NULL | 기본값 | 설명 |
|---|---|---|---|---|
| `id` | `id()` | N | — | |
| `code` | `string(20)` | N | — | `lettuce`/`carrot`/`onion`/`potato`/`tomato`/`corn` — 클라 `CROPS[].id`와 1:1 |
| `name` | `string(40)` | N | — | 상추, 당근 … |
| `emoji` | `string(8)` | N | — | |
| `days` | `unsignedTinyInteger` | N | 7 | `CROP_DAYS = 7` |
| `points` | `unsignedInteger` | N | 0 | **수확 보너스** |
| `sort_order` | `unsignedSmallInteger` | N | 0 | |
| `is_active` | `boolean` | N | true | |
| `timestamps` | | | | |

**인덱스**: `unique('code','fc_code')` · `index(['is_active','sort_order'],'fc_sort')`
**행 수**: 6 · **보관**: 무기한 · 시더 `FarmCropSeeder`

---

### 2-3. `farm_missions` — ★세부주문서 미러 + 미션 마스터

| 컬럼 | 타입 | NULL | 기본값 | 설명 |
|---|---|---|---|---|
| `id` | `id()` | N | — | 응답에 문자열로 캐스팅 |
| `order_item_id` | `unsignedBigInteger` | N | — | `marketing_order_items.id` · **FK 없음** · unique |
| `order_id` | `unsignedBigInteger` | N | — | 스냅샷 |
| `product_id` | `unsignedBigInteger` | Y | null | 스냅샷 |
| `vendor_id` | `unsignedBigInteger` | Y | null | 퀴즈농장 vendor id 스냅샷 |
| `day_no` | `unsignedSmallInteger` | N | 0 | 회차 |
| `status` | `string(12)` | N | `'draft'` | `draft`/`active`/`paused`/`ended`/`canceled` — **미션 런타임 상태**(moi.status와 별개 축) |
| `starts_on` | `date` | N | — | = `moi.work_date` |
| `ends_on` | `date` | N | — | = `moi.end_date` |
| `daily_quota` | `unsignedInteger` | N | 0 | **일 주문횟수** |
| `total_quota` | `unsignedInteger` | N | 0 | **전체 수량** |
| `total_used` | `unsignedInteger` | N | 0 | ★**전체 소진량** |
| `slot_ratios` | `json` | Y | null | 시간구간 배분 override(비면 config 기본값) |
| `revenue_unit_price` | `decimal(12,2)` | N | 0 | ★**청구 단가 스냅샷** (`marketing_orders.unit_price`) |
| `payout_point` | `unsignedSmallInteger` | N | 0 | ★**참여당 사용자 적립 포인트** |
| `title` | `string(80)` | N | — | |
| `description` | `string(200)` | N | — | |
| `kind` | `string(12)` | N | `'external'` | `internal`/`external`/`attendance` |
| `shop_name` | `string(150)` | Y | null | ★상점명 |
| `product_title` | `string(200)` | Y | null | |
| `product_price` | `unsignedInteger` | Y | null | ★가격 |
| `product_image_url` | `string(500)` | Y | null | |
| `product_emoji` | `string(8)` | Y | null | |
| `keyword` | `string(120)` | Y | null | |
| `landing_url` | `string(500)` | Y | null | = `moi.short_url` |
| `product_url` | `string(500)` | Y | null | 폴백 링크 |
| `guide` | `json` | Y | null | 참여 방법 문자열 배열 |
| `question` | `string(200)` | Y | null | |
| `placeholder` | `string(60)` | Y | null | |
| `answer` | `string(120)` | Y | null | ★**정답 — 응답 포함 절대 금지** |
| `answer_type` | `string(8)` | N | `'number'` | `number`/`text` |
| `tolerance_percent` | `unsignedTinyInteger` | Y | null | 숫자 오차 허용 % |
| `reward_item` | `string(12)` | N | `'water'` | |
| `reward_count` | `unsignedTinyInteger` | N | 1 | |
| `user_mission_cap` | `unsignedTinyInteger` | N | 1 | ★**동일 사용자 이 미션 기간 누적 상한** |
| `user_daily_cap` | `unsignedTinyInteger` | N | 1 | 동일 사용자 이 미션 1일 상한 |
| `sort_order` | `unsignedSmallInteger` | N | 0 | |
| `synced_at` | `timestamp` | Y | null | 마지막 동기화 |
| `timestamps` | | | | |

**인덱스** (3개):
- `unique('order_item_id','fms_item')` — 동기화 upsert 키 겸 중복 미러 방지
- `index(['status','starts_on','ends_on'],'fms_window')` — 노출 후보 배치 조회
- `index(['order_id','day_no'],'fms_order')` — 세부주문서 역참조(어드민)

**예상 행 수**: 활성 500 + 종료 누적 → **연 5만~20만** · **보관**: 무기한(정산 증빙 원천)

---

### 2-4. `farm_mission_daily_counters` — ★미션×일 한도 게이트

| 컬럼 | 타입 | NULL | 기본값 | 설명 |
|---|---|---|---|---|
| `mission_id` | `unsignedBigInteger` | N | — | **PK 1** |
| `stat_date` | `date` | N | — | **PK 2** (KST) |
| `daily_quota` | `unsignedInteger` | N | 0 | 그날 한도 스냅샷 |
| `slot_ratios` | `json` | Y | null | 그날 구간 배분 스냅샷 |
| `used` | `unsignedInteger` | N | 0 | ★**일별 소진량** |
| `attempt_count` | `unsignedInteger` | N | 0 | 시도(오답 포함) |
| `overflow_count` | `unsignedInteger` | N | 0 | 한도 초과 확정 건 |
| `first_used_at` | `timestamp` | Y | null | |
| `last_used_at` | `timestamp` | Y | null | |
| `created_at`/`updated_at` | `timestamps()` | | | |

**인덱스** (2개):
- `primary(['mission_id','stat_date'])` — **대리키 `id` 없음.** 원자 UPDATE가 클러스터드 PK를 직격해야 하고, 세컨더리 unique를 경유하면 랜덤 IO가 1회 더 든다
- `index(['stat_date','mission_id'],'fmdc_date')` — 일별 잔여 현황·롤업

> rankfree 관례(`$table->id()` + unique)와 다른 이유를 마이그레이션 docblock에 명시할 것. 선례: `api_key_usages`는 `unique(api_key_id, used_date)`이지만 초당 60 UPDATE를 받지 않는다.

**행 확보**: 미션 동기화 배치가 매일 00:05 KST에 **당일 + 익일** 행을 미리 `insertOrIgnore`로 만든다. 참여 경로에서 `firstOrCreate`를 부르지 않는다(레이스 + 응답 지연).

**예상 행 수**: 500 × 365 = **18만/년** · **보관**: 13개월(약 20만 행) → 그 이후는 `farm_mission_daily_stats`가 대체

---

### 2-5. `farm_mission_snapshots` — 목록 응답 캐시 원본

| 컬럼 | 타입 | NULL | 기본값 | 설명 |
|---|---|---|---|---|
| `slot_key` | `string(40)` | N | — | **PK**. `'active'` (필요 시 `'active:slot3'`) |
| `payload` | `mediumText` | N | — | 미션 배열 JSON (정답 계열 제외) |
| `item_count` | `unsignedSmallInteger` | N | 0 | |
| `built_at` | `timestamp` | N | — | |
| `updated_at` | `timestamp` | Y | null | |

**인덱스**: PK만 · **행 수**: < 10 · **보관**: 무기한(덮어쓰기)

**왜 파일이 아니라 DB인가**: 웹 서버가 **3대**라 로컬 파일은 서버마다 다른 시점의 목록을 내려준다. DB를 원장으로 두고 각 서버가 60초 TTL로 APCu(L1) → Redis(L2) 순으로 캐싱하면 3대가 같은 스냅샷을 본다. Redis가 죽어도 DB 1행 SELECT로 저하 동작한다(infra-constraints §원칙 3).
`payload` 크기: 미션 500개 × 1KB ≈ 500KB → `mediumText`(16MB) 여유.

---

### 2-6. `farm_participation_logs` — ★참여 로그 (append-only · 월 RANGE 파티션)

| 컬럼 | 타입 | NULL | 기본값 | 설명 |
|---|---|---|---|---|
| `id` | `unsignedBigInteger` autoIncrement | N | — | **PK 1** |
| `stat_month` | `unsignedInteger` | N | — | **PK 2 · 파티션 키** (YYYYMM) |
| `stat_date` | `date` | N | — | 참여일(KST) |
| `slot_no` | `unsignedTinyInteger` | N | 0 | **시간구간 0~6** (§3-3) |
| `farm_user_id` | `unsignedBigInteger` | N | — | **FK 없음**(파티션 제약) |
| `mission_id` | `unsignedBigInteger` | N | — | **FK 없음** |
| `order_item_id` | `unsignedBigInteger` | N | 0 | ★정산 귀속 |
| `order_id` | `unsignedBigInteger` | N | 0 | ★정산 귀속 |
| `vendor_id` | `unsignedBigInteger` | Y | null | ★정산 귀속 |
| `planting_id` | `unsignedBigInteger` | Y | null | |
| `plot_index` | `unsignedTinyInteger` | Y | null | 복구용 자기기술 |
| `day_no` | `unsignedTinyInteger` | Y | null | 1~7 복구용 |
| `round_no` | `unsignedSmallInteger` | Y | null | 복구용 |
| `crop_id` | `string(20)` | Y | null | 복구용 |
| `result` | `string(10)` | N | — | `correct`/`wrong`/`rejected` |
| `reject_reason` | `string(24)` | Y | null | `daily_limit`/`cooldown`/`plot_done`/`mission_closed`/`quota_full`/`slot_full`/`mission_cap`/`point_cap`/`too_fast`/`blocked`/`plot_empty` |
| `answer_norm` | `string(64)` | Y | null | 정규화 입력. **`result='correct'`면 NULL**(정답과 동일하므로 저장 불필요) |
| `revenue_unit_price` | `decimal(12,2)` | N | 0 | ★**단가 스냅샷** |
| `payout_point` | `unsignedSmallInteger` | N | 0 | ★**적립 포인트 스냅샷** |
| `mission_seq_no` | `unsignedInteger` | N | 0 | ★**그날 그 미션 순번**(초과 판정) |
| `daily_quota` | `unsignedInteger` | N | 0 | ★그 시점 한도 스냅샷 |
| `is_overflow` | `boolean` | N | false | `mission_seq_no > daily_quota` |
| `ip` | `string(45)` | Y | null | 어뷰징 조사 |
| `created_at` | `timestamp` | N | — | **`updated_at` 없음** (파티션 테이블 관례) |

**인덱스** (PK + 보조 2개, 이게 상한):
- `primary(['id','stat_month'])` — 파티션 키를 PK에 포함해야 하는 MariaDB 제약
- `index(['stat_date','mission_id'],'fpl_date')` — 일 마감 롤업 (§4-2 Q11)
- `index(['farm_user_id','id'],'fpl_user')` — CS·어뷰징 사용자 이력 (§4-2 Q13)

**❌ 만들지 않는 인덱스와 근거**
| 후보 | 왜 안 만드나 |
|---|---|
| `(result, created_at)` | 선택도 낮음(정답 비율 70~90%) → 옵티마이저가 안 쓴다. 결과별 집계는 롤업이 담당 |
| `(planting_id)` | 7일 검증을 `farm_plantings.day_mask`로 대체해 조회 자체가 사라짐 |
| `(ip)` | 어뷰징 조사는 실시간이 아님. 배치가 파티션 스캔으로 처리 |
| `(mission_id, stat_date)` | 미션별 기간 조회는 `farm_mission_daily_stats`(18만 행)에서. 1.2억 행 로그를 미션으로 뒤질 이유 없음 |
| `(is_overflow)` | 초과는 하루 수십 건 수준. 롤업 시 `stat_date` 인덱스로 이미 스캔 |

**❌ 저장하지 않는 컬럼: `answer_raw`(원문 200자)**
2.3억 행 × 평균 40바이트 = **9GB**. 어뷰징 패턴 분석에는 `answer_norm`(정규화 후, 64자)로 충분하다. 원문이 필요한 사고 조사는 애플리케이션 로그(`Log::info`, 14일 보관)에서 본다.

**행 크기 추정**: bigint×7=56B + date 3B + tinyint×4=4B + smallint×2=4B + int×3=12B + decimal(12,2) 6B + varchar 합계 ≈ 45B + timestamp 4B ≈ **134B** → InnoDB 오버헤드 +25% = **약 168B/행**

**예상 행 수 · 용량**
| 기간 | 행 수 | 데이터 | 인덱스(2개) | 합계 |
|---|---|---|---|---|
| 1일 | 130만 | 0.21GB | 0.09GB | **0.30GB** |
| 1개월 | 3,900만 | 6.3GB | 2.7GB | **9.0GB** |
| **3개월(보관 기간)** | **1.17억** | **19GB** | **8GB** | **27GB** |

**보관 기간: 3개월** (파티션 4개 + `pmax`) — §3-2에서 근거

---

### 2-7. `farm_user_mission_counters` — 사용자×미션 누적 (사전 필터링)

| 컬럼 | 타입 | NULL | 기본값 | 설명 |
|---|---|---|---|---|
| `farm_user_id` | `unsignedBigInteger` | N | — | **PK 1** |
| `mission_id` | `unsignedBigInteger` | N | — | **PK 2** |
| `done_count` | `unsignedSmallInteger` | N | 0 | 기간 누적 참여 수 |
| `today_count` | `unsignedTinyInteger` | N | 0 | 그 미션 오늘 참여 수 |
| `last_done_on` | `date` | N | — | `today_count`의 기준일 |
| `created_at` | `timestamp` | N | — | **`updated_at` 없음** (쓰기 1회 절감 × 하루 100만) |

**인덱스** (2개):
- `primary(['farm_user_id','mission_id'])` — 클러스터드. `/missions` 응답의 "이미 다 한 미션 제외"가 **PK prefix range scan** 1회로 끝난다(사용자당 행 수 ≈ 참여한 미션 수, 보통 10~50)
- `index(['mission_id','farm_user_id'],'fumc_mission')` — 미션 종료 후 청크 삭제 + covering

**원자 갱신 (2-step, 상한 검사 포함)**
```sql
-- ① 기존 행이 있으면 상한 검사와 함께 증가
UPDATE farm_user_mission_counters
   SET done_count  = done_count + 1,
       today_count = IF(last_done_on = :today, today_count + 1, 1),
       last_done_on = :today
 WHERE farm_user_id = :u AND mission_id = :m
   AND done_count < :user_mission_cap
   AND IF(last_done_on = :today, today_count, 0) < :user_daily_cap;
-- affected=1 → 통과

-- ② affected=0 이면 첫 참여일 수 있으므로 INSERT 시도
INSERT INTO farm_user_mission_counters
       (farm_user_id, mission_id, done_count, today_count, last_done_on, created_at)
VALUES (:u, :m, 1, 1, :today, NOW());
-- 성공 → 통과 / 중복키 예외(1062) → 상한 초과 확정, reject_reason='mission_cap'
```
`INSERT ... ON DUPLICATE KEY UPDATE`를 쓰지 않는 이유: 상한 조건부 거부를 표현할 수 없어 초과 참여가 통과한다.

**예상 행 수**: 미션 평균 노출 7일 × 하루 100만 참여의 중복 제거 후 → **정상 상태 약 3,000만 행** (약 2GB + 인덱스 1GB)
**보관**: `farm_missions.ends_on + 7일` 경과 시 `mission_id` 단위 청크 삭제(1회 5,000행, `farm:prune-counters` 매일 04:00 KST)

---

### 2-8. `farm_plantings` — 재배 회차 + ★부채 원장

| 컬럼 | 타입 | NULL | 기본값 | 설명 |
|---|---|---|---|---|
| `id` | `id()` | N | — | |
| `farm_user_id` | `foreignId` → `farm_users` cascadeOnDelete | N | — | 파티션 아님 → FK 유지 |
| `plot_index` | `unsignedTinyInteger` | N | — | 0~2 |
| `round_no` | `unsignedSmallInteger` | N | 1 | 같은 밭의 몇 번째 재배 |
| `crop_id` | `string(20)` | N | — | `farm_crops.code` |
| `required_days` | `unsignedTinyInteger` | N | 7 | 심을 때 스냅샷 |
| `day_mask` | `unsignedSmallInteger` | N | 0 | ★**일차 비트마스크** (1일차=bit0) |
| `completed_days` | `unsignedTinyInteger` | N | 0 | `BIT_COUNT(day_mask)`와 동치. sqlite 폴백용 |
| `status` | `string(12)` | N | `'growing'` | `growing`/`ready`/`harvested`/`abandoned` |
| `planted_on` | `date` | N | — | |
| `last_tended_on` | `date` | Y | null | ★**하루 1회 게이트** |
| `accrued_points` | `unsignedInteger` | N | 0 | ★**확정 부채** (참여 적립 누계) |
| `expected_crop_points` | `unsignedInteger` | N | 0 | ★**조건부 부채** (수확 보너스 스냅샷) |
| `harvested_at` | `timestamp` | Y | null | |
| `ledger_id` | `unsignedBigInteger` | Y | null | `farm_point_ledgers.id` |
| `timestamps` | | | | |

**인덱스** (3개):
- `unique(['farm_user_id','plot_index','round_no'],'fpg_uni')` — 회차 중복 방지
- `index(['farm_user_id','status'],'fpg_user')` — `/me/state` 밭 조회 (§4-2 Q9)
- `index(['status','planted_on'],'fpg_debt')` — ★**미지급 부채 집계** (§5-3)

**★ 하루치 성장 원자 UPDATE (`farm_planting_days` 테이블을 대체)**
```sql
UPDATE farm_plantings
   SET day_mask       = day_mask | (1 << :day_no_minus_1),
       completed_days = completed_days + 1,
       last_tended_on = :today,
       accrued_points = accrued_points + :payout_point,
       status         = IF(completed_days + 1 >= required_days, 'ready', 'growing'),
       updated_at     = NOW()
 WHERE id = :planting_id
   AND farm_user_id = :u
   AND status = 'growing'
   AND (last_tended_on IS NULL OR last_tended_on < :today)   -- 오늘 이 밭 아직 안 돌봄
   AND (day_mask & (1 << :day_no_minus_1)) = 0;              -- 같은 일차 중복 방지
-- affected_rows = 1 → 성장 확정
```
7일 검증(수확)은 `BIT_COUNT(day_mask) >= required_days` — **비트가 7개 켜져야** 통과하므로 같은 날 두 번 넣어 7을 채우는 조작이 원천 차단된다. `completed_days` 단독 카운터보다 강하다.

**sqlite 폴백**: `BIT_COUNT()` 미지원 → 테스트 환경에서는 `completed_days >= required_days`로 판정(같은 UPDATE의 `day_mask & bit = 0` 조건이 이미 중복을 막으므로 동치).

**예상 행 수**: 활성 120만(DAU 40만 × 3밭) + 종료 90일 보관 17만/일 × 90 = 1,530만 → **약 1,650만 행** (약 1.7GB)
**보관**: 활성 무기한 · `status IN ('harvested','abandoned')` 이고 `harvested_at < now()-90일` 이면 삭제(수확 사실은 `farm_point_ledgers`에 영구 보존)

---

### 2-9. `farm_point_ledgers` — ★포인트 원장 (append-only, 수확 = 지급 1건)

| 컬럼 | 타입 | NULL | 기본값 | 설명 |
|---|---|---|---|---|
| `id` | `id()` | N | — | |
| `farm_user_id` | `unsignedBigInteger` | N | — | **FK 없음**(사용자 삭제돼도 정산 이력 보존) |
| `source` | `string(12)` | N | — | `harvest` / `adjust` / `bonus` |
| `source_id` | `unsignedBigInteger` | Y | null | `farm_plantings.id` |
| `crop_id` | `string(20)` | Y | null | |
| `amount` | `integer` | N | — | 지급 양수 / 회수·조정 음수 |
| `accrued_amount` | `unsignedInteger` | N | 0 | 참여 적립분 (7일치 합) |
| `crop_amount` | `unsignedInteger` | N | 0 | 수확 보너스분 |
| `days_completed` | `unsignedTinyInteger` | Y | null | 수확 시점 스냅샷 |
| `first_day_on` | `date` | Y | null | |
| `last_day_on` | `date` | Y | null | |
| `status` | `string(12)` | N | `'pending'` | `pending`/`requested`/`success`/`failed`/`held`/`canceled` |
| `promotion_code` | `string(40)` | Y | null | ★스냅샷 |
| `fee_rate` | `decimal(5,2)` | N | 0 | ★**수수료율 스냅샷 (%)** |
| `fee_amount` | `decimal(12,2)` | N | 0 | ★**수수료 금액** |
| `toss_key` | `string(200)` | Y | null | 멱등키 |
| `toss_key_issued_at` | `timestamp` | Y | null | 유효 1시간 판정 |
| `attempts` | `unsignedTinyInteger` | N | 0 | |
| `last_error_code` | `string(16)` | Y | null | |
| `last_error_message` | `string(200)` | Y | null | |
| `requested_at` | `timestamp` | Y | null | |
| `confirmed_at` | `timestamp` | Y | null | |
| `timestamps` | | | | |

**인덱스** (3개):
- `unique(['source','source_id'],'fpl_src')` — ★**중복 지급 최종 방어선**. 초안의 `farm_harvests.unique(planting_id)`를 흡수. `source='adjust'`는 `source_id=NULL`이라 unique를 우회(MySQL·sqlite 모두 NULL 중복 허용)
- `index(['status','updated_at'],'fpl_retry')` — 재시도 스케줄러 (§4-2 Q15)
- `index(['farm_user_id','status'],'fpl_user')` — 사용자 누적 검증(일 배치)

**❌ `(status, confirmed_at)` 인덱스는 만들지 않는다** — 기간별 지급액은 `farm_daily_stats.payout_confirmed` 롤업에서 읽는다.

**예상 행 수**: 하루 17만 → 연 6,200만. 단 **1인 5,000P 상한 ÷ 작물 평균 500P = 사용자당 최대 10 수확**이므로 실질 상한은 `누적 사용자 × 10`. 300만 사용자 → **3,000만 행이 사실상 천장**(약 6GB)
**보관**: **무기한**(세무·정산 증빙). 파티션 불필요 — §3-1 참조

---

### 2-10. 통계 테이블 3종

#### `farm_daily_stats` — 전체 일별

| 컬럼 | 타입 | NULL | 기본값 | 설명 |
|---|---|---|---|---|
| `stat_date` | `date` | N | — | **PK** |
| `dau` | `unsignedInteger` | N | 0 | `COUNT(DISTINCT farm_user_id)` |
| `new_users` | `unsignedInteger` | N | 0 | |
| `attempts` | `unsignedInteger` | N | 0 | |
| `correct` / `wrong` / `rejected` | `unsignedInteger` | N | 0 | |
| `overflow_count` | `unsignedInteger` | N | 0 | ★한도 초과 건 |
| `overflow_loss` | `decimal(14,2)` | N | 0 | ★**초과 손해액** = overflow × 단가 |
| `harvests` | `unsignedInteger` | N | 0 | |
| `revenue_amount` | `decimal(14,2)` | N | 0 | ★수입 (청구 가능분만) |
| `payout_accrued` | `decimal(14,2)` | N | 0 | ★신규 적립(부채 증가) |
| `payout_confirmed` | `decimal(14,2)` | N | 0 | ★지급 확정 |
| `fee_amount` | `decimal(14,2)` | N | 0 | ★수수료 |
| `liability_balance` | `decimal(14,2)` | N | 0 | ★**마감 부채 잔액** |
| `profit` | `decimal(14,2)` | N | 0 | ★수입 − 지급확정 − 수수료 |
| `built_at` | `timestamp` | N | — | |
| `timestamps` | | | | |

**인덱스**: PK만 · **행 수**: 365/년 · **보관**: 무기한
`decimal(14,2)` 근거: 일 수입 최대 = 100만 × 150원 = 1.5억 → 11자리. 14자리면 여유 1,000배.

#### `farm_mission_daily_stats` — 미션×일 (★정산 증빙의 최종 보관처)

| 컬럼 | 타입 | NULL | 기본값 | 설명 |
|---|---|---|---|---|
| `mission_id` | `unsignedBigInteger` | N | — | **PK 1** |
| `stat_date` | `date` | N | — | **PK 2** |
| `order_item_id` / `order_id` / `vendor_id` | `unsignedBigInteger` | N/N/Y | 0/0/null | ★정산 귀속 스냅샷 |
| `daily_quota` | `unsignedInteger` | N | 0 | |
| `used` / `correct` / `wrong` / `rejected` | `unsignedInteger` | N | 0 | |
| `overflow_count` | `unsignedInteger` | N | 0 | |
| `unique_users` | `unsignedInteger` | N | 0 | |
| `revenue_unit_price` | `decimal(12,2)` | N | 0 | ★단가 스냅샷 |
| `revenue_amount` | `decimal(14,2)` | N | 0 | `MIN(used, daily_quota) × 단가` |
| `payout_accrued` | `decimal(14,2)` | N | 0 | |
| `built_at` | `timestamp` | N | — | |
| `timestamps` | | | | |

**인덱스** (2개): `primary(['mission_id','stat_date'])` · `index(['stat_date','mission_id'],'fmds_date')`
**행 수**: 500 × 365 = **18만/년** · **보관**: **무기한** — 참여 로그를 3개월 만에 버려도 이 테이블만으로 과거 정산을 완전히 재현한다

#### `farm_mission_slot_stats` — 미션×일×시간구간

| 컬럼 | 타입 | NULL | 기본값 | 설명 |
|---|---|---|---|---|
| `mission_id` | `unsignedBigInteger` | N | — | **PK 1** |
| `stat_date` | `date` | N | — | **PK 2** |
| `slot_no` | `unsignedTinyInteger` | N | — | **PK 3** (0~6) |
| `slot_cap` | `unsignedInteger` | N | 0 | 그 구간 누적 상한 |
| `used` | `unsignedInteger` | N | 0 | 그 구간 실제 소진 |
| `rejected_full` | `unsignedInteger` | N | 0 | 구간 상한으로 거절된 수 |
| `created_at` | `timestamp` | N | — | |

**인덱스**: PK + `index(['stat_date'],'fmss_date')`
**행 수**: 500 × 7 = 3,500/일 → **128만/년** · **보관**: **90일**(약 32만 행). 구간 배분 비율 튜닝용이라 장기 보관 불필요

---

### 2-11. `farm_recommended_apps`

| 컬럼 | 타입 | NULL | 기본값 |
|---|---|---|---|
| `id` `name(40)` `description(120)` `emoji(8)` `scheme(200)` `sort_order` `is_active` `timestamps` | 초안 §2-8 그대로 | | |

**인덱스**: `index(['is_active','sort_order'],'fra_sort')` · **행 수**: < 50 · **보관**: 무기한

---

### 2-12. 전체 용량 요약

| 테이블 | 정상 상태 행 수 | 데이터+인덱스 | 보관 |
|---|---:|---:|---|
| `farm_participation_logs` | **1.17억** | **27 GB** | 3개월 |
| `farm_user_mission_counters` | 3,000만 | 3.0 GB | 미션 종료 +7일 |
| `farm_plantings` | 1,650만 | 1.7 GB | 종료 +90일 |
| `farm_point_ledgers` | 3,000만 | 6.0 GB | 무기한 |
| `farm_users` | 300만 | 0.6 GB | 무기한 |
| `farm_mission_daily_stats` | 18만/년 | 0.03 GB | 무기한 |
| `farm_mission_slot_stats` | 32만 | 0.02 GB | 90일 |
| `farm_missions` | 20만 | 0.15 GB | 무기한 |
| `farm_mission_daily_counters` | 20만 | 0.02 GB | 13개월 |
| 기타(crops/apps/snapshots/daily_stats) | < 1천 | < 0.01 GB | 무기한 |
| **합계** | **약 1.8억** | **≈ 39 GB** | |

> ⚠️ **확인 필요**: rankfree 서버(`/www/jcurve`) 디스크 여유 용량과 MariaDB datadir 위치. 39GB + 기존 rankfree(파티션 2개 포함) + 백업 여유 → **최소 150GB 여유** 확보 필요. `df -h`와 `SELECT SUM(data_length+index_length) FROM information_schema.TABLES` 로 먼저 확인할 것.

---

## 3. 파티셔닝 전략

### 3-1. 결론: **`farm_participation_logs` 만 · 월별 RANGE 파티션 · 보관 3개월**

| 대상 | 판단 | 근거 |
|---|---|---|
| `farm_participation_logs` | ✅ **월 RANGE 파티션** | 1.17억 행 / 27GB. 삭제를 `DELETE`로 하면 3,900만 행 × 월 1회 = 수 시간 락 |
| `farm_point_ledgers` | ❌ 파티션 안 함 | 실질 상한 3,000만 행(1인 5,000P 천장). 주 조회가 `(status, updated_at)` 재시도 스캔이라 **파티션하면 전 파티션 탐색**이 되어 오히려 느려진다 |
| `farm_user_mission_counters` | ❌ 파티션 안 함 | 삭제 키가 `mission_id`인데 파티션 키는 날짜여야 한다 — 정렬이 안 맞는다. `fumc_mission` 인덱스 range 청크 삭제로 충분 |
| `farm_plantings` | ❌ 파티션 안 함 | 1,650만 행. FK(`farm_user_id`)를 유지하려면 파티션 불가(MariaDB 제약) |
| 나머지 | ❌ | 전부 100만 행 미만 |

### 3-2. RANGE 파티션(월별) vs 물리 테이블 분리 — **RANGE 채택**

| 항목 | RANGE 월 파티션 ✅ | 물리 테이블 분리 (`_202607`) |
|---|---|---|
| **rankfree 선례** | `keyword_place_ranks` · `keyword_shop_ranks` **2개 운영 중** + `hub:partition-rotate` 커맨드 완성형 → **복사만 하면 됨** | 선례 0건. 새 개념 도입 |
| 기간 조회 | `WHERE stat_month BETWEEN` → 프루닝 자동 | 테이블명 조립 + `UNION ALL` 동적 SQL. Eloquent와 충돌 |
| 삭제 | `DROP PARTITION` — **메타데이터 연산, 즉시** | `DROP TABLE` — 동일하나 코드가 테이블 존재 여부를 매번 확인해야 함 |
| INSERT 경로 | 앱은 테이블 하나만 안다 | 앱이 매 INSERT마다 대상 테이블을 계산 → 월 경계 자정에 버그 지대 |
| 운영 리스크 | `pmax` 관리 실패 시 프루닝 무력화(감시 필요) | 테이블 누락 시 INSERT 실패(더 치명적) |

→ **RANGE 월 파티션.** 물리 분리의 유일한 장점(테이블당 인덱스 독립)은 파티션도 로컬 인덱스라 동일하게 얻는다.

### 3-2-1. 왜 **일별**이 아니라 **월별**인가

| 기준 | 일별 파티션 | 월별 파티션 ✅ |
|---|---|---|
| 파티션 수(3개월 보관) | 90 + pmax | **4 + pmax** |
| 파티션당 행 수 | 130만 | 3,900만 |
| 핫패스 조회 | — (핫패스는 로그를 **읽지 않는다**. 카운터 테이블만 읽는다) | — 동일 |
| 롤업 배치(하루 1회) | 파티션 1개 = 130만 행 스캔 | `stat_date` 인덱스로 130만 행 range → **동일** |
| DROP 빈도 | 매일 1회 | **월 1회** |
| `.ibd` 파일 핸들 | 90 × (데이터+인덱스) | 4 |
| REORGANIZE 위험 | 매일 → 실패 노출 90배 | 월 1회 |

→ **일별 파티션의 유일한 이점(프루닝 정밀도)이 이 설계에서는 무의미하다.** 핫패스가 로그를 읽지 않도록 설계했기 때문이다(카운터 테이블 분리). 운영 부담이 22배 낮은 월별을 택한다.

### 3-2-2. 왜 보관 **3개월**인가

| 보관 | 행 수 | 용량 | 판단 |
|---|---:|---:|---|
| 1개월 | 3,900만 | 9GB | 정산 분쟁 대응 불가(월 마감 후 이의 제기 기간) |
| **3개월** ✅ | **1.17억** | **27GB** | 광고주 이의 제기 실무 관행(익월 말)을 1개월 이상 초과 커버. 단일 MariaDB에서 여유 |
| 6개월 | 2.34억 | 54GB | 27GB 추가 지출 대비 실익 없음 — 롤업(`farm_mission_daily_stats`, 무기한)이 정산을 재현한다 |
| 13개월(rankfree 관례) | 5.1억 | 117GB | 단일 서버 디스크·백업 시간 모두 위험 |

→ **`config('rankfree.farm.log_retention_months', 3)`**. rankfree의 `HUB_RANK_RETENTION_MONTHS` 패턴 그대로 env로 노출(0 이하 = 파기 안 함).

### 3-3. 시간대 분산 — **누적 상한(cumulative cap) 방식** (제약 3)

**구간 정의** (`config('rankfree.farm.slots')`, KST):

| slot | 시간대 | 구간 비율 | **누적 상한 비율** | 일 한도 50 기준 누적 상한 |
|---|---|---:|---:|---:|
| 0 | 00:00–06:00 | 5% | 5% | 2 |
| 1 | 06:00–09:00 | 10% | 15% | 7 |
| 2 | 09:00–12:00 | 20% | 35% | 17 |
| 3 | 12:00–15:00 | 20% | 55% | 27 |
| 4 | 15:00–18:00 | 15% | 70% | 35 |
| 5 | 18:00–21:00 | 20% | 90% | 45 |
| 6 | 21:00–24:00 | 10% | **100%** | **50** |

비율 근거: 토스 미니앱 트래픽은 출근(08–09)·점심(12–13)·퇴근(18–19)·취침 전(22–23)에 몰린다. 심야는 수요가 적어 5%만 배정하되 **0으로 막지 않는다** — 사용자 쿨다운이 2시간이라 밭 3칸을 채우려면 최소 4시간이 필요하고, 야간 근무자·해외 이용자의 3번째 참여가 심야에 걸린다.

**핵심 아이디어: 구간별 카운터를 만들지 않고, 같은 카운터에 "현재 시각까지 허용되는 누적 상한"만 조건으로 건다.**

```sql
UPDATE farm_mission_daily_counters
   SET used = LAST_INSERT_ID(used + 1),         -- ★갱신 후 값을 seq 로 회수
       attempt_count = attempt_count + 1,
       last_used_at  = NOW(),
       first_used_at = COALESCE(first_used_at, NOW())
 WHERE mission_id = :m AND stat_date = :today
   AND used < daily_quota                        -- ⑤ 일 한도(최종 방어선)
   AND used < :slot_cap;                         -- ★ 현재 구간 누적 상한
-- affected = 1 → SELECT LAST_INSERT_ID() 로 mission_seq_no 획득
```
`:slot_cap` = `FLOOR(daily_quota × 누적비율[현재 slot])` — 애플리케이션이 계산해 바인딩한다.

**이 방식이 해결하는 것**

| 요구사항 | 해결 |
|---|---|
| **하루 한도를 시간 구간으로 배분** | 누적 상한 테이블로 표현. 오전에 50건이 다 소진되는 일이 구조적으로 불가능(09–12시에는 최대 17건) |
| **구간 미소진분 이월** | **자동.** 09–12시에 10건만 나갔으면 12시 상한 27에서 17건이 남아 그대로 이월된다. **별도 이월 계산 로직이 필요 없다** |
| **심야 시간대 처리** | slot 0 = 5%. `config('rankfree.farm.night_mode')` = `ratio`(기본) / `closed`(slot_cap=0) 로 전환 가능 |
| **구간 경계 동시성** | **원천 제거.** 경계에서 `slot_cap` 값만 커질 뿐 카운터는 연속이다. 카운터를 리셋·이관하지 않으므로 경계 트랜잭션이 존재하지 않는다 |
| 서버 3대 시계 오차 | slot_cap이 잠시 서버마다 다를 수 있으나, **`used < daily_quota`가 항상 함께 걸려 있어 총량은 절대 초과하지 않는다.** NTP 동기화는 권장 사항일 뿐 정합성 조건이 아니다 |

**왜 `farm_mission_slot_counters` 테이블을 만들지 않는가**: 구간별 실시간 카운터를 두면 ①UPDATE가 2개(일+구간)로 늘어 쓰기 비용 2배 ②구간 전환 시 "미소진분 이월" 계산 트랜잭션이 필요 ③경계에서 두 카운터가 불일치할 수 있다. 누적 상한은 **카운터 1개 · UPDATE 1개 · 이월 로직 0줄**로 같은 결과를 낸다. 구간별 소진 **통계**는 `farm_participation_logs.slot_no` 를 롤업해 `farm_mission_slot_stats`에 남긴다(§2-10).

### 3-4. MariaDB 11.4.2 제약 — 반드시 지킬 5가지

| # | 제약 | 이 설계의 대응 |
|---|---|---|
| 1 | 파티션 키는 **모든 PK/UNIQUE에 포함**돼야 함 | `PRIMARY KEY (id, stat_month)`. 로그에 다른 UNIQUE를 **만들지 않는다** |
| 2 | 파티션 테이블에 **FK 사용 불가** (선언·피참조 모두) | `farm_user_id`·`mission_id`·`order_item_id` 전부 plain `unsignedBigInteger` + index |
| 3 | `$table->id()`는 PK가 파티션 키를 포함하지 않아 `PARTITION BY`가 실패 | rankfree 관례대로 `unsignedBigInteger('id', false)->autoIncrement()->startingValue(1)` 로 만든 뒤 raw `ALTER TABLE ... DROP PRIMARY KEY, ADD PRIMARY KEY (id, stat_month)` |
| 4 | `REORGANIZE PARTITION pmax`는 **pmax가 비어 있을 때만 즉시 완료** | 로테이션 커맨드가 **+2개월 선생성**. 실패하면 알림(§3-5) |
| 5 | 로컬·CI는 **sqlite** | 파티션·복합PK·raw INDEX·`LAST_INSERT_ID(expr)`·`BIT_COUNT()` 를 전부 `if (DB::connection()->getDriverName() === 'mysql')` 로 분기하고 sqlite 폴백 작성. 테스트는 `HubPartitionRotateTest` 패턴 복제 |

추가 주의:
- 파티션 테이블에 `timestamps()`를 넣지 않는다(`created_at` 1개만) — rankfree 관례
- MariaDB는 MySQL 8의 `INSERT ... AS new ON DUPLICATE KEY UPDATE` 별칭 문법이 다르다 → `VALUES()` 함수를 쓴다
- `LAST_INSERT_ID(expr)`·`BIT_COUNT()`는 MariaDB 11.4에서 정상 지원 ✅

### 3-5. 파티션 생성·삭제 자동화

**커맨드**: `app/Console/Commands/FarmPartitionRotate.php` (`farm:partition-rotate`) — `HubPartitionRotate` 복제

```
스케줄 (routes/console.php):
  Schedule::command('farm:partition-rotate')
      ->timezone('Asia/Seoul')->dailyAt('05:40')
      ->withoutOverlapping()->runInBackground();
  ※ hub:partition-rotate 가 05:50 이므로 10분 앞에 둬 ALTER 가 겹치지 않게 한다

동작:
  1) 선생성: 이번 달 ~ +2개월
     information_schema.PARTITIONS 로 존재 확인
     없으면 ALTER TABLE farm_participation_logs
            REORGANIZE PARTITION pmax INTO (
              PARTITION p{YYYYMM} VALUES LESS THAN ({다음달YYYYMM}),
              PARTITION pmax      VALUES LESS THAN MAXVALUE)
     ※ 기존 파티션 최댓값 이하의 월은 건너뛴다(RANGE 는 연속)
  2) 파기 전 아카이브: cutoff = 이번달 − retention_months
     farm:archive-logs --month={YYYYMM} 를 먼저 호출하고 성공 시에만 DROP
  3) 파기: ALTER TABLE farm_participation_logs DROP PARTITION p{YYYYMM}
  4) 감시: pmax 의 TABLE_ROWS 가 0 이 아니면 Log::error + 잔디 알림
     → 로테이션이 멈춰 신규 월이 pmax 로 몰린 상태 = 프루닝 무력화 신호
  sqlite 폴백: where('stat_month','<',$cutoff)->delete()
```

**테이블 생성 시**: 13개월치가 아니라 **`retention_months + 3` 개월치**(기본 6개)만 선생성한다. 3개월 보관이므로 13개월 선생성은 빈 파티션 낭비.

### 3-6. 아카이빙 — 시점과 대상

**커맨드**: `farm:archive-logs --month=YYYYMM` (`FarmArchiveLogs`)

| 항목 | 내용 |
|---|---|
| **시점** | 파티션 DROP **직전** (매월 1일 05:40, 로테이션 커맨드가 호출) |
| **대상** | `farm_participation_logs`의 해당 월 전체 |
| **형식** | TSV + gzip. `storage/app/farm/archive/participation_{YYYYMM}.tsv.gz` |
| **방법** | `chunkById(5000)` 순회 → `gzwrite`. `SELECT ... INTO OUTFILE`은 `FILE` 권한과 `secure_file_priv` 때문에 쓰지 않는다 |
| **크기** | 3,900만 행 × 134B ÷ 압축률 8:1 ≈ **월 650MB** |
| **검증** | 압축 파일 행 수 == `COUNT(*)` 일치 확인. **불일치면 DROP 하지 않고 중단 + 알림** |
| **보존** | 로컬 디스크 12개월(7.8GB) → 그 이후 외부 스토리지 이관 또는 삭제 |
| **아카이브하지 않는 것** | `farm_user_mission_counters`(파생), `farm_plantings` 종료분(원장이 `farm_point_ledgers`에 있음), `farm_mission_slot_stats`(튜닝용) |

> **아카이브가 없어도 정산은 재현된다.** `farm_mission_daily_stats`(미션×일, 단가 포함, 무기한)가 정산의 법적 원장이다. 아카이브는 "특정 사용자가 몇 시에 무엇을 제출했나" 수준의 분쟁 대응용이다.

---

## 4. 인덱스 설계

### 4-1. 먼저 — 어떤 쿼리가 언제 얼마나 도는가

| # | 쿼리 | 빈도 | 대상 | 접근 |
|---|---|---:|---|---|
| Q1 | 노출 후보 미션 목록 → 스냅샷 굽기 | **60초 1회** | `farm_missions` ⋈ `farm_mission_daily_counters` | `fms_window` |
| Q2 | 사용자 상태·쿨다운 조회 | **120~1,200/s** | `farm_users` | PK / `fu_key` |
| Q3 | 참여 슬롯 확보 UPDATE | **12~58/s** | `farm_users` | PK |
| Q4 | 밭 하루치 성장 UPDATE | 12~58/s | `farm_plantings` | PK |
| Q5 | 미션 일 카운터 UPDATE | 12~58/s | `farm_mission_daily_counters` | PK |
| Q6 | 미션 전체 소진 UPDATE | 12~58/s | `farm_missions` | PK |
| Q7 | 사용자×미션 카운터 갱신 | 12~58/s | `farm_user_mission_counters` | PK |
| Q8 | 참여 로그 INSERT | 12~58/s | `farm_participation_logs` | append |
| Q9 | 목록에서 "이미 다 한 미션" 제외 | **120~1,200/s** | `farm_user_mission_counters` | **PK prefix range** |
| Q10 | `/me/state` 밭 조회 | 120~1,200/s | `farm_plantings` | `fpg_user` |
| Q11 | 일 마감 롤업 | **1일 1회** | `farm_participation_logs` | `fpl_date` |
| Q12 | 미션별 기간 통계(어드민) | 하루 수십 | `farm_mission_daily_stats` | PK |
| Q13 | CS·어뷰징 사용자 이력 | 하루 수십 | `farm_participation_logs` | `fpl_user` |
| Q14 | 미지급 부채 집계 | 하루 수회 | `farm_plantings` | `fpg_debt` |
| Q15 | 지급 재시도 스캔 | **5분 1회** | `farm_point_ledgers` | `fpl_retry` |
| Q16 | 미션 동기화 증분 | 5분 1회 | `marketing_order_items`(rankfree) | `(status, work_date)` 기존 인덱스 |
| Q17 | 카운터 정리 | 1일 1회 | `farm_user_mission_counters` | `fumc_mission` |

**설계 원칙 — 읽기 QPS 1,200을 감당하는 방법**: Q2·Q9·Q10 세 개가 전체 읽기의 99%다. 셋 다 **클러스터드 PK 또는 PK prefix range**로 처리되도록 스키마를 잡았다(각각 `farm_users.id`, `farm_user_mission_counters(farm_user_id, …)`, `farm_plantings(farm_user_id, status)`). 세컨더리 인덱스 → PK 룩업(2회 IO)이 발생하는 핫 쿼리가 **하나도 없다.**

### 4-2. 쿼리 → 인덱스 도출 (카디널리티 · 선택도 근거)

| 인덱스 | 담당 쿼리 | 선행 컬럼 카디널리티 | 선택도 | 근거 |
|---|---|---|---|---|
| `farm_users` PK | Q2 Q3 | 300만 | 1/300만 | 유일 |
| `fu_key (user_key_hash)` UNIQUE | Q2 최초 조회 | 300만 | 1/300만 | sha256 → 완전 균일 분포 |
| `fu_status (status, id)` | 어드민 차단 목록 | 2 | 0.1% (blocked) | 선행 컬럼 카디널리티 2로 나쁘지만, `blocked` 쪽 선택도가 극히 낮아 유효. `active` 조회는 애초에 없음 |
| `farm_missions` `fms_window (status, starts_on, ends_on)` | Q1 | status 5 / starts_on 365 | `active` ≈ 0.5% | 등호(status) 먼저 + 범위(날짜) 나중 — rankfree 관례. `ends_on`은 두 번째 범위라 인덱스로는 못 쓰지만 covering 효과 |
| `fms_item (order_item_id)` UNIQUE | Q16 upsert | 20만 | 유일 | |
| `fms_order (order_id, day_no)` | 어드민 역참조 | 5만 | 회차 수만큼 | |
| `farm_mission_daily_counters` PK `(mission_id, stat_date)` | **Q5** | 미션 20만 × 날짜 | 유일 | ★클러스터드. 초당 58 UPDATE가 여기 직격 |
| `fmdc_date (stat_date, mission_id)` | 어드민 일별 현황·롤업 | 365 | 1/365 | |
| `farm_participation_logs` PK `(id, stat_month)` | Q8 | 유일 | — | 파티션 필수. auto_increment 순차 삽입 = **랜덤 IO 없음** |
| **`fpl_date (stat_date, mission_id)`** | **Q11** | 파티션 내 30일 → 1/30 | 3.3% | 롤업이 `GROUP BY mission_id`를 인덱스 순서 그대로 읽는다(정렬 제거). `(mission_id, stat_date)`로 하면 파티션 전체를 훑어야 함 |
| **`fpl_user (farm_user_id, id)`** | **Q13** | 40만/월 활성 | 1/40만 | `id` 후행으로 최근순 정렬 무비용 |
| `farm_user_mission_counters` PK `(farm_user_id, mission_id)` | **Q7 Q9** | 300만 | 사용자당 10~50행 | ★클러스터드 range scan. `/missions`가 이 한 번으로 끝난다 |
| `fumc_mission (mission_id, farm_user_id)` | Q17 삭제 | 20만 | 미션당 수천~수만 | covering — 삭제 대상을 PK만 읽어 확보 |
| `farm_plantings` `fpg_uni (farm_user_id, plot_index, round_no)` UNIQUE | 회차 생성 | 300만 | 유일 | |
| `fpg_user (farm_user_id, status)` | **Q10** | 300만 | 사용자당 3행 | |
| `fpg_debt (status, planted_on)` | **Q14** | status 4 | `growing` 60% | 선행 카디널리티는 낮지만 **`planted_on` 범위 스캔이 목적**이고 대안이 풀스캔(1,650만 행)이라 압도적 이득 |
| `farm_point_ledgers` `fpl_src (source, source_id)` UNIQUE | 중복 지급 차단 | 3,000만 | 유일 | ★DB 레벨 최종 방어선 |
| `fpl_retry (status, updated_at)` | **Q15** | status 6 | `pending+requested+held` < 1% | 5분마다 200건 limit — 선택도 낮은 상태만 조회하므로 유효 |
| `fpl_user (farm_user_id, status)` | 일 배치 정합 검증 | 300만 | 사용자당 10행 | |

### 4-3. 쓰기 부하 대비 인덱스 개수 상한

**원칙: 초당 58 INSERT를 받는 테이블의 보조 인덱스는 2개까지.**

| 테이블 | 쓰기 QPS | PK | 보조 인덱스 | 인덱스 쓰기/초 |
|---|---:|---:|---:|---:|
| `farm_participation_logs` | 58 (INSERT) | 1 (순차) | **2** | 174 |
| `farm_mission_daily_counters` | 58 (UPDATE) | 1 | 1 | 58 (인덱스 컬럼 미변경 → 보조 인덱스 갱신 없음) |
| `farm_users` | 58 (UPDATE) | 1 | 2 | 58 (동일) |
| `farm_plantings` | 58 (UPDATE) + 2 (INSERT) | 1 | 3 | 60 |
| `farm_user_mission_counters` | 58 | 1 | 1 | 58 |
| `farm_point_ledgers` | **2** (하루 17만) | 1 | 3 | 8 |

**계산**: `farm_participation_logs`가 최악이다. PK는 auto_increment 순차라 **B-Tree 오른쪽 끝에만 삽입**(랜덤 IO 0). 보조 인덱스 2개는 랜덤 위치 삽입이지만 MariaDB **Change Buffer**가 흡수한다(보조 인덱스는 unique가 아니므로 change buffer 적용 대상 ✅). 초당 174 인덱스 쓰기는 NVMe·Change Buffer 조합에서 여유다.

→ **보조 인덱스를 3개로 늘리면** 랜덤 IO가 50% 증가하고, `is_overflow`·`result` 같은 저선택도 인덱스는 옵티마이저가 쓰지도 않는다. **2개가 상한이다.**

**Change Buffer를 살리기 위한 조건**: `farm_participation_logs`에 **UNIQUE 인덱스를 걸지 않는다**(unique는 change buffer 미적용 — 즉시 중복 검사가 필요하므로). 이것이 §3-4 제약 1과 함께 "로그에 UNIQUE 금지"의 두 번째 이유다.

### 4-4. Hot row 샤딩 — **불채택**

infra-constraints.md가 "샤드 개수는 설계서에서 확정"이라고 남긴 항목에 대한 답이다.

**결론: 샤딩하지 않는다(샤드 1개).**

```
미션당 피크 UPDATE QPS
  = 전체 피크 쓰기 QPS ÷ 피크에 실제로 열려 있는 미션 수
  = 58 ÷ 200
  = 0.29 / 초
```

InnoDB 행 락은 같은 행에 **초당 500회 이상** UPDATE가 몰릴 때부터 대기가 관측된다(락 보유 시간 ≈ 0.2~1ms). 0.29 QPS는 그 **1,700분의 1**이다.

**최악 시나리오 검산**: 활성 미션이 20개까지 줄고, 하루 트래픽의 30%가 1시간에 몰린다면
`1,000,000 × 0.3 ÷ 3,600 ÷ 20 = 4.2 QPS/미션` — 여전히 여유 100배.

**샤딩을 하면 오히려 손해인 이유**:
1. 잔여 확인이 `SUM(used)` 이 되어 **읽기 경로가 무거워진다**(현재는 PK 1행 읽기)
2. 샤드 간 불균형으로 "전체 잔여는 있는데 내 샤드는 소진" 상황이 생겨 **참여를 부당하게 거절**한다 — 광고주 한도를 다 못 채우면 그것도 손해다
3. `mission_seq_no`(초과 판정용 순번)를 샤드별로 매기면 전역 순번이 사라져 초과 감지가 깨진다

**대신 넣는 안전장치**: 카운터 UPDATE 실패(`affected=0`)를 즉시 거절로 처리하지 말고 **1회 재시도**(지터 `usleep(random_int(5_000, 20_000))`)한다. rankfree의 `ShopKeywordExposureController::short()` 패턴을 축소 적용(10회 → 1회, 여기서는 CAS가 아니라 조건부 UPDATE라 경합이 훨씬 낮다).

**재검토 트리거**: `farm_mission_daily_stats`에서 미션 하나의 일 `used`가 **50,000건**을 넘기면(= 평균 0.58 QPS, 피크 3 QPS) 샤딩을 다시 검토한다. 그 전까지는 하지 않는다.

---

## 5. 정산용 스냅샷 컬럼

### 5-1. 왜 스냅샷인가

`marketing_orders.unit_price`는 **주문 시점 스냅샷**이지만, 같은 광고주가 재주문하면 새 주문의 단가가 달라진다. 더 위험한 것은 `marketing_products.min_price`(단가 원천)를 운영자가 언제든 수정할 수 있다는 점이다. 참여 로그가 `mission_id`만 들고 있으면 **과거 정산을 재실행할 때 오늘 단가로 계산되어 금액이 바뀐다.**

→ **참여 1건 = 정산 1건.** 그 시점의 금액 결정 요소를 전부 로그 행에 박는다.

### 5-2. 스냅샷 대상 — 전체 목록

#### A. `farm_participation_logs` (참여 시점)

| 컬럼 | 원천 | 왜 스냅샷해야 하나 |
|---|---|---|
| `revenue_unit_price` | `marketing_orders.unit_price` | ★**수입 단가.** 재주문·상품 단가 수정으로 바뀐다 |
| `payout_point` | `farm_missions.payout_point` | ★**지출 단가.** 어드민에서 언제든 바꾼다 |
| `order_item_id` | `marketing_order_items.id` | ★**청구 귀속.** 세부주문서가 `regenerate()`로 삭제·재생성돼도 과거 정산이 유지된다 |
| `order_id` | `marketing_order_items.order_id` | 광고주 단위 집계 |
| `vendor_id` | `marketing_order_items.vendor_id` | 퀴즈농장 vendor id가 교체돼도 과거 귀속 유지 |
| `mission_id` | `farm_missions.id` | 미션 단위 집계 |
| `daily_quota` | `farm_mission_daily_counters.daily_quota` | ★**초과 판정 재현.** 나중에 한도를 바꿔도 그날의 판정이 재현된다 |
| `mission_seq_no` | UPDATE 반환값 | ★**그날 그 미션의 순번.** `seq > quota` = 초과분 |
| `is_overflow` | 계산값 | 청구 불가 = 손해 건 플래그 |
| `slot_no` | 참여 시각 | 시간대 분산 실적 |
| `stat_date` / `created_at` | KST | 정산 기간 귀속 |

#### B. `farm_plantings` (심기 시점 · 부채 인식)

| 컬럼 | 원천 | 왜 |
|---|---|---|
| `required_days` | `farm_crops.days` | 정책이 7일→5일로 바뀌어도 진행 중 작물은 계약대로 7일 |
| `expected_crop_points` | `farm_crops.points` | ★**조건부 부채 금액 고정.** 수확 보너스를 나중에 올려도 진행 중 작물엔 소급 안 함 |
| `accrued_points` | 참여마다 누적 | ★**확정 부채.** 이미 발생한 지급 의무 |
| `crop_id` | | |

#### C. `farm_point_ledgers` (지급 시점)

| 컬럼 | 왜 |
|---|---|
| `amount` / `accrued_amount` / `crop_amount` | 참여분과 수확 보너스분을 분리 보관 → 원인별 지출 분석 |
| `promotion_code` | 프로모션이 교체돼도 어느 예산에서 나갔는지 추적 |
| `fee_rate` / `fee_amount` | ★**수수료율 스냅샷.** 토스 정책 변경 시 과거 수익 계산이 흔들리지 않게 |
| `days_completed` / `first_day_on` / `last_day_on` | "7일 동안 매일 참여" 심사 증빙 |
| `confirmed_at` | 회계 기간 귀속(발생주의 vs 현금주의 구분) |

#### D. `farm_mission_daily_stats` (일 마감 · 최종 보관)

`revenue_unit_price` + `daily_quota` + `used` + `overflow_count` 를 함께 남겨, **참여 로그를 3개월 만에 버려도 정산을 완전히 재현**한다.

### 5-3. 정산 4대 지표 — 계산식과 조회 위치

| 지표 | 계산식 | 조회 위치 | 비용 |
|---|---|---|---|
| **참여당 비용(수입)** | `SUM(MIN(used, daily_quota) × revenue_unit_price)` | `farm_mission_daily_stats` | PK range · 18만 행/년 |
| **참여당 비용(지출)** | `SUM(payout_point)` | `farm_daily_stats.payout_accrued` | PK · 365행/년 |
| **지불한 금액** | `SUM(amount + fee_amount)` where `status='success'` | `farm_daily_stats.payout_confirmed` + `.fee_amount` | PK |
| **★기간별 앞으로 지불할 금액(부채)** | 아래 2단 계산 | `farm_plantings` `fpg_debt` | 인덱스 range |
| **기간별 참여량** | `SUM(used)` / `SUM(correct)` | `farm_daily_stats` · `farm_mission_daily_stats` | PK |
| **수익** | `revenue_amount − payout_confirmed − fee_amount` | `farm_daily_stats.profit` | PK |

**★ 미래 지급 의무(부채) 계산 — "진행 중 작물 = 부채"**

```sql
-- ① 확정 부채: 이미 발생한 적립. 사용자가 수확만 하면 반드시 나간다
SELECT SUM(accrued_points) AS confirmed_liability
  FROM farm_plantings
 WHERE status IN ('growing','ready');

-- ② 조건부 부채: 7일을 채워야 나가는 수확 보너스. 진행률로 가중
SELECT SUM(expected_crop_points * completed_days / required_days) AS expected_liability
  FROM farm_plantings
 WHERE status IN ('growing','ready');

-- ③ 최대 노출: 진행 중 작물이 전부 완주한다고 가정한 보수적 상한
SELECT SUM(accrued_points + expected_crop_points) AS max_liability
  FROM farm_plantings
 WHERE status IN ('growing','ready');
```
→ `fpg_debt (status, planted_on)` 인덱스가 `status IN (...)` 를 range로 처리한다. 대상 행 120만.
→ 매일 00:30 KST 롤업이 ①+② 를 `farm_daily_stats.liability_balance`에 기록. 어드민은 롤업 값을 읽고, 실시간 확인이 필요할 때만 위 쿼리를 직접 돌린다.

**부채 잔액 검증 항등식** (일 마감 배치가 매일 검사, 어긋나면 알림):
```
liability_balance(오늘) = liability_balance(어제)
                        + payout_accrued(오늘)      -- 신규 적립
                        - payout_confirmed(오늘)    -- 지급 확정
                        - liability_written_off     -- 포기·만료 작물
```

### 5-4. 한도 초과 감지·정산 (제약 1)

**초과가 발생하는 경로**는 하나뿐이다: **미션 동기화 지연.** rankfree 어드민에서 일 한도를 50→30으로 낮췄는데 미러가 아직 50인 5분 창.
(동시 접속으로 인한 초과는 `used < daily_quota` 원자 UPDATE가 원천 차단한다 — `affected_rows` 판정에 레이스가 없다.)

| 단계 | 처리 |
|---|---|
| **감지** | 동기화가 `daily_quota`를 낮출 때 `used > 새 quota` 이면 초과 확정. 그 시점 `overflow_count = used − 새 quota` 를 `farm_mission_daily_counters`에 기록 |
| **표시** | 일 마감 롤업이 `farm_mission_daily_stats.overflow_count` + `farm_daily_stats.overflow_loss` (= 초과건 × 단가) 집계 |
| **청구** | `revenue_amount = MIN(used, daily_quota) × revenue_unit_price` — **초과분은 청구하지 않는다** |
| **손해 인식** | `overflow_loss` 가 우리 손해. 일 5건 이상이면 잔디 알림 |
| **최소화** | ①동기화 주기 5분 → 한도 **하향** 감지 시 즉시 재동기화(rankfree 어드민 저장 훅) ②`used`가 `daily_quota`의 90%를 넘으면 스냅샷 TTL을 60초 → **10초**로 단축 |

**목표치**: 초과율 **0.01% 미만**(하루 100건 이하, 손해 15,000원/일 이하). `farm_daily_stats.overflow_loss / revenue_amount` 로 매일 감시.

---

## 6. 마이그레이션 작성 시 지켜야 할 rankfree 관례 체크리스트

- [ ] `return new class extends Migration` 익명 클래스 + 파일 상단 한글 docblock("퀴즈농장(29) — 무엇을·왜·2026-07-28")
- [ ] 클로저 인자는 `function (Blueprint $t)` (2026-07-21 이후 신규 관례)
- [ ] 컬럼 정의 줄 끝에 `// 한글 설명` (특히 status 후보값·단위·기본값 의도)
- [ ] 연관 테이블은 한 파일(`create_farm_mission_tables.php`), `down()`은 생성 역순 `dropIfExists`
- [ ] 파티션·복합PK·raw INDEX·`LAST_INSERT_ID(expr)`·`BIT_COUNT()` 는 `if (DB::connection()->getDriverName() === 'mysql')` 분기 + sqlite 폴백
- [ ] 파티션 테이블은 `timestamps()` 금지 → `created_at` 1개
- [ ] 파티션 테이블에 FK·UNIQUE 금지
- [ ] 날짜 캐스트는 `'date:Y-m-d'` 고정 (순수 `'date'`는 sqlite에서 시분초까지 저장돼 일 단위 키가 깨진다)
- [ ] 인덱스 이름을 명시적으로 부여(`fpl_date` 등, 64자 제한 회피)
- [ ] 인덱스·파생 테이블에 **실측 근거 주석**("1.17억 행 · 롤업 GROUP BY 정렬 제거" 같은 수치)
- [ ] 모델은 `app/Models/` 평면 배치, 신규이므로 Laravel 13 `#[Fillable]` + `casts()` 신문법
- [ ] 카운터 3종(`farm_mission_daily_counters`·`farm_user_mission_counters`)은 **Eloquent 모델을 만들지 않고 `DB::table()` Query Builder로만 다룬다**(복합 PK는 Eloquent가 지원하지 않고, 원자 UPDATE에 모델 이벤트가 끼면 안 된다)
- [ ] `config/rankfree.php`에 `farm` 블록 추가 — `vendor_id` · `quota_mode` · `slots` · `night_mode` · `log_retention_months` · `cooldown_minutes` · `daily_limit` · `point_cap`
- [ ] 캐시에는 배열·스칼라만(`serializable_classes => false`)

---

## 부록. 참여 확정 트랜잭션 — 원자 UPDATE 실행 순서

데드락 방지를 위해 **항상 이 순서로만** 잠근다.

```
DB::transaction(function () {
  1. farm_users            원자 UPDATE  (일 3회 · 2시간 쿨다운 · 5,000P 상한)  → affected=1 필수
  2. farm_plantings        원자 UPDATE  (밭 하루 1회 · day_mask 비트)          → affected=1 필수
  3. farm_mission_daily_counters UPDATE (daily_quota · slot_cap 누적 상한)     → affected=1, seq 회수
  4. farm_missions         원자 UPDATE  (total_quota)                          → affected=1 필수
  5. farm_user_mission_counters 2-step  (user_mission_cap · user_daily_cap)    → 통과 필수
  6. farm_participation_logs INSERT     (append-only, 스냅샷 전량)
});
// 커밋 이후: 7일 완주(status='ready')면 수확 안내만. 지급은 /harvest 에서.
```

**순서 근거**
1. **1·2는 사용자 단위**라 경합이 자기 자신뿐 → 먼저 처리해 대부분의 거절을 여기서 끝낸다(공유 자원 락을 아예 잡지 않음)
2. **3·4는 공유 자원(hot row 후보)** → 가장 나중에, 가장 짧게 잡는다. 락 보유 시간 = 두 UPDATE + INSERT ≈ 1ms 미만
3. 3 → 4 순서를 **절대 뒤집지 않는다**(반대 순서로 잠그는 경로가 생기면 즉시 데드락)
4. 6(로그 INSERT)이 마지막인 이유: 순차 auto_increment라 락 경합이 없고, 앞선 판정 결과(`mission_seq_no`)를 스냅샷해야 한다

**거절(rejected) 로그**: 1~5 중 어디서 실패하든 트랜잭션을 롤백한 뒤, **별도 트랜잭션 밖에서** `farm_participation_logs`에 `result='rejected'` + `reject_reason` 을 INSERT한다(롤백에 휩쓸리면 어뷰징 추적이 불가능해진다).
