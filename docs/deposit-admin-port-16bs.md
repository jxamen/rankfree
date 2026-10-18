# 충전관리 이식 (16bs)

부스팅샵 `agency/charger_request.php`, `charger_request_form.php`, `agency/ajax.action.php`를 기준으로 입금 표시/승인/승인취소/요청취소·복원·목록삭제/계산서 표시/부가세 요청 SMS/검색·기간·합계·페이지 기능을 이식했다. `ajax.action.php`의 승인 완료 SMS도 유효한 회원 휴대전화가 있을 때 지원한다. 계산서 발행 표시는 실제 전자세금계산서 API 호출이 아니다. 현금영수증·메모·일괄처리는 원본 충전 화면에 없어 추가하지 않았다.

새 부가세 포함 여부는 관리용 표시이며 충전액을 자동으로 나누거나 세액을 새로 적립하지 않는다. 기존 행은 부가세 미확인(NULL), 승인된 기존 행의 입금 표시는 완료로 이행한다. 입금 표시 변경만으로 잔액이 바뀌지 않는다.

승인 취소는 요청과 지갑을 잠그고 충전액을 차감한다. 잔액 부족 시 상태·원장 모두 변경하지 않는다. 승인 회차별 원장 참조로 재승인 때 한 번만 적립한다. 승인 이력이 있는 요청 금액은 수정할 수 없으며, 취소된 요청의 삭제는 목록에서만 숨겨 원장과 감사를 보존한다. SMS는 커밋 이후 전송하고 발송 전 점유 및 결과를 기록한다. 부가세 SMS는 동일 요청에서 24시간 내 재전송을 차단하며 실패/불명 결과도 자동 재시도하지 않는다. 실문자는 테스트에서 전송하지 않았다.

## 셀프마케팅

중앙 app-admin 작업 사본은 `/Users/jcurve/dev/app-admin-16bs-deposit-port`이며 운영 현재 소스를 SSH tar 읽기로 수신했다. 운영 캐시·환경파일은 사용하지 않는다. 중앙 DB에서 잔액을 직접 수정하지 않고 `AppApi`의 admin-key 인증 API만 호출한다. 선택 앱·운영자 앱 범위를 확인하며 처리자는 세션 사용자에서 채워 넣는다. `/topups`, 사이드바 `충전 요청 · 입금 확인` 및 주문 화면 링크가 진입점이다.

API: `GET /v1/selfmarketing/admin/topups` 및 `POST /v1/selfmarketing/admin/topups/{id}`. 기존 `AuthAdminKey:adapter`와 `UseAppDatabase` 뒤에서 앱 44 / slug selfmarketing / 등록 DB jc_selfmarketing / 현재 DB jc_selfmarketing / 서비스 on을 확인한다. 쓰기의 action은 confirm,revoke,cancel,restore,delete,edit,bank,vat,invoice,vat_sms이다. bank/vat/invoice는 명시적 value(0/1), confirm은 실제 amount/depositor/at, edit는 amount/depositor/company가 필요하다. API actor는 중앙 서버가 정한 운영자 식별자다.

입금 확인은 기존 CLI `selfmarketing:confirm-bank` 및 입금 훅과 같은 `SelfMarketingWallet::confirm`을 쓴다. 요청 금액, 은행 입금자명의 4자리 코드, 은행 거래시각을 대조하고 `MarketingDeposit`의 동일 사건 소비를 유지한다. 승인을 취소해도 이미 소비한 은행 사건을 재사용하지 않는다. 재승인은 새 미소비 사건을 대조해야 하며, 동일 입금의 승인 취소를 잘못 눌렀다면 별도 정산 검토가 필요하다.

마이그레이션 `2026_10_18_120000_add_selfmarketing_topup_admin.php`는 jc_selfmarketing 전용이며 mk_topups에 관리 필드, mk_topup_audits에 처리자/시각/변경 전후를 기록한다. mk_wallet_ledger는 기존 원장을 유지한다. CLI/훅 입금 확인도 새 감사표가 있으면 이력을 남긴다. 원본 구스키마 테스트 및 구스키마 경로도 유지한다.

## 랭크프리

기존 `/admin/topups`와 `/review`를 유지하고 `/admin/topups/{topup}/manage`를 추가한다. 기존 관리자 게이트가 모든 경로에 적용된다. `PrepaidWallet::review`로 승인하고 `TopupAdmin`에서 나머지 처리를 한다. topup_audits는 관리자 ID, 처리시각, 전후 값을 기록한다. SMS는 기존 Aligo 설정을 쓰되 미설정 상태를 발송 성공으로 기록하지 않는다.

마이그레이션 `2026_10_18_000004_add_topup_admin.php`는 랭크프리 topups/topup_audits만 다룬다. 기존 wallet_entries 원장은 보존한다.

이번 작업에서 운영 반영·배포·운영 마이그레이션·PR 병합은 수행하지 않았다. 향후 반영 여부는 별도 판단 대상이다. 롤백으로 신규 관리 칼럼/감사표를 제거하기 전 감사 이력과 원장 백업 및 정산이 필요하다. 실입금/실SMS/MySQL 행 잠금 실환경 검증은 수행하지 않았다.
