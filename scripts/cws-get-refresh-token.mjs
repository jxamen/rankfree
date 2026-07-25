#!/usr/bin/env node
/*
 * Chrome 웹스토어 게시용 OAuth refresh token 발급 헬퍼(대화형).
 *
 *   사용(로컬 터미널에서):  node scripts/cws-get-refresh-token.mjs
 *
 * 전제: GCP 에서 "Chrome Web Store API" 활성화 + OAuth 클라이언트(**데스크톱 앱** 유형)를 만들고,
 *       그 client_id/secret 을 .env 의 CWS_CLIENT_ID / CWS_CLIENT_SECRET 에 넣어둔다.
 *       (데스크톱 앱 유형은 http://localhost 임의 포트 리다이렉트를 허용한다.)
 *
 * 동작: 임시 로컬 서버를 띄우고 구글 동의 화면 URL 을 연다 → 브라우저에서 승인하면
 *       redirect 로 code 를 받아 refresh token 으로 교환해 출력한다.
 *       출력된 값을 .env 의 CWS_REFRESH_TOKEN 에 붙여넣으면 된다.
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFile } from 'node:child_process';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SCOPE = 'https://www.googleapis.com/auth/chromewebstore';

function get(k) {
  if (process.env[k]) return process.env[k];
  const f = path.join(ROOT, '.env');
  if (!fs.existsSync(f)) return '';
  for (const line of fs.readFileSync(f, 'utf8').split(/\r?\n/)) {
    const s = line.trim();
    if (!s || s.startsWith('#')) continue;
    const i = s.indexOf('=');
    if (i < 0 || s.slice(0, i).trim() !== k) continue;
    let v = s.slice(i + 1).trim();
    if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1);
    return v;
  }
  return '';
}

const CLIENT_ID = get('CWS_CLIENT_ID');
const CLIENT_SECRET = get('CWS_CLIENT_SECRET');
if (!CLIENT_ID || !CLIENT_SECRET) {
  console.error('✖ .env 에 CWS_CLIENT_ID / CWS_CLIENT_SECRET 가 없습니다.');
  console.error('  → GCP 에서 Chrome Web Store API 활성화 + OAuth 클라이언트(데스크톱 앱)를 만들고 .env 에 넣으세요.');
  console.error('  → 자세한 절차: extension/PUBLISHING.md');
  process.exit(1);
}

function openBrowser(url) {
  const cmd = process.platform === 'win32' ? 'cmd' : (process.platform === 'darwin' ? 'open' : 'xdg-open');
  const args = process.platform === 'win32' ? ['/c', 'start', '', url] : [url];
  try { execFile(cmd, args, () => {}); } catch (e) { /* 사용자가 URL 직접 열면 됨 */ }
}

const server = http.createServer(async (req, res) => {
  const u = new URL(req.url, 'http://localhost');
  if (!u.searchParams.has('code') && !u.searchParams.has('error')) { res.writeHead(404); res.end(); return; }
  const err = u.searchParams.get('error');
  if (err) {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end('<h2>인증 취소/실패: ' + err + '</h2><p>터미널로 돌아가 다시 시도하세요.</p>');
    console.error('✖ 인증 실패: ' + err);
    server.close(); process.exit(1);
  }
  const code = u.searchParams.get('code');
  const port = server.address().port;
  try {
    const body = new URLSearchParams({
      code, client_id: CLIENT_ID, client_secret: CLIENT_SECRET,
      redirect_uri: `http://localhost:${port}`, grant_type: 'authorization_code',
    });
    const r = await fetch('https://oauth2.googleapis.com/token', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body,
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok || !j.refresh_token) {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end('<h2>토큰 교환 실패</h2><pre>' + JSON.stringify(j, null, 2) + '</pre>');
      console.error('✖ 토큰 교환 실패(' + r.status + '): ' + (j.error_description || j.error || JSON.stringify(j)));
      if (!j.refresh_token && j.access_token) console.error('  (refresh_token 이 없습니다 — 동의 화면에서 이미 승인한 앱이면 access_type=offline&prompt=consent 로 재동의가 필요합니다.)');
      server.close(); process.exit(1);
    }
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end('<h2>✅ 발급 완료</h2><p>터미널에 출력된 CWS_REFRESH_TOKEN 을 .env 에 넣으세요. 이 창은 닫아도 됩니다.</p>');
    console.log('\n✅ refresh token 발급 성공. 아래 값을 .env 에 넣으세요:\n');
    console.log('CWS_REFRESH_TOKEN=' + j.refresh_token + '\n');
    server.close(); process.exit(0);
  } catch (e) {
    console.error('✖ 오류: ' + (e && e.message || e));
    server.close(); process.exit(1);
  }
});

server.listen(0, '127.0.0.1', () => {
  const port = server.address().port;
  const authUrl = 'https://accounts.google.com/o/oauth2/auth?' + new URLSearchParams({
    response_type: 'code', client_id: CLIENT_ID, redirect_uri: `http://localhost:${port}`,
    scope: SCOPE, access_type: 'offline', prompt: 'consent',
  }).toString();
  console.log('▶ 브라우저에서 구글 계정으로 승인하세요(웹스토어 게시 권한).');
  console.log('  자동으로 안 열리면 아래 URL 을 직접 여세요:\n');
  console.log('  ' + authUrl + '\n');
  console.log('  (승인 후 자동으로 이 터미널에 refresh token 이 출력됩니다. 대기 중…)');
  openBrowser(authUrl);
});
