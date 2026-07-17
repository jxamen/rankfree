/**
 * RankFree 확장 — background service worker.
 * rankfree 서버와의 모든 통신(로그인/세션 확인/키워드 분석)을 담당한다.
 * content script 가 직접 cross-origin 호출을 하지 않도록 여기서 중계한다
 * (host_permissions 기반이라 CORS 제약이 없다).
 */

const DEFAULT_API_BASE = 'https://rankfree.kr';

async function getStore() {
  const data = await chrome.storage.local.get(['rfToken', 'rfUser', 'rfApiBase', 'rfApiKey']);
  return {
    token: data.rfToken || null,
    user: data.rfUser || null,
    apiBase: (data.rfApiBase || DEFAULT_API_BASE).replace(/\/+$/, ''),
    apiKey: data.rfApiKey || null,
  };
}

async function apiFetch(path, { method = 'GET', body = null, token = null, apiBase } = {}) {
  const base = apiBase || (await getStore()).apiBase;
  const headers = { Accept: 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  if (token) headers['Authorization'] = 'Bearer ' + token;

  let res;
  try {
    res = await fetch(base + path, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch (e) {
    // 네트워크 오류·서버 미접속 → status 0 (토큰은 유지되어야 함)
    return { ok: false, status: 0, json: null, networkError: true };
  }

  let json = null;
  try {
    json = await res.json();
  } catch (e) {
    /* JSON 아님 */
  }
  return { ok: res.ok, status: res.status, json };
}

function storeIdFromProductUrl(url) {
  try {
    const parsed = new URL(String(url || ''));
    const match = parsed.pathname.match(/^\/([^/]+)\/products\/\d+/);
    if (!match || !/(^|\.)((smartstore|brand)\.naver\.com)$/i.test(parsed.hostname)) return '';
    return decodeURIComponent(match[1]);
  } catch (e) {
    return '';
  }
}

function sellerInfoPopupUrl({ channelUid, storeId, productUrl }) {
  const uid = encodeURIComponent(String(channelUid || ''));
  const store = encodeURIComponent(String(storeId || ''));
  let from = 'smartstore';
  let prevUrl = store ? 'https://smartstore.naver.com/' + store + '/profile' : String(productUrl || '');
  try {
    const parsed = new URL(String(productUrl || ''));
    if (/brand\.naver\.com$/i.test(parsed.hostname)) {
      from = 'brandstore';
      prevUrl = store ? 'https://brand.naver.com/' + store + '/profile' : parsed.origin + parsed.pathname;
    }
  } catch (e) { /* noop */ }
  return 'https://shopping.naver.com/popup/seller-info/' + uid + '/profile?from=' +
    encodeURIComponent(from) + '&prevUrl=' + encodeURIComponent(prevUrl);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// 서비스워커가 새로 뜨면(확장 리로드·브라우저 재시작) 이전 수집 루프는 이미 죽어 있다.
// running 플래그만 남아 "이미 수집이 진행 중입니다"로 영구히 막히는 것을 막는다.
(async () => {
  try {
    const s = await chrome.storage.local.get(['rfBulk', 'rfSellerCaptcha']);
    if (s.rfBulk && s.rfBulk.running) {
      await chrome.storage.local.set({ rfBulk: Object.assign({}, s.rfBulk, { running: false, stop: false, current: '' }) });
    }
    if (s.rfSellerCaptcha && s.rfSellerCaptcha.running) {
      await chrome.storage.local.set({
        rfSellerCaptcha: Object.assign({}, s.rfSellerCaptcha, {
          running: false,
          stop: false,
          current: '',
          inFlight: 0,
          lastError: 'extension service worker restarted',
          finishedAt: Date.now(),
        }),
      });
    }
  } catch (e) { /* noop */ }
})();

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
    const { ok, status, json } = await apiFetch('/api/ext/me', { token, apiBase });
    if (!ok) {
      // 토큰이 실제로 무효(401)일 때만 제거. 네트워크/서버 오류(0·5xx·429)엔
      // 토큰을 유지하고 캐시된 사용자로 로그인 상태를 보존(자꾸 풀리던 원인).
      if (status === 401) {
        await chrome.storage.local.remove(['rfToken', 'rfUser']);
        return { ok: true, loggedIn: false };
      }
      return { ok: true, loggedIn: Boolean(user), user: user || null, apiBase, transient: true };
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

  /**
   * rankfree 키워드 분석.
   * API 키가 저장돼 있으면 공개 API v1 사용: 상세(성별·연령·트렌드) → 경량 순 폴백.
   * 키가 없으면 확장 로그인 토큰(ext) 경로 사용.
   */
  async keywordAnalysis({ keyword }) {
    const { token, apiBase, apiKey } = await getStore();
    const q = '?keyword=' + encodeURIComponent(keyword);

    if (apiKey) {
      for (const path of ['/api/v1/keyword/detail', '/api/v1/keyword']) {
        const { ok, status, json } = await apiFetch(path + q, { token: apiKey, apiBase });
        if (ok && json && json.data) {
          return { ok: true, data: json.data, source: 'api-key' };
        }
        if (status === 401) {
          return { ok: false, message: 'API 키가 유효하지 않습니다. 설정(⚙)에서 확인해 주세요.' };
        }
        if (status === 429) {
          return { ok: false, message: 'API 키 일일 한도를 초과했습니다.' };
        }
        // 403(scope 없음)·503(상세 소스 일시 장애) 등 → 다음 경로로 폴백
      }
    }

    if (!token) return { ok: false, loggedIn: false };

    // ext 토큰 경로 — 상세(성별·연령·12개월 트렌드) 우선, 실패 시 기본으로 폴백
    for (const path of ['/api/ext/keyword-analysis/detail', '/api/ext/keyword-analysis']) {
      const { ok, status, json } = await apiFetch(path + q, { token, apiBase });
      if (status === 401) {
        await chrome.storage.local.remove(['rfToken', 'rfUser']);
        return { ok: false, loggedIn: false };
      }
      if (ok && json && json.data) {
        return { ok: true, status, data: json.data, message: json.message, source: 'ext', share_token: json.share_token || null, apiBase };
      }
      // 503(상세 소스 일시 장애) 등 → 다음(기본) 경로로 폴백
    }
    return { ok: false, data: null, message: '키워드 분석 데이터를 조회하지 못했습니다.' };
  },

  /** '함께 많이 찾는'(SERP qra 모듈, badge 포함) — 서버가 SERP 크롤링. 확장 DOM scrape 대체 */
  async keywordTogether({ keyword }) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, json } = await apiFetch('/api/ext/keyword-together?keyword=' + encodeURIComponent(keyword || ''), { token, apiBase });
    if (ok && json) return { ok: true, data: json.data || [] };
    return { ok: false, data: [] };
  },

  /** 쇼핑 상품명 SEO 분석 — 상품명 배열 → 제목 점수·공통단어·추천·노출 키워드 */
  async shoppingSeo({ keyword, products }) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, json } = await apiFetch('/api/ext/shopping-seo', { method: 'POST', body: { keyword, products }, token, apiBase });
    if (ok && json) return { ok: true, data: json.data };
    return { ok: false, message: (json && json.message) || '상품명 SEO 분석에 실패했습니다.' };
  },

  /** 플레이스 리스트 순위(map.naver 배지) — 키워드 상위 오가닉 순위 목록(광고 제외·서울 고정 좌표) */
  async placeSerp({ keyword, cat, top }) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const q = '?keyword=' + encodeURIComponent(keyword || '') + '&cat=' + encodeURIComponent(cat || '') + '&top=' + (top || 100);
    const { ok, status, json } = await apiFetch('/api/ext/place-serp' + q, { token, apiBase });
    if (status === 401) {
      await chrome.storage.local.remove(['rfToken', 'rfUser']);
      return { ok: false, loggedIn: false };
    }
    if (ok && json) {
      return { ok: true, blocked: !!json.blocked, total: json.total || 0, items: json.items || [] };
    }
    return { ok: false, message: (json && json.message) || '플레이스 순위를 조회하지 못했습니다.' };
  },

  /** 단일 매장 정밀 분석(매장분석) — 완전 N1/N2/N3 + D1~D10(D7/D9/D10 포함) */
  async placeDetail({ place_id, keyword, cat }) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const q = '?place_id=' + encodeURIComponent(place_id || '') + '&keyword=' + encodeURIComponent(keyword || '') + '&cat=' + encodeURIComponent(cat || '');
    const { ok, status, json } = await apiFetch('/api/ext/place-detail' + q, { token, apiBase });
    if (status === 401) {
      await chrome.storage.local.remove(['rfToken', 'rfUser']);
      return { ok: false, loggedIn: false };
    }
    if (ok && json) {
      return { ok: true, detail: json.detail };
    }
    return { ok: false, message: (json && json.message) || '매장 상세 분석에 실패했습니다.' };
  },

  /** 시장 분석 결과 서버 저장 */
  async saveMarketAnalysis(payload) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/market-analyses', {
      method: 'POST',
      body: payload,
      token,
      apiBase,
    });
    return { ok, status, id: json && json.id, share_token: json && json.share_token, apiBase, message: json && json.message };
  },

  /** 저장된 분석 내역 목록 */
  async listMarketAnalyses({ limit } = {}) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/market-analyses?limit=' + (limit || 30), {
      token,
      apiBase,
    });
    return { ok, status, data: (json && json.data) || [] };
  },

  /** 저장된 분석 1건(스냅샷 포함) */
  async getMarketAnalysis({ id }) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/market-analyses/' + encodeURIComponent(id), {
      token,
      apiBase,
    });
    return { ok, status, data: json && json.data };
  },

  /** 상품 분석(리뷰) 저장 */
  async saveProductAnalysis(payload) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/product-analyses', {
      method: 'POST',
      body: payload,
      token,
      apiBase,
    });
    return { ok, status, id: json && json.id, share_token: json && json.share_token, apiBase, message: json && json.message };
  },

  async listProductAnalyses({ limit } = {}) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/product-analyses?limit=' + (limit || 30), {
      token,
      apiBase,
    });
    return { ok, status, data: (json && json.data) || [] };
  },

  async getProductAnalysis({ id }) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/product-analyses/' + encodeURIComponent(id), {
      token,
      apiBase,
    });
    return { ok, status, data: json && json.data };
  },

  /** 플레이스 매장 분석 저장(정밀 분석 완료분) — 같은 매장×키워드는 서버에서 갱신 */
  async savePlaceAnalysis(payload) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/place-analyses', {
      method: 'POST',
      body: payload,
      token,
      apiBase,
    });
    return { ok, status, id: json && json.id, share_url: json && json.share_url, apiBase, message: json && json.message };
  },

  async listPlaceAnalyses({ limit } = {}) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/place-analyses?limit=' + (limit || 30), {
      token,
      apiBase,
    });
    return { ok, status, data: (json && json.data) || [] };
  },

  async getPlaceAnalysis({ id }) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/place-analyses/' + encodeURIComponent(id), {
      token,
      apiBase,
    });
    return { ok, status, data: json && json.data };
  },

  async listSellerPower({ limit } = {}) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/seller-power?limit=' + (limit || 30), {
      token,
      apiBase,
    });
    return { ok, status, data: (json && json.data) || [], apiBase };
  },

  async getSellerPower({ id }) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/seller-power/' + encodeURIComponent(id), {
      token,
      apiBase,
    });
    return { ok, status, data: json && json.data, apiBase };
  },

  /**
   * 네이버 쇼핑 페이지 HTML 가져오기 (셀러력 — 검색결과·경쟁 상품 상세).
   * content script 의 cross-origin 제약을 우회(host_permissions 기반, 브라우저 세션 쿠키 포함).
   */
  async spFetchHtml({ url }) {
    try {
      const res = await fetch(url, {
        credentials: 'include',
        headers: { Accept: 'text/html,application/json', 'Accept-Language': 'ko-KR,ko;q=0.9' },
      });
      const text = await res.text();
      return { ok: res.ok, status: res.status, text };
    } catch (e) {
      return { ok: false, status: 0, message: String((e && e.message) || e) };
    }
  },

  /**
   * 셀러력 경쟁 상품 상세 수집 — 봇 차단(429)으로 fetch 불가하니, 비활성 백그라운드 탭으로
   * 실제 렌더한 뒤 content script(수집 모드)가 __PRELOADED_STATE__를 추출해 보내면 탭을 닫는다.
   */
  async sellerCollectDetail({ url, shouldStop }) {
    return new Promise((resolve) => {
      let tabId = null;
      let done = false;
      const to = setTimeout(() => finish({ ok: false, timeout: true }), 20000);
      const stopTimer = setInterval(async () => {
        try {
          if (!done && shouldStop && await shouldStop()) finish({ ok: false, stopped: true, message: 'stopped' });
        } catch (e) { /* noop */ }
      }, 500);
      function finish(res) {
        if (done) return;
        done = true;
        clearTimeout(to);
        clearInterval(stopTimer);
        chrome.runtime.onMessage.removeListener(onMsg);
        if (tabId != null) { try { chrome.tabs.remove(tabId); } catch (e) { /* noop */ } }
        resolve(res);
      }
      function onMsg(msg, sender) {
        if (sender && sender.tab && sender.tab.id === tabId && msg && msg.type === '__spCollected') {
          finish({ ok: !!msg.ok, data: msg.data || null });
        }
      }
      chrome.runtime.onMessage.addListener(onMsg);
      try {
        chrome.tabs.create({ url: String(url).split('#')[0] + '#rfspcollect', active: false }, (tab) => {
          if (chrome.runtime.lastError || !tab) { finish({ ok: false, message: 'tab_create_failed' }); return; }
          tabId = tab.id;
        });
      } catch (e) {
        finish({ ok: false, message: String(e && e.message || e) });
      }
    });
  },

  /**
   * 상품(리뷰) 분석 — 실제 스토어 상품 URL을 백그라운드(비활성) 탭으로 열어 분석 실행 후
   * 리포트 HTML 회수, 탭 자동 닫음. 결과는 검색 패널에 in-panel 표시.
   * (로그인 리다이렉트는 'main' 제네릭 URL 때문이었고, 실제 스토어 URL은 백그라운드에서도 정상 로드)
   */
  async openSellerInfoCaptcha({ channelUid, channelId, storeId, productUrl, active, keepOpen, shouldStop }) {
    return new Promise((resolve) => {
      let tabId = null;
      let windowId = null;
      let done = false;
      const url = sellerInfoPopupUrl({ channelUid, storeId, productUrl });
      const to = setTimeout(() => finish({
        ok: false,
        timeout: true,
        channelUid,
        channelId,
        storeId,
        sellerInfoUrl: url,
        message: 'seller-info captcha capture timeout',
      }), 18000);
      const stopTimer = setInterval(async () => {
        try {
          if (!done && shouldStop && await shouldStop()) {
            finish({
              ok: false,
              stopped: true,
              channelUid,
              channelId,
              storeId,
              sellerInfoUrl: url,
              message: 'stopped',
            });
          }
        } catch (e) { /* noop */ }
      }, 500);

      function finish(res) {
        if (done) return;
        done = true;
        clearTimeout(to);
        clearInterval(stopTimer);
        chrome.runtime.onMessage.removeListener(onMsg);
        if (!keepOpen) {
          if (windowId != null) {
            try { chrome.windows.remove(windowId); } catch (e) { /* noop */ }
          } else if (tabId != null) {
            try { chrome.tabs.remove(tabId); } catch (e) { /* noop */ }
          }
        }
        resolve(res);
      }

      function onMsg(msg, sender) {
        if (!(sender && sender.tab && sender.tab.id === tabId && msg && msg.type === '__sellerCaptchaCaptured')) return;
        finish({
          ok: !!msg.ok,
          channelUid: msg.channelUid || channelUid,
          channelId,
          storeId,
          sellerInfoUrl: url,
          data: msg.data || null,
          apiBase: msg.apiBase || '',
          message: msg.message || '',
        });
      }

      chrome.runtime.onMessage.addListener(onMsg);
      try {
        // 팝업 창이 아니라 일반 탭으로 연다 — 팝업(window.open) 컨텍스트가 없어
        // 정답 통과 후 다음 단계로 넘어가지 못하던 문제를 피한다.
        chrome.tabs.create({
          url,
          active: !!active || !!keepOpen,
        }, (tab) => {
          if (chrome.runtime.lastError || !tab) {
            finish({ ok: false, message: 'seller_info_tab_create_failed', channelUid, channelId, storeId, sellerInfoUrl: url });
            return;
          }
          tabId = tab.id;
          if (done && !keepOpen && tabId != null) {
            try { chrome.tabs.remove(tabId); } catch (e) { /* noop */ }
          }
        });
      } catch (e) {
        finish({ ok: false, message: String(e && e.message || e), channelUid, channelId, storeId, sellerInfoUrl: url });
      }
    });
  },

  async sellerCaptchaStart({ products, active, force, concurrency, keepOpen }) {
    const { token } = await getStore();
    if (!token) return { ok: false, loggedIn: false, message: 'RankFree extension login is required.' };

    const list = (Array.isArray(products) ? products : [])
      .map((p) => ({
        url: String((p && p.url) || '').split('#')[0],
        title: String((p && p.title) || ''),
        storeId: String((p && p.storeId) || ''),
      }))
      .filter((p) => p.url);
    if (!list.length) return { ok: false, message: 'No product URLs.' };

    const prev = (await chrome.storage.local.get(['rfSellerCaptcha'])).rfSellerCaptcha;
    if (prev && prev.running && prev.heartbeat && Date.now() - prev.heartbeat < 60000 && !force) {
      return { ok: false, message: 'Seller captcha collection is already running.' };
    }

    const conc = Math.min(5, Math.max(1, Number(concurrency) || (list.length === 1 ? 1 : 3)));
    const keepTabsOpen = !!keepOpen;
    const init = {
      running: true, stop: false, total: list.length, done: 0, saved: 0, failed: 0,
      current: '', inFlight: 0, concurrency: conc, lastError: '', results: [], startedAt: Date.now(), heartbeat: Date.now(),
    };
    await chrome.storage.local.set({ rfSellerCaptcha: init });

    (async () => {
      const patch = async (data) => {
        const cur = (await chrome.storage.local.get(['rfSellerCaptcha'])).rfSellerCaptcha || {};
        await chrome.storage.local.set({ rfSellerCaptcha: Object.assign({}, cur, data, { heartbeat: Date.now() }) });
      };
      const isStopped = async () => !!(((await chrome.storage.local.get(['rfSellerCaptcha'])).rfSellerCaptcha || {}).stop);
      let nextIndex = 0, done = 0, saved = 0, failed = 0;
      const results = [];
      const activeItems = new Map();

      const currentText = () => Array.from(activeItems.values()).slice(0, 3).join(', ');

      const take = async () => {
        if (await isStopped()) return null;
        if (nextIndex >= list.length) return null;
        return list[nextIndex++];
      };

      const worker = async (workerId) => {
        while (true) {
          const item = await take();
          if (!item) break;

          const label = item.title || item.url;
          const storeId = item.storeId || storeIdFromProductUrl(item.url);
          activeItems.set(workerId, label);
          await patch({ current: currentText(), inFlight: activeItems.size });

          let result = { ok: false, url: item.url, title: item.title, storeId, message: '' };
          try {
            if (await isStopped()) break;
            if (!storeId) throw new Error('store_id_not_found');

            const detail = await handlers.sellerCollectDetail({ url: item.url, shouldStop: isStopped });
            if (detail && detail.stopped) break;

            const channel = detail && detail.data && detail.data.smartStoreV2 && detail.data.smartStoreV2.channel
              ? detail.data.smartStoreV2.channel
              : {};
            const channelUid = String(channel.channelUid || '');
            const channelId = String(channel.channelId || '');
            const channelNo = channel.id || null;
            if (!detail || !detail.ok || !channelUid) throw new Error((detail && detail.message) || 'channel_uid_not_found');

            const cap = await handlers.openSellerInfoCaptcha({
              channelUid,
              channelId,
              storeId,
              productUrl: item.url,
              active: !!active,
              keepOpen: keepTabsOpen,
              shouldStop: isStopped,
            });
            if (cap && cap.stopped) break;

            result = Object.assign({
              url: item.url, title: item.title, storeId, channelUid, channelId, channelNo,
            }, cap || { ok: false, message: 'unknown_error' });
          } catch (e) {
            result.message = String((e && e.message) || e);
          } finally {
            activeItems.delete(workerId);
          }

          done++;
          if (result.ok) saved++; else failed++;
          results.push(result);
          await patch({
            done,
            saved,
            failed,
            results,
            current: currentText(),
            inFlight: activeItems.size,
            lastError: result.ok ? '' : (result.message || 'failed'),
          });

          if (await isStopped()) break;
          await sleep(200);
        }
      };

      try {
        await Promise.all(Array.from({ length: conc }, (_, i) => worker(i)));
      } finally {
        const stopped = await isStopped();
        await patch({
          running: false,
          stop: false,
          stopped,
          current: '',
          inFlight: 0,
          finishedAt: Date.now(),
        });
      }
    })();

    return { ok: true, started: true, total: list.length, concurrency: conc };
  },

  async sellerCaptchaStatus() {
    const s = await chrome.storage.local.get(['rfSellerCaptcha']);
    return { ok: true, job: s.rfSellerCaptcha || { running: false, done: 0, saved: 0, failed: 0, total: 0, current: '' } };
  },

  async sellerCaptchaStop() {
    const s = await chrome.storage.local.get(['rfSellerCaptcha']);
    if (s.rfSellerCaptcha) await chrome.storage.local.set({ rfSellerCaptcha: Object.assign({}, s.rfSellerCaptcha, { stop: true }) });
    return { ok: true };
  },

  async reviewCollectDetail({ url, title }, origin) {
    const originTabId = origin && origin.tab && origin.tab.id;
    const MAX_ATTEMPTS = 2;       // 최초 1회 + 재시도 1회
    const PER_ATTEMPT_MS = 150000; // 시도별 예산 — 백그라운드 탭은 타이머가 1초로 스로틀되어 여유 필요
    const collectUrl = String(url).split('#')[0] + '#rfreviewcollect';
    const jobBase = { url: String(url).split('#')[0], title: String(title || '') };
    // 잡 상태를 storage에 기록 — 사용자가 다른 페이지로 이동해도 진행·결과를 이어서 볼 수 있게 함
    const setJob = (patch) => { try { chrome.storage.local.set({ rfReviewJob: Object.assign({}, jobBase, patch) }); } catch (e) { /* noop */ } };
    setJob({ status: 'running', pct: 0, startedAt: Date.now() });
    return new Promise((resolve) => {
      let tabId = null;
      let done = false;
      let attempt = 0;
      let to = null;
      function armTimeout() { clearTimeout(to); to = setTimeout(onTimeout, PER_ATTEMPT_MS); }
      function finish(res) {
        if (done) return;
        done = true;
        clearTimeout(to);
        chrome.runtime.onMessage.removeListener(onMsg);
        if (tabId != null) { try { chrome.tabs.remove(tabId); } catch (e) { /* noop */ } }
        setJob({ status: 'done', ok: !!res.ok, html: res.html || '', name: res.name || '', message: res.message || '', id: res.id || null, share_token: res.share_token || null, finishedAt: Date.now() });
        resolve(res);
      }
      // 실패/타임아웃 시: 탭이 상품 메인으로 이탈해 해시가 유실됐을 수 있으므로,
      // reload 대신 collectUrl(#rfreviewcollect)로 재이동해 수집 모드를 재무장한다.
      // 포커스는 절대 가져오지 않는다(백그라운드 유지).
      function retryOrFail(failRes) {
        if (done) return;
        attempt++;
        if (attempt >= MAX_ATTEMPTS || tabId == null) { finish(failRes); return; }
        try {
          chrome.tabs.update(tabId, { url: collectUrl }, () => {
            if (chrome.runtime.lastError) { finish(failRes); return; }
          });
        } catch (e) { finish(failRes); return; }
        armTimeout();
      }
      function onTimeout() {
        retryOrFail({ ok: false, timeout: true, message: '분석 시간이 초과되었습니다. 리뷰가 많은 상품은 시간이 더 걸릴 수 있어요.' });
      }
      function onMsg(msg, snd) {
        if (!(snd && snd.tab && snd.tab.id === tabId && msg)) return;
        if (msg.type === '__reviewProgress') {
          setJob({ status: 'running', pct: msg.pct || 0, startedAt: Date.now() }); // 다른 페이지에서도 진행률 구독 가능
          if (originTabId != null) { try { chrome.tabs.sendMessage(originTabId, { type: '__reviewProgress', pct: msg.pct }); } catch (e) { /* noop */ } }
          return;
        }
        if (msg.type === '__reviewCollected') {
          if (msg.ok && msg.html) {
            finish({ ok: true, html: msg.html, name: msg.name || '', message: '', id: msg.id || null, share_token: msg.share_token || null });
          } else {
            retryOrFail({ ok: false, message: msg.message || '리뷰 분석에 실패했습니다.' });
          }
        }
      }
      chrome.runtime.onMessage.addListener(onMsg);
      try {
        // 백그라운드 탭 고정 — 사용자의 현재 페이지를 절대 바꾸지 않는다.
        // 렌더 없이도 리뷰 지연로딩이 발화하도록 injected-store.js가 visibility 스푸핑 +
        // 리뷰 섹션 한정 IntersectionObserver 합성 교차(setTimeout 기반, 배경 탭에서도 동작)를 건다.
        chrome.tabs.create({ url: collectUrl, active: false }, (tab) => {
          if (chrome.runtime.lastError || !tab) { finish({ ok: false, message: 'tab_create_failed' }); return; }
          tabId = tab.id;
          armTimeout();
        });
      } catch (e) {
        finish({ ok: false, message: String(e && e.message || e) });
      }
    });
  },

  /** 통합검색 등 비쇼핑 페이지 위임 수집 — 쇼핑 검색 페이지를 백그라운드 탭으로 열어 상품을 수집해 회신. */
  /**
   * 대량 자동 수집 — 서버 대기열(미수집·오래된 키워드)을 받아 한 건씩 연속 수집한다.
   * 쇼핑 키워드가 수만 개라 사람이 하나씩 클릭할 수 없다. 진행 상황은 rfBulk 로 저장해 화면이 폴링한다.
   */
  async bulkShopStart({ limit, delayMs, concurrency, force }) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false, message: '확장에 로그인해 주세요.' };

    // running 플래그가 영구히 남지 않게 — 확장 리로드/서비스워커 종료로 루프가 죽어도 플래그만 남는다.
    // 살아있는 루프는 heartbeat 를 갱신하므로, 60초 넘게 갱신이 없으면 죽은 것으로 보고 새로 시작한다.
    const state = await chrome.storage.local.get(['rfBulk']);
    const prev = state.rfBulk;
    if (prev && prev.running) {
      const alive = prev.heartbeat && (Date.now() - prev.heartbeat) < 60000;
      if (alive && !force) {
        return { ok: false, message: '이미 수집이 진행 중입니다. (중단하려면 중단 버튼)' };
      }
      // 죽은 세션 정리 후 진행
      await chrome.storage.local.set({ rfBulk: Object.assign({}, prev, { running: false, stop: true }) });
      await new Promise((r) => setTimeout(r, 50));
    }

    // limit=0(또는 미지정) = 무제한 — 카테고리를 순서대로 끝까지. 멈춰도 서버 대기열이 이어할 지점을 알려준다.
    const max = Number(limit) > 0 ? Math.min(100000, Number(limit)) : Infinity;
    // 너무 빨리 열면 네이버가 일시 차단한다 — 건당 최소 4초, 기본 6초.
    const baseGap = Math.max(1000, Number(delayMs) || 6000);   // 하한 1초 — 차단되면 아래에서 1초씩 자동으로 늘린다
    // 병렬 수집 — 동시에 탭 N개. 많을수록 빠르지만 차단 위험이 커진다(1~10).
    const conc = Math.min(10, Math.max(1, Number(concurrency) || 2));
    // ★ 콜드스타트 버스트 방지 — 처음부터 conc개(예: 10) 탭을 한꺼번에 열면 네이버가 바로 429/차단한다.
    //   낮게(warmStart) 시작해 성공이 이어지면 1씩 올리고(램프업), 차단되면 절반으로 줄인다.
    const warmStart = conc <= 2 ? conc : 2;
    await chrome.storage.local.set({ rfBulk: {
      running: true, done: 0, failed: 0, total: (max === Infinity ? 0 : max), current: '', category: '',
      conc: warmStart, target: conc, gap: baseGap, blockedUntil: 0, startedAt: Date.now(), stop: false, lastError: '',
      heartbeat: Date.now(),   // 살아있음 표시 — 끊기면 죽은 세션으로 보고 재시작 허용
    } });

    // 백그라운드로 진행(응답은 즉시) — 화면은 bulkShopStatus 로 폴링
    (async () => {
      const MAX_GAP = 10000;    // 간격 상한 10초
      let done = 0, failed = 0;
      let gap = baseGap;        // 차단이 뜨면 1초씩 늘리고, 성공이 이어지면 천천히 되돌린다
      let queue = [];           // 서버 대기열 버퍼
      let category = '';
      let stopped = false;
      let blocked = false;      // 차단 상태 — 모든 워커가 대기한다
      let liveConc = warmStart; // 실제 동시 수 — 낮게 시작(warmStart)해 성공하면 올리고 차단되면 줄인다
      let blockHits = 0;        // 연속 차단 횟수
      let okStreak = 0;         // 연속 성공 횟수 — 일정 이상이면 동시 수를 1 올린다(램프업)
      let exhausted = false;    // 모든 분류 소진 — 대기 중이던 워커도 이걸 보고 종료한다
      const RAMP_EVERY = 3;     // 연속 성공 N회마다 동시 수 +1 (target = conc 까지)

      const patch = async (o) => {
        const cur = (await chrome.storage.local.get(['rfBulk'])).rfBulk || {};
        await chrome.storage.local.set({ rfBulk: Object.assign({}, cur, o, { heartbeat: Date.now() }) });
      };
      const isStopped = async () => stopped || !!((await chrome.storage.local.get(['rfBulk'])).rfBulk || {}).stop;
      // 대기 중에도 중단이 즉시 먹히도록 잘게 쪼개 잔다(6~10초 sleep 중 중단 눌러도 바로 멈춤)
      const sleep = async (ms) => {
        const end = Date.now() + ms;
        while (Date.now() < end) {
          await new Promise((r) => setTimeout(r, Math.min(500, end - Date.now())));
          if (stopped || await isStopped()) { stopped = true; return; }
        }
      };
      // 살아있음 신호 — 루프가 죽으면 끊긴다(그때만 재시작 허용)
      const hb = setInterval(() => { patch({}); }, 15000);

      // 카테고리 순서(1차 → 2차 → 3차)로 대기열을 받는다. 비면 서버에서 다음 분류를 받아온다.
      // ★ 워커가 동시에 호출하면 각자 같은 20개를 받아 같은 키워드를 중복 수집한다(실측: 4개 워커가 '린넨원피스' 반복).
      //   → 리필을 단일 체인으로 직렬화하고, 이미 꺼낸 키워드는 taken 으로 걸러 한 번만 나가게 한다.
      const taken = new Set();
      let refill = Promise.resolve();
      const next = async () => {
        while (true) {
          while (queue.length) {
            const kw = queue.shift();
            if (kw && !taken.has(kw)) { taken.add(kw); return kw; }
          }
          let got = false;
          refill = refill.then(async () => {
            if (queue.length) { got = true; return; }        // 다른 워커가 이미 채웠다
            // 동시 수가 많으면 20개는 금방 비어 리필이 잦아진다 — 동시 수에 맞춰 넉넉히 받는다(서버 상한 50)
            const qLimit = Math.min(50, Math.max(20, conc * 4));
            const { ok, json } = await apiFetch('/api/ext/keyword-shop-serp/queue?mode=category&limit=' + qLimit, { token, apiBase });
            const d = (ok && json && json.data) || {};
            queue = (d.keywords || []).filter((k) => !taken.has(k));   // 서버가 또 준 것(수집 반영 전)은 제외
            got = queue.length > 0;
            if (d.category && d.category !== category) {
              category = d.category;
              await patch({ category, categoryIndex: d.category_index || 0, categoryTotal: d.category_total || 0, remaining: d.remaining || 0 });
            }
          });
          await refill;
          if (!got && !queue.length) return null;            // 정말 더 없음
        }
      };

      // 차단 판정 — 네이버가 실제로 막았을 때의 문구만 본다.
      // '시간이 초과'는 차단이 아니라 우리 쪽이 오래 걸린 것이라 여기서 뺀다
      // (실측: 가격비교 많은 의류 키워드에서 카탈로그를 다 읽다 초과 → 창엔 차단 메시지가 없었다).
      const looksBlocked = (msg) => /차단|429|일시적으로 제한|데이터를 찾지 못|페이지가 열리지 않/.test(String(msg || ''));

      // 탭은 '순서대로' 열되 기다리지 않는다 — 앞 탭이 열리면 바로 다음 탭을 연다.
      // (gap 만큼 직렬 대기시키면 동시 N개가 무의미해져 사실상 순차가 된다)
      let openChain = Promise.resolve();
      const openSlot = () => {
        const my = openChain.then(() => sleep(300));   // 탭 생성만 0.3초 간격으로 겹치지 않게
        openChain = my;

        return my;
      };

      let workerNo = 0;
      const worker = async () => {
        const me = workerNo++;                              // 0..conc-1 — 워밍업/차단으로 정원이 바뀌면 합류·대기한다
        while (!stopped && !exhausted && done + failed < max) {
          // 아직/이미 정원(liveConc) 밖이면 — 종료하지 말고 대기한다.
          // 워밍업으로 정원이 늘면 합류하고, 차단으로 줄면 다시 빠진다(종료 X → 회복 시 재합류).
          if (me >= liveConc) {
            await sleep(1500);
            continue;                                       // 다시 조건 검사(stopped/exhausted/정원 반영)
          }
          if (await isStopped()) { stopped = true; break; }

          // 차단 중이면 다음 작업을 하지 않고 대기한다(계속 두드리면 더 오래 막힌다)
          while (blocked && !stopped) {
            await sleep(2000);
            if (await isStopped()) { stopped = true; break; }
          }
          if (stopped) break;

          const kw = await next();
          if (!kw) { exhausted = true; break; }             // 모든 분류를 다 비웠다 — 대기 중 워커도 종료시킨다

          await openSlot();                                 // ★ 탭 여는 순번 대기(직렬)
          if (stopped || blocked) continue;
          await patch({ current: kw });

          const r = await handlers.collectShopSerp({ keyword: kw, count: 80 });
          if (r && r.ok) {
            done++;
            gap = Math.max(baseGap, gap - 1000);            // 성공하면 1초씩 되돌린다(하한 = 설정값)
            blockHits = 0;                                  // 잘 돌고 있다 — 연속 차단 카운터 초기화
            // ★ 램프업 — 성공이 이어지면 동시 수를 target(conc)까지 1씩 천천히 올린다.
            //   (콜드스타트에 10개를 한꺼번에 열지 않고, 네이버가 받아주는 만큼만 서서히 늘린다)
            okStreak++;
            if (okStreak >= RAMP_EVERY && liveConc < conc) {
              liveConc++;
              okStreak = 0;
              await patch({ done, failed, gap, conc: liveConc, blockedUntil: 0 });
            } else {
              await patch({ done, failed, gap, blockedUntil: 0 });
            }
          } else {
            failed++;
            const lastError = (r && r.message) || '알 수 없는 오류';
            if (looksBlocked(lastError)) {
              // 차단 — 간격을 1초 늘리고(최대 10초), 그 시간만큼 전 워커가 쉰다
              gap = Math.min(MAX_GAP, gap + 1000);
              blocked = true;
              blockHits++;
              okStreak = 0;                                 // 차단됨 — 램프업 진행도 초기화(다시 낮은 데서 올린다)

              // ★ 차단이 이어지면 동시 수를 줄인다 — 간격만 늘려봐야 탭 N개가 한꺼번에 몰리는 건 그대로다.
              //   (동시 10으로 돌리면 네이버가 429로 막는다 — 실측). 최소 2까지 절반씩 줄이고,
              //   줄인 워커는 스스로 빠진다(quota 초과분).
              if (blockHits >= 2 && liveConc > 2) {
                liveConc = Math.max(2, Math.floor(liveConc / 2));
                blockHits = 0;
                await patch({ conc: liveConc });
              }

              // 차단이 반복될수록 더 오래 쉰다(429 는 계속 두드리면 더 길어진다)
              const rest = Math.min(60000, gap * (1 + blockHits));
              const until = Date.now() + rest;
              await patch({ done, failed, gap, conc: liveConc,
                lastError: lastError + ' — 차단 감지, ' + Math.round(rest / 1000) + '초 대기 후 재개(동시 ' + liveConc + ')',
                blockedUntil: until });
              await sleep(rest);
              blocked = false;
              await patch({ blockedUntil: 0 });
            } else {
              await patch({ done, failed, gap, lastError });
            }
          }
          // 다음 탭 간격은 openSlot 이 관리한다(여기서 또 쉬면 이중 대기)
        }
      };

      try {
        await Promise.all(Array.from({ length: conc }, () => worker()));
      } finally {
        clearInterval(hb);
        // 어떤 경로로 끝나든 running 을 반드시 내린다(플래그가 남아 재시작이 막히지 않게)
        await patch({ running: false, current: '', stop: false, finishedAt: Date.now() });
      }
    })();

    return { ok: true, started: true, total: (max === Infinity ? 0 : max), concurrency: conc };
  },

  /** 대량 수집 진행 상황 */
  async bulkShopStatus() {
    const s = await chrome.storage.local.get(['rfBulk']);
    return { ok: true, bulk: s.rfBulk || { running: false, done: 0, failed: 0, total: 0, current: '' } };
  },

  /** 대량 수집 중단 */
  async bulkShopStop() {
    const s = await chrome.storage.local.get(['rfBulk']);
    if (s.rfBulk) await chrome.storage.local.set({ rfBulk: Object.assign({}, s.rfBulk, { stop: true }) });
    return { ok: true };
  },

  /**
   * 관리자 화면 요청 — 쇼핑 상품(상위 80)을 수집해 서버에 저장한다.
   * 서버는 search.shopping 이 418 이라 직접 수집할 수 없어 확장이 대신한다.
   */
  async collectShopSerp({ keyword, count }) {
    const kw = String(keyword || '').trim();
    if (!kw) return { ok: false, message: '키워드가 없습니다.' };

    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false, message: '확장에 로그인해 주세요.' };

    const col = await handlers.collectShopping({ keyword: kw, count: Number(count) || 80 });
    if (!col || !col.ok || !Array.isArray(col.products) || !col.products.length) {
      return { ok: false, message: (col && col.message) || '상품 수집에 실패했습니다.' };
    }

    const products = col.products.slice(0, Number(count) || 80).map((p, i) => ({
      title: String(p.title || ''),
      rank: p.rank || i + 1,
      price: Number(p.price) || 0,
      mallName: String(p.mallName || ''),
      link: String(p.link || ''),
      isAd: !!p.isAd,
      talkId: String(p.talkId || ''),   // 톡톡 코드(talk.naver.com/ct/{code}) — 관리자에서 톡톡 열기용
      storeId: String(p.storeId || ''), // 스토어 핸들(smartstore/brand) — 서버가 스마트스토어만 저장한다
      reviewCount: Number(p.reviewCount) || 0,
      isCatalog: !!p.isCatalog,         // 가격비교는 저장하지 않는다(서버에서도 한 번 더 막는다)
    })).filter((p) => p.title);

    const { ok, json } = await apiFetch('/api/ext/keyword-shop-serp', {
      method: 'POST',
      body: { keyword: kw, total: col.total || 0, products, related_tags: col.relatedTags || [] },
      token,
      apiBase,
    });
    if (!ok) return { ok: false, message: (json && json.message) || '서버 저장에 실패했습니다.' };

    return { ok: true, saved: (json && json.data && json.data.saved) || products.length, total: col.total || 0 };
  },

  async collectShopping({ keyword, count }) {
    return new Promise((resolve) => {
      let tabId = null;
      let done = false;
      // 상품 5페이지(약 20초) + 가격비교 카탈로그 확장(예산 20초) + 여유
      const to = setTimeout(() => finish({ ok: false, timeout: true, message: '상품 수집 시간이 초과되었습니다.' }), 75000);
      // 네이버가 429/차단으로 막으면 크롬 에러 페이지라 content script 가 아예 안 돈다.
      // 그때는 살아있음 신호가 없다 — 75초를 기다리지 말고 12초에 차단으로 판정해 바로 쉰다
      // (계속 두드리면 네이버가 더 오래 막는다).
      const alive = setTimeout(() => {
        finish({ ok: false, message: '페이지가 열리지 않았습니다 — 차단(429)으로 보입니다.' });
      }, 12000);
      function finish(res) {
        if (done) return;
        done = true;
        clearTimeout(to);
        clearTimeout(alive);
        chrome.runtime.onMessage.removeListener(onMsg);
        if (tabId != null) { try { chrome.tabs.remove(tabId); } catch (e) { /* noop */ } }
        resolve(res);
      }
      function onMsg(msg, sender) {
        if (!sender || !sender.tab || sender.tab.id !== tabId || !msg) return;
        // 수집 스크립트가 떴다 — 페이지는 정상이니 살아있음 타이머만 끈다(수집 자체는 계속 기다린다)
        if (msg.type === '__shoppingCollectStarted') { clearTimeout(alive); return; }
        if (msg.type === '__shoppingCollected') {
          finish({ ok: !!msg.ok, products: msg.products || [], total: msg.total || 0, relatedTags: msg.relatedTags || [], message: msg.message || '' });
        }
      }
      chrome.runtime.onMessage.addListener(onMsg);
      try {
        const url = 'https://search.shopping.naver.com/search/all?query=' + encodeURIComponent(String(keyword || '')) + '#rfcollect=' + (Number(count) || 80);
        chrome.tabs.create({ url, active: false }, (tab) => {
          if (chrome.runtime.lastError || !tab) { finish({ ok: false, message: 'tab_create_failed' }); return; }
          tabId = tab.id;
        });
      } catch (e) {
        finish({ ok: false, message: String(e && e.message || e) });
      }
    });
  },

  /** 셀러력 경쟁 상품 목록 — 서버가 shop.json으로 검색(검색 API 봇 차단 우회). */
  async sellerCompetitors({ keyword }) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/seller-power/competitors?keyword=' + encodeURIComponent(keyword), { token, apiBase });
    return { ok, status, products: (json && json.products) || [] };
  },

  /** 셀러력 분석 결과 서버 저장(서버가 계산해 결과 반환). */
  async saveSellerPower(payload) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/seller-power', {
      method: 'POST',
      body: payload,
      token,
      apiBase,
    });
    return { ok, status, id: json && json.id, shareToken: json && json.share_token, apiBase, result: json && json.result, message: json && json.message };
  },

  /** 톡톡/스토어 연락 식별자 수집 저장(마케팅 리드 · 조회는 슈퍼어드민). */
  async harvestTalk(payload) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false };
    const { ok, status, json } = await apiFetch('/api/ext/talk-contacts', {
      method: 'POST',
      body: payload,
      token,
      apiBase,
    });
    return { ok, status, saved: json && json.saved };
  },

  async saveSellerCaptcha(payload) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false, apiBase, message: 'RankFree extension login is required.' };
    const { ok, status, json } = await apiFetch('/api/ext/seller-captchas', {
      method: 'POST',
      body: payload,
      token,
      apiBase,
    });
    return {
      ok,
      status,
      apiBase,
      data: json && json.data,
      message: (json && (json.message || json.error)) || (ok ? '' : 'Failed to save seller captcha.'),
    };
  },

  /**
   * 퀴즈 풀이 — 질문 텍스트 + 보기 이미지를 서버(Gemini)로 보내 정답을 받아온다. (저장하지 않음)
   * payload: { question?, image_data?, images?, instruction? }
   */
  async solveQuiz(payload) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false, apiBase, message: 'RankFree extension login is required.' };
    const { ok, status, json } = await apiFetch('/api/ext/quiz/solve', {
      method: 'POST',
      body: payload,
      token,
      apiBase,
    });
    return {
      ok,
      status,
      apiBase,
      data: json && json.data,
      answer: json && json.data ? json.data.answer : null,
      message: (json && (json.message || json.error)) || (ok ? '' : 'Failed to solve quiz.'),
    };
  },

  /** 캡차 통과 후 판매자정보 팝업에서 파싱한 사업자 정보를 업체(채널) 기준으로 저장. */
  async saveSellerInfo(payload) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false, apiBase, message: 'RankFree extension login is required.' };
    const { ok, status, json } = await apiFetch('/api/ext/seller-infos', {
      method: 'POST',
      body: payload,
      token,
      apiBase,
    });
    return {
      ok,
      status,
      apiBase,
      data: json && json.data,
      message: (json && (json.message || json.error)) || (ok ? '' : 'Failed to save seller info.'),
    };
  },

  /** 확장 설정 조회 (API 키 등) */
  async getSettings() {
    const { apiKey, apiBase } = await getStore();
    return { ok: true, apiKey: apiKey || '', apiBase };
  },

  /** 확장 설정 저장 — 빈 값이면 키 제거 */
  async saveSettings({ apiKey }) {
    const value = String(apiKey || '').trim();
    if (value) {
      await chrome.storage.local.set({ rfApiKey: value });
    } else {
      await chrome.storage.local.remove('rfApiKey');
    }
    return { ok: true, hasKey: Boolean(value) };
  },
};

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  const handler = handlers[msg && msg.type];
  if (!handler) {
    sendResponse({ ok: false, message: 'unknown message: ' + (msg && msg.type) });
    return false;
  }
  handler(msg.payload || {}, sender)
    .then(sendResponse)
    .catch((e) => sendResponse({ ok: false, message: String((e && e.message) || e) }));
  return true; // async 응답
});
