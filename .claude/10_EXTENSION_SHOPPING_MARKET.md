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
| 수집 방식 | content script가 **같은 오리진**으로 `/api/search/all` 호출(pagingSize=80 × 1~5페이지, 페이지 간 500ms). 실패 시 `__NEXT_DATA__` 폴백 |
| nCaptcha | MAIN world 훅(injected.js)으로 페이지가 쓰는 `x-wtm-ncaptcha-token`을 가로채 재사용(없어도 동작 시도) |
| 키워드 분석 | 서버 API `GET /api/ext/keyword-analysis` — 네이버 검색광고 `/keywordstool`(HMAC-SHA256, crm `api/naver_ads_api.php` 서명 방식 이식). 자격증명 없으면 `data: null` |
| UI | 우하단 FAB → 우측 고정 패널. **탭 전환 구조**(시장 분석=구현, 순위 추적·리뷰 분석=준비 중 자리) |
| 스타일 | Cal.com 토큰을 `panel.css`에 CSS 변수로 미러(확장은 Tailwind 빌드 불가). `resources/css/app.css` @theme 값과 동기 유지 |

## 시장 분석 지표 정의

- 대상: 광고 제외 상품(토글로 포함 가능), `price > 0`
- **6개월 시장 규모** = Σ(구매건수(6개월) × 판매가) — *자체 추정치로 표기(네이버 공식 아님)*
- **월평균 매출** = 시장 규모 / 6, **월평균 판매량** = Σ구매건수 / 6
- **평균 판매가** = 단순 평균(+중앙값 병기)
- **상위 10개 점유율** = 매출 상위 10개 매출합 / 전체 매출
- **월 예상 수익** = 월평균 매출 × 마진율(사용자 입력, 기본 30%)
- 키워드 카드: 월간 검색량(PC/모바일), 경쟁 강도(compIdx), 전체 상품수, **상품수/검색량 비율**(낮을수록 기회)

## 서버 API 계약

| 메서드 | 경로 | 인증 | 응답 |
|--------|------|------|------|
| POST | `/api/ext/login` | — (throttle 10/분) | `{token, user:{id,name,email,role}}` / 422 |
| GET | `/api/ext/me` | Bearer | `{user}` / 401 |
| POST | `/api/ext/logout` | Bearer | `{ok:true}` (토큰 폐기) |
| GET | `/api/ext/keyword-analysis?keyword=` | Bearer (throttle 30/분) | `{data: {keyword, monthly_pc, monthly_mobile, monthly_total, comp_idx, related[]} \| null}` |

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

## 남은 일 (다음 단계)

- [ ] 확장 아이콘(16/48/128) 추가 후 스토어 등록 준비
- [ ] 순위 추적 탭: 특정 상품(스토어) 순위 저장 → rankfree 콘솔 연동
- [ ] 리뷰 분석 탭
- [ ] 연관 키워드(related) UI 노출 + 03_KEYWORD_ANALYSIS_UI.md 콘솔 화면과 데이터 공유
- [ ] 등급(grade)별 수집 범위 제한(무료 80개 / 유료 400개 등) — MenuPermission 체계와 연결
