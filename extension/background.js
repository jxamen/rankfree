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
  async sellerCollectDetail({ url }) {
    return new Promise((resolve) => {
      let tabId = null;
      let done = false;
      const to = setTimeout(() => finish({ ok: false, timeout: true }), 20000);
      function finish(res) {
        if (done) return;
        done = true;
        clearTimeout(to);
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
  async bulkShopStart({ limit, delayMs }) {
    const { token, apiBase } = await getStore();
    if (!token) return { ok: false, loggedIn: false, message: '확장에 로그인해 주세요.' };

    const state = await chrome.storage.local.get(['rfBulk']);
    if (state.rfBulk && state.rfBulk.running) return { ok: false, message: '이미 수집이 진행 중입니다.' };

    const max = Math.min(500, Math.max(1, Number(limit) || 50));
    const gap = Math.max(1000, Number(delayMs) || 2500);   // 네이버 부하를 낮추려 건당 최소 1초
    await chrome.storage.local.set({ rfBulk: { running: true, done: 0, failed: 0, total: max, current: '', startedAt: Date.now(), stop: false } });

    // 백그라운드로 진행(응답은 즉시) — 화면은 bulkShopStatus 로 폴링
    (async () => {
      let done = 0, failed = 0;
      while (done + failed < max) {
        const st = (await chrome.storage.local.get(['rfBulk'])).rfBulk || {};
        if (st.stop) break;

        const { ok, json } = await apiFetch('/api/ext/keyword-shop-serp/queue?limit=10', { token, apiBase });
        const list = (ok && json && json.data && json.data.keywords) || [];
        if (!list.length) break;                            // 더 수집할 게 없음

        for (const kw of list) {
          const cur = (await chrome.storage.local.get(['rfBulk'])).rfBulk || {};
          if (cur.stop || done + failed >= max) break;
          await chrome.storage.local.set({ rfBulk: Object.assign({}, cur, { current: kw }) });

          const r = await handlers.collectShopSerp({ keyword: kw, count: 80 });
          let lastError = '';
          if (r && r.ok) {
            done++;
          } else {
            failed++;
            lastError = (r && r.message) || '알 수 없는 오류';   // 실패 사유를 남겨야 원인을 안다
          }

          const now = (await chrome.storage.local.get(['rfBulk'])).rfBulk || {};
          await chrome.storage.local.set({ rfBulk: Object.assign({}, now, { done, failed, lastError: lastError || now.lastError || '' }) });

          // 연속 5건 이상 실패하면 중단 — 차단·로그인 만료 상태로 계속 두드리지 않는다
          if (failed >= 5 && done === 0) {
            const st2 = (await chrome.storage.local.get(['rfBulk'])).rfBulk || {};
            await chrome.storage.local.set({ rfBulk: Object.assign({}, st2, { stop: true, lastError: lastError + ' (연속 실패로 중단)' }) });
            break;
          }
          await new Promise((r2) => setTimeout(r2, gap));
        }
      }
      const fin = (await chrome.storage.local.get(['rfBulk'])).rfBulk || {};
      await chrome.storage.local.set({ rfBulk: Object.assign({}, fin, { running: false, current: '', finishedAt: Date.now() }) });
    })();

    return { ok: true, started: true, total: max };
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
      const to = setTimeout(() => finish({ ok: false, timeout: true, message: '상품 수집 시간이 초과되었습니다.' }), 60000);
      function finish(res) {
        if (done) return;
        done = true;
        clearTimeout(to);
        chrome.runtime.onMessage.removeListener(onMsg);
        if (tabId != null) { try { chrome.tabs.remove(tabId); } catch (e) { /* noop */ } }
        resolve(res);
      }
      function onMsg(msg, sender) {
        if (sender && sender.tab && sender.tab.id === tabId && msg && msg.type === '__shoppingCollected') {
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
