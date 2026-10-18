# 확장 0.6.0 수집 경로와 무응답 인계

- 수집 탭 하나를 비활성 상태로 재사용한다. `m.search.naver.com/search.naver?where=m&query=...#rfnav` 로드 후 상단 쇼핑 메뉴의 실제 링크를 클릭한다. 링크가 없으면 모바일 쇼핑 검색 href로 이동한다. 쇼핑 문서에서 한 화면 스크롤 → 750ms 대기 → 순위 읽기 순서를 지킨다. 기존 공용 간격 정책을 그대로 사용한다.
- 실제 메뉴는 `msearch.shopping.naver.com`으로 연결된다. 데스크톱 쇼핑 주소도 계속 읽을 수 있다. 보안문자·접속 제한은 감지만 하며 자동 풀이와 IP 변경은 하지 않는다.
- 탭 닫힘/통신 실패는 `unavailable` 이벤트로 기존 서버 lane에 즉시 넘긴다. 페이지 전체가 닫혀 이벤트를 보낼 수 없으면 `shop-keyword:watch-browser`가 마지막 브라우저 수집 시각 60초 이상인 분석을 인계한다. 기존 스케줄러에서 매분 실행하므로 감지 시점은 대략 60~120초다. API 분석은 최초 브라우저 응답이 없어도 생성 60초 이후 대상이다.
- 무응답 인계는 API/관리자 분석 모두 기존 `ShopKeywordCheckJob`으로 진행한다. 서버 차단은 기존 3분 쿨다운을 준수한다. paused/목표 도달/광고 종료/미확인 없음은 인계하지 않는다.
- 확장 큐나 화면 pending 요청이 돌아오면 무응답 인계만 해제한다. 서버 배치가 실행 중이면 완료될 때까지 반환을 미룬다. 이전 서버 전용 재큐 작업은 해제 후 종료한다. 실제 403/429/보안문자 이관은 기존 정책을 유지한다.
- 운영 보호 파일 8개와 deploy.sh는 수정하지 않는다. 스케줄은 기존 별도 서비스 프로바이더에 등록하며 routes/console.php는 수정하지 않는다. 마이그레이션 없음. 운영 반영 후 rankfree queue:restart 및 schedule:list로 명령 등록을 확인한다.

검증: 관련 PHP 테스트와 `node --test tests/extension/*.test.cjs`. Chrome/Playwright 검증은 `node tests/extension/shop-navigation.browser.cjs`. 모의 정상 문서는 메뉴 이동·스크롤·순위 반환 순서를 검증한다. 실제 네이버에서는 메뉴 클릭 후 접속 제한 화면을 확인했으며 정상 상품 순위의 실서비스 검증은 차단 해제 후 별도로 필요하다. 브라우저 검증 로그는 `/tmp/rf-nav-browser-evidence.json`.
