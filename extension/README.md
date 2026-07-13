# RankFree 크롬 확장 — 네이버 쇼핑 시장분석

네이버 쇼핑 검색 결과 페이지(`search.shopping.naver.com/search/*`)에서 동작하는 시장분석 도구.
**rankfree.kr 계정으로 로그인해야만** 분석 화면이 열립니다.

## 기능 (v0.1)

- **시장 분석** (구현됨)
  - **연관 키워드 자동 추출**(패널 상단 칩) — 칩 클릭 시 해당 키워드로 이동해 바로 분석
  - 상품 수집은 자동 실행되지 않음 — **"수집 시작" 버튼**을 눌러야 수집(범위 80~400개 선택)
  - 상위 80~400개 상품의 `6개월 구매건수 × 판매가`로 시장 규모 추정
  - 월평균 매출·판매량, 평균/중앙값 판매가, 상위 10개 매출 점유율
  - 마진율 입력 → 월 예상 수익 계산
  - 매출 상위 10개 상품 테이블 (광고 포함/제외 토글)
  - **키워드 분석 합산 표시**: rankfree 서버의 네이버 검색광고 API(월간 검색량·경쟁강도·상품수/검색량 비율)
- **순위 추적 / 리뷰 분석** — 탭만 준비(곧 제공)

## 설치 (개발자 모드)

1. Chrome → `chrome://extensions` → 우측 상단 **개발자 모드** ON
2. **압축해제된 확장 프로그램을 로드합니다** → 이 `extension/` 폴더 선택
3. 네이버 쇼핑에서 아무 키워드나 검색 → 우하단 **R** 버튼 클릭
4. rankfree 계정으로 로그인 → 자동 분석 시작

### 로컬 개발 서버 연결

로그인 폼의 **고급 설정 → 서버 주소**에 로컬 주소(예: `http://rankfree.test` 또는
`http://localhost:8000`)를 입력하면 운영(`https://rankfree.kr`) 대신 해당 서버로 인증/API를 호출합니다.

## 동작 구조

```
content/injected.js   MAIN world — 페이지의 x-wtm-ncaptcha-token 가로채기(있으면 재사용)
content/content.js    패널 UI + 같은 오리진으로 /api/search/all 수집 + 시장분석 계산
content/panel.css     Cal.com 디자인 토큰 미러(모노크롬) — resources/css/app.css 와 값 동기 유지
background.js         rankfree 서버 통신(로그인/세션/키워드 분석) — Bearer 토큰
```

- 네이버 쇼핑 데이터는 **사용자 브라우저의 자체 세션**으로 페이지와 같은 오리진에서 수집합니다
  (쿠키를 확장이 저장하거나 외부로 보내지 않음).
- rankfree 인증 토큰은 `chrome.storage.local`에만 저장됩니다.

## 서버 요구사항

- `POST /api/ext/login`, `GET /api/ext/me`, `POST /api/ext/logout`,
  `GET /api/ext/keyword-analysis?keyword=` — 본 저장소 Laravel 앱에 구현돼 있음
- 키워드 분석(검색량)을 켜려면 `.env`에 네이버 검색광고 API 자격증명 설정:
  ```
  NAVER_SEARCHAD_API_KEY=
  NAVER_SEARCHAD_SECRET=
  NAVER_SEARCHAD_CUSTOMER_ID=
  ```
  비워두면 키워드 카드 없이 시장 분석만 표시됩니다.

## 주의

- 구매건수는 네이버가 노출하는 **최근 6개월** 값이며, 시장 규모·예상 수익은 rankfree **자체 추정치**입니다.
- 네이버 응답 구조(`shoppingResult.products`, `__NEXT_DATA__`) 변경 시 파싱이 깨질 수 있습니다.
