# 30. 크롬 확장 — 웹스토어 게시와 심사 리스크

> 게시가 막힌 원인, 캡차 자동 풀이 제거, 그리고 **남겨 두기로 한 리스크와 그 근거**.
> 2026-08-10 (v0.4.0).

## 게시가 막혔던 진짜 이유

초안은 정상 업로드돼 있었는데(0.3.26) 게시본이 0.3.16 에 멈춰 있었다.
웹스토어 API 를 직접 호출해 사유를 받았다.

```
HTTP 400 — Publish condition not met: To publish your item, you must provide
mandatory privacy information ... Privacy practices tab
```

업로드가 아니라 **게시 조건** 문제였다. `alarms` 권한이 새로 추가되면서 구글이 권한 사유를
다시 요구했고, 대시보드의 **개인정보 보호 관행** 탭에서 `alarms` 칸이 비어 있었다.

> 관리자 [웹스토어에 게시] 버튼은 이 메시지를 그대로 보여준다
> ([CwsPublisher.php](../app/Support/CwsPublisher.php) `error.message` 노출).
> 게시가 안 되면 버튼을 눌러 나온 문구를 먼저 읽는다.

## 제거한 것 — 캡차 자동 풀이

권한 사유를 정직하게 쓰려고 코드를 감사하다 발견했다.

`seller-info-captcha.js` 가 판매자정보 팝업의 **캡차 이미지를 서버(`/api/ext/quiz/solve`)로
보내 정답을 받아 자동 입력·제출**하고 있었다. 오답이면 새 캡차로 최대 3회 재시도까지 했다.
봇 방지 우회는 심사에서 반려가 아니라 **항목 삭제·개발자 계정 제재**로 갈 수 있다.

슈퍼관리자 토큰이 없으면 서버가 403 을 주도록 게이팅돼 있었지만, **코드가 패키지에 실려
있으면 리뷰어가 읽는다.** 런타임 게이팅으로는 부족해 소스에서 삭제했다.

| 삭제 대상 | 위치 |
|---|---|
| 콘텐츠 스크립트 2개 | `content/seller-info-captcha.js`, `seller-info-captcha-injected.js` |
| manifest content_scripts 2개 | `shopping.naver.com/popup/seller-info/*` |
| background 핸들러 | `openSellerInfoCaptcha`·`sellerCaptchaStart/Status/Stop`·`solveQuiz`·`isSellerCaptchaTab`·`closeSellerTab`·`saveSellerInfo` |
| 부속 | `sellerCaptchaTabs`·`getQuizConfig`·`sellerInfoPopupUrl`·`storeIdFromProductUrl`·`removeWindow` |
| 브릿지 라우트 | `admin-bridge.js` 의 `collectSellerCaptchas`·`sellerCaptcha*` |
| host_permissions | `https://shopping.naver.com/*` (캡차 팝업 전용이었다) |

**재유입 방지**: [CwsPublisher.php](../app/Support/CwsPublisher.php) 가 게시 직전 패키지의 모든
JS 를 훑어 `solveQuiz`·`sellerCaptcha` 가 있으면 **게시를 중단**한다.

### 딸려 없어진 기능

관리자 **수집 상품**(`/admin/shop-products`)의 **판매자정보 수집** UI. 캡차를 못 푸니
동작할 수 없다(게다가 `callExt` 에 타임아웃이 없어 버튼이 영구히 잠겼다).
이미 수집된 정보의 **열람**은 그대로 유지했다.

서버 `/api/ext/quiz/solve`(`ExtQuizController` + `QuizSolver`)는 소비자가 사라져 고아가 됐다.
남겨 둘 이유가 없으면 제거 검토.

## 남겨 두기로 한 것 — 판단 근거를 남긴다

감사에서 **또 하나의 정책 리스크**가 나왔다. 한때 이것도 공개본에서 빼려고 빌드를 둘로
나눴다가 **되돌렸다.**

### 리스크: 서버 배정 백그라운드 수집

`drainShopRankQueue` 외 큐 워커들은 **로그인·사용자 조작 없이** 1분 알람으로 rankfree 서버에
롱폴링해 작업을 받고, 숨김 탭으로 네이버를 **사용자 IP·세션으로** 크롤해 결과를 올린다.
코드 주석이 그대로 말한다 — *"확장이 깔려 있으면 그냥 돈다."*

구글 관점에서는 **표시된 단일 목적과 무관한 미신고 백그라운드 기능**으로 읽힐 수 있다.

### 왜 그래도 남겼나

**쇼핑 주문을 넣는 누구나 상품정보 수집을 쓸 수 있어야 한다**(2026-08-10 결정).
주문 자동채움([OrderFieldAutofill](../app/Domain/Order/OrderFieldAutofill.php))이 쓰는
`seller_tags`·`thumbnail_url` 은 확장 수집이 유일한 소스이고,
외부 API 로 만든 분석은 화면을 여는 사람이 없어 큐 워커가 채워 준다
([ShopKeywordApiController](../app/Http/Controllers/Api/ShopKeywordApiController.php):83).

빌드를 나누면 웹스토어로 받은 사용자는 이 경로가 통째로 죽는다. 사업 요구가 먼저다.

### 심사에서 지적받으면

권한 사유·단일 목적 설명에 이 동작을 **명시**하고, 사용자에게 보이는 곳(스토어 설명·옵션)에서
켜고 끌 수 있게 하는 것이 정공법이다. 그때 이 문서의 근거를 그대로 쓴다.

같은 감사에서 나온 잔여 항목:

- `content/product.js` 의 `collectProductInfo()` 가 스마트스토어 상품 페이지 **진입만으로**
  실행된다 → 개인정보 신고의 "웹 기록" 회색지대.
- `content/injected.js`·`injected-store.js` 가 `fetch`/`XHR` 을 후킹한다(네이버 검색·리뷰 API
  한정) → "네트워크 모니터링" 으로 볼 여지.

## 개인정보 보호 관행 — 코드와 맞는 답

감사 결과 기준. **네이버 쿠키·세션 탈취는 없다**(`cookies` 권한 없음, `document.cookie`·
`chrome.cookies` 사용 0건). 제3자 추적 SDK 도 0건 — 통신 대상은 rankfree 와 naver 뿐이다.

| 항목 | 신고 | 근거 |
|---|---|---|
| 개인 식별 정보 | **예** | 확장 로그인 폼이 이메일을 받고 `rfUser`(id·name·email)를 저장 |
| 인증 정보 | **예** | 로그인 폼이 비밀번호를 받아 전송(저장은 안 함), `rfApiKey` 평문 저장 |
| 웹사이트 콘텐츠 | **예** | 네이버 검색결과·상품 정보를 파싱해 서버로 전송 |
| 건강 / 금융·결제 / 개인 통신 / 위치 | 아니오 | 관련 코드 0건(`geolocation` 검색 0건) |
| 웹 기록 / 사용자 활동 | 아니오 | `history` 권한 없음, `chrome.tabs.query` 0건. 단 위 회색지대 참고 |

권한 사유는 매니페스트와 **정확히 일치**해야 한다. `shopping.naver.com` 은 이번에 뺐으므로
사유에서도 지워야 한다(현재 호스트: rankfree 2 · search.shopping · search · m.search ·
s.search · smartstore · brand · map · pcmap · localhost 2 = 12개).

## 게시 절차

```bash
node scripts/publish-extension.mjs          # 로컬 CLI (extension/ 을 zip → 업로드 → 게시)
```

또는 관리자 **환경설정**(`/admin/settings`) → **[웹스토어에 게시]**.

두 경로 모두 `extension/` 을 압축하며 `.md` 는 제외한다. 게시 직전 캡차 유출 검사를 통과해야 한다.
