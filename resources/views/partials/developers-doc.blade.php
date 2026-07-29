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
    /* 엔드포인트 카드 — 요청표 · 요청예시 · 응답예시 · 응답필드표를 한 카드에 순서대로 */
    .ep { border: 1px solid var(--color-hairline); border-radius: 16px; overflow: hidden; margin-top: 14px; background: var(--color-canvas); }
    .ep-h { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 11px 14px; background: var(--color-surface-soft); border-bottom: 1px solid var(--color-hairline-soft); }
    .ep-p { font-family: var(--font-mono); font-size: var(--fs-xs); font-weight: 600; color: var(--color-ink); }
    .ep-s { margin-left: auto; color: var(--color-muted); font-size: var(--fs-xs); }
    .ep-b { padding: 14px; }
    .ep-t { font-size: var(--fs-xs); line-height: 1.7; color: var(--color-body); }
    .ep-l { font-size: var(--fs-xs); font-weight: 600; color: var(--color-muted); margin: 16px 0 7px; }
    .ep-l.first { margin-top: 12px; }
    .req-y { color: var(--color-error); font-weight: 600; }
    .req-n { color: var(--color-muted-soft); }
    /* 코드블록 복사 버튼 */
    .doc-copy-wrap { position: relative; }
    .doc-copy-wrap .doc-pre { padding-right: 64px; }
    .doc-copy {
        position: absolute; top: 7px; right: 7px; z-index: 1;
        border: 1px solid var(--color-hairline); background: var(--color-canvas); color: var(--color-muted);
        font-size: var(--fs-xs); font-family: inherit; padding: 3px 10px; border-radius: var(--radius-pill, 100px);
        cursor: pointer; opacity: 0; transition: opacity .12s, color .12s, border-color .12s;
    }
    .doc-copy-wrap:hover .doc-copy, .doc-copy:focus { opacity: 1; }
    .doc-copy:hover { color: var(--color-primary); border-color: var(--color-primary); }
    @media (hover: none) { .doc-copy { opacity: 1; } }
    /* 단계 흐름(쇼핑 유입키워드) */
    /* 단계는 한 줄에 하나씩(세로 스택) — 설명이 길어 가로 3열은 읽기 어렵다 */
    .flow { display: flex; flex-direction: column; gap: 10px; margin-top: 14px; }
    .flow-i { border: 1px solid var(--color-hairline); border-radius: 12px; padding: 12px 14px; }
    .flow-n { font-family: var(--font-mono); font-size: var(--fs-xs); color: var(--color-muted-soft); margin-bottom: 3px; }
</style>

<p class="mt-4 text-body" style="font-size:var(--fs-base);line-height:1.7;">
    네이버 플레이스 <b class="text-ink">순위추적</b>, <b class="text-ink">경쟁분석</b>, <b class="text-ink">키워드분석</b>,
    <b class="text-ink">쇼핑 유입키워드</b> 데이터와 <b class="text-ink">마케팅 상품 주문</b>을 REST API로 제공합니다.
    API 키는 <a href="{{ route('console.api-keys') }}" class="text-accent">콘솔 → API 키</a>에서
    발급하며, 키마다 권한(scope)·허용기간·일일 호출 한도·허용 IP를 설정할 수 있습니다.
    아래 탭에서 주제를 고르면 엔드포인트별 <b class="text-ink">요청 파라미터 · 요청 예시 · 응답 예시 · 응답 필드</b>를 순서대로 볼 수 있습니다.
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
    <div class="doc-copy-wrap mt-3"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -H "Authorization: Bearer rk_xxxxxxxxxxxxxxxx" \
     "{{ url('/api/v1') }}/rank/slots"</pre></div>
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
        네이버 플레이스의 <b class="text-ink">키워드 × 업체</b> 순위를 조회하고, 슬롯으로 등록해 매일 자동 기록합니다.
        슬롯을 만들면 일자별 순위 이력(<code class="doc-code">history</code>)이 쌓이고, 필요할 때 즉시 갱신(<code class="doc-code">run</code>)할 수 있습니다.
        슬롯 없이 지금 순위만 확인하려면 <code class="doc-code">POST /rank/check</code>를 사용합니다.
    </p>

    <table class="doc-table mt-4">
        <thead><tr><th style="width:150px;">공통 규칙</th><th>설명</th></tr></thead>
        <tbody>
            <tr><td>Base URL</td><td><code class="doc-code">{{ url('/api/v1') }}</code> · 모든 요청에 <code class="doc-code">Authorization: Bearer rk_…</code> (또는 <code class="doc-code">X-API-KEY</code>). <code class="doc-code">rank</code> 권한이 없는 키는 <code class="doc-code">403</code></td></tr>
            <tr><td><code class="doc-code">rank</code> 값</td><td><code class="doc-code">1</code> 이상 = 노출 순위 · <code class="doc-code">300</code> = 상위 300위(6페이지) 밖 · <code class="doc-code">0</code> = 조회 불가(차단이거나 키워드·대상 플레이스 미확정). <b class="text-ink">300위 밖은 항상 <code class="doc-code">300</code>이며 <code class="doc-code">0</code>이 아닙니다</b> · <code class="doc-code">blocked: true</code> 이면 순위 미확정(<code class="doc-code">rank</code>는 <code class="doc-code">0</code>, 기록 저장 안 함)</td></tr>
            <tr><td>슬롯 한도</td><td><code class="doc-code">used</code>는 플레이스 + 쇼핑 순위추적 <b class="text-ink">합산</b> 사용량(<b class="text-ink">활성 슬롯만</b> 집계 — 자동 중단된 슬롯은 빠짐). <code class="doc-code">limit</code>이 <code class="doc-code">-1</code> 이면 무제한. 한도 초과 등록은 <code class="doc-code">422</code></td></tr>
            <tr><td><code class="doc-code">history</code></td><td>이력을 함께 로드하는 응답(<code class="doc-code">GET /rank/slots</code>, <code class="doc-code">run</code>)에서만 배열이고, 슬롯 등록(<code class="doc-code">POST /rank/slots</code>) 응답에서는 <code class="doc-code">null</code></td></tr>
            <tr><td>소요 시간</td><td><code class="doc-code">run</code>·<code class="doc-code">check</code>는 네이버 실시간 조회(최대 6페이지 · 페이지 간 지연)로 <b class="text-ink">수 초~수십 초</b> 걸립니다. 클라이언트 타임아웃을 넉넉히 두세요</td></tr>
            <tr><td>소유권</td><td>내 소유가 아닌 슬롯 <code class="doc-code">id</code>로 <code class="doc-code">run</code>·<code class="doc-code">DELETE</code> 호출 시 <code class="doc-code">403</code></td></tr>
        </tbody>
    </table>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/rank/slots</code>
            <span class="ep-s">추적 슬롯 목록 + 사용량</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">내 계정의 플레이스 순위추적 슬롯을 최신 등록순으로 모두 반환합니다(자동 중단된 슬롯 포함). 각 슬롯에는 일자별 순위 이력(<code class="doc-code">history</code>, 날짜 오름차순)이 포함됩니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <p class="ep-t">없음(인증 헤더만 필요).</p>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl {{ url('/api/v1') }}/rank/slots \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "used": 12,
  "limit": 100,
  "slots": [
    {
      "id": 128,
      "keyword": "강남 미용실",
      "place_id": "1145161001",
      "place_name": "라온헤어 강남점",
      "place_url": "https://m.place.naver.com/place/1145161001/home",
      "label": "강남점",
      "category": "hairshop",
      "last_rank": 3,
      "last_review_count": 1240,
      "last_checked_at": "2026-07-29T11:30:00+09:00",
      "history": [
        {"date": "2026-07-28", "rank": 4},
        {"date": "2026-07-29", "rank": 3}
      ]
    }
  ]
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">used</code></td><td>int</td><td><b class="text-ink">활성 슬롯 수</b>(플레이스 + 쇼핑 합산). 자동 중단된 슬롯은 세지 않으므로 <code class="doc-code">slots[]</code> 개수보다 작을 수 있습니다</td></tr>
                    <tr><td><code class="doc-code">limit</code></td><td>int</td><td>등급별 슬롯 한도(추천 보너스 포함). <code class="doc-code">-1</code>=무제한</td></tr>
                    <tr><td><code class="doc-code">slots[]</code></td><td>array</td><td>슬롯 목록(최근 등록순). 자동 중단된 슬롯도 포함되며, <b class="text-ink">활성 여부를 나타내는 필드는 현재 응답에 없습니다</b></td></tr>
                    <tr><td><code class="doc-code">slots[].id</code></td><td>int</td><td>슬롯 ID — <code class="doc-code">run</code>·<code class="doc-code">DELETE</code> 경로에 사용</td></tr>
                    <tr><td><code class="doc-code">slots[].keyword</code></td><td>string</td><td>추적 키워드</td></tr>
                    <tr><td><code class="doc-code">slots[].place_id</code></td><td>string|null</td><td>네이버 플레이스 ID(숫자). 업체명으로만 등록한 경우 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">slots[].place_name</code></td><td>string|null</td><td>업체명(등록 시 자동 조회)</td></tr>
                    <tr><td><code class="doc-code">slots[].place_url</code></td><td>string|null</td><td>정규 모바일 플레이스 URL(<code class="doc-code">m.place.naver.com/place/{id}/home</code>)</td></tr>
                    <tr><td><code class="doc-code">slots[].label</code></td><td>string|null</td><td>등록 시 지정한 그룹 라벨</td></tr>
                    <tr><td><code class="doc-code">slots[].category</code></td><td>string</td><td>업종 키(<code class="doc-code">hairshop</code>·<code class="doc-code">restaurant</code>·<code class="doc-code">place</code> 등). 판별하지 못했으면 <code class="doc-code">place</code>가 들어가며 <code class="doc-code">null</code>이 되지 않습니다</td></tr>
                    <tr><td><code class="doc-code">slots[].last_rank</code></td><td>int|null</td><td>마지막 조회 순위. 아직 조회 전이면 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">slots[].last_review_count</code></td><td>int|null</td><td>마지막 조회 시점의 방문자 리뷰 수</td></tr>
                    <tr><td><code class="doc-code">slots[].last_checked_at</code></td><td>string|null</td><td>마지막 확인 시각(ISO 8601, KST)</td></tr>
                    <tr><td><code class="doc-code">slots[].history[]</code></td><td>array|null</td><td>일자별 순위 기록(오름차순)</td></tr>
                    <tr><td><code class="doc-code">slots[].history[].date</code></td><td>string</td><td>기록 일자(<code class="doc-code">YYYY-MM-DD</code>)</td></tr>
                    <tr><td><code class="doc-code">slots[].history[].rank</code></td><td>int</td><td>그날의 순위</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-post">POST</span>
            <code class="ep-p">/rank/slots</code>
            <span class="ep-s">슬롯 등록(키워드 다건)</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">플레이스 1곳에 키워드 N개를 한 번에 등록합니다. 업체명·업종·정규 URL은 서버가 자동 조회해 채웁니다. 이미 추적 중인 키워드는 생성하지 않고 <code class="doc-code">skipped</code>로 돌려줍니다. 등록만 하고 순위 조회는 하지 않으므로 <code class="doc-code">last_rank</code>는 <code class="doc-code">null</code>이며, 바로 순위를 얻으려면 이어서 <code class="doc-code">run</code>을 호출하세요.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">place</code></td><td><span class="req-y">필수</span></td><td>string</td><td>플레이스 URL 또는 ID(최대 1000자). <code class="doc-code">m.place.naver.com</code>·<code class="doc-code">map.naver.com</code>·<code class="doc-code">naver.me</code> 단축 URL·숫자 ID 모두 가능. ID를 못 찾고 URL도 아니면 입력값을 업체명으로 간주</td></tr>
                    <tr><td><code class="doc-code">keyword</code></td><td><span class="req-n">선택</span></td><td>string</td><td>단건 키워드(최대 100자). <code class="doc-code">keywords</code>가 없으면 필수</td></tr>
                    <tr><td><code class="doc-code">keywords</code></td><td><span class="req-n">선택</span></td><td>array</td><td>키워드 배열(1개 이상, 각 최대 100자). <code class="doc-code">keyword</code>가 없으면 필수. 두 값을 함께 보내면 합쳐서 처리(중복·공백 자동 제거)</td></tr>
                    <tr><td><code class="doc-code">label</code></td><td><span class="req-n">선택</span></td><td>string</td><td>슬롯 그룹 라벨(최대 100자). 생성되는 모든 슬롯에 동일 적용</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -X POST {{ url('/api/v1') }}/rank/slots \
  -H "Authorization: Bearer rk_..." \
  -H "Content-Type: application/json" \
  -d '{"place": "https://m.place.naver.com/hairshop/1145161001", "keywords": ["강남 미용실", "역삼 미용실"], "label": "강남점"}'</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">HTTP/1.1 201 Created
{
  "place": {
    "place_id": "1145161001",
    "place_name": "라온헤어 강남점",
    "place_url": "https://m.place.naver.com/place/1145161001/home",
    "category": "hairshop"
  },
  "created": [
    {
      "id": 128,
      "keyword": "강남 미용실",
      "place_id": "1145161001",
      "place_name": "라온헤어 강남점",
      "place_url": "https://m.place.naver.com/place/1145161001/home",
      "label": "강남점",
      "category": "hairshop",
      "last_rank": null,
      "last_review_count": null,
      "last_checked_at": null,
      "history": null
    }
  ],
  "skipped": ["역삼 미용실"]
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">place.place_id</code></td><td>string|null</td><td>확정된 플레이스 ID. 못 찾으면 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">place.place_name</code></td><td>string|null</td><td>자동 조회한 업체명(ID를 못 찾고 URL도 아니면 입력값 그대로)</td></tr>
                    <tr><td><code class="doc-code">place.place_url</code></td><td>string|null</td><td>정규 모바일 플레이스 URL</td></tr>
                    <tr><td><code class="doc-code">place.category</code></td><td>string|null</td><td>업종 키. ID를 못 찾으면 <code class="doc-code">null</code>(이 경우 슬롯에는 <code class="doc-code">place</code>로 저장)</td></tr>
                    <tr><td><code class="doc-code">created[]</code></td><td>array</td><td>새로 만들어진 슬롯. 항목 구조는 <code class="doc-code">GET /rank/slots</code>의 <code class="doc-code">slots[]</code>와 동일하며 <code class="doc-code">history</code>는 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">skipped[]</code></td><td>array</td><td>이미 같은 키워드 × 플레이스로 추적 중이라 건너뛴 키워드 문자열 배열</td></tr>
                </tbody>
            </table>
            <p class="ep-t">키워드가 하나도 없거나 슬롯 한도를 넘기면 <code class="doc-code">422</code>와 함께 사유가 <code class="doc-code">message</code>에 담깁니다(예: <span class="text-muted">추적 한도(100개, 플레이스+쇼핑 합산)를 초과합니다. 현재 100개 사용 중 · 추가 가능 0개(요청 2개).</span>). 한도 검사는 등록 전에 수행되므로 초과 시 슬롯이 하나도 생성되지 않습니다.</p>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/rank/resolve</code>
            <span class="ep-s">플레이스 메타만 조회</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">입력한 URL·ID·업체명에서 플레이스 ID를 확정하고 업체명·업종·정규 URL을 돌려줍니다. 슬롯을 만들지 않으므로 등록 화면의 미리보기(입력값이 올바른 플레이스인지 확인)에 사용하세요.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">place</code></td><td><span class="req-y">필수</span></td><td>string</td><td>플레이스 URL 또는 ID, 업체명(최대 1000자). 쿼리스트링으로 전달</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -G "{{ url('/api/v1') }}/rank/resolve" \
  -H "Authorization: Bearer rk_..." \
  --data-urlencode "place=https://m.place.naver.com/hairshop/1145161001"</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "place": {
    "place_id": "1145161001",
    "place_name": "라온헤어 강남점",
    "place_url": "https://m.place.naver.com/place/1145161001/home",
    "category": "hairshop"
  }
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">place.place_id</code></td><td>string|null</td><td>확정된 플레이스 ID(숫자 문자열). 단축·딥링크 URL은 최종 URL까지 따라가 추출</td></tr>
                    <tr><td><code class="doc-code">place.place_name</code></td><td>string|null</td><td>업체명. ID를 못 찾고 URL도 아니면 입력값을 그대로 업체명으로 반환</td></tr>
                    <tr><td><code class="doc-code">place.place_url</code></td><td>string|null</td><td>정규 모바일 플레이스 URL. ID를 못 찾았고 입력이 URL이면 입력값 그대로, 아니면 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">place.category</code></td><td>string|null</td><td>업종 키(<code class="doc-code">hairshop</code>·<code class="doc-code">restaurant</code> 등). 판별 실패 시 <code class="doc-code">place</code>, ID를 못 찾으면 <code class="doc-code">null</code></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-post">POST</span>
            <code class="ep-p">/rank/slots/{id}/run</code>
            <span class="ep-s">즉시 순위 갱신</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">슬롯의 순위를 지금 조회해 당일 기록을 저장(같은 날 기록이 있으면 덮어씀)하고, 슬롯의 최신값을 갱신합니다. 차단(<code class="doc-code">blocked: true</code>)이면서 미노출이면 기록을 남기지 않고 확인 시각만 갱신합니다. <b class="text-ink">조회 불가(<code class="doc-code">rank: 0</code>)가 3일 연속이면</b> 해당 슬롯의 자동 추적이 중단됩니다(삭제 아님 — 콘솔에서 재개 가능). 기본 조회 경로에서 300위 밖은 <code class="doc-code">rank: 300</code>으로 기록되므로 <b class="text-ink">순위 밖이 이어지는 것만으로는 중단되지 않습니다</b>. <code class="doc-code">rank: 0</code>은 릴레이 폴백(nCaptcha 토큰 없이 릴레이가 설정된 경우) 결과가 0이거나, 슬롯의 <code class="doc-code">place_id</code>·<code class="doc-code">place_name</code>이 모두 비어 조회 자체가 불가능할 때만 기록됩니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">id</code></td><td><span class="req-y">필수</span></td><td>int</td><td>경로 파라미터 — 슬롯 ID. 내 소유가 아니면 <code class="doc-code">403</code></td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -X POST {{ url('/api/v1') }}/rank/slots/128/run \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "result": {
    "blocked": false,
    "found": true,
    "rank": 3,
    "list_total": 280,
    "category": "hairshop",
    "place_id": "1145161001",
    "place_name": "라온헤어 강남점",
    "review_count": 1240,
    "blog_review_count": 320,
    "save_count": 890,
    "review_score": 4.9,
    "tags": ["염색", "펌"]
  },
  "slot": {
    "id": 128,
    "keyword": "강남 미용실",
    "place_id": "1145161001",
    "place_name": "라온헤어 강남점",
    "place_url": "https://m.place.naver.com/place/1145161001/home",
    "label": "강남점",
    "category": "hairshop",
    "last_rank": 3,
    "last_review_count": 1240,
    "last_checked_at": "2026-07-29T11:32:07+09:00",
    "history": [
      {"date": "2026-07-28", "rank": 4},
      {"date": "2026-07-29", "rank": 3}
    ]
  }
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">result</code></td><td>object</td><td>이번 조회 결과 — <b class="text-ink">구조는 <code class="doc-code">POST /rank/check</code>의 <code class="doc-code">result</code>와 동일</b></td></tr>
                    <tr><td><code class="doc-code">slot</code></td><td>object</td><td>갱신된 슬롯(이력 포함)</td></tr>
                    <tr><td><code class="doc-code">slot.id</code></td><td>int</td><td>슬롯 ID</td></tr>
                    <tr><td><code class="doc-code">slot.keyword</code></td><td>string</td><td>추적 키워드</td></tr>
                    <tr><td><code class="doc-code">slot.place_id</code></td><td>string|null</td><td>플레이스 ID. 비어 있었다면 이번 조회 결과로 채워짐</td></tr>
                    <tr><td><code class="doc-code">slot.place_name</code></td><td>string|null</td><td>업체명. 비어 있었다면 이번 조회 결과로 채워짐</td></tr>
                    <tr><td><code class="doc-code">slot.place_url</code></td><td>string|null</td><td>정규 모바일 플레이스 URL</td></tr>
                    <tr><td><code class="doc-code">slot.label</code></td><td>string|null</td><td>그룹 라벨</td></tr>
                    <tr><td><code class="doc-code">slot.category</code></td><td>string</td><td>업종 키. 조회로 판별된 값으로 갱신되며 항상 값이 있습니다(판별 실패 시 <code class="doc-code">place</code>)</td></tr>
                    <tr><td><code class="doc-code">slot.last_rank</code></td><td>int|null</td><td>이번 조회 순위로 갱신</td></tr>
                    <tr><td><code class="doc-code">slot.last_review_count</code></td><td>int|null</td><td>이번 조회 방문자 리뷰 수로 갱신</td></tr>
                    <tr><td><code class="doc-code">slot.last_checked_at</code></td><td>string|null</td><td>확인 시각(ISO 8601, KST). 차단된 경우에도 갱신</td></tr>
                    <tr><td><code class="doc-code">slot.history[]</code></td><td>array</td><td>일자별 순위 기록(<code class="doc-code">date</code>·<code class="doc-code">rank</code>, 오름차순). 이번 실행분 포함</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-del">DELETE</span>
            <code class="ep-p">/rank/slots/{id}</code>
            <span class="ep-s">슬롯 삭제</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">슬롯을 삭제합니다. 삭제하면 해당 슬롯의 순위 이력도 함께 사라집니다. <b class="text-ink">활성 슬롯</b>을 삭제하면 사용량(<code class="doc-code">used</code>)이 즉시 줄지만, <b class="text-ink">자동 중단된 슬롯</b>은 애초에 <code class="doc-code">used</code>에 포함되지 않으므로 삭제해도 <code class="doc-code">used</code>는 그대로입니다. 되돌릴 수 없습니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">id</code></td><td><span class="req-y">필수</span></td><td>int</td><td>경로 파라미터 — 슬롯 ID. 내 소유가 아니면 <code class="doc-code">403</code>, 없는 ID면 <code class="doc-code">404</code></td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -X DELETE {{ url('/api/v1') }}/rank/slots/128 \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "deleted": true
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">deleted</code></td><td>bool</td><td>삭제 완료 여부(항상 <code class="doc-code">true</code>)</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-post">POST</span>
            <code class="ep-p">/rank/check</code>
            <span class="ep-s">1회성 순위 조회</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">슬롯을 만들지 않고 키워드 × 플레이스 순위를 한 번만 조회합니다. 슬롯 한도를 소모하지 않고 기록도 남지 않습니다. 광고를 제외한 오가닉 순위이며 좌표는 서울 고정입니다. 업종 전용 리스트에서 미노출이면 통합 플레이스 리스트로 1회 다시 조회하는데, 이 폴백은 <b class="text-ink">플레이스 ID를 확정했고</b> 판별된 업종이 <code class="doc-code">place</code>가 아니며 차단되지 않은 경우에만 동작합니다. ID를 못 찾아 업체명 부분일치로 찾는 경우에는 폴백하지 않습니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">keyword</code></td><td><span class="req-y">필수</span></td><td>string</td><td>검색 키워드(최대 100자)</td></tr>
                    <tr><td><code class="doc-code">place</code></td><td><span class="req-y">필수</span></td><td>string</td><td>플레이스 URL 또는 ID(최대 1000자). ID를 추출하지 못하면 입력값을 업체명으로 보고 리스트에서 부분일치로 찾습니다(이 경우 통합 리스트 폴백 없음)</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -X POST {{ url('/api/v1') }}/rank/check \
  -H "Authorization: Bearer rk_..." \
  -H "Content-Type: application/json" \
  -d '{"keyword": "강남 미용실", "place": "https://m.place.naver.com/hairshop/1145161001"}'</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "result": {
    "blocked": false,
    "found": true,
    "rank": 3,
    "list_total": 280,
    "category": "hairshop",
    "place_id": "1145161001",
    "place_name": "라온헤어 강남점",
    "review_count": 1240,
    "blog_review_count": 320,
    "save_count": 890,
    "review_score": 4.9,
    "tags": ["염색", "펌"]
  }
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">result.blocked</code></td><td>bool</td><td>네이버 조회 차단(429/405 · 토큰 만료) 여부. <code class="doc-code">true</code>면 순위는 신뢰할 수 없으니 잠시 뒤 재시도</td></tr>
                    <tr><td><code class="doc-code">result.found</code></td><td>bool</td><td>상위 300위 리스트에서 대상 업체를 찾았는지 여부</td></tr>
                    <tr><td><code class="doc-code">result.rank</code></td><td>int</td><td>순위(1~). <code class="doc-code">300</code>=리스트 내 미노출(300위 밖은 언제나 이 값), <code class="doc-code">0</code>=차단(<code class="doc-code">blocked: true</code>)이거나 조회 불가(키워드·대상 미확정 · 릴레이 폴백 결과 0)</td></tr>
                    <tr><td><code class="doc-code">result.list_total</code></td><td>int</td><td>해당 키워드 검색 결과의 총 업체 수</td></tr>
                    <tr><td><code class="doc-code">result.category</code></td><td>string</td><td>조회에 사용한 업종 키(<code class="doc-code">hairshop</code>·<code class="doc-code">restaurant</code>·<code class="doc-code">place</code> 등)</td></tr>
                    <tr><td><code class="doc-code">result.place_id</code></td><td>string</td><td>매칭된 플레이스 ID. 못 찾으면 요청에서 확정한 ID 또는 빈 문자열</td></tr>
                    <tr><td><code class="doc-code">result.place_name</code></td><td>string</td><td>매칭된 업체명. 못 찾으면 빈 문자열</td></tr>
                    <tr><td><code class="doc-code">result.review_count</code></td><td>int|null</td><td>방문자 리뷰 수</td></tr>
                    <tr><td><code class="doc-code">result.blog_review_count</code></td><td>int|null</td><td>블로그·카페 리뷰 수</td></tr>
                    <tr><td><code class="doc-code">result.save_count</code></td><td>int|null</td><td>저장 수</td></tr>
                    <tr><td><code class="doc-code">result.review_score</code></td><td>float|null</td><td>방문자 리뷰 평점</td></tr>
                    <tr><td><code class="doc-code">result.tags</code></td><td>array</td><td>업체 대표 태그(문자열 배열). 없으면 빈 배열</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ============ 경쟁분석 ============ --}}
<div class="doc-panel" data-panel="compete">
    <h2 class="font-display text-ink doc-h2">경쟁분석 <span class="badge border border-hairline" style="font-size:var(--fs-xs);vertical-align:middle;">scope: compete</span></h2>
    <p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;">
        순위추적 슬롯(키워드 x 플레이스)을 기준으로 네이버 플레이스 상위 노출 경쟁 업체를 수집해, 내 플레이스와 리뷰·평점·사진·정보충실성 등의 신호를 비교·점수화합니다.
        분석 실행(<code class="doc-code">POST</code>)은 수집을 동기로 처리해 수십 초가 걸리며, 결과는 <b class="text-ink">하루 1건의 일자별 스냅샷</b>으로 저장되어 이후 조회(<code class="doc-code">GET</code>)로 언제든 다시 읽을 수 있습니다.
        점수 <code class="doc-code">N1</code>/<code class="doc-code">N2</code>/<code class="doc-code">N3</code>·<code class="doc-code">D1~D10</code> 은 <b class="text-ink">랭크프리가 관측 신호로 산출한 자체 추정치</b>이며 네이버가 공개하는 공식 지표가 아닙니다.
    </p>

    <table class="doc-table mt-4">
        <thead><tr><th style="width:150px;">항목</th><th>규칙</th></tr></thead>
        <tbody>
            <tr><td>인증</td><td><code class="doc-code">Authorization: Bearer rk_...</code> 헤더(또는 <code class="doc-code">X-API-KEY</code>). <code class="doc-code">compete</code> 권한이 있는 API 키만 호출할 수 있습니다</td></tr>
            <tr><td>Base URL</td><td><code class="doc-code">{{ url('/api/v1') }}</code></td></tr>
            <tr><td><code class="doc-code">slotId</code></td><td>순위추적 슬롯 ID. <code class="doc-code">GET /compete/tracks</code> 응답의 <code class="doc-code">slot_id</code> 를 사용합니다. 존재하지 않는 ID 면 <code class="doc-code">404</code>, 존재하지만 본인 소유가 아니면 <code class="doc-code">403</code></td></tr>
            <tr><td>점수 성격</td><td><code class="doc-code">N1</code>/<code class="doc-code">N2</code>/<code class="doc-code">N3</code>·<code class="doc-code">D1~D10</code> 은 <b class="text-ink">랭크프리 자체 추정치</b>(0~100). 네이버 공식 지표가 아니며 같은 키워드 경쟁셋 안에서의 상대 비교용입니다</td></tr>
            <tr><td>점수 직렬화</td><td>DB 에 저장된 점수(<code class="doc-code">d1</code>~<code class="doc-code">d10</code>·<code class="doc-code">n1</code>~<code class="doc-code">n3</code>)와 <code class="doc-code">review_score</code> 는 <b class="text-ink">소수 자릿수가 고정된 문자열</b>로 내려옵니다 — <code class="doc-code">"82.500"</code>(점수, 소수 3자리) · <code class="doc-code">"4.87"</code>(평점, 소수 2자리). 분석 실행(<code class="doc-code">POST</code>) 응답의 <code class="doc-code">my_score</code> 만 계산 직후 값이라 숫자(float)입니다. 클라이언트에서 숫자로 변환해 사용하세요</td></tr>
            <tr><td>분석 단위</td><td>날짜(<code class="doc-code">ymd</code>, Asia/Seoul) 기준 스냅샷. 같은 날 다시 실행하면 그날 스냅샷을 덮어씁니다</td></tr>
            <tr><td>순위 표기</td><td>상위 300위(6페이지)까지 탐색. 그 안에서 찾지 못하면 <code class="doc-code">my_rank</code> 와 저장되는 <code class="doc-code">rnk</code> 가 모두 <code class="doc-code">300</code> 입니다(<code class="doc-code">0</code> 은 내려가지 않습니다). 슬롯에 플레이스가 지정되지 않은 경우에도 <code class="doc-code">my_rank</code> 는 <code class="doc-code">300</code></td></tr>
            <tr><td>오류</td><td><code class="doc-code">401</code> 키 무효·만료 / <code class="doc-code">403</code> scope 없음·타인 슬롯 / <code class="doc-code">404</code> 존재하지 않는 <code class="doc-code">slotId</code>(경로의 슬롯은 라우트 모델 바인딩이라 소유 검사보다 먼저 <code class="doc-code">404</code> 가 납니다) / <code class="doc-code">429</code> 일일 호출 한도 초과 또는 네이버 조회 차단</td></tr>
        </tbody>
    </table>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/compete/tracks</code>
            <span class="ep-s">슬롯별 최신 분석 요약</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">내 순위추적 슬롯 전체와 각 슬롯의 <b class="text-ink">가장 최근 분석 결과</b>(N1/N2/N3·순위)를 한 번에 가져옵니다. 요청 파라미터는 없습니다. 아직 한 번도 분석하지 않은 슬롯은 <code class="doc-code">analyzed_ymd</code> 와 점수 필드가 모두 <code class="doc-code">null</code> 로 내려옵니다. 다른 엔드포인트에 쓰는 <code class="doc-code">slotId</code> 는 여기의 <code class="doc-code">slot_id</code> 값입니다.</p>
            <div class="ep-l first">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl {{ url('/api/v1') }}/compete/tracks \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "tracks": [
    {
      "slot_id": 12,
      "keyword": "강남 미용실",
      "place_id": "1145161001",
      "place_name": "라온헤어 강남점",
      "category": "hairshop",
      "analyzed_ymd": "2026-07-29",
      "n1": "82.500",
      "n2": "74.132",
      "n3": "68.041",
      "rnk": 3
    },
    {
      "slot_id": 15,
      "keyword": "역삼동 네일샵",
      "place_id": "1029384756",
      "place_name": "뷰티네일 역삼점",
      "category": "nailshop",
      "analyzed_ymd": null,
      "n1": null,
      "n2": null,
      "n3": null,
      "rnk": null
    }
  ]
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">tracks[]</code></td><td>array</td><td>순위추적 슬롯 목록(최근 생성 순)</td></tr>
                    <tr><td><code class="doc-code">tracks[].slot_id</code></td><td>int</td><td>슬롯 ID — 이후 호출의 <code class="doc-code">slotId</code></td></tr>
                    <tr><td><code class="doc-code">tracks[].keyword</code></td><td>string</td><td>추적 키워드</td></tr>
                    <tr><td><code class="doc-code">tracks[].place_id</code></td><td>string</td><td>네이버 플레이스 ID</td></tr>
                    <tr><td><code class="doc-code">tracks[].place_name</code></td><td>string</td><td>업체명</td></tr>
                    <tr><td><code class="doc-code">tracks[].category</code></td><td>string</td><td>업종 경로 — <code class="doc-code">place</code>·<code class="doc-code">restaurant</code>·<code class="doc-code">hairshop</code>·<code class="doc-code">nailshop</code>·<code class="doc-code">hospital</code>·<code class="doc-code">accommodation</code></td></tr>
                    <tr><td><code class="doc-code">tracks[].analyzed_ymd</code></td><td>string|null</td><td>최신 분석일(<code class="doc-code">YYYY-MM-DD</code>). 분석 이력이 없으면 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">tracks[].n1</code></td><td>string|null</td><td>키워드 일치 점수(0~100, 자체 추정치). 저장값 그대로라 소수 3자리 문자열</td></tr>
                    <tr><td><code class="doc-code">tracks[].n2</code></td><td>string|null</td><td>경쟁력 종합 점수(0~100, 자체 추정치). 소수 3자리 문자열</td></tr>
                    <tr><td><code class="doc-code">tracks[].n3</code></td><td>string|null</td><td>순위 환산 점수(0~100, 자체 추정치). 소수 3자리 문자열</td></tr>
                    <tr><td><code class="doc-code">tracks[].rnk</code></td><td>int|null</td><td>최신 분석 시점의 내 플레이스 순위. <code class="doc-code">300</code> = 상위 300위 밖(미노출)</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/compete/{slotId}</code>
            <span class="ep-s">최신 분석 상세(경쟁셋·해설·추이)</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">해당 슬롯의 <b class="text-ink">가장 최근 분석일 스냅샷</b>을 반환합니다. 경쟁셋 비교표(<code class="doc-code">rows</code>), 내 플레이스 점수 해설(<code class="doc-code">explain</code>), 일자별 추이(<code class="doc-code">series</code>)로 구성됩니다. <code class="doc-code">rows</code> 는 내 플레이스가 맨 앞에 오고 그다음 순위 오름차순입니다. 분석 이력이 없으면 <code class="doc-code">analyzed: false</code> 로만 응답하므로, 먼저 <code class="doc-code">POST /compete/{slotId}/analyze</code> 를 실행해야 합니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">slotId</code></td><td><span class="req-y">필수</span></td><td>int</td><td>경로 파라미터. 순위추적 슬롯 ID(없는 ID 는 <code class="doc-code">404</code>, 본인 소유가 아니면 <code class="doc-code">403</code>)</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl {{ url('/api/v1') }}/compete/12 \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "analyzed": true,
  "ymd": "2026-07-29",
  "keyword": "강남 미용실",
  "place_name": "라온헤어 강남점",
  "rows": [
    {
      "rnk": 3,
      "name": "라온헤어 강남점",
      "place_id": "1145161001",
      "is_mine": true,
      "visitor_cnt": 1842,
      "blog_cnt": 407,
      "review_score": "4.87",
      "d7": "78.400",
      "n1": "82.500",
      "n2": "74.132",
      "n3": "68.041",
      "tier": 2
    },
    {
      "rnk": 1,
      "name": "제로헤어 강남본점",
      "place_id": "1038472910",
      "is_mine": false,
      "visitor_cnt": 3120,
      "blog_cnt": 655,
      "review_score": "4.92",
      "d7": "91.200",
      "n1": "88.000",
      "n2": "86.417",
      "n3": "100.000",
      "tier": 2
    }
  ],
  "explain": {
    "components": {
      "L": 1.0,
      "B": 1.0,
      "T": 0.6,
      "M": 1.0,
      "region": "강남",
      "core": "강남",
      "bizterm": "미용실"
    },
    "seo": [
      {
        "label": "메뉴/시술",
        "raw": "24개",
        "grade": 1.0,
        "w": 1.5,
        "avail": 1
      },
      {
        "label": "대표키워드",
        "raw": "5개",
        "grade": 1.0,
        "w": 1.5,
        "avail": 1
      },
      {
        "label": "필수정보 완성",
        "raw": "찾아오는길 누락",
        "grade": 0.67,
        "w": 1.0,
        "avail": 1
      }
    ],
    "dims": {
      "d1": "88.240",
      "d2": "71.503",
      "d3": "64.118",
      "d4": "82.667",
      "d5": null,
      "d6": "59.420",
      "d7": "78.400",
      "d8": "82.500",
      "d9": "66.315",
      "d10": "73.333",
      "n1": "82.500",
      "n2": "74.132",
      "n3": "68.041"
    },
    "review_kw": {
      "menus": [
        { "l": "뿌리염색", "c": 32 },
        { "l": "레이어드컷", "c": 21 }
      ],
      "themes": [
        { "l": "친절해요", "c": 51 }
      ],
      "voted": [
        { "l": "상담이 자세해요", "c": 27 }
      ]
    },
    "review_weekly": {
      "v": [12, 21, 33, 46],
      "b": [3, 5, 8, 11]
    },
    "review_quality": {
      "photo_n": 18,
      "photo_total": 31,
      "photo_ratio": 0.581,
      "ctx": {
        "예약 후 이용": 14,
        "지인 추천": 6
      },
      "authority": {
        "infl": 5,
        "hi_infl": 3,
        "power": 2,
        "avg_fol": 63,
        "top": [
          { "n": "라라뷰티", "f": 1820, "r": 214, "rt": 5 },
          { "n": "강남헤어러버", "f": 940, "r": 88, "rt": 4.5 }
        ]
      },
      "bloggers": [
        { "id": "beauty_haru", "n": "하루의 미용일지" }
      ]
    }
  },
  "series": [
    {
      "ymd": "2026-07-27",
      "n1": "82.500",
      "n2": "71.204",
      "n3": "65.238",
      "rnk": 5
    },
    {
      "ymd": "2026-07-29",
      "n1": "82.500",
      "n2": "74.132",
      "n3": "68.041",
      "rnk": 3
    }
  ]
}</pre></div>
            <div class="ep-l">응답 예시 — 분석 이력이 없을 때</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "analyzed": false,
  "rows": [],
  "mine": null,
  "explain": null
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:230px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">analyzed</code></td><td>bool</td><td>분석 이력 유무. <code class="doc-code">false</code> 면 <code class="doc-code">rows</code> 는 빈 배열, <code class="doc-code">mine</code>·<code class="doc-code">explain</code> 은 <code class="doc-code">null</code> 만 내려옵니다</td></tr>
                    <tr><td><code class="doc-code">ymd</code></td><td>string</td><td>스냅샷 기준일(<code class="doc-code">YYYY-MM-DD</code>) — 가장 최근 분석일</td></tr>
                    <tr><td><code class="doc-code">keyword</code></td><td>string</td><td>슬롯 키워드</td></tr>
                    <tr><td><code class="doc-code">place_name</code></td><td>string</td><td>내 플레이스 업체명</td></tr>
                    <tr><td><code class="doc-code">rows[]</code></td><td>array</td><td>해당 일자 경쟁셋 — 상위 30개. 내 플레이스가 상위 30 밖이면 내 플레이스 행이 따로 한 건 더 저장되어 <b class="text-ink">최대 31행</b>이 됩니다. 내 플레이스 우선 → 순위 오름차순</td></tr>
                    <tr><td><code class="doc-code">rows[].rnk</code></td><td>int</td><td>노출 순위(1~). <code class="doc-code">300</code> = 상위 300위 밖</td></tr>
                    <tr><td><code class="doc-code">rows[].name</code></td><td>string</td><td>업체명</td></tr>
                    <tr><td><code class="doc-code">rows[].place_id</code></td><td>string</td><td>네이버 플레이스 ID</td></tr>
                    <tr><td><code class="doc-code">rows[].is_mine</code></td><td>bool</td><td>내 플레이스 여부</td></tr>
                    <tr><td><code class="doc-code">rows[].visitor_cnt</code></td><td>int|null</td><td>방문자 리뷰 수</td></tr>
                    <tr><td><code class="doc-code">rows[].blog_cnt</code></td><td>int|null</td><td>블로그·카페 리뷰 수</td></tr>
                    <tr><td><code class="doc-code">rows[].review_score</code></td><td>string|null</td><td>평점(5점 만점). 저장값 그대로라 소수 2자리 문자열(<code class="doc-code">"4.87"</code>)</td></tr>
                    <tr><td><code class="doc-code">rows[].d7</code></td><td>string|null</td><td>정보충실성 점수(0~100, 소수 3자리 문자열). 상세 수집한 업체만 값이 있습니다</td></tr>
                    <tr><td><code class="doc-code">rows[].n1</code></td><td>string|null</td><td>키워드 일치 점수(자체 추정치, 소수 3자리 문자열)</td></tr>
                    <tr><td><code class="doc-code">rows[].n2</code></td><td>string|null</td><td>경쟁력 종합 점수(자체 추정치, 소수 3자리 문자열)</td></tr>
                    <tr><td><code class="doc-code">rows[].n3</code></td><td>string|null</td><td>순위 환산 점수(자체 추정치, 소수 3자리 문자열)</td></tr>
                    <tr><td><code class="doc-code">rows[].tier</code></td><td>int|null</td><td><code class="doc-code">1</code> = 목록 지표만 수집 / <code class="doc-code">2</code> = 상세까지 수집(상위 <code class="doc-code">max(detail_top, 10)</code> 이내 또는 내 플레이스)</td></tr>
                    <tr><td><code class="doc-code">explain</code></td><td>object|null</td><td>내 플레이스 점수 해설. 내 플레이스의 상세 수집분이 없으면 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">explain.components</code></td><td>object</td><td>N1 구성요소 — 키워드를 지역어/업종어로 분해해 매칭한 결과</td></tr>
                    <tr><td><code class="doc-code">explain.components.L</code></td><td>float|null</td><td>지역 일치 0~1 (주소 1.0 / 상호 0.7 / 대표키워드 0.5 / 불일치 0)</td></tr>
                    <tr><td><code class="doc-code">explain.components.B</code></td><td>float|null</td><td>업종 일치 0~1 (업종 경로 또는 카테고리명 일치 1.0 / 동일 계열 0.8)</td></tr>
                    <tr><td><code class="doc-code">explain.components.T</code></td><td>float|null</td><td>대표키워드 일치 0~1 (전체 키워드 1.0 / 업종어 0.6 / 지역 핵심어 0.4)</td></tr>
                    <tr><td><code class="doc-code">explain.components.M</code></td><td>float|null</td><td>상호 일치 0~1 (지역 핵심어 포함 1.0 / 업종어 포함 0.6)</td></tr>
                    <tr><td><code class="doc-code">explain.components.region</code></td><td>string</td><td>키워드에서 분리한 지역어</td></tr>
                    <tr><td><code class="doc-code">explain.components.core</code></td><td>string</td><td>지역 핵심어(역·동·구 등 접미 제거)</td></tr>
                    <tr><td><code class="doc-code">explain.components.bizterm</code></td><td>string</td><td>키워드에서 분리한 업종어</td></tr>
                    <tr><td><code class="doc-code">explain.seo[]</code></td><td>array</td><td>D7 정보충실성 체크리스트 항목</td></tr>
                    <tr><td><code class="doc-code">explain.seo[].label</code></td><td>string</td><td>항목명 — 메뉴/시술·대표키워드·찾아오는길·대표사진·영업시간 공개·예약 연결·가격 공개·필수정보 완성·톡톡/챗봇·스타일리스트·편의시설·부가 카테고리·결제수단</td></tr>
                    <tr><td><code class="doc-code">explain.seo[].raw</code></td><td>string</td><td>현재 상태 표시값(<code class="doc-code">"24개"</code>, <code class="doc-code">"공개"</code>, <code class="doc-code">"누락 없음"</code> 등)</td></tr>
                    <tr><td><code class="doc-code">explain.seo[].grade</code></td><td>float</td><td>항목 달성도 0~1</td></tr>
                    <tr><td><code class="doc-code">explain.seo[].w</code></td><td>float</td><td>항목 가중치(0.5~1.5)</td></tr>
                    <tr><td><code class="doc-code">explain.seo[].avail</code></td><td>int</td><td><code class="doc-code">1</code> = 이 업종에 해당(D7 에 반영) / <code class="doc-code">0</code> = 미해당(계산에서 제외)</td></tr>
                    <tr><td><code class="doc-code">explain.dims</code></td><td>object|null</td><td>내 플레이스의 저장된 점수 원본 — <code class="doc-code">d1</code>~<code class="doc-code">d10</code>·<code class="doc-code">n1</code>·<code class="doc-code">n2</code>·<code class="doc-code">n3</code>. DB 저장값 그대로라 각 값은 소수 3자리 문자열(미산출 항목은 <code class="doc-code">null</code>)</td></tr>
                    <tr><td><code class="doc-code">explain.review_kw</code></td><td>object|null</td><td>방문자 리뷰 AI 분석 키워드 — <code class="doc-code">menus</code>·<code class="doc-code">themes</code>·<code class="doc-code">voted</code> 각각 <code class="doc-code">[{"l": 라벨, "c": 건수}]</code></td></tr>
                    <tr><td><code class="doc-code">explain.review_weekly</code></td><td>object|null</td><td>주별 리뷰 누적 — <code class="doc-code">v</code>(방문자)·<code class="doc-code">b</code>(블로그) 각 4칸 배열, 순서대로 최근 1·2·3·4주 <b class="text-ink">누적</b> 건수</td></tr>
                    <tr><td><code class="doc-code">explain.review_quality</code></td><td>object|null</td><td>최근 4주 방문자 리뷰 품질 지표</td></tr>
                    <tr><td><code class="doc-code">explain.review_quality.photo_n</code></td><td>int</td><td>사진 첨부 리뷰 수</td></tr>
                    <tr><td><code class="doc-code">explain.review_quality.photo_total</code></td><td>int</td><td>집계 대상 리뷰 수(최근 4주)</td></tr>
                    <tr><td><code class="doc-code">explain.review_quality.photo_ratio</code></td><td>float</td><td>사진 첨부 비율 0~1</td></tr>
                    <tr><td><code class="doc-code">explain.review_quality.ctx</code></td><td>object</td><td>방문 맥락별 건수(<code class="doc-code">{"예약 후 이용": 14}</code> 형태, 건수 내림차순)</td></tr>
                    <tr><td><code class="doc-code">explain.review_quality.authority</code></td><td>object</td><td>리뷰어 영향력 — <code class="doc-code">infl</code>(팔로워 100+ 리뷰어 수) · <code class="doc-code">hi_infl</code>(그중 평점 4.5+ 부여) · <code class="doc-code">power</code>(리뷰 100건+ 리뷰어 수) · <code class="doc-code">avg_fol</code>(평균 팔로워) · <code class="doc-code">top[]</code>(상위 5명: <code class="doc-code">n</code> 닉네임, <code class="doc-code">f</code> 팔로워, <code class="doc-code">r</code> 리뷰수, <code class="doc-code">rt</code> 부여 평점)</td></tr>
                    <tr><td><code class="doc-code">explain.review_quality.bloggers[]</code></td><td>array</td><td>블로그 리뷰 작성자(최대 8명) — <code class="doc-code">id</code>(블로그 ID) · <code class="doc-code">n</code>(필명). 수집된 경우에만 포함</td></tr>
                    <tr><td><code class="doc-code">series[]</code></td><td>array</td><td>내 플레이스의 일자별 추이(오래된 순) — <code class="doc-code">ymd</code>·<code class="doc-code">n1</code>·<code class="doc-code">n2</code>·<code class="doc-code">n3</code>·<code class="doc-code">rnk</code>. <code class="doc-code">n1</code>~<code class="doc-code">n3</code> 는 저장값 그대로라 소수 3자리 문자열, <code class="doc-code">rnk</code> 는 정수. 분석을 실행한 날짜만 존재합니다</td></tr>
                </tbody>
            </table>
            <div class="ep-l">점수 지표 정의 (랭크프리 자체 추정치)</div>
            <p class="ep-t">아래 지표는 모두 0~100 으로 정규화한 <b class="text-ink">랭크프리 자체 추정치</b>이며 네이버 공식 점수가 아닙니다. 값은 그날 수집한 상위 30개 경쟁셋을 모집단으로 계산되므로, 다른 키워드끼리는 직접 비교하지 마세요.</p>
            <table class="doc-table">
                <thead><tr><th style="width:80px;">지표</th><th style="width:88px;">N2 가중</th><th>산출 기준</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">d1</code></td><td>0.18</td><td>방문자 리뷰 수 — 경쟁셋 90분위 기준 로그 정규화</td></tr>
                    <tr><td><code class="doc-code">d2</code></td><td>0.09</td><td>블로그·카페 리뷰 수</td></tr>
                    <tr><td><code class="doc-code">d3</code></td><td>0.07</td><td>예약자 리뷰 수. 미제공 업종은 리뷰 방문맥락 "예약 후 이용" 건수로 대체</td></tr>
                    <tr><td><code class="doc-code">d4</code></td><td>0.12</td><td>평점 — 방문자 수로 보정한 베이지안 평균</td></tr>
                    <tr><td><code class="doc-code">d5</code></td><td>0.08</td><td>저장 수 — 음식점(<code class="doc-code">restaurant</code>)만 산출, 그 외 업종은 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">d6</code></td><td>0.08</td><td>등록 사진 수</td></tr>
                    <tr><td><code class="doc-code">d7</code></td><td>0.14</td><td>정보충실성 — <code class="doc-code">explain.seo</code> 체크리스트 가중평균</td></tr>
                    <tr><td><code class="doc-code">d8</code></td><td>-</td><td>키워드 일치 — <code class="doc-code">L</code> .30 / <code class="doc-code">B</code> .30 / <code class="doc-code">T</code> .30 / <code class="doc-code">M</code> .10. <code class="doc-code">n1</code> 과 동일 값</td></tr>
                    <tr><td><code class="doc-code">d9</code></td><td>0.20</td><td>최근 리뷰 유입 — 최근 4주 누적 방문자+블로그 리뷰 수</td></tr>
                    <tr><td><code class="doc-code">d10</code></td><td>0.12</td><td>리뷰어 영향력 — 팔로워·리뷰수 기반 권위 점수의 경쟁셋 내 백분위</td></tr>
                    <tr><td><code class="doc-code">n1</code></td><td>-</td><td><code class="doc-code">d8</code> 과 동일 — 키워드와 업체 정보의 일치도</td></tr>
                    <tr><td><code class="doc-code">n2</code></td><td>-</td><td><code class="doc-code">d1</code>·<code class="doc-code">d2</code>·<code class="doc-code">d3</code>·<code class="doc-code">d4</code>·<code class="doc-code">d5</code>·<code class="doc-code">d6</code>·<code class="doc-code">d7</code>·<code class="doc-code">d9</code>·<code class="doc-code">d10</code> 가중평균. 수집되지 않은 지표(<code class="doc-code">null</code>)는 가중치를 재정규화해 제외(표의 가중치는 기본값)</td></tr>
                    <tr><td><code class="doc-code">n3</code></td><td>-</td><td>순위 환산 — <code class="doc-code">100 x (1 - ln(min(rnk,300)) / ln 301)</code>. 미노출(<code class="doc-code">rnk</code> = 300)이면 약 <code class="doc-code">0.058</code></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-post">POST</span>
            <code class="ep-p">/compete/{slotId}/analyze</code>
            <span class="ep-s">분석 실행(동기, 수십 초 소요)</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">지금 네이버에서 해당 키워드의 상위 30개 업체를 수집하고, 상위 <code class="doc-code">max(detail_top, 10)</code> 곳(내 플레이스는 순위와 무관하게 항상 포함)의 상세 정보를 추가로 읽어 점수를 계산·저장합니다. 주별 리뷰 수집은 상위 10곳 + 내 플레이스로 고정이며, 리뷰 수집 대상은 상세도 함께 읽히므로 <code class="doc-code">detail_top</code> 을 10 미만으로 주더라도 상위 10곳은 상세까지 수집됩니다. <b class="text-ink">동기 처리로 수십 초가 걸리므로 클라이언트 타임아웃을 넉넉히(권장 300초) 설정</b>하세요. 실행 결과 요약만 즉시 반환하며, 경쟁셋 표·해설은 이어서 <code class="doc-code">GET /compete/{slotId}</code> 로 조회합니다. 같은 날 다시 실행하면 그날 스냅샷을 덮어씁니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">slotId</code></td><td><span class="req-y">필수</span></td><td>int</td><td>경로 파라미터. 순위추적 슬롯 ID(없는 ID 는 <code class="doc-code">404</code>, 본인 소유가 아니면 <code class="doc-code">403</code>)</td></tr>
                    <tr><td><code class="doc-code">detail_top</code></td><td><span class="req-n">선택</span></td><td>int</td><td>상세 정보를 수집할 상위 개수(기본 <code class="doc-code">10</code>). 값이 클수록 정확도가 올라가지만 소요 시간이 늘어납니다. 리뷰 주별 수집은 상위 10곳 + 내 플레이스로 고정이며 그 대상은 상세도 함께 수집되므로, 실효 기준은 <code class="doc-code">max(detail_top, 10)</code> 입니다(10 미만을 줘도 상위 10곳은 <code class="doc-code">tier</code> <code class="doc-code">2</code>)</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -X POST {{ url('/api/v1') }}/compete/12/analyze \
  -H "Authorization: Bearer rk_..." \
  -H "Content-Type: application/json" \
  -d '{"detail_top": 10}' \
  --max-time 300</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "blocked": false,
  "my_rank": 3,
  "total": 284,
  "competitors": 30,
  "my_score": {
    "d1": 88.24,
    "d2": 71.503,
    "d3": 64.118,
    "d4": 82.667,
    "d5": null,
    "d6": 59.42,
    "d7": 78.4,
    "d8": 82.5,
    "d9": 66.315,
    "d10": 73.333,
    "n1": 82.5,
    "n2": 74.132,
    "n3": 68.041,
    "act": null,
    "mask": 1007,
    "tier": 2,
    "rnk": 3
  }
}</pre></div>
            <div class="ep-l">응답 예시 — 조회 차단(HTTP 429)</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">HTTP/1.1 429 Too Many Requests

{
  "message": "조회 제한(nCaptcha 토큰 재발급 필요)",
  "blocked": true
}</pre></div>
            <p class="ep-t">네이버가 순위 조회를 차단하면 위 응답이 내려오며 <b class="text-ink">이 호출로 저장된 데이터는 없습니다</b>. 잠시 뒤(수 분 이상 간격) 재시도하세요. 같은 <code class="doc-code">429</code> 라도 <code class="doc-code">blocked</code> 키 없이 <code class="doc-code">daily_limit</code>·<code class="doc-code">used</code> 가 오면 API 키의 일일 호출 한도 초과입니다.</p>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">blocked</code></td><td>bool</td><td>정상 응답(<code class="doc-code">200</code>)에서는 항상 <code class="doc-code">false</code>. 차단은 <code class="doc-code">429</code> + <code class="doc-code">blocked: true</code></td></tr>
                    <tr><td><code class="doc-code">my_rank</code></td><td>int</td><td>내 플레이스 순위(1~). 상위 300위 안에서 발견되지 않으면 <code class="doc-code">300</code>(저장되는 <code class="doc-code">rnk</code> 도 <code class="doc-code">300</code>). 슬롯에 플레이스가 지정되지 않은 경우에도 <code class="doc-code">300</code> 이며, <code class="doc-code">0</code> 은 내려가지 않습니다</td></tr>
                    <tr><td><code class="doc-code">total</code></td><td>int</td><td>해당 키워드의 네이버 검색 결과 전체 업체 수</td></tr>
                    <tr><td><code class="doc-code">competitors</code></td><td>int</td><td>이번에 수집한 상위 목록 개수(최대 30). 내 플레이스가 상위 30 밖이라 따로 수집·저장된 행은 포함하지 않습니다</td></tr>
                    <tr><td><code class="doc-code">my_score</code></td><td>object|null</td><td>내 플레이스 점수. 슬롯에 플레이스가 지정되지 않았으면 <code class="doc-code">null</code>. 계산 직후 값이라 각 점수는 문자열이 아닌 숫자입니다</td></tr>
                    <tr><td><code class="doc-code">my_score.d1</code> ~ <code class="doc-code">d10</code></td><td>float|null</td><td>세부 지표(0~100). 수집 불가 항목은 <code class="doc-code">null</code> — 위 "점수 지표 정의" 참고</td></tr>
                    <tr><td><code class="doc-code">my_score.n1</code></td><td>float|null</td><td>키워드 일치 점수(자체 추정치)</td></tr>
                    <tr><td><code class="doc-code">my_score.n2</code></td><td>float|null</td><td>경쟁력 종합 점수(자체 추정치)</td></tr>
                    <tr><td><code class="doc-code">my_score.n3</code></td><td>float</td><td>순위 환산 점수(자체 추정치). 순위가 미노출이면 <code class="doc-code">rnk</code> <code class="doc-code">300</code> 으로 계산되어 약 <code class="doc-code">0.058</code> 이 되며, <code class="doc-code">null</code> 은 나오지 않습니다</td></tr>
                    <tr><td><code class="doc-code">my_score.act</code></td><td>null</td><td>예약 필드 — 현재 항상 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">my_score.mask</code></td><td>int</td><td>산출에 성공한 지표 비트마스크. bit0부터 순서대로 <code class="doc-code">d1</code>·<code class="doc-code">d2</code>·<code class="doc-code">d3</code>·<code class="doc-code">d4</code>·<code class="doc-code">d5</code>·<code class="doc-code">d7</code>·<code class="doc-code">d8</code>·<code class="doc-code">d9</code>·<code class="doc-code">d10</code>·<code class="doc-code">d6</code> (예: <code class="doc-code">1007</code> = <code class="doc-code">d5</code> 만 결측)</td></tr>
                    <tr><td><code class="doc-code">my_score.tier</code></td><td>int</td><td><code class="doc-code">1</code> = 목록 지표만 / <code class="doc-code">2</code> = 상세까지 수집</td></tr>
                    <tr><td><code class="doc-code">my_score.rnk</code></td><td>int</td><td>스냅샷에 저장된 순위. 미노출이면 <code class="doc-code">300</code></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ============ 키워드분석 ============ --}}
<div class="doc-panel" data-panel="keyword">
    <h2 class="font-display text-ink doc-h2">키워드분석 <span class="badge border border-hairline" style="font-size:var(--fs-xs);vertical-align:middle;">scope: keyword · keyword_detail</span></h2>
    <p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;">
        네이버 검색광고 데이터로 키워드의 <b class="text-ink">월간 검색량(PC/모바일)·경쟁강도·연관 키워드</b>를 조회합니다.
        <b class="text-ink">경량(<code class="doc-code">/keyword</code>)</b>과 <b class="text-ink">상세(<code class="doc-code">/keyword/detail</code>)</b>는
        서로 다른 권한(scope)으로 분리되어 있어, 상품별로 키를 발급하고 한도를 따로 관리할 수 있습니다.
        상세는 경량 지표에 성별·연령 분포, 최근 12개월 트렌드, 요일별 비율, 자동 인사이트, 공유 토큰이 더해집니다.
    </p>

    <table class="doc-table mt-4">
        <thead><tr><th style="width:150px;">공통 규칙</th><th>내용</th></tr></thead>
        <tbody>
            <tr><td>인증</td><td><code class="doc-code">Authorization: Bearer rk_...</code> (또는 <code class="doc-code">X-API-KEY</code> 헤더)</td></tr>
            <tr><td>권한(scope)</td><td><code class="doc-code">GET /keyword</code> = <code class="doc-code">keyword</code>, <code class="doc-code">GET /keyword/detail</code> = <code class="doc-code">keyword_detail</code>. 키에 해당 scope 가 없으면 <code class="doc-code">403</code></td></tr>
            <tr><td>월 한도</td><td>두 엔드포인트 모두 회원 기능 한도 <code class="doc-code">keyword_analysis</code> 를 호출당 1회 차감. 초과 시 <code class="doc-code">429</code> + <code class="doc-code">limit_exceeded: true</code></td></tr>
            <tr><td>일일 한도</td><td>키에 일일 한도가 설정된 경우 초과 시 <code class="doc-code">429</code>. 응답 헤더 <code class="doc-code">X-RateLimit-Limit</code> / <code class="doc-code">X-RateLimit-Remaining</code> 제공</td></tr>
            <tr><td>키워드 정규화</td><td>서버는 <b class="text-ink">공백 제거 + 영문 대문자</b>로 정규화해 조회합니다. 다만 응답 <code class="doc-code">data.keyword</code> 는 서버 정규화 값이 아니라 <b class="text-ink">검색광고(keywordstool)가 돌려준 키워드 원문</b>으로, 대개 정규화된 형태(<code class="doc-code">강남 미용실</code> → <code class="doc-code">강남미용실</code>)입니다. <code class="doc-code">/keyword/detail</code> 에서 <b class="text-ink">검색량 조회만 실패</b>하고 상세만 성공한 경우에는 요청 원문(양끝 공백만 제거)이 그대로 들어가 <b class="text-ink">공백이 남습니다</b>(예: <code class="doc-code">강남 미용실</code>)</td></tr>
            <tr><td>캐시</td><td>검색량 6시간, 상세(성별·연령·트렌드) 6시간 — <b class="text-ink">성공 응답만</b> 캐시합니다. 요일별 비율(<code class="doc-code">data.weekday</code>)은 별도 소스(데이터랩)라 규칙이 달라 <b class="text-ink">성공 24시간 · 실패(<code class="doc-code">null</code>) 30분</b>으로 실패도 캐시합니다</td></tr>
            <tr><td>데이터 없음</td><td><code class="doc-code">200</code> + <code class="doc-code">data: null</code> + <code class="doc-code">message</code>. 오류가 아니라 해당 키워드의 집계가 없는 경우입니다</td></tr>
            <tr><td>검색량 표기</td><td>네이버가 <code class="doc-code">&lt; 10</code> 으로 절사한 값은 정수 <code class="doc-code">5</code> 로 변환해 반환합니다</td></tr>
        </tbody>
    </table>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/keyword</code>
            <span class="ep-s">경량 — 검색량·경쟁강도·연관 키워드 (scope: keyword)</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">키워드 1개의 월간 검색량(PC/모바일 분리), 광고 경쟁강도, 연관 키워드 목록을 조회합니다. 연관 키워드는 월간 총 검색량 내림차순으로 정렬되며 개수 제한 없이 전부 반환됩니다.</p>

            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">keyword</code></td><td><span class="req-y">필수</span></td><td>string</td><td>분석할 검색 키워드(쿼리스트링). 공백 포함 가능 — 서버가 공백 제거·대문자로 정규화합니다. 빈 값이면 <code class="doc-code">422</code></td></tr>
                </tbody>
            </table>

            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl --get {{ url('/api/v1') }}/keyword \
  -H "Authorization: Bearer rk_..." \
  --data-urlencode "keyword=강남 미용실"</pre></div>

            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "data": {
    "keyword": "강남미용실",
    "monthly_pc": 1200,
    "monthly_mobile": 8800,
    "comp_idx": "높음",
    "monthly_total": 10000,
    "related": [
      {
        "keyword": "강남역미용실",
        "monthly_pc": 500,
        "monthly_mobile": 3800,
        "comp_idx": "중간",
        "monthly_total": 4300
      },
      {
        "keyword": "신사동미용실",
        "monthly_pc": 210,
        "monthly_mobile": 1640,
        "comp_idx": "낮음",
        "monthly_total": 1850
      }
    ]
  },
  "message": null
}</pre></div>

            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:230px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">data</code></td><td>object|null</td><td>조회 결과. 자격증명 문제·집계 없음이면 <code class="doc-code">null</code>(HTTP 200, <code class="doc-code">message</code> 확인)</td></tr>
                    <tr><td><code class="doc-code">data.keyword</code></td><td>string</td><td>검색광고(keywordstool) 응답의 <code class="doc-code">relKeyword</code> 원문 — 서버 정규화 결과가 아니라 네이버가 돌려준 값이며, 통상 정규화된 형태(공백 제거·영문 대문자)입니다. <b class="text-ink">정규화 키와 정확히 일치하는 행이 없으면 응답의 첫 연관 행을 대표값으로 사용</b>하므로, 요청한 키워드와 다른 키워드가 반환될 수 있습니다</td></tr>
                    <tr><td><code class="doc-code">data.monthly_pc</code></td><td>int</td><td>최근 30일 PC 검색수. 절사값(<code class="doc-code">&lt; 10</code>)은 <code class="doc-code">5</code></td></tr>
                    <tr><td><code class="doc-code">data.monthly_mobile</code></td><td>int</td><td>최근 30일 모바일 검색수</td></tr>
                    <tr><td><code class="doc-code">data.comp_idx</code></td><td>string|null</td><td>광고 경쟁강도 — <code class="doc-code">높음</code> / <code class="doc-code">중간</code> / <code class="doc-code">낮음</code></td></tr>
                    <tr><td><code class="doc-code">data.monthly_total</code></td><td>int</td><td><code class="doc-code">monthly_pc + monthly_mobile</code></td></tr>
                    <tr><td><code class="doc-code">data.related</code></td><td>array</td><td>연관 키워드 목록. <code class="doc-code">monthly_total</code> 내림차순, 개수 제한 없음(수십~수백 건)</td></tr>
                    <tr><td><code class="doc-code">data.related[].keyword</code></td><td>string</td><td>연관 키워드(정규화 형태)</td></tr>
                    <tr><td><code class="doc-code">data.related[].monthly_pc</code></td><td>int</td><td>연관 키워드의 PC 검색수</td></tr>
                    <tr><td><code class="doc-code">data.related[].monthly_mobile</code></td><td>int</td><td>연관 키워드의 모바일 검색수</td></tr>
                    <tr><td><code class="doc-code">data.related[].comp_idx</code></td><td>string|null</td><td>연관 키워드의 경쟁강도</td></tr>
                    <tr><td><code class="doc-code">data.related[].monthly_total</code></td><td>int</td><td>연관 키워드의 월간 총 검색수</td></tr>
                    <tr><td><code class="doc-code">message</code></td><td>string|null</td><td><code class="doc-code">data</code> 가 <code class="doc-code">null</code> 일 때 사유 문구. 정상 조회 시 <code class="doc-code">null</code></td></tr>
                </tbody>
            </table>

            <div class="ep-l">월 한도 초과 응답 (429)</div>
            <p class="ep-t">회원 기능 한도(<code class="doc-code">keyword_analysis</code>)를 모두 사용한 경우입니다. <b class="text-ink">두 엔드포인트 공통</b>이며, 다음 달 1일에 초기화됩니다.</p>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">HTTP/1.1 429 Too Many Requests
{
  "data": null,
  "limit_exceeded": true,
  "message": "이번 달 키워드 분석 호출 한도(300회)를 초과했습니다."
}</pre></div>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/keyword/detail</code>
            <span class="ep-s">상세 — 경량 + 성별·연령·트렌드 (scope: keyword_detail)</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">경량 지표에 더해 성별·연령 분포(성별×연령 버킷 포함), 최근 12개월 검색량 트렌드, 요일별 검색 비율, 자동 인사이트, 검색량 등급(S~F)을 반환합니다. 조회에 성공하면 공개 공유용 <code class="doc-code">share_token</code> 도 함께 발급됩니다. <b class="text-ink">검색량(경량)과 상세는 소스가 서로 달라 한쪽만 실패할 수 있으며</b>, 검색량만 실패하면 검색량 계열 필드가 응답에서 빠집니다(아래 <code class="doc-code">data</code> 설명 참고). 상세 소스는 별도 세션 기반이라 일시 장애 시 <code class="doc-code">503</code> 이 반환될 수 있습니다.</p>

            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">keyword</code></td><td><span class="req-y">필수</span></td><td>string</td><td>분석할 검색 키워드(쿼리스트링). 공백 포함 가능 — 서버가 공백 제거·대문자로 정규화합니다. 빈 값이면 <code class="doc-code">422</code></td></tr>
                </tbody>
            </table>

            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl --get {{ url('/api/v1') }}/keyword/detail \
  -H "Authorization: Bearer rk_..." \
  --data-urlencode "keyword=강남 미용실"</pre></div>

            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "data": {
    "keyword": "강남미용실",
    "monthly_pc": 1200,
    "monthly_mobile": 8800,
    "comp_idx": "높음",
    "monthly_total": 10000,
    "related": [
      {
        "keyword": "강남역미용실",
        "monthly_pc": 500,
        "monthly_mobile": 3800,
        "comp_idx": "중간",
        "monthly_total": 4300
      }
    ],
    "grade": "B",
    "weekday": [
      { "w": "월", "pct": 15.8 },
      { "w": "화", "pct": 14.9 },
      { "w": "수", "pct": 14.1 },
      { "w": "목", "pct": 14.4 },
      { "w": "금", "pct": 15.2 },
      { "w": "토", "pct": 13.0 },
      { "w": "일", "pct": 12.6 }
    ],
    "detail": {
      "gender": {
        "female": 7200,
        "male": 2800,
        "female_pct": 72.0,
        "male_pct": 28.0
      },
      "age": [
        { "age": "25-29", "total": 2400, "pct": 24.0 },
        { "age": "30-39", "total": 3900, "pct": 39.0 }
      ],
      "monthly": [
        { "label": "2025-07", "pc": 1100, "mobile": 8300, "total": 9400 },
        { "label": "2025-08", "pc": 1250, "mobile": 9150, "total": 10400 }
      ],
      "buckets": [
        { "gender": "f", "age": "25-29", "pc": 260, "mobile": 1980, "total": 2240 },
        { "gender": "m", "age": "30-39", "pc": 190, "mobile": 1010, "total": 1200 }
      ],
      "insights": {
        "cards": [
          { "group": "season", "label": "시즌성", "value": "보통", "color": "var(--color-warning)" },
          { "group": "target", "label": "주 타겟 연령", "value": "30대·20대 후", "color": "var(--color-accent)" }
        ],
        "summary": "이 키워드는 여성(72%) 비중이 높고 30대·20대 후가 검색의 63%를 차지합니다. 어느 정도 시즌을 타는 키워드로, 검색은 4월·5월에 몰리고 1월·2월에 가장 적습니다."
      }
    }
  },
  "share_token": "8Kq2mZr7bVdT1sYxLpA0eWnC5gJhF3uQ",
  "message": null
}</pre></div>

            <div class="ep-l">응답 예시 — 검색량만 실패하고 상세만 성공한 경우</div>
            <p class="ep-t">검색광고 HMAC 자격증명 오류·쿼터 소진 등으로 검색량 조회만 실패하고 상세(웹 세션) 조회는 성공한 경우입니다. HTTP <code class="doc-code">200</code> 이지만 <b class="text-ink">검색량 계열 키가 응답에 존재하지 않으므로</b> 클라이언트는 키 존재 여부를 확인해야 합니다.</p>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "data": {
    "keyword": "강남 미용실",
    "grade": null,
    "weekday": null,
    "detail": {
      "gender": {
        "female": 7200,
        "male": 2800,
        "female_pct": 72.0,
        "male_pct": 28.0
      },
      "age": [
        { "age": "30-39", "total": 3900, "pct": 39.0 }
      ],
      "monthly": [
        { "label": "2025-08", "pc": 1250, "mobile": 9150, "total": 10400 }
      ],
      "buckets": [
        { "gender": "f", "age": "30-39", "pc": 300, "mobile": 2100, "total": 2400 }
      ],
      "insights": null
    }
  },
  "share_token": "8Kq2mZr7bVdT1sYxLpA0eWnC5gJhF3uQ",
  "message": null
}</pre></div>

            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:230px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">data</code></td><td>object|null</td><td>검색량 조회에 성공하면 경량 응답의 모든 필드(<code class="doc-code">keyword</code>·<code class="doc-code">monthly_pc</code>·<code class="doc-code">monthly_mobile</code>·<code class="doc-code">comp_idx</code>·<code class="doc-code">monthly_total</code>·<code class="doc-code">related</code>)를 그대로 포함합니다. <b class="text-ink">검색량만 실패하고 상세만 성공하면</b>(검색광고 자격증명 오류·쿼터 소진 등, 웹 세션은 정상) <code class="doc-code">keyword</code>·<code class="doc-code">grade</code>·<code class="doc-code">weekday</code>·<code class="doc-code">detail</code> 만 담기고 <code class="doc-code">monthly_pc</code>·<code class="doc-code">monthly_mobile</code>·<code class="doc-code">comp_idx</code>·<code class="doc-code">monthly_total</code>·<code class="doc-code">related</code> 키는 <b class="text-ink">아예 존재하지 않습니다</b>(이때 <code class="doc-code">keyword</code> 는 정규화되지 않은 요청 원문). 검색량·상세가 모두 실패하면 <code class="doc-code">null</code>(HTTP 200, <code class="doc-code">message</code> 확인)</td></tr>
                    <tr><td><code class="doc-code">data.grade</code></td><td>string|null</td><td>검색량 등급(자체 추정) — S(10만↑)·A(3만↑)·B(1만↑)·C(3천↑)·D(1천↑)·E(100↑)·F. 검색량 조회 실패 시 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">data.weekday</code></td><td>array|null</td><td>최근 90일 요일별 검색 비율(월~일 7개). <b class="text-ink">검색량 조회가 실패하면 데이터랩과 무관하게 항상 <code class="doc-code">null</code></b>(요일 조회 자체를 시도하지 않음). 검색량이 성공해도 데이터랩 조회가 불가하면 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">data.weekday[].w</code></td><td>string</td><td>요일 — <code class="doc-code">월</code>·<code class="doc-code">화</code>·<code class="doc-code">수</code>·<code class="doc-code">목</code>·<code class="doc-code">금</code>·<code class="doc-code">토</code>·<code class="doc-code">일</code></td></tr>
                    <tr><td><code class="doc-code">data.weekday[].pct</code></td><td>float</td><td>해당 요일 비중(%). 7개 합이 100</td></tr>
                    <tr><td><code class="doc-code">data.detail</code></td><td>object|null</td><td>성별·연령·트렌드 묶음. 해당 키워드의 상세 집계가 없으면 <code class="doc-code">null</code>(HTTP 200 + <code class="doc-code">message</code>)</td></tr>
                    <tr><td><code class="doc-code">data.detail.gender.female</code></td><td>int</td><td>여성 검색수 합계(PC+모바일)</td></tr>
                    <tr><td><code class="doc-code">data.detail.gender.male</code></td><td>int</td><td>남성 검색수 합계(PC+모바일)</td></tr>
                    <tr><td><code class="doc-code">data.detail.gender.female_pct</code></td><td>float</td><td>여성 비중(%), 소수 1자리</td></tr>
                    <tr><td><code class="doc-code">data.detail.gender.male_pct</code></td><td>float</td><td>남성 비중(%), 소수 1자리</td></tr>
                    <tr><td><code class="doc-code">data.detail.age</code></td><td>array</td><td>연령대별 집계(최대 7개 밴드)</td></tr>
                    <tr><td><code class="doc-code">data.detail.age[].age</code></td><td>string</td><td>연령 밴드 — <code class="doc-code">0-12</code>·<code class="doc-code">13-19</code>·<code class="doc-code">20-24</code>·<code class="doc-code">25-29</code>·<code class="doc-code">30-39</code>·<code class="doc-code">40-49</code>·<code class="doc-code">50-</code></td></tr>
                    <tr><td><code class="doc-code">data.detail.age[].total</code></td><td>int</td><td>해당 연령대 검색수(PC+모바일)</td></tr>
                    <tr><td><code class="doc-code">data.detail.age[].pct</code></td><td>float</td><td>해당 연령대 비중(%), 소수 1자리</td></tr>
                    <tr><td><code class="doc-code">data.detail.monthly</code></td><td>array</td><td>최근 12개월 검색량 트렌드(과거 → 최근 순)</td></tr>
                    <tr><td><code class="doc-code">data.detail.monthly[].label</code></td><td>string</td><td>월 라벨 — <code class="doc-code">YYYY-MM</code> 형식</td></tr>
                    <tr><td><code class="doc-code">data.detail.monthly[].pc</code></td><td>int</td><td>해당 월 PC 검색수</td></tr>
                    <tr><td><code class="doc-code">data.detail.monthly[].mobile</code></td><td>int</td><td>해당 월 모바일 검색수</td></tr>
                    <tr><td><code class="doc-code">data.detail.monthly[].total</code></td><td>int</td><td>해당 월 총 검색수(pc+mobile)</td></tr>
                    <tr><td><code class="doc-code">data.detail.buckets</code></td><td>array</td><td>성별×연령 교차 버킷(최대 14개) — 원본 분포를 그대로 쓰고 싶을 때 사용</td></tr>
                    <tr><td><code class="doc-code">data.detail.buckets[].gender</code></td><td>string</td><td><code class="doc-code">f</code>(여성) / <code class="doc-code">m</code>(남성)</td></tr>
                    <tr><td><code class="doc-code">data.detail.buckets[].age</code></td><td>string</td><td>연령 밴드(<code class="doc-code">age[].age</code> 와 동일 코드)</td></tr>
                    <tr><td><code class="doc-code">data.detail.buckets[].pc</code></td><td>int</td><td>해당 버킷 PC 검색수</td></tr>
                    <tr><td><code class="doc-code">data.detail.buckets[].mobile</code></td><td>int</td><td>해당 버킷 모바일 검색수</td></tr>
                    <tr><td><code class="doc-code">data.detail.buckets[].total</code></td><td>int</td><td>해당 버킷 총 검색수(pc+mobile)</td></tr>
                    <tr><td><code class="doc-code">data.detail.insights</code></td><td>object|null</td><td>데이터 기반 자동 요약(시즌성·주 타겟). 성별·연령·월별이 모두 비어 있으면 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">data.detail.insights.cards</code></td><td>array</td><td>지표 카드 목록</td></tr>
                    <tr><td><code class="doc-code">data.detail.insights.cards[].group</code></td><td>string</td><td>묶음 — <code class="doc-code">season</code>(시즌성·성수기·비수기) / <code class="doc-code">target</code>(성별·연령·핵심 타겟)</td></tr>
                    <tr><td><code class="doc-code">data.detail.insights.cards[].label</code></td><td>string</td><td>카드 제목(예: 시즌성, 성수기, 주 타겟 연령)</td></tr>
                    <tr><td><code class="doc-code">data.detail.insights.cards[].value</code></td><td>string</td><td>표시 문구(예: 뚜렷함, 4월·5월, 여성 72%)</td></tr>
                    <tr><td><code class="doc-code">data.detail.insights.cards[].color</code></td><td>string</td><td>강조 색 CSS 변수 문자열(예: <code class="doc-code">var(--color-accent)</code>). 자체 UI 사용 시 무시해도 됩니다</td></tr>
                    <tr><td><code class="doc-code">data.detail.insights.summary</code></td><td>string</td><td>한 문단 자연어 요약(성별·연령·시즌성)</td></tr>
                    <tr><td><code class="doc-code">share_token</code></td><td>string|null</td><td>공개 공유 토큰. <code class="doc-code">{{ url('/keyword') }}/{share_token}</code> 로 로그인 없이 리포트를 열 수 있습니다</td></tr>
                    <tr><td><code class="doc-code">message</code></td><td>string|null</td><td>상세 데이터가 없을 때 사유 문구. 정상 조회 시 <code class="doc-code">null</code></td></tr>
                </tbody>
            </table>

            <div class="ep-l">소스 장애 응답 (503)</div>
            <p class="ep-t">상세 지표 소스(검색광고 세션)에 연결할 수 없을 때 반환합니다. <b class="text-ink">데이터 없음(200 + <code class="doc-code">detail: null</code>)과 구분되는 일시 장애</b>이므로, 잠시 후 같은 요청을 재시도하면 됩니다.</p>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">HTTP/1.1 503 Service Unavailable
{
  "data": null,
  "message": "상세 분석 소스에 일시적으로 연결할 수 없습니다. 잠시 후 다시 시도하세요."
}</pre></div>
        </div>
    </div>
</div>

{{-- ============ 마케팅 상품 주문 ============ --}}
<div class="doc-panel" data-panel="order">
    <h2 class="font-display text-ink doc-h2">마케팅 상품 주문 <span class="badge border border-hairline" style="font-size:var(--fs-xs);vertical-align:middle;">scope: order</span></h2>
    <p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;">
        판매 중인 마케팅 상품을 조회하고, 외부 시스템에서 바로 주문을 접수하고, 주문 상태를 확인합니다.
        검증·금액 계산은 웹 주문과 <b class="text-ink">완전히 동일한 로직(OrderPlacer)</b>을 공유하므로 화면 주문과 결과가 같습니다.
        주문은 <code class="doc-code">pending</code>(접수) 상태로 생성되며, 주문에 쓰는 <code class="doc-code">product_id</code>는 <code class="doc-code">GET /products</code> 응답의 <code class="doc-code">id</code>입니다.
    </p>

    <p class="mt-5 text-body" style="font-size:var(--fs-sm);line-height:1.7;"><b class="text-ink">공통 주문 규칙</b> — 아래 규칙은 <code class="doc-code">POST /orders</code> 전체에 적용됩니다. 상품마다 다르므로 주문 전 <code class="doc-code">GET /products/{id}</code> 로 스펙을 먼저 확인하세요.</p>
    <table class="doc-table mt-2">
        <thead><tr><th style="width:190px;">항목</th><th>규칙</th></tr></thead>
        <tbody>
            <tr>
                <td><code class="doc-code">quantity</code></td>
                <td>수량. 상품 상세의 <code class="doc-code">fields</code> 에 <code class="doc-code">daily_qty</code> 필드가 있으면 <b class="text-ink">본문의 <code class="doc-code">quantity</code> 는 무시</b>되고 <code class="doc-code">fields.daily_qty</code> 값이 수량이 됩니다. 그 필드가 없을 때만 <code class="doc-code">quantity</code> 를 사용하며, <b class="text-ink">미전달 시 0 으로 평가</b>됩니다. <code class="doc-code">min_quantity</code> ~ <code class="doc-code">max_quantity</code> 범위를 벗어나면 422</td>
            </tr>
            <tr>
                <td><code class="doc-code">days</code></td>
                <td><code class="doc-code">quantity_mode</code> 가 <code class="doc-code">daily</code> 인 상품에만 의미가 있습니다. 상품에 <code class="doc-code">start_date</code> 와 <code class="doc-code">end_date</code> 필드가 <b class="text-ink">둘 다</b> 있을 때만 <code class="doc-code">days</code> 가 무시되고 <code class="doc-code">종료일 − 시작일 + 1</code> 로 계산됩니다. <b class="text-ink">둘 다 갖춰지지 않은 상품</b>(두 필드가 모두 없거나 <code class="doc-code">start_date</code> 만 있는 경우 등)은 <code class="doc-code">days</code> 를 쓰며, 미전달 시 <code class="doc-code">min_days</code> 가 적용됩니다. <code class="doc-code">quantity_mode</code> 가 <code class="doc-code">total</code> 이면 일수는 1로 취급되고 응답 <code class="doc-code">days</code> 는 <code class="doc-code">null</code></td>
            </tr>
            <tr>
                <td><code class="doc-code">fixed_quantity</code></td>
                <td>값이 있는 <b class="text-ink">고정 수량(패키지) 상품</b>은 <code class="doc-code">quantity</code>·<code class="doc-code">fields.daily_qty</code> 로 무엇을 보내든 <b class="text-ink">고정값으로 접수</b>됩니다. 저장되는 <code class="doc-code">daily_qty</code> 값도 고정값으로 덮어써집니다</td>
            </tr>
            <tr>
                <td><code class="doc-code">fixed_days</code></td>
                <td><code class="doc-code">quantity_mode</code> 가 <code class="doc-code">daily</code> 인 상품에서만 적용됩니다. 이때 값이 있는 <b class="text-ink">고정 기간 상품</b>은 <code class="doc-code">days</code> 를 보내도 <b class="text-ink">고정 일수로 접수</b>되고 <code class="doc-code">min_days</code> 검증도 건너뜁니다. <code class="doc-code">start_date</code> 필드가 있으면 <b class="text-ink">시작일만 보내면 되고</b>(누락 시 422 <code class="doc-code">field: "days"</code>), <code class="doc-code">end_date</code> 필드까지 있으면 종료일은 <code class="doc-code">시작일 + 고정일수 − 1</code> 로 서버가 재계산해 제출값을 덮어씁니다. <code class="doc-code">quantity_mode</code> 가 <code class="doc-code">total</code> 이면 <code class="doc-code">fixed_days</code> 는 <b class="text-ink">무시</b>되고 일수 1·응답 <code class="doc-code">days</code> 는 <code class="doc-code">null</code></td>
            </tr>
            <tr>
                <td>날짜 필드</td>
                <td><code class="doc-code">DATE</code> 타입은 <code class="doc-code">YYYY-MM-DD</code> 문자열. 상품의 <code class="doc-code">earliest_start_date</code>(접수 마감·진행 지연·주말 반영) 보다 이른 날짜는 422</td>
            </tr>
            <tr>
                <td>URL 필드</td>
                <td><code class="doc-code">URL</code> 타입의 플레이스 주소는 서버가 <b class="text-ink">표준 m.place 주소로 정규화</b>해 저장합니다(응답 <code class="doc-code">fields</code> 값이 보낸 값과 다를 수 있음)</td>
            </tr>
            <tr>
                <td><code class="doc-code">contains</code></td>
                <td>필드 스펙의 <code class="doc-code">contains</code> 문자열이 입력값에 포함돼 있지 않으면 422 <code class="doc-code">f_{필드키}</code>. 정규화가 끝난 뒤에 검사합니다</td>
            </tr>
            <tr>
                <td><code class="doc-code">not_contains</code></td>
                <td>관리자가 지정한 <b class="text-ink">금지 문자열</b>이 입력값에 포함돼 있으면 <code class="doc-code">contains</code> 와 동일하게 422 <code class="doc-code">f_{필드키}</code>(정규화 후 검사). 다만 이 규칙은 <b class="text-ink">필드 스펙 응답(<code class="doc-code">GET /products/{id}</code>)에 내려가지 않습니다</b> — 값이 있는지는 오류 메시지로만 확인할 수 있으니 운영자에게 확인하세요</td>
            </tr>
            <tr>
                <td>숨김(내부) 필드</td>
                <td>상품 상세의 <code class="doc-code">fields</code> 에는 고객 입력 항목과 <b class="text-ink">운영자 전용 숨김 필드가 함께</b> 내려오지만, 응답에는 이를 구분할 키(<code class="doc-code">is_hidden</code> 등)가 <b class="text-ink">없습니다</b>. 숨김 필드는 <code class="doc-code">required: true</code> 여도 누락으로 422 가 나지 않고, 보낸 값은 <b class="text-ink">무시된 채</b> 서버 기본값(없으면 <code class="doc-code">null</code>)으로 저장됩니다(실값은 수집·관리자 입력으로 채워짐). 어떤 키가 숨김인지는 운영자에게 확인하세요</td>
            </tr>
            <tr>
                <td>파일 필드</td>
                <td><code class="doc-code">FILE</code>·<code class="doc-code">IMAGE</code> 타입은 <b class="text-ink">API 미지원</b>(<code class="doc-code">api_supported: false</code>). 필수 파일 필드가 있는 상품은 목록에서 <code class="doc-code">orderable: false</code> 로 내려오고, 주문을 시도하면 422 <code class="doc-code">field: "product_id"</code> 로 거절됩니다(웹 주문만 가능)</td>
            </tr>
            <tr>
                <td><code class="doc-code">user_coupon_id</code></td>
                <td>보유 쿠폰 발급분 ID(선택). 본인 발급분·사용 가능·해당 상품 적용 가능 여부를 검증한 뒤 <b class="text-ink">할인 금액을 서버가 재계산</b>해 <code class="doc-code">discount_amount</code>·<code class="doc-code">total_price</code> 에 반영합니다</td>
            </tr>
            <tr>
                <td>금액</td>
                <td><code class="doc-code">total_price = unit_price × quantity × days − discount_amount</code> (0 미만이면 0). 모든 금액은 원 단위 정수</td>
            </tr>
        </tbody>
    </table>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/products</code>
            <span class="ep-s">주문 가능 상품 목록</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">판매 중(활성)인 마케팅 상품을 제목 오름차순으로 모두 반환합니다. 쿼리 파라미터는 없습니다. 여기서 얻은 <code class="doc-code">id</code> 를 <code class="doc-code">POST /orders</code> 의 <code class="doc-code">product_id</code> 로 사용하며, <code class="doc-code">orderable</code> 이 <code class="doc-code">false</code> 인 상품은 API 로 주문할 수 없습니다.</p>
            <div class="ep-l first">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl {{ url('/api/v1') }}/products \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "products": [
    {
      "id": 12,
      "title": "네이버 플레이스 저장",
      "type": "REWARD",
      "type_name": "참여형 리워드",
      "unit_price": 200,
      "quantity_mode": "daily",
      "min_quantity": 10,
      "max_quantity": 10000,
      "min_days": 1,
      "fixed_quantity": null,
      "fixed_days": null,
      "earliest_start_date": "2026-07-31",
      "orderable": true,
      "not_orderable_reason": null
    },
    {
      "id": 27,
      "title": "블로그 체험단 10인 패키지",
      "type": "EXPERIENCE",
      "type_name": "체험단",
      "unit_price": 90000,
      "quantity_mode": "total",
      "min_quantity": 1,
      "max_quantity": 50,
      "min_days": 1,
      "fixed_quantity": 10,
      "fixed_days": null,
      "earliest_start_date": "2026-08-03",
      "orderable": false,
      "not_orderable_reason": "필수 파일 첨부 필드: 제품 이미지"
    }
  ]
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:210px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">products[].id</code></td><td>int</td><td>상품 번호 — 주문 시 <code class="doc-code">product_id</code> 로 사용(관리자 상품 목록의 번호와 동일)</td></tr>
                    <tr><td><code class="doc-code">products[].title</code></td><td>string</td><td>상품명</td></tr>
                    <tr><td><code class="doc-code">products[].type</code></td><td>string</td><td>상품 유형 코드(<code class="doc-code">REWARD</code>·<code class="doc-code">EXPERIENCE</code>·<code class="doc-code">SNS</code>·<code class="doc-code">BLOG_REVIEW</code>·<code class="doc-code">REVIEW</code> 등)</td></tr>
                    <tr><td><code class="doc-code">products[].type_name</code></td><td>string</td><td>상품 유형 이름(한글)</td></tr>
                    <tr><td><code class="doc-code">products[].unit_price</code></td><td>int</td><td>단가(원)</td></tr>
                    <tr><td><code class="doc-code">products[].quantity_mode</code></td><td>string</td><td>과금 방식 — <code class="doc-code">daily</code>(단가×일수량×일수) 또는 <code class="doc-code">total</code>(단가×수량)</td></tr>
                    <tr><td><code class="doc-code">products[].min_quantity</code></td><td>int</td><td>최소 수량</td></tr>
                    <tr><td><code class="doc-code">products[].max_quantity</code></td><td>int</td><td>최대 수량</td></tr>
                    <tr><td><code class="doc-code">products[].min_days</code></td><td>int</td><td>최소 기간(일)</td></tr>
                    <tr><td><code class="doc-code">products[].fixed_quantity</code></td><td>int|null</td><td>값이 있으면 수량 고정(입력 무시)</td></tr>
                    <tr><td><code class="doc-code">products[].fixed_days</code></td><td>int|null</td><td>값이 있으면 기간 고정(종료일 자동 계산). <code class="doc-code">quantity_mode: "daily"</code> 상품에서만 적용</td></tr>
                    <tr><td><code class="doc-code">products[].earliest_start_date</code></td><td>string</td><td>선택 가능한 가장 빠른 시작일(<code class="doc-code">YYYY-MM-DD</code>)</td></tr>
                    <tr><td><code class="doc-code">products[].orderable</code></td><td>bool</td><td><code class="doc-code">false</code> = 필수 파일 첨부 필드가 있어 API 주문 불가</td></tr>
                    <tr><td><code class="doc-code">products[].not_orderable_reason</code></td><td>string|null</td><td>주문 불가 사유(파일 필드 라벨). 주문 가능하면 <code class="doc-code">null</code></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/products/{id}</code>
            <span class="ep-s">상품 상세 · 주문 필드 스펙</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">목록 응답의 모든 필드에 더해 <code class="doc-code">description</code> 과 <b class="text-ink">주문 입력 필드 스펙(<code class="doc-code">fields</code>)</b> 을 반환합니다. <code class="doc-code">POST /orders</code> 의 <code class="doc-code">fields</code> 객체는 여기 나온 <code class="doc-code">key</code> 를 그대로 키로 사용합니다. 다만 <code class="doc-code">fields</code> 에는 고객 입력 항목뿐 아니라 <b class="text-ink">운영자 전용 숨김(내부) 필드도 같은 모양으로 섞여</b> 내려오고, 응답만으로는 이를 구분할 수 없습니다(숨김 필드는 값을 보내도 무시되고 <code class="doc-code">required</code> 검증도 하지 않습니다 — 위 <b class="text-ink">공통 주문 규칙</b> 참고). 판매 중이 아니거나 없는 상품이면 404 입니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">id</code></td><td><span class="req-y">필수</span></td><td>int</td><td>경로 파라미터 — 상품 번호(<code class="doc-code">GET /products</code> 의 <code class="doc-code">id</code>)</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl {{ url('/api/v1') }}/products/12 \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "product": {
    "id": 12,
    "title": "네이버 플레이스 저장",
    "type": "REWARD",
    "type_name": "참여형 리워드",
    "unit_price": 200,
    "quantity_mode": "daily",
    "min_quantity": 10,
    "max_quantity": 10000,
    "min_days": 1,
    "fixed_quantity": null,
    "fixed_days": null,
    "earliest_start_date": "2026-07-31",
    "orderable": true,
    "not_orderable_reason": null,
    "description": "네이버 플레이스 저장(즐겨찾기)을 일 단위로 진행합니다.",
    "fields": [
      {
        "key": "place_url",
        "label": "플레이스 URL",
        "type": "URL",
        "required": true,
        "help": "네이버 지도에서 업체를 검색한 뒤 주소를 복사해 주세요.",
        "options": null,
        "contains": "place.naver.com",
        "api_supported": true
      },
      {
        "key": "daily_qty",
        "label": "일 수량",
        "type": "NUMBER",
        "required": true,
        "help": null,
        "options": null,
        "contains": null,
        "api_supported": true
      },
      {
        "key": "start_date",
        "label": "시작일",
        "type": "DATE",
        "required": true,
        "help": null,
        "options": null,
        "contains": null,
        "api_supported": true
      },
      {
        "key": "end_date",
        "label": "종료일",
        "type": "DATE",
        "required": true,
        "help": null,
        "options": null,
        "contains": null,
        "api_supported": true
      }
    ]
  }
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:210px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">product.*</code></td><td>-</td><td><code class="doc-code">GET /products</code> 의 상품 필드 전부 동일하게 포함</td></tr>
                    <tr><td><code class="doc-code">product.description</code></td><td>string|null</td><td>상품 설명(상세 조회에만 포함)</td></tr>
                    <tr><td><code class="doc-code">product.fields[].key</code></td><td>string</td><td>주문 시 <code class="doc-code">fields</code> 객체에 쓰는 키(<code class="doc-code">daily_qty</code>·<code class="doc-code">start_date</code>·<code class="doc-code">end_date</code> 는 수량·기간 시스템 필드). 숨김(내부) 필드의 키는 보내도 무시됩니다</td></tr>
                    <tr><td><code class="doc-code">product.fields[].label</code></td><td>string</td><td>필드 이름(오류 메시지에 그대로 등장)</td></tr>
                    <tr><td><code class="doc-code">product.fields[].type</code></td><td>string</td><td><code class="doc-code">TEXT</code>·<code class="doc-code">TEXTAREA</code>·<code class="doc-code">URL</code>·<code class="doc-code">NUMBER</code>·<code class="doc-code">SELECT</code>·<code class="doc-code">MULTI_SELECT</code>·<code class="doc-code">TOGGLE</code>·<code class="doc-code">DATE</code>·<code class="doc-code">FILE</code>·<code class="doc-code">IMAGE</code>·<code class="doc-code">ADDRESS</code>·<code class="doc-code">MISSION_OPTIONS</code>·<code class="doc-code">TAGS</code></td></tr>
                    <tr><td><code class="doc-code">product.fields[].required</code></td><td>bool</td><td>필수 여부. 누락 시 422 <code class="doc-code">f_{필드키}</code>. <b class="text-ink">단 숨김(내부) 필드는 예외</b> — <code class="doc-code">true</code> 여도 검증하지 않고 보낸 값도 무시되며, 응답만으로는 숨김 여부를 알 수 없습니다(기본값이 <code class="doc-code">true</code> 라 숨김 + <code class="doc-code">required: true</code> 조합이 흔합니다)</td></tr>
                    <tr><td><code class="doc-code">product.fields[].help</code></td><td>string|null</td><td>입력 도움말</td></tr>
                    <tr><td><code class="doc-code">product.fields[].options</code></td><td>array|null</td><td><code class="doc-code">SELECT</code>·<code class="doc-code">MULTI_SELECT</code> 의 <code class="doc-code">{value, label}</code> 목록. 그 외에는 <code class="doc-code">null</code></td></tr>
                    <tr><td><code class="doc-code">product.fields[].contains</code></td><td>string|null</td><td>입력값에 반드시 포함돼야 하는 문자열(위반 시 422). 반대 규칙인 금지 문자열(<code class="doc-code">not_contains</code>)은 <b class="text-ink">이 응답에 포함되지 않습니다</b></td></tr>
                    <tr><td><code class="doc-code">product.fields[].api_supported</code></td><td>bool</td><td><code class="doc-code">false</code> = <code class="doc-code">FILE</code>·<code class="doc-code">IMAGE</code> 필드로 API 로는 값을 보낼 수 없음</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-post">POST</span>
            <code class="ep-p">/orders</code>
            <span class="ep-s">주문 생성 (분당 30회)</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">상품을 주문 접수합니다. 성공 시 <b class="text-ink">201 Created</b> 로 응답하며, 생성된 주문은 <code class="doc-code">pending</code>(접수) 상태이고 운영자 승인 후 진행됩니다. 상품별 입력 필드는 <code class="doc-code">fields</code> 객체에 <code class="doc-code">{"필드키": "값"}</code> 형태로 담습니다(값이 배열이면 <b class="text-ink">필드 타입과 무관하게</b> 문자열 배열로 받습니다 — <code class="doc-code">MULTI_SELECT</code>·<code class="doc-code">TAGS</code>·<code class="doc-code">MISSION_OPTIONS</code> 등. 배열 안의 비스칼라 원소는 버려집니다). 위 <b class="text-ink">공통 주문 규칙</b>이 그대로 적용됩니다. 요청 제한은 분당 30회입니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">product_id</code></td><td><span class="req-y">필수</span></td><td>int</td><td>상품 번호. 없거나 판매 중이 아니면 404</td></tr>
                    <tr><td><code class="doc-code">quantity</code></td><td><span class="req-n">선택</span></td><td>int</td><td>수량(1 이상). 상품에 <code class="doc-code">daily_qty</code> 필드가 있으면 무시되고 <code class="doc-code">fields.daily_qty</code> 가 쓰임. <code class="doc-code">fixed_quantity</code> 상품은 고정값으로 강제. <b class="text-ink"><code class="doc-code">daily_qty</code> 필드도 <code class="doc-code">fixed_quantity</code> 도 없는 상품에서는 사실상 필수</b> — 미전달 시 0 으로 평가돼 422 <code class="doc-code">field: "quantity"</code></td></tr>
                    <tr><td><code class="doc-code">days</code></td><td><span class="req-n">선택</span></td><td>int</td><td>기간(일, 1 이상). 상품에 <code class="doc-code">start_date</code>·<code class="doc-code">end_date</code> 필드가 <b class="text-ink">둘 다</b> 있으면 무시되고 날짜로 계산. 미전달 시 <code class="doc-code">min_days</code>. <code class="doc-code">fixed_days</code> 상품(<code class="doc-code">quantity_mode: "daily"</code>)은 고정값으로 강제</td></tr>
                    <tr><td><code class="doc-code">fields</code></td><td><span class="req-n">선택</span></td><td>object</td><td>상품 상세의 <code class="doc-code">fields</code> 스펙대로 <code class="doc-code">필드키 → 값</code>. 필수 필드가 있는 상품에서는 사실상 필수</td></tr>
                    <tr><td><code class="doc-code">user_coupon_id</code></td><td><span class="req-n">선택</span></td><td>int</td><td>사용할 보유 쿠폰 발급분 ID. 할인은 서버가 재계산</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -X POST {{ url('/api/v1') }}/orders \
  -H "Authorization: Bearer rk_..." \
  -H "Content-Type: application/json" \
  -d '{"product_id": 12, "fields": {"place_url": "https://m.place.naver.com/restaurant/1234567890", "daily_qty": "100", "start_date": "2026-08-01", "end_date": "2026-08-07"}}'</pre></div>
            <div class="ep-l">응답 예시 (201 Created)</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "order": {
    "order_no": "MO2607291A2B3C",
    "status": "pending",
    "status_label": "접수",
    "product": {
      "id": 12,
      "title": "네이버 플레이스 저장"
    },
    "quantity": 100,
    "days": 7,
    "unit_price": 200,
    "discount_amount": 0,
    "total_price": 140000,
    "fields": {
      "place_url": "https://m.place.naver.com/restaurant/1234567890",
      "daily_qty": "100",
      "start_date": "2026-08-01",
      "end_date": "2026-08-07"
    },
    "created_at": "2026-07-29T14:30:00+09:00"
  }
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:210px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">order.order_no</code></td><td>string</td><td>주문번호 — <code class="doc-code">GET /orders/{orderNo}</code> 조회 키</td></tr>
                    <tr><td><code class="doc-code">order.status</code></td><td>string</td><td><code class="doc-code">pending</code>·<code class="doc-code">processing</code>·<code class="doc-code">completed</code>·<code class="doc-code">canceled</code>. 생성 직후는 항상 <code class="doc-code">pending</code></td></tr>
                    <tr><td><code class="doc-code">order.status_label</code></td><td>string</td><td>상태 한글 표기(접수·진행중·완료·취소)</td></tr>
                    <tr><td><code class="doc-code">order.product.id</code></td><td>int|null</td><td>주문 상품 번호</td></tr>
                    <tr><td><code class="doc-code">order.product.title</code></td><td>string|null</td><td>주문 상품명</td></tr>
                    <tr><td><code class="doc-code">order.quantity</code></td><td>int</td><td>서버가 확정한 수량(고정 수량 상품이면 고정값)</td></tr>
                    <tr><td><code class="doc-code">order.days</code></td><td>int|null</td><td>서버가 확정한 기간(일). <code class="doc-code">quantity_mode: "total"</code> 상품은 <code class="doc-code">null</code>(<code class="doc-code">fixed_days</code> 가 있어도 무시)</td></tr>
                    <tr><td><code class="doc-code">order.unit_price</code></td><td>int</td><td>주문 시점 단가(원)</td></tr>
                    <tr><td><code class="doc-code">order.discount_amount</code></td><td>int</td><td>쿠폰 할인액(원). 쿠폰 미사용이면 0</td></tr>
                    <tr><td><code class="doc-code">order.total_price</code></td><td>int</td><td>최종 결제 금액(원) = 단가 × 수량 × 일수 − 할인액</td></tr>
                    <tr><td><code class="doc-code">order.fields</code></td><td>object</td><td>실제 저장된 입력값(URL 정규화·고정값 덮어쓰기·종료일 재계산·숨김 필드 기본값이 반영된 값). 값이 없으면 빈 객체</td></tr>
                    <tr><td><code class="doc-code">order.created_at</code></td><td>string</td><td>접수 일시(ISO 8601, KST)</td></tr>
                </tbody>
            </table>
            <div class="ep-l">오류 응답</div>
            <table class="doc-table">
                <thead><tr><th style="width:70px;">코드</th><th style="width:190px;"><code class="doc-code">field</code></th><th>상황</th></tr></thead>
                <tbody>
                    <tr><td>404</td><td><code class="doc-code">product_id</code></td><td>상품이 없거나 판매 중이 아님</td></tr>
                    <tr><td>422</td><td><code class="doc-code">product_id</code></td><td>필수 파일 첨부 필드가 있는 상품(API 주문 불가)</td></tr>
                    <tr><td>422</td><td><code class="doc-code">f_{필드키}</code></td><td>필수 필드 누락, <code class="doc-code">earliest_start_date</code> 이전 날짜, <code class="doc-code">contains</code> 불일치, <code class="doc-code">not_contains</code>(금지 문자열) 포함 등 동적 필드 검증 실패</td></tr>
                    <tr><td>422</td><td><code class="doc-code">quantity</code></td><td>수량이 <code class="doc-code">min_quantity</code> ~ <code class="doc-code">max_quantity</code> 범위 밖(<code class="doc-code">quantity</code> 미전달로 0 인 경우 포함)</td></tr>
                    <tr><td>422</td><td><code class="doc-code">days</code></td><td>시작일·종료일 누락, 종료일이 시작일보다 이전, 최소 기간 미달</td></tr>
                    <tr><td>422</td><td><code class="doc-code">user_coupon_id</code></td><td>사용 불가 쿠폰(만료·사용됨·중지), 상품 미적용, 최소 주문 금액 미달</td></tr>
                </tbody>
            </table>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "message": "'플레이스 URL' 항목을 입력하세요.",
  "field": "f_place_url"
}</pre></div>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/orders</code>
            <span class="ep-s">내 주문 목록</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">API 키 소유 계정의 주문을 <b class="text-ink">최신순</b>으로 반환합니다. 상태 필터와 페이지네이션을 지원하며, 각 항목의 구조는 <code class="doc-code">POST /orders</code> 의 <code class="doc-code">order</code> 와 동일합니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">status</code></td><td><span class="req-n">선택</span></td><td>string</td><td><code class="doc-code">pending</code>·<code class="doc-code">processing</code>·<code class="doc-code">completed</code>·<code class="doc-code">canceled</code> 중 하나. 알 수 없는 값은 무시되고 전체가 조회됨</td></tr>
                    <tr><td><code class="doc-code">per_page</code></td><td><span class="req-n">선택</span></td><td>int</td><td>페이지당 건수. 기본 20, 1~100 으로 자동 보정</td></tr>
                    <tr><td><code class="doc-code">page</code></td><td><span class="req-n">선택</span></td><td>int</td><td>페이지 번호. 기본 1</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl "{{ url('/api/v1') }}/orders?status=processing&amp;per_page=20&amp;page=1" \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "orders": [
    {
      "order_no": "MO2607291A2B3C",
      "status": "processing",
      "status_label": "진행중",
      "product": {
        "id": 12,
        "title": "네이버 플레이스 저장"
      },
      "quantity": 100,
      "days": 7,
      "unit_price": 200,
      "discount_amount": 10000,
      "total_price": 130000,
      "fields": {
        "place_url": "https://m.place.naver.com/restaurant/1234567890",
        "daily_qty": "100",
        "start_date": "2026-08-01",
        "end_date": "2026-08-07"
      },
      "created_at": "2026-07-29T14:30:00+09:00"
    },
    {
      "order_no": "MO260728D4E5F6",
      "status": "processing",
      "status_label": "진행중",
      "product": {
        "id": 27,
        "title": "블로그 체험단 10인 패키지"
      },
      "quantity": 10,
      "days": null,
      "unit_price": 90000,
      "discount_amount": 0,
      "total_price": 900000,
      "fields": {
        "shop_name": "연남동 소금빵집",
        "guide_note": "매장 외관 사진 필수"
      },
      "created_at": "2026-07-28T10:05:12+09:00"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 37,
    "last_page": 2
  }
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:210px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">orders[]</code></td><td>array</td><td>주문 배열 — 항목 구조는 <code class="doc-code">POST /orders</code> 의 <code class="doc-code">order</code> 와 동일(<code class="doc-code">order_no</code>·<code class="doc-code">status</code>·<code class="doc-code">status_label</code>·<code class="doc-code">product</code>·<code class="doc-code">quantity</code>·<code class="doc-code">days</code>·<code class="doc-code">unit_price</code>·<code class="doc-code">discount_amount</code>·<code class="doc-code">total_price</code>·<code class="doc-code">fields</code>·<code class="doc-code">created_at</code>)</td></tr>
                    <tr><td><code class="doc-code">meta.page</code></td><td>int</td><td>현재 페이지 번호</td></tr>
                    <tr><td><code class="doc-code">meta.per_page</code></td><td>int</td><td>페이지당 건수(보정된 실제 값)</td></tr>
                    <tr><td><code class="doc-code">meta.total</code></td><td>int</td><td>필터 적용 후 전체 주문 수</td></tr>
                    <tr><td><code class="doc-code">meta.last_page</code></td><td>int</td><td>마지막 페이지 번호 — <code class="doc-code">page</code> 가 이 값에 도달하면 순회 종료</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/orders/{orderNo}</code>
            <span class="ep-s">주문 단건 조회 · 상태 확인</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">주문번호로 <b class="text-ink">본인 주문 한 건</b>을 조회합니다. 진행 상태(<code class="doc-code">status</code>) 확인용으로 사용하세요. 다른 계정의 주문이거나 없는 주문번호면 404 입니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">orderNo</code></td><td><span class="req-y">필수</span></td><td>string</td><td>경로 파라미터 — 주문번호(<code class="doc-code">order.order_no</code>)</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl {{ url('/api/v1') }}/orders/MO2607291A2B3C \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "order": {
    "order_no": "MO2607291A2B3C",
    "status": "completed",
    "status_label": "완료",
    "product": {
      "id": 12,
      "title": "네이버 플레이스 저장"
    },
    "quantity": 100,
    "days": 7,
    "unit_price": 200,
    "discount_amount": 10000,
    "total_price": 130000,
    "fields": {
      "place_url": "https://m.place.naver.com/restaurant/1234567890",
      "daily_qty": "100",
      "start_date": "2026-08-01",
      "end_date": "2026-08-07"
    },
    "created_at": "2026-07-29T14:30:00+09:00"
  }
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:210px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">order.status</code></td><td>string</td><td><code class="doc-code">pending</code>(접수) → <code class="doc-code">processing</code>(진행중) → <code class="doc-code">completed</code>(완료), 또는 <code class="doc-code">canceled</code>(취소)</td></tr>
                    <tr><td><code class="doc-code">order.status_label</code></td><td>string</td><td>상태 한글 표기</td></tr>
                    <tr><td><code class="doc-code">order.*</code></td><td>-</td><td>나머지 필드는 <code class="doc-code">POST /orders</code> 응답과 동일</td></tr>
                </tbody>
            </table>
            <div class="ep-l">오류 응답</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "message": "주문을 찾을 수 없습니다."
}</pre></div>
        </div>
    </div>
</div>

{{-- ============ 쇼핑 유입키워드 ============ --}}
<div class="doc-panel" data-panel="shop_keyword">
    <h2 class="font-display text-ink doc-h2">쇼핑 유입키워드 <span class="badge border border-hairline" style="font-size:var(--fs-xs);vertical-align:middle;">scope: shop_keyword</span></h2>
    <p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;">
        핵심 키워드 하나와 내 상품 URL 로 <b class="text-ink">롱테일 키워드 조합을 자동 생성</b>하고, 각 조합으로 네이버 쇼핑을 검색해
        <b class="text-ink">내 상품이 상위 N위 안에 노출되는 키워드만</b> 골라냅니다. 분석을 만들면 순위 확인이 자동으로 끝까지 진행되며,
        찾아낸 노출 키워드는 그룹으로 나눠 <b class="text-ink">Short URL</b> 로 바로 발급할 수 있습니다(규칙은 관리자 화면과 동일).
    </p>

    <div class="flow">
        <div class="flow-i">
            <div class="flow-n">1단계</div>
            <div class="text-ink mt-2" style="font-size:var(--fs-sm);font-weight:600;">상품 정보 — 자동 수집</div>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);line-height:1.7;">
                보내는 값은 <b class="text-ink">핵심 키워드와 상품 URL 두 개뿐</b>입니다. 상품 제목 · 상점명 · 가격 같은 상품 정보는
                <b class="text-ink">랭크프리가 알아서 수집합니다</b> — 따로 넣을 값이 없습니다.
                수집에는 <b class="text-ink">요청자 계정으로 로그인된 랭크프리 확장 프로그램</b>이 필요하며,
                확장이 켜져 있으면 화면을 열어 둘 필요 없이 자동으로 처리됩니다.
            </p>
        </div>
        <div class="flow-i">
            <div class="flow-n">2단계</div>
            <div class="text-ink mt-2" style="font-size:var(--fs-sm);font-weight:600;">조합 + 순위체크</div>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);line-height:1.7;">
                수집된 상품 정보로 <b class="text-ink">롱테일 키워드를 자동으로 만들어</b>, 키워드마다 쇼핑을 검색해
                <b class="text-ink">내 상품이 상위 N위 안에 노출되는지 확인</b>합니다. 생성 응답은 확인을 기다리지 않고 바로 돌아오므로,
                진행률은 <code class="doc-code">GET /shop-keywords/{id}</code> 의 <code class="doc-code">progress</code> 로 폴링합니다.
            </p>
        </div>
        <div class="flow-i">
            <div class="flow-n">3단계</div>
            <div class="text-ink mt-2" style="font-size:var(--fs-sm);font-weight:600;">Short URL</div>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);line-height:1.7;">
                노출로 판정된 키워드(<code class="doc-code">exposed_keywords</code>)를 원하는 그룹 수로 나눠
                <b class="text-ink">그룹별 단축 URL</b> 을 발급합니다. 발급된 <code class="doc-code">url</code> 과 배정 키워드를 발주 · 배포 시스템이 그대로 가져다 씁니다.
            </p>
        </div>
    </div>

    <table class="doc-table mt-6">
        <thead><tr><th style="width:150px;">항목</th><th>공통 규칙</th></tr></thead>
        <tbody>
            <tr><td>Base URL</td><td><code class="doc-code">{{ url('/api/v1') }}</code> — 아래 모든 경로 앞에 붙습니다.</td></tr>
            <tr><td>인증</td><td><code class="doc-code">Authorization: Bearer rk_...</code> 헤더. API 키에 <code class="doc-code">shop_keyword</code> 스코프가 있어야 합니다.</td></tr>
            <tr><td>소유권</td><td>키 소유자가 만든 분석만 접근할 수 있습니다. 남의 <code class="doc-code">id</code> 를 조회하면 <code class="doc-code">403</code>.</td></tr>
            <tr><td>호출 한도</td><td>생성 · 변경 계열(<span class="doc-method m-post">POST</span>)은 분당 30회.</td></tr>
            <tr><td><code class="doc-code">status</code></td><td><code class="doc-code">pending</code>(상품 정보 수집 대기 — 확장이 채우면 자동으로 <code class="doc-code">checking</code> 으로 넘어갑니다) · <code class="doc-code">checking</code>(확인 중) · <code class="doc-code">done</code>(완료) · <code class="doc-code">blocked</code>(차단으로 중단) · <code class="doc-code">paused</code>(사용자 중단)</td></tr>
            <tr><td>확장 프로그램</td><td>상품 정보 수집은 <b class="text-ink">요청자 계정으로 로그인된 랭크프리 확장</b>이 담당합니다. 확장이 켜져 있지 않으면 <code class="doc-code">pending</code> 에서 더 진행되지 않습니다(화면을 열어 둘 필요는 없습니다).</td></tr>
            <tr><td><code class="doc-code">progress</code></td><td><code class="doc-code">total</code>(전체 조합) · <code class="doc-code">checked</code>(확인 완료) · <code class="doc-code">remaining</code>(남은 조합) · <code class="doc-code">exposed</code>(노출 판정 수). <b class="text-ink">remaining 이 0 이면 확인 종료</b>입니다.</td></tr>
        </tbody>
    </table>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-post">POST</span>
            <code class="ep-p">/shop-keywords</code>
            <span class="ep-s">분석 생성 + 순위확인 자동 시작</span>
        </div>
        <div class="ep-b">
            <p class="ep-t"><b class="text-ink">핵심 키워드와 상품 URL 두 개</b>로 분석을 만듭니다. 상품 정보 수집 · 키워드 생성 · 순위 확인이 이어서 <b class="text-ink">자동으로 진행</b>되며, 응답은 이를 기다리지 않고 바로 돌아옵니다. 진행 상태는 <code class="doc-code">GET /shop-keywords/{id}</code> 로 폴링하세요.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:210px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">core_keyword</code></td><td><span class="req-y">필수</span></td><td>string</td><td>핵심 키워드(최대 120자). 모든 조합에 이 키워드가 포함됩니다.</td></tr>
                    <tr><td><code class="doc-code">product</code></td><td><span class="req-y">필수</span></td><td>string</td><td>내 상품 URL(최대 500자). 스마트스토어 · 브랜드스토어 · 가격비교 catalog URL 을 인식해 <code class="doc-code">product_id</code> 를 자동 추출합니다. URL 이 아니면 업체명으로 취급해 상품이 아닌 <b class="text-ink">업체 단위</b>로 매칭합니다.</td></tr>
                    <tr><td><code class="doc-code">threshold</code></td><td><span class="req-n">선택</span></td><td>int</td><td>노출로 볼 순위 기준 — <b class="text-ink"><code class="doc-code">4</code> 또는 <code class="doc-code">5</code></b>만 쓸 수 있습니다(기본 <code class="doc-code">5</code>). 이 순위 이내면 노출로 판정합니다.</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -X POST {{ url('/api/v1') }}/shop-keywords \
  -H "Authorization: Bearer rk_..." \
  -H "Content-Type: application/json" \
  -d '{
    "core_keyword": "비타민c",
    "product": "https://smartstore.naver.com/healthyday/products/6412870193"
  }'</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">HTTP/1.1 201 Created
{
  "analysis": {
    "id": 42,
    "core_keyword": "비타민c",
    "product_url": "https://smartstore.naver.com/healthyday/products/6412870193",
    "product_id": "6412870193",
    "mall_name": "헬씨데이",
    "threshold": 5,
    "status": "checking",
    "progress": {
      "total": 240,
      "checked": 0,
      "remaining": 240,
      "exposed": 0,
      "blocked": false
    },
    "created_at": "2026-07-29T14:20:11+09:00"
  }
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:210px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">analysis.id</code></td><td>int</td><td>분석 ID. 이후 조회 · Short URL 호출의 <code class="doc-code">{id}</code> 입니다.</td></tr>
                    <tr><td><code class="doc-code">analysis.core_keyword</code></td><td>string</td><td>입력한 핵심 키워드</td></tr>
                    <tr><td><code class="doc-code">analysis.product_url</code></td><td>string</td><td><b class="text-ink">상품 정보</b> — 자동 정리된 상품 URL(추적 파라미터 제거). URL 이 아니면 빈 문자열</td></tr>
                    <tr><td><code class="doc-code">analysis.product_id</code></td><td>string</td><td><b class="text-ink">상품 정보</b> — URL 에서 자동 추출한 상품 ID(스마트스토어 channelProductId 또는 가격비교 nvMid). 업체 매칭이면 빈 문자열</td></tr>
                    <tr><td><code class="doc-code">analysis.mall_name</code></td><td>string</td><td><b class="text-ink">상품 정보</b> — 자동 수집된 상점명(입력이 URL 이 아니면 입력한 업체명). 수집 전에는 빈 문자열</td></tr>
                    <tr><td><code class="doc-code">analysis.threshold</code></td><td>int</td><td>노출 판정 기준 순위</td></tr>
                    <tr><td><code class="doc-code">analysis.status</code></td><td>string</td><td>진행 상태. 조합이 0개면 즉시 <code class="doc-code">done</code></td></tr>
                    <tr><td><code class="doc-code">analysis.progress.total</code></td><td>int</td><td>생성된 조합 수</td></tr>
                    <tr><td><code class="doc-code">analysis.progress.checked</code></td><td>int</td><td>순위 확인이 끝난 조합 수</td></tr>
                    <tr><td><code class="doc-code">analysis.progress.remaining</code></td><td>int</td><td>남은 조합 수(0 이면 확인 종료)</td></tr>
                    <tr><td><code class="doc-code">analysis.progress.exposed</code></td><td>int</td><td>1~<code class="doc-code">threshold</code> 위로 확인된 조합 수</td></tr>
                    <tr><td><code class="doc-code">analysis.progress.blocked</code></td><td>bool</td><td>차단으로 확인이 멈췄는지 여부</td></tr>
                    <tr><td><code class="doc-code">analysis.created_at</code></td><td>string</td><td>생성 시각(ISO 8601, KST)</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/shop-keywords</code>
            <span class="ep-s">내 분석 목록</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">내 API 키로 만든 분석을 최신순으로 조회합니다. 각 항목은 생성 응답과 동일한 <code class="doc-code">analysis</code> 페이로드(진행률 포함)입니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">page</code></td><td><span class="req-n">선택</span></td><td>int</td><td>페이지 번호(기본 1)</td></tr>
                    <tr><td><code class="doc-code">per_page</code></td><td><span class="req-n">선택</span></td><td>int</td><td>페이지당 개수(1~100, 기본 20)</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl "{{ url('/api/v1') }}/shop-keywords?page=1&amp;per_page=20" \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "analyses": [
    {
      "id": 42,
      "core_keyword": "비타민c",
      "product_url": "https://smartstore.naver.com/healthyday/products/6412870193",
      "product_id": "6412870193",
      "mall_name": "헬씨데이",
      "threshold": 5,
      "status": "done",
      "progress": {
        "total": 240,
        "checked": 240,
        "remaining": 0,
        "exposed": 12,
        "blocked": false
      },
      "created_at": "2026-07-29T14:20:11+09:00"
    },
    {
      "id": 41,
      "core_keyword": "루테인",
      "product_url": "https://smartstore.naver.com/healthyday/products/6390112044",
      "product_id": "6390112044",
      "mall_name": "헬씨데이",
      "threshold": 5,
      "status": "checking",
      "progress": {
        "total": 180,
        "checked": 96,
        "remaining": 84,
        "exposed": 5,
        "blocked": false
      },
      "created_at": "2026-07-28T09:41:07+09:00"
    }
  ],
  "page": 1,
  "per_page": 20,
  "total": 2
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">analyses[]</code></td><td>array</td><td>분석 목록(최신순). 각 항목의 구조는 생성 응답의 <code class="doc-code">analysis</code> 와 동일</td></tr>
                    <tr><td><code class="doc-code">page</code></td><td>int</td><td>현재 페이지</td></tr>
                    <tr><td><code class="doc-code">per_page</code></td><td>int</td><td>페이지당 개수</td></tr>
                    <tr><td><code class="doc-code">total</code></td><td>int</td><td>전체 분석 수</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/shop-keywords/{id}</code>
            <span class="ep-s">진행 상태 · 상품 정보 · 노출 키워드</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">분석 하나의 <b class="text-ink">진행률 · 저장된 상품 정보 · 노출 판정 키워드 · 발급된 Short URL</b> 을 한 번에 돌려줍니다. 생성 직후에는 <code class="doc-code">progress.remaining</code> 이 0 이 될 때까지 몇 초 간격으로 폴링하면 됩니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">id</code></td><td><span class="req-y">필수</span></td><td>int</td><td>경로 파라미터. 분석 ID(내 소유가 아니면 <code class="doc-code">403</code>)</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl {{ url('/api/v1') }}/shop-keywords/42 \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "analysis": {
    "id": 42,
    "core_keyword": "비타민c",
    "product_url": "https://smartstore.naver.com/healthyday/products/6412870193",
    "product_id": "6412870193",
    "mall_name": "헬씨데이",
    "threshold": 5,
    "status": "done",
    "progress": {
      "total": 240,
      "checked": 240,
      "remaining": 0,
      "exposed": 12,
      "blocked": false
    },
    "created_at": "2026-07-29T14:20:11+09:00"
  },
  "exposed_keywords": [
    "비타민c 고함량 스틱",
    "헬씨데이 비타민c 1000mg 30포"
  ],
  "short_links": [
    {
      "group_no": 1,
      "url": "https://rankfree.kr/s/AbC123xYz9K",
      "keywords": [
        "비타민c 고함량 스틱"
      ],
      "hit_count": 0
    }
  ]
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:210px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">analysis.id</code></td><td>int</td><td>분석 ID(요청한 <code class="doc-code">{id}</code> 와 동일)</td></tr>
                    <tr><td><code class="doc-code">analysis.product_url</code></td><td>string</td><td><b class="text-ink">상품 정보</b> — 정리된 상품 URL(쿼리스트링 제거)</td></tr>
                    <tr><td><code class="doc-code">analysis.product_id</code></td><td>string</td><td><b class="text-ink">상품 정보</b> — 서버가 URL 에서 자동 추출한 상품 ID</td></tr>
                    <tr><td><code class="doc-code">analysis.mall_name</code></td><td>string</td><td><b class="text-ink">상품 정보</b> — 자동 수집된 상점명. 수집 전에는 빈 문자열</td></tr>
                    <tr><td><code class="doc-code">analysis.core_keyword</code></td><td>string</td><td>핵심 키워드</td></tr>
                    <tr><td><code class="doc-code">analysis.threshold</code></td><td>int</td><td>노출 판정 기준 순위</td></tr>
                    <tr><td><code class="doc-code">analysis.status</code></td><td>string</td><td><code class="doc-code">checking</code>/<code class="doc-code">done</code>/<code class="doc-code">blocked</code>/<code class="doc-code">paused</code></td></tr>
                    <tr><td><code class="doc-code">analysis.progress.*</code></td><td>object</td><td><code class="doc-code">total</code> · <code class="doc-code">checked</code> · <code class="doc-code">remaining</code> · <code class="doc-code">exposed</code> · <code class="doc-code">blocked</code></td></tr>
                    <tr><td><code class="doc-code">analysis.created_at</code></td><td>string</td><td>생성 시각(ISO 8601)</td></tr>
                    <tr><td><code class="doc-code">exposed_keywords</code></td><td>array</td><td><b class="text-ink">노출 판정(1~threshold 위) 키워드 문자열 배열.</b> 확인 순서를 유지하고 중복은 제거합니다. 3단계 Short URL 의 재료입니다.</td></tr>
                    <tr><td><code class="doc-code">short_links[].group_no</code></td><td>int</td><td>그룹 번호(1부터)</td></tr>
                    <tr><td><code class="doc-code">short_links[].url</code></td><td>string</td><td>발급된 Short URL(아직 생성 전이면 배열이 비어 있음)</td></tr>
                    <tr><td><code class="doc-code">short_links[].keywords</code></td><td>array</td><td>이 그룹에 배정된 키워드</td></tr>
                    <tr><td><code class="doc-code">short_links[].hit_count</code></td><td>int</td><td>이 링크가 호출된 횟수</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-post">POST</span>
            <code class="ep-p">/shop-keywords/{id}/short-links</code>
            <span class="ep-s">Short URL 생성</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">노출 판정 키워드를 <code class="doc-code">group_count</code> 개 그룹으로 나눠 그룹마다 Short URL 을 새로 발급합니다. 기존 링크는 교체되므로, 이미 배포해 호출이 발생한 링크가 있다면 이 엔드포인트 대신 <code class="doc-code">/reassign</code> 을 사용하세요.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">id</code></td><td><span class="req-y">필수</span></td><td>int</td><td>경로 파라미터. 분석 ID</td></tr>
                    <tr><td><code class="doc-code">group_count</code></td><td><span class="req-y">필수</span></td><td>int</td><td>만들 그룹(=Short URL) 수(1~100). <b class="text-ink">노출 키워드 수보다 클 수 없습니다.</b></td></tr>
                </tbody>
            </table>
            <div class="ep-l">Short URL 규칙</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">항목</th><th>규칙</th></tr></thead>
                <tbody>
                    <tr><td>그룹 분배</td><td>노출 키워드를 순서대로 그룹에 <b class="text-ink">라운드로빈으로 고르게</b> 나눕니다(1번 → 2번 → … → 1번).</td></tr>
                    <tr><td>재생성 제한</td><td>이미 호출된 적이 있는 링크(<code class="doc-code">hit_count &gt; 0</code>)가 하나라도 있으면 생성이 막힙니다 — 배포한 주소가 죽지 않도록 하기 위함입니다. 이때는 <code class="doc-code">/reassign</code> 으로 키워드만 교체하세요.</td></tr>
                    <tr><td>실패 응답</td><td>노출 키워드 없음 · 그룹 수 초과 · 호출된 링크 존재는 <code class="doc-code">422</code> + <code class="doc-code">message</code> + <code class="doc-code">field</code>(<code class="doc-code">group_count</code>)</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -X POST {{ url('/api/v1') }}/shop-keywords/42/short-links \
  -H "Authorization: Bearer rk_..." \
  -H "Content-Type: application/json" \
  -d '{"group_count": 2}'</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">HTTP/1.1 201 Created
{
  "short_links": [
    {
      "group_no": 1,
      "url": "https://rankfree.kr/s/AbC123xYz9K",
      "keywords": [
        "비타민c 고함량 스틱",
        "비타민c 스틱 30포"
      ],
      "hit_count": 0
    },
    {
      "group_no": 2,
      "url": "https://rankfree.kr/s/Qm7RtV2wLp0",
      "keywords": [
        "헬씨데이 비타민c 1000mg 30포"
      ],
      "hit_count": 0
    }
  ]
}</pre></div>
            <div class="ep-l">실패 응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">HTTP/1.1 422
{
  "message": "Short URL 개수는 상위 노출 키워드 수보다 많을 수 없습니다.",
  "field": "group_count"
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">short_links[].group_no</code></td><td>int</td><td>그룹 번호(1부터 오름차순)</td></tr>
                    <tr><td><code class="doc-code">short_links[].url</code></td><td>string</td><td>발급된 Short URL(보조 도메인이 설정돼 있으면 그룹별로 번갈아 사용)</td></tr>
                    <tr><td><code class="doc-code">short_links[].keywords</code></td><td>array</td><td>이 그룹에 배정된 노출 키워드</td></tr>
                    <tr><td><code class="doc-code">short_links[].hit_count</code></td><td>int</td><td>호출 횟수(생성 직후 0)</td></tr>
                    <tr><td><code class="doc-code">message</code></td><td>string</td><td>실패(422) 시 사유</td></tr>
                    <tr><td><code class="doc-code">field</code></td><td>string</td><td>실패(422) 시 원인 필드 — <code class="doc-code">group_count</code></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/shop-keywords/{id}/short-links</code>
            <span class="ep-s">Short URL 목록</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">발급된 Short URL 만 가볍게 조회합니다. 발주 · 배포 시스템이 링크와 배정 키워드를 그대로 가져다 쓰거나, <code class="doc-code">hit_count</code> 로 호출량을 확인할 때 사용합니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">id</code></td><td><span class="req-y">필수</span></td><td>int</td><td>경로 파라미터. 분석 ID</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl {{ url('/api/v1') }}/shop-keywords/42/short-links \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "short_links": [
    {
      "group_no": 1,
      "url": "https://rankfree.kr/s/AbC123xYz9K",
      "keywords": [
        "비타민c 고함량 스틱",
        "비타민c 스틱 30포"
      ],
      "hit_count": 128
    },
    {
      "group_no": 2,
      "url": "https://rankfree.kr/s/Qm7RtV2wLp0",
      "keywords": [
        "헬씨데이 비타민c 1000mg 30포"
      ],
      "hit_count": 96
    }
  ]
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">short_links[].group_no</code></td><td>int</td><td>그룹 번호</td></tr>
                    <tr><td><code class="doc-code">short_links[].url</code></td><td>string</td><td>Short URL</td></tr>
                    <tr><td><code class="doc-code">short_links[].keywords</code></td><td>array</td><td>현재 배정된 키워드</td></tr>
                    <tr><td><code class="doc-code">short_links[].hit_count</code></td><td>int</td><td>누적 호출 횟수</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-post">POST</span>
            <code class="ep-p">/shop-keywords/{id}/short-links/reassign</code>
            <span class="ep-s">키워드만 재배정(URL 유지)</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">이미 배포한 <b class="text-ink">Short URL 주소는 그대로 두고 배정 키워드만 다시 나눕니다.</b> 순위 확인이 더 진행돼 노출 키워드가 늘었을 때 사용합니다(생성은 호출된 링크가 있으면 막히므로 이쪽을 쓰세요). 요청 본문은 필요 없고, 그룹 수는 기존 링크 수를 그대로 유지합니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:150px;">파라미터</th><th style="width:64px;">필수</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">id</code></td><td><span class="req-y">필수</span></td><td>int</td><td>경로 파라미터. 분석 ID. <b class="text-ink">본문 파라미터는 없습니다.</b></td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -X POST {{ url('/api/v1') }}/shop-keywords/42/short-links/reassign \
  -H "Authorization: Bearer rk_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "short_links": [
    {
      "group_no": 1,
      "url": "https://rankfree.kr/s/AbC123xYz9K",
      "keywords": [
        "비타민c 고함량 스틱",
        "비타민c 스틱 30포 휴대용"
      ],
      "hit_count": 128
    },
    {
      "group_no": 2,
      "url": "https://rankfree.kr/s/Qm7RtV2wLp0",
      "keywords": [
        "헬씨데이 비타민c 1000mg 30포",
        "비타민c 고함량 1000mg 스틱"
      ],
      "hit_count": 96
    }
  ]
}</pre></div>
            <div class="ep-l">실패 응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">HTTP/1.1 422
{
  "message": "재배정할 Short URL이 없습니다. 먼저 Short URL을 생성하세요.",
  "field": "short_links"
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">short_links[].group_no</code></td><td>int</td><td>그룹 번호(1부터 다시 매김)</td></tr>
                    <tr><td><code class="doc-code">short_links[].url</code></td><td>string</td><td><b class="text-ink">변경되지 않습니다</b> — 기존 주소 유지</td></tr>
                    <tr><td><code class="doc-code">short_links[].keywords</code></td><td>array</td><td>새로 배정된 키워드</td></tr>
                    <tr><td><code class="doc-code">short_links[].hit_count</code></td><td>int</td><td>누적 호출 횟수(초기화되지 않음)</td></tr>
                    <tr><td><code class="doc-code">message</code></td><td>string</td><td>실패(422) 시 사유 — 링크 없음 · 노출 키워드 없음 · 링크 수가 노출 키워드 수보다 많음</td></tr>
                    <tr><td><code class="doc-code">field</code></td><td>string</td><td>실패(422) 시 <code class="doc-code">short_links</code></td></tr>
                </tbody>
            </table>
        </div>
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

// 코드블록 복사
document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.doc-copy') : null;
    if (!btn) return;
    var pre = btn.parentElement.querySelector('pre');
    if (!pre || !navigator.clipboard) return;
    navigator.clipboard.writeText(pre.textContent).then(function () {
        btn.textContent = '복사됨';
        setTimeout(function () { btn.textContent = '복사'; }, 1200);
    }).catch(function () {});
});
</script>
