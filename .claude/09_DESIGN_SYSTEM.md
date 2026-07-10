# 09. 디자인 시스템 — Cal.com → Tailwind v4 토큰 매핑

> 원본: getdesign.md/cal ([../DESIGN.md](../DESIGN.md))
> 구현: `resources/css/app.css`의 `@theme` + `@layer components`
> **모든 색/타이포/radius는 아래 토큰으로만.** 하드코딩 hex 금지.

## 디자인 무드

흰 캔버스 + 검정(#111) 프라이머리 CTA의 **모노크롬 SaaS**. 브랜드 전압은 색이 아니라 (1) 디스플레이 타이포(음수 자간)와 (2) 카드 안에 실제 제품 UI 조각을 넣는 방식에서 나온다. 라이트그레이 카드, 다크 푸터로 긴 페이지를 닫는다.

## 색 토큰 → Tailwind 유틸

| 토큰 | 값 | Tailwind 유틸 | 용도 |
|---|---|---|---|
| `--color-primary` | #111111 | `bg-primary` `text-primary` | 프라이머리 CTA, 헤드라인 |
| `--color-primary-active` | #242424 | `bg-primary-active` | CTA press/hover |
| `--color-accent` | #3b82f6 | `text-accent` | 인라인 링크(희소하게) |
| `--color-ink` | #111111 | `text-ink` | 모든 헤드라인·주요 텍스트 |
| `--color-body` | #374151 | `text-body` | 본문 |
| `--color-muted` | #6b7280 | `text-muted` | 보조 텍스트 |
| `--color-muted-soft` | #898989 | `text-muted-soft` | 캡션·플레이스홀더 |
| `--color-canvas` | #ffffff | `bg-canvas` | 페이지 바닥 |
| `--color-surface-soft` | #f8f9fa | `bg-surface-soft` | 아주 옅은 구분 |
| `--color-surface-card` | #f5f5f5 | `bg-surface-card` | 피처/후기 카드 |
| `--color-surface-strong` | #e5e7eb | `bg-surface-strong` | 비활성 버튼 |
| `--color-surface-dark` | #101010 | `bg-surface-dark` | **푸터·featured 카드 전용** |
| `--color-surface-dark-elevated` | #1a1a1a | `bg-surface-dark-elevated` | 다크 위 중첩 카드 |
| `--color-on-primary` / `--color-on-dark` | #ffffff | `text-on-primary` `text-on-dark` | 검정/다크 위 텍스트 |
| `--color-on-dark-soft` | #a1a1aa | `text-on-dark-soft` | 푸터 본문 |
| `--color-hairline` | #e5e7eb | `border-hairline` | 1px 경계 |
| `--color-hairline-soft` | #f3f4f6 | `border-hairline-soft` | 거의 안 보이는 구분 |
| `--color-success/warning/error` | #10b981/#f59e0b/#ef4444 | `text-success` 등 | 시맨틱 |
| `--color-badge-orange/pink/violet/emerald` | pastel | `bg-badge-*` | 아바타·태그 액센트만 |

## radius → `rounded-*`

| 토큰 | 값 | 용도 |
|---|---|---|
| `rounded-xs` | 4px | 배지 액센트 |
| `rounded-sm` | 6px | 작은 인라인 버튼 |
| `rounded-md` | 8px | **버튼·인풋·탭** |
| `rounded-lg` | 12px | **콘텐츠 카드** |
| `rounded-xl` | 16px | 히어로 목업 카드 |
| `rounded-full` | 9999px | 아바타·필·아이콘버튼 |

## 타이포

- **본문/UI**: `font-sans` = Pretendard(한글) + Inter fallback. 400/500.
- **디스플레이 헤드라인**: `.font-display` = Manrope + Pretendard, weight 700, `letter-spacing: -0.03em`. (Cal Sans 대체)
- **코드**: `font-mono` = JetBrains Mono.
- 크기(참고): display-xl 64 / lg 48 / md 36 / sm 28, title-lg 22 / md 18 / sm 16, body 16 / sm 14, caption 13. Tailwind `text-*`로 근사, 헤드라인엔 `.font-display` 병용.

## 컴포넌트 클래스 (`@layer components`)

반복 UI는 아래 클래스를 **재사용**한다(새 인라인 스타일 남발 금지):

| 클래스 | 용도 |
|---|---|
| `.btn` + `.btn-primary` / `.btn-secondary` / `.btn-ghost` | 버튼. 크기 `.btn-sm` `.btn-lg` |
| `.card` / `.card-soft` | 흰 카드(hairline) / 라이트그레이 카드 |
| `.input` | 텍스트 입력(focus 시 ink 테두리) |
| `.badge` | 필 배지 |
| `.container-page` | 최대 1200px 중앙 컨테이너 |

예:
```html
<button class="btn btn-primary">무료로 순위 조회</button>
<div class="card p-8"> ... </div>
<input class="input" placeholder="키워드 입력">
```

## Do / Don't

**Do**
- 프라이머리 CTA·헤드라인엔 검정(`primary`/`ink`). Cal.com 버튼은 near-black.
- 헤드라인엔 `.font-display` + 음수 자간. 본문은 Pretendard.
- 라이트그레이 카드(`card-soft`)=추상적 기능 주장, 흰 카드(`card`)=실제 데이터/제품 화면.
- 페이지는 다크 푸터로 닫는다.

**Don't**
- 프라이머리 CTA에 accent/pastel 색 쓰지 않기(액션 레이어는 모노크롬).
- 디스플레이 weight 700 초과 금지(bombastic).
- 카드 radius `rounded-xl`(16px) 초과 금지(소비자앱처럼 보임).
- 다크 서피스를 푸터·featured 외에 남발 금지.
- sign 등 타 프로젝트 스타일 복사 금지.
