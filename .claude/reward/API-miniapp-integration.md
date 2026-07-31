# 리워드 미니앱 연동 가이드 (퀴즈농장)

> 퀴즈농장 앱이 rankfree 리워드 백엔드에 연동할 때 보는 문서. **모든 예제는 실제 서버 응답을 그대로 옮긴 것**이다(2026-07-31 캡처).
> 설계 배경은 [design-04-vendor-api.md](./design-04-vendor-api.md)·[server-api-spec.md](./server-api-spec.md), 구현 진입점은 [HANDOFF-rankfree.md](./HANDOFF-rankfree.md).

- **베이스 URL**: `https://rankfree.kr/api/farm` — ⚠️ **아직 운영 배포 전이다**(2026-07-31 기준 로컬만).
  현재 연동 테스트는 로컬 `http://127.0.0.1:8000/api/farm` 에서 한다
- **매체 슬러그**: `quiz-farm` (라우트에 고정 — 매체가 비활성이면 전 엔드포인트 503)
- **인코딩**: 요청·응답 모두 UTF-8 JSON. 시각은 ISO-8601 (KST, `+09:00`)

---

## 1. 인증 — `x-user-key` 헤더

쿠키 세션을 쓰지 않는다(iOS 서드파티 쿠키 차단). **모든 요청에 `x-user-key` 헤더**를 넣는다.

```
x-user-key: <앱이 만든 사용자 고유 문자열>
```

- 값은 앱이 정한다(설치 UUID, 토스 사용자 식별자 등). 서버는 **sha256 해시로만 저장**하고 평문은 지급 재시도용으로 암호화 보관한다.
- 같은 키 = 같은 사용자. **키가 바뀌면 다른 사용자**가 되어 참여 이력·쿨다운·포인트가 초기화된다 → 기기 재설치에도 유지되는 값을 써야 한다.
- 키를 빼먹으면 `401 {"message":"unauthorized"}`.

**신규 사용자 생성은 IP 시간당 예산이 있다** (기본 60명/시간/IP). 초과 시 `429 {"message":"too many requests"}` — 정상 사용자는 걸리지 않지만, 테스트에서 키를 대량 생성하면 만난다. 이미 존재하는 사용자는 예산과 무관하다.

---

## 2. 참여 흐름

```
① POST /missions/assign      "미션 참여하기" → 미션 1건 받기
② (앱) quiz.hintUrl 열기      상품 페이지에서 해시태그 확인
③ POST /plots/{index}/plant  밭이 비어 있으면 작물 심기 (참여 전 1회)
④ POST /missions/{id}/submit 정답 제출 → 채점·확정
⑤ GET  /me/state             밭·포인트·쿨다운 갱신
```

> ③ 심기는 **참여의 전제 조건**이다. 자라는 밭이 없으면 ④가 `"먼저 밭에 작물을 심어 주세요."`로 거절된다.

---

## 3. 엔드포인트

| 메서드 | 경로 | 용도 | 제한 |
|---|---|---|---|
| GET | `/config` | 앱 설정값(쿨다운·일 한도·작물 포인트) | — |
| POST | `/missions/assign` | **미션 단건 할당** (기본 방식) | 60/분 |
| GET | `/missions` | 미션 목록 (구버전 호환) | — |
| POST | `/missions/{id}/submit` | 정답 제출·채점 | 30/분 |
| POST | `/plots/{index}/plant` | 작물 심기 (`index` 0~2) | — |
| GET | `/me/state` | 내 농장 상태 | — |
| POST | `/me/notifications/cooldown` | 쿨타임 알림 on/off | — |

### 3-1. `GET /config`

앱 시작 시 1회. 값은 **매체별 설정**이라 서버에서 바뀔 수 있으니 하드코딩하지 않는다.

```json
{
  "cooldownMinutes": 120,
  "dailyMissionLimit": 3,
  "pointsByCrop": { "lettuce": 50 },
  "defaultPoints": 50,
  "maxPointPerUser": 5000
}
```

`maxPointPerUser`는 토스 프로모션 정책 한도다. `pointsByCrop`에 없는 작물은 `defaultPoints`로 표시한다.

### 3-2. `POST /missions/assign` — 미션 단건 할당 ★

"미션 참여하기"를 누를 때마다 호출한다. 서버가 참여 가능한 미션 하나를 골라 준다. **요청 본문 없음.**

**할당됨 (200)**

```json
{
  "mission": {
    "id": "2",
    "kind": "external",
    "title": "두둘리앙 원목 장롱 수납장 최저가 찾기",
    "description": "원목장롱 검색 결과에서 두둘리앙 상품 가격을 확인하고 오면 water 1개를 받아요.",
    "reward": { "item": "water", "count": 1 },
    "points": 0,
    "quiz": {
      "product": { "name": "원목 장롱 수납장", "imageEmoji": null, "imageUrl": null, "price": 129000 },
      "guide": [
        "아래 '참여하기'를 누르면 상품 페이지가 열려요.",
        "상품 정보에 있는 해시태그 중 3번째 태그를 확인해 주세요.",
        "다시 돌아와서 입력하면 돼요. #은 빼고 적어도 괜찮아요."
      ],
      "hintUrl": "https://s.example/quizfarm-1",
      "question": "3번째 해시태그를 입력해 주세요",
      "placeholder": null,
      "tagIndex": 3,
      "tagCount": 3
    },
    "completed": false,
    "locked": false
  },
  "meta": { "remaining": 3, "dailyLimit": 3, "slot": 3, "closed": false }
}
```

**줄 미션이 없음 (200 — 오류가 아니다)**

```json
{
  "mission": null,
  "meta": {
    "reason": "cooldown",
    "remaining": 2, "dailyLimit": 3,
    "unlockAt": "2026-07-31T18:58:19+09:00",
    "cooldownUntil": "2026-07-31T18:58:19+09:00",
    "slot": 3, "closed": false
  }
}
```

| `meta.reason` | 뜻 | 화면 |
|---|---|---|
| `cooldown` | 쿨다운 중 (`unlockAt` 동봉) | "다음 미션은 HH:MM에 열려요" |
| `daily_limit` | 오늘 참여를 다 채움 (`remaining: 0`) | "오늘 참여를 모두 마쳤어요" |
| `closed` | 심야 휴지 02~06시 (`opensAt` 동봉, `closed: true`) | "미션은 아침 6시에 열려요" |
| `no_mission` | 줄 미션이 없음 | "남은 미션이 없어요" |

> `no_mission`은 **재고 소진과 이용 제한을 구분하지 않는다**(의도된 설계). 제한 사유를 노출하면 어뷰저가 판정을 역이용한다.

**필드 설명**

| 필드 | 설명 |
|---|---|
| `id` | 미션 ID **문자열**. 제출 시 그대로 사용 |
| `points` | 참여 시 적립 포인트. **현재 0이면 서버에 지급 포인트가 아직 설정되지 않은 것** |
| `quiz.hintUrl` | 참여하기 버튼이 열 상품 페이지 URL |
| `quiz.tagIndex` | **몇 번째 해시태그**를 묻는지 (1부터). **사용자마다 다른 값** — 화면 문구와 채점이 이 값으로 맞춰진다 |
| `quiz.tagCount` | 상품 해시태그 개수 |
| `quiz.guide` / `question` | 서버가 `tagIndex`를 반영해 만든 문구. 그대로 쓰면 된다 |
| `completed`·`locked` | 단건 할당에서는 항상 `false`(구버전 목록 UI 호환 필드) |

> 🔒 **정답과 태그 목록은 어떤 응답에도 없다.** `tagIndex`/`tagCount`만 내려간다. 채점은 서버가 한다.

> ⚠️ **할당은 예약이 아니다.** 받은 미션이 제출 시점엔 다른 사용자가 소진했을 수 있고, 그때 제출은 `"방금 마감됐어요. 다른 미션을 해보세요."`로 거절된다. 정상 동작이니 재할당을 안내하면 된다.

### 3-3. `POST /missions/{id}/submit` — 정답 제출

```json
// 요청
{ "answer": "수납장추천" }
```

`answer`는 필수, 최대 200자. 서버가 정규화(앞뒤 공백 → 맨 앞 `#` → 모든 공백 제거 → 소문자)해서 비교하므로 `#태그`, `태그`, 대소문자 차이는 모두 정답 처리된다.

**정답 (200)**

```json
{
  "correct": true,
  "reward": { "item": "water", "count": 1 },
  "points": 0,
  "nextMissionAt": "2026-07-31T18:58:19+09:00"
}
```

**오답·거절 (200 — HTTP 오류가 아니다)**

```json
{ "correct": false, "message": "다시 한 번 확인해 주세요." }
```

거절은 전부 `200 {correct:false, message}` 형태이고 **`message`를 그대로 보여주면 된다.** 쿨다운 거절일 때만 `nextMissionAt`이 함께 온다.

| `message` | 상황 |
|---|---|
| `다시 한 번 확인해 주세요.` | 오답 |
| `먼저 밭에 작물을 심어 주세요.` | 자라는 밭 없음 → 심기 유도 |
| `오늘은 모든 밭을 돌봤어요.` | 오늘 모든 밭을 이미 돌봄 |
| `이 밭은 오늘 이미 돌봤어요.` | 동시 제출 경합으로 밭이 방금 채워짐 |
| `다음 미션은 HH:MM에 열려요.` | 쿨다운 중 (`nextMissionAt` 동봉) |
| `다음 미션은 잠시 후에 열려요.` | 쿨다운 (동시 제출 경합으로 확정 직전에 걸린 경우) |
| `오늘 참여를 모두 마쳤어요.` | 일 참여 한도 소진 |
| `이미 참여한 미션이에요.` | 그 미션 참여 상한 |
| `방금 마감됐어요. 다른 미션을 해보세요.` | 미션 수량 소진 → 재할당 안내 |
| `종료된 미션이에요.` | 미션 종료·비활성 |
| `미션은 아침 6시에 열려요.` | 심야 휴지(02~06시) |
| `잠시 후 다시 시도해 주세요.` | 제출 간격 3초 미만 / IP 식별자 한도 |
| `오늘은 더 시도할 수 없어요.` | 하루 시도 횟수 상한(오답·거절 포함) |
| `지금은 참여할 수 없어요.` | 이용 제한 상태 |
| `더 받을 수 있는 포인트가 없어요.` | 누적 포인트 상한 도달 |

> `correct: false`는 카운터를 소모하지 않는다(오답은 참여로 세지 않는다). 다만 **시도 횟수는 누적**되므로 무한 재시도는 `잠시 후 다시 시도해 주세요.`로 막힌다.

### 3-4. `POST /plots/{index}/plant` — 작물 심기

`index`는 `0`~`2`(밭 3칸). 요청 `{ "cropId": "lettuce" }`.

```json
{ "ok": true }                                        // 200
{ "ok": false, "message": "이미 작물이 자라고 있어요." }   // 422
{ "ok": false, "message": "선택할 수 없는 작물이에요." }   // 422
```

작물의 재배 일수와 수확 포인트는 **심는 순간 고정**된다(이후 서버 설정이 바뀌어도 소급하지 않는다).

### 3-5. `GET /me/state` — 내 농장 상태

```json
{
  "plots": [
    { "cropId": "lettuce", "completedDates": ["2026-07-31"], "lastTendedDate": "2026-07-31", "rewardPoints": 50 },
    { "cropId": null, "completedDates": [], "lastTendedDate": "" },
    { "cropId": null, "completedDates": [], "lastTendedDate": "" }
  ],
  "todayMissionIds": ["2"],
  "nextMissionAt": "2026-07-31T18:58:19+09:00",
  "cooldownNotify": false,
  "earnedPoints": 0,
  "harvested": []
}
```

- `plots`는 **밭 위치(0~2) 순서 고정 배열** — 비어 있으면 `cropId: null`
- `completedDates`는 그 밭을 돌본 날짜들. 길이가 작물 재배 일수에 도달하면 수확 가능
- `rewardPoints`는 **심은 시점에 고정된 수확 보너스** — 화면 표시와 실제 지급이 일치한다
- `earnedPoints`는 누적 적립(`maxPointPerUser` 대비 잔여 계산의 입력값)

### 3-6. `POST /me/notifications/cooldown`

`{ "enabled": true }` → `{ "cooldownNotify": true }`. 기기를 바꿔도 따라가야 하므로 **서버가 원장**이다.

### 3-7. `GET /missions` — 목록 (구버전 호환)

단건 할당 이전 방식. `missions[]` 배열 + `meta`를 준다. 신규 연동은 `assign`을 쓴다.

---

## 4. 반드시 지켜야 할 규칙

**정답을 클라이언트에서 검증하지 않는다.** 태그 목록이 앱에 내려가지 않으므로 애초에 불가능하다. 채점·한도·중복 판정은 전부 서버가 다시 한다.

**`tagIndex`는 사용자마다 다르다.** 캐싱해서 다른 사용자에게 재사용하면 안 된다. 같은 사용자·같은 미션·같은 날에는 항상 같은 값이라 화면을 다시 그려도 문제없다.

**시간대 분산이 있다.** 미션 수량은 하루에 걸쳐 시간대별로 열린다. 지금 줄 미션이 없어도(`no_mission`) 잠시 후 다시 열릴 수 있으니, 앱은 이를 "종료"가 아니라 "지금은 없음"으로 다뤄야 한다.

**심야(02:00~06:00)는 닫힌다.** `assign`이 `closed: true` + `opensAt`을 준다. 하루 경계는 자정이 아니라 **오전 6시**다.

**HTTP 상태코드**: 인증 실패 `401`, 매체 비활성 `503`, 검증 실패 `422`, 레이트 리밋 `429`. 그 외 미션 관련 거절은 전부 `200`이고 본문으로 판단한다.

---

## 5. 연동 체크리스트

- [ ] `x-user-key`가 앱 재설치·업데이트에도 유지되는 값인가
- [ ] `GET /config` 값을 하드코딩하지 않고 서버 값으로 쓰는가
- [ ] `assign`이 `mission: null`을 줄 때 `meta.reason`별 문구를 처리하는가
- [ ] 밭이 없을 때 심기로 유도하는가
- [ ] 제출 거절 시 `message`를 그대로 노출하는가
- [ ] `quota_full`(마감) 응답에서 재할당을 안내하는가
- [ ] 제출을 3초 이내 연타하지 않게 버튼을 잠그는가

---

## 6. 서버 쪽 미완료 항목 (연동 전 확인)

| 항목 | 상태 |
|---|---|
| 참여 적립 포인트(`points`) | 미션별 `payout_point` 미설정이면 0. 매체별 설정 화면(Phase 7)에서 입력 예정 |
| 수확·지급 (`POST /harvest`) | **미구현** (Phase 5) — 현재는 참여 적립까지만 동작 |
| 주간 랭킹·추천 앱 등 | server-api-spec의 나머지 절은 미구현 |
