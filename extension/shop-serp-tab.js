/* Each policy lane owns one inactive, unpinned collection tab reused across jobs. */
class ShopSerpTab {
  constructor({ slot = 0, browser = chrome, timeoutMs = 15000, wait = ms => new Promise(r => setTimeout(r, ms)) } = {}) {
    this.slot = slot; this.browser = browser; this.timeoutMs = timeoutMs; this.wait = wait;
    this.tabId = null;
  }
  session() {
    const storage = this.browser.storage.session;
    const name = key => this.slot ? key + ':lane:' + this.slot : key;
    return {
      get: async keys => {
        const list = typeof keys === 'string' ? [keys] : keys;
        const data = await storage.get(list.map(name));
        return Object.fromEntries(list.map(k => [k, data[name(k)]]));
      },
      set: data => storage.set(Object.fromEntries(Object.entries(data).map(([k,v]) => [name(k),v]))),
    };
  }
  async create(options) {
    const job = (ShopSerpTab.openLane || Promise.resolve()).then(async () => {
      const storage = this.browser.storage.session;
      const saved = await storage.get('rfSerpLastOpened');
      const gap = Math.max(0, Number(this.openIntervalMs ?? 3000));
      const delay = Math.max(0, Number(saved.rfSerpLastOpened || 0) + gap - Date.now());
      if (delay) await this.wait(delay);
      if (this.allowOpen && !this.allowOpen()) { const e = new Error('차단 감지 — 동시 검색 1개로 줄임'); e.code = 'RF_PARALLEL_REDUCED'; throw e; }
      if (await this.closed()) { const e = new Error('수집 탭 닫힘'); e.code = 'RF_TAB_CLOSED'; throw e; }
      const tab = await this.browser.tabs.create(options);
      await storage.set({rfSerpLastOpened:Date.now()});
      return tab;
    });
    ShopSerpTab.openLane = job.catch(() => {});
    return job;
  }
  static html(products) {
    const slots = products.map((p, i) => ({ data: {
      sourceType: p.isAd ? 'AD' : 'SAS', rank: p.rank || i + 1,
      channelProductId: String(p.channelProductId || p.mallProductId || ''),
      nvMid: String(p.nvMid || p.id || ''), productUrl: { pcUrl: p.link || '' },
      mallName: p.mallName || '', productName: p.title || '', salePrice: p.price || 0,
    } }));
    return '<script>var _INITIAL_STATE = ' + JSON.stringify({ initProps: { pagedSlot: [{ slots }] } }).replace(/</g, '\\u003c') + ';</script>';
  }
  // The fetch option uses the same embedded shopping list and ad fields as the tab reader.
  static fetchedHtml(html) {
    const match = html.match(/<script\b[^>]*\bid=["']__NEXT_DATA__["'][^>]*>([\s\S]*?)<\/script>/i);
    if (!match) return html;
    const candidates = [];
    function walk(node, key = '', depth = 0) {
      if (!node || typeof node !== 'object' || depth > 16) return;
      if (Array.isArray(node)) {
        const sample = node[0]?.item || node[0];
        if (sample && typeof sample === 'object' && ['productTitle','productName','lowPrice','reviewCount','mallName','rank'].filter(k => k in sample).length >= 2) {
          candidates.push({list: node, promo: /superSaving|adProduct|\bads?\b/i.test(key), wrapped: !!node[0].item});
        } else for (const child of node) walk(child, key, depth + 1);
      } else for (const k of Object.keys(node)) walk(node[k], k, depth + 1);
    }
    try { walk(JSON.parse(match[1])); } catch (_) { return html; }
    const wrapped = candidates.filter(c => !c.promo && c.wrapped && c.list.length >= 10);
    const main = candidates.filter(c => !c.promo);
    const best = (wrapped.length ? wrapped : main.length ? main : candidates).sort((a,b) => b.list.length - a.list.length)[0];
    if (!best) return html;
    return ShopSerpTab.html(best.list.map((raw, i) => {
      const p = raw.item || raw;
      return {channelProductId: p.channelProductId || p.channel?.channelProductId || p.mallProductId,
        nvMid: p.nvMid || p.id, rank: Number(p.rank) || i + 1,
        isAd: !!(p.adId || raw.adId || p.adcrUrl || raw.adcrUrl || String(p.adType) === '1' || String(p.type || raw.type).toLowerCase() === 'supersaving' || best.promo),
        title: p.productTitle || p.productName || p.title, mallName: p.mallName || p.lowMallList?.[0]?.name,
        price: p.price || p.lowPrice || p.mobileLowPrice, link: p.mallProductUrl || p.crUrl || ''};
    }));
  }
  async closed() {
    if (typeof rfCloseWork !== 'undefined') await rfCloseWork;
    return this.isClosed && await this.isClosed();
  }
  async focus(keyword) {
    if (await this.closed()) return;
    const c = this.browser;
    const saved = await this.session().get(['rfSerpTabId']);
    let tab;
    try { tab = await c.tabs.get(saved.rfSerpTabId); } catch (_) {}
    if (!tab) {
      tab = await this.create({url: 'https://msearch.shopping.naver.com/search/all?query=' + encodeURIComponent(keyword) + '#rfserp', active: true});
      this.tabId = tab.id;
      await this.session().set({rfSerpTabId: tab.id, rfSerpTabKeyword: keyword, rfSerpAnalysisId: this.analysisId});
    } else await c.tabs.update(tab.id, {active: true});
    if (tab.windowId != null) await c.windows.update(tab.windowId, {focused: true});
  }
  // Inspect the existing document; only recreate/focus a missing recovery tab.
  async peek(keyword) {
    if (await this.closed()) return {ok:false, tab_closed:true};
    try {
      const saved = await this.session().get(['rfSerpTabId', 'rfSerpTabKeyword']);
      if (saved.rfSerpTabKeyword !== keyword) return null;
      if (saved.rfSerpTabId == null) { await this.focus(keyword); return null; }
      const tab = await this.browser.tabs.get(saved.rfSerpTabId);
      const url = new URL(tab.url);
      if (url.origin === 'https://m.search.naver.com' && url.hash === '#rfnav' && url.searchParams.get('query') === keyword) {
        await this.browser.tabs.sendMessage(tab.id, { type: '__rfNavResume' });
        return null;
      }
      if (!['https://search.shopping.naver.com', 'https://msearch.shopping.naver.com'].includes(url.origin) || !url.pathname.startsWith('/search/') || url.searchParams.get('query') !== keyword) return null;
      const r = await this.browser.tabs.sendMessage(saved.rfSerpTabId, { type: '__rfSerpPeek' });
      if (r?.ok && Array.isArray(r.products)) {
        return { ok: true, status: 200, html: ShopSerpTab.html(r.products), result_source: r.result_source || 'shopping', mode: 'tab' };
      }
    } catch (_) { await this.focus(keyword).catch(() => {}); }
    return null;
  }
  async read(keyword, target = {}) {
    if (await this.closed()) return {ok:false, tab_closed:true};
    const c = this.browser;
    let tab;
    let finish;
    let shopping = false, inspecting = false;
    const result = new Promise(resolve => { finish = resolve; });
    const blocked = () => finish({ ok: false, captcha: true, status: 200, message: '네이버 보안 화면 감지 — 수집 탭에서 풀어 주세요.' });
    const onMessage = (msg, sender, reply) => {
      if (tab && sender?.tab?.id === tab.id && msg?.type === '__rfSerpTarget') { reply(target); return; }
      if (!tab || sender?.tab?.id !== tab.id || msg?.type !== '__rfSerpRead') return;
      const r = msg.result;
      if (r?.ok && Array.isArray(r.products)) finish({ ok: true, status: 200, html: ShopSerpTab.html(r.products), result_source: r.result_source || 'shopping', mode: 'tab' });
      else if (r && !r.ok) finish(r);
    };
    const onUpdated = (id, info) => {
      if (id === tab?.id && /shopping\.naver\.com/.test(info.url || '')) {
        globalThis.rfSerpCurrent = {analysisId:this.analysisId, keyword, screen: '쇼핑'};
        if (!shopping) { shopping = true; clearTimeout(timer); timer = setTimeout(() => finish({ok:false,status:0,message:'쇼핑 상품 목록 읽기 15초 초과'}), this.timeoutMs); }
      }
      if (id === tab?.id && /^https:\/\/(?:nid|captcha|ncpt)\.naver\.com\//i.test(info.url || '')) blocked();
    };
    const onRemoved = id => {
      if (id !== tab?.id) return;
      this.tabId = null;
      finish({ ok: false, status: 0, tab_closed:true, message: '수집 탭을 닫아 멈췄어요 — 이어서를 누르면 다시 시작' });
    };
    c.runtime.onMessage.addListener(onMessage);
    c.tabs.onUpdated.addListener(onUpdated);
    c.tabs.onRemoved.addListener(onRemoved);
    let timer; // Start the document timeout after the configured opening queue.
    const poll = setInterval(async () => {
      if (!shopping || inspecting || !tab) return;
      inspecting = true;
      try {
        const r = await c.tabs.sendMessage(tab.id, {type:'__rfSerpPeek'});
        if (r?.ok && Array.isArray(r.products)) finish({ok:true,status:200,html:ShopSerpTab.html(r.products),result_source:'shopping',mode:'tab'});
        else if (r?.captcha) blocked();
        else finish({ok:false,status:0,message:'못 읽음 — 쇼핑 상품 목록'});
      } catch (_) {} finally { inspecting = false; }
    }, 500);
    try {
      const url = 'https://m.search.naver.com/search.naver?where=m&query=' + encodeURIComponent(keyword) + '#rfnav';
      const storage = this.session();
      if (this.tabId === null) {
        const saved = await storage.get('rfSerpTabId');
        this.tabId = saved.rfSerpTabId ?? null;
      }
      if (this.tabId !== null) {
        try { tab = await c.tabs.get(this.tabId); }
        catch (_) { this.tabId = null; }
      }
      if (tab) {
        if (this.allowOpen && !this.allowOpen()) return {ok:false,deferred:true};
        await c.tabs.update(tab.id, { url, active: false });
      } else {
        tab = await this.create({ url, active: false, pinned: false });
        this.tabId = tab.id;
        await storage.set({ rfSerpTabId: tab.id });
      }
      await storage.set({ rfSerpTabKeyword: keyword, rfSerpAnalysisId: this.analysisId });
      if (!timer) timer = setTimeout(() => finish({ ok: false, status: 0, message: '쇼핑 탭 응답 시간이 초과되었습니다.' }), this.timeoutMs);
      const current = await c.tabs.get(tab.id);
      onUpdated(tab.id, { url: current.url || current.pendingUrl });
      const response = await result;
      clearTimeout(timer);

      if (response.captcha || [403,429].includes(response.status)) await this.focus(keyword).catch(() => {});
      return response;
    } catch (e) { return { ok: false, status: 0, deferred: e.code === 'RF_PARALLEL_REDUCED', message: '쇼핑 탭 오류: ' + e.message }; }
    finally {
      clearInterval(poll);
      clearTimeout(timer);
      c.runtime.onMessage.removeListener(onMessage);
      c.tabs.onUpdated.removeListener(onUpdated);
      c.tabs.onRemoved.removeListener(onRemoved);
    }
  }
}
globalThis.ShopSerpTab = ShopSerpTab;
