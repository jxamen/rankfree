# 09. 디자인 시스템 — Coinbase → Tailwind v4 토큰 매핑

> 원본: getdesign.md/coinbase ([../DESIGN.md](../DESIGN.md)) — **2026-07-12 Cal.com에서 전환**
> 구현: `resources/css/app.css`의 `@theme` + `@layer components`
> **모든 색/타이포/radius는 아래 토큰으로만.** 하드코딩 hex 금지.
> 토큰 **이름**은 Cal.com 시절 그대로 유지(뷰 수정 없이 값만 리매핑). 이전 팔레트 백업: `.claude/backup/DESIGN-calcom-20260712.md`, `app-css-calcom-20260712.css`

## ⛔ 폰트 크기 규칙 (절대) — 인라인 px 금지

- **`style="font-size:NNpx"` (px 리터럴)를 어떤 뷰에서도 쓰지 않는다.** 위반 = 리뷰 실패.
- 폰트 크기는 **디자인 토큰(`--fs-*`)** 또는 **유틸 클래스(`.fs-*`)** 로만 지정한다.
- **시스템 전체 최소/스케일은 `app.css @theme` 의 `--fs-*` 한 곳에서만 조절** → 전 페이지 동시 반영.

| 토큰 / 클래스 | 값 | 용도(대략) |
|---|---|---|
| `--fs-xs` / `.fs-xs` | 0.875rem (14px) | **시스템 최소** — 라벨·보조·배지·표 |
| `--fs-sm` / `.fs-sm` | 0.9375rem (15px) | 본문·소제목 |
| `--fs-base` / `.fs-base` | 1rem (16px) | 강조 본문 |
| `--fs-md` / `.fs-md` | 1.125rem (18px) | 카드 제목 |
| `--fs-lg` / `.fs-lg` | 1.3125rem (21px) | 섹션 헤딩 |
| `--fs-xl` / `.fs-xl` | 1.5625rem (25px) | 페이지 헤딩 |
| `--fs-2xl` / `.fs-2xl` | 1.9375rem (31px) | 디스플레이 |
| `--fs-3xl` / `.fs-3xl` | 2.75rem (44px) | 히어로 |

- 인라인 style 안에서 크기가 필요하면 `style="font-size:var(--fs-xs)"` 처럼 **토큰 참조**(px 리터럴 X).
- 크기를 키우려면: `--fs-xs` 등 토큰 값만 수정하고 `npm run build`.

## 디자인 무드

**차분한 금융 브랜드(institutional)** — 흰 캔버스 + **Coinbase Blue(#0052ff) 단일 브랜드 컬러**. 파랑은 프라이머리 CTA·인라인 링크·포커스 링에만 희소하게. 그 외는 쿨그레이 스케일 + 다크 히어로 밴드(#0a0b0d)로 페이지 리듬을 만든다. **모든 CTA는 pill(100px)**, 카드 radius는 16~24px, 디스플레이 타이포는 **weight 400 + 음수 자간**(트레이딩 앱의 요란함이 아니라 편집 디자인의 침착함).

## 색 토큰 → Tailwind 유틸

| 토큰 | 값 | Tailwind 유틸 | 용도 |
|---|---|---|---|
| `--color-primary` | **#0052ff** | `bg-primary` `text-primary` | 프라이머리 CTA·링크·포커스 (Coinbase Blue) |
| `--color-primary-active` | #003ecc | `bg-primary-active` | CTA press/hover |
| `--color-primary-disabled` | #a8b8cc | — | 비활성 CTA(파랑 페이드) |
| `--color-accent` | #4d7ce4 | `text-accent` | 그래프·링크 — primary보다 한 톤 부드럽게(면적 큰 요소 눈부심 방지) |
| `--color-ink` | #0a0b0d | `text-ink` | 헤드라인·주요 텍스트 |
| `--color-body` | #454b57 | `text-body` | 본문 — Coinbase 원본(#5b616e)은 콘솔에서 흐릿해 진하게 조정 |
| `--color-muted` | #646b76 | `text-muted` | 보조 텍스트(조정값) |
| `--color-muted-soft` | #8d929b | `text-muted-soft` | 캡션·플레이스홀더(조정값) |
| `--color-canvas` | #ffffff | `bg-canvas` | 페이지 바닥 |
| `--color-surface-soft` | #f7f7f7 | `bg-surface-soft` | 옅은 교차 밴드 |
| `--color-surface-card` | #f7f7f7 | `bg-surface-card` | 소프트 카드(card-soft) |
| `--color-surface-strong` | #eef0f3 | `bg-surface-strong` | **세컨더리 버튼·배지·아이콘 플레이트 필** |
| `--color-surface-dark` | #0a0b0d | `bg-surface-dark` | 다크 히어로·featured 카드 |
| `--color-surface-dark-elevated` | #16181c | `bg-surface-dark-elevated` | 다크 위 중첩(제품 목업) 카드 |
| `--color-on-primary` / `--color-on-dark` | #ffffff | `text-on-primary` `text-on-dark` | 파랑/다크 위 텍스트 |
| `--color-on-dark-soft` | #a8acb3 | `text-on-dark-soft` | 다크 위 보조 텍스트 |
| `--color-hairline` | #dee1e6 | `border-hairline` | 1px 경계 |
| `--color-hairline-soft` | #eef0f3 | `border-hairline-soft` | 거의 안 보이는 구분 |
| `--color-success` | #2fa476 | `text-success` | 상승/성공 — **텍스트·상태색만, 배경 금지** (원색 #05b169에서 완화) |
| `--color-warning` | #dfa63c | `text-warning` | 주의 (완화값) |
| `--color-error` | #d05560 | `text-error` | 하락/오류 — 텍스트·상태색만 (원색 #cf202f에서 완화) |
| `--color-badge-orange/pink/violet/emerald` | #e0a94e/#e07bb0/#9d82e8/#48ad88 | `bg-badge-*` | **데이터 시각화 전용**(등급·차트 구분) — 시스템 외 확장, CTA 금지. 그래프 눈부심 방지 위해 파스텔로 완화 |

> **다크 모드(`.theme-dark`)**: 텍스트·시맨틱·차트색 전부 다크 전용 값으로 재매핑(app.css 참조). 순백·원색 금지 — ink #e2e5ea, primary #3773f5, accent #5b82d8 등.

## radius → `rounded-*` (Coinbase 스케일)

| 토큰 | 값 | 용도 |
|---|---|---|
| `rounded-xs` | 4px | 인라인 태그 |
| `rounded-sm` | 8px | 컴팩트 행 |
| `rounded-md` | 12px | **인풋** |
| `rounded-lg` | 16px | **콘텐츠 카드**(.card 기본) |
| `rounded-xl` | 24px | 피처 카드·제품 목업·프라이싱 |
| `--radius-pill` | 100px | **모든 CTA 버튼·검색 필·배지** |
| `rounded-full` | 9999px | 아이콘 서클·아바타 |

**Sharp corner 금지** — 인터랙션=pill, 컨테이너=16~24px, 아이콘=full circle.

## 타이포

- **본문/UI**: `font-sans` = Pretendard(한글) + Inter fallback. 400/600. (CoinbaseSans 대체)
- **디스플레이 헤드라인**: `.font-display` = Inter + Pretendard, **weight 400**, `letter-spacing: -0.02em`. (CoinbaseDisplay 대체 — **display를 bold로 만들지 않는 것이 시그니처**)
- **숫자(가격·지표·순위)**: `font-mono` = JetBrains Mono, weight 500. (CoinbaseMono 대체 — "모든 숫자는 mono" 원칙, 점진 적용)
- 크기(참고): display-mega 80 / xl 64 / lg 52 / md 44 / sm 36, title-lg 32 / md 18(600) / sm 16(600), body 16 / sm 14, caption 13.

## 컴포넌트 클래스 (`@layer components`)

반복 UI는 아래 클래스를 **재사용**한다(새 인라인 스타일 남발 금지):

| 클래스 | 용도 |
|---|---|
| `.btn` + `.btn-primary`(파랑 pill) / `.btn-secondary`(surface-strong 필, 보더 없음) / `.btn-ghost` | 버튼 — **전부 pill**. 크기 `.btn-sm` `.btn-lg` |
| `.btn-accent` | = `.btn-primary` (단일 브랜드 컬러라 동일 파랑) |
| `.card` / `.card-soft` | 흰 카드(hairline, 16px) / 소프트 그레이 카드 |
| `.input` | 텍스트 입력(12px radius, focus 시 **Coinbase Blue** 링) |
| `.badge` | pill 배지(surface-strong 필) |
| `.container-page` | 최대 1200px 중앙 컨테이너 |

예:
```html
<button class="btn btn-primary">무료로 순위 조회</button>
<div class="card p-8"> ... </div>
<input class="input" placeholder="키워드 입력">
```

## 홈페이지 (다크 히어로 밴드 리듬)

홈페이지(`welcome.blade.php`)는 화이트 편집 섹션 ↔ 소프트 그레이 밴드 ↔ **풀블리드 다크 히어로(#0a0b0d)** 의 3모드 로테이션이 Coinbase 시그니처. 기존 expo.dev 장식 요소는 토큰 리매핑으로 Coinbase 톤을 따라간다:

| 이름 | 역할 |
|---|---|
| `.glow-orb` | blur 글로우 오브 — `color-mix(in srgb, var(--color-accent) N%, transparent)` |
| `.text-gradient` | primary(파랑)→violet 그라데이션 텍스트(헤드라인 키워드 1곳에만) |
| 터미널 카드 | 맥 도트 헤더 + `font-mono` — 히어로 API 티저 |
| `<x-card-bg>` | 카드 배경 SVG 장식([card-bg.blade.php](../resources/views/components/card-bg.blade.php)). `pattern="dots|grid|rings|gradient"` |

**상단 메가 메뉴 (NAVER Cloud 스타일)**: `.nav-item` + `.nav-mega` + `.nav-mega-link` + `.badge-update`. hover 색은 primary(파랑). **정적 링크** — 사이트 상단 메뉴는 DB 미연동(DB 메뉴관리는 콘솔 사이드바 전용).

**다크 테마 스코프**: `.theme-dark`(app.css) — 다크 값 재매핑(canvas #0a0b0d, 카드 #16181c). **Coinbase는 다크 위에서도 CTA가 파랑**(흰 버튼 반전 아님). 페이지에서 `@section('theme-dark', '1')` 선언으로 켠다.

## Do / Don't

**Do**
- 프라이머리 CTA·인라인 브랜드 링크·포커스 링에만 Coinbase Blue. **밴드당 파랑 1~2곳**.
- 모든 CTA는 pill(100px), 아이콘 플레이트는 full circle.
- 디스플레이는 weight 400 유지 + 음수 자간.
- 숫자·지표는 `font-mono`로(점진 적용).
- 다크 히어로 밴드 ↔ 화이트 섹션 로테이션으로 페이지 리듬.
- 상승=success(#05b169)·하락=error(#cf202f)는 **텍스트 색으로만**.

**Don't**
- 두 번째 브랜드 컬러 도입 금지 — badge 파스텔은 데이터 시각화(등급·차트) 전용, CTA·브랜드 표면 금지.
- 디스플레이 bold(700+) 금지 — 브랜드 톤이 바뀐다.
- 그림자 티어 추가 금지 — `--shadow-card`(0 4px 12px 4%) 단일 티어.
- CTA에 sharp corner(0px) 금지.
- success/error를 버튼 배경으로 쓰지 않는다(텍스트 전용).
- sign 등 타 프로젝트 스타일 복사 금지.
