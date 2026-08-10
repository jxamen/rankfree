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

// 탭/창 닫기 — 사용자가 탭을 드래그 중이면 크롬이 "Tabs cannot be edited right now
// (user may be dragging a tab)" 로 프로미스를 '거부'한다. 콜백 없이 부르면 try/catch 로는
// 못 잡아 'Uncaught (in promise)' 로 샌다. 거부를 삼키고, 드래그가 끝나면 잠시 뒤 재시도해
// 수집 탭이 남지 않게 한다.
function removeTab(id, tries) {
  if (id == null) return;
  tries = tries == null ? 4 : tries;
  let p;
  try { p = chrome.tabs.remove(id); } catch (e) { return; }
  if (p && typeof p.catch === 'function') {
    p.catch((e) => {
      if (tries > 0 && /dragging|cannot be edited/i.test(String((e && e.message) || e))) {
        setTimeout(() => removeTab(id, tries - 1), 600);
      }
    });
  }
}

// 상품페이지 백그라운드 수집 완료 대기 — product.js 의 saveProductInfo 도착으로 해소된다.
const productInfoWaiters = new Map();

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

  /** 상품정보(제목·업체명·가격·SEO태그) 저장 — 노출 키워드 분석 조합 재료(25) */
  async saveProductInfo(payload) {
    // 백그라운드 수집이 이 상품의 완료를 기다리고 있으면 payload 째로 알려준다(공개본엔 대기자가 없어 no-op)
    // (콘솔 페이지가 자기 서버에 직접 저장 — 확장 로그인이 prod 를 보고 있어도 로컬 분석이 동작)
    const notify = (r) => {
      const w = productInfoWaiters.get(String((payload && payload.channel_product_id) || ''));
      if (w) w(Object.assign({ payload }, r));
      return r;
    };
    const { token, apiBase } = await getStore();
    if (!token) return notify({ ok: false, loggedIn: false });
    const { ok, status, json } = await apiFetch('/api/ext/product-infos', {
      method: 'POST',
      body: payload,
      token,
      apiBase,
    });
    return notify({ ok, status, id: json && json.id, message: json && json.message });
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
        removeTab(tabId);
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
        removeTab(tabId);
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

  /**
   * 쇼핑 순위체크(패널 UI) — 키워드 × 상품URL/업체명으로 지금 순위를 확인한다.
   *
   * 수집은 시장분석과 같은 수집기(collectShopping)로 하고, **순위 판정은 서버**가 한다 —
   * 워커(claim/result)와 같은 판정기를 써야 규칙(광고 제외 오가닉)이 두 곳으로 갈라지지 않는다.
   * 슬롯·큐를 만들지 않으므로 기록에 남지 않는 1회성 조회다.
   */
  async shopRankCheck({ keyword, target, count }) {
    const kw = String(keyword || '').trim();
    const tg = String(target || '').trim();
    if (!kw) return { ok: false, message: '키워드를 입력하세요.' };
    if (!tg) return { ok: false, message: '상품 URL 또는 업체명을 입력하세요.' };

    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false, message: '확장에 로그인해 주세요.' };

    // 대상 해석은 서버에 맡긴다(확장이 URL 파싱을 따로 갖지 않게) — 조기중단 힌트로만 쓴다.
    // 힌트가 있으면 대상을 찾는 즉시 수집을 멈춘다: 상위권 상품은 1페이지에서 끝나 훨씬 빠르고
    // 네이버 트래픽도 줄어든다. 실패해도 힌트 없이 끝까지 훑을 뿐 결과는 같다.
    const rs = await apiFetch('/api/ext/shop-rank/resolve', { method: 'POST', body: { target: tg }, token, apiBase });
    const hint = (rs && rs.ok && rs.json && rs.json.data) ? rs.json.data : null;

    const col = await handlers.collectShopping({
      keyword: kw,
      count: Number(count) || 400,
      match: hint ? { product_id: hint.product_id || '', mall_name: hint.mall_name || '' } : undefined,
    });
    if (!col || !col.ok || !Array.isArray(col.products) || !col.products.length) {
      return {
        ok: false,
        blocked: !!(col && col.blocked),
        message: (col && col.message) || '상품 목록을 가져오지 못했습니다.',
      };
    }

    const { ok, json } = await apiFetch('/api/ext/shop-rank/check', {
      method: 'POST',
      body: { keyword: kw, target: tg, products: col.products, total: col.total || 0 },
      token,
      apiBase,
    });
    if (!ok || !json || !json.ok) {
      return { ok: false, message: (json && json.message) || '순위 판정에 실패했습니다.' };
    }

    return { ok: true, data: json.data, scanned: col.products.length, total: col.total || 0 };
  },

  async collectShopping({ keyword, count, match }) {
    return new Promise((resolve) => {
      let tabId = null;
      let done = false;
      let loaded = false;    // tabs.onUpdated status:complete — 페이지가 실제로 떴다
      let started = false;   // content script(document_idle) 시작 신호 — 수집 스크립트가 돈다
      let stall = null;      // '떴는데 수집 스크립트가 안 붙음'(에러 페이지 등) 판정용

      // 상품 5페이지(약 20초) + 가격비교 카탈로그 확장(예산 20초) + 여유.
      // 순위체크는 1000위(13페이지)까지 뒤지므로 요청 개수에 비례해 늘린다 — 고정 75초면 깊은 스캔이 항상 타임아웃난다.
      const budgetMs = Math.min(300000, 75000 + Math.max(0, (Number(count) || 80) - 400) * 90);
      const to = setTimeout(() => finish({ ok: false, timeout: true, message: '상품 수집 시간이 초과되었습니다.' }), budgetMs);

      // '안 열림 = 차단' 판정. 예전엔 시작 신호를 12초 안 받으면 차단으로 봤으나, 동시 수집(백그라운드 탭 N개)에서는
      //   크롬이 background 탭의 document_idle content script 주입을 뒤로 미뤄, 페이지는 3~4초에 떠도 시작 신호가
      //   12초를 넘겨 도착 → 멀쩡한 페이지를 차단으로 오판했다(실측: 동시 4에서 다발, 로딩은 12초도 안 걸림).
      //   → 열림 판정을 시작 신호가 아니라 '실제 페이지 로드 완료(tabs.onUpdated status:complete)'로 바꾼다.
      //     이 신호는 content script 주입 스케줄링과 무관하게 네트워크/렌더 완료 시점에 온다.
      //   alive: complete·시작신호 둘 다 30초 없으면 진짜 미로딩(연결 실패·차단)으로 보고 차단 처리(백오프).
      //     동시수를 상한(10)까지 올리거나 회선이 느리면 complete 가 20초대에 오기도 해 30초로 여유를 둔다.
      const alive = setTimeout(() => {
        if (!loaded && !started) finish({ ok: false, message: '페이지가 열리지 않았습니다 — 차단(429)으로 보입니다.' });
      }, 30000);
      const clearBlockTimers = () => { clearTimeout(alive); if (stall) { clearTimeout(stall); stall = null; } };
      const isGateUrl = (u) => !!u && /^https:\/\/(nid\.naver\.com|captcha\.naver\.com|ncpt\.naver\.com)\//i.test(u);

      // 페이지가 실제로 떴다(complete) — '안 열림' 판정 해제. 단, 떴는데도 수집 스크립트가 15초 안 붙으면
      //   대개 네이버 429 에러 페이지다("HTTP ERROR 429" — 크롬 에러 페이지라 status:complete 는 뜨지만
      //   content script 가 주입되지 않아 시작 신호가 영영 안 온다). → 차단으로 보고 백오프시킨다(메시지에
      //   429·차단 토큰을 넣어 looksBlocked 매칭). 정상 페이지는 complete 후 시작 신호가 수 초 내 오므로 15초면
      //   오탐이 없다(원 버그는 create→complete 지연이 컸던 것이지 complete→started 간격은 짧다).
      const markLoaded = () => {
        if (loaded || started) return;
        loaded = true;
        clearTimeout(alive);
        if (!stall) stall = setTimeout(() => {
          if (!started) finish({ ok: false, message: '페이지는 떴으나 수집 스크립트 미응답 — 429 차단으로 보입니다.' });
        }, 15000);
      };

      // 탭 상태 감시 — 캡차/로그인 리다이렉트(진짜 차단)는 즉시, 로드 완료는 '열림'으로 본다.
      const onUpd = (id, info) => {
        if (!tabId || id !== tabId || !info) return;
        if (isGateUrl(info.url || '')) {
          // 네이버가 보안(캡차/로그인) 게이트로 돌렸다 — 확실한 차단. 타이머 안 기다리고 즉시 판정.
          finish({ ok: false, message: '네이버 보안(캡차) 감지 — 차단으로 보입니다.' });
          return;
        }
        if (info.status === 'complete') markLoaded();
      };

      function finish(res) {
        if (done) return;
        done = true;
        clearTimeout(to);
        clearBlockTimers();
        chrome.runtime.onMessage.removeListener(onMsg);
        try { chrome.tabs.onUpdated.removeListener(onUpd); } catch (e) { /* noop */ }
        removeTab(tabId);
        resolve(res);
      }
      function onMsg(msg, sender) {
        if (!sender || !sender.tab || sender.tab.id !== tabId || !msg) return;
        // 수집 스크립트가 떴다 — 페이지는 정상이니 차단 판정 타이머를 모두 끈다(수집 자체는 계속 기다린다)
        if (msg.type === '__shoppingCollectStarted') { started = true; clearBlockTimers(); return; }
        // 페이지별 진행 — 몇 페이지에서 몇 개를 받았고 왜 멈췄는지가 여기서만 보인다
        if (msg.type === '__shoppingCollectProgress') {
          console.log('[RankFree]   ' + msg.page + '페이지: +' + msg.got
            + ' (누적 ' + msg.organic + '/' + msg.target + ')' + (msg.note ? ' — ' + msg.note : ''));
          return;
        }
        if (msg.type === '__shoppingCollected') {
          finish({
            ok: !!msg.ok, products: msg.products || [], total: msg.total || 0,
            relatedTags: msg.relatedTags || [], message: msg.message || '',
            blocked: !!msg.blocked,   // 418/429 — 워커가 이걸 보고 즉시 쉰다
          });
        }
      }
      chrome.runtime.onMessage.addListener(onMsg);
      try { chrome.tabs.onUpdated.addListener(onUpd); } catch (e) { /* noop */ }
      try {
        // 조기 중단 힌트(pid·mall)를 함께 넘긴다 — 대상을 찾으면 페이지 수집을 멈춰 차단을 피한다
        const hint = match ? ('&pid=' + encodeURIComponent(match.product_id || '') + '&mall=' + encodeURIComponent(match.mall_name || '')) : '';
        const url = 'https://search.shopping.naver.com/search/all?query=' + encodeURIComponent(String(keyword || '')) + '#rfcollect=' + (Number(count) || 80) + hint;
        chrome.tabs.create({ url, active: false }, (tab) => {
          if (chrome.runtime.lastError || !tab) { finish({ ok: false, message: 'tab_create_failed' }); return; }
          tabId = tab.id;
          if (done) { removeTab(tab.id); return; }   // 콜백 전에 이미 끝났으면(타이머 등) 고아 탭 정리
          // ★ 콜백 이전에 도착해 !tabId 가드로 놓쳤을 수 있는 캡차 리다이렉트/complete 를 1회 보정(경합 하드닝)
          try {
            chrome.tabs.get(tab.id, (t) => {
              if (chrome.runtime.lastError || !t || done) return;
              if (isGateUrl(t.url || t.pendingUrl || '')) { finish({ ok: false, message: '네이버 보안(캡차) 감지 — 차단으로 보입니다.' }); return; }
              if (t.status === 'complete') markLoaded();
            });
          } catch (e) { /* noop */ }
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
