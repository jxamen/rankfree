# 수집 차단 Slack 알림

- 서버 기존 `shop_serp_request` 로그(403/429/captcha/blocked_on_resume)에 더해, 서버 분석기와 확장(화면·배경)이 공통 `ShopExposureBlockAlert`에 전달한다.
- 확장은 인증된 POST `/api/ext/shop-keyword/{analysis}/collector-event`로 짧은 이벤트를 전송한다. shop_keyword 권한 및 분석 소유자 확인. IP는 요청에서 판별하며 클라이언트 입력을 받지 않는다.
- 원문 HTML, 토큰, Slack 응답 본문은 로그에 남기지 않는다. `storage/logs/laravel.log`의 `shop_exposure_block_event`에 핵심 키워드·분석·수집기·IP·종류·KST 일일 횟수·재시도·blocked_on_resume를 기록한다. 캐시 적중/휴식/통신 오류는 차단 이벤트가 아니다.
- 공유 Laravel 캐시로 IP별 잠금, KST 일일 횟수, 차단 상태, Slack 메시지 ts를 보존한다. 같은 IP 직전 차단부터 10분 이내면 chat.update로 한 메시지를 갱신한다. 성공은 활성 차단이 있을 때만 재개 메시지를 한 번 보낸다. 다음 시각은 정책의 차단 후 3분을 나타내며 자동 실행을 보장하는 시각은 아니다.
- 기존 `services.slack.notifications.bot_user_oauth_token` (`SLACK_BOT_USER_OAUTH_TOKEN`)을 사용한다. 미설정이면 전송하지 않고 `shop_exposure_slack_configuration_required` 로그를 남긴다. Slack 실패는 수집을 멈추지 않는다.
- 채널: `SHOP_EXPOSURE_BLOCK_SLACK_CHANNEL`, 기본 C0C75BREWJX. 봇에 chat:write 권한과 채널 참여가 필요하다.
- 서버 수집 IP: `SHOP_EXPOSURE_COLLECTOR_IP`, 기본 49.247.13.187 (2026-10-18 운영 서버 outbound 조회 확인). 서버 네트워크 변경 시 갱신한다.
- 브라우저는 확장 0.4.3 설치/새로고침이 필요하다. 수집은 보고 실패 시 계속되며 이벤트 재전송 큐는 없으므로 네트워크 단절 중 보고는 유실될 수 있다. 일일 횟수는 서버가 받은 실제 차단 이벤트 수이며 캐시 삭제 시 초기화된다.
- 운영 반영: master 병합 후 보호 대상 8개 파일 해시를 대조하며 ff-only. deploy.sh/reset/stash/DB 백업 불필요. 마이그레이션 없음. config 및 view 캐시 갱신. 토큰은 운영 담당자가 별도 설정하며 코드·결과서에 값을 기록하지 않는다.

## 보안문자 모달 복구 (확장 0.6.8.9)

- `ShopSerpPolicy` 결과의 `observed_at`은 실제 결과/복구/당일 캐시 확인 시각이다. cooldown은 최초 차단 시각을 유지하며 새 보안문자 관측이 아니다. lane별 저장소·3분 until·사람의 recovery 탭 검사·간격 정책은 유지한다.
- 상세 화면은 성공 결과를 받으면 collector-event POST를 기다리지 않고 해당 성공보다 오래된 모든 키워드 대기를 제거한다. 늦게 도착한 과거 차단 응답은 다시 모달을 열지 않는다. 더 늦게 관측된 실제 차단은 유지한다.
- 확장 모드에서는 pending의 서버 집계 blocked를 새 모달 근거로 사용하지 않는다. 실제 확장 검색 응답을 사용하며, 서버 fallback에서는 기존 서버 상태 표시를 유지한다. success 이벤트의 서버 error/policy until/blocked 상태 정리는 기존 clearWait가 담당한다.
- 닫기/ESC 뒤 같은 관측의 반복과 cooldown은 모달을 다시 열지 않는다. 성공 이후 실제 새 보안문자에는 다시 열린다. 구버전 확장 응답은 요청 시작 시각으로 순서를 보수적으로 판별하므로 0.6.8.9 확장 갱신을 함께 적용한다.
