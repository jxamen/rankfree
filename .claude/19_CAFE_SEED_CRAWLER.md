# 19. 카페 글감 수집 → 커뮤니티 시딩 파이프라인

> 네이버 카페 인기글(제목·본문·작성일·댓글·댓글작성일)을 수집해 DB에 저장하고,
> 커뮤니티 글밥(community_seeds)으로 전환 → 페르소나가 **AI 재작성**해 커뮤니티에 게시하며
> **사용 이력(언제·누가·어떤 AI·어떤 결과물)** 을 기록한다. (2026-07-15 구축)

## 전체 흐름

```
naver-cafe-crawler.cjs (Playwright, 멤버 세션)
  → JSON (storage/app/cafe-crawl/)
  → artisan cafe:crawl : cafe_crawl_articles / cafe_crawl_comments upsert
  → --seed : community_seeds 전환 (kind=post 본문 있는 글 / kind=comment 8자 이상 댓글)
  → community:simulate : CommunitySeed::pick → PersonaContentGenerator (Gemini/Claude 재작성)
  → CommunityPost/Comment 생성 + community_seed_usages 기록(+used_count/last_used_at)
```

## 크롤러 — scripts/naver-cafe-crawler.cjs

- **대상**: 인기글 페이지(`/f-e/cafes/{id}/popular`). UI 페이지네이션과 무관하게
  `WeeklyPopularArticleListV3.json` 이 전체(약 200건)를 한 번에 반환 = 전 페이지 커버.
- **본문**: `cafe-articleapi/v3/.../articles/{id}` → `result.article.contentHtml`(멤버 세션에서만. 비멤버는 미리보기라 null).
- **댓글**: `cafe-articleapi/v2/.../comments/pages/{n}?requestFrom=A&orderBy=asc` → `result.comments.items` + `hasNext`.
  - ⚠️ 댓글 수가 페이지 크기(100)의 배수면 마지막 페이지가 반복 반환됨 → **id Set 으로 중복 차단**(수정 완료).
- **로그인 세션**: `scripts/.naver-cafe-profile`(gitignore). **카페 멤버 계정** 필수 —
  `node scripts/naver-cafe-crawler.cjs --reset --headful` 로 1회 로그인하면 유지.
  아프니까사장이다(23611966)는 멤버만 글/댓글 읽기 가능(비멤버는 4004). 세션 만료 시 401 → 재로그인 안내 출력.
- 옵션: `--cafe <id>` `--url <카페URL>` `--max <n>` `--out-file <json경로>`(artisan 연동, CSV 생략) `--headful` `--reset` `--delay <ms>`
- 단독 실행 출력: `storage/app/cafe-crawl/cafe-{id}-popular-{일시}.json/.csv`(CSV 는 엑셀용 BOM)

## DB (2026_07_15_000002 마이그레이션)

| 테이블 | 역할 | 핵심 컬럼 |
|---|---|---|
| `cafe_crawl_articles` | 수집 원본(글) | unique(cafe_id, article_id) · title/body/writer/wrote_at/read_count/comment_count · **seed_id/seeded_at**(글밥 전환 추적) · crawled_at |
| `cafe_crawl_comments` | 수집 원본(댓글) | unique(crawl_article_id, comment_id) · parent_comment_id(대댓글) · content/wrote_at/is_deleted · **seed_id/seeded_at** |
| `community_seed_usages` | **사용 이력** | seed_id/persona_id/used_for(post·comment)/post_id/comment_id/**provider**(gemini·anthropic·fallback) · created_at=사용 시각 |
| `community_seeds` (+) | 글밥 | `last_used_at` 추가. source='cafe:{cafeId}:{articleId}[:c{commentId}]' |

날짜는 KST 수집 → UTC 저장(앱 TZ), 표시 시 `->timezone('Asia/Seoul')`.

## 명령·스케줄

- `php artisan cafe:crawl [--cafe=] [--max=] [--file=기존JSON] [--seed] [--seed-category=]`
  - node 실행(또는 --file 임포트) → upsert(재수집분은 수치·본문 갱신, 시드 연결 보존) → --seed 전환
  - 실행 상태 캐시 `cafe-crawl:running`(CafeCrawl::RUNNING_CACHE) — 어드민 버튼과 공유, finally 해제
- 스케줄: **매일 05:10 KST** `cafe:crawl --seed` (routes/console.php, `CAFE_CRAWL_SCHEDULE_ENABLED` 로 on/off)
- config: `rankfree.cafe_crawl.*` (node/php_bin/cafe_id/seed_min_comment_length)

## AI 재작성 (PersonaContentGenerator)

- 공급자: **Gemini 기본**(무료 티어, `gemini-2.5-flash`, thinkingBudget 0) / Claude 폴백 — `auto|gemini|anthropic|off`
- 설정: 어드민 **환경 설정 > AI API** 탭 — 공급자·모델·"실패 시 원문 변형 폴백" 체크박스
  (AppSetting `community.rewrite_provider/model/fallback` → SettingsServiceProvider 가 `rankfree.community.rewrite.*` 오버라이드)
- 키 등록: 같은 탭 'AI 모델 API 키'(provider=google 이 services.gemini.key 로 매핑)
- generatePost/generateComment 는 `seed_id`·`provider` 를 함께 반환 → 시뮬레이터가
  `community_seed_usages` 기록 + `last_used_at` 갱신 (used_count 는 생성 시점 증가)
- ⚠️ 폴백(원문 가벼운 변형)은 **원문 노출 위험** — 키 등록 후 폴백 off 권장

## 어드민

- **/admin/cafe-seeds** — 수집 글 목록(검색·전환/사용 필터·통계) + **지금 수집** 버튼(백그라운드 spawn, 진행중 배너)
- **/admin/cafe-seeds/{id}** — 본문·글밥 상태·**사용 이력 테이블**(시각/페르소나/결과물 링크/AI)·수집 댓글 전체
- 콘솔 메뉴 등록은 /admin/menus 에서 수동(시더 금지 — 프로젝트 규칙)

## 주의

- 수집 데이터에 닉네임 등 개인정보 포함 — 분석·소재 용도로만, 외부 공유 금지
- 재작성 프롬프트는 "원문 복붙 금지·표현 변형" 강제. 그래도 게시 전 어드민에서 결과물 점검 권장
- 네이버 API 경로/응답 구조는 변경될 수 있음 — 깨지면 이 문서의 실측 내역 기준으로 재정찰
