#!/usr/bin/env node
/*
 * Chrome 웹스토어 원클릭 게시 — extension/ 을 zip 으로 묶어 업로드하고 심사 제출한다.
 *
 *   사용:  node scripts/publish-extension.mjs            # 빌드 → 업로드 → 게시(심사 제출)
 *          node scripts/publish-extension.mjs --upload-only   # 업로드까지만(대시보드에서 수동 제출)
 *          node scripts/publish-extension.mjs --dry-run       # 자격증명·zip·manifest 만 점검(호출 안 함)
 *          node scripts/publish-extension.mjs --testers       # 신뢰할 수 있는 테스터에게만 게시
 *
 * 전제: 최초 1회 웹스토어 대시보드에서 항목을 등록해 CWS_EXTENSION_ID 를 확보하고,
 *       OAuth 자격증명(CWS_CLIENT_ID/SECRET/REFRESH_TOKEN)을 .env 에 넣어둔다.
 *       발급 절차는 extension/PUBLISHING.md, refresh token 은 scripts/cws-get-refresh-token.mjs.
 *
 * 자격증명은 .env 에서만 읽는다(코드·문서에 하드코딩 금지 — 보안 규칙).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const EXT_DIR = path.join(ROOT, 'extension');
const argv = new Set(process.argv.slice(2));
const DRY = argv.has('--dry-run');
const UPLOAD_ONLY = argv.has('--upload-only');
const PUBLISH_TARGET = argv.has('--testers') ? 'trustedTesters' : 'default';

const log = (m) => console.log(m);
const die = (m) => { console.error('✖ ' + m); process.exit(1); };

// ── .env 파서(값 안의 = 유지, 따옴표 제거). process.env 가 우선. ──────────────
function loadEnv() {
  const env = {};
  const f = path.join(ROOT, '.env');
  if (fs.existsSync(f)) {
    for (const line of fs.readFileSync(f, 'utf8').split(/\r?\n/)) {
      const s = line.trim();
      if (!s || s.startsWith('#')) continue;
      const i = s.indexOf('=');
      if (i < 0) continue;
      const k = s.slice(0, i).trim();
      let v = s.slice(i + 1).trim();
      if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1);
      env[k] = v;
    }
  }
  return (k) => (process.env[k] && process.env[k].length ? process.env[k] : env[k]) || '';
}
const get = loadEnv();

// ── 자격증명 점검 ─────────────────────────────────────────────────────────────
const CLIENT_ID = get('CWS_CLIENT_ID');
const CLIENT_SECRET = get('CWS_CLIENT_SECRET');
const REFRESH_TOKEN = get('CWS_REFRESH_TOKEN');
const EXTENSION_ID = get('CWS_EXTENSION_ID');

const missing = [];
if (!CLIENT_ID) missing.push('CWS_CLIENT_ID');
if (!CLIENT_SECRET) missing.push('CWS_CLIENT_SECRET');
if (!REFRESH_TOKEN) missing.push('CWS_REFRESH_TOKEN');
if (!EXTENSION_ID) missing.push('CWS_EXTENSION_ID');

// ── manifest 버전 → zip 파일명 ────────────────────────────────────────────────
const manifest = JSON.parse(fs.readFileSync(path.join(EXT_DIR, 'manifest.json'), 'utf8'));
const VERSION = manifest.version;
const ZIP = path.join(ROOT, `rankfree-extension-v${VERSION}.zip`);

// ── zip 빌드(항상 최신 소스로 재생성) ────────────────────────────────────────
function buildZip() {
  try { fs.rmSync(ZIP, { force: true }); } catch (e) { /* noop */ }
  // 개발 문서(.md)는 제외한다 — CwsPublisher.php 와 같은 규칙. 심사 잡음을 줄이고,
  // 내부 설명이 공개 패키지로 새어 나가지 않게 한다(README 가 zip 에 들어가던 문제, 2026-08-10).
  if (process.platform === 'win32') {
    execFileSync('powershell', ['-NoProfile', '-Command',
      `Get-ChildItem -Path '${EXT_DIR}' -Exclude '*.md' | Compress-Archive -DestinationPath '${ZIP}' -Force`],
      { stdio: 'pipe' });
  } else {
    // extension/ 내용이 아카이브 루트에 오도록 그 안에서 압축
    execFileSync('bash', ['-c', `cd '${EXT_DIR}' && zip -r -X '${ZIP}' . -x '*.DS_Store' '*.md'`], { stdio: 'pipe' });
  }
  if (!fs.existsSync(ZIP)) die('zip 생성 실패: ' + ZIP);
  const kb = (fs.statSync(ZIP).size / 1024).toFixed(0);
  log(`✔ zip 빌드: ${path.basename(ZIP)} (${kb} KB)`);
}

// ── Chrome Web Store API ──────────────────────────────────────────────────────
async function accessToken() {
  const body = new URLSearchParams({
    client_id: CLIENT_ID, client_secret: CLIENT_SECRET,
    refresh_token: REFRESH_TOKEN, grant_type: 'refresh_token',
  });
  const r = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body,
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok || !j.access_token) {
    die('access token 발급 실패(' + r.status + '): ' + (j.error_description || j.error || JSON.stringify(j))
      + '\n  → refresh token 이 만료/무효일 수 있습니다. scripts/cws-get-refresh-token.mjs 로 재발급하세요.');
  }
  return j.access_token;
}

async function upload(token) {
  const bytes = fs.readFileSync(ZIP);
  const r = await fetch(`https://www.googleapis.com/upload/chromewebstore/v1.1/items/${EXTENSION_ID}`, {
    method: 'PUT',
    headers: { Authorization: 'Bearer ' + token, 'x-goog-api-version': '2' },
    body: bytes,
  });
  const j = await r.json().catch(() => ({}));
  if (j.uploadState === 'SUCCESS') { log('✔ 업로드 성공 (uploadState=SUCCESS)'); return; }
  const errs = (j.itemError || []).map((e) => e.error_detail || e.error_code).join('; ');
  die('업로드 실패(' + r.status + ', uploadState=' + (j.uploadState || '?') + '): ' + (errs || JSON.stringify(j))
    + '\n  → CWS_EXTENSION_ID 가 맞는지, manifest version 이 스토어 최신본보다 높은지 확인하세요.');
}

async function publish(token) {
  const r = await fetch(
    `https://www.googleapis.com/chromewebstore/v1.1/items/${EXTENSION_ID}/publish?publishTarget=${PUBLISH_TARGET}`,
    { method: 'POST', headers: { Authorization: 'Bearer ' + token, 'x-goog-api-version': '2', 'Content-Length': '0' } });
  const j = await r.json().catch(() => ({}));
  const status = (j.status || []);
  if (r.ok && (status.includes('OK') || status.includes('ITEM_PENDING_REVIEW'))) {
    log('✔ 게시 요청 접수: ' + status.join(', ') + (PUBLISH_TARGET === 'trustedTesters' ? ' (테스터 대상)' : ''));
    if (j.statusDetail && j.statusDetail.length) log('  상세: ' + j.statusDetail.join('; '));
    log('  → 심사 대기(보통 수 시간~며칠). 승인되면 스토어에 반영됩니다.');
    return;
  }
  die('게시 실패(' + r.status + '): ' + status.join(', ') + ' ' + (j.statusDetail || []).join('; ') || JSON.stringify(j));
}

// ── 실행 흐름 ─────────────────────────────────────────────────────────────────
(async () => {
  log(`▶ rankfree 확장 게시 — v${VERSION}  [target=${PUBLISH_TARGET}${UPLOAD_ONLY ? ', upload-only' : ''}${DRY ? ', dry-run' : ''}]`);

  buildZip();  // 자격증명 없어도 빌드는 검증한다

  if (missing.length) {
    const msg = '자격증명 누락(.env): ' + missing.join(', ')
      + '\n  → extension/PUBLISHING.md 절차로 발급 후 .env 에 넣으세요.';
    if (DRY) { log('ℹ 드라이런 — ' + msg); log('\n✔ 드라이런 통과: zip·manifest 정상. 자격증명만 채우면 실제 게시 가능합니다.'); process.exit(0); }
    die(msg);
  }
  log(`✔ 자격증명 확인(.env): CWS_EXTENSION_ID=${EXTENSION_ID.slice(0, 6)}…`);

  if (DRY) { log('\n✔ 드라이런 통과: zip·manifest·자격증명 모두 정상. 실제 게시는 --dry-run 없이 실행하세요.'); process.exit(0); }

  const token = await accessToken();
  log('✔ access token 발급');
  await upload(token);
  if (UPLOAD_ONLY) { log('■ --upload-only: 심사 제출은 건너뜁니다(대시보드에서 수동 제출).'); process.exit(0); }
  await publish(token);
  log('✅ 완료');
})().catch((e) => die(String(e && e.stack || e)));
