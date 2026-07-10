# 네이버 검색광고 — 공식 API + 웹 콘솔 통합 참조 (rankfree)

> 작성 2026-07-10. 공식 문서(naver.github.io/searchad-apidoc)·php-sample + 웹 콘솔 실캡처 기반.
> 자격증명/계정 PII 미포함. 자격증명은 `.env`(NAVER_SEARCHAD_*)에만.

## 0. 두 갈래 요약

| 데이터 | 소스 | 인증 | rankfree |
|---|---|---|---|
| 연관키워드·월간검색량·경쟁도·클릭지표·예상실적 | **공식 API** `api.searchad.naver.com` | HMAC(만료 없음) | `App\Domain\SearchAd\SearchAdClient` |
| **성별·연령별 비율, 월별 검색 트렌드** | **웹 콘솔** `ads.naver.com/apis` | 쿠키 세션(NID_AUT/NID_SES) | 웹세션 프록시(운영 필요) |
| 요일별 트렌드, 블로그/카페 발행량, 인기글 | 별도 소스 | — | 네이버 데이터랩 + 검색 OpenAPI |

---

## A. 공식 API (HMAC) — `https://api.searchad.naver.com`

### 인증/서명 (php-sample `restapi.php`로 확정)
- `X-Signature = base64( HMAC-SHA256( SECRET, "{ms_timestamp}.{METHOD}.{path}" ) )`
  - `path` = **쿼리스트링 제외 경로** (`/keywordstool`, `/ncc/campaigns`)
  - `timestamp` = 밀리초 epoch, 서명과 `X-Timestamp` 동일값
- 헤더: `X-Timestamp`, `X-API-KEY`(액세스 라이선스), `X-Customer`(CUSTOMER_ID), `X-Signature`, `Content-Type: application/json; charset=UTF-8`

### 엔드포인트 카탈로그 (rankfree 관련)
| METHOD | PATH | 용도 | 주요 파라미터 |
|---|---|---|---|
| GET | `/keywordstool` | **연관키워드 + 월간검색량 + 경쟁도** | `hintKeywords`(콤마,최대5), `showDetail=1`, `siteId`, `biztpId`, `event`, `month` |
| POST | `/estimate/performance/{keyword\|id}` | 입찰가별 예상 노출·클릭 | `{device,key,bids:[]}` |
| POST | `/estimate/average-position-bid/{type}` | 목표순위 예상 입찰가 | `{device,items:[{key,position}]}` |
| POST | `/estimate/exposure-minimum-bid/{type}` | 최소노출 입찰가 | `{device,period,items:[]}` |
| POST | `/npla-estimate/*` | **쇼핑검색광고(NPLA)** 예상 입찰가/실적 | keyword/position |
| GET | `/stats` | 실시간 통계 | `id`/`ids`, `fields`(JSON배열문자열), `timeRange`/`datePreset`, `timeIncrement` |
| POST | `/stat-reports`, `/master-reports` | 통계/마스터 리포트(비동기 Job→TSV) | `{reportTp,statDt}` / `{item,fromTime}` |
| GET/POST/PUT/DELETE | `/ncc/campaigns\|adgroups\|keywords\|ads` | 광고 관리 CRUD | `ids`, `nccCampaignId`, `nccAdgroupId`, `?fields=` |
| GET | `/customer-links?type=MYCLIENTS`, `/ad-accounts`, `/manager-accounts` | 계정/클라이언트 | `page`,`size` |
| GET | `/billing/bizmoney` | 비즈머니 잔액 | |

**`/keywordstool` 응답 필드(RelKwdStat)**: `relKeyword`, `monthlyPcQcCnt`, `monthlyMobileQcCnt`, `monthlyAvePcClkCnt`, `monthlyAveMobileClkCnt`, `monthlyAvePcCtr`, `monthlyAveMobileCtr`, `plAvgDepth`, `compIdx`(낮음/중간/높음). ※ 소량은 `"< 10"` 문자열 → 방어 파싱.

### 레이트리밋/주의
- `429`(too many requests), `1016`(too many connections), `1014`(limit exceeded) → 백오프 재시도.
- `X-Timestamp` 서버 시각과 크게 어긋나면 인증 실패(NTP 동기화).
- **순수 키워드 리서치는 광고 관리 권한 없이도** 4헤더 + `GET /keywordstool`만으로 동작.

### rankfree 구현
- `App\Domain\SearchAd\SearchAdClient::request($method,$path,$query,$body)` — 임의 엔드포인트 서명 호출. `keywordTool([$kw])` 헬퍼.
- 검증(2026-07-10): `/keywordstool` 200(여름원피스 PC 14,100·모바일 53,400·경쟁 높음·연관 1,200), `/ncc/campaigns` 200.

---

## B. 공식 API에 **없는** 기능 → 웹 콘솔 (`ads.naver.com/apis`)

### 인증 = 쿠키 세션
- **NID_AUT + NID_SES**(네이버 통합로그인) 두 쿠키가 인증의 전부(앞선 teardown 실측). 헤더 `x-ad-customer-id`는 계정 스코프.
- Playwright 자동 로그인 확인(2026-07-10): 스텔스 옵션(`--disable-blink-features=AutomationControlled`, `navigator.webdriver` 우회, 느린 타이핑)으로 **캡차 없이 로그인 성공** → `.../dashboard` 도달, NID_AUT/NID_SES 확보. 세션 만료 시 재로그인 필요.
- ⚠️ 비공식 웹 내부 API — 네이버 변경 시 깨짐. 서버 데이터센터 IP는 차단 가능성 있어 세션·프록시 전략 필요.

### 핵심: 성별·연령·월별 트렌드
```
GET https://ads.naver.com/apis/sa/keywordstool
    ?format=json&includeHintKeywords=0&showDetail=1&keyword={KW}
```
응답 `keywordList[0]` (실캡처, 여름원피스):
- **`userStat`** — 성별×연령 14버킷
  - `monthlyPcQcCnt[14]`, `monthlyMobileQcCnt[14]` (PC/모바일 검색수)
  - `genderType[14]` = `["f"×7, "m"×7]`
  - `ageGroup[14]` = `["0-12","13-19","20-24","25-29","30-39","40-49","50-"]` × 2(여성 다음 남성)
  - → 인덱스 i의 (성별 genderType[i], 연령 ageGroup[i]) 조합의 PC/모바일 검색수. 성별비율·연령비율 UI에 그대로 매핑.
- **`monthlyProgressList`** — 최근 12개월 트렌드
  - `monthlyProgressPcQcCnt[12]`, `monthlyProgressMobileQcCnt[12]`, `monthlyLabel[12]`(예 `2025-07`…`2026-06`)
- `relKeyword` — 키워드

> 연관키워드 그리드/절대 검색량은 **공식 API `/keywordstool`** 로 대체(이미 1,200개 확보). 웹세션은 **성별/연령/월별**만 보강하면 됨.

### 기타 유용 웹 엔드포인트 (실캡처)
| METHOD | PATH | 용도 |
|---|---|---|
| GET | `/apis/sa/api/ncc/advoost-shopping/status` | 쇼핑검색광고 자격/네이버쇼핑몰 보유여부(`hasNaverShoppingMall`, `advoostShoppingEligible`) |
| GET | `/apis/sa/format` | 캠페인/광고그룹/소재 JSON 스키마(SHOPPING 등) |
| GET | `/apis/sa/api/managed-customers/{customerId}` | 광고계정 속성(대행 관리 여부 등) |
| GET | `/apis/sa/bizmoney/account`, `/apis/dashboard/v1/adAccounts/{no}/billing/overview` | 잔액/소진 |
| POST | `/apis/dashboard/v1/adAccounts/{no}/reports/search` | 대시보드 리포트(일자별 지표) `{startDate,endDate}` |
| GET | `/apis/sa/api/search?q={KW}&exact=false&size=201` | 비즈채널/계정 내 검색 |

> ⚠️ 요일별 트렌드·블로그/카페 발행량·인기글 TOP은 이 엔드포인트들에 **없음** → 네이버 데이터랩(shoppingInsight 등) + 검색 OpenAPI로 별도 수집(앞선 분석). 쇼핑 시장/리뷰/스토어 분석은 크롤링 불가 → **확장 프로그램**.

---

## C. rankfree 통합 전략 ("여러 곳" 사용)
1. **키워드 검색량/연관/경쟁도/예상실적** → 공식 API(`SearchAdClient`). 서버에 자격증명(만료 없음) → 콘솔·확장·배치 어디서든 서버 경유 호출. 확장은 `/api/ext/keyword-analysis`(다른 세션 구현) 사용.
2. **성별/연령/월별 트렌드** → 웹세션. 서버가 NID_AUT/NID_SES를 보관·주기 갱신(세션 워머, 401 감지 재로그인)하고, 클라이언트엔 정제 결과만 프록시.
3. **안정성**: 서비스 핵심 지표는 공식 API로 확보해 웹세션이 끊겨도 유지. 웹세션 의존(성별/연령/트렌드)은 부가 카드로.

## D. 출처
- 공식 문서/샘플: naver.github.io/searchad-apidoc, github.com/naver/searchad-apidoc (php-sample restapi.php, Swagger `assets/json/*`)
- 웹 엔드포인트: ads.naver.com 로그인 후 XHR 실캡처(2026-07-10)
