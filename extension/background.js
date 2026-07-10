/**
 * RankFree 확장 — background service worker.
 * rankfree 서버와의 모든 통신(로그인/세션 확인/키워드 분석)을 담당한다.
 * content script 가 직접 cross-origin 호출을 하지 않도록 여기서 중계한다
 * (host_permissions 기반이라 CORS 제약이 없다).
 */

const DEFAULT_API_BASE = 'https://rankfree.kr';

async function getStore() {
  const data = await chrome.storage.local.get(['rfToken', 'rfUser', 'rfApiBase']);
  return {
    token: data.rfToken || null,
    user: data.rfUser || null,
    apiBase: (data.rfApiBase || DEFAULT_API_BASE).replace(/\/+$/, ''),
  };
}

async function apiFetch(path, { method = 'GET', body = null, token = null, apiBase } = {}) {
  const base = apiBase || (await getStore()).apiBase;
  const headers = { Accept: 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  if (token) headers['Authorization'] = 'Bearer ' + token;

  const res = await fetch(base + path, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });

  let json = null;
  try {
    json = await res.json();
  } catch (e) {
    /* JSON 아님 */
  }
  return { ok: res.ok, status: res.status, json };
}

const handlers = {
  /** 로그인 → 토큰 저장 */
  async login({ email, password, apiBase }) {
    const base = (apiBase || DEFAULT_API_BASE).replace(/\/+$/, '');
    const { ok, status, json } = await apiFetch('/api/ext/login', {
      method: 'POST',
      body: { email, password, device_name: 'chrome-extension' },
      apiBase: base,
    });
    if (!ok || !json || !json.token) {
      return {
        ok: false,
        status,
        message:
          (json && (json.message || json.error)) ||
          (status === 0 ? '서버에 연결할 수 없습니다.' : '로그인에 실패했습니다. (' + status + ')'),
      };
    }
    await chrome.storage.local.set({
      rfToken: json.token,
      rfUser: json.user || null,
      rfApiBase: base,
    });
    return { ok: true, user: json.user || null };
  },

  /** 저장된 토큰이 유효한지 확인 */
  async session() {
    const { token, user, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, json } = await apiFetch('/api/ext/me', { token, apiBase });
    if (!ok) {
      await chrome.storage.local.remove(['rfToken', 'rfUser']);
      return { ok: true, loggedIn: false };
    }
    const freshUser = (json && json.user) || user;
    await chrome.storage.local.set({ rfUser: freshUser });
    return { ok: true, loggedIn: true, user: freshUser, apiBase };
  },

  /** 로그아웃 */
  async logout() {
    const { token, apiBase } = await getStore();
    if (token) {
      try {
        await apiFetch('/api/ext/logout', { method: 'POST', token, apiBase });
      } catch (e) {
        /* 서버 실패해도 로컬 토큰은 지운다 */
      }
    }
    await chrome.storage.local.remove(['rfToken', 'rfUser']);
    return { ok: true };
  },

  /** rankfree 키워드 분석 (네이버 월간 검색량 등) */
  async keywordAnalysis({ keyword }) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch(
      '/api/ext/keyword-analysis?keyword=' + encodeURIComponent(keyword),
      { token, apiBase }
    );
    if (status === 401) {
      await chrome.storage.local.remove(['rfToken', 'rfUser']);
      return { ok: false, loggedIn: false };
    }
    return { ok, status, data: (json && json.data) || null, message: json && json.message };
  },
};

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  const handler = handlers[msg && msg.type];
  if (!handler) {
    sendResponse({ ok: false, message: 'unknown message: ' + (msg && msg.type) });
    return false;
  }
  handler(msg.payload || {})
    .then(sendResponse)
    .catch((e) => sendResponse({ ok: false, message: String((e && e.message) || e) }));
  return true; // async 응답
});
