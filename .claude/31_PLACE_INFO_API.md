# 플레이스 상세 조회 API

- `GET /api/v1/rank/candidates?region=제주 애월&name=랜디스도넛` 또는 `keyword=지역 상호`: 신규개업 `PlacePhoneFetcher`와 동일한 pcmap 검색 페이지 SSR 경로. 순위 GraphQL은 상호 검색 0건 문제가 있어 여기서 사용하지 않는다. ROOT_QUERY의 해당 query의 placeList.businesses.items 순서만 읽고 ID 중복 제거(광고/다른 캐시 노드 제외). 후보 ID/URL/주소/전화/좌표 반환 후 호출자가 선택하여 상세 API 호출. limit 기본20 최대50, 첫 페이지만, 페이지네이션 없음. 원본 오류와 검색0건 구별.

- `GET /api/v1/rank/search?keyword=지역+디저트`: 실제 결과 수만큼 최대 300개 반환. total/count/items/capped/partial/blocked. 0/5/10/100/300/430개 페이지네이션 및 오류 테스트 완료.
- 상세 응답 `place.links`: 공식 홈페이지와 SNS(type/url). `place.images`: 업체 대표 사진만 최대 10개(url/width/height); 방문자 후기 사진은 제외. 디저트나우 소비자가 320/640px WebP로 최대 3장 캐싱한다.

- `GET /api/v1/rank/place`, 기존 `rank` scope 및 API 키 일일 한도 적용. 분당 20회 제한.
- `place`: 네이버 플레이스 URL/숫자 ID. 업체명 입력은 미지원(422). `review_limit`: 1~50, 기본 10.
- `PlaceInfoFetcher`: 기존 순위/SEO 수집에서 사용하는 m.place SSR Apollo 데이터를 홈·메뉴·최신 방문자 리뷰 페이지에서 조회. 인증 쿠키나 nCaptcha 토큰을 요구하지 않는다.
- 반환: 업체명·업종·전화·소개·영업시간·편의시설·결제정보·주소·길안내·업체 좌표·리뷰 수·메뉴 목록·최근 방문자 리뷰. Apollo 참조를 해제하고 응답에 필요한 필드만 선택한다.
- 최신 리뷰는 공개 페이지에 포함된 범위만 제공하며 전체 이력/페이지네이션은 지원하지 않는다. 메뉴 페이지 실패 시 홈의 메뉴 노드를 보존한다.
- `status.business/menus/recent_reviews`: `ok`, `blocked`, `unavailable`. 목록 파싱 성공은 전체 데이터 수집을 보장하지 않는다. 누락 값은 null/빈 배열.
- 업체 홈 실패는 429(차단) 또는 503(통신/파싱/대상 미확인). 메뉴·리뷰만 실패하면 HTTP 200 + `partial: true`.
- 기존 rank/check·resolve·추적 스키마를 변경하지 않으며 슬롯 생성/순위 저장/DB 마이그레이션은 없다.
- 성공/실패 모두 실시간 조회하며 현재 캐시는 없다. 최대 3회 조회, 각 요청 타임아웃은 `rankfree.place.timeout`(기본 20초). 클라이언트는 90초 이상 권장.
- 개발자 문서: `/developers#rank`. 테스트: `tests/Feature/PlaceInfoApiTest.php`.

## 실응답 확인

2026-10-04 운영 서버에서 `1000671392`(랜디스도넛 애월점)의 홈·메뉴·최신 리뷰를 HTTP 200으로 확인했다. 기본 HTTP 클라이언트 UA로는 429였으나 기존 수집기와 같은 브라우저 UA/헤더로 정상 응답한다.

- 최신 메뉴는 `PlaceMenuItem`, 가격은 `price.displayText` 구조(이전 `Menu`도 지원).
- 소개·영업시간은 `ROOT_QUERY.placeDetail(...).description/newBusinessHours`에 있다.
- 최근 리뷰는 `ROOT_QUERY.visitorReviews(...)` 중 `sort=recent`, `includeContent=true`의 items 순서를 따른다. 날짜가 `10.4.일`처럼 표시되므로 문자열 날짜 정렬 금지. 같은 리뷰의 본문 없는 노드가 별도로 있어 전체 노드 스캔보다 원본 목록을 우선한다.
- `coordinate.x/y`는 경도/위도 문자열. API는 유효한 숫자만 float으로 반환한다.
