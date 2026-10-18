# 쇼핑 키워드 광고 종료

`php83 artisan shop-keyword:auto-end --dry-run`으로 판정하고, 옵션 없이 실행하면 수집만 종료한다. 매일 00:10 Asia/Seoul에 기존 Laravel schedule:run 크론으로 실행된다. 운영 로컬 변경이 있는 routes/console.php는 수정하지 않고 별도 서비스 프로바이더에서 스케줄을 등록한다.

- 대상 상태: pending/checking/processing/running/paused/blocked. 완료·목표 도달·실패·광고 종료는 제외.
- 사유 우선순위: 주문 종료일이 오늘 이전 → 기간 없는 분석의 동일 상품 URL·핵심 키워드 캠페인이 모두 종료/취소/기간만료 → 매칭 없음 및 마지막 수집 후 7일 경과.
- 동일 상품·키워드의 유효 캠페인이 하나라도 있으면 두 번째 사유로 종료하지 않는다.
- 수집 시각은 collection_seen_at, 키워드 checked_at, 상품정보 collected_at의 최댓값. 전부 없으면 생성 시각. 상태 갱신 시각은 사용하지 않는다.
- 부스팅샵 조회는 DB의 READ ONLY 트랜잭션에서 SELECT만 수행한다. 설정 파일만 읽고 다른 앱을 부트스트랩하지 않는다. 설정 경로는 SHOP_KEYWORD_BOOSTING_DB_CONFIG로 변경할 수 있다. 조회 실패 시 명령은 실패하며 종료하지 않는다.
- `ad_ended`는 기존 varchar(20) 상태 열에 저장한다. 마이그레이션 없음. 관리자 표시 `종료(광고 끝남)`이 목표 도달 표시보다 우선한다.
- 결과·항목·단축 URL은 유지하며 확장 큐, 서버 배치, 늦은 업로드 및 재개 요청을 차단한다. DB 큐에서는 해당 분석의 ShopKeywordCheckJob만 제거한다.
- 실행 로그에는 판정 ID·사유·매칭 캠페인 ID가 기록된다. finish 직전 새 수집 시각과 캠페인 스냅샷을 다시 확인한다.

## 2026-10-18 일회 정리 인계

맥북14 gh 인증 무효로 PR 생성·병합·운영 코드 반영은 수행하지 않는다. 윈도 세션 병합 필요.

운영 데이터 정리 상세와 백업 경로는 `~/dev/appmgr/codex-code-queue/results/rf-autoend.result.md` 및 서버 `/home/jcurve/rf-autoend-audit/cleanup.json`에 있다. 기존 코드에 안전한 paused로 우선 중단했다. 이후 다른 작업이 일부를 target_reached로 바꾸었으며 이 상태도 기존 큐에서 제외된다.

병합 후 운영 반영 전, 보호 8개 파일의 해시와 변경 파일 비중복을 다시 확인한다. `git fetch origin` 및 `git merge --ff-only origin/master`로 반영한다. deploy.sh/reset/stash는 사용하지 않는다. 마이그레이션은 없고 전체 DB 백업도 필요 없다. 설정 캐시가 있다면 기존 운영 방식으로 재생성하고 rankfree 워커만 재시작한다. dry-run과 schedule:list를 확인한다.

일회 정리의 19개 ID는 다시 판정하고, 아직 종료 사유가 유효한 경우에만 최종 ad_ended로 확정한다. paused 상태는 명령의 대상이다. 다른 흐름에 의해 target_reached가 된 행은 일반 자동 종료 대상 밖이므로 일회 정리 manifest의 ID와 대조해 수동으로 판정을 재검증한다. 수동 상태 변경 시에도 해당 ID만 `mysqldump --where='id IN (...)'`로 새 백업하고 결과 항목은 건드리지 않는다. 새 수집이 확인돼 제외된 #21은 일회 확정 대상에 넣지 않는다.

종료 후 실제 관리자 목록 서버 렌더에서 상태 문구·진행 중 수를 확인한다. 일회 정리 시에는 최근 30건 `진행 중` 3→0건, 분석 51건·항목 63,395개 유지였다.
