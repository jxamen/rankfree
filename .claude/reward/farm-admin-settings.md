# 퀴즈농장 운영 설정 — 관리자 환경설정 설계

쿨타임·하루 참여 횟수·작물별 수확 포인트를 **rankfree 관리자 환경설정(`/admin/settings`)에서 바꾸고**,
미니앱이 `GET /config`로 받아 화면에 반영하는 구조를 정리한 문서예요.

- 대상 코드: `C:/Users/jxame/Documents/project/rankfree` (관리자·서버)
- 대상 클라이언트: `C:/Users/jxame/Documents/project/toss_inapp_farmer/farm-quiz`
- 선행 문서: `design-01-schema.md`(스키마) · `design-02-runtime.md`(런타임) · `design-03-billing.md`(정산) · `rankfree-integration.md`(통합 확정판) · `server-api-spec.md`(클라 계약)

---

## 0. 결정 요약

| # | 결정 | 이유 |
|---|---|---|
| D1 | 쿨타임·하루 횟수는 **`app_settings` 2개 키**, 작물 포인트는 **`farm_crops.points` 컬럼** | 작물 포인트는 이미 DB 마스터가 원천(design-01 §2-2)이고 심을 때 스냅샷돼요. `app_settings`에 또 넣으면 원천이 둘이 돼요 |
| D2 | 편집 UI는 **환경설정 화면 안 새 탭(`farm`) 한 곳**에 모아요 | 사업자 요구가 "환경설정 화면에서 바꾼다"이고, 저장 버튼이 갈리면 운영자가 헷갈려요 |
| D3 | farm 런타임은 **`SettingsServiceProvider`/`config()`에 의존하지 않아요.** `FarmSettings` 캐시만 읽어요 | provider가 매 요청 `app_settings` 전 행을 SELECT+복호화해요(§6-4). farm이 rankfree 최대 트래픽이라 그 경로를 타면 안 돼요 |
| D4 | 작물 포인트는 **심을 때 스냅샷**(`farm_plantings.reward_points`), 소급 없음 | 부채 추정·원장이 스냅샷 기준이에요. `server-api-spec.md`의 "수확 시점 값" 서술과 충돌하며, **백엔드 확정판이 맞아요** (§7-3) |
| D5 | 5,000P 상한은 **저장 시 차단 2건 + 경고 3건**으로 거르고, 최종 방어는 런타임 원자 게이트가 담당해요 | 수확 횟수는 시간이 지나면 무한이라 저장 시점 계산만으로는 못 막아요 (§5-2) |
| D6 | 1인 누적 상한(5,000P)은 **읽기 전용 표시**, `.env`로만 변경 | 토스 프로모션 정책 상한이라 올릴 수 없어요. 내리는 기능은 요구에 없어요 |

---

## 1. 설정 키 목록

### 1-1. `app_settings` 키 (운영자가 바꿔요)

rankfree 네이밍 관례를 그대로 따라요 — **`도메인.항목` 점 2단계, 소문자 스네이크, `key`는 80자 이내**
(`app_settings.key`는 `string(80) unique` — `database/migrations/2026_07_13_000007_create_app_settings_table.php:12-17`).

| 키 | 저장 타입 | 기본값 | 허용 범위 | 설명 |
|---|---|---|---|---|
| `farm.cooldown_minutes` | 숫자 문자열 | `120` | 1 ~ 1440 | 미션 참여 사이 최소 간격(분). 참여 시 `farm_users.cooldown_until = now() + 이 값` |
| `farm.daily_mission_limit` | 숫자 문자열 | `3` | 1 ~ 20 | 한 사람이 농장일 하루에 참여할 수 있는 횟수. `farm_users.today_count` 상한 |

> `app_settings.value`는 `longText` + Laravel `encrypted` 캐스트라 **전부 문자열이고 암호화 저장**돼요
> (`app/Models/AppSetting.php:14-17`). 정수도 `'120'` 처럼 문자열로 넣고 읽을 때 캐스팅해요.

**키 이름을 이렇게 정한 이유** — 설계 문서마다 이름이 갈려 있었어요.
`daily_mission_limit`(draft §8-7 / design-02 §11) 대 `daily_limit`(design-01 체크리스트 1011행).
**`farm.daily_mission_limit`으로 확정**해요. `farm_missions.daily_limit`(미션별 1인 1일 한도)·`daily_limit_qty`(미션 일 수량)라는
DB 컬럼이 따로 있어서, 짧은 이름을 쓰면 코드에서 섞여요.

### 1-2. `farm_crops` 컬럼 (작물별 수확 포인트)

`farm_crops` 마스터의 `points` 컬럼을 그대로 편집해요 (`design-01-schema.md:239-256`).

| 작물 `code` | 이름 | 클라 폴백값 | 허용 범위 | 비고 |
|---|---|---|---|---|
| `lettuce` | 상추 | 50 | 0 ~ 5000 | |
| `carrot` | 당근 | 70 | 0 ~ 5000 | |
| `onion` | 양파 | 70 | 0 ~ 5000 | |
| `potato` | 감자 | 100 | 0 ~ 5000 | |
| `tomato` | 방울토마토 | 150 | 0 ~ 5000 | |
| `corn` | 옥수수 | 200 | 0 ~ 5000 | |

- `code`는 클라이언트 `CROPS[].id`와 1:1로 맞아요 (`farm-quiz/src/game/types.ts`).
- "클라 폴백값"은 `farm-quiz/src/api/config.ts:34-47`의 `DEFAULT_CONFIG.pointsByCrop`이에요.
  **서버 응답을 못 받았을 때만 쓰는 값**이고 정책 기준이 아니에요.
- **실제 운영 포인트 금액은 아직 미정이에요.** 설계 문서 전반은 500P를 가정하는데
  (`rankfree-integration.md:391`), `config/rankfree.php`의 폴백은 50P라 10배 차이가 나요. → **확인 필요**

### 1-3. `config/rankfree.php` 폴백 (코드에서만 바꿔요)

`config/rankfree.php`에는 **아직 `farm` 블록이 없어요.** 신설이 필요해요.
아래는 이 문서가 요구하는 최소 키만 적은 거예요(전체 farm 블록은 design-02 §11 참고).

| config 경로 | env | 기본값 | 설명 |
|---|---|---|---|
| `rankfree.farm.cooldown_minutes` | `FARM_COOLDOWN_MIN` | 120 | `app_settings`에 값이 없을 때 폴백 |
| `rankfree.farm.daily_mission_limit` | — | 3 | 동일 |
| `rankfree.farm.default_points` | — | 50 | 목록에 없는 작물의 기본 수확 포인트 |
| `rankfree.farm.point_cap_per_user` | `FARM_POINT_CAP` | 5000 | 1인 누적 상한. **어드민에서 편집 불가**(읽기 전용 표시) |
| `rankfree.farm.plot_count` | — | 3 | 밭 칸 수. 검증식에 쓰여요 |
| `rankfree.farm.crop_days` | — | 7 | 작물 성장 일수(기본값). 검증식에 쓰여요 |

```php
// config/rankfree.php — 신설. 기존 블록의 주석·캐스팅 관례를 그대로 따라요.
/*
|--------------------------------------------------------------------------
| 퀴즈농장(29) — 토스 미니앱 운영 설정
|--------------------------------------------------------------------------
| 여기 값은 "폴백"이에요. 운영 중 조정하는 값은 app_settings 가 덮어써요.
| ⚠ 단, farm 런타임은 config() 가 아니라 FarmSettings(캐시)를 읽어요 — §6 참고.
*/
'farm' => [
    'cooldown_minutes'     => (int) env('FARM_COOLDOWN_MIN', 120),  // 재참여 쿨타임(분)
    'daily_mission_limit'  => 3,                                    // 1인 1일(농장일) 참여 상한
    'default_points'       => 50,                                   // farm_crops.points 조회 실패 폴백
    'point_cap_per_user'   => (int) env('FARM_POINT_CAP', 5000),    // 토스 프로모션 1인 누적 상한
    'plot_count'           => 3,                                    // 밭 칸 수
    'crop_days'            => 7,                                    // 작물 성장 일수(기본값)
],
```

> ⚠ 운영은 `config:cache` 상태예요. `.env`를 바꾸면 `php83 artisan config:cache`를 다시 돌려야 반영돼요
> (`design-02-runtime.md:1710`). 그래서 **자주 만질 값은 전부 `app_settings` 쪽에 뒀어요.**

---

## 2. `app_settings` 저장 방식

rankfree에는 설정 전용 패키지가 없어요. `AppSetting` 모델의 static 헬퍼가 전부예요.

```php
// app/Models/AppSetting.php:20-46 — 읽기/쓰기 API 전부
AppSetting::read('farm.cooldown_minutes');        // ?string  (행이 없을 때만 default 반환)
AppSetting::write('farm.cooldown_minutes', '120'); // updateOrCreate, 항상 문자열
AppSetting::readJson('some.key');                  // array
AppSetting::map();                                 // 전체 key => value
```

**반드시 지켜야 하는 것 3가지**

1. **정수는 문자열로 넣어요.** `(string) max(1, (int) $input)` — `referral.bonus_per` 저장부와 같은 형태예요
   (`app/Http/Controllers/Admin/SettingsController.php:184-186`).
2. **`read()`의 default는 "행이 없을 때"만 나와요.** 빈 문자열로 저장된 행이 있으면 `''`가 돌아오고
   `(int) '' === 0`이 돼요. 그래서 폴백은 `??`가 아니라 `?:`를 써요.
   ```php
   $min = (int) (AppSetting::read('farm.cooldown_minutes') ?: config('rankfree.farm.cooldown_minutes'));
   ```
3. **`SIMPLE` 상수에 넣지 않아요.** `update()`가 `SIMPLE` 키를 요청 유무와 무관하게
   `input($field, '')`로 전부 덮어써요(`SettingsController.php:171-173`). 정수 키가 거기 들어가면
   폼 일부만 담은 PUT 한 번에 쿨타임이 `''`(=0분)으로 날아가요. **개별 write 블록**으로 처리해요
   (`community.rewrite_*` · `referral.bonus_*`와 같은 방식).

작물 포인트는 `app_settings`가 아니라 `farm_crops`를 UPDATE해요.
`value`가 암호화 컬럼이라 SQL 조인·집계가 불가능한데, 작물 포인트는 심을 때 스냅샷되고
부채 집계 SQL에서 합산되는 값이라 평문 컬럼이어야 해요.

---

## 3. 관리자 화면 변경 — 파일과 코드

`/admin/settings`는 **8개 탭이 들어간 단일 거대 폼**이에요. 탭은 CSS로 숨길 뿐이라
**숨은 탭의 input도 항상 함께 POST**돼요(`resources/views/admin/settings/index.blade.php:679-688`).
그래서 "탭을 안 열어도 저장은 된다"를 전제로 설계해요.

라우트·권한은 기존 것을 그대로 써요. 변경 없어요.
`PUT /admin/settings` → `SettingsController::update` (`routes/web.php:564-569`),
미들웨어 `AdminHostOnly + auth + operator` (`routes/web.php:335-340`).

### 3-1. `app/Domain/Farm/FarmSettings.php` — 신규

읽기·캐시·무효화를 여기 한 곳에 모아요. farm 런타임과 `/config`가 **전부 이 클래스만** 봐요.

```php
<?php

namespace App\Domain\Farm;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 퀴즈농장 운영 설정 — 읽기 단일 창구.
 *
 * config() 를 거치지 않는 이유: SettingsServiceProvider 는 매 요청 app_settings 전 행을
 * SELECT + 복호화한다. farm 은 rankfree 최대 트래픽(피크 438 QPS)이라 그 경로에 얹으면 안 된다.
 * 여기서는 캐시 1개(TTL 300초)로 처리하고, 저장 시 forget 으로 즉시 반영한다.
 */
final class FarmSettings
{
    public const CACHE_KEY = 'farm:settings:v1';
    public const TTL = 300; // 초

    /** @return array{cooldown_minutes:int, daily_mission_limit:int, crop_points:array<string,int>, default_points:int, point_cap:int} */
    public static function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::TTL, fn () => self::load());
        } catch (\Throwable) {
            return self::defaults(); // 캐시·DB 미준비 — 앱이 죽지 않게 폴백
        }
    }

    public static function cooldownMinutes(): int { return self::all()['cooldown_minutes']; }
    public static function dailyMissionLimit(): int { return self::all()['daily_mission_limit']; }
    /** @return array<string,int> */
    public static function cropPoints(): array { return self::all()['crop_points']; }

    /** 작물 1개 수확 포인트. 없는 작물은 기본값. */
    public static function pointsOf(string $cropCode): int
    {
        $all = self::all();
        return $all['crop_points'][$cropCode] ?? $all['default_points'];
    }

    /** 저장 직후 반드시 호출한다. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function load(): array
    {
        $d = self::defaults();

        // ?: 를 쓴다 — '' 로 저장된 행이 있으면 ?? 는 폴백이 발동하지 않는다(AppSetting::read 특성)
        $cool  = (int) (AppSetting::read('farm.cooldown_minutes') ?: $d['cooldown_minutes']);
        $daily = (int) (AppSetting::read('farm.daily_mission_limit') ?: $d['daily_mission_limit']);

        $crops = [];
        if (Schema::hasTable('farm_crops')) {   // 마이그레이션 이전에도 죽지 않게
            $crops = DB::table('farm_crops')->where('is_active', true)
                ->pluck('points', 'code')->map(fn ($p) => (int) $p)->all();
        }

        return [
            'cooldown_minutes'    => max(1, $cool),
            'daily_mission_limit' => max(1, $daily),
            'crop_points'         => $crops,
            'default_points'      => $d['default_points'],
            'point_cap'           => $d['point_cap'],
        ];
    }

    private static function defaults(): array
    {
        return [
            'cooldown_minutes'    => (int) config('rankfree.farm.cooldown_minutes', 120),
            'daily_mission_limit' => (int) config('rankfree.farm.daily_mission_limit', 3),
            'crop_points'         => [],
            'default_points'      => (int) config('rankfree.farm.default_points', 50),
            'point_cap'           => (int) config('rankfree.farm.point_cap_per_user', 5000),
        ];
    }
}
```

### 3-2. `SettingsController::index()` — 뷰 데이터 추가

`index()`의 view 배열(현재 49~121행) 끝에 추가해요.

```php
// 퀴즈농장(29) — 쿨타임·하루 참여 횟수·작물별 수확 포인트
'farmCooldownMinutes' => FarmSettings::cooldownMinutes(),
'farmDailyLimit'      => FarmSettings::dailyMissionLimit(),
'farmCrops'           => Schema::hasTable('farm_crops')
    ? DB::table('farm_crops')->orderBy('sort_order')->get(['code', 'name', 'emoji', 'points', 'is_active'])
    : collect(),
'farmPointCap'        => (int) config('rankfree.farm.point_cap_per_user', 5000),
'farmPlotCount'       => (int) config('rankfree.farm.plot_count', 3),
'farmCropDays'        => (int) config('rankfree.farm.crop_days', 7),
'farmMaxPayout'       => Schema::hasTable('farm_missions')
    ? (int) DB::table('farm_missions')->where('is_active', true)->max('payout_point')
    : 0,
```

> ⚠ 기존 `index()`에는 `'quizThinking'` 키가 99·100행에 **중복 정의**돼 있어요(뒤가 이겨요).
> 이번 작업과 무관한 기존 버그라 **건드리지 않고 기록만 남겨요.**

### 3-3. `SettingsController::update()` — farm 저장 블록 추가

`referral.bonus_*` 저장 다음(현재 184-186행 뒤), 리다이렉트 앞에 넣어요.

```php
// 퀴즈농장(29) — 쿨타임·하루 참여 횟수·작물별 수확 포인트
// 폼 일부만 담은 PUT 으로 기존 값이 날아가지 않게 has() 로 감싼다.
if ($request->has('farm_cooldown_minutes')) {
    $this->saveFarmSettings($request);
}
```

```php
/**
 * 퀴즈농장 운영 설정 저장.
 *
 * 이 화면 전체(PUT /admin/settings)에는 원래 validate() 가 없다.
 * 잘못된 값이 그대로 저장되면 참여가 통째로 막히므로 farm 항목만 명시적으로 검증한다.
 */
private function saveFarmSettings(Request $request): void
{
    $cap      = (int) config('rankfree.farm.point_cap_per_user', 5000);
    $plots    = (int) config('rankfree.farm.plot_count', 3);
    $cropDays = (int) config('rankfree.farm.crop_days', 7);

    $data = $request->validate([
        'farm_cooldown_minutes'    => ['required', 'integer', 'min:1', 'max:1440'],
        'farm_daily_mission_limit' => ['required', 'integer', 'min:1', 'max:20'],
        'farm_crop_points'         => ['array', 'max:50'],
        'farm_crop_points.*'       => ['integer', 'min:0', 'max:' . $cap],
    ], [
        'farm_cooldown_minutes.max'    => '쿨타임은 1440분(24시간)을 넘을 수 없어요.',
        'farm_crop_points.*.max'       => '작물 1개 수확 포인트가 1인 누적 상한(' . number_format($cap) . 'P)을 넘을 수 없어요.',
    ]);

    $cooldown  = (int) $data['farm_cooldown_minutes'];
    $daily     = (int) $data['farm_daily_mission_limit'];
    $points    = array_map('intval', (array) ($data['farm_crop_points'] ?? []));
    $maxCrop   = $points ? max($points) : 0;
    $maxPayout = Schema::hasTable('farm_missions')
        ? (int) DB::table('farm_missions')->where('is_active', true)->max('payout_point')
        : 0;

    // ── 5,000P 교차검증: 한 사이클(작물 1회분) 최대 적립 ─────────────────
    // 참여 1회 = 밭 1칸 하루치라, 하루 실효 참여는 min(일 상한, 밭 칸 수)로 묶인다.
    $perDay        = min($daily, $plots);
    $cycleAccrual  = $perDay * $cropDays * $maxPayout;      // 참여 적립분
    $cycleHarvest  = $plots * $maxCrop;                     // 수확 보너스분

    if ($maxCrop > $cap) {   // validate 에서 이미 걸리지만 이중 방어
        throw ValidationException::withMessages(['farm_crop_points' =>
            '작물 수확 포인트가 1인 누적 상한을 넘어 영구히 지급되지 않아요.']);
    }
    if ($maxPayout > 0 && $cycleAccrual >= $cap) {
        throw ValidationException::withMessages(['farm_daily_mission_limit' =>
            "참여 적립만으로 상한에 도달해요(하루 {$perDay}회 × {$cropDays}일 × {$maxPayout}P = "
            . number_format($cycleAccrual) . "P ≥ " . number_format($cap) . "P). 수확 보너스가 항상 0이 돼요."]);
    }

    AppSetting::write('farm.cooldown_minutes', (string) $cooldown);
    AppSetting::write('farm.daily_mission_limit', (string) $daily);

    if ($points && Schema::hasTable('farm_crops')) {
        foreach ($points as $code => $p) {
            DB::table('farm_crops')->where('code', $code)->update(['points' => $p, 'updated_at' => now()]);
        }
    }

    FarmSettings::forget();   // ★ 캐시 무효화 — 빠뜨리면 최대 5분간 옛 값이 나간다

    // 경고는 막지 않고 알리기만 한다(운영자가 의도했을 수 있다)
    foreach (self::farmWarnings($cooldown, $daily, $plots, $cropDays, $cycleAccrual + $cycleHarvest, $cap) as $w) {
        session()->flash('farm_warning', $w);
    }
}
```

### 3-4. 탭 화이트리스트 — `update()` 리다이렉트

현재 190행의 `in_array` 목록에 `'farm'`을 추가해요. **안 넣으면 저장 후 `basic` 탭으로 튕겨요.**

```php
$tab = in_array($request->input('tab'),
    ['basic', 'api', 'integ', 'member', 'payment', 'place', 'domains', 'custom', 'farm'], true)
    ? $request->input('tab') : null;
```

### 3-5. `resources/views/admin/settings/index.blade.php`

**(a) 탭 배열에 추가** — 17행

```php
$__tabs = ['basic' => '광고·데이터 API', 'api' => 'AI API', 'integ' => '외부 연동', 'member' => '회원',
           'payment' => '결제', 'place' => '플레이스 패턴', 'domains' => '2차 도메인',
           'custom' => '커스텀 코드', 'farm' => '퀴즈농장'];
```

**(b) 탭 패널 추가** — 다른 `rf-tabpane` 블록과 같은 위치(메인 폼 안)

```blade
{{-- ── 퀴즈농장: 쿨타임·하루 참여 횟수·작물별 수확 포인트 ───────────── --}}
<div class="rf-tabpane" data-tab="farm" @if ($__active !== 'farm') hidden @endif>
    <p class="text-muted mb-4" style="font-size:var(--fs-xs);">
        미니앱이 <code>GET /config</code>로 받아가는 <b>운영 값</b>입니다.
        저장 즉시 반영되며, <b>이미 시작된 쿨타임과 이미 심어진 작물에는 소급되지 않습니다.</b>
    </p>

    @if (session('farm_warning'))
        <div class="card-soft mb-3" style="border-left:3px solid var(--color-warning);font-size:var(--fs-xs);">
            {{ session('farm_warning') }}
        </div>
    @endif

    <div class="mb-3" style="max-width:560px;">
        <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;display:block;margin-bottom:5px;">
            재참여 쿨타임(분) <span class="text-muted-soft" style="font-weight:400;">기본 120, 1~1440</span>
        </label>
        <input type="number" name="farm_cooldown_minutes" value="{{ old('farm_cooldown_minutes', $farmCooldownMinutes) }}"
               min="1" max="1440" step="1" class="input" style="width:140px;font-size:var(--fs-xs);">
    </div>

    <div class="mb-4" style="max-width:560px;">
        <label class="text-muted" style="font-size:var(--fs-xs);font-weight:600;display:block;margin-bottom:5px;">
            1인 1일 참여 횟수 <span class="text-muted-soft" style="font-weight:400;">기본 3, 1~20 · 밭 {{ $farmPlotCount }}칸을 넘으면 초과분은 항상 거절돼요</span>
        </label>
        <input type="number" name="farm_daily_mission_limit" value="{{ old('farm_daily_mission_limit', $farmDailyLimit) }}"
               min="1" max="20" step="1" class="input" style="width:140px;font-size:var(--fs-xs);">
    </div>

    <h3 style="font-size:var(--fs-sm);font-weight:700;margin:0 0 8px;">작물별 수확 포인트</h3>
    <p class="text-muted mb-3" style="font-size:var(--fs-xs);">
        7일 완주 후 수확할 때 지급됩니다. <b>심는 시점의 금액이 고정</b>되므로 진행 중인 작물에는 반영되지 않습니다.
    </p>

    @forelse ($farmCrops as $crop)
        <div class="flex items-center gap-2 mb-2" style="max-width:420px;">
            <span style="width:150px;font-size:var(--fs-xs);">{{ $crop->emoji }} {{ $crop->name }}
                <span class="text-muted-soft" style="font-family:var(--font-mono);">{{ $crop->code }}</span></span>
            <input type="number" name="farm_crop_points[{{ $crop->code }}]"
                   value="{{ old('farm_crop_points.' . $crop->code, $crop->points) }}"
                   min="0" max="{{ $farmPointCap }}" step="10" class="input text-right"
                   style="width:120px;font-size:var(--fs-xs);">
            <span class="text-muted-soft" style="font-size:var(--fs-xs);">P</span>
        </div>
    @empty
        <p class="text-muted" style="font-size:var(--fs-xs);">
            <code>farm_crops</code> 테이블이 아직 없어요. 마이그레이션 후 작물이 표시됩니다.
        </p>
    @endforelse

    <div class="card-soft mt-4" style="max-width:560px;font-size:var(--fs-xs);">
        <b>1인 누적 지급 상한 {{ number_format($farmPointCap) }}P</b> — 토스 프로모션 정책값이라 화면에서 바꿀 수 없어요
        (<code>.env</code>의 <code>FARM_POINT_CAP</code>).
        현재 설정 기준 한 사이클({{ $farmCropDays }}일) 최대 적립:
        <b>{{ number_format(min($farmDailyLimit, $farmPlotCount) * $farmCropDays * $farmMaxPayout
              + $farmPlotCount * ($farmCrops->max('points') ?? 0)) }}P</b>
    </div>
</div>
```

> ⚠ **중첩 `<form>` 금지.** 메인 폼(`#rf-settings-form`) 안에 `<form>`을 넣으면 `</form>`이 메인 폼을
> 조기 종료시켜 뒤쪽 필드가 전부 전송되지 않아요. 작물 행은 전부 메인 폼 안의 `input`으로만 두세요.

### 3-6. 변경 파일 요약

| 파일 | 변경 | 비고 |
|---|---|---|
| `app/Domain/Farm/FarmSettings.php` | **신규** | 읽기·캐시·무효화 단일 창구 |
| `app/Http/Controllers/Admin/SettingsController.php` | 수정 | `index()` 뷰 데이터, `update()` farm 블록, `saveFarmSettings()`, 탭 화이트리스트(190행) |
| `resources/views/admin/settings/index.blade.php` | 수정 | `$__tabs`(17행) + `farm` 탭 패널 |
| `config/rankfree.php` | 수정 | `farm` 블록 신설 |
| `app/Http/Controllers/Api/Farm/FarmConfigController.php` | **신규** | `GET /config` |
| `routes/farm.php` | 수정 | `/config` 라우트 1줄 (`auth.farm` 그룹 **밖**) |
| `tests/Feature/FarmSettingsTest.php` | **신규** | §10 참고 |
| `app/Providers/SettingsServiceProvider.php` | **변경 없음** | D3 — farm은 config 오버라이드를 타지 않아요 |
| `routes/web.php` | **변경 없음** | 기존 `PUT /admin/settings` 재사용 |

---

## 4. `GET /config` 응답 계약

### 4-1. 엔드포인트

```
GET /api/farm/config
```

- **인증 불필요.** 사용자별 데이터가 없어요. `auth.farm` 그룹 **밖**에 두면 요청당 사용자 조회 1회를 아껴요.
- 클라이언트는 `VITE_API_BASE_URL=https://<도메인>/api/farm`으로 두고 `/config`를 호출해요
  (`farm-quiz/src/api/client.ts:34` — `fetch(BASE_URL + path)`).
- CORS 허용 Origin에 `https://<appName>.apps.tossmini.com` / `https://<appName>.private-apps.tossmini.com` 등록 필요.
  (문서마다 `.web.tossmini.com` 표기도 있어 **콘솔에서 실제 값 확인 필요**)

### 4-2. 응답 200

```json
{
  "cooldownMinutes": 120,
  "dailyMissionLimit": 3,
  "pointsByCrop": {
    "lettuce": 50,
    "carrot": 70,
    "onion": 70,
    "potato": 100,
    "tomato": 150,
    "corn": 200
  },
  "defaultPoints": 50,
  "maxPointPerUser": 5000
}
```

| 필드 | 타입 | 원천 | 설명 |
|---|---|---|---|
| `cooldownMinutes` | number | `farm.cooldown_minutes` | 참여 사이 최소 간격(분) |
| `dailyMissionLimit` | number | `farm.daily_mission_limit` | 1인 1일 참여 횟수 |
| `pointsByCrop` | object | `farm_crops.points` (`is_active`만) | 작물 code → 수확 포인트 |
| `defaultPoints` | number | `rankfree.farm.default_points` | 목록에 없는 작물의 기본액 |
| `maxPointPerUser` | number | `rankfree.farm.point_cap_per_user` | 1인 누적 상한 |

**필드 이름은 클라이언트 `GameConfig`와 정확히 같아야 해요** (`farm-quiz/src/api/config.ts:15-26`).
클라는 `{ ...DEFAULT_CONFIG, ...서버응답 }`으로 병합하므로 **일부만 내려도 동작**하지만,
없는 필드는 조용히 폴백값이 쓰여요 — 전부 채워 내리는 걸 권장해요.

### 4-3. 헤더

```
Cache-Control: public, max-age=60
```

사용자 무관 응답이라 공용 캐시가 안전해요. 저장 즉시 반영이 필요하면 `max-age=0`으로 낮추되,
그만큼 오리진 요청이 늘어요. (앱 실행당 1회 호출 = 하루 100만 참여 기준 대략 평균 10~25 QPS 수준으로 추정 — **실측 확인 필요**)

### 4-4. 컨트롤러

```php
final class FarmConfigController extends Controller
{
    public function __invoke()
    {
        $s = FarmSettings::all();

        return response()->json([
            'cooldownMinutes'   => $s['cooldown_minutes'],
            'dailyMissionLimit' => $s['daily_mission_limit'],
            'pointsByCrop'      => (object) $s['crop_points'],  // 빈 배열이 [] 로 나가지 않게
            'defaultPoints'     => $s['default_points'],
            'maxPointPerUser'   => $s['point_cap'],
        ])->header('Cache-Control', 'public, max-age=60');
    }
}
```

### 4-5. 이 값들은 **표시용**이에요

> 쿨타임 초과·횟수 초과·지급 금액은 **서버가 제출·수확 시점에 다시 검증**해요.
> 클라이언트가 받은 설정은 조작될 수 있어요.

판정은 `farm_users` 원자 UPDATE 한 문장이 담당해요 (`design-01-schema.md:204-218`).

```sql
UPDATE farm_users
   SET today_count    = IF(today_date = :farmDay, today_count + 1, 1),
       today_date     = :farmDay,
       cooldown_until = DATE_ADD(NOW(), INTERVAL :cooldown_min MINUTE),
       accrued_points = accrued_points + :payout_point
 WHERE id = :farm_user_id
   AND status = 'active'
   AND (today_date <> :farmDay OR today_count < :daily_limit)   -- farm.daily_mission_limit
   AND (cooldown_until IS NULL OR cooldown_until <= NOW())      -- farm.cooldown_minutes
   AND accrued_points + :payout_point <= :point_cap             -- 5,000P
-- affected_rows = 1 이면 통과, 0 이면 거절
```

`:cooldown_min`·`:daily_limit`·`:point_cap` 바인딩 값은 **반드시 `FarmSettings`에서 가져와요.**
`/config`와 판정이 같은 캐시를 보므로 표시와 판정이 어긋나지 않아요.

---

## 5. 검증 규칙

### 5-1. 단일 값 범위

| 필드 | 규칙 | 거부 사유 |
|---|---|---|
| `farm_cooldown_minutes` | `required, integer, min:1, max:1440` | 0이면 쿨타임 무력화, 1440(24h) 초과는 농장일 안에 재참여 불가 |
| `farm_daily_mission_limit` | `required, integer, min:1, max:20` | 0이면 전원 참여 불가 |
| `farm_crop_points.*` | `integer, min:0, max:5000` | 상한 초과분은 영구히 지급 불가 |

> 메인 저장 경로(`PUT /admin/settings`)에는 원래 `$request->validate()`가 **한 번도 없어요.**
> blade의 `min`/`max`는 HTML 힌트일 뿐이라 서버는 문자열을 그대로 저장해요.
> farm 항목만 `saveFarmSettings()` 안에서 명시적으로 검증해요. 기존 항목은 건드리지 않아요.

### 5-2. 5,000P 상한 교차검증

계산에 쓰는 값:

```
cap        = rankfree.farm.point_cap_per_user   (5000)
plots      = rankfree.farm.plot_count           (3)
cropDays   = rankfree.farm.crop_days            (7)
daily      = farm.daily_mission_limit           (입력값)
maxCrop    = max(farm_crop_points.*)            (입력값)
maxPayout  = max(farm_missions.payout_point)    (활성 미션 중 최대 · 미션 등록 화면에서 관리)

perDay        = min(daily, plots)               ← 참여 1회 = 밭 1칸 하루치라 밭 수로 묶여요
cycleAccrual  = perDay × cropDays × maxPayout   ← 한 사이클 참여 적립
cycleHarvest  = plots × maxCrop                 ← 한 사이클 수확 보너스
cycleWorst    = cycleAccrual + cycleHarvest
```

| # | 조건 | 처리 | 이유 |
|---|---|---|---|
| **B1** | `maxCrop > cap` | **저장 거부** | 원자 게이트 `accrued + payout <= cap`을 단독으로 못 넘어요. 그 작물은 영원히 0P |
| **B2** | `cycleAccrual >= cap` | **저장 거부** | 참여 적립만으로 상한을 채워 수확 보너스가 항상 0이 돼요. 7일 완주 동기가 사라져요 |
| **W1** | `cycleWorst > cap` | 경고(저장은 허용) | 사이클 중간에 상한 도달 → 이후는 부분 지급(`min(crop, cap − accrued)`)이 돼요 |
| **W2** | `daily > plots` | 경고 | 초과 참여는 돌볼 밭이 없어 항상 거절(`reject_reason=plot_done`)돼요 |
| **W3** | `(daily − 1) × cooldown > 1200` | 경고 | 농장일 활동 창(06:00~02:00, 20시간=1200분) 안에 설정한 횟수를 채울 수 없어요 |

**왜 저장 시점 계산만으로는 부족한가** — 수확은 시간이 지나면 반복돼요.
7일마다 3작물이면 상한 도달은 시간문제이고, 저장 시점에는 "언제까지 운영할지"를 알 수 없어요.
그래서 저장 검증은 **명백한 설정 오류(B1·B2)만 차단**하고, 실제 상한은 런타임 원자 게이트가 지켜요.
상한에 닿은 사용자는 수확 시 부분 지급(`min(crop_points, cap − accrued)`)을 받고,
`{ok:true, points:0, message:'누적 포인트 한도에…'}`가 나가요 (`rankfree-integration.md:846`).

**예시 — 현재 문서의 예시값이 이미 B2에 걸려요**
`server-api-spec.md`의 참여 포인트 예시 300P를 쓰면
`3회 × 7일 × 300P = 6,300P ≥ 5,000P` → 저장 거부예요.
참여 적립은 **100P 이하**로 잡아야 수확 보너스가 살아나요. → **참여 포인트 실제 금액 확인 필요**

### 5-3. 정합성 방어

| 상황 | 처리 |
|---|---|
| `farm_crops` 테이블 없음 | 작물 편집칸을 숨기고 안내 문구. 저장 시 `Schema::hasTable` 가드로 skip |
| `farm_missions` 테이블 없음 | `maxPayout = 0` → B2 검사 비활성(0이면 검사 자체를 건너뜀) |
| 폼 일부만 담은 PUT | `$request->has('farm_cooldown_minutes')` 가드로 farm 블록 전체를 skip |
| 없는 작물 code 전송 | `where('code', $code)->update()`가 0행 → 조용히 무시 |

---

## 6. 캐시 전략

### 6-1. 키와 TTL

| 항목 | 값 |
|---|---|
| 캐시 키 | `farm:settings:v1` (단일 키. 응답 조립에 필요한 값 전부를 배열로 보관) |
| TTL | **300초** |
| 무효화 | `FarmSettings::forget()` — `saveFarmSettings()` 마지막 줄 |
| 스토어 | 앱 기본 스토어 (`config('cache.default')`) |
| 태그 | **사용 안 해요.** `file`·`database` 스토어 모두 태그 미지원이에요 |

**`rememberForever`를 쓰지 않는 이유** — rankfree의 유일한 설정 캐시인 `AppSetting::customHead()`가
`rememberForever` + 수동 `forget` 1곳 조합인데, tinker·시더·다른 코드 경로로 값을 바꾸면
**영원히 옛 값이 남아요**(`app/Models/AppSetting.php:52-71`, 무효화는 `SettingsController.php:168` 단 한 곳).
TTL 300초를 두면 어떤 경로로 바뀌어도 5분 안에 수렴해요. 즉시 반영은 `forget`이 담당해요.

### 6-2. 캐시 스토어 — **확인 필요**

요구사항에는 "현재 앱 캐시 드라이버는 file"이라고 되어 있는데,
**rankfree 실제 설정은 `database`예요** (`.env:40 CACHE_STORE=database`, `config/cache.php:18` 기본값도 `database`).

이 차이가 중요한 이유:

| 스토어 | 노드 간 공유 | 무효화 전파 |
|---|---|---|
| `database` | ✅ 공유 | `forget` 1회로 전 노드 즉시 반영 |
| `file` | ❌ 노드별 로컬 | 저장한 노드만 즉시 반영. **다른 노드는 최대 TTL(300초)만큼 옛 값** |

`file`이면 웹 서버가 2대 이상일 때 노드마다 다른 쿨타임으로 판정할 수 있어요.
**단일 서버가 아니라면 `file`을 쓰면 안 돼요.** 어느 쪽이 맞는지 확인이 필요해요.

### 6-3. 캐시 미스 비용

미스 1회 = `app_settings` SELECT 2행(복호화 2회) + `farm_crops` SELECT 6행.
TTL 300초면 **노드당 5분에 1회**예요. 하루 100만 참여 규모에서도 무시 가능해요.

### 6-4. farm 런타임이 `config()`를 쓰지 않는 이유 (D3)

`SettingsServiceProvider::boot()`는 **매 HTTP 요청마다** `AppSetting::map()` → `static::all()`로
전 행을 SELECT하고 행마다 복호화해요 (`app/Providers/SettingsServiceProvider.php:19-136` → `AppSetting.php:33-40`).
캐시가 전혀 없고, `config:cache` 상태에서도 이 비용은 사라지지 않아요.

현재 키가 40여 개인데, farm이 붙으면 **rankfree 최대 트래픽 경로**(design-02 추정 `GET /missions` 피크 438 QPS)가
그 위에 얹혀요. farm 설정을 provider의 오버라이드 루프에 넣으면 그 비용을 정당화하는 모양이 돼요.

그래서 farm은 provider를 타지 않고 `FarmSettings` 캐시만 읽어요. 부수 효과:

- provider의 단일 값 오버라이드 루프는 값을 **문자열로만** 넣어요(`config([$cfg => $v])`).
  int 키를 그냥 얹으면 타입이 string이 되는 함정도 자연스럽게 피해요.
- 대신 rankfree 관례(설정 = `config()` 경유)에서 **의도적으로 벗어나요.** 이유를 코드 주석에 남겨야 해요.

> 별건 제안(이 문서 범위 밖): `AppSetting::map()` 자체에 60초 캐시 + `write()` 시 `forget`을 걸면
> farm 없이도 rankfree 전체가 이득이에요. 다만 전역 변경이라 **사업자 결정 + 별도 작업**으로 분리해요.

---

## 7. 설정 변경이 진행 중인 데이터에 미치는 영향

원칙: **이미 시작된 것은 시작 당시의 계약대로 끝내요. 새 값은 다음 것부터 적용해요.**

### 7-1. 쿨타임 변경

`farm_users.cooldown_until`은 참여 시점에 **절대 시각**으로 저장돼요(`now() + cooldown_minutes`).
따라서 설정을 바꿔도 **이미 발급된 대기 시각은 그대로**예요. 구조적으로 소급이 일어나지 않아요.

| 변경 | 진행 중인 건 | 다음 참여부터 |
|---|---|---|
| 단축 (120 → 90) | 그대로 120분 뒤에 열려요 | 90분 |
| 연장 (120 → 180) | 그대로 120분 뒤에 열려요 | 180분 |

**소급 적용은 하지 않아요.** 이유:

1. 사용자가 화면에서 이미 "오후 3:24에 열려요"를 봤어요. 그 약속을 깨면 신뢰가 깨져요.
2. 쿨타임 종료 알림 job이 `->delay($nextMissionAt)` 절대 시각으로 예약돼 있어요
   (`server-api-spec.md` §2-3). 시각을 당기면 **알림보다 먼저 열리거나 알림이 헛발**이 돼요.
3. 되돌릴 수 없어요 — 단축 소급 UPDATE를 돌린 뒤 되돌리려면 원래 값을 복원할 방법이 없어요.

A/B 테스트로 즉시 반영이 꼭 필요하다면(예: DAU 미달로 120→90 긴급 조정),
`UPDATE farm_users SET cooldown_until = DATE_SUB(cooldown_until, INTERVAL 30 MINUTE) WHERE cooldown_until > NOW()` 같은
1회성 배치가 기술적으로는 가능해요. **하지만 위 2번 때문에 권장하지 않아요.**
새 값이 자연히 퍼지는 데 걸리는 시간은 최대 옛 쿨타임 1회분(2시간)이에요.

### 7-2. 하루 참여 횟수 변경

`farm_users.today_count`는 이미 쌓인 값이고, 판정은 원자 UPDATE의 `today_count < :daily_limit`이 해요.
**별도 처리 없이 다음 참여부터 새 값이 적용돼요.**

| 변경 | 오늘 이미 참여한 사용자 | 처리 |
|---|---|---|
| 축소 (3 → 2) | 이미 3회 한 사람 | 남은 횟수 0. **이미 지급한 보상은 회수하지 않아요** |
| 축소 (3 → 2) | 1회 한 사람 | 남은 1회 |
| 확대 (3 → 5) | 3회 한 사람 | 즉시 2회 더 가능 |

**주의 1 — 축소는 진행 중인 작물을 늦춰요.**
참여 1회 = 밭 1칸 하루치예요. 밭이 3칸인데 하루 횟수를 2로 내리면
**하루에 3칸을 다 못 키워요.** 7일 코스가 늘어지고, 이탈률과 미회수 부채가 같이 올라가요.
이미 심어진 작물이 있는 동안 `daily_mission_limit < plot_count`로 내리는 건 신중해야 해요. (검증 W2)

**주의 2 — 확대는 쿨타임에 막혀요.**
5회를 하려면 `(5−1) × 120분 = 480분`이 필요해요. 농장일 활동 창은 06:00~02:00(20시간=1200분)이라
아직 여유가 있지만, 쿨타임을 같이 올리면 금방 불가능해져요. (검증 W3)
심야 02:00~06:00은 노출 휴지 구간이에요 (`design-02-runtime.md:791`).

**주의 3 — 예약된 알림은 자동으로 맞춰져요.**
쿨타임 종료 알림은 발송 직전에 "오늘 참여 횟수가 남아 있는지"를 다시 확인해요.
횟수를 낮추면 대상에서 자동으로 빠져요. 추가 작업 없어요.

### 7-3. 작물별 수확 포인트 변경 — ⚠ 문서 충돌 있어요

**두 문서가 정반대예요.**

| 문서 | 서술 |
|---|---|
| `server-api-spec.md:91` | "**수확 시점의 값**으로 지급합니다(심은 시점 아님). 밭 카드에 항상 현재 금액이 보이므로 표시와 지급이 일치합니다" |
| `design-01-schema.md:922` · `design-03-billing.md:136-139` · `rankfree-integration.md:391,757` | "심을 때 `farm_crops.points` → `farm_plantings.reward_points`로 **스냅샷**. 수확 보너스를 나중에 올려도 **진행 중 작물엔 소급 안 함**" |

**결정: 스냅샷(백엔드 확정판)을 채택해요.** 근거 3가지.

1. **부채 회계가 스냅샷 위에 서 있어요.** `farm_liability_snapshots`의 `gross_krw`·`expected_krw`가
   `SUM(farm_plantings.reward_points)`로 계산돼요(`design-03-billing.md:204-205`).
   수확 시점 값으로 바꾸면 어제 찍은 부채 스냅샷과 실제 지급액이 어긋나 **정산이 재현되지 않아요.**
2. **인하 소급은 민원이 돼요.** "심을 때 500P라고 봤는데 300P만 받았어요"는 방어할 논리가 없어요.
3. **인상 소급은 부채가 예고 없이 늘어요.** 진행 중인 작물 전체(활성 120만 건 규모)에 즉시 반영돼요.

**단, `server-api-spec.md`가 지적한 "표시와 지급의 불일치"는 진짜 문제예요.** 이렇게 해결해요.

> `GET /me/state`의 각 밭(plot)에 **스냅샷된 금액 `rewardPoints`를 포함**해요.
> 클라이언트는 **심어진 밭에는 `plot.rewardPoints`를, 빈 밭·작물 선택 화면에는 `/config.pointsByCrop`을** 써요.

이러면 "지금 키우는 이 작물은 500P(심을 때 금액), 새로 심으면 300P"가 화면에 정확히 드러나요.
`/config.pointsByCrop`은 **앞으로 심을 작물의 안내가**라는 의미로 정리돼요.

**액션 아이템**
- `server-api-spec.md:84-91`의 영향 표에서 "지급 포인트 변경" 행을 **스냅샷 기준으로 수정**해야 해요.
- `GET /me/state` 응답에 `plots[].rewardPoints` 추가가 필요해요(현재 계약에 없어요).
- 컬럼명은 **`reward_points`로 확정**돼 있어요 (`rankfree-integration.md:757` — C27이
  design-01의 `expected_crop_points` 대신 design-03의 `reward_points`를 채택).
  design-01 §2-8의 `expected_crop_points` 표기는 폐기 대상이에요.

| 변경 | 진행 중인 작물 | 새로 심는 작물 |
|---|---|---|
| 인상 (500 → 800) | 500P (스냅샷) | 800P |
| 인하 (500 → 300) | 500P (스냅샷) | 300P |
| 0으로 설정 | 500P (스냅샷) | 0P — 수확해도 포인트 없음. 아이템·완주 UX는 유지 |

### 7-4. 5,000P 상한에 이미 닿은 사용자

포인트를 인상해도 상한은 그대로예요. 수확 시 `min(crop_points, cap − accrued)`로 **부분 지급**되고,
남은 금액이 0이면 `{ok:true, points:0, message:'누적 포인트 한도에…'}`가 나가요.
설정 변경으로 이 동작이 달라지지 않아요.

### 7-5. 캐시 전파 중의 불일치

TTL 300초 안에는 캐시가 옛 값을 들고 있을 수 있어요. 이때도 **표시(`/config`)와 판정(원자 UPDATE)이
같은 `FarmSettings`를 보므로 서로 어긋나지 않아요.** 저장 시 `forget`이 즉시 무효화하니
실제 노출 시간은 거의 0이에요 — 단, **캐시 스토어가 `file`이고 서버가 여러 대면 노드별로 최대 300초 어긋나요**(§6-2).

### 7-6. 요약표

| 변경 | 진행 중인 데이터 | 추가 배치 | 사용자 체감 |
|---|---|---|---|
| 쿨타임 | 영향 없음 (절대 시각 저장) | 불필요 | 다음 참여부터 |
| 하루 횟수 축소 | 남은 횟수만 줄어듦. 보상 회수 없음 | 불필요 | 즉시 |
| 하루 횟수 확대 | 즉시 사용 가능 | 불필요 | 즉시 (쿨타임 범위 안에서) |
| 작물 포인트 | 영향 없음 (심을 때 스냅샷) | 불필요 | 다음에 심는 작물부터 |

---

## 8. 마이그레이션

### 8-1. `app_settings` — **마이그레이션 불필요**

행만 추가돼요. 새 컬럼도, 새 테이블도 없어요.
키 길이는 `farm.daily_mission_limit` = 24자로 80자 상한 안이에요.

**초기 시딩도 불필요해요.** 행이 없으면 `AppSetting::read()`가 `null`을 반환하고
`FarmSettings::load()`가 `?:`로 `config` 폴백을 써요. 운영자가 처음 저장할 때 행이 생겨요.

### 8-2. `farm_crops` — 선행 마이그레이션 필요 (이 문서 범위 밖)

`farm_crops` 테이블은 `design-01-schema.md:239-256`에 설계돼 있지만 **아직 구현 전이에요.**
이 문서의 작물 포인트 편집은 그 테이블에 의존해요.

`FarmCropSeeder`에서 **`points` 초기값을 반드시 넣어야 해요.** 컬럼 기본값이 `0`이라
시더가 값을 안 넣으면 6종 전부 0P가 돼요. 초기값은 §1-2 "클라 폴백값"과 맞추는 걸 권장하지만,
**실제 금액은 아직 미정이에요** (설계 문서는 500P 가정, 폴백은 50P — 확인 필요).

### 8-3. `farm_plantings.reward_points` — 컬럼 추가 필요

`design-03-billing.md:139`가 명시해요: "`farm_plantings`에 `reward_points unsignedInteger` 컬럼을
**추가해야 한다.** 초안에는 `required_days`만 스냅샷돼 있다."
§7-3의 스냅샷 정책이 이 컬럼 위에서 동작해요.

### 8-4. 캐시 테이블

`CACHE_STORE=database`면 `cache` 테이블이 이미 있어요. 추가 마이그레이션 없어요.

---

## 9. 확인 필요 목록

| # | 항목 | 왜 필요한가 |
|---|---|---|
| Q1 | **캐시 스토어가 `file`인가 `database`인가** | rankfree `.env:40`은 `database`인데 요구사항에는 `file`로 적혀 있어요. `file` + 다중 서버면 노드별로 판정이 갈려요 (§6-2) |
| Q2 | **작물별 수확 포인트 실제 금액** | 설계 문서는 500P 가정, config 폴백은 50P — 10배 차이예요 |
| Q3 | **참여 1회당 적립 포인트(`farm_missions.payout_point`) 실제 금액** | 5,000P 교차검증(B2)의 핵심 입력값이에요. `server-api-spec.md`의 예시 300P를 쓰면 저장이 거부돼요 (§5-2) |
| Q4 | **`server-api-spec.md`의 "수확 시점 지급" 서술 수정 승인** | 백엔드 확정판과 정면 충돌해요. 스냅샷으로 통일해야 정산이 재현돼요 (§7-3) |
| Q5 | **`GET /me/state`에 `plots[].rewardPoints` 추가** | 스냅샷 채택 시 표시-지급 일치를 위해 필요해요. 현재 계약에 없어요 |
| Q6 | **`GET /config` 호출량 실측** | `Cache-Control: max-age` 값을 정하려면 필요해요. 현재는 추정치예요 |
| Q7 | **환경설정 화면 진입 메뉴 노출** | settings 화면 링크가 `admin/partials`·`layout.blade.php` grep에 안 잡혀요(DB 기반 메뉴로 추정). 새 탭은 URL로만 접근돼요 |

---

## 10. 테스트

rankfree 관례는 `tests/Feature/SettingsTest.php`의 `reboot()`(provider 수동 재부팅)인데,
farm은 provider를 타지 않으므로 **`FarmSettings::forget()` 기준**으로 검증해요.

```php
// tests/Feature/FarmSettingsTest.php
public function test_저장하면_config_응답이_바뀐다(): void
{
    $this->actingAs($this->operator())->put('/admin/settings', [
        'farm_cooldown_minutes' => 90,
        'farm_daily_mission_limit' => 2,
        'farm_crop_points' => ['lettuce' => 120],
        // …기존 폼 필드 (SIMPLE 25개가 '' 로 덮이지 않게 전부 포함해야 한다)
    ])->assertRedirect();

    $this->getJson('/api/farm/config')
        ->assertOk()
        ->assertJsonPath('cooldownMinutes', 90)
        ->assertJsonPath('dailyMissionLimit', 2)
        ->assertJsonPath('pointsByCrop.lettuce', 120);
}

public function test_상한을_넘는_작물_포인트는_거부된다(): void   // B1
public function test_참여적립만으로_상한을_채우면_거부된다(): void  // B2
public function test_저장하면_캐시가_무효화된다(): void            // forget 확인
public function test_설정값은_암호화되어_저장된다(): void          // DB 원문에 '90' 이 안 보여야 한다
public function test_farm_필드가_없는_PUT은_기존값을_지우지_않는다(): void  // has() 가드
```

> ⚠ **테스트 작성 시 함정** — `PUT /admin/settings`는 `SIMPLE` 25개 키를
> 요청 유무와 무관하게 `''`로 덮어써요. farm 필드만 담은 PUT을 보내면
> **다른 설정이 전부 빈 값으로 날아가요**(기존 `SettingsTest`의 PUT들이 실제로 그렇게 동작해요).
> farm 테스트는 이 부작용을 감안하고 짜야 해요.

---

## 11. 구현 순서

| 단계 | 내용 | 검증 |
|---|---|---|
| 1 | `config/rankfree.php`에 `farm` 블록 신설 | `php83 artisan config:cache` 후 `config('rankfree.farm.cooldown_minutes') === 120` |
| 2 | `app/Domain/Farm/FarmSettings.php` 작성 | `FarmSettings::all()`이 테이블 없이도 폴백을 반환하는 테스트 통과 |
| 3 | `FarmConfigController` + `routes/farm.php` 1줄 | `GET /api/farm/config`가 인증 없이 200, 필드 5개 전부 존재 |
| 4 | `SettingsController::index()` 뷰 데이터 + blade `farm` 탭 | 화면에 값이 보이고 저장 후 `?tab=farm`으로 돌아옴 |
| 5 | `saveFarmSettings()` + 검증 + `FarmSettings::forget()` | B1·B2 거부 테스트 통과, 저장 후 `/config`가 즉시 바뀜 |
| 6 | (선행 필요) `farm_crops` 마이그레이션 + `FarmCropSeeder` `points` 초기값 | 작물 6종이 편집칸에 뜨고 `pointsByCrop`에 6개가 내려감 |
| 7 | (선행 필요) `farm_plantings.reward_points` 컬럼 + 심을 때 스냅샷 | 포인트를 바꿔도 진행 중 작물의 지급액이 안 변함 |
| 8 | 클라이언트 `.env`에 `VITE_API_BASE_URL=https://<도메인>/api/farm` | 미니앱이 `DEFAULT_CONFIG`가 아닌 서버 값을 표시 |

6·7번은 이 문서 범위 밖(스키마 구현)이지만 **작물 포인트 기능이 동작하려면 반드시 선행**돼야 해요.
1~5번은 `farm_crops` 없이도 독립적으로 배포 가능하고, 그 상태에서는 쿨타임·하루 횟수만 동작해요.
