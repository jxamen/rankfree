# 퀴즈농장 미니앱 — rankfree(Laravel) 백엔드 설계서

> 대상 저장소: `C:\Users\jxame\Documents\project\rankfree` (Laravel 13.8 / PHP 8.3)
> 클라이언트: `C:\Users\jxame\Documents\project\toss_inapp_farmer\farm-quiz` (앱인토스 미니앱)
> 작성일: 2026-07-28
> rankfree 설계 문서로 등록할 때 파일명: `.claude/29_TOSS_FARM_MINIAPP.md` (기존 최대 번호 28)
> 등록 후 `.claude/CLAUDE.md` 하단 문서 표에 한 줄 추가할 것.

---

## 1. 개요

### 1-1. 무엇을 만드나

rankfree에 **토스 앱인토스 미니앱 "퀴즈농장"의 백엔드**를 추가한다.
rankfree에는 게임·미션·리워드 기능이 **전혀 없으므로** 도메인·스키마·화면을 전부 새로 만든다.
(기존 `ExtQuizController`/`QuizSolver`는 네이버 캡차를 AI로 푸는 유틸이라 이 기능과 무관하다 — 이름만 같다.)

### 1-2. 게임 규칙 (서버가 강제하는 것)

| 규칙 | 값 | 서버 강제 지점 |
|---|---|---|
| 밭 칸 수 | 3 | `farm_plantings.plot_index` 0~2 |
| 작물 코스 | 7일 | `farm_plantings.required_days` |
| 하루 참여 한도 | 3회 (밭당 1회) | `farm_planting_days` unique(user, plot_index, work_date) |
| 정답 | 서버에만 존재 | `farm_missions.answer` + `#[Hidden]` + 직렬화 화이트리스트 |
| 수확 조건 | 서버 기록 7일 | `farm_planting_days` count |
| 중복 수확 | 금지 | `farm_harvests` unique(planting_id) |
| 1인 누적 포인트 | 5,000P | `farm_point_ledgers` 합계 + 트랜잭션 락 |

### 1-3. 3계층 원칙

```
[상태 테이블]  farm_plantings / farm_users.total_points      ← 빠른 조회용 "캐시"
[로그 테이블]  farm_planting_days / farm_mission_logs        ← append-only "사실"
              farm_harvests / farm_point_ledgers
[마스터]      farm_missions / farm_crops / farm_recommended_apps
```

- **판정은 언제나 로그로 한다.** 수확 7일 검증은 `farm_plantings.completed_days`가 아니라 `farm_planting_days` count로 한다.
- 상태 테이블이 손상돼도 `php artisan farm:rebuild-state`로 로그에서 전부 복구된다(§10-3).
- 로그 테이블은 애플리케이션에서 **절대 UPDATE/DELETE 하지 않는다.** 취소는 반대 부호의 새 행으로 표현한다.

### 1-4. rankfree 관례 대비 의도적 예외 3가지

| 예외 | 내용 | 이유 |
|---|---|---|
| `user_id` 대신 `farm_user_id` | 미니앱 사용자는 `users`(rankfree 회원)가 아니다 | 토스 익명키 기반의 별개 신원 축. `users`에 섞으면 회원 등급·과금·메뉴 권한이 오염된다 |
| `setUserResolver()` 미사용 | `$request->attributes->get('farm_user')` 사용 | `FarmUser`는 `Authenticatable`이 아니다. `$request->user()`를 오염시키면 다른 미들웨어/헬퍼가 오작동한다 |
| 로그 테이블에 FK 없음 | `farm_mission_logs.mission_id` 등은 plain `unsignedBigInteger` + index | 미션·상태 행이 삭제돼도 로그는 남아야 한다(사용자 요구: DB 유실 대비) |

### 1-5. 재사용하는 기존 rankfree 코드

| 대상 | 경로 | 무엇을 베끼나 |
|---|---|---|
| 토큰 인증 미들웨어 골격 | `app/Http/Middleware/AuthenticateExtToken.php` | sha256 저장·401 한글 메시지·`last_used_at` 1분 단위 갱신·`attributes->set()` |
| API 키 3중 게이트 | `app/Http/Middleware/AuthenticateApiKey.php` | 429 + `X-RateLimit-*` 헤더 패턴 |
| 별도 라우트 파일 로딩 | `routes/coupon.php` + `bootstrap/app.php` `then:` | `routes/farm.php` 분리 방식 |
| 관리자 CRUD 완성형 | `app/Http/Controllers/Admin/VendorController.php` + `resources/views/admin/vendors/{index,form}.blade.php` | 목록/폼/토글/삭제 마크업 통째로 |
| 관리자 메뉴 마이그레이션 | `database/migrations/2026_07_22_000200_add_coupon_menus.php` | `insertMenu()` 헬퍼 그대로 복사 |
| 낙관적 락 카운터 | `app/Domain/Shopping/ShopKeywordShortLinkService.php` `short()` | 동시성 카운터 패턴 (여기서는 `lockForUpdate` 채택) |
| 자기 재큐 배치 Job | `app/Jobs/ShopKeywordCheckJob.php` | 지급 재시도 Job의 sync 커넥션 가드 |
| 런타임 설정 오버라이드 | `app/Providers/SettingsServiceProvider.php` + `app_settings` | 포인트 한도·프로모션 코드를 어드민에서 바꾸기 |

---

## 2. DB 스키마

파일명 규칙: `YYYY_MM_DD_HHMMSS_동사_대상.php`, HHMMSS는 손으로 고른 일련번호.
2026-07-28 날짜의 다른 마이그레이션과 겹치지 않도록 `1000xx` 대역을 쓴다.
전부 `return new class extends Migration` 익명 클래스 + 파일 상단 한글 docblock("퀴즈농장(29) — …").

### 2-1. `2026_07_28_100000_create_farm_users_table.php`

**farm_users** — 토스 미니앱 사용자 신원. rankfree 회원(`users`)과 완전히 분리된다.

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| id | `id()` | — | PK |
| user_key_hash | `string(64)` | — | `hash('sha256', x-user-key)`. **평문은 저장하지 않는다** |
| key_type | `string(8)` | `'anon'` | `anon`(getAnonymousKey) / `toss`(토스 로그인 userKey) |
| toss_user_key | `string(200) nullable` | null | 토스 로그인 연동 시 `x-toss-user-key` 원문(**암호화 저장**, `encrypted` cast) |
| total_points | `unsignedInteger` | 0 | 누적 지급 포인트 **캐시**. 판정은 원장 합계로 한다 |
| correct_count | `unsignedInteger` | 0 | 정답 누적(운영 지표) |
| harvest_count | `unsignedSmallInteger` | 0 | 수확 누적(운영 지표) |
| status | `string(12)` | `'active'` | `active` / `blocked` |
| blocked_reason | `string(120) nullable` | null | 차단 사유(어뷰징 기록) |
| last_seen_at | `timestamp nullable` | null | 1분 단위로만 갱신 |
| created_at/updated_at | `timestamps()` | — | KST |

인덱스: `unique('user_key_hash')`, `index(['status','created_at'], 'fu_status')`

### 2-2. `2026_07_28_100100_create_farm_crops_table.php`

**farm_crops** — 작물 마스터 + 수확 보상 금액. `GET /reward/info`의 유일한 소스.

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| id | `id()` | — | |
| code | `string(20)` | — | `lettuce`/`carrot`/`onion`/`potato`/`tomato`/`corn`. 클라이언트 `cropId`와 1:1 |
| name | `string(40)` | — | 상추, 당근 … |
| emoji | `string(8)` | — | 🥬 |
| days | `unsignedTinyInteger` | 7 | 코스 일수 |
| points | `unsignedInteger` | 0 | 수확 시 지급 포인트 |
| sort_order | `unsignedSmallInteger` | 0 | |
| is_active | `boolean` | true | |
| timestamps | | | |

인덱스: `unique('code')`, `index(['is_active','sort_order'], 'fc_sort')`

> 시더 `database/seeders/FarmCropSeeder.php`로 6종 초기 투입(클라이언트 `src/game/types.ts`의 `CROPS`와 code·days 일치 필수).

### 2-3. `2026_07_28_100200_create_farm_missions_table.php`

**farm_missions** — 미션/퀴즈 마스터. **정답 컬럼 포함, 관리자만 등록.**

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| id | `id()` | — | 응답에서 문자열로 캐스팅해 내보낸다(클라 `Mission.id: string`) |
| title | `string(80)` | — | 목록 제목 |
| description | `string(200)` | — | 한 줄 설명 |
| kind | `string(12)` | `'external'` | `internal` / `external` / `attendance` |
| product_name | `string(120) nullable` | null | 퀴즈 소재 상품명 |
| product_image_url | `string(500) nullable` | null | 상품 이미지 URL |
| product_emoji | `string(8) nullable` | null | 이미지 없을 때 대체 이모지 |
| guide | `json nullable` | null | 참여 방법 **문자열 배열**. 클라가 번호 매겨 표시 |
| hint_url | `string(500) nullable` | null | '힌트 보기' 링크(외부확인형만) |
| question | `string(200) nullable` | null | 질문 문구 |
| placeholder | `string(60) nullable` | null | 입력칸 안내 |
| **answer** | `string(120) nullable` | null | **정답. 응답에 절대 포함 금지** |
| answer_type | `string(8)` | `'number'` | `number` / `text` |
| tolerance_percent | `unsignedTinyInteger nullable` | null | 숫자 정답 오차 허용(%) — 가격처럼 변동값용 |
| reward_item | `string(12)` | `'water'` | `water`/`sunlight`/`fertilizer`/`pesticide` |
| reward_count | `unsignedTinyInteger` | 1 | 지급 개수 |
| points | `unsignedSmallInteger` | 0 | 참여 포인트(0이면 아이템만) |
| daily_limit | `unsignedTinyInteger` | 1 | 1인 1일 이 미션 참여 한도 |
| starts_at | `dateTime` | — | 노출 시작 |
| ends_at | `dateTime` | — | 노출 종료 |
| is_active | `boolean` | true | |
| sort_order | `unsignedSmallInteger` | 0 | |
| created_by | `foreignId nullable` | null | `->constrained('users')->nullOnDelete()` (운영자 기록) |
| timestamps | | | |

인덱스: `index(['is_active','starts_at','ends_at'], 'fm_window')`, `index(['sort_order','id'], 'fm_sort')`

### 2-4. `2026_07_28_100300_create_farm_mission_logs_table.php`

**farm_mission_logs** — **append-only.** 정답/오답/거절 **전부** 남긴다. 어뷰징 추적·상태 복구의 1차 근거.

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| id | `id()` | — | |
| farm_user_id | `foreignId` | — | `->constrained('farm_users')->cascadeOnDelete()` |
| mission_id | `unsignedBigInteger` | — | **FK 없음** — 미션이 지워져도 로그는 남는다 |
| plot_index | `unsignedTinyInteger nullable` | null | 대상 밭 |
| planting_id | `unsignedBigInteger nullable` | null | **FK 없음** |
| answer_raw | `string(200)` | — | 사용자 입력 원문(어뷰징 분석용) |
| answer_norm | `string(200)` | — | 정규화 결과 |
| result | `string(12)` | — | `correct` / `wrong` / `rejected` |
| reject_reason | `string(24) nullable` | null | `daily_limit`/`plot_done`/`mission_closed`/`too_fast`/`already_done`/`plot_empty`/`point_cap`/`blocked` |
| reward_item | `string(12) nullable` | null | 정답일 때만 |
| reward_count | `unsignedTinyInteger nullable` | null | |
| points | `unsignedSmallInteger` | 0 | 실제 적립 예약 금액(한도로 깎였으면 깎인 값) |
| ip | `string(45) nullable` | null | Cloudflare 헤더 우선 |
| timestamps | | | 행은 절대 UPDATE 하지 않는다 |

인덱스: `index(['farm_user_id','created_at'], 'fml_user')`, `index(['mission_id','created_at'], 'fml_mission')`, `index(['result','created_at'], 'fml_result')`

### 2-5. `2026_07_28_100400_create_farm_plantings_tables.php`

한 파일에 연관 테이블 2개(`create_x_tables` 관례).

**farm_plantings** — 재배 1회차(사이클). 밭 슬롯이 아니라 **회차**다. 수확하면 그 행은 `harvested`로 닫히고, 다시 심으면 `round_no+1` 행이 새로 생긴다.

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| id | `id()` | — | |
| farm_user_id | `foreignId` | — | `->constrained('farm_users')->cascadeOnDelete()` |
| plot_index | `unsignedTinyInteger` | — | 0~2 |
| round_no | `unsignedSmallInteger` | 1 | 같은 밭의 몇 번째 재배인지 |
| crop_id | `string(20)` | — | `farm_crops.code` |
| required_days | `unsignedTinyInteger` | 7 | 심을 때 `farm_crops.days` 스냅샷 |
| completed_days | `unsignedTinyInteger` | 0 | **캐시**. 판정에 쓰지 않는다 |
| status | `string(12)` | `'growing'` | `growing`/`ready`/`harvested`/`abandoned` |
| planted_on | `date` | — | 심은 날(KST) |
| last_tended_date | `date nullable` | null | 마지막 참여일 |
| harvested_at | `timestamp nullable` | null | |
| timestamps | | | |

인덱스: `unique(['farm_user_id','plot_index','round_no'], 'fpl_uni')`, `index(['farm_user_id','status'], 'fpl_status')`

> "한 밭에 활성 재배 1개"는 부분 unique로 표현할 수 없다(sqlite/MySQL 호환). **트랜잭션 + `FarmUser` 행 `lockForUpdate()`** 로 보장한다.

**farm_planting_days** — **append-only.** "며칠 참여했는가"의 **유일한 진실**.

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| id | `id()` | — | |
| farm_user_id | `foreignId` | — | `->constrained('farm_users')->cascadeOnDelete()` |
| planting_id | `unsignedBigInteger` | — | **FK 없음**(재배 행이 지워져도 참여 이력은 남긴다) |
| plot_index | `unsignedTinyInteger` | — | 복구용 자기기술 컬럼 |
| round_no | `unsignedSmallInteger` | — | 복구용 |
| crop_id | `string(20)` | — | 복구용 |
| day_no | `unsignedTinyInteger` | — | 1~7 |
| work_date | `date` | — | 참여일(KST) |
| mission_id | `unsignedBigInteger nullable` | null | |
| mission_log_id | `unsignedBigInteger nullable` | null | |
| timestamps | | | |

인덱스:
- `unique(['planting_id','day_no'], 'fpd_day')` — 같은 회차 같은 일차 중복 금지
- `unique(['farm_user_id','plot_index','work_date'], 'fpd_slot')` — **하루에 밭마다 1회** 정책의 DB 레벨 강제
- `index(['farm_user_id','work_date'], 'fpd_user_date')` — 오늘 참여 수 집계

### 2-6. `2026_07_28_100500_create_farm_harvests_table.php`

**farm_harvests** — **append-only.** 수확 사실.

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| id | `id()` | — | |
| farm_user_id | `foreignId` | — | `->constrained('farm_users')->cascadeOnDelete()` |
| planting_id | `unsignedBigInteger` | — | **FK 없음** |
| plot_index | `unsignedTinyInteger` | — | |
| round_no | `unsignedSmallInteger` | — | |
| crop_id | `string(20)` | — | |
| days_completed | `unsignedTinyInteger` | — | 수확 시점 로그 count 스냅샷 |
| first_day_date | `date` | — | 첫 참여일 |
| last_day_date | `date` | — | 마지막 참여일 |
| points | `unsignedInteger` | 0 | 지급 예약 금액(한도로 깎였으면 깎인 값) |
| ledger_id | `unsignedBigInteger nullable` | null | `farm_point_ledgers.id` |
| harvested_at | `timestamp` | — | |
| timestamps | | | |

인덱스: **`unique('planting_id', 'fh_planting')` ← 중복 수확 방지의 최종 방어선**, `index(['farm_user_id','harvested_at'], 'fh_user')`

### 2-7. `2026_07_28_100600_create_farm_point_ledgers_table.php`

**farm_point_ledgers** — **append-only 포인트 원장.** 토스 프로모션 지급의 상태 머신을 여기서 관리한다.

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| id | `id()` | — | |
| farm_user_id | `foreignId` | — | `->constrained('farm_users')->cascadeOnDelete()` |
| source | `string(12)` | — | `mission` / `harvest` / `adjust`(운영 조정) |
| source_id | `unsignedBigInteger nullable` | null | `farm_mission_logs.id` 또는 `farm_harvests.id` |
| crop_id | `string(20) nullable` | null | |
| amount | `integer` | — | 지급은 양수, 회수·조정은 음수 |
| status | `string(12)` | `'pending'` | `pending`→`requested`→`success` / `failed` / `held` / `canceled` |
| promotion_code | `string(40) nullable` | null | 지급 당시 프로모션 코드 스냅샷 |
| toss_key | `string(200) nullable` | null | `getKey` 결과 = **멱등키**. 재시도에 같은 값을 쓴다 |
| toss_key_issued_at | `timestamp nullable` | null | **유효시간 1시간** 판정용 |
| attempts | `unsignedTinyInteger` | 0 | |
| last_error_code | `string(16) nullable` | null | `4109`/`4112`/`4113` 등 |
| last_error_message | `string(200) nullable` | null | |
| requested_at | `timestamp nullable` | null | `executePromotion` 호출 시각 |
| confirmed_at | `timestamp nullable` | null | `getExecutionResult`로 확정한 시각 |
| timestamps | | | |

인덱스:
- **`unique(['source','source_id'], 'fpg_src')` ← 같은 수확/미션에 두 번 지급 불가(중복 지급 방지의 핵심)**
- `index(['status','created_at'], 'fpg_status')` — 재시도 스케줄러가 긁는다
- `index(['farm_user_id','status'], 'fpg_user')` — 누적 한도 계산

> `source='adjust'`는 `source_id`가 NULL이라 unique에 걸리지 않는다(MySQL·SQLite 모두 NULL 중복 허용).

### 2-8. `2026_07_28_100700_create_farm_recommended_apps_table.php`

**farm_recommended_apps**

| 컬럼 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| id | `id()` | — | |
| name | `string(40)` | — | |
| description | `string(120)` | — | |
| emoji | `string(8)` | — | |
| scheme | `string(200)` | — | `intoss://…` (`intoss-private://`는 QR 테스트 전용 — 저장 시 경고) |
| sort_order | `unsignedSmallInteger` | 0 | |
| is_active | `boolean` | true | |
| timestamps | | | |

인덱스: `index(['is_active','sort_order'], 'fra_sort')`

### 2-9. `2026_07_28_100800_add_farm_admin_menus.php`

`2026_07_22_000200_add_coupon_menus.php`의 `insertMenu()` 헬퍼를 그대로 복사해 `menus`에 `area='admin'` 행 4개를 넣는다(존재 검사로 멱등, `down()`에서 delete).

| name | route | icon |
|---|---|---|
| 퀴즈농장 미션 | `admin.farm-missions` | `fa-solid fa-seedling` |
| 퀴즈농장 작물 | `admin.farm-crops` | `fa-solid fa-carrot` |
| 퀴즈농장 포인트 | `admin.farm-ledgers` | `fa-solid fa-coins` |
| 추천 미니앱 | `admin.farm-apps` | `fa-solid fa-mobile-screen` |

> ⚠️ 메뉴 행이 없으면 사이드바에 안 뜨고, `admin.layout`·`x-console.page-head`가 페이지 제목을 못 찾고, 브레드크럼 부모도 안 잡힌다.

---

## 3. 모델

전부 `app/Models/` 평면 배치, 네임스페이스 `App\Models`, `extends Illuminate\Database\Eloquent\Model`.
**신규이므로 Laravel 13 신문법**(`#[Fillable([...])]` 애트리뷰트 + `protected function casts(): array`)을 쓴다. `$fillable` 배열 방식과 섞지 않는다.
클래스 위에 한 줄 한글 docblock + `(29)` 표기. SoftDeletes·팩토리는 만들지 않는다.

### 3-1. `FarmUser`

```
/** 퀴즈농장(29) 미니앱 사용자 — 토스 익명키 sha256 로만 식별한다(2026-07-28). */
#[Fillable(['user_key_hash','key_type','toss_user_key','total_points','correct_count','harvest_count','status','blocked_reason','last_seen_at'])]
#[Hidden(['user_key_hash','toss_user_key'])]
```
- casts: `last_seen_at`→`datetime`, `total_points`/`correct_count`/`harvest_count`→`integer`, `toss_user_key`→`encrypted`
- 관계: `plantings(): HasMany`, `missionLogs(): HasMany`, `harvests(): HasMany`, `ledgers(): HasMany(FarmPointLedger::class)`
- 정적: `findOrCreateByKey(string $plain): self` — `hash('sha256',$plain)`으로 `firstOrCreate`
- 메서드: `isBlocked(): bool`, `grantedPoints(): int`(원장 합계, §6-6)
- 상수: `public const STATUSES = ['active' => '정상', 'blocked' => '차단'];`

### 3-2. `FarmCrop`
- `#[Fillable(['code','name','emoji','days','points','sort_order','is_active'])]`
- casts: `days`/`points`/`sort_order`→`integer`, `is_active`→`boolean`
- 스코프: `scopeActive(Builder $q): Builder` → `->where('is_active', true)->orderBy('sort_order')->orderBy('id')`

### 3-3. `FarmMission`

```
/** 퀴즈농장(29) 미션·퀴즈 마스터 — answer 는 서버 전용, 응답에 절대 싣지 않는다. */
#[Fillable([...전체...])]
#[Hidden(['answer','answer_type','tolerance_percent'])]
```
- casts: `guide`→`array`, `starts_at`/`ends_at`→`datetime`, `is_active`→`boolean`, 숫자류→`integer`
- 상수(값이 한글 라벨 — rankfree 관례):
  ```
  public const KINDS = ['internal' => '앱내완결', 'external' => '외부확인', 'attendance' => '출석'];
  public const ITEMS = ['water' => '물', 'sunlight' => '햇빛', 'fertilizer' => '비료', 'pesticide' => '벌레약'];
  public const ANSWER_TYPES = ['number' => '숫자', 'text' => '자유 텍스트'];
  ```
- 스코프: `scopeVisible(Builder $q): Builder` → `is_active=true AND starts_at <= now() AND ends_at >= now()`
- 관계: `creator(): BelongsTo(User::class, 'created_by')`

> `#[Hidden]`은 **2차 방어선**이다. 1차 방어선은 컨트롤러의 `private function missionJson()` 화이트리스트다. `toArray()`를 그대로 내보내는 코드를 절대 쓰지 말 것.

### 3-4. `FarmPlanting`
- `#[Fillable(['farm_user_id','plot_index','round_no','crop_id','required_days','completed_days','status','planted_on','last_tended_date','harvested_at'])]`
- casts: `planted_on`/`last_tended_date`→`date`, `harvested_at`→`datetime`, 숫자류→`integer`
- 관계: `farmUser(): BelongsTo`, `days(): HasMany(FarmPlantingDay::class, 'planting_id')`
- 상수: `public const STATUSES = ['growing' => '자라는 중', 'ready' => '수확 가능', 'harvested' => '수확 완료', 'abandoned' => '중단'];`
- 메서드: `isActive(): bool` → `in_array($this->status, ['growing','ready'])`

### 3-5. `FarmPlantingDay`
- `#[Fillable(['farm_user_id','planting_id','plot_index','round_no','crop_id','day_no','work_date','mission_id','mission_log_id'])]`
- casts: `work_date`→`date`, 숫자류→`integer`

### 3-6. `FarmMissionLog`
- `#[Fillable([...전체...])]`
- 상수: `public const REJECT_REASONS = ['daily_limit' => '하루 참여 한도', 'plot_done' => '오늘 이미 돌본 밭', 'mission_closed' => '종료된 미션', 'too_fast' => '제출 간격 초과', 'already_done' => '이미 참여한 미션', 'plot_empty' => '돌볼 밭 없음', 'point_cap' => '누적 포인트 한도', 'blocked' => '차단 계정'];`

### 3-7. `FarmHarvest`
- `#[Fillable([...])]`, casts: `first_day_date`/`last_day_date`→`date`, `harvested_at`→`datetime`

### 3-8. `FarmPointLedger`
- `#[Fillable([...])]`
- casts: `amount`/`attempts`→`integer`, `toss_key_issued_at`/`requested_at`/`confirmed_at`→`datetime`
- 상수:
  ```
  public const STATUSES = [
      'pending'   => '지급 대기',
      'requested' => '지급 요청됨',
      'success'   => '지급 완료',
      'failed'    => '지급 실패',
      'held'      => '보류(예산·승인)',
      'canceled'  => '취소',
  ];
  /** 누적 한도 계산에 포함되는 상태 — 실패·취소는 한도에서 되돌려준다 */
  public const COUNTED = ['pending', 'requested', 'success'];
  ```
- 스코프: `scopeCounted(Builder $q): Builder` → `->whereIn('status', self::COUNTED)`
- 메서드: `keyExpired(): bool` → `toss_key_issued_at === null || toss_key_issued_at->lt(now()->subMinutes(55))` (토스 key 유효 1시간, 5분 여유)

### 3-9. `FarmRecommendedApp`
- `#[Fillable(['name','description','emoji','scheme','sort_order','is_active'])]`, `scopeActive()`

---

## 4. 라우트

### 4-1. 어느 파일에?

**새 파일 `routes/farm.php`** 를 만들고 `bootstrap/app.php`의 `withRouting(then:)`에서 require 한다. `routes/coupon.php`와 같은 방식이다.

- `routes/api.php`에 넣지 **않는다** — 이 파일은 크롬 확장(`ext`)과 외부 판매 API(`v1`) 전용이고, 미니앱은 제3의 클라이언트 계열이다. 파일이 이미 크다.
- `routes/apiv1.php`는 **절대 건드리지 않는다** — `ApiV1ServiceProvider`가 로드하는 구식 잔재이고, URI가 겹치면 조용히 죽는다.

```php
// bootstrap/app.php
then: function () {
    require __DIR__.'/../routes/coupon.php';   // 쿠폰(26) — 별도 파일
    require __DIR__.'/../routes/farm.php';     // 퀴즈농장(29) — 토스 미니앱 전용 API
},
```

> `then:`으로 로드하는 파일은 prefix·미들웨어가 자동으로 안 붙는다. **`middleware('api')`를 직접 명시**해야 `SubstituteBindings`가 동작한다(coupon.php가 `['web','auth','operator']`를 명시하는 것과 같은 이유).

### 4-2. `routes/farm.php` 구조

```php
<?php
/*
|--------------------------------------------------------------------------
| 퀴즈농장(29) — 토스 앱인토스 미니앱 전용 API
|--------------------------------------------------------------------------
| 클라이언트가 부르는 경로가 `/me/state`, `/missions` 처럼 고정돼 있어
| VITE_API_BASE_URL = https://rankfree.kr/api/farm 으로 맞춘다(앱 수정 불필요).
| 인증은 x-user-key 헤더(auth.farm). 쿠키 세션은 iOS 서드파티 쿠키 차단으로 못 쓴다.
| 경로를 api/* 아래 두는 이유: 프레임워크 기본 CORS(paths=['api/*'])와
| bootstrap/app.php 의 shouldRenderJsonWhen(request->is('api/*')) 를 그대로 타기 위함.
*/
Route::prefix('api/farm')->middleware('api')->group(function (): void {
    Route::middleware('auth.farm')->group(function (): void {

        // 내 농장 상태 — 서버가 원장. 클라 localStorage 는 캐시일 뿐이다.
        Route::get('/me/state', [FarmStateController::class, 'show'])->middleware('throttle:60,1');

        // 밭에 작물 심기 — 참여 시작 시점을 서버에 남겨야 7일 검증이 가능하다.
        Route::post('/plots/{index}/plant', [FarmStateController::class, 'plant'])
            ->whereNumber('index')->middleware('throttle:20,1');

        // 오늘의 미션 — 정답(answer)은 절대 포함하지 않는다.
        Route::get('/missions', [FarmMissionController::class, 'index'])->middleware('throttle:60,1');

        // 정답 제출(채점) — 쓰기이자 어뷰징 표적이라 20/분. 컨트롤러가 별도 쿨다운도 건다.
        Route::post('/missions/{mission}/submit', [FarmMissionController::class, 'submit'])
            ->whereNumber('mission')->middleware('throttle:20,1');

        // 수확 — 7일 검증·중복 방지·누적 한도를 전부 서버가 판정한다.
        Route::post('/harvest', [FarmHarvestController::class, 'store'])->middleware('throttle:20,1');

        // 작물별 보상 금액 — 정책이 바뀌어도 앱 재배포가 필요 없게 서버가 내려준다.
        Route::get('/reward/info', [FarmHarvestController::class, 'rewardInfo'])->middleware('throttle:60,1');

        // 추천 미니앱 — 제휴가 바뀌어도 앱 재배포 불필요.
        Route::get('/recommended-apps', [FarmAppController::class, 'index'])->middleware('throttle:60,1');
    });
});
```

- 라우트 **이름은 붙이지 않는다**(`/api/ext/*`·v1 그룹과 동일한 현재 관례).
- 고정 경로가 `{param}` 앞에 오도록 선언 순서를 지킨다. 여기서는 충돌이 없지만 확장 시 주의.
- 컨트롤러는 파일 상단 `use App\Http\Controllers\Api\Farm...Controller;` **use import** 방식으로 쓴다(FQCN 인라인보다 읽기 좋다).
- `api` 미들웨어 그룹에 **전역 throttle이 없다.** 라우트마다 붙이지 않으면 진짜 무제한이다.

### 4-3. 최종 URL 매핑

| 클라이언트 호출 | 실제 URL | 메서드 |
|---|---|---|
| `GET /me/state` | `https://rankfree.kr/api/farm/me/state` | `FarmStateController@show` |
| `POST /plots/:index/plant` | `/api/farm/plots/{index}/plant` | `FarmStateController@plant` |
| `GET /missions` | `/api/farm/missions` | `FarmMissionController@index` |
| `POST /missions/:id/submit` | `/api/farm/missions/{mission}/submit` | `FarmMissionController@submit` |
| `POST /harvest` | `/api/farm/harvest` | `FarmHarvestController@store` |
| `GET /reward/info` | `/api/farm/reward/info` | `FarmHarvestController@rewardInfo` |
| `GET /recommended-apps` | `/api/farm/recommended-apps` | `FarmAppController@index` |

클라이언트 `.env`: `VITE_API_BASE_URL=https://rankfree.kr/api/farm`

> ⚠️ **호스트를 정확히 `rankfree.kr`로 쓸 것.** `bootstrap/app.php`가 `RedirectCanonicalHost`를 `prepend()`로 최선두에 붙여 `rankfree.co.kr` → `rankfree.kr` 301을 낸다. 브라우저 fetch는 preflight 리다이렉트에서 CORS 실패하므로 미니앱이 통째로 죽는다.

---

## 5. 인증 미들웨어

### 5-1. 등록

`app/Http/Middleware/AuthenticateFarmUser.php` 생성 후 `bootstrap/app.php`의 `$middleware->alias([...])`에 추가:

```php
'auth.farm' => \App\Http\Middleware\AuthenticateFarmUser::class,
```

기존 별칭(operator, menu.gate, usage.gate, auth.ext, auth.apikey) 아래에 한 줄로 붙인다.

### 5-2. 동작 (`AuthenticateExtToken` 패턴 그대로)

```
/** 토스 미니앱 x-user-key 헤더 기반 인증(29). rankfree 회원(users)과 별개 신원이다. */
handle(Request $request, Closure $next): Response
  1. $plain = trim((string) $request->header('x-user-key'))
     빈 값 또는 200자 초과 → return response()->json(['message' => '사용자 식별키가 필요해요.'], 401)

  2. $hash = hash('sha256', $plain)          // 평문은 DB·로그에 절대 남기지 않는다

  3. (선택, config('rankfree.farm.toss.verify_anon_key') 가 true 일 때)
     위조 검증: TossPromotion::verifyAnonKey($plain)
       - Cache::remember("farm:anon:{$hash}", now()->addHours(6), fn () => …)
         → 분당 3,000 QPM 한도 절약. 캐시 미스일 때만 mTLS 호출
       - 검증 false 또는 mTLS 실패(운영 판단: fail-open 권장) → 401 '사용자 확인에 실패했어요.'
       - ⚠️ 토스 API 장애 때 미니앱 전체가 멈추지 않도록, 네트워크 예외는 fail-open(통과)으로 두고
         Log::warning 만 남긴다. 검증 자체가 false 로 확정된 경우에만 401.

  4. $farmUser = FarmUser::findOrCreateByKey($plain)   // firstOrCreate(['user_key_hash' => $hash], [...])

  5. if ($farmUser->status === 'blocked')
        return response()->json(['message' => '참여가 제한된 계정이에요.'], 403)
     // ⚠️ 반드시 403. 권한 문제를 401 로 내리면 클라이언트가 "인증 만료"로 오해한다
     //    (rankfree 확장이 401 을 받으면 토큰을 지우고 재로그인하는 것과 같은 함정)

  6. // last_seen_at 은 분 단위로만 갱신해 불필요한 쓰기를 줄인다 (ExtToken 과 동일)
     if ($farmUser->last_seen_at === null || $farmUser->last_seen_at->lt(now()->subMinute()))
        $farmUser->forceFill(['last_seen_at' => now()])->save();

  7. $request->attributes->set('farm_user', $farmUser);
     // ⚠️ setUserResolver() 를 쓰지 않는다. FarmUser 는 Authenticatable 이 아니고,
     //    $request->user() 를 오염시키면 다른 컨트롤러·미들웨어가 rankfree 회원으로 오인한다.

  8. return $next($request);
```

### 5-3. 컨트롤러 접근 헬퍼

모든 Farm 컨트롤러 하단에 동일한 private 헬퍼를 둔다(트레이트를 만들지 않는 것이 rankfree 스타일과 맞다 — 다만 4개 컨트롤러가 공유하므로 `app/Http/Controllers/Api/Concerns/ResolvesFarmUser.php` 트레이트 1개는 허용):

```php
private function farmUser(Request $request): FarmUser
{
    return $request->attributes->get('farm_user');
}
```

`Auth::user()` / `auth()->user()`는 이 경로에서 **항상 null**이다. 세션 가드('web')만 존재한다.

### 5-4. 토큰을 안 만드는 이유

앱인토스는 `getAnonymousKey()`가 **재설치·기기 변경에도 안정적인 사용자 해시**를 제공하고, 서버가 `verifyAnonKey`로 위조를 검증할 수 있다. 따라서 rankfree의 `ext_tokens` 같은 **자체 발급 토큰 계층을 추가하지 않는다**(로그인 화면·만료·재발급 UI가 전부 불필요해짐).
토스 로그인(userKey)까지 붙일 경우에만 `farm_users.key_type='toss'` + `toss_user_key`(암호화)를 채우고, 같은 사람의 anon 행과 병합하는 마이그레이션 커맨드를 별도로 만든다.

---

## 6. 컨트롤러

### 6-0. 공통 규약

| 항목 | 규칙 |
|---|---|
| 위치 | `app/Http/Controllers/Api/`, 네임스페이스 `App\Http\Controllers\Api` |
| 네이밍 | `Farm{도메인}Controller` (확장의 `Ext*` 접두와 같은 논리 — 클라이언트 계열 접두) |
| 상속 | `use App\Http\Controllers\Controller;` + `extends Controller` (빈 abstract, 트레이트 없음) |
| docblock | 클래스 상단 한글 PHPDoc: 무엇을 하는지 + 인증 방식(`auth.farm`) + `설계: .claude/29_TOSS_FARM_MINIAPP.md` |
| 시그니처 | `public function submit(Request $request, FarmMission $mission): JsonResponse` — 반환 타입 명시 |
| 검증 | FormRequest 금지. 컨트롤러 안에서 `$data = $request->validate([...])`, 규칙은 배열 표기 |
| 직렬화 | API Resource 금지. `private function plotJson(): array` / `missionJson()` 등 **화이트리스트 배열** |
| 서비스 주입 | **메서드 인젝션 기본** — `public function submit(Request $r, MissionSubmitService $svc)` |
| 도메인 로직 | `app/Domain/Farm/` 서비스가 단일 소스. 컨트롤러는 얇게 |

**응답 envelope — 이 계열의 규칙(클라이언트 TS 타입이 이미 고정돼 있어 협상 불가):**

| 엔드포인트 | 성공 shape |
|---|---|
| `/me/state` | `{plots, todayMissionIds, earnedPoints, harvested}` (top-level, `ServerState` 그대로) |
| `/missions` | `{'missions': [...], 'meta': {...}}` |
| `/missions/{id}/submit` | `{'correct': bool, 'reward'?: {...}, 'points'?: int, 'message'?: string}` |
| `/harvest` | `{'ok': bool, 'points'?: int, 'message'?: string}` |
| `/reward/info` | `{'pointsByCrop': {...}, 'defaultPoints': int}` |
| `/recommended-apps` | `{'apps': [...]}` |
| `/plots/{i}/plant` | `{'ok': true, 'plot': {...}}` |

**🔴 가장 중요한 계약: 비즈니스 실패는 HTTP 200 + 실패 플래그로 내린다.**
클라이언트 `src/api/client.ts`의 `request()`는 `!res.ok`면 `ApiError`를 **throw**한다. `harvestCrop()`·`submitAnswer()`는 그 예외를 잡지 않으므로, 4xx로 내리면 화면이 깨진다.
따라서:
- 오답 → `200 {'correct': false, 'message': '다시 한 번 확인해 주세요.'}`
- 수확 불가(7일 미달·이미 수확·한도) → `200 {'ok': false, 'message': '…'}`
- 하루 상한/밭 중복 → `200 {'correct': false, 'message': '오늘 참여를 모두 마쳤어요.'}` (제출 API 기준)

**4xx를 쓰는 경우는 한정한다:**

| 코드 | 언제 |
|---|---|
| 401 | `x-user-key` 없음/형식 오류 (미들웨어) |
| 403 | 차단 계정 (미들웨어) |
| 404 | 미션 id 자체가 없음 (라우트 모델 바인딩) |
| 422 | 요청 형식 오류(`cropId` 누락, `plotIndex` 범위 밖) — 정상 사용자에게는 발생하지 않는 값 |
| 429 | 제출 쿨다운·throttle 초과 (`fetch`가 throw → 클라가 "요청 실패" 표시. 의도된 동작) |

오류 메시지 키는 예외 없이 `'message'`, 값은 **한글 문장**.

---

### 6-1. `FarmStateController@show` — `GET /me/state`

```
public function show(Request $request, FarmStatePresenter $presenter): JsonResponse

$user = $this->farmUser($request);
return response()->json($presenter->payload($user));
```

`app/Domain/Farm/FarmStatePresenter::payload(FarmUser $user): array` 의사코드:

```
$plotCount = config('rankfree.farm.plot_count', 3);

// 활성 재배(밭당 최대 1개)를 plot_index 로 인덱싱
$active = FarmPlanting::query()
    ->where('farm_user_id', $user->id)
    ->whereIn('status', ['growing', 'ready'])
    ->orderBy('round_no')
    ->get()
    ->keyBy('plot_index');

// 참여일은 상태 테이블이 아니라 로그에서 읽는다 (원장 원칙)
$daysByPlanting = FarmPlantingDay::query()
    ->whereIn('planting_id', $active->pluck('id'))
    ->orderBy('work_date')
    ->get()
    ->groupBy('planting_id');

$plots = [];
for ($i = 0; $i < $plotCount; $i++) {
    $p = $active->get($i);
    if ($p === null) {
        $plots[] = ['cropId' => null, 'completedDates' => [], 'lastTendedDate' => ''];
        continue;
    }
    $dates = ($daysByPlanting[$p->id] ?? collect())->map(fn ($d) => $d->work_date->toDateString())->values()->all();
    $plots[] = [
        'cropId'         => $p->crop_id,
        'completedDates' => $dates,                                    // YYYY-MM-DD (KST)
        'lastTendedDate' => $p->last_tended_date?->toDateString() ?? '',
    ];
}

return [
    'plots'          => $plots,
    'todayMissionIds'=> FarmMissionLog::where('farm_user_id', $user->id)
                          ->where('result', 'correct')
                          ->whereDate('created_at', now()->toDateString())
                          ->pluck('mission_id')->map(fn ($id) => (string) $id)->unique()->values()->all(),
    'earnedPoints'   => (int) FarmPointLedger::where('farm_user_id', $user->id)->counted()->sum('amount'),
    'harvested'      => FarmHarvest::where('farm_user_id', $user->id)->orderBy('id')->pluck('crop_id')->all(),
];
```

> `whereDate('created_at', ...)`는 KST 벽시계 기준으로 맞다 — DB에 KST가 그대로 저장되고(`config/app.php` timezone=Asia/Seoul, `2026_07_18_000010_shift_utc_timestamps_to_kst`), `now()`도 KST다. **UTC를 가정한 날짜 비교를 쓰면 어긋난다.**

---

### 6-2. `FarmStateController@plant` — `POST /plots/{index}/plant`

```
public function plant(Request $request, int $index, PlantingService $service): JsonResponse

$user = $this->farmUser($request);
$data = $request->validate(['cropId' => ['required', 'string', 'max:20']]);

try {
    $planting = $service->plant($user, $index, $data['cropId']);
} catch (FarmRuleException $e) {
    return response()->json(['ok' => false, 'message' => $e->getMessage()], 200);
}

return response()->json(['ok' => true, 'plot' => $this->plotJson($planting)], 201);
```

`PlantingService::plant(FarmUser $user, int $index, string $cropCode): FarmPlanting`

```
검증 순서:
1) $index 가 0 .. plot_count-1 아니면 throw FarmRuleException('밭 번호가 올바르지 않아요.')
2) $crop = FarmCrop::active()->where('code', $cropCode)->first()
   null → throw FarmRuleException('지금은 심을 수 없는 작물이에요.')
3) DB::transaction(function () use (...) {
     a) FarmUser::whereKey($user->id)->lockForUpdate()->first();   // 같은 사용자 동시요청 직렬화
     b) $exists = FarmPlanting::where('farm_user_id', $user->id)
                    ->where('plot_index', $index)
                    ->whereIn('status', ['growing','ready'])->exists();
        exists → throw FarmRuleException('이 밭에는 이미 작물이 자라고 있어요.')
     c) $round = FarmPlanting::where('farm_user_id', $user->id)
                    ->where('plot_index', $index)->max('round_no') + 1;
     d) return FarmPlanting::create([
            'farm_user_id'   => $user->id,
            'plot_index'     => $index,
            'round_no'       => $round,
            'crop_id'        => $crop->code,
            'required_days'  => $crop->days,        // 심는 시점 스냅샷 — 나중에 정책이 바뀌어도 진행 중 작물은 안 바뀐다
            'completed_days' => 0,
            'status'         => 'growing',
            'planted_on'     => now()->toDateString(),
        ]);
   });
```

> 클라이언트는 `plantCropOnServer()` 실패를 조용히 무시하고 로컬에만 심는다. 다음 `/me/state`에서 서버 값이 이기므로 사용자에게는 "심기가 안 됐네"로 보인다. 이것이 **의도된 동작**이다(서버가 원장).

---

### 6-3. `FarmMissionController@index` — `GET /missions`

```
public function index(Request $request): JsonResponse

$user  = $this->farmUser($request);
$today = now()->toDateString();

$missions = FarmMission::visible()->orderBy('sort_order')->orderBy('id')->get();

// 오늘 이미 정답 처리된 미션
$doneIds = FarmMissionLog::where('farm_user_id', $user->id)
    ->where('result', 'correct')->whereDate('created_at', $today)
    ->pluck('mission_id')->countBy();          // mission_id => 오늘 성공 횟수

// 오늘 남은 참여 횟수 = 하루 한도 - 오늘 완료한 "밭" 수
$doneToday = FarmPlantingDay::where('farm_user_id', $user->id)->where('work_date', $today)->count();
$limit     = config('rankfree.farm.daily_mission_limit', 3);

return response()->json([
    'missions' => $missions->map(fn ($m) => $this->missionJson($m, ($doneIds[$m->id] ?? 0) >= $m->daily_limit))->all(),
    'meta'     => ['remaining' => max(0, $limit - $doneToday), 'dailyLimit' => $limit],
]);
```

`private function missionJson(FarmMission $m, bool $completed): array` — **화이트리스트만 조립**:

```
return [
    'id'          => (string) $m->id,               // 클라 Mission.id 는 string
    'kind'        => $m->kind,
    'title'       => $m->title,
    'description' => $m->description,
    'reward'      => ['item' => $m->reward_item, 'count' => (int) $m->reward_count],
    'points'      => (int) $m->points,
    'completed'   => $completed,
    'quiz'        => $m->question === null ? null : [
        'product'  => [
            'name'       => $m->product_name,
            'imageUrl'   => $m->product_image_url,
            'imageEmoji' => $m->product_emoji ?? '🎁',
        ],
        'guide'       => $m->guide ?? [],
        'hintUrl'     => $m->hint_url,
        'question'    => $m->question,
        'placeholder' => $m->placeholder,
    ],
];
// ⚠️ answer / answer_type / tolerance_percent 를 넣는 순간 게임이 끝난다.
//    코드 리뷰 체크리스트 1번 항목으로 둘 것.
```

---

### 6-4. `FarmMissionController@submit` — `POST /missions/{mission}/submit` 🔴 핵심

**검증 순서가 곧 보안이다. 채점(9단계)은 모든 한도 검사를 통과한 뒤에만 한다** — 한도가 찬 뒤에도 정답 여부를 알려주면 사용자가 정답을 미리 탐색해 다음 날 즉시 맞힐 수 있다.

```
public function submit(Request $request, FarmMission $mission, MissionSubmitService $service): JsonResponse

$user = $this->farmUser($request);
$data = $request->validate([
    'answer'    => ['required', 'string', 'max:200'],
    'plotIndex' => ['nullable', 'integer', 'min:0', 'max:2'],   // 없으면 서버가 밭을 고른다
]);

$result = $service->submit($user, $mission, $data['answer'], $data['plotIndex'] ?? null, $request->ip());

return response()->json($result->toArray(), 200);   // 비즈니스 실패도 200
```

`app/Domain/Farm/MissionSubmitService::submit(...)` 의사코드:

```
$today = now()->toDateString();
$limit = config('rankfree.farm.daily_mission_limit', 3);

// ── 1. 미션 노출 상태 ──────────────────────────────────
if (! $mission->is_active || $mission->starts_at->gt(now()) || $mission->ends_at->lt(now())) {
    log(rejected, 'mission_closed');
    return Result::fail('지금은 참여할 수 없는 미션이에요.');
}

// ── 2. 제출 쿨다운 (어뷰징: 자동 대입) ───────────────────
$last = FarmMissionLog::where('farm_user_id', $user->id)->latest('id')->first();
$cool = config('rankfree.farm.submit_cooldown_seconds', 3);
if ($last !== null && $last->created_at->gt(now()->subSeconds($cool))) {
    log(rejected, 'too_fast');
    return Result::fail('잠시 후 다시 시도해 주세요.');
}

// ── 3. 오늘 총 시도 횟수 (오답 포함) ─────────────────────
$attempts = FarmMissionLog::where('farm_user_id', $user->id)->whereDate('created_at', $today)->count();
if ($attempts >= config('rankfree.farm.max_attempts_per_day', 10)) {
    log(rejected, 'too_fast');
    return Result::fail('오늘은 더 시도할 수 없어요. 내일 다시 만나요.');
}

// ── 4. 하루 참여 상한 (밭 기준 3회) ──────────────────────
$doneToday = FarmPlantingDay::where('farm_user_id', $user->id)->where('work_date', $today)->count();
if ($doneToday >= $limit) {
    log(rejected, 'daily_limit');
    return Result::fail('오늘 참여를 모두 마쳤어요. 내일 또 만나요!');
}

// ── 5. 대상 밭 결정 ─────────────────────────────────────
$doneSlots = FarmPlantingDay::where('farm_user_id', $user->id)->where('work_date', $today)->pluck('plot_index');
$candidates = FarmPlanting::where('farm_user_id', $user->id)
    ->where('status', 'growing')                 // ready(7일 완주)는 더 돌볼 수 없다
    ->whereNotIn('plot_index', $doneSlots)
    ->orderBy('plot_index')->get();

$planting = $plotIndex !== null
    ? $candidates->firstWhere('plot_index', $plotIndex)
    : $candidates->first();

if ($planting === null) {
    log(rejected, $plotIndex !== null ? 'plot_done' : 'plot_empty');
    return Result::fail($plotIndex !== null
        ? '이 밭은 오늘 이미 돌봤어요.'
        : '오늘 돌볼 밭이 없어요. 빈 밭에 작물을 심어 주세요.');
}

// ── 6. 같은 미션 1일 한도 ───────────────────────────────
$sameMission = FarmMissionLog::where('farm_user_id', $user->id)
    ->where('mission_id', $mission->id)->where('result', 'correct')
    ->whereDate('created_at', $today)->count();
if ($sameMission >= $mission->daily_limit) {
    log(rejected, 'already_done');
    return Result::fail('오늘 이미 참여한 미션이에요. 다른 미션을 해보세요.');
}

// ── 7. 채점 (여기서 처음 정답을 본다) ────────────────────
$norm = MissionGrader::normalize($mission, $answer);
if (! MissionGrader::matches($mission, $norm)) {
    log(wrong, answer_raw: $answer, answer_norm: $norm);
    return Result::wrong('다시 한 번 확인해 주세요.');
}

// ── 8. 정답 확정 — 트랜잭션 ─────────────────────────────
return DB::transaction(function () use (...) {
    FarmUser::whereKey($user->id)->lockForUpdate()->first();      // 동시 요청 직렬화

    // 8-a. 4·5·6 재검증 (락 획득 이후 상태가 바뀌었을 수 있다)
    …동일 쿼리 3개 재실행, 하나라도 걸리면 rejected 로그 후 Result::fail…

    // 8-b. 미션 로그 (append-only)
    $log = FarmMissionLog::create([
        'farm_user_id' => $user->id,  'mission_id' => $mission->id,
        'plot_index'   => $planting->plot_index,  'planting_id' => $planting->id,
        'answer_raw'   => Str::limit($answer, 200, ''),  'answer_norm' => $norm,
        'result'       => 'correct',
        'reward_item'  => $mission->reward_item, 'reward_count' => $mission->reward_count,
        'points'       => 0,          // 8-e 에서 확정 후 별도 컬럼 없이 원장으로 관리, 표시용만 갱신
        'ip'           => $ip,
    ]);

    // 8-c. 참여일 로그 (append-only) — unique 2개가 최종 방어선
    try {
        FarmPlantingDay::create([
            'farm_user_id' => $user->id, 'planting_id' => $planting->id,
            'plot_index'   => $planting->plot_index, 'round_no' => $planting->round_no,
            'crop_id'      => $planting->crop_id,
            'day_no'       => FarmPlantingDay::where('planting_id', $planting->id)->count() + 1,
            'work_date'    => $today,
            'mission_id'   => $mission->id, 'mission_log_id' => $log->id,
        ]);
    } catch (QueryException $e) {                      // unique 위반 = 동시요청 경합
        throw new FarmRuleException('이 밭은 오늘 이미 돌봤어요.');
    }

    // 8-d. 상태 테이블 갱신 (캐시)
    $done = FarmPlantingDay::where('planting_id', $planting->id)->count();
    $planting->forceFill([
        'completed_days'   => $done,
        'last_tended_date' => $today,
        'status'           => $done >= $planting->required_days ? 'ready' : 'growing',
    ])->save();

    // 8-e. 참여 포인트 예약 (있을 때만)
    $points = 0;
    if ($mission->points > 0) {
        $points = app(PointLedgerService::class)->reserve($user, 'mission', $log->id, $mission->points, null);
        // reserve() 가 누적 한도에 걸리면 남은 금액만 예약하고, 0 이면 원장 행을 만들지 않는다
        $log->forceFill(['points' => $points])->save();   // 로그의 유일한 예외적 UPDATE(같은 트랜잭션 내 확정)
    }
    $user->increment('correct_count');

    return Result::correct($mission->reward_item, $mission->reward_count, $points, ledgerId: …);
});

// ── 9. 커밋 이후 ────────────────────────────────────────
if ($ledgerId !== null) { FarmPointPayoutJob::dispatch($ledgerId); }
```

**응답 예시**

```json
// 정답
{"correct": true, "reward": {"item": "sunlight", "count": 2}, "points": 300}
// 오답
{"correct": false, "message": "다시 한 번 확인해 주세요."}
// 한도
{"correct": false, "message": "오늘 참여를 모두 마쳤어요. 내일 또 만나요!"}
```

**`MissionGrader` (`app/Domain/Farm/MissionGrader.php`)**

```
normalize(FarmMission $m, string $input): string
  answer_type === 'number' → preg_replace('/[^0-9]/', '', $input)
  answer_type === 'text'   → mb_strtolower(preg_replace('/\s+/u', '', trim($input)))

matches(FarmMission $m, string $norm): bool
  $ans = normalize($m, (string) $m->answer);
  if ($ans === '' || $norm === '') return false;
  if ($m->answer_type === 'text') return hash_equals($ans, $norm);

  // 숫자 + 오차 허용 (가격처럼 변동되는 값)
  $a = (int) $ans; $b = (int) $norm;
  $tol = (int) ($m->tolerance_percent ?? 0);
  if ($tol <= 0) return $a === $b;
  return $a > 0 && abs($a - $b) <= $a * $tol / 100;
```

---

### 6-5. `FarmHarvestController@store` — `POST /harvest` 🔴 핵심

```
public function store(Request $request, HarvestService $service): JsonResponse

$user = $this->farmUser($request);
$data = $request->validate([
    'plotIndex' => ['required', 'integer', 'min:0', 'max:2'],
    'cropId'    => ['required', 'string', 'max:20'],
]);

$result = $service->harvest($user, $data['plotIndex'], $data['cropId']);
return response()->json($result, 200);       // 실패도 200 {'ok': false, 'message': …}
```

`app/Domain/Farm/HarvestService::harvest(...)` 의사코드:

```
$ledgerId = null;

$out = DB::transaction(function () use (...) {
    // 0. 동시 요청 직렬화
    FarmUser::whereKey($user->id)->lockForUpdate()->first();

    // 1. 재배 행 확보 (행 락)
    $planting = FarmPlanting::where('farm_user_id', $user->id)
        ->where('plot_index', $plotIndex)
        ->whereIn('status', ['growing', 'ready'])
        ->lockForUpdate()->first();
    if ($planting === null)          return ['ok' => false, 'message' => '수확할 작물이 없어요.'];

    // 2. 클라이언트가 보낸 cropId 대조 (오조작·조작 방지)
    if ($planting->crop_id !== $cropId) return ['ok' => false, 'message' => '작물 정보가 맞지 않아요.'];

    // 3. 🔴 7일 검증 — completed_days(캐시)를 믿지 않고 로그를 센다
    $days = FarmPlantingDay::where('planting_id', $planting->id)->orderBy('work_date')->get();
    if ($days->count() < $planting->required_days) {
        return ['ok' => false, 'message' => '아직 다 자라지 않았어요.'];
    }

    // 4. 중복 수확 방지 (1차: 조회)
    if (FarmHarvest::where('planting_id', $planting->id)->exists()) {
        return ['ok' => false, 'message' => '이미 수확한 작물이에요.'];
    }

    // 5. 보상 금액 결정 — 클라이언트 값이 아니라 서버 마스터에서 읽는다
    $crop   = FarmCrop::where('code', $planting->crop_id)->first();
    $amount = (int) ($crop?->points ?? config('rankfree.farm.default_points', 50));

    // 6. 1인 누적 한도 (5,000P) — 초과분은 잘라서 지급, 게임 진행 자체는 막지 않는다
    $grantable = app(PointLedgerService::class)->grantable($user, $amount);   // 0 ~ $amount

    // 7. 수확 로그 (append-only) — unique(planting_id) 가 최종 방어선
    try {
        $harvest = FarmHarvest::create([
            'farm_user_id'   => $user->id,   'planting_id' => $planting->id,
            'plot_index'     => $planting->plot_index, 'round_no' => $planting->round_no,
            'crop_id'        => $planting->crop_id,
            'days_completed' => $days->count(),
            'first_day_date' => $days->first()->work_date,
            'last_day_date'  => $days->last()->work_date,
            'points'         => $grantable,
            'harvested_at'   => now(),
        ]);
    } catch (QueryException $e) {                 // 동시 요청 경합
        return ['ok' => false, 'message' => '이미 수확한 작물이에요.'];
    }

    // 8. 상태 테이블 닫기 → 밭이 비어 새 작물을 심을 수 있다
    $planting->forceFill(['status' => 'harvested', 'harvested_at' => now()])->save();
    $user->increment('harvest_count');

    // 9. 원장 예약 (unique(source, source_id) 가 중복 지급을 DB 레벨에서 차단)
    if ($grantable > 0) {
        $ledger = app(PointLedgerService::class)->reserve($user, 'harvest', $harvest->id, $grantable, $planting->crop_id);
        $harvest->forceFill(['ledger_id' => $ledger->id])->save();
        $ledgerId = $ledger->id;
    }

    return $grantable > 0
        ? ['ok' => true, 'points' => $grantable]
        : ['ok' => true, 'points' => 0, 'message' => '누적 포인트 한도에 도달해 포인트는 지급되지 않아요.'];
});

// 10. 커밋 이후에 지급 Job (트랜잭션 안에서 dispatch 하면 롤백 시 유령 Job 이 생긴다)
if ($ledgerId !== null) { FarmPointPayoutJob::dispatch($ledgerId); }

return $out;
```

---

### 6-6. `PointLedgerService` (`app/Domain/Farm/PointLedgerService.php`)

```
/** 남은 지급 가능액. 실패·취소분은 한도에서 되돌려준다. */
public function grantable(FarmUser $user, int $want): int
{
    $cap  = (int) config('rankfree.farm.point_cap_per_user', 5000);
    $used = (int) FarmPointLedger::where('farm_user_id', $user->id)->counted()->sum('amount');
    return max(0, min($want, $cap - $used));
}

/** 원장 예약. 호출자는 반드시 트랜잭션 + FarmUser lockForUpdate 안에서 부른다. */
public function reserve(FarmUser $user, string $source, int $sourceId, int $want, ?string $cropId): ?FarmPointLedger
{
    $amount = $this->grantable($user, $want);
    if ($amount <= 0) return null;

    $ledger = FarmPointLedger::create([
        'farm_user_id'   => $user->id,
        'source'         => $source,     'source_id' => $sourceId,
        'crop_id'        => $cropId,     'amount'    => $amount,
        'status'         => 'pending',
        'promotion_code' => config('rankfree.farm.toss.promotion_code'),
    ]);

    $user->increment('total_points', $amount);      // 캐시. 판정에는 쓰지 않는다
    return $ledger;
}
```

> ⚠️ `grantable()`은 **락 안에서** 호출돼야 한다. 락 없이 부르면 동시 수확 2건이 각각 한도를 통과해 초과 지급된다.
> ⚠️ 지급이 최종 `failed`로 확정되면 `PointLedgerService::markFailed()`가 `farm_users.total_points`를 되돌린다(원장 행은 지우지 않고 status만 바꾼다 — status 변경은 허용된 유일한 UPDATE다).

---

### 6-7. `FarmHarvestController@rewardInfo` — `GET /reward/info`

```
$crops = FarmCrop::active()->get(['code', 'points']);
return response()->json([
    'pointsByCrop'  => $crops->pluck('points', 'code')->map(fn ($p) => (int) $p)->all(),
    'defaultPoints' => (int) config('rankfree.farm.default_points', 50),
]);
```

### 6-8. `FarmAppController@index` — `GET /recommended-apps`

```
return response()->json([
    'apps' => FarmRecommendedApp::active()->get()
        ->map(fn ($a) => [
            'id' => (string) $a->id, 'name' => $a->name,
            'description' => $a->description, 'emoji' => $a->emoji, 'scheme' => $a->scheme,
        ])->all(),
]);
```

---

## 7. 관리자 화면

### 7-1. 라우트 (`routes/web.php` 의 `$__admin` 그룹 안, 336~591행 블록)

`Route::resource()`를 쓰지 않고 한 줄씩 명시. 블록 위에 한글 주석.
**고정 경로(`/create`)를 `{model}` 라우트보다 반드시 앞에 선언한다.**

```php
// 퀴즈농장(29) — 토스 미니앱 미션·작물·포인트 운영(2026-07-28)
Route::get('/farm-missions', [FarmMissionController::class, 'index'])->name('admin.farm-missions');
Route::get('/farm-missions/create', [FarmMissionController::class, 'create'])->name('admin.farm-missions.create');
Route::post('/farm-missions', [FarmMissionController::class, 'store'])->name('admin.farm-missions.store');
Route::get('/farm-missions/{mission}/edit', [FarmMissionController::class, 'edit'])->name('admin.farm-missions.edit');
Route::put('/farm-missions/{mission}', [FarmMissionController::class, 'update'])->name('admin.farm-missions.update');
Route::delete('/farm-missions/{mission}', [FarmMissionController::class, 'destroy'])->name('admin.farm-missions.destroy');
Route::post('/farm-missions/{mission}/toggle', [FarmMissionController::class, 'toggle'])->name('admin.farm-missions.toggle');

Route::get('/farm-crops', [FarmCropController::class, 'index'])->name('admin.farm-crops');
Route::put('/farm-crops/{crop}', [FarmCropController::class, 'update'])->name('admin.farm-crops.update');
Route::post('/farm-crops/{crop}/toggle', [FarmCropController::class, 'toggle'])->name('admin.farm-crops.toggle');

Route::get('/farm-apps', [FarmAppController::class, 'index'])->name('admin.farm-apps');
Route::get('/farm-apps/create', ...)->name('admin.farm-apps.create');
… store / edit / update / destroy / toggle …

// 포인트 지급 내역 — 읽기 전용 + 실패분 재시도
Route::get('/farm-ledgers', [FarmLedgerController::class, 'index'])->name('admin.farm-ledgers');
Route::post('/farm-ledgers/{ledger}/retry', [FarmLedgerController::class, 'retry'])->name('admin.farm-ledgers.retry');
Route::post('/farm-users/{farmUser}/block', [FarmLedgerController::class, 'block'])->name('admin.farm-users.block');
```

> 목록 라우트명에 `.index`를 붙이지 않는다. `route('admin.farm-missions')`가 목록이다.
> 이 그룹의 미들웨어는 `[AdminHostOnly, auth, operator]` — 운영자면 URL로 다 들어갈 수 있다는 전제로 설계한다(`menu.gate`는 콘솔 전용).

### 7-2. 컨트롤러

`app/Http/Controllers/Admin/FarmMissionController.php` (그 외 `FarmCropController`, `FarmAppController`, `FarmLedgerController`)

- `namespace App\Http\Controllers\Admin;` + `extends App\Http\Controllers\Controller`
- 클래스 위 `/** 퀴즈농장(29) 미션·퀴즈 등록 (운영자). */`
- 생성자 미들웨어 없음(인증은 라우트 그룹 담당)
- 메서드: `index(Request)`, `create()`, `store(Request)`, `edit(FarmMission $mission)`, `update(Request, FarmMission $mission)`, `destroy(FarmMission $mission)`, `toggle(FarmMission $mission)`
- 하단에 공유 검증 헬퍼:

```php
private function validated(Request $request): array
{
    $data = $request->validate([
        'title'             => ['required', 'string', 'max:80'],
        'description'       => ['required', 'string', 'max:200'],
        'kind'              => ['required', Rule::in(array_keys(FarmMission::KINDS))],
        'product_name'      => ['nullable', 'string', 'max:120'],
        'product_image_url' => ['nullable', 'url', 'max:500'],
        'product_emoji'     => ['nullable', 'string', 'max:8'],
        'guide_text'        => ['nullable', 'string', 'max:2000'],   // 줄바꿈 구분
        'hint_url'          => ['nullable', 'url', 'max:500'],
        'question'          => ['nullable', 'string', 'max:200'],
        'placeholder'       => ['nullable', 'string', 'max:60'],
        'answer'            => ['nullable', 'string', 'max:120'],
        'answer_type'       => ['required', Rule::in(array_keys(FarmMission::ANSWER_TYPES))],
        'tolerance_percent' => ['nullable', 'integer', 'min:0', 'max:50'],
        'reward_item'       => ['required', Rule::in(array_keys(FarmMission::ITEMS))],
        'reward_count'      => ['required', 'integer', 'min:1', 'max:9'],
        'points'            => ['required', 'integer', 'min:0', 'max:1000'],
        'daily_limit'       => ['required', 'integer', 'min:1', 'max:3'],
        'starts_at'         => ['required', 'date'],
        'ends_at'           => ['required', 'date', 'after:starts_at'],
        'sort_order'        => ['nullable', 'integer', 'min:0', 'max:9999'],
    ]);

    // 참여 방법: textarea 한 줄 = 항목 1개 → json 배열
    $data['guide'] = collect(preg_split('/\r\n|\r|\n/', (string) ($data['guide_text'] ?? '')))
        ->map(fn ($l) => trim($l))->filter()->values()->all();
    unset($data['guide_text']);

    $data['is_active'] = $request->boolean('is_active', true);

    // 도메인 규칙: 출석형(참여만으로 완료)에는 포인트를 넣지 못하게 막는다.
    // 참여 없이 포인트를 주면 프로모션 심사 위반이다.
    if ($data['kind'] === 'attendance' && $data['points'] > 0) {
        throw ValidationException::withMessages(['points' => '출석형 미션에는 포인트를 넣을 수 없어요.']);
    }
    // 퀴즈가 있으면 정답은 필수
    if (! empty($data['question']) && empty($data['answer'])) {
        throw ValidationException::withMessages(['answer' => '질문이 있으면 정답을 반드시 입력해 주세요.']);
    }

    return $data;
}
```

리다이렉트: `store`/`update` → `redirect()->route('admin.farm-missions')->with('status', '미션을 저장했습니다.')`, `destroy`/`toggle` → `back()->with('status', …)`.
목록: `paginate(20)->withQueryString()` + `$request->query('q')` 검색(제목/상품명 LIKE).

### 7-3. 뷰

`resources/views/admin/farm-missions/index.blade.php` + `form.blade.php` (등록·수정 공용, `$mission->exists`로 분기).
`resources/views/admin/vendors/{index,form}.blade.php`를 그대로 베낀다.

```
@extends('admin.layout')
@section('page-title', '퀴즈농장 미션')
@section('page-actions')  ← 우측 '＋ 미션 등록' 버튼
@section('crumb-parent', 'admin.farm-missions')   ← 폼 페이지
@section('admin-content')
  <x-console.page-head title="퀴즈농장 미션">
    <x-slot:desc>미니앱에 노출되는 문제·링크·보상을 관리해요. 정답은 서버에만 저장돼요.</x-slot:desc>
    … 우측 액션 버튼 …
  </x-console.page-head>

  <div class="card overflow-hidden">
    <div style="overflow-x:auto;">
      <table class="w-full" style="min-width:1000px;"> … </table>
    </div>
  </div>
  <div class="mt-4">{{ $items->links() }}</div>
@endsection
```

- 활성 토글은 `.rf-switch` 마크업 + `fetch` + `X-CSRF-TOKEN` 헤더 → `admin.farm-missions.toggle`
- 삭제 폼은 `<form method="POST" data-confirm="이 미션을 삭제할까요?" data-confirm-text="참여 로그는 그대로 남아요.">` (SweetAlert2 전역 핸들러). `onsubmit="return confirm()"`는 쓰지 않는다
- 폼 값은 `old('field', $mission->field)` + `@checked()` / `@selected()`, 체크박스 앞에 `<input type="hidden" name="is_active" value="0">`
- 스타일은 `.btn/.card/.input/.badge` + `var(--fs-*)`, `var(--color-*)` 토큰만. **하드코딩 hex 금지, 12px 미만 폰트 금지**
- JS는 `@section('admin-content')` 끝의 인라인 IIFE

### 7-4. 등록 폼 항목

| 구분 | 필드 | 입력 형태 | 필수 | 비고 |
|---|---|---|---|---|
| 기본 | 제목 | text | ✅ | |
| 기본 | 설명 | text | ✅ | 목록·상세 한 줄 |
| 기본 | 종류 | select(`FarmMission::KINDS`) | ✅ | 앱내완결/외부확인/출석 |
| 기본 | 정렬 순서 | number | – | |
| 상품 | 상품명 | text | – | |
| 상품 | **상품 이미지 URL** | url | – | 비우면 이모지 사용 |
| 상품 | 대체 이모지 | text(2자) | – | 기본 🎁 |
| 안내 | **참여 방법(여러 줄)** | textarea | ✅ | **한 줄 = 한 단계**, 클라가 번호를 붙여 표시 |
| 안내 | 힌트 링크 | url | – | 외부확인형만 |
| 문제 | 질문 | text | – | 비우면 출석형(퀴즈 없음) |
| 문제 | **정답** | text | 조건부 ✅ | 질문이 있으면 필수. **응답에 절대 안 나감** |
| 문제 | 정답 형식 | select(숫자/자유 텍스트) | ✅ | |
| 문제 | 오차 허용(%) | number 0~50 | – | 숫자형 + 가격처럼 변동되는 값일 때 |
| 문제 | 입력칸 안내 | text | – | placeholder |
| 보상 | 아이템 | select(`FarmMission::ITEMS`) | ✅ | |
| 보상 | 개수 | number 1~9 | ✅ | |
| 보상 | 포인트 | number 0~1000 | – | **출석형은 0 고정(검증에서 막음)** |
| 노출 | 시작 일시 | datetime-local | ✅ | |
| 노출 | 종료 일시 | datetime-local | ✅ | `after:starts_at` |
| 제한 | 1인 1일 참여 한도 | number 1~3 | ✅ | 기본 1 |
| 노출 | 활성 여부 | rf-switch | ✅ | |

**목록 화면 운영 컬럼**: 제목 / 종류 배지 / 보상(아이템×개수, 포인트) / 노출기간 / **오늘 시도·정답 수** / **정답률** / 활성 토글 / 수정·삭제
→ 정답률이 갑자기 떨어지면 "정답이 바뀐 미션"이므로 운영자가 바로 알 수 있다. `farm_mission_logs`에서 집계.

**정답 보호**: 목록에는 정답을 표시하지 않고 `••••`로 마스킹, 수정 폼에서만 평문 노출.

### 7-5. 포인트 지급 내역 화면 (`admin.farm-ledgers`)

- 필터: 상태(`FarmPointLedger::STATUSES`), 기간, 소스(mission/harvest)
- 컬럼: id / 사용자(farm_user_id, 키 해시 앞 8자) / 소스+source_id / 금액 / 상태 배지 / attempts / last_error_code / requested_at / confirmed_at
- 상단 요약: 오늘 지급 합계, 대기(pending+requested) 합계, 실패·보류 건수 → **예산 소진(4109/4112) 조기 감지**
- 행 액션: `failed`/`held` 상태만 "재시도" 버튼(POST) → `FarmPointPayoutJob::dispatch($ledger->id)`

---

## 8. 토스 포인트 지급 연동

### 8-1. 사전 조건 (코드로 해결 안 되는 것)

1. 앱인토스 콘솔에서 **프로모션 생성 → 승인 → 실행 중** 상태
2. **비즈월렛 최소 30만원 충전**(프로모션 예산), 사전 검수 2~3영업일
3. **mTLS 클라이언트 인증서** 발급(`.crt` / `.key`)
4. rankfree 서버 **Outbound 방화벽**에 `apps-in-toss-api.toss.im` (117.52.3.192 / 211.115.96.192 / 106.249.5.192 : 443) 허용

> 🔴 **정책 프레이밍**: 보상 조건은 문서·심사 설명에서 반드시 **"7일 동안 매일 참여"** 로 기술한다. "작물을 다 키웠으니 지급"으로 쓰면 **게임 결과 기반 보상**이 되어 프로모션 지급이 불가능하다. 구현은 동일하다.

### 8-2. 어디에 두나

| 파일 | 역할 |
|---|---|
| `app/Support/TossPromotion.php` | 외부 API 래퍼(정적 메서드). `app/Support/`는 외부 SDK·유틸 래퍼 자리다(QuizSolver·Aligo·GoogleToken과 같은 층) |
| `app/Jobs/FarmPointPayoutJob.php` | 지급 오케스트레이션(키 발급→지급→결과 확정) |
| `app/Console/Commands/FarmRetryPayouts.php` | `farm:retry-payouts` — 실패·보류·PENDING 잔여분 재처리 |
| `app/Domain/Farm/PointLedgerService.php` | 한도·원장 규칙(§6-6) |

`TossPromotion` 메서드:

```
verifyAnonKey(string $anonKey): bool
    POST /api-partner/v1/apps-in-toss/users/anon-key/verify   (헤더 x-anon-key)
getKey(string $anonKey): string
    POST /api-partner/v1/apps-in-toss/promotion/execute-promotion/get-key
execute(string $anonKey, string $key, string $promotionCode, int $amount): void
    POST /api-partner/v1/apps-in-toss/promotion/execute-promotion
result(string $anonKey, string $key, string $promotionCode): string   // PENDING|SUCCESS|FAILED
    POST /api-partner/v1/apps-in-toss/promotion/execution-result
```

공통 클라이언트:

```php
Http::withOptions([
        'cert'    => config('rankfree.farm.toss.cert_path'),
        'ssl_key' => config('rankfree.farm.toss.key_path'),
    ])
    ->timeout(config('rankfree.farm.toss.timeout', 10))
    ->withHeaders(['Content-Type' => 'application/json', 'x-anon-key' => $anonKey])
    ->post($base.$path, $body);
```

> 🔴 **비즈니스 오류도 HTTP 200으로 온다.** `resultType !== 'SUCCESS'`면 전부 실패로 처리하고 `error.errorCode`를 꺼내 `TossPromotionException(code, reason)`으로 던진다. HTTP status만 보면 실패를 전부 놓친다.
> 🔴 `getExecutionResult`는 `success`가 **객체가 아니라 문자열 enum**(`'PENDING'|'SUCCESS'|'FAILED'`)이다. 봉투의 `resultType`과 헷갈리지 말 것.

### 8-3. 지급 상태 머신

```
pending ──getKey──▶ requested ──result=SUCCESS──▶ success
   │                    │
   │                    ├─ result=FAILED ──────▶ failed   (예산 롤백됨. 한도도 되돌린다)
   │                    └─ result=PENDING ─────▶ (백오프 폴링 유지)
   │
   └─ 4108/4109/4112 (승인 안 됨·실행중 아님·예산 부족) ──▶ held  (운영자 개입 필요, 자동 재시도 대상)
   └─ 4110/4095/네트워크 ──▶ 같은 toss_key 로 재시도 (attempts++)
   └─ 4113 (이미 지급됨) ──▶ 결과 조회로 확정 (성공이면 success)
```

### 8-4. `FarmPointPayoutJob` 의사코드

```
public int $tries = 5;
public array $backoff = [10, 30, 120, 600, 1800];   // 초

public function handle(): void
{
    $ledger = FarmPointLedger::find($this->ledgerId);
    if ($ledger === null || in_array($ledger->status, ['success', 'canceled'], true)) return;

    $anonKey = /* farm_users 는 평문 키를 저장하지 않는다 → §8-5 참조 */;
    $code    = $ledger->promotion_code ?? config('rankfree.farm.toss.promotion_code');

    // 1) 멱등키 확보 — 이미 있고 만료 전이면 재사용한다. 🔴 새 key 로 재시도하면 중복 지급이다.
    if ($ledger->toss_key === null || ($ledger->status === 'failed' && $ledger->keyExpired())) {
        $key = TossPromotion::getKey($anonKey);
        $ledger->forceFill(['toss_key' => $key, 'toss_key_issued_at' => now()])->save();
    }

    // 2) 지급 요청
    try {
        $ledger->forceFill(['status' => 'requested', 'requested_at' => now()])
               ->increment('attempts');
        TossPromotion::execute($anonKey, $ledger->toss_key, $code, $ledger->amount);
    } catch (TossPromotionException $e) {
        // 4113(이미 지급) 은 오류가 아니다 — 3) 으로 내려가 확정한다
        if (! in_array($e->code, ['4113'], true)) {
            $ledger->forceFill(['last_error_code' => $e->code, 'last_error_message' => $e->getMessage()])->save();

            // 운영자 개입이 필요한 코드는 재시도해도 소용없다 → held 로 두고 종료(release 하지 않는다)
            if (in_array($e->code, ['4108', '4109', '4112', '4100', '4105', '4114'], true)) {
                $ledger->forceFill(['status' => 'held'])->save();
                Log::error('[farm] 프로모션 지급 보류', ['ledger' => $ledger->id, 'code' => $e->code]);
                return;   // farm:retry-payouts 가 나중에 다시 집는다
            }
            // 그 외(4110 내부오류·4095 한도·타임아웃)는 결과 조회로 확정 시도 후 재시도
        }
    }

    // 3) 결과 확정 (PENDING 이면 백오프 폴링, 최대 5회)
    for ($i = 0; $i < 5; $i++) {
        $status = TossPromotion::result($anonKey, $ledger->toss_key, $code);
        if ($status === 'SUCCESS') {
            $ledger->forceFill(['status' => 'success', 'confirmed_at' => now()])->save();
            return;
        }
        if ($status === 'FAILED') {
            app(PointLedgerService::class)->markFailed($ledger);   // status=failed + total_points 롤백
            return;
        }
        sleep(min(8, 2 ** $i));   // PENDING
    }

    // 4) 여전히 PENDING → 예외를 던져 큐 재시도(backoff)에 맡긴다. toss_key 는 유지된다.
    throw new RuntimeException('프로모션 지급 결과가 확정되지 않았어요.');
}

public function failed(Throwable $e): void
{
    FarmPointLedger::where('id', $this->ledgerId)
        ->update(['status' => 'held', 'last_error_message' => Str::limit($e->getMessage(), 200)]);
}
```

> ⚠️ `QUEUE_CONNECTION=sync`인 환경에서는 Job이 요청 스레드에서 그대로 돌아 응답이 느려진다. **운영은 반드시 `database` 또는 `redis` 큐 + `queue:work`** 로 띄운다. sync일 때는 폴링 루프를 건너뛰고 `pending`으로 남긴 뒤 스케줄러가 처리하도록 가드를 넣는다(`ShopKeywordCheckJob`의 sync 재귀 가드와 같은 논리).

### 8-5. 익명키 문제 — 지급 시점에 `x-anon-key`가 필요하다

`farm_users`는 보안상 **평문 익명키를 저장하지 않는다**(sha256만). 그런데 `executePromotion`은 `x-anon-key` 헤더가 필요하다. 해결책 두 가지 중 하나를 고른다:

| 방안 | 내용 | 판단 |
|---|---|---|
| **A. 동기 지급(권장)** | 수확·정답 요청은 사용자의 `x-user-key`를 헤더로 갖고 있다. **요청 스레드에서 지급까지 끝낸다.** Job은 "실패분 재처리" 전용으로만 쓰고, 재처리에 필요한 키는 `farm_users.toss_user_key`(암호화)나 단기 캐시(`Cache::put("farm:key:{$hash}", $plain, 24h)`)에서 꺼낸다 | 응답이 1~3초 느려지지만 사용자에게 즉시 확정 결과를 보여줄 수 있고 CS가 줄어든다 |
| **B. 단기 보관** | `farm_point_ledgers`에 요청 시점의 익명키를 **암호화해 저장**(`anon_key_enc`, `encrypted` cast), 지급 확정 후 NULL로 지운다 | 비동기 지급 가능. 대신 민감정보가 잠깐 DB에 남는다 |

**권장: A + B 혼합.**
- 정상 경로: 요청 스레드에서 `getKey → execute → result` 1회 시도(타임아웃 3초). 확정되면 `success`.
- 미확정/실패: `farm_point_ledgers.anon_key_enc`에 암호화 저장 후 `held`/`pending`. 스케줄러(`farm:retry-payouts`)가 5분마다 재시도하고, `success`/`failed` 확정 시 `anon_key_enc`를 NULL로 지운다.
- 이 경우 §2-7 스키마에 컬럼 하나를 추가한다: `anon_key_enc` `text nullable` (암호화, 확정 후 NULL).

> `.claude/CLAUDE.md` 보안 규칙("네이버 자격증명·쿠키 등 민감정보는 반드시 암호화 저장")을 그대로 따른다.

### 8-6. 스케줄러 (`routes/console.php`)

```php
// 퀴즈농장(29) — 미확정·보류 포인트 지급 재처리(예산 충전 후 자동 복구)
Schedule::command('farm:retry-payouts')
    ->everyFiveMinutes()->timezone('Asia/Seoul')->withoutOverlapping()->runInBackground();
```

`farm:retry-payouts` 동작:
```
FarmPointLedger::whereIn('status', ['pending', 'requested', 'held'])
    ->where('updated_at', '<', now()->subMinutes(3))
    ->where('attempts', '<', 20)
    ->orderBy('id')->limit(200)->get()
    → 각각 FarmPointPayoutJob::dispatchSync()  (한 배치만 처리하고 종료 — 재귀 금지)
```

### 8-7. 설정 (`config/rankfree.php`)

기능별 중첩 배열 + `env()` 기본값, 블록 위에 `|---` 스타일 한글 주석.

```php
/*
|--------------------------------------------------------------------------
| 퀴즈농장(29) — 토스 미니앱
|--------------------------------------------------------------------------
| 포인트 한도·일일 상한은 운영 중 바뀔 수 있어 app_settings 로 덮어쓴다
| (SettingsServiceProvider::boot). 토스 mTLS 인증서 경로는 .env 로만 관리한다.
*/
'farm' => [
    'plot_count'               => 3,
    'daily_mission_limit'      => 3,
    'crop_days'                => 7,
    'default_points'           => 50,
    'point_cap_per_user'       => (int) env('FARM_POINT_CAP', 5000),
    'submit_cooldown_seconds'  => 3,
    'max_attempts_per_day'     => 10,
    'toss' => [
        'base_url'         => env('TOSS_API_BASE', 'https://apps-in-toss-api.toss.im'),
        'promotion_code'   => env('TOSS_PROMOTION_CODE'),
        'cert_path'        => env('TOSS_MTLS_CERT'),
        'key_path'         => env('TOSS_MTLS_KEY'),
        'timeout'          => (int) env('TOSS_API_TIMEOUT', 10),
        'verify_anon_key'  => (bool) env('TOSS_VERIFY_ANON_KEY', true),
    ],
],
```

`.env` (운영):
```
FARM_POINT_CAP=5000
TOSS_PROMOTION_CODE=01JPPJ6SB66BQXXDAKRQZ6SZD7
TOSS_MTLS_CERT=/etc/toss/client.crt
TOSS_MTLS_KEY=/etc/toss/client.key
TOSS_VERIFY_ANON_KEY=true
```

어드민 환경설정 화면(`resources/views/admin/settings/index.blade.php`)에는 **포인트 한도·프로모션 코드·일일 상한**만 노출한다. 인증서 경로는 UI에 올리지 않는다.

---

## 9. CORS 설정

### 9-1. 결론: **지금 상태로 동작한다. 코드 변경 불필요.**

rankfree는 `config/cors.php`를 발행하지 않아 프레임워크 기본값이 적용된다:

| 항목 | 기본값 | 미니앱 관점 판정 |
|---|---|---|
| `paths` | `['api/*', 'sanctum/csrf-cookie']` | `/api/farm/*`가 포함된다 ✅ |
| `allowed_origins` | `['*']` | `*.apps.tossmini.com` / `*.private-apps.tossmini.com` / `*.web.tossmini.com` 전부 통과 ✅ |
| `allowed_headers` | `['*']` | 커스텀 헤더 `x-user-key` preflight 통과 ✅ |
| `allowed_methods` | `['*']` | ✅ |
| `supports_credentials` | `false` | **쿠키를 못 싣는다** → 애초에 헤더 토큰 방식이라 문제 없음 ✅ |
| `max_age` | 0 | preflight가 매번 발생 — 성능만 손해 |

`HandleCors`는 Laravel 기본 글로벌 미들웨어라 이미 붙어 있다.

### 9-2. Origin을 좁히고 싶다면 (선택)

```
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan config:publish cors
```

`config/cors.php`에서:

```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],

'allowed_origins' => ['*'],   // ⚠️ 그대로 둘 것 — §9-3 참조

'allowed_origins_patterns' => [],

'allowed_headers' => ['*'],

'max_age' => 86400,           // preflight 캐시 24시간 (앱 응답 속도 개선)

'supports_credentials' => false,
```

### 9-3. 🔴 `allowed_origins`를 좁히면 안 되는 이유

`paths`는 `api/*` 하나라서 **정책이 `/api/ext/*`(크롬 확장)와 `/api/v1/*`(외부 판매 API)에 동시에 적용된다.**
- 확장은 `host_permissions` 컨텍스트라 CORS 영향을 안 받지만,
- **`/api/v1/*`를 브라우저에서 호출하는 외부 고객이 있으면 즉시 깨진다.**

따라서 `allowed_origins`는 `['*']`로 유지하고, 미니앱 경로의 보안은 **`x-user-key` 검증(`auth.farm`) + 라우트별 throttle**로 확보한다. CORS는 브라우저의 편의 장치일 뿐 인증이 아니다.
Origin 제한이 정말 필요하면 `paths`를 쪼갤 수 없으므로, `routes/farm.php` 그룹에만 붙는 전용 미들웨어(`EnsureTossOrigin`)를 만들어 `Origin` 헤더 화이트리스트를 검사한다.

### 9-4. 함께 확인할 것

| 항목 | 내용 |
|---|---|
| HTTPS | 앱인토스는 HTTPS 전용. `rankfree.kr`은 이미 HTTPS ✅ |
| 정확 호스트 | `RedirectCanonicalHost`가 `rankfree.co.kr` → `rankfree.kr` 301을 낸다. `VITE_API_BASE_URL`에 **반드시 `rankfree.kr`** 을 쓴다 |
| OPTIONS와 throttle | preflight OPTIONS도 라우트 throttle에 카운트된다. 60/분 정도로 여유를 둔다 |
| 예외 렌더링 | 경로가 `api/*` 아래라 `shouldRenderJsonWhen`이 걸려 검증 실패가 자동 422 JSON이 된다 ✅ |
| 404/500 | 미니앱 클라이언트는 `!res.ok`면 throw 한다. 예상 실패는 반드시 200 + 플래그로 (§6-0) |

---

## 10. 구현 순서

### 1단계 — 스키마와 모델 (반나절)
1. 마이그레이션 8개 작성 (§2). `.claude/backup/`에 날짜 붙인 백업 후 진행
2. `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan migrate`
3. 모델 9개 작성 (§3). `#[Fillable]` + `casts()` 신문법, 한글 docblock
4. `FarmCropSeeder` 작성 후 실행 — 클라이언트 `src/game/types.ts`의 `CROPS` 6종과 `code`·`days` 일치 확인
5. **검증**: `php artisan tinker`에서 관계·스코프 동작 확인

### 2단계 — 인증과 읽기 API (반나절)
6. `AuthenticateFarmUser` + `bootstrap/app.php` 별칭 등록 (§5)
7. `routes/farm.php` + `bootstrap/app.php` `then:` require (§4)
8. `FarmStateController@show`, `FarmHarvestController@rewardInfo`, `FarmAppController@index`
9. **검증**: `php artisan route:list -v | findstr farm` 으로 등록 확인 → curl로 401/200 확인

### 3단계 — 심기·미션 목록 (반나절)
10. `PlantingService`, `FarmStatePresenter`
11. `FarmStateController@plant`, `FarmMissionController@index`
12. **검증**: `missionJson()` 응답에 `answer`가 없는지 **직접 눈으로** 확인 (테스트로도 assertJsonMissing)

### 4단계 — 채점 (하루) 🔴
13. `MissionGrader`, `MissionSubmitService`, `FarmRuleException`
14. `FarmMissionController@submit` — **검증 순서(§6-4)를 그대로 구현**
15. **검증 테스트 필수**: 정답 / 오답 / 하루 상한 초과 / 같은 밭 이중 참여 / 종료 미션 / 쿨다운

### 5단계 — 수확과 원장 (하루) 🔴
16. `PointLedgerService`, `HarvestService`
17. `FarmHarvestController@store`
18. **검증 테스트 필수**: 7일 미달 거부 / 정확히 7일 성공 / 중복 수확 거부 / 누적 5,000P 한도 / 동시 요청 2건에 1건만 성공

### 6단계 — 토스 포인트 지급 (하루~, 콘솔 승인 대기 별도)
19. `TossPromotion` + `TossPromotionException`, `config/rankfree.php` `farm` 블록, `.env`
20. `FarmPointPayoutJob`, `FarmRetryPayouts` 커맨드, `routes/console.php` 스케줄
21. `farm_point_ledgers.anon_key_enc` 컬럼 추가 마이그레이션 (§8-5)
22. **검증**: `Http::fake()`로 4109/4112/4113/PENDING 시나리오 테스트. 실제 지급은 콘솔 승인·예산 충전 후

### 7단계 — 관리자 화면 (하루)
23. 메뉴 마이그레이션 (§2-9) → 사이드바에 뜨는지 먼저 확인
24. `Admin\FarmMissionController` + `index/form` 뷰
25. `Admin\FarmCropController`, `Admin\FarmAppController`, `Admin\FarmLedgerController`
26. **검증**: Playwright로 미션 등록 → 미니앱 `/missions` 응답에 반영 → 정답 제출까지 실동작 확인 (`.claude/CLAUDE.md` 완료 기준)

### 8단계 — 복구·운영 도구 (반나절)
27. `farm:rebuild-state` 커맨드 — 로그에서 상태 재계산
28. 문서 등록: `.claude/29_TOSS_FARM_MINIAPP.md` + `.claude/CLAUDE.md` 표에 링크
29. 클라이언트 `.env`에 `VITE_API_BASE_URL=https://rankfree.kr/api/farm` 설정 후 QR 테스트

---

## 부록 A. 상태 복구 커맨드 (`farm:rebuild-state`)

`app/Console/Commands/FarmRebuildState.php`

```
/** 로그(append-only)에서 상태 테이블을 재계산한다. 상태 손상·마이그레이션 사고 복구용(29). */

--user=ID  (생략 시 전체), --dry-run

각 farm_user 에 대해:
  1) farm_plantings 재계산
     - farm_planting_days 를 (plot_index, round_no) 로 그룹 → completed_days = count, last_tended_date = max(work_date)
     - farm_harvests 에 해당 planting_id 가 있으면 status='harvested', 없고 count >= required_days 면 'ready', 아니면 'growing'
     - farm_plantings 행 자체가 없으면 로그의 자기기술 컬럼(plot_index/round_no/crop_id)으로 **재생성**
  2) farm_users.total_points = farm_point_ledgers.counted()->sum('amount')
  3) farm_users.correct_count = farm_mission_logs where result='correct' count
  4) farm_users.harvest_count = farm_harvests count
  5) 차이가 있으면 표로 출력. --dry-run 이면 저장하지 않는다
```

이 커맨드가 성립하려면 로그 테이블에 자기기술 컬럼(`plot_index`, `round_no`, `crop_id`)이 있어야 한다 — §2-5에서 일부러 중복 저장한 이유다.

## 부록 B. 테스트

`tests/Feature/` 아래, `Tests\TestCase` + `use RefreshDatabase;`, 한글 PHPDoc, `postJson`/`getJson` + `assertJsonPath`.
인증 헬퍼:

```php
/** 미니앱 사용자 헤더를 만든다. 키가 다르면 완전히 다른 사용자가 된다. */
private function farmHeaders(string $key = 'test-anon-key-1'): array
{
    return ['x-user-key' => $key];
}
```

| 파일 | 최소 케이스 |
|---|---|
| `FarmApiTest.php` | 미인증 401 / 차단 계정 403 / `/missions` 응답에 `answer` 없음(`assertJsonMissing`) / 심기 성공 201 / 같은 밭 중복 심기 거부 |
| `FarmMissionSubmitTest.php` | 정답 200 `correct=true` / 오답 200 `correct=false` / 하루 3회 초과 거부 / 같은 밭 하루 2회 거부 / 종료 미션 거부 / 쿨다운 / **한도 초과 상태에서 정답을 제출해도 `correct=true`가 안 나오는지** |
| `FarmHarvestTest.php` | 6일에서 수확 거부 / 7일 수확 성공 / 중복 수확 거부 / 누적 5,000P 초과 시 `points` 절삭 / 다른 `x-user-key`로 남의 밭 수확 불가 |
| `FarmPayoutTest.php` | `Http::fake()`로 4109→held / 4113→결과조회 확정 / PENDING→SUCCESS / FAILED→한도 롤백 / **같은 ledger 2회 실행 시 `toss_key` 재사용** |
| `AdminFarmMissionTest.php` | `OperatorRole(is_super=true)` 유저로 `actingAs` → `/admin/farm-missions` 200 / 출석형+포인트 검증 실패 / 질문 있는데 정답 없으면 검증 실패 |

외부 HTTP는 실제로 때리지 않는다(`Http::fake()`). PHPUnit 12, 스타일은 laravel/pint.

## 부록 C. 운영 주의사항 체크리스트

- [ ] `missionJson()`에 `answer` 계열 필드가 없는가 (리뷰 1순위)
- [ ] 모든 한도 검사가 **채점보다 앞**에 있는가
- [ ] 수확 7일 검증이 `completed_days`가 아니라 `farm_planting_days` count인가
- [ ] `farm_point_ledgers` unique(source, source_id)가 걸려 있는가
- [ ] `grantable()`이 `lockForUpdate()` 안에서 호출되는가
- [ ] 재시도가 **같은 `toss_key`** 를 쓰는가 (새 key = 중복 지급)
- [ ] 지급 Job dispatch가 **트랜잭션 커밋 이후**인가
- [ ] 비즈니스 실패가 4xx가 아니라 200 + 플래그인가
- [ ] 권한/차단이 401이 아니라 403인가
- [ ] 라우트마다 `throttle:N,1`이 붙어 있는가 (전역 throttle 없음)
- [ ] `routes/apiv1.php`를 건드리지 않았는가
- [ ] 로그 테이블에 UPDATE/DELETE 하는 코드가 없는가 (status 전이 제외)
- [ ] 날짜 비교가 KST 전제인가 (`now()`가 이미 KST)
- [ ] `.claude/29_TOSS_FARM_MINIAPP.md` 등록 + `.claude/CLAUDE.md` 표 갱신
- [ ] 큰 변경 전 `.claude/backup/`에 날짜 붙인 백업
- [ ] Playwright 실동작 검증 완료 (`.claude/CLAUDE.md` 완료 기준)
- [ ] artisan은 절대경로 `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan …`
