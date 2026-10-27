<div class="doc-panel" data-panel="integrated_shop_rank">
    <h2 class="doc-h2">통합검색 쇼핑 영역 순위</h2>
    <p class="mt-3">PC·모바일 통합검색의 최초 쇼핑 영역 상품을 HTTP로 수집합니다. 상품 수는 키워드마다 다릅니다. 쇼핑 전용 검색 전체 순위나 개인화된 사용자 브라우저의 순위를 보장하지 않습니다.</p>
    <div class="ep">
        <div class="ep-h"><span class="doc-method m-post">POST</span><code class="ep-p">/api/v1/integrated-shop-rank</code><span class="ep-s">scope: integrated_shop_rank</span></div>
        <div class="ep-body p-4">
            <p>관리자가 계정에 <b>통합검색 쇼핑 순위</b> 권한을 허용한 다음, 콘솔 → API 키 → 권한 수정(또는 키 발급)에서 같은 항목을 체크하세요. 기존 키에 자동 부여되지 않습니다.</p>
            <table class="doc-table"><thead><tr><th>입력</th><th>설명</th></tr></thead><tbody>
                <tr><td>keywords</td><td>필수 문자열 배열, 1~5개, 각 100자 이하, 중복 불가</td></tr>
                <tr><td>target.type / target.value</td><td>필수. type: nvMid, mallName, mallId, channelNo, channelProductId 중 하나. value: 문자열, 정확히 일치하는 상품 모두 반환. 몰 이름은 부분 검색하지 않습니다.</td></tr>
                <tr><td>top</td><td>선택, 1~100. PC 또는 모바일 중 하나라도 대상이 해당 순위 이내인 키워드만 data에 반환합니다. 오류는 errors에 계속 표시합니다.</td></tr>
                <tr><td>rank_by</td><td>rank(기본, 광고 포함) 또는 organic_rank(광고 제외). top의 비교 기준입니다.</td></tr>
            </tbody></table>
            <pre class="doc-pre">curl -X POST https://rankfree.kr/api/v1/integrated-shop-rank \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"keywords":["캠핑의자","무선이어폰","강아지사료"],"target":{"type":"mallName","value":"조회할 몰 이름"},"top":5,"rank_by":"rank"}'</pre>
            <p class="mt-3">top을 빼면 모든 키워드를 반환합니다. data[]에는 keyword, elapsed_ms, pc, mobile이 들어 있습니다. 기기별 status, area_code, fetched_at, cached, items, matches, rank, organic_rank, is_ad를 확인하세요.</p>
            <pre class="doc-pre">{
  "data": [{"keyword": "예시", "elapsed_ms": 2500,
    "pc": {"status": "ok", "area_code": "shp_gui", "cached": false,
      "fetched_at": "2026-10-27T16:20:00+09:00",
      "rank": 2, "organic_rank": 1, "is_ad": false,
      "matches": [{"nvMid": "123", "rank": 2, "organic_rank": 1, "is_ad": false}],
      "items": ["최초 페이지 전체 상품 객체 — 예시에서는 생략"]},
    "mobile": {"status": "area_absent", "area_code": null, "cached": false,
      "fetched_at": "2026-10-27T16:20:01+09:00", "items": [], "matches": [],
      "rank": null, "organic_rank": null, "is_ad": null}}],
  "errors": [{"keyword": "예시", "device": "mobile", "status": "area_absent"}],
  "rank_by": "rank", "cache_ttl_seconds": 600
}</pre>
            <p>위 응답은 필드 설명용 축약 예시입니다. items에는 화면 순서대로 nvMid, productName(강조 태그 제거), mallName, mallId, channelNo, channelProductId, sourceType, cardType, productUrl(pcUrl/mobileUrl), salePrice, rank, organic_rank, is_ad가 들어갑니다. 같은 nvMid가 광고·일반 상품에 중복되어도 보존합니다. matches는 일치 상품 전체이며 최상위 rank와 is_ad는 첫 일치 상품 기준, organic_rank는 광고가 아닌 일치 상품의 최소 순위입니다.</p>
            <p class="mt-3">광고는 sourceType AD·AITEMS_FORYOU_AD 또는 cardType AD_CARD입니다. SUPER_POINT 등 적립 상품은 광고 제외 순위에도 포함하며 원래 유형을 보존합니다. 순위는 1부터 시작합니다. 광고 상품의 organic_rank, 대상 미발견·영역 없음·실패의 rank는 null이며 0위로 기록하지 않습니다. 초기 상태에 미리 포함된 다음 페이지는 순위에 합산하지 않습니다.</p>
            <table class="doc-table"><thead><tr><th>상태/오류</th><th>의미·처리</th></tr></thead><tbody>
                <tr><td>ok</td><td>정상 파싱. rank=null이면 최초 페이지에 대상이 없습니다.</td></tr>
                <tr><td>area_absent</td><td>쇼핑 영역 없음. 미노출 순위로 확정하지 마세요.</td></tr>
                <tr><td>parse_failed</td><td>초기 JSON 또는 구조 변경. 자동 재시도하지 말고 결과를 확인하세요.</td></tr>
                <tr><td>blocked</td><td>네이버 403/429 또는 보안 확인 응답. 즉시 남은 수집을 중단하며 서버 전체 수집을 10분간 쉽니다. 보안 확인 해결·우회·자동 재시도 없음.</td></tr>
                <tr><td>busy</td><td>다른 수집이 진행 중. 남은 수집을 시작하지 않습니다. 잠시 뒤 필요할 때 다시 요청하세요.</td></tr>
                <tr><td>fetch_failed</td><td>연결 실패·5초 시간 초과·기타 HTTP 오류/리다이렉트. 자동 재시도 없음.</td></tr>
                <tr><td>HTTP 401 / 403 / 422 / 429</td><td>각각 키 인증 실패 / 계정·키 권한 또는 IP 제한 / 입력 오류 / 기존 일일 한도 또는 이 경로 분당 10회 한도.</td></tr>
            </tbody></table>
            <p class="mt-3">기기별 결과 상태는 HTTP 200 응답 안에 포함됩니다. 키워드·기기별 정상 결과/영역 없음은 10분 캐시합니다. 캐시 적중은 네이버에 요청하지 않습니다. 단일 운영 서버에서 수집 동시 수 1개, 요청 완료 후 다음 요청까지 최소 1초이며, 키워드마다 PC·모바일 각 1회만 요청합니다. 응답 시간은 캐시·상대 서버 상태에 따라 달라집니다.</p>
        </div>
    </div>
</div>
