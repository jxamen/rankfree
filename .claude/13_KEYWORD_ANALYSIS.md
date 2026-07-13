# 13. 마케팅 키워드 분석 페이지

> 콘솔 `console.keyword` — 검색량·성별/연령·트렌드·연관키워드 대시보드(블랙키위 스타일 참고).
> 기존 키워드 API 자산(`NaverKeywordService` + `SearchAdWebClient`)을 웹 콘솔 페이지로 조립.

## 데이터 소스 (기존 자산 재사용)

| 소스 | 제공 |
|------|------|
| `NaverKeywordService::analyze()` (검색광고 keywordstool, HMAC) | 월간 검색량 PC/Mobile/Total · 경쟁강도(comp_idx) · 연관 키워드 10 |
| `SearchAdWebClient::keywordDetail()` (검색광고 웹 세션) | 성별·연령 분포 · 최근 12개월 월별 트렌드 · 14버킷 |

- 상세(성별·연령·트렌드)는 성공만 6시간 캐시(`kw:detail:{md5}`), 오류는 캐시 안 함.
- 자격증명(`rankfree.searchad.*`) 없으면 analyze()=null, 웹 세션 없으면 detail=null → 페이지는 빈 상태/부분 상태로 graceful 렌더.

## 파생 지표 (`KeywordAnalysisPresenter`, 순수)

- **등급(S~F)**: 월간 검색량 임계값 기반 **자체 추정**(공식 아님).
- **상업성**: 경쟁강도(낮음/중간/높음) → 상업성 %(20/45/70) 추정 → 정보성/상업성 막대.
- **예상 검색량**: 월간을 일할 비례 환산(7일/30일/일). 실측 아님.
- **월별 검색 비율**: 12개월 트렌드를 월-of-year 로 집계·비율화(계절성).
- **성별·연령·성별×연령**: `SearchAdWebClient` **userStat 14버킷(`detail['buckets']`, 성별 f×7·m×7)** 를 직접 조합 — 트렌드(monthlyProgressList)에서 뽑지 않는다. 버킷으로 성별합·연령합·**결합 피라미드**(연령별 여/남)까지 산출. buckets 없으면 사전집계(gender/age) 폴백(피라미드 없음). 연령코드(`0-12`…`50-`)→라벨 매핑.
- 유닛테스트 `tests/Unit/KeywordAnalysisPresenterTest.php`(등급·상업성·환산·월별·버킷 조합·폴백·조립).

## 공유 모듈 (키워드 ↔ 시장 분석 공용)

성별·연령·성별×연령·12개월 트렌드(+월별 PC/모바일/합계 표)·월별 계절성은 **한 모듈을 양쪽에서 재사용**한다.

- **데이터**: `KeywordAnalysisPresenter::detailModel(?array $detail)` — 검색량/연관키워드 제외한 상세 VM(has_detail·has_demo·trend·month_ratio·gender·age·pyramid). `build()`가 내부에서 이 모델을 합성.
- **뷰**: [resources/views/partials/keyword-detail.blade.php](../resources/views/partials/keyword-detail.blade.php) — `@include('partials.keyword-detail', ['d'=>$dm, 'emptyNote'=>…])`.
- **사용처**: `console.keyword`(라이브) + `console.market-show`(스냅샷 `keyword_data.detail`). 시장은 `detailModel((array)$kd['detail'])` 로 넘김.
- **연관 키워드는 모듈 미포함** — 키워드 페이지 전용(쇼핑/시장에서는 미사용).

## ⚠️ 검색광고 웹: 키워드 공백 정규화 (필수)

`ads.naver.com/apis/sa/keywordstool` 는 **공백 포함 키워드**(`강남 맛집`)에 성별/연령/월별을 **빈 배열로** 반환한다(실측). → `SearchAdWebClient::keywordDetail()` 는 요청 전 **공백 제거 + 대문자 정규화**(`mb_strtoupper(str_replace(' ',''))`, 공식 API 와 동일)해야 한다. 회귀 테스트 `tests/Feature/SearchAdWebKeywordDetailTest.php`. (증상: 성별 0%·연령/12개월 조회수 안 나옴)

## 화면 구성 · UI

- **UI 기준 = `console.market.show`(마켓 분석 상세)** — 모노크롬 CSS 막대(색 `var(--color-ink)`/`--color-muted-soft`, 트랙 `--color-surface-soft`), `.card`/`.badge`/`.card-soft` 컴포넌트. **SVG·컬러 도넛 안 씀**(마켓과 통일). 스크린샷(블랙키위)은 **데이터 구성만** 참고.
- 실데이터: 핵심지표(검색량/PC/모바일/경쟁/등급/상업) · 예상검색량 · 12개월 트렌드(막대) · 성별(스택바)·연령(가로막대)·성별×연령(피라미드) · 월별비율 · 연관키워드(표) · 정보성/상업성(스택바).
- **미연결 소스**: 발행량·포화·요일별·인기글·SERP 배치·이슈성·AI 인사이트 → `card-soft` "준비 중" 안내 1개로 통합(과장 없이).
- 상세(성별·연령·트렌드)는 검색광고 웹 세션 필요 → 없으면 검색량·경쟁·연관만 + 안내.

## 코드

| 파일 | 역할 |
|------|------|
| [app/Http/Controllers/KeywordAnalysisController.php](../app/Http/Controllers/KeywordAnalysisController.php) | `index` — analyze+detail 캐시 조립 → 뷰모델 |
| [app/Domain/Keyword/KeywordAnalysisPresenter.php](../app/Domain/Keyword/KeywordAnalysisPresenter.php) | 파생 지표 + SVG 차트(순수) |
| [resources/views/console/keyword/index.blade.php](../resources/views/console/keyword/index.blade.php) | 대시보드(검색바·요약칩·차트·락 카드) |

라우트: `console.keyword` — [routes/web.php](../routes/web.php). 메뉴: PermissionSeeder `console.keyword`(🔍).

## 후속 (플레이스홀더 → 실데이터)

- **발행량/포화**: 블로그·카페 문서 수 수집(openapi search 또는 크롤) 연결 → 발행량·포화지수·연관키워드 발행량 컬럼.
- **트렌드 성별/연령 필터**: 현재 monthly 는 total-only. 데모별 월별은 buckets 확장 필요.
- **인기글/SERP 액션 배치**: SERP 파싱 수집기 필요(순위체크 인프라 재사용 가능).
- **AI 인사이트**: LLM 연동(검색의도·경쟁·콘텐츠 방향).
