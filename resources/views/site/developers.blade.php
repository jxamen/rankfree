@extends('layouts.site')

@section('title', 'API 문서 · rankfree')
@section('description', 'rankfree 순위추적·경쟁분석·키워드분석 REST API 문서 — 인증, 엔드포인트, 요청/응답 예시')

@section('content')
<style>
    .doc-h2 { font-size:var(--fs-xl); line-height: 1.3; margin-top: 48px; }
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
</style>

<section class="container-page" style="padding-top:48px;padding-bottom:96px;max-width:880px;">
    <div class="badge mb-4 border border-hairline">개발자 문서</div>
    <h1 class="font-display text-ink" style="font-size:clamp(28px,4vw,40px);line-height:1.1;">rankfree API</h1>
    <p class="mt-4 text-body" style="font-size:var(--fs-base);line-height:1.7;">
        네이버 플레이스 <b class="text-ink">순위추적</b>, <b class="text-ink">경쟁분석</b>, <b class="text-ink">키워드분석</b> 데이터를
        REST API로 제공합니다. API 키는 <a href="{{ route('console.api-keys') }}" class="text-accent">콘솔 → API 키</a>에서
        발급하며, 키마다 권한(scope)·허용기간·일일 호출 한도·허용 IP를 설정할 수 있습니다.
    </p>

    {{-- ============ 인증 ============ --}}
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

    {{-- ============ 오류 ============ --}}
    <h2 class="font-display text-ink doc-h2">오류 코드</h2>
    <table class="doc-table mt-4">
        <thead><tr><th style="width:80px;">코드</th><th>의미</th></tr></thead>
        <tbody>
            <tr><td><code class="doc-code">401</code></td><td>키 없음/잘못됨 · 비활성화됨 · 유효기간 만료</td></tr>
            <tr><td><code class="doc-code">403</code></td><td>허용되지 않은 IP · 키에 없는 권한(scope)의 엔드포인트 호출</td></tr>
            <tr><td><code class="doc-code">404</code></td><td>리소스 없음 (내 소유가 아닌 슬롯 포함)</td></tr>
            <tr><td><code class="doc-code">422</code></td><td>요청 파라미터 검증 실패</td></tr>
            <tr><td><code class="doc-code">429</code></td><td>일일 호출 한도 초과 · 네이버 조회 일시 제한(blocked)</td></tr>
        </tbody>
    </table>

    {{-- ============ 순위추적 ============ --}}
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

    {{-- ============ 경쟁분석 ============ --}}
    <h2 class="font-display text-ink doc-h2">경쟁분석 <span class="badge border border-hairline" style="font-size:var(--fs-xs);vertical-align:middle;">scope: compete</span></h2>
    <p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;">
        키워드 상위 노출 경쟁 업체와 내 플레이스의 SEO 신호를 비교·점수화합니다.
        N1/N2/N3·D1~D10 점수는 <b class="text-ink">rankfree 자체 추정치</b>이며 네이버 공식 지표가 아닙니다.
    </p>
    <table class="doc-table mt-4">
        <thead><tr><th style="width:340px;">엔드포인트</th><th>설명</th></tr></thead>
        <tbody>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/compete/tracks</code></td><td>슬롯별 최신 분석 요약(N1/N2/N3·순위)</td></tr>
            <tr><td><span class="doc-method m-get">GET</span> <code class="doc-code">/compete/{slotId}</code></td><td>최신 분석 상세 — 경쟁셋 비교(<code class="doc-code">rows</code>), 내 점수 해설(<code class="doc-code">explain</code>), 추이(<code class="doc-code">series</code>)</td></tr>
            <tr><td><span class="doc-method m-post">POST</span> <code class="doc-code">/compete/{slotId}/analyze</code></td><td>분석 실행 — <code class="doc-code">detail_top</code>(기본 10). 동기 처리로 수십 초 소요, 차단 시 <code class="doc-code">429 blocked</code></td></tr>
        </tbody>
    </table>

    {{-- ============ 키워드분석 ============ --}}
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
    "related": [{"keyword": "강남역미용실", "monthly_total": 4300}]
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
    }
  }
}</pre>

    <div class="card-soft mt-12 p-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">API 키가 필요하신가요?</div>
            <p class="text-muted mt-1" style="font-size:var(--fs-xs);">콘솔에서 직접 발급하고 권한·기간·한도·IP를 설정할 수 있습니다.</p>
        </div>
        <a href="{{ route('console.api-keys') }}" class="btn btn-primary btn-sm">API 키 발급</a>
    </div>
</section>
@endsection
