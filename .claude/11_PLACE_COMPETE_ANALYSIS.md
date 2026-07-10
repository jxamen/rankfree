# 11. 플레이스 경쟁 분석 (SEO 점수 + 순위추적)

> crm `ads/smartplace/place_score.php` 이식. **경쟁 분석 = SEO 점수 분석 + 순위추적**.
> 원본 인벤토리는 [research/research-crm-smartplace-inventory.md](./research/research-crm-smartplace-inventory.md), 순위추적은 [순위 추적 슬롯]과 통합.

## 개념

- **트랙 = (키워드 × 내 플레이스)** — 순위추적 슬롯(`place_rank_slots`)을 트랙으로 재사용한다.
- 트랙 1개를 "분석"하면: 같은 키워드 pcmap 상위 N(경쟁셋) 수집 → 내 매장 + 상위 상세 수집 → 지표 점수화 → 일자별 저장.
- 점수는 **관측 신호 기반 자체 추정치**(N1/N2/N3, D1~D10). "네이버 공식 점수" 아님.

## 점수 산식 (고정 — `App\Domain\Place\PlaceScorer`)

| 코드 | 지표 | 계산 | N2 가중치 | 산출대상 |
|------|------|------|-----------|----------|
| D1 | 방문자(영수증) 리뷰수 | `absP90` = 100·min(1, ln(1+x)/ln(1+P90)) | 0.18 | 전체 |
| D2 | 블로그/카페 리뷰수 | `absP90` | 0.09 | 전체 |
| D3 | 예약자 리뷰수 | `absP90`; 없으면 방문맥락 예약이용(bv_raw) | 0.07 | booking 업종/상위10 |
| D4 | 평점(베이지안) | `(v·s+20·μ)/(v+20)` → `100·clamp((st−3.5)/1.5)` | 0.12 | 평점·방문자 존재 |
| D5 | 저장수 | `absP90` | 0.08 | **restaurant만** |
| D7 | 정보충실성 | 체크리스트 가중합(아래) | 0.14 | 상세수집 매장 |
| D8=N1 | 키워드 일치 | L.30/B.30/T.30/M.10 | (N1로 승격) | 전체 |
| D9 | 최근 리뷰 활동성 | `absP90(rec_raw)` (4주 신규) | 0.20 | 내+상위10 |
| D10 | 리뷰어 영향력 | `pct(auth_raw)` Hazen 백분위 | 0.12 | 내+상위10 |

- **N1 유사도** = D8 = keywordMatch(지역 L / 업종 B / 대표키워드 T / 상호 M, 결측 재정규화).
- **N2 관련성** = 위 가중평균(결측 항목은 가중치에서 제외 후 재정규화 — `weighted`).
- **N3 랭킹** = `100·(1 − ln(min(rnk,300))/ln 301)` (1위≈100, 로그 감쇠). 순위추적의 실제 순위 사용.
- **정보충실성 D7 체크리스트**: 메뉴/시술(w1.5) · 대표키워드(1.5) · 찾아오는길(1.5) · 대표사진(1.0) · 영업시간공개(1.0) · 예약연결(1.0) · 가격공개(1.0) · 필수정보완성(1.0) · 톡톡/챗봇(0.8) · 스타일리스트(0.8) · 편의시설(0.7) · 부가카테고리(0.5) · 결제수단(0.5). `avail` 항목만 `100·Σ(w·grade)/Σ(w)`.

## 데이터 수집 (`App\Domain\Place\PlaceRankChecker`)

- `serpFetch(keyword, cat, myPid, topN)` — pcmap GraphQL 최대 6p × 50 순회 → 상위 topN 리스트 + `my_rank` + `total`. **nCaptcha 토큰 필수**(없으면 blocked). 좌표 서울 고정.
  - serp item: id·name·visitor_cnt·blog_cnt·booking_cnt·save_cnt·review_score·tags·address·rnk.
- `placeDetailFull(placeId, cat)` — m.place `/home` SSR `__APOLLO_STATE__` 전체 파싱(로그인 불필요).
  - `PlaceDetailBase:{pid}` → name·category·categoryCount·visitorReviewsTotal·visitorReviewsScore·cafeBlogReviewsTotal·road·conveniences·paymentInfo·hideBusinessHours·hidePrice·talktalkUrl·chatBotUrl·missingInfo.
  - 노드 카운트: `Menu:`→menu_cnt, `PlaceDetailTopPhotoItem:`→photo_cnt, `Stylist:`→stylist_cnt.
  - 전역: keywordList→tags/keyword_cnt, bookingBusinessId→has_booking, placePlus→place_plus, VisitorReviewStatsResult.analysis→review_kw.
- D9/D10(리뷰 주별/영향력)은 **task D**에서 `spa_review_weekly` 이식(방문자 최신/추천 + 블로그 최신 3URL 병렬, 내+상위10만).

## 저장 스키마 (task B)

순위추적 슬롯을 트랙으로 재사용 + 일별 스냅샷 append(멱등 upsert):
- `place_seo_serp` (slot_id, ymd, rnk, place_id, name, visitor_cnt, blog_cnt, booking_cnt, save_cnt, review_score, tags, address, is_mine, list_total) · UNIQUE(slot_id,ymd,rnk,is_mine)
- `place_seo_scores` (slot_id, place_id, ymd, d1..d10, n1, n2, n3, avail_mask, tier, is_mine) · UNIQUE(slot_id,place_id,ymd)
- `place_seo_daily` (place_id, ymd, 상세신호 컬럼…, review_weekly, place_plus, review_keywords, review_quality, missing_labels) · UNIQUE(place_id,ymd) — place 단위 공유

## 화면 (task C · `console.compete`)

1. KPI 4: 순위 / N1 유사도 / N2 관련성 / N3 랭킹 + [분석 갱신][공유]
2. 경쟁 히트맵 비교표: 행=매장(내 매장 ⭐ 최상단), 열=일별순위 10 / 영수증 4주+총 / 블로그 4주+총 / 평점 / 정보충실성 / N1 / N2 / N3. 셀 상대음영.
3. 시계열: 내 매장 N1/N2/N3 라인(0~100).
4. 점수근거 explain 모달: N1 요소표 · N2 차원표 · 정보충실성 grid · 리뷰키워드 · 리뷰품질.
5. 이력: 일자별 카드 + 지표별 표(직전 대비 델타), 순위/점수 추이 그래프.
- 모든 기능 **api/v1/compete/\*** 동시 제공(확장·외부).

## 진행 상태

- [x] **A. 점수 엔진** — `PlaceScorer`(전 산식) + `PlaceRankChecker::serpFetch/placeDetailFull`. 실측 검증(강남 미용실 → 준오헤어 삼성역점 N1=48/N2=98.7/N3=100).
- [x] B. 저장/일별 스냅샷 + 이력
- [x] C. 웹 비교표·explain + API
- [ ] D. 리뷰 주별수집(D9/D10)·품질·블로그
