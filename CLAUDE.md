# CLAUDE.md

> Claude Code가 **rankfree**(플레이스·쇼핑·마케팅 분석 서비스)에서 작업할 때의 Quick Reference입니다.
> 상세 규칙은 [.claude/CLAUDE.md](.claude/CLAUDE.md), 설계는 `.claude/01~` 문서가 기준입니다.

## Project Overview

**rankfree** — 네이버 플레이스·쇼핑·마케팅 **분석 SaaS**. 무료 순위·경쟁 분석으로 사용자를 모집한 뒤, 마케팅 서비스 중개·광고로 수익화한다.

- **퍼널**: 무료 순위/경쟁 분석(진입장벽 0) → 회원가입(추적·알림) → 마케팅 서비스 중개·광고
- **원본 자산**: crm(crmpro.kr)의 `ads/smartplace` 모듈(플레이스 순위체크·경쟁분석·블로그분석·외부 순위 API)을 Laravel로 이식
- **홈페이지**: 신규 제작 / **콘솔**: 정보 구조만 참고, 스타일은 신규 디자인 시스템

## 기술 스택

- **백엔드**: PHP 8.3 + **Laravel 13** (Blade 자체완결형, 별도 프론트 SPA 없음)
- **프론트**: **Tailwind CSS v4** (`@tailwindcss/vite`) + Vite 8 — **디자인 시스템은 Coinbase 스타일**(getdesign.md/coinbase)
- **DB**: MySQL/MariaDB (utf8mb4). 로컬은 sqlite 가능
- **수집**: PHP curl(네이버 pcmap GraphQL 등) + Node/Playwright 헬퍼(nCaptcha 토큰 발급)
- **타임존**: Asia/Seoul

## 디자인 시스템 (중요)

- **Coinbase 디자인 시스템**을 Tailwind `@theme` 토큰으로 고정 — [DESIGN.md](DESIGN.md) 원본, 매핑은 [.claude/09_DESIGN_SYSTEM.md](.claude/09_DESIGN_SYSTEM.md) (2026-07-12 Cal.com에서 전환, 백업 `.claude/backup/`)
- **모든 색/타이포/radius는 토큰으로만** 사용, 하드코딩 hex 금지 (`resources/css/app.css`의 `@theme`) — 토큰 이름은 기존 그대로, 값만 Coinbase로 리매핑
- 흰 캔버스 + **Coinbase Blue(#0052ff) 단일 브랜드 컬러 CTA(pill)**, 쿨그레이 서피스, 다크 히어로 밴드(#0a0b0d)
- **모든 CTA는 pill(100px)**, 카드 radius 16~24px, 디스플레이 헤드라인 weight 400(+음수 자간), 숫자는 `font-mono`
- 본문=Pretendard(한글), 디스플레이 헤드라인=Inter+Pretendard(`.font-display`, weight 400)
- 반복 컴포넌트(btn/card/input/badge)는 `@layer components`로 정의 → 홈페이지·콘솔 공통. **sign 프로젝트 스타일 복사 금지**

## 로컬 실행 (Laragon)

```
# PHP는 PATH 미등록 — 절대경로 사용
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan ...
npm run dev      # Vite 개발 서버 (HMR)
npm run build    # 프로덕션 빌드
php artisan test # 테스트
```

## 관련 프로젝트

| 경로 | 내용 |
|------|------|
| `C:\Users\jxame\Documents\project\crm\ads\smartplace` | 이식 원본 (플레이스 순위·경쟁분석·블로그분석·순위 API) |
| `C:\Users\jxame\Documents\project\sign` | 하네스(.claude 체계)·Laravel+Tailwind 스택 참고 (스타일은 참고 안 함) |

## 현재 상태

**부트스트랩 진행 중** (2026-07-10)
- Laravel 13 + Tailwind v4 스캐폴드 완료, Cal.com `@theme` 토큰 주입 완료 (`resources/css/app.css`)
- 하네스(.claude) 세팅 중
- 크롬 확장 v0.1 (`extension/`) — 쇼핑 시장 분석(C1) + 확장용 토큰 인증 API(`/api/ext/*`) 완료 ([.claude/10_EXTENSION_SHOPPING_MARKET.md](.claude/10_EXTENSION_SHOPPING_MARKET.md))
- 다음: 콘솔 셸(Cal.com 스타일) → crm smartplace 모듈 이식 → 홈페이지

---
상세 규칙은 [.claude/CLAUDE.md](.claude/CLAUDE.md) 를 참조하세요.
