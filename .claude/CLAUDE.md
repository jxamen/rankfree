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

## UI / 디자인 규칙 (Cal.com 디자인 시스템)

- **디자인 시스템은 Cal.com** — [DESIGN.md](../DESIGN.md) 원본, Tailwind 매핑은 [09_DESIGN_SYSTEM.md](./09_DESIGN_SYSTEM.md)
- **모든 색/타이포/radius는 `resources/css/app.css`의 `@theme` 토큰으로만** 사용. 하드코딩 hex 금지
- 반복 UI(버튼/카드/입력/배지)는 `@layer components`(btn/card/input/badge)로 정의된 클래스를 **재사용**. 새 인라인 스타일 남발 금지
- **sign 프로젝트의 콘솔/홈페이지 스타일을 복사하지 않는다.** 정보 구조만 참고, 스타일은 Cal.com 토큰으로 신규
- 흰 캔버스 + 검정(#111) 프라이머리 CTA(모노크롬). 파랑(accent)은 인라인 링크 등에 희소하게
- 다크 서피스는 **푸터와 featured 카드**에만. 남용 금지
- 디스플레이 헤드라인은 `.font-display`(Manrope+Pretendard, weight 700, 음수 자간). 본문은 Pretendard 400/500

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
| [research/research-crm-smartplace-inventory.md](./research/research-crm-smartplace-inventory.md) | crm ads/smartplace 이식 자산 인벤토리 |
| [tasks/](./tasks/) | 작업 태스크 |
