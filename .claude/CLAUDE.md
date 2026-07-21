# CLAUDE.md — rankfree 상세 규칙

> 루트 [CLAUDE.md](../CLAUDE.md)는 Quick Reference, 이 파일이 상세 기준입니다.

## 프로젝트 정보

- **프로젝트**: rankfree — 네이버 플레이스·쇼핑·마케팅 분석 SaaS. 무료 분석으로 사용자 모집 → 마케팅 중개·광고 수익화
- **스택**: PHP 8.3 + Laravel 13 + Tailwind v4 + Vite 8, MySQL/MariaDB, Blade 자체완결형
- **원본 자산**: `crm/ads/smartplace`(플레이스 순위·경쟁분석·블로그분석·순위 API) → Laravel 이식
- **배포(예정)**: 미정. crm 서버 무변경 원칙

## 응답 규칙

- **모든 답변은 한글**
- 관련 파일은 **링크로 제공**. 본문 파일경로 뒤에는 한 칸 띄우고 조사를 붙인다(Ctrl+클릭 깨짐 방지)
- 작업 완료 시 **수정/생성 파일 목록**을 아래 형식으로 제공:
  ```
  ## 수정된 파일
  - [파일경로](파일경로) - 설명
  ## 신규 생성 파일
  - [파일경로](파일경로) - 설명
  ```

## 작업 원칙

- 요청받은 작업은 그 자리에서 직접 바로 진행한다.
- 여러 단계 작업은 시작 전 계획을 세우고, 단계마다 검증 후 진행한다.
- 시작한 작업은 끝까지 완료한다. 실제로 반영·검증된 결과가 완료의 기준이다.
- **작업 완료 전 반드시 Playwright로 실제 동작을 검증한다**(문법 통과·코드 리뷰만으로 완료라 하지 않는다). 확장·수집·UI처럼 네이버 페이지 실동작에 의존하는 기능은 실제로 돌려 확인하고, 오류가 나면 **원인 수정 → 재검증 루프를 요청이 실동작으로 마무리될 때까지 반복**한다. 완료 보고엔 Playwright 검증 방법·결과를 포함한다. (chromium 설치됨, 임시 스크립트는 `$CLAUDE_JOB_DIR/tmp`)
- 막히면 조용히 넘어가지 말고 보고한다: 지금까지의 결과 + 막힌 지점 + 시도한 방법 + 대안.
- 모호한 요청은 가장 그럴듯한 해석을 명시하고 진행한다. 해석이 크게 갈릴 때만 질문한다.
- 설계 결정사항은 해당 `.claude` 설계 문서에 즉시 반영해 문서가 항상 최신 기준이 되게 한다.

## 코드 작성 규칙

- 수정 전에 반드시 대상 파일을 실제로 읽는다.
- 요청된 범위만 고친다. 요청 없는 리팩터링·기능 추가는 하지 않는다.
- 존재하지 않는 API·함수·컬럼을 추측으로 쓰지 않는다.
- Laravel 컨벤션을 따른다. 확정된 프로젝트 컨벤션은 이 문서에 추가해 나간다.
- 시크릿(DB 비밀번호, API 키, 네이버 자격증명)을 코드·문서에 하드코딩하지 않는다. `.env` 사용.
- 네이버 계정 쿠키/비밀번호 등 민감정보는 **암호화 저장**(원본 crm은 평문 — 이식 시 반드시 개선).
- 큰 변경 전 `.claude/backup/`에 백업본(파일명에 날짜)을 남긴다.

## UI / 디자인 규칙 (Coinbase 디자인 시스템)

- **디자인 시스템은 Coinbase** — [DESIGN.md](../DESIGN.md) 원본, Tailwind 매핑은 [09_DESIGN_SYSTEM.md](./09_DESIGN_SYSTEM.md). 2026-07-12 Cal.com에서 전환(백업 [backup/](./backup/))
- **모든 색/타이포/radius는 `resources/css/app.css`의 `@theme` 토큰으로만** 사용. 하드코딩 hex 금지. 토큰 이름은 기존 그대로(값만 Coinbase 리매핑)
- 반복 UI(버튼/카드/입력/배지)는 `@layer components`(btn/card/input/badge)로 정의된 클래스를 **재사용**. 새 인라인 스타일 남발 금지
- **sign 프로젝트의 콘솔/홈페이지 스타일을 복사하지 않는다.** 정보 구조만 참고, 스타일은 Coinbase 토큰으로 신규
- 흰 캔버스 + **Coinbase Blue(#0052ff=`primary`) 단일 브랜드 컬러** — CTA·인라인 링크·포커스 링에만 희소하게(밴드당 1~2곳). 두 번째 브랜드 컬러 도입 금지
- **모든 CTA는 pill(`--radius-pill`)**, 카드 16~24px, sharp corner 금지
- 상승/성공=`success`(#05b169)·하락/오류=`error`(#cf202f)는 **텍스트 색으로만**(버튼 배경 금지). badge 파스텔은 등급·차트 등 데이터 시각화 전용
- 다크 서피스(#0a0b0d)는 **히어로 밴드·featured 카드**에만. 남용 금지
- 디스플레이 헤드라인은 `.font-display`(Inter+Pretendard, **weight 400**, 음수 자간 — bold 금지가 시그니처). 본문은 Pretendard 400/600. 숫자·지표는 `font-mono`(JetBrains Mono)
- **콘솔 최소 폰트: 본문/데이터 13px, 보조(라벨·캡션·배지·테이블 헤더 셀) 12px. 12px 미만 금지**(10~11.5px 사용하지 않는다). 카드 제목은 14px
- **콘솔 콘텐츠 가로 최대 1880px** — `console.layout`의 `<main>` 내부 래퍼(`max-width:1880px;margin:0 auto`)로 고정

## 데이터 수집 규칙 (네이버)

- 네이버 pcmap GraphQL 순위조회는 **nCaptcha 토큰 필수**(없으면 405/429). 토큰 발급 도구·저장·재사용을 세트로 관리
- 좌표는 **서울 고정**(원본 설계 의도 — 지역좌표 주입 시 실제 모바일 순위와 어긋남)
- 응답 파싱(GraphQL opName·`__APOLLO_STATE__` 키)은 네이버 변경 시 깨질 수 있음. 인벤토리 [research-crm-smartplace-inventory.md](./research/research-crm-smartplace-inventory.md) 참조
- 점수(N1/N2/N3, D1~D10)는 **자체 추정치** — "네이버 공식 점수" 로 표현하지 않는다

## 보안

- 개인정보·네이버 자격증명은 최고 수준으로 취급. 시크릿을 로그·응답 출력에 노출하지 않는다.
- 악성 코드는 어떤 명분으로도 작성하지 않는다. 방어적 보안은 지원한다.

## 📁 상세 문서

| 파일 | 내용 |
|------|------|
| [09_DESIGN_SYSTEM.md](./09_DESIGN_SYSTEM.md) | Cal.com 디자인 시스템 → Tailwind 토큰 매핑 |
| [10_EXTENSION_SHOPPING_MARKET.md](./10_EXTENSION_SHOPPING_MARKET.md) | 크롬 확장 — 쇼핑 시장 분석(C1) 설계·API 계약 |
| [12_SMARTPLACE_REPORT.md](./12_SMARTPLACE_REPORT.md) | 스마트플레이스 리포트 수집(통계·리뷰·스마트콜·예약) 이식 설계 + N2 캘리브레이션·가중치 학습 |
| [13_KEYWORD_ANALYSIS.md](./13_KEYWORD_ANALYSIS.md) | 마케팅 키워드 분석 페이지(검색량·성별/연령·트렌드·연관키워드) 설계 |
| [14_SHOPPING_RANK.md](./14_SHOPPING_RANK.md) | 쇼핑 순위추적(openapi shop.json · 상품/업체 × 키워드) — 플레이스 순위추적 미러 |
| [15_SELLER_POWER.md](./15_SELLER_POWER.md) | 셀러력 — 쇼핑 상품 SEO·지수 경쟁 비교(5축·확장 수집·서버 계산·패널/웹 UI) |
| [16_DEPLOYMENT.md](./16_DEPLOYMENT.md) | 배포 — jcurve2(crm) 서버에 PHP 8.3 공존(apxs mod_proxy_fcgi + php83-fpm)·MariaDB·vhost·런북 |
| [17_GIT_WORKFLOW.md](./17_GIT_WORKFLOW.md) | Git 워크플로 — 여러 CLI 동시작업(worktree)·선택 커밋·master 배포전용·롤백 |
| [18_VENDOR_DISPATCH.md](./18_VENDOR_DISPATCH.md) | 외부 발주 — 업체 관리(API/구글시트)·상품별 배분(비율/수량)·매핑·승인 시 자동 전송 |
| [19_CAFE_SEED_CRAWLER.md](./19_CAFE_SEED_CRAWLER.md) | 카페 글감 수집 — 인기글·댓글 크롤 → DB → 글밥 전환 → AI(Gemini) 재작성·사용 이력 |
| [20_SEARCH_CONSOLE.md](./20_SEARCH_CONSOLE.md) | 검색 유입 분석 — 구글 서치 콘솔 연동(서비스 계정·일별 수집 크론·관리자 대시보드) |
| [21_SEO_SLUG_SITEMAP.md](./21_SEO_SLUG_SITEMAP.md) | SEO 공유 슬러그(/keyword/여름브라)·robots·사이트맵(인덱스+섹션, 추적 슬롯 제외) |
| [22_KEYWORD_CONTENT_HUB.md](./22_KEYWORD_CONTENT_HUB.md) | 키워드 콘텐츠 허브 — 카테고리→키워드 수집(시드·지역조합·데이터랩·GSC)·승인·발행→허브 페이지(/keywords)·AEO 문서·AI 인사이트·상호 추천(Phase 0~3 + 대량 시드 구현) |
| [23_GA4_INSIGHTS_PACKAGE.md](./23_GA4_INSIGHTS_PACKAGE.md) | GA4 상세 분석 대시보드(/admin/traffic-stats) — 이식형 패키지 jcurve/ga4-insights(유입·랜딩/이탈·기기·이벤트·실시간, 기간대비) |
| [24_NEW_BUSINESS.md](./24_NEW_BUSINESS.md) | 신규 개업 — 지방행정 인허가 공공데이터 수집 + 네이버 플레이스 등록 여부(관리자 **열람 전용**, 광고 발송 금지) |
| [25_SHOPPING_KEYWORD_EXPOSURE.md](./25_SHOPPING_KEYWORD_EXPOSURE.md) | 쇼핑 노출 키워드 분석(/admin/shop-keyword — 운영자 전용, 2026-07-21 콘솔에서 이동) — 핵심 키워드+상품 → 키워드 추출·2~5단어 조합 → 쇼핑 상위 N위 노출 판정(비동기 배치 폴링). **유입 조작·회전 리다이렉트는 범위 밖** |
| [26_COUPON.md](./26_COUPON.md) | 쿠폰 — 정액/정률 할인, 관리자 발행·전체 발급·마이페이지 다운로드(콘솔 메뉴 없음), 셀프마케팅 주문 적용·취소 복원. **라우트는 routes/coupon.php 별도 파일** |
| [27_PRODUCT_PACKAGE.md](./27_PRODUCT_PACKAGE.md) | 마케팅 상품 — 고정 수량·기간 패키지(고객은 그대로 주문, 서버 강제) + 상품 복제(필드·배분 포함, 비활성 시작) |
| [research/research-crm-smartplace-inventory.md](./research/research-crm-smartplace-inventory.md) | crm ads/smartplace 이식 자산 인벤토리 |
| [tasks/](./tasks/) | 작업 태스크 |
