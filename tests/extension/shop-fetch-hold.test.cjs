const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const read = file => fs.readFileSync(path.join(__dirname, '../../extension', file), 'utf8');
const fetchSource = read('shop-serp-fetch.js');
const background = read('background.js');
const block = background.slice(background.indexOf('// ── fetch 수집 보안 확인 새 창 (BEGIN)'), background.indexOf('// ── fetch 수집 보안 확인 새 창 (END)'));

function sandbox(overrides = {}) {
  const store = {};
  const created = [], alarms = [], cleared = [], listeners = [];
  const chrome = {
    storage: { local: {
      get: async keys => { const list = typeof keys === 'string' ? [keys] : keys; return Object.fromEntries(list.filter(k => k in store).map(k => [k, store[k]])); },
      set: async v => { Object.assign(store, v); },
      remove: async keys => { for (const k of [].concat(keys)) delete store[k]; },
    } },
    windows: { create: async ({ url }) => { created.push(url); return { id: 100 + created.length, tabs: [{ id: 200 + created.length }] }; } },
    tabs: { get: async id => { if (overrides.openTabs && !overrides.openTabs.includes(id)) throw new Error('No tab'); return { id }; }, onRemoved: { addListener: fn => listeners.push(fn) } },
    alarms: { create: (name, info) => alarms.push({ name, info }), clear: async name => { cleared.push(name); } },
  };
  const context = vm.createContext({ chrome, Date, Number, Promise, handlers: overrides.handlers || {}, ShopSerpFetch: undefined });
  vm.runInContext(fetchSource + ';this.ShopSerpFetch = ShopSerpFetch;', context);
  vm.runInContext(block + ';this.holdFetchForHuman = holdFetchForHuman; this.releaseFetchHold = releaseFetchHold; this.probeFetchHold = probeFetchHold; this.FETCH_HOLD_ALARM = FETCH_HOLD_ALARM;', context);
  return { context, store, created, alarms, cleared, listeners };
}
const blocked = { ok: false, blocked: true, fetch_stopped: true, status: 429, url: 'https://m.search.naver.com/search.naver?where=m&query=%EA%B0%80%EC%A7%9C%ED%94%BC' };

test('restriction detection covers 403/429, the security-check pages and access-limit titles, and the blocked address is handed back', async () => {
  const S = sandbox().context.ShopSerpFetch;
  assert.equal(S.isRestricted({ status: 403, text: '' }), true);
  assert.equal(S.isRestricted({ status: 429, text: '' }), true);
  assert.equal(S.isRestricted({ status: 200, url: 'https://ncpt.naver.com/x', text: '' }), true);
  assert.equal(S.isRestricted({ status: 200, text: '<html><title>보안 확인</title></html>' }), true);
  assert.equal(S.isRestricted({ status: 200, text: '<form class="captcha_form"></form>' }), true);
  assert.equal(S.isRestricted({ status: 200, text: '<script>var title="보안 확인"</script><html><title>검색</title></html>' }), false);
  const r = await S.collect('가짜피', {}, { read: async () => ({ ok: false, status: 429, text: '' }) });
  assert.equal(r.fetch_stopped, true);
  assert.equal(r.url, 'https://m.search.naver.com/search.naver?where=m&query=' + encodeURIComponent('가짜피'));
  assert.match(r.message, /보안문자를 풀어 주세요/);
});

test('a blocked fetch opens the blocked address in ONE new window even when several lanes report it', async () => {
  const s = sandbox({ openTabs: [201] });
  await Promise.all([s.context.holdFetchForHuman(blocked), s.context.holdFetchForHuman(blocked), s.context.holdFetchForHuman(blocked)]);
  assert.deepEqual(s.created, [blocked.url]);
  assert.deepEqual(s.store.rfFetchStopped, blocked);
  assert.equal(s.store.rfFetchHoldWindow.tabId, 201);
  assert.ok(s.alarms.some(a => a.name === 'rf-fetch-hold-probe'));
});

test('closing the window releases the hold at once; a re-block within a minute does not reopen a window', async () => {
  const s = sandbox({ openTabs: [201] });
  await s.context.holdFetchForHuman(blocked);
  assert.equal(s.listeners.length, 1);
  await s.listeners[0](999);                       // 다른 탭이 닫힘 — 영향 없음
  assert.ok(s.store.rfFetchStopped);
  await s.listeners[0](201);                       // 그 창이 닫힘 → 바로 해제
  assert.equal(s.store.rfFetchStopped, undefined);
  assert.equal(s.store.rfFetchHoldWindow, undefined);
  assert.ok(s.cleared.includes('rf-fetch-hold-probe'));
  await s.context.holdFetchForHuman(blocked);      // 닫자마자 또 막힘 — 멈춤만 유지, 새 창은 열지 않는다
  assert.equal(s.created.length, 1);
  assert.ok(s.store.rfFetchStopped);
});

test('the same address answering normally releases the hold; a still-restricted answer keeps it; nothing is read or solved', async () => {
  let reply = { ok: true, status: 429, text: '' };
  const s = sandbox({ openTabs: [201], handlers: { spFetchHtml: async () => reply } });
  await s.context.holdFetchForHuman(blocked);
  await s.context.probeFetchHold();
  assert.ok(s.store.rfFetchStopped, '아직 막혀 있다');
  reply = { ok: true, status: 200, text: '<html><title>가짜피 : 네이버 검색</title></html>' };
  await s.context.probeFetchHold();
  assert.equal(s.store.rfFetchStopped, undefined);
  assert.equal(s.store.rfFetchHoldWindow, undefined);
  // 컴플라이언스: 이 블록은 창 안을 읽거나 풀거나 누르지 않는다(.claude/30_EXTENSION_STORE_COMPLIANCE.md)
  assert.doesNotMatch(block, /solveQuiz|sellerCaptcha|executeScript|sendMessage|\.click\(|dispatchEvent|insertText/i);
});

test('resume (the 이어서 button) lifts the hold before restarting', () => {
  assert.match(background, /async resumeShopSerp\(\{ collectorIp, analysisId \}\) \{\s*await releaseFetchHold\('manual'\);/);
  assert.match(background, /fetch_hold: !!\(await chrome\.storage\.local\.get\('rfFetchStopped'\)\)\.rfFetchStopped/);
  assert.match(background, /stop: result => holdFetchForHuman\(result\)/);
});
