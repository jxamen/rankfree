# 10. 크롬 확장 — 쇼핑 시장 분석 (C1)

> [02_SERVICE_CATALOG.md](./02_SERVICE_CATALOG.md) 의 C1 "쇼핑 시장 분석" 구현 문서.
> 쇼핑군(C1~C4)은 서버 크롤링 불가 → **확장 프로그램이 사용자 브라우저에서 수집**하는 설계.

## 결정 사항 (2026-07-10)

| 항목 | 결정 |
|------|------|
| 위치 | 저장소 루트 `extension/` (Manifest V3, 빌드 없음 — 순수 JS/CSS) |
| 동작 URL | `https://search.shopping.naver.com/search/*` |
| 로그인 게이트 | **rankfree 계정 필수.** 미로그인 시 패널에 로그인 폼만 노출 |
| 인증 방식 | 자체 경량 Bearer 토큰(`ext_tokens` 테이블, sha256 해시 저장). **Sanctum 미도입**(의존성 최소화, 세션 기반 웹과 분리) |
| 수집 방식 | **3단 전략**: ① `/api/search/all`(캡처된 nCaptcha 토큰 동봉) → ② 검색 페이지 HTML fetch 후 SSR(`__NEXT_DATA__`) 추출 → ③ 현재 문서 SSR. 상품 배열은 고정 경로가 아니라 **JSON 재귀 탐색**으로 찾음(구조 변경 내성). pagingSize=80 × 1~5페이지, 페이지 간 600ms, 중복 제거 |
| 수집 트리거 | **수동** — 페이지 이동 시 자동 수집 금지. 연관 키워드만 자동 추출(무요청 DOM SSR, SPA 이동 후엔 1페이지 HTML 1회), 상품 수집은 "수집 시작" 버튼 또는 연관 키워드 칩 클릭(명시적 분석 요청)으로만 |
| 연관 키워드 | 쇼핑 연관검색어(수집 데이터 내 `related*` 문자열 배열) + keywordstool 연관어(월간 검색량 배지) 병합, 패널 상단 칩. 칩 클릭 → 해당 키워드 페이지 이동 + 자동 수집(sessionStorage `rfAutoCollect`) |
| nCaptcha | MAIN world 훅(injected.js)으로 페이지가 쓰는 `x-wtm-ncaptcha-token`을 가로채 재사용(없어도 동작 시도) |
| 키워드 분석 | **API 키 우선**: 설정(⚙)에 `rk_` 키 저장 시 `GET /api/v1/keyword/detail`(scope keyword_detail, 성별·연령·12개월 트렌드) → 403/503이면 `GET /api/v1/keyword`(scope keyword) 폴백. 키 없으면 `GET /api/ext/keyword-analysis`(ext 토큰). 상세는 패널에 미니 컬럼(트렌드)·분할 바(성별)·가로 바(연령)로 시각화(모노크롬, 직접 라벨) |
| UI | 우하단 FAB → 우측 고정 패널. **탭 전환 구조**(시장 분석=구현, 순위 추적·리뷰 분석=준비 중 자리) |
| 스타일 | Cal.com 토큰을 `panel.css`에 CSS 변수로 미러(확장은 Tailwind 빌드 불가). `resources/css/app.css` @theme 값과 동기 유지 |

## 시장 분석 지표 정의

- 대상: 광고 제외 상품(토글로 포함 가능), `price > 0`
- **가격비교(카탈로그) 상품 보강**: 목록에서 구매건수가 없는 카탈로그 상품(productType=1 또는 mallCount>1)은
  `/catalog/{id}` 페이지를 같은 오리진으로 받아 판매처(스마트스토어 등)의 구매건수×판매가를 합산
  (리뷰 많은 순 최대 15개, **동시 4요청 병렬**, `revenue6m` 오버라이드) — 순차→병렬로 대기시간 대폭 단축
- 상품 배열 추출은 재귀 탐색 후 **가장 큰 배열 선택**(광고 배열 오탐 방지). 연관검색어는
  ① 렌더된 DOM 스트립 → ② SSR JSON → ③ 1페이지 HTML 순으로 추출
- **6개월 시장 규모** = Σ(구매건수(6개월) × 판매가) — *자체 추정치로 표기(네이버 공식 아님)*
- **월평균 매출** = 시장 규모 / 6, **월평균 판매량** = Σ구매건수 / 6
- **평균 판매가** = 단순 평균(+중앙값 병기)
- **상위 10개 점유율** = 매출 상위 10개 매출합 / 전체 매출
- **월 예상 수익** = 월평균 매출 × 마진율(사용자 입력, 기본 30%)
- 키워드 카드: 월간 검색량(PC/모바일), 경쟁 강도(compIdx), 전체 상품수, **상품수/검색량 비율**
- **상품수/검색량(진입 경쟁도) 등급** — rankfree 자체 기준, 등급 배지+해석 문구로 표시:
  `<0.5 매우 좋음 / <1.5 좋음 / <4 보통 / <10 높음 / ≥10 매우 높음(포화)`

## 서버 API 계약

| 메서드 | 경로 | 인증 | 응답 |
|--------|------|------|------|
| POST | `/api/ext/login` | — (throttle 10/분) | `{token, user:{id,name,email,role}}` / 422 |
| GET | `/api/ext/me` | Bearer | `{user}` / 401 |
| POST | `/api/ext/logout` | Bearer | `{ok:true}` (토큰 폐기) |
| GET | `/api/ext/keyword-analysis?keyword=` | Bearer (throttle 30/분) | `{data: {keyword, monthly_pc, monthly_mobile, monthly_total, comp_idx, related[]} \| null}` |
| GET | `/api/ext/keyword-analysis/detail?keyword=` | Bearer (throttle 15/분) | 경량 + `detail:{gender, age[], monthly[](12개월), buckets[]}` — 검색광고 웹 세션 소스, 장애 시 503. 외부용은 `/api/v1/keyword/detail`(scope `keyword_detail`)로 분리 |

- 구현: `app/Http/Controllers/Api/ExtAuthController.php`, `ExtKeywordController.php`,
  `app/Http/Middleware/AuthenticateExtToken.php`(alias `auth.ext`), `app/Models/ExtToken.php`,
  `app/Domain/Keyword/NaverKeywordService.php`
- keywordstool 응답 캐시 6시간(`searchad:kwtool:{md5}`), `"< 10"` 절사값은 5로 추정
- 자격증명 .env: `NAVER_SEARCHAD_API_KEY / NAVER_SEARCHAD_SECRET / NAVER_SEARCHAD_CUSTOMER_ID`
- 테스트: `tests/Feature/ExtApiTest.php` (7건)

## 보안 원칙

- 네이버 쿠키/세션은 **사용자 브라우저 안에서만** 사용 — 확장이 저장·전송하지 않는다
- rankfree 토큰은 `chrome.storage.local` 저장, DB에는 sha256 해시만
- 개발용 서버 주소 오버라이드는 로그인 폼 "고급 설정"에서만(기본 `https://rankfree.kr`)
- **개발 바이패스**: `APP_ENV=local` + `rankfree.super_admins` 이메일에 한해 확장 로그인 시
  비밀번호 검사 생략(계정 없으면 role=super 자동 생성). `ExtAuthController::isDevBypass()` —
  local 환경 밖에서는 절대 동작하지 않음(테스트로 고정)

## 분석 결과 서버 저장 — 2026-07-10 추가

- **자동 저장**: 확장에서 수집 완료 시 `POST /api/ext/market-analyses`(ext 토큰, throttle 20/분)로
  요약 컬럼(keyword, sales_6m, revenue_6m, avg_price, median_price, top10_share, monthly_search,
  comp_idx 등) + `snapshot` JSON(연관 키워드, 키워드 데이터[buckets 제외], 매출 상위 10개 상품)을 저장.
  스냅샷 120KB 초과 시 422. 테이블 `market_analyses`
- **내역 조회**: `GET /api/ext/market-analyses`(목록, limit≤50) / `GET /api/ext/market-analyses/{id}`(스냅샷 포함, 소유자만)
- **확장 UI**: "내역" 탭 → 목록 클릭 시 저장 당시 결과를 시장 분석 탭에 저장본 모드로 렌더링
  ("새로 분석" 버튼으로 현재 페이지 재수집). 저장 성공 시 시장 규모 카드에 "☁ 저장됨" 칩
- **웹 콘솔**: `GET /console/market`(목록·삭제) / `/console/market/{id}`(상세 — 지표 카드·키워드·연관
  키워드·상위 상품). 사이드바 메뉴 "쇼핑 > 시장 분석"(menus id 20). 컨트롤러 `MarketAnalysisController`
- **콘솔 상세의 키워드 상세 분석**(시즌·타겟): 12개월 검색량 추이 컬럼차트 + 성별 분할 바 +
  연령별 가로 바 + **성별×연령 페어 바**(buckets). 피크/주 타겟 인사이트 배지, 시즌 해석 각주.
  이를 위해 확장 저장 시 detail의 `buckets`를 **삭제하지 않고 통째로 저장**(구 저장본은 해당 카드에 재분석 안내)
- 테스트: `tests/Feature/ExtMarketApiTest.php` (5건 — 저장/검증/목록 소유자 분리/스냅샷·403/미인증)

## 시장 구성 (1등 카테고리 · 몰 등급) — 2026-07-10 추가

- 상품 정규화 시 `mallGrade` 분류(`mallGradeOf`): 프리미엄/빅파워/파워/브랜드스토어/일반/가격비교/해외직구/기타
  (네이버 필드가 버전마다 달라 여러 키를 넓게 확인 — 실데이터로 검증·보정 필요)
- 시장 분석 카드에 **시장 구성** 추가: 1등(매출 최상위) 상품 카테고리, 판매처 등급 분포(막대 + 상위등급 합계),
  주요 카테고리 TOP5. snapshot에 `top_product_category/mall_grades/top_categories` 저장 → 콘솔 상세에도 표시

## 상품 분석 (스마트스토어 리뷰 분석) — 2026-07-10 추가

- **동작 URL**: `smartstore.naver.com/*/products/*`, `brand.naver.com/*/products/*` (`content/product.js`)
- **데이터**: `POST /i/v1/contents/reviews/query-pages` (같은 오리진, 사용자 세션) —
  body `{checkoutMerchantNo, originProductNo, page, pageSize, reviewSearchSortType}`.
  파라미터는 페이지 스크립트(`__PRELOADED_STATE__`)에서 정규식 추출, x-client-* 헤더는
  MAIN world 훅(`injected-store.js`)으로 캡처해 재사용
- **pageSize 프로브**: 기본 20 → 100/50/30 순 시도해 실제 최대치 측정(반환수<요청수라도 전체
  리뷰수와 같으면 요청 크기 인정). 결과를 UI 칩("페이지당 N개")과 콘솔에 표시
- **병렬 수집**: 동시 4요청, id 중복 제거. 최신순(REVIEW_CREATE_DATE_DESC) 100/300/500개 선택 +
  평점 낮은순(REVIEW_SCORE_ASC) 100개
- **지표**: 전체 리뷰수, 평균 평점, 평점 분포(1~5), **재구매 비율**(repurchase 플래그),
  **최근 7일/1개월/3개월 리뷰수**(작성일 기준 — 수집 범위가 90일을 못 덮으면 "최소치" 표기),
  **인기 옵션 TOP6**(리뷰의 옵션 문자열 집계 — 판매 비중 추정치)
- **약점 분석**: 3점 이하 리뷰의 빈출 단어(문서 빈도, 불용어 제거, 상위 12개) + 저평점 발췌 2건
- 수집은 **수동 트리거**("리뷰 분석 시작" 버튼), 로그인 게이트는 검색 패널과 동일(background 공유)
- 리뷰 분석 수: 300/500/1000/2000/**3000** 선택(기본 500), 저평점 200개
- **감정 분석·장단점**(경량 형태소): 외부 NLP 라이브러리 없이 조사·어미 휴리스틱 + 감성 사전으로
  긍정/부정/중립 비율, 자주 언급된 장점/단점 표현, 주로 이야기하는 명사 추출(`analyzeTexts`)
- **QnA 분석**: `GET /i/v1/qna/pages?page=&pageSize=20&isMyQna=false&qnaStatus=ALL&excludeSecret=false`
  `&channelProductNo={URL상품번호}&channelNo={merchantNo}` 를 **파라미터로 직접 구성 호출**(리뷰와 동일
  서명 헤더 재사용) → **문의 탭을 안 열어도 자동 수집**. **비밀글(secret) 제외** 후 형태소 분석으로
  "자주 묻는 것" 추출. 응답 파싱은 재귀 탐색(`findQnaList`)이라 구조 변경에 견딤. `injected-store.js`
  캡처는 폴백으로만 사용
- 감성/QnA 집계는 snapshot에 저장 → 콘솔 상세에도 표시
- **서명 헤더 확보(401 방지)**: 리뷰 API는 페이지 생성 `x-client-rtk/rts` 서명 헤더 필수(없으면 401
  "비정상적인 접근"). 분석 전 `ensureSignedHeaders()`가 페이지 리뷰 로드를 유도(리뷰 탭 클릭/스크롤)해
  `injected-store.js` 훅이 헤더+정확한 파라미터(merchantNo/originProductNo)를 캡처하게 함
- **옵션별 예상 판매·매출**: 6개월 판매량(입력) × 리뷰 옵션 비율 → 옵션별 예상 판매수량·매출.
  판매가는 페이지에서 자동 추출. 확장 패널·콘솔 상세 양쪽 제공
- **서버 저장**: 확장에서 분석 완료 시 `POST /api/ext/product-analyses`(throttle 20/분) 자동 저장.
  `product_analyses` 테이블. 내역 `GET /api/ext/product-analyses`(+`/{id}`). 확장 "내역" 탭 + 저장본 열람.
  웹 콘솔 `console/product`·`console/product/{id}`(옵션별 매출 계산기 포함), 메뉴 "쇼핑 > 상품 분석"(id 21)
- 테스트: `tests/Feature/ExtProductApiTest.php` (4건)

## 남은 일 (다음 단계)

- [ ] 확장 아이콘(16/48/128) 추가 후 스토어 등록 준비
- [ ] 순위 추적 탭: 특정 상품(스토어) 순위 저장 → rankfree 콘솔 연동
- [ ] 리뷰 분석 탭
- [ ] 연관 키워드(related) UI 노출 + 03_KEYWORD_ANALYSIS_UI.md 콘솔 화면과 데이터 공유
- [ ] 등급(grade)별 수집 범위 제한(무료 80개 / 유료 400개 등) — MenuPermission 체계와 연결
