# PR #15 재검수 NG 3건 수정

시작 HEAD: 64779368b19f4265cc99a8a545f1029b1f494b9a, feat/16rk-prepaid.

- 개별/전체 발주 취소와 회차 취소를 OrderDispatchState의 주문 행 잠금으로 보호한다. 잠금 안에서 최신 상태를 다시 읽으며 dispatching 또는 최종 상태에서는 기록 취소·pending 복귀를 허용하지 않는다.
- 발주 점유 충돌은 대기 상태로 유지한다. 회차 전송 전 같은 주문 잠금 안에서 최신 회차 상태도 검사한다. 외부 호출 후 발주·회차 결과 저장과 주문 상태 확정을 한 트랜잭션에서 처리해 다음 회차가 미확정 결과 사이에 끼어들지 않게 한다.
- 부스팅샵은 accepted/rejected/unknown을 구분한다. HTTP 오류·비정상 JSON·result 누락·연결 예외는 dispatching을 유지하고 발주 기록에 관리자 확인을 남긴다. 명시적 result=fail만 이전 상태로 복귀한다. 기록 저장과 상태 확정도 같은 주문 잠금 안에서 수행한다.
- 공통 API/시트 전송의 HTTP 오류·예외도 결과 불명으로 보호 상태를 유지한다.

로컬에서 이번 검수의 ReviewRegressionTest.php 원본은 발견하지 못해 결과서의 실행 순서대로 PrepaidDispatchReviewTest에 재현 3개를 추가했다. 오래된 전체·개별 발주 취소/회차 취소, 경합 회차의 스케줄러 재처리, 플레이스·쇼핑의 HTML 502/필드 누락 JSON/연결 예외 및 명시적 거절을 포함한다. 전부 HTTP fake이며 실업체 요청은 없다.

추가 3개는 시작 HEAD의 별도 임시 worktree에서 모두 실패했다(regression-before.log). vendor는 공유하되 bootstrap의 클래스 매핑과 APP_BASE_PATH를 해당 worktree로 지정해 원본 앱을 실행했다. 임시 worktree는 삭제했다. 수정 후 관련 41개/331 assertions 통과(related-tests.log). 이전 검수 원본 /tmp/rk15rev-runtime/PrepaidReviewTest.php는 수정 없이 7개/53 assertions 통과(previous-review.log).

전체 재실행: 기준 4f1db2d 816개/784 통과/기존 실패 32개, 최종 836개/804 통과/동일 실패 32개/13,076 assertions. 실패 이름 집합 대조로 신규 실패 0, 해소된 기존 실패 0(test-comparison.json). APP_KEY는 테스트 전용 값, SQLite :memory:, memory_limit=1G, SHOP_EXPOSURE_FETCH_DELAY_MS=0. JUnit과 전체 로그는 /tmp/rkf2-baseline.{xml,log}, /tmp/rkf2-complete.{xml,log}. PHP 구문 검사 및 git diff --check 통과.

보호 대상 8개 파일 SHA-256은 이전 증빙과 동일(protected-files.json). 운영 접속·배포·master 수정·병합은 하지 않는다. 실제 MySQL 병렬 잠금/교착 검증이나 결과 불명 주문의 전용 복구 화면·명령은 이번 범위에 포함하지 않았다. 결과 불명 주문은 자동 초기화하지 않고 업체 대조가 필요한 관리자 확인 상태로 남긴다.
