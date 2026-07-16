# 20. 검색 유입·방문 분석 — 구글 서치 콘솔 + GA4 연동

> Site Kit(워드프레스) 류의 검색 성과·방문 대시보드를 관리자에 내장. (2026-07-15 도입)

## GA4 방문 분석 (추가)

- **수집**: `App\Domain\Seo\GoogleAnalyticsService` — Analytics Data API `runReport`(`analytics.readonly`), date/channel/source/page 차원 → `ga_stats`. 어제까지 확정 데이터.
- **크론**: `ga:collect` 매일 04:10 KST. 최초 적재 `--days=400`.
- **화면**: `/admin/traffic-stats` (메뉴: 운영·매출 › 방문 분석) — 사용자·신규·세션·페이지뷰, 일별 추이, 유입 채널/소스(네이버 유입 확인 가능), 인기 페이지.
- **설정**: 환경설정 › 외부 연동 › GA4 — 속성 ID(숫자, `ga.property_id`). 같은 서비스 계정을 GA4 속성 액세스 관리에 **뷰어**로 추가.

## 구성

- **인증**: 서비스 계정 JWT — 공용 헬퍼 `App\Support\GoogleServiceAccount`(스코프별 토큰 캐시 50분). 외부 발주 구글시트(18번)와 같은 키(`GOOGLE_SERVICE_ACCOUNT_JSON`) 공유.
- **수집**: `App\Domain\Seo\SearchConsoleService::collect(days)` — Search Analytics API(`webmasters.readonly`)를 date/query/page/device 차원으로 조회해 `gsc_stats` 테이블에 upsert. GSC 데이터 지연(2~3일)을 고려해 3일 전까지 갱신, `dataState=all`.
- **크론**: `gsc:collect` 매일 04:00 KST (routes/console.php). 최초 적재는 `--days=480`(최대 16개월).
- **화면**: `/admin/search-stats` (메뉴: 운영·매출 › 검색 유입 분석 — 운영은 /admin/menus 수동 추가)
  - 요약(클릭·노출·CTR·평균순위, 노출 가중 평균) · 일별 클릭 차트 · 상위 검색어 50 · 상위 페이지 30 · 기기별 · 기간 7/28/90일 · [지금 수집]
  - 미연동 시 설정 단계 안내 카드(키 경로·서비스 계정 이메일·속성 공유·최초 적재 명령).
- **설정**: 환경설정 › 외부 연동 › 구글 서치 콘솔 — 속성(`gsc.property`, 기본 `sc-domain:rankfree.kr`).

## 인증 — OAuth(기본) + 서비스 계정(폴백)

`App\Support\GoogleToken::token(scope)` 가 단일 진입점: ① 관리자 OAuth 연동(refresh token, AppSetting `google_oauth.*` 암호화 저장) ② 서비스 계정 키 폴백.

**OAuth 연동(Site Kit 방식, 권장)** — 소셜 로그인 OAuth 클라이언트 재사용:
1. GCP(소셜 로그인 클라이언트가 있는 프로젝트): Search Console API · Google Analytics Data API **사용 설정** + OAuth 클라이언트의 승인된 리디렉션 URI에 `https://rankfree.kr/admin/google-connect/callback` 추가 (최초 1회)
2. 관리자 → 환경설정 › 외부 연동 → **[구글 계정으로 연동]** → 속성 접근 가능한 구글 계정으로 동의 → refresh token 저장(끝)
   - 라우트: admin.google-connect(redirect) / .callback / .disconnect — state 검증, `access_type=offline&prompt=consent`
3. 최초 적재 `gsc:collect --days=480` · `ga:collect --days=400`

**서비스 계정(폴백)**: `.env GOOGLE_SERVICE_ACCOUNT_JSON=키 경로` + 각 속성에 서비스 계정 이메일 공유(GSC 사용자/GA4 뷰어).

## 참고

- GSC는 **구글 검색 유입만** — 전체 방문 분석은 GA4(태그 삽입됨)에 있고, 필요 시 GA4 Data API를 같은 패턴(서비스 계정)으로 추가 연동 가능.
- 네이버 서치어드바이저는 성과 API 미제공 — 네이버 유입 분석은 자체 방문 로그로만 가능.
