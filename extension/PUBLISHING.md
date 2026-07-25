# 확장 웹스토어 게시 (자동화)

`extension/` 을 Chrome 웹스토어에 올리는 절차. **최초 1회 셋업**만 끝내면, 이후에는
`node scripts/publish-extension.mjs` 한 줄(또는 "게시해줘")로 빌드→업로드→심사제출이 된다.

- 게시 스크립트: [scripts/publish-extension.mjs](../scripts/publish-extension.mjs)
- refresh token 발급 헬퍼: [scripts/cws-get-refresh-token.mjs](../scripts/cws-get-refresh-token.mjs)
- 자격증명은 `.env` 에만 둔다(커밋 안 됨). 등록정보 문구는 [STORE_LISTING.md](./STORE_LISTING.md).

---

## 최초 1회 셋업

### A. 웹스토어에 확장 최초 등록 → 항목 ID
> API 는 "기존 항목 업데이트/게시"만 한다. 최초 항목 생성은 대시보드에서 1회 해야 한다.

1. https://chrome.google.com/webstore/devconsole 접속(게시할 구글 계정).
2. 개발자 등록이 안 돼 있으면 **일회성 등록비 $5** 결제.
3. **새 항목** → `rankfree-extension-v0.3.18.zip` 업로드(먼저 `node scripts/publish-extension.mjs --dry-run` 으로 zip 생성).
4. 스토어 등록정보 입력: 설명은 [STORE_LISTING.md](./STORE_LISTING.md), 아이콘/스크린샷, **개인정보처리방침 URL = `https://rankfree.kr/privacy`**.
5. 저장 후 주소창의 항목 ID(32자 영문 소문자)를 복사 → `.env` 의 `CWS_EXTENSION_ID`.
   - (여기서 한 번 **제출**해 최초 게시를 마쳐도 되고, API 로 제출해도 된다.)

### B. GCP OAuth 자격증명
1. https://console.cloud.google.com 에서 프로젝트 생성(또는 기존 것).
2. **API 및 서비스 → 라이브러리 → "Chrome Web Store API" 사용 설정**.
3. **OAuth 동의 화면** 구성(External, 앱 이름/이메일만 채우면 됨. 테스트 사용자에 본인 구글 계정 추가).
4. **사용자 인증 정보 → 사용자 인증 정보 만들기 → OAuth 클라이언트 ID → 유형 "데스크톱 앱"**.
   - 데스크톱 앱 유형은 `http://localhost` 임의 포트 리다이렉트를 허용한다(발급 헬퍼가 이걸 쓴다).
5. 발급된 client_id / client_secret → `.env` 의 `CWS_CLIENT_ID` / `CWS_CLIENT_SECRET`.

### C. refresh token 발급
로컬 터미널에서:
```
node scripts/cws-get-refresh-token.mjs
```
브라우저 동의 화면이 열린다 → 승인하면 터미널에 `CWS_REFRESH_TOKEN=...` 이 출력된다 → `.env` 에 붙여넣기.

이제 `.env` 에 4개가 모두 채워진다:
```
CWS_EXTENSION_ID=...
CWS_CLIENT_ID=...
CWS_CLIENT_SECRET=...
CWS_REFRESH_TOKEN=...
```

---

## 이후 게시 (원클릭)

```
node scripts/publish-extension.mjs             # 빌드 → 업로드 → 심사 제출(기본)
node scripts/publish-extension.mjs --upload-only   # 업로드까지만(대시보드에서 수동 제출)
node scripts/publish-extension.mjs --testers       # 신뢰할 수 있는 테스터에게만 게시
node scripts/publish-extension.mjs --dry-run       # 자격증명·zip·manifest 점검만(호출 안 함)
```

- 스크립트가 `extension/manifest.json` 의 `version` 을 읽어 `rankfree-extension-v{ver}.zip` 을 **매번 새로 빌드**한다.
- **버전을 올리지 않으면 업로드가 거부**된다(스토어 최신본보다 높아야 함). 새로 게시할 땐 `manifest.json` 의 `version` 을 먼저 올린다.
- 심사는 보통 수 시간~며칠. 승인되면 스토어에 반영된다.

### 게시 후 할 일
- 웹스토어에서 발급된 실제 항목 URL(`https://chromewebstore.google.com/detail/{id}`)로 **설치 링크(현재 플레이스홀더)를 교체**한다 — 콘솔 배너([database/seeders/CmsSeeder.php](../database/seeders/CmsSeeder.php)의 "설치하기") 등. 교체 후 `deploy-remote.sh` 로 배포.

---

## 트러블슈팅
- **access token 발급 실패 / invalid_grant**: refresh token 만료·무효. `cws-get-refresh-token.mjs` 재실행.
- **업로드 실패 (uploadState=FAILURE)**: `CWS_EXTENSION_ID` 오타 또는 `manifest.version` 이 스토어 최신본과 같거나 낮음.
- **refresh_token 이 안 나옴**: 이미 동의한 앱이면 구글이 refresh token 을 생략한다 — 헬퍼는 `prompt=consent&access_type=offline` 로 매번 재동의를 강제하므로, 그래도 안 나오면 [내 계정 → 보안 → 서드파티 액세스](https://myaccount.google.com/connections)에서 해당 앱 제거 후 재시도.
- **403 (권한 없음)**: GCP 에서 "Chrome Web Store API" 사용 설정이 안 됐거나, 동의 화면 scope/테스트 사용자 누락.
