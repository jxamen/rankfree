# 30. 크롬 확장 — 공개 배포본 분리

> 웹스토어 심사를 통과할 수 있는 **공개 배포본**을 작업용 확장에서 생성해 내는 구조.
> 2026-08-10 적용(v0.4.0).

## 왜 나눴나

게시가 계속 막혀 원인을 추적했더니 웹스토어 API 가 이렇게 답했다.

```
HTTP 400 — Publish condition not met:
you must provide mandatory privacy information ... Privacy practices tab
```

권한에 `alarms` 가 추가돼 권한 사유를 다시 받는 상황이었는데, 그 사유를 정직하게 쓰려고
코드를 감사한 결과 **신고 이전에 정책 위반 소지가 있는 기능 두 개**가 나왔다.

| 기능 | 문제 |
|---|---|
| 서버 배정 크롤 워커 (`drainShopRankQueue` 등) | 로그인·사용자 조작 없이 1분마다 서버에 롱폴링해 작업을 받고, 숨김 탭으로 네이버를 **사용자 IP·세션으로** 크롤 → 표시된 단일 목적과 무관한 미신고 백그라운드 기능 |
| 캡차 자동 풀이 (`seller-info-captcha*.js`) | 캡차 이미지를 서버로 보내 정답을 받아 자동 입력·제출 → 봇 방지 우회 |

런타임 권한 게이팅(슈퍼관리자만 서버가 응답)으로는 부족하다. **코드가 패키지에 실려 있으면
리뷰어가 읽는다.**

## 결정

- **캡차 자동 풀이는 완전 삭제** — 작업본에도 남기지 않는다.
- **크롤 워커·대량 수집은 공개본에서만 제외** — 작업본에는 그대로 둔다.

## 구조 — 방향이 중요하다

```
extension/                  ← 작업본. 평소 크롬에 로드해 쓰는 폴더(전부 동작)
  └ internal/internal.js        워커 + 대량수집 + SERP 대행 핸들러 + 알람/부트 훅
extension-public/           ← 공개 배포본. 생성물이지만 **git 에 커밋한다**
```

**공개본을 생성물로 뺀 이유**: `extension/` 은 운영자가 매일 로드해 쓰는 폴더다.
여기를 반쪽짜리로 만들면 관리자 화면 브릿지·대량수집·상품정보 원격수집이 전부 죽어
일상 업무가 멈춘다. 처음엔 반대로(=`extension/` 을 공개본으로) 만들었다가 그 문제로 되돌렸다.

**`extension-public/` 을 커밋하는 이유**: 관리자 화면의 **[웹스토어에 게시]** 버튼
([CwsPublisher.php](../app/Support/CwsPublisher.php))은 **운영 서버에서** 도는데 거기엔 node 가
없다. 서버에서 빌드할 수 없으니 결과물을 커밋해 두고 그대로 압축한다.

## 빌드

```bash
node scripts/build-extension-public.mjs
```

하는 일: `extension/` 복사 → `internal/` 디렉터리·브릿지 2개·`.md` 제외 →
`background.js` 끝의 오버레이 로드 블록 제거 → manifest 에서 `alarms`·로컬호스트·
m.search/s.search·브릿지 항목 제거 → **유출 검사**.

### 🔴 게시 전에 반드시 다시 빌드

`extension/` 을 고쳐 놓고 공개본을 다시 만들지 않으면 **낡은 코드가 게시된다.**
그래서 게시 경로 두 곳 모두 가드를 넣었다(실동작 검증 완료).

| 가드 | 동작 |
|---|---|
| 버전 불일치 | `extension` 과 `extension-public` 의 manifest 버전이 다르면 게시 거부 |
| 유출 검사 | 공개본 JS 에 `drainShopRankQueue`·`bulkShopStart`·`collectShopSerp`·`solveQuiz`·`sellerCaptcha`·`rfWorkerId`·`chrome.alarms` 가 있으면 게시 거부 |

두 게시 경로: [scripts/publish-extension.mjs](../scripts/publish-extension.mjs)(로컬 CLI) ·
[app/Support/CwsPublisher.php](../app/Support/CwsPublisher.php)(관리자 버튼, 운영 서버).

### 🔴 importScripts 는 반드시 파일 끝

`background.js` 의 `const handlers` 는 TDZ 다. 선언 이전에 `importScripts` 를 부르면
`ReferenceError` 로 서비스워커가 통째로 죽는다. 오버레이도 `Object.assign(handlers, {...})` 로
핸들러를 덧붙이고, 알람·부트 훅은 **그 뒤**에 둔다(첫 알람이 빈 handlers 를 만나지 않게).

`manifest.json` 에 `"type": "module"` 이 없어 classic worker 다 → `importScripts` 가 쓸 수 있다.
모듈로 바꾸면 이 구조가 깨진다.

공개본 빌드는 `background.js` 끝의 `\n\n// ── 사내 전용 오버레이 ` 마커부터 잘라낸다.
이 주석 문구를 바꾸면 빌드가 실패한다(찾지 못하면 명시적으로 에러).

## 공개본에 남은 것 / 빠진 것

| | extension/ (작업본) | extension-public/ |
|---|---|---|
| 시장분석·상품분석·리뷰분석·셀러력·플레이스 배지·순위체크 패널·로그인 | ✅ | ✅ |
| **상품정보 자동 수집**(`collectProductInfo` → `saveProductInfo`) | ✅ | ✅ |
| 백그라운드 순위 워커 / 키워드 큐 워커 | ✅ | ❌ |
| 대량 수집(`bulkShopStart`) + 워치독 | ✅ | ❌ |
| 관리자·콘솔 브릿지(`collectProductPage` 원격 트리거 포함) | ✅ | ❌ |
| 캡차 자동 풀이 · 판매자정보 수집 | ❌ | ❌ (삭제) |
| `alarms` 권한 | ✅ | ❌ |

공개본은 권한이 `storage`·`tabs` 로 줄어 **신규 권한 추가가 없다** — 웹스토어
"alarms 사용 근거" 칸 자체가 없어진다. host_permissions 12 → 8개, content_scripts 9 → 7개.

### 주문 경로는 공개본으로도 동작한다

주문 자동채움([OrderFieldAutofill](../app/Domain/Order/OrderFieldAutofill.php))이 쓰는 값 중
확장 수집이 **유일한 소스인 것은 `seller_tags`·`thumbnail_url` 둘뿐**이고, 이를 만드는
`collectProductInfo()`(상품 페이지 진입 시 자동 실행)는 **공개본에도 그대로 있다**.

- 상품정보가 없어도 **주문 접수는 실패하지 않는다** — 숨김 필드는 검증을 건너뛴다
  ([OrderPlacer.php:37-43](../app/Domain/Order/OrderPlacer.php)). 막히는 건 발주·승인 단계이고
  관리자가 주문 상세에서 직접 입력해 우회할 수 있다.
- 캡차로 모으던 **사업자정보(`seller_infos`)는 주문·발주에서 전혀 쓰지 않는다** — 삭제 영향 없음.
- 공개본에서 빠진 건 관리자 화면이 확장에 원격으로 수집을 시키는 `collectProductPage` 뿐이고,
  이건 운영자 편의 기능이라 작업본을 쓰는 운영자에겐 영향이 없다.

### 남겨야 하는 것 (지우면 공개 기능이 깨진다)

- `handlers.collectShopping` — 워커도 쓰지만 **사용자 패널**이 쓴다
- `handlers.sellerCollectDetail` — **셀러력**(`product.js`)이 쓴다
- `productInfoWaiters` — `saveProductInfo` 가 참조(공개본에선 빈 Map 로 no-op)
- `removeTab` — 존치 핸들러 4곳이 쓴다

## 부수 변경

- `resources/views/admin/shop-products.blade.php` — 판매자정보 **수집** UI·스크립트 제거
  (캡차가 없어 동작 불가, 게다가 `callExt` 에 타임아웃이 없어 버튼이 영구히 잠겼다).
  이미 수집된 정보의 **열람**은 그대로 유지.
- `scripts/publish-extension.mjs` — 공개 zip 에서 `.md` 제외(README 가 그대로 들어가고 있었다).

## 남은 판단거리

- **서버 `/api/ext/quiz/solve`**(`ExtQuizController` + `QuizSolver`)가 고아가 됐다.
  확장이 유일한 소비자였다. 인증이 걸려 있지만 캡차 풀이 엔드포인트를 살려 둘 이유가 없다면 제거 검토.
- **상품 페이지 자동 수집** — `collectProductInfo()` 가 스마트스토어 상품 페이지 **진입만으로**
  실행된다. "웹 기록 수집 아님"으로 신고하기엔 회색지대다. 사용자 실행형 전환 검토.
- `content/injected.js`·`injected-store.js` 의 `fetch`/`XHR` 후킹은 남아 있다(네이버 검색·리뷰
  API 한정). 심사에서 "네트워크 모니터링" 으로 볼 여지가 있다.

## 검증 (2026-08-10, Playwright 실제 크롬)

| 항목 | extension/ | extension-public/ |
|---|---|---|
| `chrome.alarms` API | ✅ | ❌ (권한 없음) |
| `drainShopRankQueue` | ✅ | ❌ |
| `handlers.bulkShopStart` | ✅ | ❌ |
| `handlers.collectProductPage` | ✅ | ❌ |
| `handlers.solveQuiz` | ❌ | ❌ |
| `saveProductInfo`·`collectShopping`·`shopRankCheck`·`sellerCollectDetail` | ✅ | ✅ |
| 브릿지 content_scripts | 2개 | 0개 |
| 네이버 쇼핑 검색 페이지 패널(FAB) | ✅ | ✅ |

게시 가드도 실제로 돌려 확인했다 — 사내 심볼을 심으면 거부, 버전이 어긋나면 거부.
