{{-- 개발자 문서 본문 — 공개 /developers 전용. 내용 수정은 이 파일에서(콘솔 문서는 2026-07-29 제거).
     주제별 탭(시작하기·순위추적·경쟁분석·키워드분석·상품 주문·쇼핑 유입키워드) — URL 해시(#order 등)로 특정 탭 딥링크 가능. --}}
<style>
    .doc-h2 { font-size:var(--fs-xl); line-height: 1.3; margin-top: 34px; }
    .doc-code { font-family: var(--font-mono); font-size:var(--fs-xs); }
    .doc-pre {
        font-family: var(--font-mono); font-size:var(--fs-xs); line-height: 1.65;
        background: var(--color-surface-soft); border: 1px solid var(--color-hairline);
        border-radius: var(--radius-md); padding: 14px 16px; overflow-x: auto; white-space: pre;
    }
    .doc-table { width: 100%; font-size:var(--fs-xs); }
    .doc-table th { text-align: left; padding: 8px 10px; color: var(--color-muted); font-size:var(--fs-xs); border-bottom: 1px solid var(--color-hairline); }
    .doc-table td { padding: 9px 10px; border-bottom: 1px solid var(--color-hairline-soft); vertical-align: top; }
    .doc-method { display: inline-block; font-family: var(--font-mono); font-size:var(--fs-xs); font-weight: 700; padding: 1px 7px; border-radius: 4px; }
    .m-get { background: color-mix(in srgb, var(--color-accent) 14%, transparent); color: var(--color-accent); }
    .m-post { background: color-mix(in srgb, var(--color-success) 14%, transparent); color: var(--color-success); }
    .m-del { background: color-mix(in srgb, var(--color-error) 12%, transparent); color: var(--color-error); }
    /* 주제 탭 */
    .doc-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 28px; border-bottom: 1px solid var(--color-hairline); padding-bottom: 14px; }
    .doc-tab {
        border: 1px solid var(--color-hairline); background: var(--color-canvas); color: var(--color-body,var(--color-ink));
        font-size: var(--fs-xs); font-weight: 600; padding: 8px 16px; border-radius: var(--radius-pill, 100px); cursor: pointer;
        transition: border-color .12s, color .12s, background .12s; font-family: inherit;
    }
    .doc-tab:hover { border-color: var(--color-primary); color: var(--color-primary); }
    .doc-tab.on { background: var(--color-ink); border-color: var(--color-ink); color: var(--color-canvas); }
    .doc-panel { display: none; }
    .doc-panel.on { display: block; }
</style>

<p class="mt-4 text-body" style="font-size:var(--fs-base);line-height:1.7;">
    네이버 플레이스 <b class="text-ink">순위추적</b>, <b class="text-ink">경쟁분석</b>, <b class="text-ink">키워드분석</b> 데이터와
    <b class="text-ink">마케팅 상품 주문</b>을 REST API로 제공합니다. API 키는 <a href="{{ route('console.api-keys') }}" class="text-accent">콘솔 → API 키</a>에서
    발급하며, 키마다 권한(scope)·허용기간·일일 호출 한도·허용 IP를 설정할 수 있습니다.
</p>

{{-- 주제 탭 --}}
<div class="doc-tabs" id="doc-tabs">
    <button type="button" class="doc-tab on" data-tab="start">시작하기</button>
    <button type="button" class="doc-tab" data-tab="rank">순위추적</button>
    <button type="button" class="doc-tab" data-tab="compete">경쟁분석</button>
    <button type="button" class="doc-tab" data-tab="keyword">키워드분석</button>
    <button type="button" class="doc-tab" data-tab="order">마케팅 상품 주문</button>
    <button type="button" class="doc-tab" data-tab="shop_keyword">쇼핑 유입키워드</button>
</div>

{{-- ============ 시작하기: 인증 · 오류 ============ --}}
<div class="doc-panel on" data-panel="start">
    <h2 class="font-display text-ink doc-h2">인증</h2>
    <p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;">
        모든 요청에 <code class="doc-code">Authorization: Bearer</code> 헤더(또는 <code class="doc-code">X-API-KEY</code>)로 키를 전달합니다.
    </p>
    <pre class="doc-pre mt-3">curl -H "Authorization: Bearer rk_xxxxxxxxxxxxxxxx" \
     "{{ url('/api/v1') }}/rank/slots"</pre>
    <table class="doc-table mt-4">
        <thead><tr><th style="width:120px;">항목</th><th>값</th></tr></thead>
        <tbody>
            <tr><td>Base URL</td><td><code class="doc-code">{{ url('/api/v1') }}</code></td></tr>
            <tr><td>인증 헤더</td><td><code class="doc-code">Authorization: Bearer rk_…</code> 또는 <code class="doc-code">X-API-KEY: rk_…</code></td></tr>
            <tr><td>응답 형식</td><td>JSON (UTF-8)</td></tr>
            <tr><td>호출 한도</td><td>키에 설정한 일일 한도. 한도 설정 키는 응답에 <code class="doc-code">X-RateLimit-Limit</code> / <code class="doc-code">X-RateLimit-Remaining</code> 헤더 포함</td></tr>
        </tbody>
    </table>

    <h2 class="font-display text-ink doc-h2">오류 코드</h2>
    <table class="doc-table mt-4">
        <thead><tr><th style="width:80px;">코드</th><th>의미</th></tr></thead>
        <tbody>
            <tr><td><code class="doc-code">401</code></td><td>키 없음/잘못됨 · 비활성화됨 · 유효기간 만료</td></tr>
            <tr><td><code class="doc-code">403</code></td><td>허용되지 않은 IP · 키에 없는 권한(scope)의 엔드포인트 호출</td></tr>
            <tr><td><code class="doc-code">404</code></td><td>리소스 없음 (내 소유가 아닌 슬롯·주문 포함)</td></tr>
            <tr><td><code class="doc-code">422</code></td><td>요청 파라미터 검증 실패</td></tr>
            <tr><td><code class="doc-code">429</code></td><td>일일 호출 한도 초과 · 네이버 조회 일시 제한(blocked)</td></tr>
        </tbody>
    </table>
</div>

{{-- ============ 순위추적 ============ --}}
<div class="doc-panel" data-panel="rank">
    <h2 class="font-display text-ink doc-h2">순위추적 <span class="badge border border-hairline" style="font-size:var(--fs-xs);vertical-align:middle;">scope: rank</span></h2>
    <p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;">
        키워드 × 플레이스 슬롯을 등록하면 매일 순위가 기록됩니다. <code class="doc-code">rank</code> 값
        <code class="doc-code">0</code>/<code class="doc-code">300</code>=순위밖, <code class="doc-code">-429</code>=일시 차단.
    </p>
    <table class="doc-table mt-4">
        <thead><tr><th style="width:340px;">엔드포인트</th><th>설명</th></tr></thead>
        <tbody>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/rank/slots</code></td><td>추적 슬롯 목록 + 일자별 순위 이력(<code class="doc-code">history</code>)</td></tr>
            <tr><td><span class="doc-method m-post">POST</span> <code class="doc-code">/rank/slots</code></td><td>슬롯 등록 — <code class="doc-code">place</code>(URL/ID, 필수), <code class="doc-code">keyword</code> 또는 <code class="doc-code">keywords[]</code>, <code class="doc-code">label</code>(선택)</td></tr>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/rank/resolve?place=</code></td><td>플레이스 메타 조회(업체명·카테고리·정규 URL) — 슬롯 생성 없음</td></tr>
            <tr><td><span class="doc-method m-post">POST</span> <code class="doc-code">/rank/slots/{id}/run</code></td><td>즉시 순위 갱신(당일 기록 upsert)</td></tr>
            <tr><td><span class="doc-method m-del">DELETE</span> <code class="doc-code">/rank/slots/{id}</code></td><td>슬롯 삭제</td></tr>
            <tr><td><span class="doc-method m-post">POST</span> <code class="doc-code">/rank/check</code></td><td>1회성 순위 조회(슬롯 없이) — <code class="doc-code">keyword</code>, <code class="doc-code">place</code></td></tr>
        </tbody>
    </table>
    <pre class="doc-pre mt-4">POST {{ url('/api/v1') }}/rank/slots
{"place": "https://m.place.naver.com/hairshop/1145161001", "keywords": ["강남 미용실", "역삼 미용실"]}

HTTP/1.1 201
{
  "place": {"place_id": "1145161001", "place_name": "라온헤어 강남점", "category": "hairshop"},
  "created": [{"id": 12, "keyword": "강남 미용실", "last_rank": null, "history": []}],
  "skipped": []
}</pre>

    <p class="mt-4 text-body" style="font-size:var(--fs-sm);line-height:1.7;"><b class="text-ink">순위 확인 결과(<code class="doc-code">result</code>)</b> — <code class="doc-code">run</code>·<code class="doc-code">check</code> 응답에 공통으로 실립니다.</p>
    <table class="doc-table mt-2">
        <thead><tr><th style="width:220px;">필드</th><th>설명</th></tr></thead>
        <tbody>
            <tr><td><code class="doc-code">rank</code></td><td>순위(1~). <code class="doc-code">0</code>/<code class="doc-code">300</code>=순위밖, <code class="doc-code">blocked:true</code>=차단</td></tr>
            <tr><td><code class="doc-code">found</code> · <code class="doc-code">blocked</code></td><td>노출 여부 · IP/토큰 차단 여부(bool)</td></tr>
            <tr><td><code class="doc-code">list_total</code></td><td>해당 키워드 검색 결과의 총 업체 수</td></tr>
            <tr><td><code class="doc-code">review_count</code> · <code class="doc-code">blog_review_count</code> · <code class="doc-code">save_count</code></td><td>방문자 리뷰 · 블로그 리뷰 · 저장 수</td></tr>
            <tr><td><code class="doc-code">review_score</code> · <code class="doc-code">category</code> · <code class="doc-code">tags</code></td><td>평점 · 업종 · 대표 키워드(배열)</td></tr>
        </tbody>
    </table>
    <div class="doc-block mt-4">
    <div class="doc-block-t">1회성 순위 조회 · 슬롯 목록</div>
    <pre class="doc-pre">curl -X POST {{ url('/api/v1') }}/rank/check \
  -H "Authorization: Bearer rk_..." -H "Content-Type: application/json" \
  -d '{"keyword": "강남 미용실", "place": "https://m.place.naver.com/hairshop/1145161001"}'
# → {"result": {"blocked": false, "found": true, "rank": 3, "list_total": 280,
#               "category": "미용실", "place_id": "1145161001", "place_name": "라온헤어 강남점",
#               "review_count": 1240, "blog_review_count": 320, "save_count": 890,
#               "review_score": 4.9, "tags": ["염색", "펌"]}}

curl {{ url('/api/v1') }}/rank/slots -H "Authorization: Bearer rk_..."
# → {"used": 12, "limit": 100, "slots": [
#     {"id": 12, "keyword": "강남 미용실", "place_id": "1145161001", "place_name": "라온헤어 강남점",
#      "last_rank": 3, "last_review_count": 1240, "last_checked_at": "2026-07-29T11:30:00+09:00",
#      "history": [{"date": "2026-07-28", "rank": 4}, {"date": "2026-07-29", "rank": 3}]}]}</pre>
    </div>
</div>

{{-- ============ 경쟁분석 ============ --}}
<div class="doc-panel" data-panel="compete">
    <h2 class="font-display text-ink doc-h2">경쟁분석 <span class="badge border border-hairline" style="font-size:var(--fs-xs);vertical-align:middle;">scope: compete</span></h2>
    <p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;">
        키워드 상위 노출 경쟁 업체와 내 플레이스의 SEO 신호를 비교·점수화합니다.
        N1/N2/N3·D1~D10 점수는 <b class="text-ink">랭크프리 자체 추정치</b>이며 네이버 공식 지표가 아닙니다.
    </p>
    <table class="doc-table mt-4">
        <thead><tr><th style="width:340px;">엔드포인트</th><th>설명</th></tr></thead>
        <tbody>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/compete/tracks</code></td><td>슬롯별 최신 분석 요약(N1/N2/N3·순위)</td></tr>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/compete/{slotId}</code></td><td>최신 분석 상세 — 경쟁셋 비교(<code class="doc-code">rows</code>), 내 점수 해설(<code class="doc-code">explain</code>), 추이(<code class="doc-code">series</code>)</td></tr>
            <tr><td><span class="doc-method m-post">POST</span> <code class="doc-code">/compete/{slotId}/analyze</code></td><td>분석 실행 — <code class="doc-code">detail_top</code>(기본 10). 동기 처리로 수십 초 소요, 차단 시 <code class="doc-code">429 blocked</code></td></tr>
        </tbody>
    </table>

    <p class="mt-4 text-body" style="font-size:var(--fs-sm);line-height:1.7;"><b class="text-ink">응답 구조</b> — <code class="doc-code">rows</code>(경쟁셋, <code class="doc-code">is_mine</code> 로 내 플레이스 구분) · <code class="doc-code">explain</code>(내 점수 해설: <code class="doc-code">components</code>·<code class="doc-code">seo</code>·<code class="doc-code">dims</code>) · <code class="doc-code">series</code>(일자별 N1/N2/N3·순위 추이). 분석 이력이 없으면 <code class="doc-code">{"analyzed": false, "rows": [], "mine": null, "explain": null}</code>.</p>
    <div class="doc-block mt-4">
    <div class="doc-block-t">분석 실행 → 상세 조회 → 요약</div>
    <pre class="doc-pre">curl -X POST {{ url('/api/v1') }}/compete/12/analyze \
  -H "Authorization: Bearer rk_..." -H "Content-Type: application/json" -d '{"detail_top": 10}'
# → {"blocked": false, "my_rank": 3, "total": 280, "competitors": 10,
#    "my_score": {"n1": 82.5, "n2": 74.1, "n3": 68.0, "tier": 2, "rnk": 3,
#                 "d1": 90, "d2": 75, "…": "…", "d10": 60}}
# 차단 시 → HTTP 429 {"message": "조회 제한(nCaptcha 토큰 재발급 필요)", "blocked": true}

curl {{ url('/api/v1') }}/compete/12 -H "Authorization: Bearer rk_..."
# → {"analyzed": true, "ymd": "2026-07-29", "keyword": "강남 미용실", "place_name": "라온헤어 강남점",
#    "rows": [{"rnk": 1, "name": "A헤어", "place_id": "123", "is_mine": false,
#              "visitor_cnt": 2100, "blog_cnt": 540, "review_score": 4.8,
#              "n1": 88.0, "n2": 80.1, "n3": 72.5, "tier": 1},
#             {"rnk": 3, "name": "라온헤어 강남점", "place_id": "1145161001", "is_mine": true, "…": "…"}],
#    "explain": {"components": {"L": 12.0, "B": 8.5, "region": "강남", "core": "미용실"},
#                "seo": [{"label": "업체명 키워드", "grade": 1.0, "w": 0.15, "avail": 1}],
#                "dims": {"d1": 90, "n1": 82.5, "…": "…"}},
#    "series": [{"ymd": "2026-07-28", "n1": 81.0, "rnk": 4}, {"ymd": "2026-07-29", "n1": 82.5, "rnk": 3}]}

curl {{ url('/api/v1') }}/compete/tracks -H "Authorization: Bearer rk_..."
# → {"tracks": [{"slot_id": 12, "keyword": "강남 미용실", "place_name": "라온헤어 강남점",
#               "analyzed_ymd": "2026-07-29", "n1": 82.5, "n2": 74.1, "n3": 68.0, "rnk": 3}]}</pre>
    </div>
</div>

{{-- ============ 키워드분석 ============ --}}
<div class="doc-panel" data-panel="keyword">
    <h2 class="font-display text-ink doc-h2">키워드분석 <span class="badge border border-hairline" style="font-size:var(--fs-xs);vertical-align:middle;">scope: keyword · keyword_detail</span></h2>
    <p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;">
        <b class="text-ink">경량 분석</b>과 <b class="text-ink">상세 분석</b>은 별도 권한(scope)으로 분리되어 있어
        키를 상품별로 발급하고 기간·일일 한도를 따로 관리할 수 있습니다.
    </p>
    <table class="doc-table mt-4">
        <thead><tr><th style="width:340px;">엔드포인트</th><th>설명</th></tr></thead>
        <tbody>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/keyword?keyword=</code></td><td><b>경량</b> — 월간 검색량(PC/모바일)·경쟁강도·연관 키워드 <span class="badge" style="font-size:var(--fs-xs);padding:1px 7px;">scope: keyword</span></td></tr>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/keyword/detail?keyword=</code></td><td><b>상세</b> — 경량 지표 + 성별·연령 분포, 최근 12개월 검색량 트렌드 <span class="badge" style="font-size:var(--fs-xs);padding:1px 7px;">scope: keyword_detail</span>. 소스 일시 장애 시 <code class="doc-code">503</code></td></tr>
        </tbody>
    </table>
    <pre class="doc-pre mt-4">GET {{ url('/api/v1') }}/keyword?keyword=강남+미용실

HTTP/1.1 200
{
  "data": {
    "keyword": "강남미용실",
    "monthly_pc": 1200, "monthly_mobile": 8800, "monthly_total": 10000,
    "comp_idx": "높음",
    "related": [{"keyword": "강남역미용실", "monthly_pc": 500, "monthly_mobile": 3800, "comp_idx": "중간", "monthly_total": 4300}]
  }
}</pre>
    <pre class="doc-pre mt-3">GET {{ url('/api/v1') }}/keyword/detail?keyword=강남+미용실

HTTP/1.1 200
{
  "data": {
    "keyword": "강남미용실",
    "monthly_pc": 1200, "monthly_mobile": 8800, "monthly_total": 10000,
    "comp_idx": "높음",
    "related": [ … ],
    "detail": {
      "gender": {"female": 7200, "male": 2800, "female_pct": 72.0, "male_pct": 28.0},
      "age": [{"age": "20", "total": 3100, "pct": 31.0}, {"age": "30", "total": 4200, "pct": 42.0}],
      "monthly": [{"label": "2025-08", "pc": 1100, "mobile": 8300, "total": 9400}, … ],
      "buckets": [{"gender": "f", "age": "20", "pc": 300, "mobile": 2500, "total": 2800}, … ]
    },
    "grade": "A", "weekday": {"mon": 14.2, "sun": 12.1, "…": "…"}
  },
  "share_token": "abc123def…"
}</pre>
    <p class="mt-2 text-muted-soft" style="font-size:var(--fs-xs);">* <code class="doc-code">grade</code>(S~F 추정 등급)·<code class="doc-code">weekday</code>(요일별 검색 비율)·<code class="doc-code">share_token</code>(공개 공유 토큰)은 상세 응답에만 포함. 소스 일시 장애 시 <code class="doc-code">503</code> <code class="doc-code">{"data": null, "message": …}</code>. 월 한도 초과 시 <code class="doc-code">429</code> <code class="doc-code">{"limit_exceeded": true}</code>.</p>
</div>

{{-- ============ 마케팅 상품 주문 ============ --}}
<div class="doc-panel" data-panel="order">
    <h2 class="font-display text-ink doc-h2">마케팅 상품 주문 <span class="badge border border-hairline" style="font-size:var(--fs-xs);vertical-align:middle;">scope: order</span></h2>
    <p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;">
        판매 중인 마케팅 상품을 조회하고 외부 시스템에서 바로 주문을 접수합니다. 검증·금액 계산은
        웹 주문과 <b class="text-ink">완전히 동일한 규칙</b>이 적용됩니다(고정 수량·기간 상품은 입력값과 무관하게 고정값으로 접수).
        주문은 <code class="doc-code">pending</code>(접수) 상태로 생성되며 운영자 승인 후 진행됩니다.
        주문에 쓰는 <b class="text-ink">상품 번호(<code class="doc-code">product_id</code>)</b>는
        <code class="doc-code">GET /products</code> 응답의 <code class="doc-code">id</code>이며, 관리자 → 마케팅 상품 목록의 <b class="text-ink">번호(API)</b> 열과 같습니다.
    </p>
    <table class="doc-table mt-4">
        <thead><tr><th style="width:340px;">엔드포인트</th><th>설명</th></tr></thead>
        <tbody>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/products</code></td><td>주문 가능 상품 목록 — 상품 번호(<code class="doc-code">id</code>)·단가·과금 방식·수량/기간 제한·고정값·<code class="doc-code">orderable</code></td></tr>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/products/{id}</code></td><td>상품 상세 — 주문 입력 필드 스펙(<code class="doc-code">fields</code>: key·type·required·options·contains)</td></tr>
            <tr><td><span class="doc-method m-post">POST</span> <code class="doc-code">/orders</code></td><td>주문 생성 — <code class="doc-code">product_id</code>(필수), <code class="doc-code">quantity</code>, <code class="doc-code">days</code>, <code class="doc-code">fields</code>(객체), <code class="doc-code">user_coupon_id</code>(선택)</td></tr>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/orders</code></td><td>내 주문 목록 — <code class="doc-code">status</code>(pending·processing·completed·canceled) 필터, <code class="doc-code">page</code>/<code class="doc-code">per_page</code>(≤100)</td></tr>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/orders/{orderNo}</code></td><td>주문 단건 조회(상태 확인) — 본인 주문만</td></tr>
        </tbody>
    </table>

    <p class="mt-4 text-body" style="font-size:var(--fs-sm);line-height:1.7;"><b class="text-ink">주문 입력 규칙</b></p>
    <table class="doc-table mt-2">
        <thead><tr><th style="width:200px;">항목</th><th>규칙</th></tr></thead>
        <tbody>
            <tr><td><code class="doc-code">quantity</code></td><td>과금 방식 <code class="doc-code">total</code>(단가×수량) 상품의 수량. <code class="doc-code">min_quantity</code>~<code class="doc-code">max_quantity</code> 범위. <b>상품에 <code class="doc-code">daily_qty</code> 필드가 있으면 대신 <code class="doc-code">fields.daily_qty</code> 로 전달</b></td></tr>
            <tr><td><code class="doc-code">days</code></td><td>과금 방식 <code class="doc-code">daily</code>(단가×일수량×일수) 상품의 기간(일). <b>상품에 <code class="doc-code">start_date</code>/<code class="doc-code">end_date</code> 필드가 있으면 대신 <code class="doc-code">fields</code> 로 날짜를 전달</b>(YYYY-MM-DD, <code class="doc-code">earliest_start_date</code> 이후만)</td></tr>
            <tr><td>고정 상품</td><td><code class="doc-code">fixed_quantity</code>/<code class="doc-code">fixed_days</code> 가 있는 상품은 어떤 값을 보내도 <b>고정값으로 접수</b>. 기간 고정 + 날짜 필드 상품은 시작일만 보내면 종료일 자동 계산</td></tr>
            <tr><td><code class="doc-code">fields</code></td><td>상품 상세의 <code class="doc-code">fields</code> 스펙대로 <code class="doc-code">{"field_key": "값"}</code>. 필수 필드 누락·<code class="doc-code">contains</code> 불일치 시 <code class="doc-code">422</code> + <code class="doc-code">field: "f_{key}"</code>. 플레이스 URL 필드는 서버가 표준 m.place URL 로 정규화</td></tr>
            <tr><td>파일 필드</td><td>API 미지원 — 필수 파일 필드가 있는 상품은 <code class="doc-code">orderable: false</code>(웹 주문만 가능)</td></tr>
            <tr><td><code class="doc-code">user_coupon_id</code></td><td>보유 쿠폰 발급분 ID(선택). 할인은 서버가 재계산해 <code class="doc-code">discount_amount</code>·<code class="doc-code">total_price</code> 에 반영</td></tr>
        </tbody>
    </table>

    <pre class="doc-pre mt-4">GET {{ url('/api/v1') }}/products/12

HTTP/1.1 200
{
  "product": {
    "id": 12, "title": "네이버 플레이스 저장 리워드", "type_name": "참여형 리워드",
    "unit_price": 300, "quantity_mode": "daily",
    "min_quantity": 10, "max_quantity": 10000, "min_days": 1,
    "fixed_quantity": null, "fixed_days": null,
    "earliest_start_date": "2026-07-24", "orderable": true,
    "fields": [
      {"key": "place_url", "label": "플레이스 URL", "type": "URL", "required": true, "contains": null, "api_supported": true},
      {"key": "daily_qty", "label": "일 수량", "type": "NUMBER", "required": true, "api_supported": true},
      {"key": "start_date", "label": "시작일", "type": "DATE", "required": true, "api_supported": true},
      {"key": "end_date", "label": "종료일", "type": "DATE", "required": true, "api_supported": true}
    ]
  }
}</pre>
    <pre class="doc-pre mt-3">POST {{ url('/api/v1') }}/orders
{
  "product_id": 12,
  "fields": {
    "place_url": "https://m.place.naver.com/restaurant/1234567890",
    "daily_qty": "100",
    "start_date": "2026-07-25",
    "end_date": "2026-07-31"
  }
}

HTTP/1.1 201
{
  "order": {
    "order_no": "MO260722ABC123", "status": "pending", "status_label": "접수",
    "product": {"id": 12, "title": "네이버 플레이스 저장 리워드"},
    "quantity": 100, "days": 7,
    "unit_price": 300, "discount_amount": 0, "total_price": 210000,
    "fields": {"place_url": "…", "daily_qty": "100", "start_date": "2026-07-25", "end_date": "2026-07-31"},
    "created_at": "2026-07-22T14:30:00+09:00"
  }
}</pre>
    <pre class="doc-pre mt-3">POST {{ url('/api/v1') }}/orders   (검증 실패)

HTTP/1.1 422
{"message": "'플레이스 URL' 항목을 입력하세요.", "field": "f_place_url"}</pre>
</div>

{{-- ============ 쇼핑 유입키워드 ============ --}}
<div class="doc-panel" data-panel="shop_keyword">
    <h2 class="font-display text-ink doc-h2">쇼핑 유입키워드 <span class="badge border border-hairline" style="font-size:var(--fs-xs);vertical-align:middle;">scope: shop_keyword</span></h2>
    <p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;">
        핵심 키워드와 상품으로 <b class="text-ink">롱테일 조합을 만들어 상위 노출되는 키워드를 찾고</b>, 그 키워드를 그룹으로 나눠
        <b class="text-ink">Short URL</b>을 생성합니다. 분석을 만들면 순위 확인이 <b class="text-ink">자동으로 진행</b>되며,
        진행 상태는 조회 API로 확인합니다(규칙은 관리자 화면과 동일).
    </p>
    <table class="doc-table mt-4">
        <thead><tr><th style="width:340px;">엔드포인트</th><th>설명</th></tr></thead>
        <tbody>
            <tr><td><span class="doc-method m-post">POST</span> <code class="doc-code">/shop-keywords</code></td><td>분석 생성 + 순위 확인 자동 시작 — <code class="doc-code">core_keyword</code>(필수), <code class="doc-code">product</code>(필수: 상품 URL 또는 업체명), <code class="doc-code">threshold</code>(1~40, 기본 5), <code class="doc-code">check_method</code>, <code class="doc-code">product_info</code></td></tr>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/shop-keywords</code></td><td>내 분석 목록 — <code class="doc-code">page</code>/<code class="doc-code">per_page</code>(≤100)</td></tr>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/shop-keywords/{id}</code></td><td>진행 상태 + <b class="text-ink">상품 정보</b>(<code class="doc-code">product_url</code>·<code class="doc-code">product_id</code>·<code class="doc-code">mall_name</code>) + <code class="doc-code">exposed_keywords</code>(노출 키워드) + <code class="doc-code">short_links</code></td></tr>
            <tr><td><span class="doc-method m-post">POST</span> <code class="doc-code">/shop-keywords/{id}/short-links</code></td><td>Short URL 생성 — <code class="doc-code">group_count</code>(1~100). 노출 키워드를 그룹에 고르게 나눠 배정</td></tr>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/shop-keywords/{id}/short-links</code></td><td>Short URL 목록 — <code class="doc-code">group_no</code>·<code class="doc-code">url</code>·<code class="doc-code">keywords</code>·<code class="doc-code">hit_count</code></td></tr>
            <tr><td><span class="doc-method m-post">POST</span> <code class="doc-code">/shop-keywords/{id}/short-links/reassign</code></td><td>재배정 — <b>URL은 그대로 두고 키워드만 다시 나눔</b>(노출 키워드가 늘었을 때)</td></tr>
        </tbody>
    </table>

    <p class="mt-4 text-body" style="font-size:var(--fs-sm);line-height:1.7;"><b class="text-ink">Short URL 규칙</b></p>
    <table class="doc-table mt-2">
        <thead><tr><th style="width:200px;">항목</th><th>규칙</th></tr></thead>
        <tbody>
            <tr><td>그룹 분배</td><td>노출 키워드를 순서대로 그룹에 <b class="text-ink">고르게 나눕니다</b>(1번→2번→…→1번). <code class="doc-code">group_count</code> 는 노출 키워드 수보다 클 수 없습니다.</td></tr>
            <tr><td>재생성 제한</td><td>이미 <b class="text-ink">호출된 적이 있는 링크</b>(<code class="doc-code">hit_count &gt; 0</code>)가 있으면 생성이 막힙니다(배포한 주소가 죽지 않도록). 이때는 <code class="doc-code">/reassign</code> 으로 키워드만 교체하세요.</td></tr>
            <tr><td>실패 응답</td><td>노출 키워드 없음·그룹 수 초과·호출된 링크 존재는 <code class="doc-code">422</code> + <code class="doc-code">field</code>(<code class="doc-code">group_count</code> 또는 <code class="doc-code">short_links</code>)</td></tr>
        </tbody>
    </table>

    <p class="mt-4 text-body" style="font-size:var(--fs-sm);line-height:1.7;"><b class="text-ink">확장(브라우저) 필요 여부</b> — 서버에는 확장을 켜 둘 수 없으므로, 브라우저로만 얻을 수 있는 정보는 <b class="text-ink">요청하는 쪽에서 수집해 전달</b>합니다.</p>
    <table class="doc-table mt-2">
        <thead><tr><th style="width:200px;">항목</th><th>설명</th></tr></thead>
        <tbody>
            <tr><td><code class="doc-code">check_method</code> <br><code class="doc-code">api</code>(기본)</td><td><b class="text-ink">확장 없이 서버가 끝까지 확인</b>합니다(쇼핑 API 기준, 상위 40위까지·광고 판별 없음). 별도 조작 없이 완료될 때까지 자동 진행됩니다.</td></tr>
            <tr><td><code class="doc-code">check_method</code> <br><code class="doc-code">search</code></td><td>실제 검색화면 기준(광고 판별 가능)이지만 서버 IP가 막히면 <code class="doc-code">status: "blocked"</code> 로 멈춥니다. 이때는 <b class="text-ink">확장을 설치한 브라우저에서 해당 분석 화면을 열어 두면</b> 이어서 처리됩니다.</td></tr>
            <tr><td><code class="doc-code">product_info</code></td><td>조합 재료가 되는 <b class="text-ink">상품 제목·브랜드·상점명·가격·SEO 태그</b>는 서버가 상품 페이지를 열 수 없어 가져오지 못합니다. 요청 시 <code class="doc-code">{"title": "...", "brand": "...", "mall": "상점명", "price": 19900, "seller_tags": ["..."]}</code> 로 함께 보내면 제목 기반 조합이 만들어져 노출 적중률이 크게 올라갑니다. <code class="doc-code">mall</code>(상점명)·<code class="doc-code">price</code>(가격)는 그대로 저장돼 조회 응답의 상품 정보로 되돌려받습니다. <b>생략하면 조합이 빈약해집니다.</b></td></tr>
        </tbody>
    </table>

    <p class="mt-4 text-body" style="font-size:var(--fs-sm);line-height:1.7;"><b class="text-ink">진행 상태(<code class="doc-code">status</code>)</b> — <code class="doc-code">checking</code>(확인 중) · <code class="doc-code">done</code>(완료) · <code class="doc-code">blocked</code>(차단·중단) · <code class="doc-code">paused</code>(사용자 중단). <code class="doc-code">progress.remaining</code> 이 0 이면 확인이 끝난 것입니다.</p>

<div class="doc-block mt-4">
<div class="doc-block-t">분석 생성 → 상태 확인 → Short URL</div>
<pre class="doc-pre">curl -X POST https://rankfree.kr/api/v1/shop-keywords \
  -H "Authorization: Bearer rk_..." -H "Content-Type: application/json" \
  -d '{
    "core_keyword": "비타민c",
    "product": "https://smartstore.naver.com/mystore/products/123456",
    "threshold": 5,
    "product_info": {"title": "종근당 비타민c 고함량 스틱", "brand": "종근당", "seller_tags": ["고함량비타민"]}
  }'

# → {"analysis": {"id": 42, "product_url": "https://smartstore.naver.com/mystore/products/123456",
#                 "product_id": "123456", "mall_name": "종근당건강몰",
#                 "status": "checking", "progress": {"total": 240, "remaining": 240, ...}}}

curl https://rankfree.kr/api/v1/shop-keywords/42 -H "Authorization: Bearer rk_..."
# → {"analysis": {"status": "done", "product_id": "123456", "mall_name": "종근당건강몰",
#                 "progress": {"exposed": 12, "remaining": 0}},
#     "exposed_keywords": ["비타민c 고함량", …], "short_links": []}

curl -X POST https://rankfree.kr/api/v1/shop-keywords/42/short-links \
  -H "Authorization: Bearer rk_..." -H "Content-Type: application/json" \
  -d '{"group_count": 3}'
# → {"short_links": [{"group_no": 1, "url": "https://.../s/AbC123", "keywords": ["비타민c 고함량", …]}, …]}</pre>
</div>
</div>

<div class="card-soft mt-12 p-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
    <div>
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">API 키가 필요하신가요?</div>
        <p class="text-muted mt-1" style="font-size:var(--fs-xs);">콘솔에서 직접 발급하고 권한·기간·한도·IP를 설정할 수 있습니다.</p>
    </div>
    <a href="{{ route('console.api-keys') }}" class="btn btn-primary btn-sm">API 키 발급</a>
</div>

<script>
// 주제 탭 — 해시(#rank 등)로 딥링크, 탭 전환 시 해시 갱신
(function () {
    var tabs = document.querySelectorAll('#doc-tabs .doc-tab');
    var panels = document.querySelectorAll('.doc-panel');
    function open(name, updateHash) {
        var found = false;
        panels.forEach(function (p) { if (p.dataset.panel === name) found = true; });
        if (!found) name = 'start';
        tabs.forEach(function (t) { t.classList.toggle('on', t.dataset.tab === name); });
        panels.forEach(function (p) { p.classList.toggle('on', p.dataset.panel === name); });
        if (updateHash) { try { history.replaceState(null, '', '#' + name); } catch (e) {} }
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function () { open(t.dataset.tab, true); });
    });
    window.addEventListener('hashchange', function () {
        open((location.hash || '').replace('#', '') || 'start', false);
    });
    open((location.hash || '').replace('#', '') || 'start', false);
})();
</script>
