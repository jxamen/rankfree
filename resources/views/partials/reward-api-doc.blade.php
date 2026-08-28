{{-- 리워드 미션 API 문서 본문 — 매체 파트너용. 광고주용 문서(partials/developers-doc)와 의도적으로 분리한다.
     대상 독자가 다르다: 저쪽은 데이터·주문을 쓰는 광고주, 이쪽은 참여를 공급하는 매체. --}}
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
    /* 엔드포인트 카드 — 요청표 · 요청예시 · 응답예시 · 응답필드표를 한 카드에 순서대로 */
    .ep { border: 1px solid var(--color-hairline); border-radius: 16px; overflow: hidden; margin-top: 14px; background: var(--color-canvas); }
    .ep-h { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 12px 16px; background: var(--color-surface-soft); border-bottom: 1px solid var(--color-hairline); }
    .ep-p { font-family: var(--font-mono); font-size:var(--fs-sm); color: var(--color-ink); font-weight: 600; }
    .ep-s { color: var(--color-muted); font-size:var(--fs-xs); }
    .ep-b { padding: 16px; }
    .ep-t { font-size:var(--fs-sm); line-height: 1.7; color: var(--color-body,var(--color-ink)); }
    .ep-l { font-size:var(--fs-xs); font-weight: 700; color: var(--color-muted); margin-top: 18px; margin-bottom: 6px; letter-spacing: .02em; }
    .ep-l.first { margin-top: 12px; }
    .doc-copy-wrap { position: relative; }
    .doc-copy {
        position: absolute; top: 8px; right: 8px; z-index: 1;
        border: 1px solid var(--color-hairline); background: var(--color-canvas); color: var(--color-muted);
        font-size: var(--fs-xs); padding: 3px 10px; border-radius: var(--radius-pill, 100px); cursor: pointer; font-family: inherit;
    }
    .doc-copy:hover { border-color: var(--color-primary); color: var(--color-primary); }
</style>

<p class="text-body" style="font-size:var(--fs-sm);line-height:1.75;">
    광고주가 발주한 <b class="text-ink">참여형 미션</b>을 API로 받아, 여러분의 사용자에게 노출하고 참여 결과를 제출하는 연동입니다.
    오퍼월 · 미니앱 · 포인트 앱처럼 <b class="text-ink">사용자를 보유한 제휴 매체</b>가 대상이며, <b class="text-ink">수락된 참여 건수로 정산</b>합니다.
</p>

<h2 class="font-display text-ink doc-h2">시작하기</h2>
<p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;">
    연동은 <b class="text-ink">제휴 매체 등록 → 전용 키 수령 → 미션 수신 → 참여 제출 → 정산 대조</b> 순서로 진행합니다.
    운영자가 매체를 등록하면 <b class="text-ink">그 매체의 전용 키</b>가 함께 발급되어 전달됩니다 — 받은 키를 그대로 헤더에 넣으면 바로 호출됩니다.
</p>
<table class="doc-table mt-4">
    <thead><tr><th style="width:120px;">항목</th><th>값</th></tr></thead>
    <tbody>
        <tr><td>Base URL</td><td><code class="doc-code">{{ url('/api/v1') }}</code></td></tr>
        <tr><td>인증 헤더</td><td><code class="doc-code">Authorization: Bearer rkm_…</code> 또는 <code class="doc-code">X-API-KEY: rkm_…</code></td></tr>
        <tr><td>키</td><td><b class="text-ink">제휴 매체 전용 키</b>(<code class="doc-code">rkm_</code> 로 시작) — 운영자가 매체를 등록할 때 발급해 전달합니다. 랭크프리 <b class="text-ink">회원 API 키와는 별개</b>라 회원가입이 필요 없습니다</td></tr>
        <tr><td>응답 형식</td><td>JSON (UTF-8). 시각은 ISO-8601 한국 시간(<code class="doc-code">+09:00</code>)</td></tr>
    </tbody>
</table>

<div class="doc-copy-wrap mt-3"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -X POST "{{ url('/api/v1') }}/missions/assign" \
     -H "Authorization: Bearer rkm_xxxxxxxxxxxxxxxx" \
     -H "Content-Type: application/json" \
     -d '{"participant_hash":"u_9f2c1a"}'</pre></div>

<h2 class="font-display text-ink doc-h2">오류 코드</h2>
<table class="doc-table mt-4">
    <thead><tr><th style="width:80px;">코드</th><th>의미</th></tr></thead>
    <tbody>
        <tr><td><code class="doc-code">400</code></td><td><code class="doc-code">Idempotency-Key</code> 헤더 누락 (참여 제출)</td></tr>
        <tr><td><code class="doc-code">401</code></td><td>키 없음/잘못됨 · 재발급으로 무효가 된 키 · <b class="text-ink">회원 API 키로 호출</b>(체계가 다릅니다)</td></tr>
        <tr><td><code class="doc-code">403</code></td><td><b class="text-ink">제휴 매체가 중지 상태</b> — 운영자에게 문의하세요</td></tr>
        <tr><td><code class="doc-code">410</code></td><td>존재하지 않는 미션</td></tr>
        <tr><td><code class="doc-code">422</code></td><td>파라미터 검증 실패 · 참여 거절(<code class="doc-code">reason</code> 동봉)</td></tr>
        <tr><td><code class="doc-code">429</code></td><td>제휴 매체 초당 호출 한도 초과 — 백오프 후 재시도</td></tr>
    </tbody>
</table>

<h2 class="font-display text-ink doc-h2">연동 방식과 규칙</h2>

    <p class="mt-3 text-body" style="font-size:var(--fs-sm);line-height:1.7;"><b class="text-ink">미션을 받는 두 가지 방식</b> — 화면 구성에 맞는 쪽을 고르면 됩니다. 제출 이후는 완전히 동일합니다.</p>
    <table class="doc-table mt-2">
        <thead><tr><th style="width:150px;">방식</th><th>흐름</th></tr></thead>
        <tbody>
            <tr>
                <td><b class="text-ink">목록형</b><br><span class="text-muted" style="font-size:var(--fs-xs);">오퍼월</span></td>
                <td><code class="doc-code">GET /missions</code> 로 목록을 받아 화면에 나열 → 사용자가 고르면 <code class="doc-code">GET /missions/{id}</code> 로 참여 자격을 확인하고 상세를 받음 → 수행 → 제출</td>
            </tr>
            <tr>
                <td><b class="text-ink">단건형</b><br><span class="text-muted" style="font-size:var(--fs-xs);">미니앱·퀴즈형</span></td>
                <td>“미션 참여하기” 한 번에 <code class="doc-code">POST /missions/assign</code> → 서버가 참여 가능한 미션 하나를 골라 상세까지 한 번에 내려줌 → 수행 → 제출</td>
            </tr>
        </tbody>
    </table>

    <p class="mt-5 text-body" style="font-size:var(--fs-sm);line-height:1.7;"><b class="text-ink">연동 전 알아야 할 규칙</b></p>
    <table class="doc-table mt-2">
        <thead><tr><th style="width:190px;">항목</th><th>규칙</th></tr></thead>
        <tbody>
            <tr>
                <td><code class="doc-code">kind</code><br><span class="text-muted" style="font-size:var(--fs-xs);">미션 유형</span></td>
                <td>미션은 <b class="text-ink">쇼핑(<code class="doc-code">shopping</code>) · 플레이스(<code class="doc-code">place</code>) · 몰(<code class="doc-code">mall</code>) · 웹(<code class="doc-code">web</code>)</b> 으로 나뉩니다. 목록·단건 할당 모두 <code class="doc-code">kind</code> 로 <b class="text-ink">원하는 유형만</b> 받을 수 있고(쉼표로 여러 개), 응답의 <code class="doc-code">mission.kind</code> 로 유형을 확인합니다. 지금 쓸 수 있는 유형 키는 목록 응답의 <code class="doc-code">meta.kinds</code> 에 함께 옵니다</td>
            </tr>
            <tr>
                <td><code class="doc-code">participant_hash</code></td>
                <td>여러분 서비스의 사용자 식별자를 <b class="text-ink">해시 등 비식별 문자열</b>로 보낸 값(최대 128자). 개인정보를 그대로 보내지 마세요. 참여 한도·중복 판정의 기준이며, <b class="text-ink">같은 값 = 같은 사용자</b>입니다</td>
            </tr>
            <tr>
                <td><code class="doc-code">remaining</code></td>
                <td><b class="text-ink">지금 받을 수 있는 남은 수량</b>입니다. 수시로 변하므로 캐시하지 마세요. 0이 되어 목록에서 사라진 미션도 <b class="text-ink">나중에 다시 나올 수 있으니</b> “종료”로 처리하지 마세요</td>
            </tr>
            <tr>
                <td><code class="doc-code">landingUrl</code></td>
                <td>참여자를 보낼 <b class="text-ink">단축 URL</b>입니다. 미션마다 다르며, <b class="text-ink">이 주소로 이동시켜야 참여가 집계</b>됩니다. 주소를 가공하거나 상품 원본 주소로 바꾸지 마세요</td>
            </tr>
            <tr>
                <td>정답</td>
                <td>정답과 해시태그 목록은 <b class="text-ink">어떤 응답에도 포함되지 않습니다</b>. 퀴즈형 미션은 <code class="doc-code">quiz.tagIndex</code>(몇 번째 태그를 묻는지)만 내려가며 <b class="text-ink">참여자마다 번호가 다릅니다</b>. 채점은 서버가 합니다</td>
            </tr>
            <tr>
                <td>미션 수령은 예약이 아님</td>
                <td>목록·상세·할당으로 받은 미션이 <b class="text-ink">제출 시점엔 소진됐을 수 있습니다</b>. 참여 확정은 제출 시점에 이뤄지므로, 수량 소진으로 거절될 수 있다는 전제로 구현하세요</td>
            </tr>
            <tr>
                <td><code class="doc-code">Idempotency-Key</code></td>
                <td>제출에 <b class="text-ink">필수</b>(최대 80자, 요청마다 새 UUID 권장). 네트워크 오류·타임아웃 시 <b class="text-ink">같은 키로 재시도</b>하면 중복 차감 없이 첫 응답을 그대로 돌려받습니다</td>
            </tr>
            <tr>
                <td>호출 한도</td>
                <td>제휴 매체별로 <b class="text-ink">초당 호출 한도</b>가 설정됩니다(연동 시 안내). 초과 시 <code class="doc-code">429</code> — 백오프 후 <b class="text-ink">같은 Idempotency-Key</b> 로 재시도하세요. 목록은 <code class="doc-code">ETag</code> 를 지원하므로 <code class="doc-code">If-None-Match</code> 로 폴링 부하를 줄일 수 있습니다</td>
            </tr>
            <tr>
                <td>운영 시간</td>
                <td>새벽 <b class="text-ink">02:00~06:00</b> 에는 참여가 중단됩니다(<code class="doc-code">closed: true</code>). 이 시간에는 미션이 내려가지 않습니다</td>
            </tr>
        </tbody>
    </table>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-post">POST</span>
            <code class="ep-p">/missions/assign</code>
            <span class="ep-s">미션 단건 할당</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">참여 가능한 미션 하나를 골라 상세까지 한 번에 돌려줍니다. 줄 미션이 없으면 본문 없이 <code class="doc-code">204</code> 이며, 다시 시도할 시점을 <code class="doc-code">Retry-After</code>(초) 헤더로 알려줍니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">이름</th><th style="width:80px;">필수</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">participant_hash</code></td><td>필수</td><td>참여자 식별 해시 (최대 128자)</td></tr>
                    <tr><td><code class="doc-code">kind</code></td><td>선택</td><td>받고 싶은 <b class="text-ink">미션 유형</b> — <code class="doc-code">shopping</code>(쇼핑) · <code class="doc-code">place</code>(플레이스) · <code class="doc-code">mall</code>(몰) · <code class="doc-code">web</code>(웹). 쉼표로 여러 개(<code class="doc-code">place,shopping</code>). 비우면 전 유형. 모르는 값은 <code class="doc-code">422</code></td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -X POST {{ url('/api/v1') }}/missions/assign \
  -H "Authorization: Bearer rkm_..." \
  -H "Content-Type: application/json" \
  -d '{"participant_hash":"u_9f2c1a","kind":"place"}'</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "status": "ok",
  "mission": {
    "id": "2",
    "kind": "shopping",
    "title": "예시몰 무선 이어폰 최저가 찾기",
    "description": "무선이어폰 검색 결과에서 예시몰 상품 가격을 확인하고 오면 물 1개를 받아요.",
    "keyword": "무선이어폰",
    "landingUrl": "https://link.example.com/a1b2c3",
    "product": {
      "name": "예시 무선 이어폰 프로",
      "imageUrl": null,
      "price": 39900,
      "shopName": "예시몰"
    },
    "startsOn": "2026-07-31",
    "endsOn": "2026-08-04",
    "remaining": 20,
    "quiz": {
      "question": "지정된 순서의 해시태그를 입력해 주세요",
      "tagIndex": 3,
      "tagCount": 3
    }
  }
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:210px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">mission.id</code></td><td>string</td><td>미션 ID. 제출 시 경로에 그대로 사용</td></tr>
                    <tr><td><code class="doc-code">mission.landingUrl</code></td><td>string</td><td>참여자를 보낼 <b class="text-ink">단축 URL</b>. <b class="text-ink">반드시 이 주소로 이동</b>시켜야 참여가 집계됩니다 — 상품 원본 주소로 바꿔 열면 유입이 잡히지 않습니다</td></tr>
                    <tr><td><code class="doc-code">mission.remaining</code></td><td>int</td><td>지금 받을 수 있는 남은 수량</td></tr>
                    <tr><td><code class="doc-code">mission.quiz.tagIndex</code></td><td>int</td><td>몇 번째 해시태그를 묻는지(1부터). <b class="text-ink">참여자마다 다른 값</b>이며, 그대로 화면에 안내하면 됩니다</td></tr>
                    <tr><td><code class="doc-code">mission.quiz.tagCount</code></td><td>int</td><td>상품에 달린 해시태그 개수</td></tr>
                    <tr><td><code class="doc-code">mission.quiz</code></td><td>object</td><td>퀴즈형 미션에만 포함됩니다(태그가 없는 미션이면 키 자체가 없음)</td></tr>
                </tbody>
            </table>
            <div class="ep-l">줄 미션이 없을 때</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">HTTP/1.1 204 No Content
Retry-After: 5931</pre></div>
            <p class="ep-t mt-2">참여자에게는 “지금은 참여할 미션이 없습니다”로 안내하고, <code class="doc-code">Retry-After</code> 이후에 다시 호출하세요.</p>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/missions</code>
            <span class="ep-s">미션 목록</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">지금 참여 가능한 미션을 반환합니다. <code class="doc-code">participant_hash</code> 를 함께 보내면 <b class="text-ink">그 참여자가 이미 참여한 미션은 제외</b>된 목록이 옵니다(권장). <code class="doc-code">ETag</code> 헤더가 함께 오므로, 다음 폴링에 <code class="doc-code">If-None-Match</code> 로 보내면 변경이 없을 때 <code class="doc-code">304</code> 로 응답해 트래픽을 줄일 수 있습니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">이름</th><th style="width:80px;">필수</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">participant_hash</code></td><td>선택</td><td>참여자 식별 해시. 주면 그 사용자 기준으로 걸러진 목록을 반환</td></tr>
                    <tr><td><code class="doc-code">kind</code></td><td>선택</td><td>받고 싶은 <b class="text-ink">미션 유형</b> — <code class="doc-code">shopping</code>(쇼핑) · <code class="doc-code">place</code>(플레이스) · <code class="doc-code">mall</code>(몰) · <code class="doc-code">web</code>(웹). 쉼표로 여러 개(<code class="doc-code">place,shopping</code>). 비우면 전 유형. 모르는 값은 <code class="doc-code">422</code></td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl "{{ url('/api/v1') }}/missions?participant_hash=u_9f2c1a&kind=place" \
  -H "Authorization: Bearer rkm_..." \
  -H "If-None-Match: \"e3b0c44298fc1c149afbf4c8996fb924\""</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "missions": [
    {
      "id": "2",
      "kind": "shopping",
    "kind": "shopping",
      "title": "예시몰 무선 이어폰 최저가 찾기",
      "description": "무선이어폰 검색 결과에서 예시몰 상품 가격을 확인하고 오면 물 1개를 받아요.",
      "keyword": "무선이어폰",
      "landingUrl": "https://link.example.com/a1b2c3",
      "product": {
        "name": "예시 무선 이어폰 프로",
        "imageUrl": null,
        "price": 39900,
        "shopName": "예시몰"
      },
      "startsOn": "2026-07-31",
      "endsOn": "2026-08-04",
      "remaining": 20
    }
  ],
  "meta": { "slot": 3, "closed": false, "verifyMode": "server" }
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:210px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">missions[].remaining</code></td><td>int</td><td>지금 받을 수 있는 남은 수량. <b class="text-ink">0인 미션은 목록에 나오지 않습니다</b></td></tr>
                    <tr><td><code class="doc-code">meta.slot</code></td><td>int</td><td>내부 참조용 값 — 연동에서 사용하지 않습니다</td></tr>
                    <tr><td><code class="doc-code">meta.closed</code></td><td>bool</td><td><code class="doc-code">true</code> 면 운영 시간이 아님(02~06시). <code class="doc-code">opensAt</code> 에 다음 오픈 시각</td></tr>
                    <tr><td><code class="doc-code">meta.verifyMode</code></td><td>string</td><td><code class="doc-code">server</code> = 정답 검증을 랭크프리가 수행 / <code class="doc-code">vendor</code> = 제휴 매체가 자체 검증</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/missions/{id}</code>
            <span class="ep-s">참여 자격 확인 + 상세</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">목록에서 사용자가 미션을 선택했을 때 호출합니다. 미션이 <b class="text-ink">아직 유효하고 그 참여자가 참여 가능한지</b> 확인한 뒤 상세를 돌려줍니다. 참여 직전에 한 번 더 확인하는 관문이라, 목록을 캐시해 두었다면 반드시 거쳐야 합니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">이름</th><th style="width:80px;">필수</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">participant_hash</code></td><td>필수</td><td>참여자 식별 해시 (최대 128자)</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl "{{ url('/api/v1') }}/missions/2?participant_hash=u_9f2c1a" \
  -H "Authorization: Bearer rkm_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "status": "ok",
  "mission": {
    "id": "2",
    "kind": "shopping",
    "title": "예시몰 무선 이어폰 최저가 찾기",
    "landingUrl": "https://link.example.com/a1b2c3",
    "remaining": 20,
    "quiz": { "question": "지정된 순서의 해시태그를 입력해 주세요", "tagIndex": 3, "tagCount": 3 }
  }
}</pre></div>
            <div class="ep-l">거절 응답</div>
            <table class="doc-table">
                <thead><tr><th style="width:80px;">코드</th><th style="width:190px;">reason</th><th>의미</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">410</code></td><td><code class="doc-code">not_found</code></td><td>존재하지 않는 미션</td></tr>
                    <tr><td><code class="doc-code">422</code></td><td><code class="doc-code">closed</code></td><td>종료·비활성 미션이거나 운영 시간이 아님</td></tr>
                    <tr><td><code class="doc-code">422</code></td><td><code class="doc-code">slot_exhausted</code></td><td>지금은 받을 수 있는 수량이 없습니다 — 잠시 후 다시 시도</td></tr>
                    <tr><td><code class="doc-code">422</code></td><td><code class="doc-code">participant_duplicate</code></td><td>그 참여자가 이미 참여한 미션</td></tr>
                    <tr><td><code class="doc-code">422</code></td><td><code class="doc-code">not_eligible</code></td><td>지금 이 참여자는 참여할 수 없습니다</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-post">POST</span>
            <code class="ep-p">/missions/{id}/participations</code>
            <span class="ep-s">참여 제출</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">참여 결과를 제출합니다. <b class="text-ink"><code class="doc-code">Idempotency-Key</code> 헤더가 필수</b>입니다. 이 응답이 정산의 기준이므로, <code class="doc-code">accepted</code> 를 받은 건에 대해서만 여러분 사용자에게 보상을 지급하세요.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">이름</th><th style="width:80px;">필수</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">Idempotency-Key</code> <span class="text-muted" style="font-size:var(--fs-xs);">(헤더)</span></td><td>필수</td><td>요청마다 새로 만드는 고유 문자열(최대 80자). 없으면 <code class="doc-code">400</code></td></tr>
                    <tr><td><code class="doc-code">participant_hash</code></td><td>필수</td><td>참여자 식별 해시 (최대 128자)</td></tr>
                    <tr><td><code class="doc-code">answer</code></td><td>조건부</td><td>참여자가 입력한 정답 (최대 200자). <code class="doc-code">verifyMode</code> 가 <code class="doc-code">server</code> 면 필수. <code class="doc-code">#</code>·공백·대소문자는 서버가 정규화하므로 입력값 그대로 보내면 됩니다</td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl -X POST {{ url('/api/v1') }}/missions/2/participations \
  -H "Authorization: Bearer rkm_..." \
  -H "Idempotency-Key: 6f1c0b7e-2d54-4a9f-8c31-b0d2e7a1f902" \
  -H "Content-Type: application/json" \
  -d '{"participant_hash":"u_9f2c1a","answer":"무선충전"}'</pre></div>
            <div class="ep-l">응답 예시 — 수락</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "status": "accepted",
  "participation_id": 55,
  "remaining": 19
}</pre></div>
            <div class="ep-l">응답 예시 — 같은 키로 재시도</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "status": "duplicate",
  "participation_id": 55,
  "remaining": 19
}</pre></div>
            <p class="ep-t mt-2"><code class="doc-code">duplicate</code> 는 <b class="text-ink">첫 요청이 이미 수락되었다는 뜻</b>입니다(오류가 아님). 카운터는 한 번만 차감되며, 보상도 한 번만 지급하면 됩니다.</p>
            <div class="ep-l">응답 예시 — 거절</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "status": "rejected",
  "reason": "verify_failed"
}</pre></div>
            <div class="ep-l">거절 사유</div>
            <table class="doc-table">
                <thead><tr><th style="width:80px;">코드</th><th style="width:190px;">reason</th><th>의미 · 대응</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">422</code></td><td><code class="doc-code">verify_failed</code></td><td>정답 불일치 — 참여자에게 다시 확인하도록 안내</td></tr>
                    <tr><td><code class="doc-code">422</code></td><td><code class="doc-code">quota_full</code></td><td>미션 하루 수량 소진 — 다른 미션으로 안내</td></tr>
                    <tr><td><code class="doc-code">422</code></td><td><code class="doc-code">slot_exhausted</code></td><td>지금은 받을 수 있는 수량이 없습니다. <code class="doc-code">retry_after_seconds</code> 가 함께 오니 그만큼 기다렸다 재시도하세요</td></tr>
                    <tr><td><code class="doc-code">422</code></td><td><code class="doc-code">participant_duplicate</code></td><td>그 참여자가 이미 참여한 미션</td></tr>
                    <tr><td><code class="doc-code">422</code></td><td><code class="doc-code">not_eligible</code></td><td>지금 이 참여자는 참여할 수 없습니다</td></tr>
                    <tr><td><code class="doc-code">422</code></td><td><code class="doc-code">closed</code></td><td>미션 종료·비활성이거나 운영 시간이 아님</td></tr>
                    <tr><td><code class="doc-code">410</code></td><td><code class="doc-code">not_found</code></td><td>존재하지 않는 미션</td></tr>
                    <tr><td><code class="doc-code">400</code></td><td>—</td><td><code class="doc-code">Idempotency-Key</code> 헤더 누락</td></tr>
                </tbody>
            </table>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:210px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">status</code></td><td>string</td><td><code class="doc-code">accepted</code> · <code class="doc-code">duplicate</code> · <code class="doc-code">rejected</code></td></tr>
                    <tr><td><code class="doc-code">participation_id</code></td><td>int</td><td>참여 원장 ID. 정산 대조에 사용하므로 여러분 쪽에도 저장하세요</td></tr>
                    <tr><td><code class="doc-code">remaining</code></td><td>int</td><td>제출 후 남은 수량</td></tr>
                    <tr><td><code class="doc-code">retry_after_seconds</code></td><td>int</td><td><code class="doc-code">slot_exhausted</code> 일 때만. 이 초만큼 기다렸다 다시 시도하면 됩니다</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ep">
        <div class="ep-h">
            <span class="doc-method m-get">GET</span>
            <code class="ep-p">/participations</code>
            <span class="ep-s">정산 대조</span>
        </div>
        <div class="ep-b">
            <p class="ep-t">일자별 수락 건수를 집계해 돌려줍니다. 여러분이 기록한 <code class="doc-code">accepted</code> 건수와 대조해 정산 차이를 확인하는 용도입니다. 하루 경계는 <b class="text-ink">오전 6시 기준</b>입니다.</p>
            <div class="ep-l first">요청 파라미터</div>
            <table class="doc-table">
                <thead><tr><th style="width:190px;">이름</th><th style="width:80px;">필수</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">date</code></td><td>선택</td><td><code class="doc-code">YYYY-MM-DD</code>. 미지정 시 오늘. 형식이 다르면 <code class="doc-code">422</code></td></tr>
                </tbody>
            </table>
            <div class="ep-l">요청 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">curl "{{ url('/api/v1') }}/participations?date=2026-07-31" \
  -H "Authorization: Bearer rkm_..."</pre></div>
            <div class="ep-l">응답 예시</div>
            <div class="doc-copy-wrap"><button type="button" class="doc-copy">복사</button><pre class="doc-pre">{
  "date": "2026-07-31",
  "totalAccepted": 1,
  "byMission": [
    { "missionId": "2", "accepted": 1 }
  ]
}</pre></div>
            <div class="ep-l">응답 필드</div>
            <table class="doc-table">
                <thead><tr><th style="width:210px;">필드</th><th style="width:80px;">타입</th><th>설명</th></tr></thead>
                <tbody>
                    <tr><td><code class="doc-code">totalAccepted</code></td><td>int</td><td>그 날 수락된 참여 총 건수</td></tr>
                    <tr><td><code class="doc-code">byMission[]</code></td><td>array</td><td>미션별 수락 건수</td></tr>
                </tbody>
            </table>
        </div>
    </div>



<div class="card-soft mt-12 p-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
    <div>
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">제휴 매체 연동을 시작하시겠어요?</div>
        <p class="text-muted mt-1" style="font-size:var(--fs-xs);">운영자가 제휴 매체를 등록하면 전용 키를 전달해 드립니다. 받는 즉시 호출할 수 있습니다.</p>
    </div>
    <a href="{{ route('console.api-keys') }}" class="btn btn-primary btn-sm">API 키 발급</a>
</div>

<script>
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
