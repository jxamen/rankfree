# 12. 스마트플레이스 리포트 수집

> crm `ads/smartplace`(계정별 통계·리뷰·스마트콜·예약 수집 + 5탭 리포트)를 rankfree 콘솔로 이식.
> 원본 인벤토리: [research/research-crm-smartplace-inventory.md](./research/research-crm-smartplace-inventory.md)

## 목적

사장님(광고주) 네이버 계정의 **스마트플레이스 대시보드 데이터**를 대행사가 한 번에 수집·열람.
순위 추적(외부 관측)과 달리 **로그인 세션(쿠키) 기반 내부 통계**를 다룬다.

## 등록 흐름 (매장 자동 조회 + 플레이스 URL)

등록 모달 입력: **광고주 네이버 아이디/비밀번호** + (선택) **플레이스 URL**.
1. `[매장 불러오기]` → 저장 없이 임시 로그인(`SmartplaceLoginService::login($temp, persist: false)`)해 쿠키 획득.
2. `SmartplaceCollector::listBusinesses()` — `GET /api/refined-businesses`(place-seq 없이)가 계정의 **매장 목록**을 배열로 반환.
3. 매장 **1개면 자동 선택**, 여러 개면 선택 → hidden `place_seq`/`business_id` 확정 + 업체명 자동.
4. **플레이스 URL**(지도/순위): PC `map.naver.com` URL·`m.place` URL·플레이스 ID 무엇이든 입력 → `console.rank.resolve`(= `RankSlotService::resolvePlace`) 재사용으로 **m.place 정규화 + 업체명·업종 자동 조회**(디바운스 미리보기). 업체명 비어 있으면 자동 채움.
5. 등록 제출: `place_seq`(필수·매장 선택) + `place`(플레이스 URL, 선택) → `place_url`/`place_id`/`category` 저장.

목록 항목 구조는 네이버가 문서화하지 않아, `placeSeq`·업체명·`placeId`·`businessId` 를 **재귀 탐색(deepFind)** 으로 방어적으로 추출한다. AJAX 엔드포인트: `POST console/smartplace/discover`.

**비밀번호 표시**: 수정 모달은 저장된 비밀번호를 **복호화해 채워 표시**(보기 토글) — 실제 네이버 로그인 확인용으로 보관하므로. 저장은 암호화(`naver_pw` = `encrypted`). 브라우저 비밀번호 저장 팝업은 `autocomplete=off`·`data-lpignore`·더미 username 필드로 억제.

> ⚠️ 실제 매장이 있는 계정으로 `listBusinesses` 항목 구조 최종 검증 필요 — 매장 없는 계정(스모크)은 `[]`(count 0). 매장이 있는데 0개면 응답 키(`placeSeq` 등)가 예상과 달라 `deepFind` 키 후보 조정 필요.

**로컬 실행 주의(TEMP)**: 웹 서버(Laragon) 프로세스에 `TEMP`/`TMP` 가 없으면 Playwright 가 `mkdtemp 'undefined\temp\...'` 로 실패한다(`browser_launch_failed`). `SmartplaceLoginService` 가 `env` 에 `TEMP`/`TMP`(sys_get_temp_dir)·`SystemRoot` 를 명시 주입해 해결. 실서버(리눅스)는 정상.

## 인증 방식 (아이디/비밀번호 자동 로그인)

계정 등록 시 **광고주 네이버 아이디/비밀번호**를 저장(비번 암호화). 쿠키는 직접 입력하지 않는다.

**node 경로 주의**: 웹 서버(Laragon/php-fpm) 프로세스의 PATH 에는 `node` 가 없을 수 있어(CLI 로는 되지만 웹 요청에선 `no_output` 실패), `SmartplaceLoginService::resolveNode()` 가 흔한 절대경로(`C:\Program Files\nodejs\node.exe` 등)를 자동 탐색한다. 명시하려면 `.env` 의 `RANKFREE_NODE` 에 node.exe 절대경로 지정.

`[수집]` 시:
1. 저장된 세션 쿠키가 있으면 그걸로 우선 수집(빠름 — 매번 로그인 안 함).
2. 세션 없음/만료(`loggedIn=false`)면 **자동 로그인(Playwright)** → 쿠키 발급·저장 → 재수집.
3. 자동 로그인 실패(캡차/2차 인증)면 사유를 안내.

로그인은 [scripts/naver-smartplace-login.cjs](../scripts/naver-smartplace-login.cjs)(원본 `sp_auto_runner.mjs` 이식) — `nidlogin` 폼에 ID/PW 입력, **로그인 상태 유지 ON**, `deviceConfirm`(새 기기) "등록안함" 처리, 스마트플레이스/bizadvisor 방문으로 세션 보강 후 쿠키를 stdout JSON 으로 출력. [SmartplaceLoginService](../app/Domain/Place/SmartplaceLoginService.php) 가 `Process->env()->run([node, script])`(검색광고 로그인과 동일 패턴)로 실행하고 쿠키를 암호화 저장. node/playwright 경로는 `config/searchadweb.php` 재사용.

## 데이터 소스 (네이버, 쿠키 인증)

| 섹션 | 엔드포인트 | 인증 |
|------|-----------|------|
| ID 확정 | `new.smartplace.naver.com/api/refined-businesses/place-seq/{placeSeq}` | NID 쿠키 |
| 통계 6종 | `.../api/proxy/bizadvisor/api/v3/sites/{siteId}/report` | NID + **Bearer(ba_access_token)** |
| 방문자 리뷰 | `.../graphql?opName=getReviews` | NID |
| 블로그 리뷰 | `.../api/reviews/blog?businessId=&category=` | NID (category 필수 → m.place 자동판별) |
| 예약 고객 | `api-partner.booking.naver.com/.../users` | NID (businessId 있을 때만) |
| 스마트콜 | `smartcall.smartplace.naver.com/api/businesses/{placeId}/...` | NID |

- **Bearer 자동발급**: NID 쿠키로 `bizadvisor.naver.com/auth/naver/from/smartplace` OAuth 리다이렉트를 따라가 Set-Cookie 의 `ba_access_token` 획득. 쿠키에 이미 있으면 재사용.
- 통계 6종: 일자별 조회수 / 연령·성별 / 시간대 / 요일 / 유입채널 / 유입검색어.
- 네이버 sec-fetch/sec-ch-ua 헤더 검사 → curl 도 브라우저와 동일 헤더로 위장.

## 코드 구조 (Laravel)

| 파일 | 역할 |
|------|------|
| [app/Domain/Place/SmartplaceCollector.php](../app/Domain/Place/SmartplaceCollector.php) | 순수 curl 수집기 (crm `smartplace.lib.php` 이식). URL/쿠키 파싱, Bearer 발급, 섹션별 호출, `collect()` 오케스트레이션 |
| [app/Domain/Place/SmartplaceLoginService.php](../app/Domain/Place/SmartplaceLoginService.php) | 계정별 아이디/비번으로 Playwright 자동 로그인 → 세션 쿠키 발급·암호화 저장 |
| [scripts/naver-smartplace-login.cjs](../scripts/naver-smartplace-login.cjs) | Playwright 로그인 러너 (crm `sp_auto_runner.mjs` 이식). env 입력 → 쿠키 stdout JSON |
| [app/Domain/Place/SmartplaceReportPresenter.php](../app/Domain/Place/SmartplaceReportPresenter.php) | `last_result` JSON → 5탭 HTML (crm `report.php` 이식). SVG 차트·막대·연령 피라미드·테이블. **색은 전부 디자인 토큰** |
| [app/Models/SmartplaceAccount.php](../app/Models/SmartplaceAccount.php) | 계정 모델. `naver_pw`·`cookie`=`encrypted`, `last_result`=`encrypted:array`, `hidden`=[naver_pw,cookie] |
| [app/Http/Controllers/SmartplaceController.php](../app/Http/Controllers/SmartplaceController.php) | index/store/update/destroy + collect(세션 우선 → 자동 로그인 재수집)/report |
| [resources/views/console/smartplace/index.blade.php](../resources/views/console/smartplace/index.blade.php) | 계정 목록(가로 전체 테이블) · 등록/수정 모달(아이디/비번) · 안내창 · 기간 컨트롤 · 수집 Swal |
| [resources/views/console/smartplace/report.blade.php](../resources/views/console/smartplace/report.blade.php) | 5탭 리포트(리포트/플레이스/스마트콜/예약·주문/리뷰) · 기간 재수집 |

라우트: `console.smartplace.*` (index/store/update/destroy/collect/report) — [routes/web.php](../routes/web.php).
메뉴: PermissionSeeder 콘솔 메뉴에 `console.smartplace`(🏪) 추가.

## N2 실측 캘리브레이션 (경쟁분석 연동)

내 매장의 **실제 조회수(스마트플레이스)** ↔ **N2 추정치**를 시계열로 맞춰 N2 정확도를 검증·튜닝한다.

- **연결 키**: `smartplace_accounts.place_id` == `place_seo_scores.place_id` (둘 다 m.place placeId). 자동 매칭.
- **실측 신호**: `last_result['sections']['stats']['date_time']['data']` = `[{date_time, pv}]` (일자별 조회수=플레이스 유입 PV).
- **엔진**: [PlaceCalibration](../app/Domain/Place/PlaceCalibration.php) (순수·무네트워크) — `dailyPv()`·`align()`(합집합 rows/교집합 overlap)·`pearson()`·`summarize()`. 유닛테스트 `tests/Unit/PlaceCalibrationTest.php`.
- **상관**: N2↔PV(양수 클수록 N2가 실측 잘 반영), 순위↔PV(음수가 정상 — 상위일수록 조회↑). 표본 n≥3(겹치는 분석일) 필요.
- **표시**: `console.compete.show`의 KPI 아래 "실측 검증" 패널([_calibration.blade.php](../resources/views/compete/_calibration.blade.php)) — 상관 리드아웃 + PV막대·N2선 오버레이. **사적 데이터라 공개 공유(`compete.share`)엔 노출 안 함.** 미연결/미수집 시 안내 상태.
- **활용**: 강한 양의 상관이면 현행 가중치 타당, 약하면 D6(사진)·D9(리뷰활동) 등 가중치 조정 근거. 분석일이 누적될수록 신뢰도 상승.

## N2 가중치 학습 (소수 라벨 → 프라이어 수축)

캘리브레이션은 "검증"이고, 이 절은 그 라벨로 **N2 가중치를 학습**해 개선한다. 스마트플레이스 조회수는 **소유매장 소수**만 있으므로(경쟁사엔 없음), 목표는 **공개 신호(X)→실제 조회수(y) 함수를 소수 라벨로 학습해 경쟁사에 적용**하는 것. N2 자체가 이미 "수동 회귀"(D가중치)이므로, 그 계수를 학습으로 개선한다.

- **특징 X = 공개 신호만** (경쟁사에도 있어야 적용 가능): D1~D10 정규화 점수. 스마트플레이스發 값은 X에 쓰지 않음.
- **라벨 y = 실제 조회수(PV)**: 소유매장만. PV(조회수)≠방문자 — 라벨은 조회수로 정직히 표기.
- **과적합 방어(핵심)**: 라벨이 8개 가중치보다 적으면 자유학습 불가 → **프라이어(현 수동 가중치)에서 데이터가 말하는 만큼만 이동**.
  `w_new[d] = prior[d]·exp(β·trust·clamp(corr(D,PV)))`, `trust=n_store/(n_store+K)`. 라벨 적으면 trust→0 → 거의 프라이어. 표본 3개 미만이면 recommended==prior.
- **게이팅**: `apply = n_store ≥ scoring.apply_min_stores(기본 10)` 이고 근거 차원 ≥3 일 때만 학습 반영 권고. 그 전엔 **진단만**(현재 라벨 1~3개 → 프라이어 유지).
- **엔진/커맨드**: [PlaceWeightLearner](../app/Domain/Place/PlaceWeightLearner.php)(순수, 유닛테스트 `PlaceWeightLearnerTest`) + `php artisan place:learn-weights`(소유매장 라벨 집계 → 차원별 PV상관·프라이어 vs 권고 표·판정).
- **적용 통로**: N2 가중치는 [config/rankfree.php](../config/rankfree.php) `scoring.n2_weights` 로 외부화 — `PlaceScorer::n2Weights()`가 읽음. 학습 확정 시 이 값을 권고값으로 갱신(수동 검토 후)하면 즉시 반영.
- **성장 자산**: 관리(소유) 매장이 늘수록 라벨이 늘어 모델이 강해진다. 지금 1~3개는 "프라이어 유지 + 진단" 단계.

## 원본과 달라진 점 (개선)

- **자격·쿠키·수집결과 암호화 저장** — 원본 crm 은 평문(`naver_pw`/`cookie`/`last_result`). rankfree 는 Laravel `encrypted` 캐스트 + 모델 `hidden`. validation 실패 시 세션 플래시 제외(`bootstrap/app.php` dontFlash: naver_pw/cookie).
- **사용자 격리** — 계정은 `user_id` 소유. 모든 액션에서 `abort_unless(owner)`.
- **자동 로그인 통합** — 원본은 크론/exec 로 러너를 외부 실행했으나, rankfree 는 `[수집]` 액션이 세션 만료를 감지하면 `SmartplaceLoginService` 로 **인라인 자동 로그인 후 재수집**. 사용자는 아이디/비번만 등록하면 된다.
- **자격 변경 시 세션 폐기** — 수정에서 아이디/비번이 바뀌면 저장된 쿠키·`logged_in_at` 을 비워 다음 수집 때 새로 로그인.
- **순위체크 미포함** — rankfree 는 이미 `console.rank`(RankSlotService)로 순위 추적을 제공하므로 스마트플레이스에서는 리포트 수집만 담당.

## 한계 / 주의

- 응답 파싱(GraphQL opName, `refined-businesses` 구조, `fsasReviews` 키 등)은 **네이버 변경 시 깨질 수 있음**.
- 좌표·점수 관련 규칙은 [CLAUDE.md](./CLAUDE.md) 데이터 수집 규칙 준수.

## 예약 세부지표 (예약 고객 데이터 기반)

원본은 "연결 예정"으로 비워둔 예약 탭을, **이미 수집되는 예약 고객 목록**(`booking_users` = `api-partner.booking.naver.com/v3.0/businesses/{businessId}/users`, size=100)을 집계해 실제 지표로 구현.

- **요약**: 예약·주문 고객 수 · 분석 표본 · 여성 비율 · 최다 연령대 · 최다 유입.
- **유입경로**: `initialEntry`(고객이 매장을 처음 접한 경로) 분포 막대.
- **고객분석**: 성별(`sex`)·연령대(`ageGroup`) 분포 막대.
- **부가**: 재방문 고객(`visitCount>1`)·생일 등록률.
- **고객 목록**: 이름·성별·연령·전화·생일·유입(최대 40행).

빌더: `SmartplaceReportPresenter::bookingTab()` — `countBy()`/`distBars()`/`topKey()`. 색은 디자인 토큰. 예약 미사용(businessId 없음) 계정은 안내 상태.

> **매출·예약건수·시간대 통계**는 별도 네이버 예약 통계 API가 필요한데, `booking_users` 에 해당 필드가 없어 미표시. 실제 예약 사용 매장 계정으로 예약 통계 엔드포인트를 확인하면 추가 가능(추측 구현 금지 원칙상 보류).
