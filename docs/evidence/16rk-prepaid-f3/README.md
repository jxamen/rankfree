# PR #15 검토 3차 NG 2건 수정

시작 HEAD: 998736f6b7ee7e48a268689c02b52d6722fcd6b4, feat/16rk-prepaid.
검토: /tmp/rk15rev3-RESULT.md 및 /tmp/rk15rev3-files/의 실행 스크립트.

외부 전송 성공·결과 불명은 주문의 external_dispatched_at에 보존한다. 공통 API/시트와 부스팅샵 플레이스·쇼핑이 결과 저장 트랜잭션의 주문 잠금 아래 기록한다. 상태 변경, 발주/회차 취소, 재전송은 이 값을 지우지 않는다. 지갑 refund 자체가 주문을 다시 잠그고 전송 이력·현재 점유를 검사하므로 pending/completed 우회, canceled 발주 이력, 오래된 모델을 쓰는 직접 환원도 차단한다. 삭제도 차단해 발주·환원 감사 이력을 보존한다. 기존 전송·취소·결과 불명 이력의 backfill 마이그레이션과 이력 조회 방어를 포함한다. 명시적으로 거절된 업체 응답은 기존처럼 자동 환원이 가능하며, 외부 전송 없는 reward 채널은 전송 표식 대상에서 제외한다.

관리자 상세의 '업체 취소 확인 후 환원'만 보호를 우회한다. 모든 업체 취소 확인 checkbox와 160자 이내 확인 메모가 필요하며, 관리자 ID·메모·시각을 환원 원장에 기록한다. 주문 잠금 아래 원결제액을 한 번 환원하고 전체 회차·발주를 취소한다. 중복 제출은 추가 지급하지 않으며 환원 후 재발주·주문 재활성화를 차단한다. 발주 중/결과 불명 dispatching은 이 동작으로도 해제할 수 없다. 실시간 전송과의 경합을 막기 위해 기존 보호를 유지한다.

관리자가 canceled 상태를 읽은 회차 발주 요청만 explicitReissue를 전달한다. beginDispatch의 공통 주문 잠금 안에서 최신 주문·환원·회차 상태를 검사하고 해당 회차 활성화와 dispatching 점유를 함께 처리한다. 스케줄러와 취소 전에 읽은 일반 발주 요청은 canceled 회차를 열 수 없다. sent 회차와 환원된 주문도 계속 차단한다.

## 검증

제공된 검토 폴더에는 5개 실행 스크립트만 있고 NewRegressionReviewTest.php 등 증빙 테스트 원본은 없었다. /tmp 및 appmgr에서도 찾지 못했다. 따라서 원본 그대로 실행했다고 주장하지 않는다. 결과서의 정상 관리자 엔드포인트 순서를 재현한 두 테스트를 저장소에 추가했고 시작 구현에서 둘 다 실패(regression-before.log), 수정 후 통과했다. 상태 우회 네 조합, OrderPlacer의 2일·2,200원 결제/자동 회차 생성, 1회차 전송/2회차 취소/명시적 재발주/HTTP 총 2회를 검사한다. 오래된 일반 발주 요청 차단도 함께 검사한다.

추가로 취소된 전송 이력·직접 지갑 호출, 명시적 환원의 권한·필수 확인·원장 감사·중복 지급 차단·후속 발주 차단, 실제 전송 fake 안에서 환원 경합, 기존 이력 마이그레이션을 검사했다. 관련 PrepaidDispatchReviewTest 19개/298 assertions 모두 통과. 이전 원본 /tmp/rk15rev-runtime/PrepaidReviewTest.php는 수정 없이 7개/53 assertions 통과. 관련 테스트 외부 HTTP는 fake하고 미등록 요청을 금지했다.

전체 재실행: 기준 4f1db2d 816개/784 통과/기존 실패 32개/12,811 assertions, 수정본 842개/810 통과/동일 실패 32개/13,178 assertions. 실패 이름 집합 대조: 신규 실패 0, 해소된 기존 실패 0(test-comparison.json). 전체 로그/JUnit: /tmp/rkf3-baseline.{log,xml}, /tmp/rkf3-complete.{log,xml}. 테스트 APP_KEY, SQLite :memory:, memory_limit=1G, SHOP_EXPOSURE_FETCH_DELAY_MS=0 사용. 전체 테스트에 기존 두 프로세스 SQLite 경합 검사도 포함한다. MySQL 동시 실행·교착/운영 DDL은 검증하지 않았다.

PHP 구문 검사와 git diff --check 통과. 보호 대상 8개 파일 SHA-256은 이전 증빙과 전부 동일하다. 대표님 개발 저장소·운영·master 수정, 배포, 병합은 하지 않았다. 신규 nullable timestamp 마이그레이션은 테스트 DB에서만 실행했다. 검토 결과서의 별도 후속 항목(일반 발주 재시도와 회차 연결의 중복 전송, 결과 불명 대조/복구 UI)은 이번 NG 2건 수정에 포함하지 않았다.
